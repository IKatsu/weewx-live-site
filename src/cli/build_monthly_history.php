<?php

declare(strict_types=1);

$srcDir = dirname(__DIR__);
require_once $srcDir . '/bootstrap.php';
require_once $srcDir . '/history_metrics.php';

function cli_arg_value(array $argv, string $prefix): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

function history_summary_write_pdo(array $config, PDO $readPdo): PDO
{
    $writerDb = (array) ($config['history_writer_db'] ?? []);
    $writerUser = trim((string) ($writerDb['username'] ?? ''));
    if ($writerUser === '') {
        $writerDb = (array) ($config['forecast_writer_db'] ?? []);
        $writerUser = trim((string) ($writerDb['username'] ?? ''));
    }

    if ($writerUser === '') {
        return $readPdo;
    }

    $writerConfig = $config;
    $writerConfig['db'] = [
        'host' => (string) ($writerDb['host'] ?? $config['db']['host']),
        'port' => (int) ($writerDb['port'] ?? $config['db']['port']),
        'database' => (string) ($writerDb['database'] ?? $config['db']['database']),
        'username' => $writerUser,
        'password' => (string) ($writerDb['password'] ?? ''),
    ];
    return pdo_from_config($writerConfig);
}

function month_key_is_valid(string $monthKey): bool
{
    return preg_match('/^\d{4}-\d{2}$/', $monthKey) === 1;
}

function previous_month_key(): string
{
    return gmdate('Y-m', strtotime('first day of last month 00:00:00 UTC'));
}

function table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $stmt->execute([':table_name' => $tableName]);
    return (int) $stmt->fetchColumn() > 0;
}

function summary_row_exists(PDO $pdo, string $tableName, string $fieldKey, string $monthKey): bool
{
    $sql = sprintf(
        'SELECT COUNT(*) FROM `%s` WHERE field_key = :field_key AND month_key = :month_key',
        $tableName
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':field_key' => $fieldKey,
        ':month_key' => $monthKey,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return array{sample_days:int,low_value:?float,avg_value:?float,high_value:?float}|null
 */
function live_monthly_rollup(PDO $pdo, string $tableName, string $monthKey): ?array
{
    $sql = sprintf(
        "SELECT
            COUNT(*) AS sample_days,
            MIN(min) AS low_value,
            SUM(sum) / NULLIF(SUM(count), 0) AS avg_value,
            MAX(max) AS high_value
         FROM `%s`
         WHERE DATE_FORMAT(FROM_UNIXTIME(dateTime), '%%Y-%%m') = :month_key",
        $tableName
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':month_key' => $monthKey]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    $sampleDays = (int) ($row['sample_days'] ?? 0);
    if ($sampleDays <= 0) {
        return null;
    }

    return [
        'sample_days' => $sampleDays,
        'low_value' => $row['low_value'] !== null ? (float) $row['low_value'] : null,
        'avg_value' => $row['avg_value'] !== null ? (float) $row['avg_value'] : null,
        'high_value' => $row['high_value'] !== null ? (float) $row['high_value'] : null,
    ];
}

$config = app_config();
$force = in_array('--force', $argv, true);
$monthKey = trim((string) (cli_arg_value($argv, '--month=') ?? previous_month_key()));
if (!month_key_is_valid($monthKey)) {
    fwrite(STDERR, "Invalid month key. Use --month=YYYY-MM.\n");
    exit(1);
}

$summaryTable = (string) (($config['history']['summary_table'] ?? '') ?: 'pws_history_monthly_summary');
if (!is_safe_identifier($summaryTable)) {
    fwrite(STDERR, "Configured history summary table name is invalid.\n");
    exit(1);
}

try {
    $readPdo = pdo_from_config($config);
    $writePdo = history_summary_write_pdo($config, $readPdo);

    if (!table_exists($writePdo, $summaryTable)) {
        throw new RuntimeException(
            'Missing history summary table. Apply docs/sql/create_pws_history_monthly_summary.sql first.'
        );
    }

    $archiveCols = archive_columns($readPdo);
    $tableExistsStmt = $readPdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $insertSql = sprintf(
        'INSERT INTO `%s`
            (field_key, source_column, month_key, month_start, sample_days, low_value, avg_value, high_value, generated_at)
         VALUES
            (:field_key, :source_column, :month_key, STR_TO_DATE(CONCAT(:month_key, \'-01\'), \'%%Y-%%m-%%d\'), :sample_days, :low_value, :avg_value, :high_value, UTC_TIMESTAMP())',
        $summaryTable
    );
    $insertStmt = $writePdo->prepare($insertSql);

    $inserted = 0;
    $skippedExisting = 0;
    $skippedEmpty = 0;
    $skippedMissingTables = 0;
    $processed = 0;

    foreach (history_metric_definitions() as $def) {
        $fieldKey = (string) $def['field'];
        $mapped = mapped_archive_column($config, $archiveCols, $fieldKey);
        if ($mapped === null) {
            $skippedMissingTables++;
            continue;
        }

        $tableName = 'archive_day_' . $mapped;
        if (!is_safe_identifier($tableName)) {
            $skippedMissingTables++;
            continue;
        }

        $tableExistsStmt->execute([':table_name' => $tableName]);
        if ((int) $tableExistsStmt->fetchColumn() === 0) {
            $skippedMissingTables++;
            continue;
        }

        if (!$force && summary_row_exists($writePdo, $summaryTable, $fieldKey, $monthKey)) {
            $skippedExisting++;
            continue;
        }

        $rollup = live_monthly_rollup($readPdo, $tableName, $monthKey);
        if ($rollup === null) {
            $skippedEmpty++;
            continue;
        }

        if ($force) {
            $deleteSql = sprintf('DELETE FROM `%s` WHERE field_key = :field_key AND month_key = :month_key', $summaryTable);
            $deleteStmt = $writePdo->prepare($deleteSql);
            $deleteStmt->execute([
                ':field_key' => $fieldKey,
                ':month_key' => $monthKey,
            ]);
        }

        $insertStmt->execute([
            ':field_key' => $fieldKey,
            ':source_column' => $mapped,
            ':month_key' => $monthKey,
            ':sample_days' => $rollup['sample_days'],
            ':low_value' => $rollup['low_value'],
            ':avg_value' => $rollup['avg_value'],
            ':high_value' => $rollup['high_value'],
        ]);
        $inserted++;
        $processed++;
    }

    fwrite(
        STDOUT,
        sprintf(
            "Monthly history refresh completed: month=%s inserted=%d existing=%d empty=%d missing=%d\n",
            $monthKey,
            $inserted,
            $skippedExisting,
            $skippedEmpty,
            $skippedMissingTables
        )
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Monthly history refresh failed: ' . $e->getMessage() . "\n");
    exit(1);
}
