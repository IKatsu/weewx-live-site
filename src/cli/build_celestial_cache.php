<?php

declare(strict_types=1);

$srcDir = dirname(__DIR__);
require_once $srcDir . '/bootstrap.php';
require_once $srcDir . '/celestial_cache.php';

$force = in_array('--force', $argv, true);
$datasets = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dataset=')) {
        $datasets[] = substr($arg, strlen('--dataset='));
    }
}
if ($datasets === []) {
    $datasets = ['daily', 'monthly', 'yearly'];
}
$datasets = array_values(array_intersect(array_unique($datasets), ['daily', 'monthly', 'yearly']));
if ($datasets === []) {
    fwrite(STDERR, "No valid datasets requested.\n");
    exit(1);
}

function writer_pdo_for_celestial(array $config): PDO
{
    $writerDb = (array) ($config['forecast_writer_db'] ?? []);
    $writerUser = (string) ($writerDb['username'] ?? '');
    if ($writerUser === '') {
        return pdo_from_config($config);
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

function run_celestial_generator(array $config, string $dataset): array
{
    $celestial = (array) ($config['celestial'] ?? []);
    $location = (array) ($config['location'] ?? []);
    $python = (string) ($celestial['python'] ?? 'python3');
    $script = __DIR__ . '/build_celestial_cache.py';
    $date = gmdate('Y-m-d');
    $bodies = implode(',', (array) ($celestial['enabled_bodies'] ?? ['sun', 'moon']));

    $cmd = [
        $python,
        $script,
        '--dataset',
        $dataset,
        '--date',
        $date,
        '--latitude',
        (string) ((float) ($location['latitude'] ?? 0.0)),
        '--longitude',
        (string) ((float) ($location['longitude'] ?? 0.0)),
        '--altitude',
        (string) ((float) ($location['altitude'] ?? 0.0)),
        '--timezone',
        (string) (($location['timezone'] ?? 'UTC') ?: 'UTC'),
        '--sample-minutes',
        (string) ((int) ($celestial['sample_minutes'] ?? 10)),
        '--bodies',
        $bodies,
    ];
    if ((string) ($celestial['data_dir'] ?? '') !== '') {
        $cmd[] = '--data-dir';
        $cmd[] = (string) $celestial['data_dir'];
    }
    if ((string) ($celestial['ephemeris_path'] ?? '') !== '') {
        $cmd[] = '--ephemeris';
        $cmd[] = (string) $celestial['ephemeris_path'];
    }

    $escaped = array_map('escapeshellarg', $cmd);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(implode(' ', $escaped), $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start celestial generator.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(trim($stderr) !== '' ? trim($stderr) : "Generator failed with exit code {$exitCode}");
    }

    $payload = json_decode((string) $stdout, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Celestial generator did not return valid JSON.');
    }
    return $payload;
}

try {
    $config = app_config();
    $pdo = writer_pdo_for_celestial($config);
    $written = 0;
    foreach ($datasets as $dataset) {
        $payload = run_celestial_generator($config, $dataset);
        $validFrom = new DateTimeImmutable((string) ($payload['validFrom'] ?? 'now'));
        $validUntil = new DateTimeImmutable((string) ($payload['validUntil'] ?? '+1 day'));
        celestial_cache_write(
            $pdo,
            $config,
            (string) ($payload['dataset'] ?? $dataset),
            (string) ($payload['periodKey'] ?? gmdate('Y-m-d')),
            $payload,
            $validFrom,
            $validUntil
        );
        $written++;
        fwrite(STDOUT, sprintf(
            "Celestial cache refreshed: dataset=%s period=%s\n",
            (string) ($payload['dataset'] ?? $dataset),
            (string) ($payload['periodKey'] ?? '')
        ));
    }
    if ($written === 0 && !$force) {
        fwrite(STDOUT, "No celestial cache datasets refreshed.\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Celestial cache refresh failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
