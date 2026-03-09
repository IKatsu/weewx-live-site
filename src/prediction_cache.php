<?php

declare(strict_types=1);

function prediction_cache_table(array $config): string
{
    $table = (string) ($config['prediction']['cache_table'] ?? 'pws_prediction_cache');
    return is_safe_identifier($table) ? $table : 'pws_prediction_cache';
}

/**
 * @param list<array<string,mixed>> $rows
 */
function prediction_cache_write(PDO $pdo, array $config, string $runId, array $rows): int
{
    if ($rows === []) {
        return 0;
    }

    $table = prediction_cache_table($config);
    $sql = "INSERT INTO {$table}
        (run_id, generated_at, target_time, metric, unit, value_num, confidence, method, details_json)
        VALUES
        (:run_id, :generated_at, :target_time, :metric, :unit, :value_num, :confidence, :method, :details_json)";
    $stmt = $pdo->prepare($sql);

    $count = 0;
    foreach ($rows as $row) {
        $stmt->execute([
            ':run_id' => $runId,
            ':generated_at' => (string) ($row['generated_at'] ?? gmdate('Y-m-d H:i:s')),
            ':target_time' => (string) ($row['target_time'] ?? gmdate('Y-m-d H:i:s')),
            ':metric' => (string) ($row['metric'] ?? ''),
            ':unit' => (string) ($row['unit'] ?? ''),
            ':value_num' => $row['value_num'],
            ':confidence' => $row['confidence'],
            ':method' => (string) ($row['method'] ?? 'local_blend_v1'),
            ':details_json' => json_encode($row['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $count++;
    }

    return $count;
}

function prediction_cache_latest_run_id(PDO $pdo, array $config): ?string
{
    $table = prediction_cache_table($config);
    $sql = "SELECT run_id FROM {$table} ORDER BY generated_at DESC LIMIT 1";
    $value = (string) ($pdo->query($sql)->fetchColumn() ?: '');
    return $value === '' ? null : $value;
}

function prediction_cache_last_generated_at(PDO $pdo, array $config): ?DateTimeImmutable
{
    $table = prediction_cache_table($config);
    $sql = "SELECT generated_at FROM {$table} ORDER BY generated_at DESC LIMIT 1";
    $value = (string) ($pdo->query($sql)->fetchColumn() ?: '');
    if ($value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return null;
    }
}

function prediction_cache_should_refresh(PDO $pdo, array $config): bool
{
    $interval = max(300, (int) ($config['prediction']['refresh_interval_seconds'] ?? 1800));
    $last = prediction_cache_last_generated_at($pdo, $config);
    if ($last === null) {
        return true;
    }
    $age = time() - $last->getTimestamp();
    return $age >= $interval;
}

/**
 * @return list<array<string,mixed>>
 */
function prediction_cache_read_latest(PDO $pdo, array $config): array
{
    $table = prediction_cache_table($config);
    $runId = prediction_cache_latest_run_id($pdo, $config);
    if ($runId === null) {
        return [];
    }

    $sql = "SELECT run_id, generated_at, target_time, metric, unit, value_num, confidence, method, details_json
            FROM {$table}
            WHERE run_id = :run_id
            ORDER BY target_time ASC, metric ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':run_id' => $runId]);

    $out = [];
    while ($row = $stmt->fetch()) {
        $details = json_decode((string) ($row['details_json'] ?? '{}'), true);
        if (!is_array($details)) {
            $details = [];
        }
        $out[] = [
            'run_id' => (string) $row['run_id'],
            'generated_at' => (string) $row['generated_at'],
            'target_time' => (string) $row['target_time'],
            'metric' => (string) $row['metric'],
            'unit' => (string) $row['unit'],
            'value_num' => $row['value_num'] !== null ? (float) $row['value_num'] : null,
            'confidence' => $row['confidence'] !== null ? (float) $row['confidence'] : null,
            'method' => (string) $row['method'],
            'details' => $details,
        ];
    }

    return $out;
}
