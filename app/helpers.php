<?php
declare(strict_types=1);

/**
 * Load configuration once. A local config/config.php overrides the defaults.
 */
function config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = [
            'app' => [
                'name'         => getenv('APP_NAME') ?: 'DiarDrive',
                'debug'        => filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOL),
                'timezone'     => 'UTC',
                'base_path'    => null,
                'trust_proxy'  => filter_var(getenv('TRUST_PROXY') ?: '1', FILTER_VALIDATE_BOOL),
                'token_ttl'    => 31536000,
            ],
            'db' => [
                'dsn'  => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=diardrive;charset=utf8mb4',
                'user' => getenv('DB_USER') ?: 'diardrive',
                'pass' => getenv('DB_PASS') ?: 'DiarDrive_DB_2026',
            ],
            'storage' => [
                'path' => getenv('STORAGE_PATH') ?: ROOT_PATH . '/storage',
            ],
        ];

        $file = ROOT_PATH . '/config/config.php';
        if (is_file($file)) {
            $local = require $file;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }
    }

    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Path prefix when the app is mounted in a sub-directory. Empty string at root.
 */
function base_path(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $configured = config('app.base_path');
    if ($configured !== null) {
        $base = rtrim('/' . trim((string) $configured, '/'), '/');
        if ($base === '/') {
            $base = '';
        }
        return $base;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (PHP_SAPI === 'cli-server' || $script === '' || !str_ends_with($script, '.php')) {
        return '';
    }

    $dir  = str_replace('\\', '/', dirname($script));
    $base = ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');

    return $base;
}

function url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return base_path() . ($path === '/' ? '/' : $path);
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (config('app.trust_proxy')) {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') {
            return true;
        }

        $cf = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
        if ($cf !== '' && str_contains($cf, '"scheme":"https"')) {
            return true;
        }
    }

    return false;
}

function csp_nonce(): string
{
    if (empty($GLOBALS['diardrive_nonce'])) {
        $GLOBALS['diardrive_nonce'] = base64_encode(random_bytes(18));
    }
    return $GLOBALS['diardrive_nonce'];
}

function storage_path(string $path = ''): string
{
    $base = rtrim((string) config('storage.path'), '/');
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function user_storage_dir(int $userId): string
{
    $dir = storage_path('user' . $userId);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return $dir;
}

function format_bytes(int $bytes, int $precision = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

function with_media_token(string $path): string
{
    $token = function_exists('auth_token') ? auth_token() : null;

    if ($token === null || $token === '') {
        return $path;
    }

    return $path . (str_contains($path, '?') ? '&' : '?') . 't=' . rawurlencode($token);
}

function clean_search_term(mixed $value): string
{
    return mb_substr(trim((string) $value), 0, 120);
}

function like_term(string $term): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
}
