<?php

declare(strict_types=1);

$srcDir = dirname(__DIR__);
require_once $srcDir . '/bootstrap.php';

const CONSTELLATION_NAMES = [
    'And' => 'Andromeda', 'Ant' => 'Antlia', 'Aps' => 'Apus', 'Aql' => 'Aquila', 'Aqr' => 'Aquarius',
    'Ara' => 'Ara', 'Ari' => 'Aries', 'Aur' => 'Auriga', 'Boo' => 'Bootes', 'CMa' => 'Canis Major',
    'CMi' => 'Canis Minor', 'CVn' => 'Canes Venatici', 'Cae' => 'Caelum', 'Cam' => 'Camelopardalis',
    'Cap' => 'Capricornus', 'Car' => 'Carina', 'Cas' => 'Cassiopeia', 'Cen' => 'Centaurus', 'Cep' => 'Cepheus',
    'Cet' => 'Cetus', 'Cha' => 'Chamaeleon', 'Cir' => 'Circinus', 'Cnc' => 'Cancer', 'Col' => 'Columba',
    'Com' => 'Coma Berenices', 'CrA' => 'Corona Australis', 'CrB' => 'Corona Borealis', 'Crt' => 'Crater',
    'Cru' => 'Crux', 'Crv' => 'Corvus', 'Cyg' => 'Cygnus', 'Del' => 'Delphinus', 'Dor' => 'Dorado',
    'Dra' => 'Draco', 'Equ' => 'Equuleus', 'Eri' => 'Eridanus', 'For' => 'Fornax', 'Gem' => 'Gemini',
    'Gru' => 'Grus', 'Her' => 'Hercules', 'Hor' => 'Horologium', 'Hya' => 'Hydra', 'Hyi' => 'Hydrus',
    'Ind' => 'Indus', 'LMi' => 'Leo Minor', 'Lac' => 'Lacerta', 'Leo' => 'Leo', 'Lep' => 'Lepus',
    'Lib' => 'Libra', 'Lup' => 'Lupus', 'Lyn' => 'Lynx', 'Lyr' => 'Lyra', 'Men' => 'Mensa',
    'Mic' => 'Microscopium', 'Mon' => 'Monoceros', 'Mus' => 'Musca', 'Nor' => 'Norma', 'Oct' => 'Octans',
    'Oph' => 'Ophiuchus', 'Ori' => 'Orion', 'Pav' => 'Pavo', 'Peg' => 'Pegasus', 'Per' => 'Perseus',
    'Phe' => 'Phoenix', 'Pic' => 'Pictor', 'PsA' => 'Piscis Austrinus', 'Psc' => 'Pisces', 'Pup' => 'Puppis',
    'Pyx' => 'Pyxis', 'Ret' => 'Reticulum', 'Scl' => 'Sculptor', 'Sco' => 'Scorpius', 'Sct' => 'Scutum',
    'Ser' => 'Serpens', 'Sex' => 'Sextans', 'Sge' => 'Sagitta', 'Sgr' => 'Sagittarius', 'Tau' => 'Taurus',
    'Tel' => 'Telescopium', 'TrA' => 'Triangulum Australe', 'Tri' => 'Triangulum', 'Tuc' => 'Tucana',
    'UMa' => 'Ursa Major', 'UMi' => 'Ursa Minor', 'Vel' => 'Vela', 'Vir' => 'Virgo', 'Vol' => 'Volans',
    'Vul' => 'Vulpecula',
];

function parse_cli_args(array $argv): array
{
    $args = ['stars' => '', 'lines' => '', 'force' => false, 'dry_run' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--force') {
            $args['force'] = true;
        } elseif ($arg === '--dry-run') {
            $args['dry_run'] = true;
        } elseif (str_starts_with($arg, '--stars=')) {
            $args['stars'] = substr($arg, strlen('--stars='));
        } elseif (str_starts_with($arg, '--lines=')) {
            $args['lines'] = substr($arg, strlen('--lines='));
        } elseif ($arg === '--help' || $arg === '-h') {
            usage(0);
        }
    }
    return $args;
}

function usage(int $exitCode): void
{
    $message = "Usage: php src/cli/import_celestial_catalog.php --stars=/path/wxskyfield_stars.dat.gz --lines=/path/wxskyfield_lines.dat [--force] [--dry-run]\n";
    fwrite($exitCode === 0 ? STDOUT : STDERR, $message);
    exit($exitCode);
}

function writer_pdo_for_catalog(array $config): PDO
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

function assert_local_readable_file(string $path, string $label): string
{
    $path = trim($path);
    if ($path === '') {
        throw new RuntimeException("Missing --{$label}= path.");
    }
    if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1) {
        throw new RuntimeException("{$label} must be a local file path, not a URL.");
    }
    $real = realpath($path);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        throw new RuntimeException("{$label} file is not readable: {$path}");
    }
    return $real;
}

function table_name(string $name): string
{
    return is_safe_identifier($name) ? $name : throw new RuntimeException("Unsafe table name {$name}");
}

function float_or_null(string $value): ?float
{
    $trimmed = trim($value);
    return $trimmed === '' ? null : (float) $trimmed;
}

function parse_star_astrometry(array $fields): ?array
{
    try {
        if (trim($fields[8] ?? '') !== '' && trim($fields[9] ?? '') !== '') {
            $raDegrees = (float) $fields[8];
            $decDegrees = (float) $fields[9];
        } else {
            $raParts = preg_split('/\s+/', trim((string) ($fields[3] ?? '')));
            $decParts = preg_split('/\s+/', trim((string) ($fields[4] ?? '')));
            if (!is_array($raParts) || !is_array($decParts) || count($raParts) < 3 || count($decParts) < 3) {
                return null;
            }
            $raDegrees = ((int) $raParts[0] + (int) $raParts[1] / 60.0 + (float) $raParts[2] / 3600.0) * 15.0;
            $sign = str_starts_with((string) $decParts[0], '-') ? -1.0 : 1.0;
            $decDegrees = $sign * (abs((int) $decParts[0]) + (int) $decParts[1] / 60.0 + (float) $decParts[2] / 3600.0);
        }
        return [
            'ra' => $raDegrees,
            'dec' => $decDegrees,
            'mag' => float_or_null((string) ($fields[5] ?? '')),
            'spectral' => trim((string) ($fields[76] ?? '')),
        ];
    } catch (Throwable) {
        return null;
    }
}

function import_stars(?PDO $pdo, string $path, bool $dryRun): array
{
    $table = table_name('pws_celestial_stars');
    $handle = gzopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Unable to open gzip star catalog: {$path}");
    }

    $stmt = $dryRun ? null : $pdo?->prepare("INSERT INTO {$table}
        (catalog_key, hip_id, right_ascension_degrees, declination_degrees, magnitude, proper_name, spectral_type, raw_json)
        VALUES ('hipparcos', :hip_id, :ra, :dec, :mag, '', :spectral, NULL)
        ON DUPLICATE KEY UPDATE
            right_ascension_degrees = VALUES(right_ascension_degrees),
            declination_degrees = VALUES(declination_degrees),
            magnitude = VALUES(magnitude),
            spectral_type = VALUES(spectral_type)");

    $seen = 0;
    $imported = 0;
    $skipped = 0;
    while (($line = gzgets($handle)) !== false) {
        $seen++;
        $fields = explode('|', $line);
        $hip = isset($fields[1]) ? (int) trim($fields[1]) : 0;
        if ($hip <= 0) {
            $skipped++;
            continue;
        }
        $parsed = parse_star_astrometry($fields);
        if ($parsed === null) {
            $skipped++;
            continue;
        }
        if (!$dryRun) {
            $stmt?->execute([
                ':hip_id' => $hip,
                ':ra' => $parsed['ra'],
                ':dec' => $parsed['dec'],
                ':mag' => $parsed['mag'],
                ':spectral' => $parsed['spectral'],
            ]);
        }
        $imported++;
    }
    gzclose($handle);
    return ['seen' => $seen, 'imported' => $imported, 'skipped' => $skipped];
}

function import_constellation_lines(?PDO $pdo, string $path, bool $dryRun): array
{
    $table = table_name('pws_celestial_constellation_lines');
    $stmt = $dryRun ? null : $pdo?->prepare("INSERT INTO {$table}
        (catalog_key, constellation_abbr, polyline_id, point_order, hip_id)
        VALUES ('stellarium-modern', :abbr, :polyline_id, :point_order, :hip_id)
        ON DUPLICATE KEY UPDATE hip_id = VALUES(hip_id)");

    $seen = 0;
    $polylines = 0;
    $points = 0;
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Unable to open constellation lines file: {$path}");
    }
    while (($line = fgets($handle)) !== false) {
        $seen++;
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        if (!is_array($parts) || count($parts) < 3) {
            continue;
        }
        $abbr = (string) array_shift($parts);
        $polylines++;
        foreach ($parts as $idx => $hipRaw) {
            $hip = (int) $hipRaw;
            if ($hip <= 0) {
                continue;
            }
            if (!$dryRun) {
                $stmt?->execute([
                    ':abbr' => $abbr,
                    ':polyline_id' => $polylines,
                    ':point_order' => $idx,
                    ':hip_id' => $hip,
                ]);
            }
            $points++;
        }
    }
    fclose($handle);
    return ['seen' => $seen, 'polylines' => $polylines, 'points' => $points];
}

function import_constellation_names(?PDO $pdo, bool $dryRun): int
{
    $table = table_name('pws_celestial_constellation_names');
    $stmt = $dryRun ? null : $pdo?->prepare("INSERT INTO {$table}
        (constellation_abbr, locale, display_name)
        VALUES (:abbr, 'en', :display_name)
        ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)");
    $count = 0;
    foreach (CONSTELLATION_NAMES as $abbr => $name) {
        if (!$dryRun) {
            $stmt?->execute([':abbr' => $abbr, ':display_name' => $name]);
        }
        $count++;
    }
    return $count;
}

function upsert_catalog_meta(?PDO $pdo, bool $dryRun, string $starsPath, string $linesPath): void
{
    $table = table_name('pws_celestial_catalog_meta');
    $stmt = $dryRun ? null : $pdo?->prepare("INSERT INTO {$table}
        (catalog_key, source_name, source_license, source_url, imported_at, notes)
        VALUES (:catalog_key, :source_name, :source_license, :source_url, UTC_TIMESTAMP(), :notes)
        ON DUPLICATE KEY UPDATE
            source_name = VALUES(source_name),
            source_license = VALUES(source_license),
            source_url = VALUES(source_url),
            imported_at = VALUES(imported_at),
            notes = VALUES(notes)");
    if ($dryRun) {
        return;
    }
    $stmt?->execute([
        ':catalog_key' => 'hipparcos',
        ':source_name' => 'Hipparcos/Tycho star catalog import',
        ':source_license' => 'CC BY-NC 3.0 IGO per weewx-skyfield documentation',
        ':source_url' => 'https://github.com/chaunceygardiner/weewx-skyfield',
        ':notes' => 'Imported from local file: ' . basename($starsPath) . '. Source file is intentionally not tracked in git.',
    ]);
    $stmt?->execute([
        ':catalog_key' => 'stellarium-modern',
        ':source_name' => 'Stellarium modern constellation lines import',
        ':source_license' => 'CC BY-SA 4.0 per weewx-skyfield documentation',
        ':source_url' => 'https://github.com/Stellarium/stellarium',
        ':notes' => 'Imported from local file: ' . basename($linesPath) . '. Source file is intentionally not tracked in git.',
    ]);
}

try {
    $args = parse_cli_args($argv);
    $starsPath = assert_local_readable_file((string) $args['stars'], 'stars');
    $linesPath = assert_local_readable_file((string) $args['lines'], 'lines');
    $dryRun = (bool) $args['dry_run'];
    $pdo = null;
    if (!$dryRun) {
        $config = app_config();
        $pdo = writer_pdo_for_catalog($config);
    }

    if (!$dryRun) {
        $pdo?->beginTransaction();
    }

    if ((bool) $args['force'] && !$dryRun) {
        $pdo?->exec('DELETE FROM pws_celestial_constellation_lines WHERE catalog_key = ' . $pdo->quote('stellarium-modern'));
        $pdo?->exec('DELETE FROM pws_celestial_constellation_names WHERE locale = ' . $pdo->quote('en'));
        $pdo?->exec('DELETE FROM pws_celestial_stars WHERE catalog_key = ' . $pdo->quote('hipparcos'));
    }

    $starStats = import_stars($pdo, $starsPath, $dryRun);
    $lineStats = import_constellation_lines($pdo, $linesPath, $dryRun);
    $nameCount = import_constellation_names($pdo, $dryRun);
    upsert_catalog_meta($pdo, $dryRun, $starsPath, $linesPath);

    if (!$dryRun) {
        $pdo?->commit();
    }

    fwrite(STDOUT, sprintf(
        "Celestial catalog import completed%s: stars=%d skipped=%d constellation_polylines=%d constellation_points=%d names=%d\n",
        $dryRun ? ' (dry-run)' : '',
        $starStats['imported'],
        $starStats['skipped'],
        $lineStats['polylines'],
        $lineStats['points'],
        $nameCount
    ));
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Celestial catalog import failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
