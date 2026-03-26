<?php

declare(strict_types=1);

// API entrypoints can run from local dev or mounted deploy paths.
putenv('PWS_BASE_DIR=' . dirname(__DIR__));

$srcCandidates = [
    dirname(__DIR__, 2) . '/src',
    dirname(__DIR__, 3) . '/src',
];

$bootstrapPath = null;
foreach ($srcCandidates as $candidate) {
    if (is_file($candidate . '/bootstrap.php')) {
        $bootstrapPath = $candidate . '/bootstrap.php';
        break;
    }
}

if ($bootstrapPath === null) {
    http_response_code(500);
    echo 'Unable to locate src/bootstrap.php';
    exit;
}

require_once $bootstrapPath;

$config = app_config();
$apiConfig = (array) ($config['api'] ?? []);
$dumpEnabled = (bool) ($apiConfig['dump_enabled'] ?? false);
$defaultRows = max(1, (int) ($apiConfig['dump_default_rows'] ?? 1000));
$maxRows = max(1, (int) ($apiConfig['dump_max_rows'] ?? 10000));
$expectedToken = trim((string) ($apiConfig['dump_token'] ?? ''));

if (!$dumpEnabled) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "Dump endpoint disabled\n";
    exit;
}

if ($expectedToken !== '') {
    $providedToken = (string) ($_GET['token'] ?? '');
    $headerToken = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');
    if ($providedToken === '' && $headerToken !== '') {
        $providedToken = $headerToken;
    }
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo "Unauthorized\n";
        exit;
    }
}

$type = strtolower((string) ($_GET['type'] ?? 'csv'));
if (!in_array($type, ['csv', 'json', 'xml'], true)) {
    $type = 'csv';
}
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : $defaultRows;
$limit = max(1, min($limit, $maxRows));
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$offset = max(0, $offset);

try {
    $pdo = pdo_from_config($config);
    // Stream rows directly from DB cursor to avoid holding full archive in memory.
    $sql = sprintf('SELECT * FROM archive ORDER BY dateTime DESC LIMIT %d OFFSET %d', $limit, $offset);
    $stmt = $pdo->query($sql);
    $first = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($first === false) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo "No data\n";
        exit;
    }

    $columns = array_keys($first);

    if ($type === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: inline; filename="archive.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'wb');
        fputcsv($out, $columns);
        fputcsv($out, $first);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    if ($type === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        // Emit as a JSON array stream to keep memory usage low.
        echo "[";
        echo json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo ",";
            echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        echo "]";
        exit;
    }

    // XML output path.
    header('Content-Type: application/xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<archive>\n";

    $emitRow = static function (array $row) use ($columns): void {
        echo "  <row>\n";
        foreach ($columns as $col) {
            $val = $row[$col];
            if ($val === null) {
                echo '    <field name="' . htmlspecialchars((string) $col, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>' . "\n";
            } else {
                echo '    <field name="' . htmlspecialchars((string) $col, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '">' .
                    htmlspecialchars((string) $val, ENT_XML1 | ENT_QUOTES, 'UTF-8') .
                    "</field>\n";
            }
        }
        echo "  </row>\n";
    };

    $emitRow($first);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $emitRow($row);
    }

    echo "</archive>\n";
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Dump failed: ' . $e->getMessage();
}
