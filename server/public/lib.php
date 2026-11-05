<?php
// Shared helpers for ingest.php and api.php. Requires PHP 8.1+ with PDO.
declare(strict_types=1);

ini_set('display_errors', '0');   // never leak stack traces to devices or browsers
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('[climate] ' . get_class($e) . ': ' . $e->getMessage());
    fail(500, 'server error');
});

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function fail(int $status, string $message): never
{
    json_out(['ok' => false, 'error' => $message], $status);
}

/** Load configuration from the first location that exists. */
function cfg(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $candidates = array_filter([
        getenv('CLIMATE_CONFIG') ?: null,
        dirname(__DIR__, 2) . '/climate-config.php',   // ~/climate-config.php when deployed to ~/public_html/climate/
        __DIR__ . '/config.php',
    ]);
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $config = require $path;
            return $config;
        }
    }
    error_log('[climate] no config file found');
    fail(500, 'server not configured');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = cfg()['db'];
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path'], null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    } else {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $options);
    }
    return $pdo;
}

function is_sqlite(): bool
{
    return (cfg()['db']['driver'] ?? 'mysql') === 'sqlite';
}

/** Driver-appropriate "insert, silently skip duplicates" keyword. */
function insert_ignore(): string
{
    return is_sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
}

function is_https(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    return ($https !== '' && strtolower($https) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

function valid_device_id(string $id): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/i', $id);
}

/** Return a float inside [min, max], or null if absent / non-numeric / out of range. */
function num_in_range(mixed $v, float $min, float $max): ?float
{
    if (!is_int($v) && !is_float($v)) {
        return null;
    }
    $f = (float)$v;
    return (is_finite($f) && $f >= $min && $f <= $max) ? $f : null;
}
