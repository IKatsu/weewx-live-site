<?php

declare(strict_types=1);

function celestial_cache_table(array $config): string
{
    $table = (string) ($config['celestial']['cache_table'] ?? 'pws_celestial_cache');
    return is_safe_identifier($table) ? $table : 'pws_celestial_cache';
}

function celestial_cache_write(PDO $pdo, array $config, string $dataset, string $periodKey, array $payload, DateTimeImmutable $validFrom, DateTimeImmutable $validUntil): void
{
    $table = celestial_cache_table($config);
    $sql = "INSERT INTO {$table}
        (dataset, period_key, generated_at, valid_from, valid_until, payload_json)
        VALUES (:dataset, :period_key, :generated_at, :valid_from, :valid_until, :payload_json)
        ON DUPLICATE KEY UPDATE
            generated_at = VALUES(generated_at),
            valid_from = VALUES(valid_from),
            valid_until = VALUES(valid_until),
            payload_json = VALUES(payload_json)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':dataset' => $dataset,
        ':period_key' => $periodKey,
        ':generated_at' => gmdate('Y-m-d H:i:s'),
        ':valid_from' => $validFrom->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ':valid_until' => $validUntil->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function celestial_cache_read(PDO $pdo, array $config, string $dataset, ?string $periodKey = null): ?array
{
    $table = celestial_cache_table($config);
    if ($periodKey !== null && $periodKey !== '') {
        $sql = "SELECT dataset, period_key, generated_at, valid_from, valid_until, payload_json
                FROM {$table}
                WHERE dataset = :dataset AND period_key = :period_key
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':dataset' => $dataset, ':period_key' => $periodKey]);
    } else {
        $sql = "SELECT dataset, period_key, generated_at, valid_from, valid_until, payload_json
                FROM {$table}
                WHERE dataset = :dataset
                ORDER BY valid_from DESC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':dataset' => $dataset]);
    }

    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    return [
        'dataset' => (string) $row['dataset'],
        'periodKey' => (string) $row['period_key'],
        'generatedAt' => (string) $row['generated_at'],
        'validFrom' => (string) $row['valid_from'],
        'validUntil' => (string) $row['valid_until'],
        'payload' => $payload,
    ];
}
