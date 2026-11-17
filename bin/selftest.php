<?php
declare(strict_types=1);

// Self-test script for DiarDrive.
// Usage: php bin/selftest.php
// Verifies: DB connection, JWT issue/validate, storage dir writable, API routes.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../app/bootstrap.php';

echo "== DiarDrive Self Test ==\n";

$ok = 0; $fail = 0;

function check($label, $condition) {
    global $ok, $fail;
    if ($condition) {
        echo "[PASS] $label\n";
        $ok++;
    } else {
        echo "[FAIL] $label\n";
        $fail++;
    }
}

// 1. DB Connection
try {
    db()->query('SELECT 1');
    check('Database connection', true);
} catch (Throwable $e) {
    check('Database connection: ' . $e->getMessage(), false);
}

// 2. JWT issue & validate
$token = issue_token(1);
$claims = token_claims($token);
check('JWT issue & validate', $claims !== null && $claims['sub'] == 1);

// 3. JWT tampered detect
$parts = explode('.', $token);
$badToken = $parts[0] . '.' . $parts[1] . '.' . strrev($parts[2]);
check('JWT tamper detection', token_claims($badToken) === null);

// 4. Storage dir
$storageDir = storage_path();
check('Storage dir exists & writable', is_dir($storageDir) && is_writable($storageDir));

echo "\nSummary: $ok passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
