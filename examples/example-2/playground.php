<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PHP 7.4 or newer is required. This server is running PHP ' . PHP_VERSION . '.' . PHP_EOL;
    exit(1);
}

if (PHP_SAPI !== 'cli') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');
}

/**
 * odata-feed live refresh playground (example-2)
 *
 * Edit the $feeds array below, then:
 *
 *   composer install
 *   php playground.php --build          # (re)generate playground.xlsx
 *   php -S localhost:8080 playground.php
 *
 * Open playground.xlsx in Excel → Data → Refresh All (enter Basic credentials).
 * Change the arrays, save this file, refresh Excel again — no rebuild needed
 * for OData (the server reads $feeds on every request). Re-run --build only
 * if you change sheet names, feedId, or the OData base URL/port.
 *
 * For subdirectory or remote hosting during --build, pass a base URL:
 *   php playground.php --build --base-url=http://localhost:8080
 *   PLAYGROUND_BASE_URL=https://example.com/my-app php playground.php --build
 */

use GuzzleHttp\Psr7\ServerRequest;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use WPDev\ODataFeed\Feed\FeedConfig;
use WPDev\ODataFeed\Writer\LiveXlsxWriter;
use WPDev\PhpSpreadsheetOData\Feed\InMemoryFeedResolver;
use WPDev\PhpSpreadsheetOData\OData\ODataServer;

require __DIR__ . '/vendor/autoload.php';

// =============================================================================
// EDIT THIS — your live OData source of truth
// =============================================================================

const PLAYGROUND_FEED_ID = 'demo';
const PLAYGROUND_PORT = 8080;

/**
 * HTTP Basic credentials for the OData feed. Excel prompts on refresh; credentials
 * are stored in the OS keychain, never in the workbook. On shared hosting you can
 * override with ODATA_USER / ODATA_PASS env vars instead of editing this file.
 */
$username = 'demo';
$password = 'demo';

$envUser = getenv('ODATA_USER');
$envPass = getenv('ODATA_PASS');
if ($envUser !== false && $envUser !== '') {
    $username = $envUser;
}
if ($envPass !== false && $envPass !== '') {
    $password = $envPass;
}

/** @var array<string, array<string, list<list<mixed>>>> */
$feeds = [
    PLAYGROUND_FEED_ID => [
        'Employees' => [
            ['Id', 'Name', 'Age', 'Department'],
            [1, 'Alice', 30, 'Engineering'],
            [2, 'Bob', 25, 'Sales'],
            [3, 'Charlie', 35, 'Engineering'],
            [4, 'Diana', 28, 'Marketing'],
        ],
        'Products' => [
            ['Sku', 'Title', 'Price', 'InStock'],
            ['W-100', 'Widget', 9.99, true],
            ['G-200', 'Gadget', 14.50, true],
            ['S-300', 'Sprocket', 3.25, false],
        ],
    ],
];

/** @var string|null */
$playgroundCliBaseUrl = null;

// =============================================================================
// Helpers
// =============================================================================

/**
 * @param array<string, list<list<mixed>>> $sheets
 */
function buildSpreadsheet(array $sheets): Spreadsheet
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $title => $rows) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($rows, null, 'A1');
    }

    $spreadsheet->setActiveSheetIndex(0);

    return $spreadsheet;
}

/**
 * @param array<string, array<string, list<list<mixed>>>> $feeds
 */
function buildFeedResolver(array $feeds): InMemoryFeedResolver
{
    $resolved = [];

    foreach ($feeds as $feedId => $sheets) {
        $resolved[$feedId] = buildSpreadsheet($sheets);
    }

    return new InMemoryFeedResolver($resolved);
}

function playgroundCliBaseUrl(): ?string
{
    global $playgroundCliBaseUrl;

    if ($playgroundCliBaseUrl !== null && $playgroundCliBaseUrl !== '') {
        return rtrim($playgroundCliBaseUrl, '/');
    }

    $env = getenv('PLAYGROUND_BASE_URL');
    if ($env !== false && $env !== '') {
        return rtrim($env, '/');
    }

    return null;
}

function playgroundBasePath(): string
{
    if (PHP_SAPI === 'cli') {
        $baseUrl = playgroundCliBaseUrl();
        if ($baseUrl !== null) {
            $path = parse_url($baseUrl, PHP_URL_PATH) ?: '';

            return rtrim($path, '/');
        }

        return '';
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/playground.php');

    // php -S playground.php sets SCRIPT_NAME to the request path, not the router script.
    if (PHP_SAPI === 'cli-server' && substr($scriptName, -strlen('/playground.php')) !== '/playground.php') {
        return '';
    }

    $base = rtrim(dirname($scriptName), '/');

    return $base === '/' ? '' : $base;
}

function playgroundServiceRoot(): string
{
    if (PHP_SAPI === 'cli') {
        $baseUrl = playgroundCliBaseUrl();
        if ($baseUrl !== null) {
            return $baseUrl . '/odata';
        }

        return 'http://localhost:' . PLAYGROUND_PORT . '/odata';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ('localhost:' . PLAYGROUND_PORT);

    return $scheme . '://' . $host . playgroundBasePath() . '/odata';
}

function playgroundRequestPath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = playgroundBasePath();

    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base)) ?: '/';
    }

    return $path;
}

function playgroundUrl(string $path): string
{
    $base = playgroundBasePath();

    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    return ($base === '' ? '' : $base) . $path;
}

function playgroundXlsxPath(): string
{
    return __DIR__ . '/playground.xlsx';
}

/**
 * @param array<string, array<string, list<list<mixed>>>> $feeds
 */
function buildPlaygroundXlsx(array $feeds): string
{
    $feedId = PLAYGROUND_FEED_ID;
    if (!isset($feeds[$feedId])) {
        throw new InvalidArgumentException(sprintf('Feed "%s" is not defined in $feeds.', $feedId));
    }

    $sheets = $feeds[$feedId];
    $entitySet = (string) array_key_first($sheets);
    $spreadsheet = buildSpreadsheet($sheets);
    $outputPath = playgroundXlsxPath();

    $writer = new LiveXlsxWriter($spreadsheet);
    $writer->setFeed(new FeedConfig(playgroundServiceRoot(), $feedId, $entitySet));
    $writer->save($outputPath);

    return $outputPath;
}

function normalizeAuthorizationHeader(): void
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return;
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];

        return;
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $headers['Authorization'];

            return;
        }
    }

    if (!empty($_SERVER['PHP_AUTH_USER'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode(
            $_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? '')
        );
    }
}

function handleODataRequest(array $feeds, string $username, string $password): void
{
    normalizeAuthorizationHeader();

    $server = new ODataServer(buildFeedResolver($feeds), playgroundServiceRoot());
    $server->useBasicAuth(static function (string $user, string $pass) use ($username, $password): bool {
        return hash_equals($username, $user) && hash_equals($password, $pass);
    });
    $response = $server->handle(ServerRequest::fromGlobals());

    http_response_code($response->getStatusCode());

    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header(sprintf('%s: %s', $name, $value), false);
        }
    }

    echo (string) $response->getBody();
}

function renderHomePage(array $feeds, string $username): void
{
    $base = playgroundServiceRoot();
    $feedId = PLAYGROUND_FEED_ID;
    $xlsxPath = playgroundXlsxPath();
    $xlsxExists = is_file($xlsxPath);

    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>odata-feed playground</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1rem;line-height:1.5}';
    echo 'code,pre{background:#f4f4f5;padding:.15rem .35rem;border-radius:4px}pre{padding:1rem;overflow:auto}';
    echo 'a{color:#2563eb}ol li{margin:.5rem 0}</style></head><body>';

    echo '<h1>odata-feed playground</h1>';
    echo '<p>Edit <code>$feeds</code> in <code>playground.php</code>, save, then refresh Excel.</p>';
    echo '<p>OData is protected with <strong>HTTP Basic</strong> auth (user <code>'
        . htmlspecialchars($username, ENT_QUOTES) . '</code>). Excel prompts on refresh.</p>';

    echo '<h2>Quick links</h2><ul>';
    echo '<li><a href="' . htmlspecialchars(playgroundUrl('/odata'), ENT_QUOTES) . '">Service document</a></li>';
    echo '<li><a href="' . htmlspecialchars(playgroundUrl('/odata/' . $feedId . '/$metadata'), ENT_QUOTES) . '">$metadata</a></li>';

    if (isset($feeds[$feedId])) {
        foreach (array_keys($feeds[$feedId]) as $entitySet) {
            $url = playgroundUrl('/odata/' . rawurlencode($feedId) . '/' . rawurlencode($entitySet));
            echo '<li><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                . htmlspecialchars($entitySet, ENT_QUOTES) . ' collection</a></li>';
        }
    }

    if ($xlsxExists) {
        echo '<li><a href="' . htmlspecialchars(playgroundUrl('/playground.xlsx'), ENT_QUOTES) . '">Download playground.xlsx</a></li>';
    }

    echo '</ul>';

    echo '<h2>Excel refresh steps</h2><ol>';
    echo '<li><code>php playground.php --build</code> (first time, or after URL/sheet renames)</li>';
    echo '<li>Run <code>php -S localhost:' . PLAYGROUND_PORT . ' playground.php</code> locally (or deploy with <code>.htaccess</code>)</li>';
    echo '<li>Open <code>playground.xlsx</code> in Excel</li>';
    echo '<li><strong>Data → Refresh All</strong> — enter Basic credentials when prompted</li>';
    echo '<li>Change a value in <code>$feeds</code> above, save <code>playground.php</code>, refresh again</li>';
    echo '</ol>';

    echo '<h2>Current feed data (preview)</h2><pre>';
    echo htmlspecialchars(json_encode($feeds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
    echo '</pre>';

    echo '<p>OData base URL wired into the workbook: <code>' . htmlspecialchars($base, ENT_QUOTES) . '</code></p>';
    echo '</body></html>';
}

/**
 * @param list<string> $argv
 */
function parseCliBaseUrl(array $argv): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, '--base-url=') === 0) {
            $value = substr($arg, strlen('--base-url='));

            return $value !== '' ? $value : null;
        }
    }

    return null;
}

/**
 * @param list<string> $argv
 * @param array<string, array<string, list<list<mixed>>>> $feeds
 */
function runCli(array $argv, array $feeds): void
{
    global $playgroundCliBaseUrl;

    $playgroundCliBaseUrl = parseCliBaseUrl($argv);

    $build = in_array('--build', $argv, true) || in_array('-b', $argv, true);
    $help = in_array('--help', $argv, true) || in_array('-h', $argv, true);

    if ($help) {
        echo "Usage:\n";
        echo "  php playground.php --build   Generate playground.xlsx (defaults to http://localhost:" . PLAYGROUND_PORT . ")\n";
        echo "  php playground.php --build --base-url=http://localhost:" . PLAYGROUND_PORT . "   Override OData host for the workbook\n";
        echo "  php -S localhost:" . PLAYGROUND_PORT . " playground.php   Start OData server\n";
        exit(0);
    }

    if ($build || !is_file(playgroundXlsxPath())) {
        $path = buildPlaygroundXlsx($feeds);
        echo "Wrote {$path}\n";
        echo "OData URL: " . playgroundServiceRoot() . '/' . PLAYGROUND_FEED_ID . "/Employees\n";
        echo "Start server: php -S localhost:" . PLAYGROUND_PORT . " playground.php\n";
        exit(0);
    }

    echo "playground.xlsx already exists. Use --build to regenerate.\n";
    echo "Start server: php -S localhost:" . PLAYGROUND_PORT . " playground.php\n";
    exit(0);
}

// =============================================================================
// Entry point
// =============================================================================

if (PHP_SAPI === 'cli') {
    runCli($argv, $feeds);
}

$path = playgroundRequestPath();

try {
    if ($path === '/playground.xlsx') {
        if (!is_file(playgroundXlsxPath())) {
            buildPlaygroundXlsx($feeds);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: inline; filename="playground.xlsx"');
        readfile(playgroundXlsxPath());
        exit;
    }

    if ($path === '/favicon.ico') {
        http_response_code(204);
        exit;
    }

    if (strpos($path, '/odata') === 0) {
        handleODataRequest($feeds, $username, $password);
        exit;
    }

    renderHomePage($feeds, $username);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Playground error: ' . $e->getMessage() . PHP_EOL;
    echo 'PHP version: ' . PHP_VERSION . PHP_EOL;
    echo 'Location: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}