<?php
declare(strict_types=1);

$nonce     = csp_nonce();
$name      = (string) config('app.name', 'DiarDrive');
$pageTitle = $name . ' — Cloud Penyimpanan Pribadi';

$clientConfig = [
    'base'    => base_path(),
    'version' => '1.0.0',
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>?v=<?= e((string) @filemtime(ROOT_PATH . '/public/assets/app.css')) ?>">
    <script nonce="<?= e($nonce) ?>" data-cfasync="false">
        window.DIAR = <?= json_encode($clientConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body>
    <?php require __DIR__ . '/icons.php'; ?>

    <div id="app" aria-live="polite">
        <div class="boot-screen" style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;color:#5f6368;">
            <p>Memuat <?= e($name) ?>…</p>
        </div>
    </div>

    <noscript>
        <div style="padding:20px;text-align:center;font-family:sans-serif;">
            <?= e($name) ?> membutuhkan JavaScript untuk berjalan. Aktifkan JavaScript di browser Anda.
        </div>
    </noscript>

    <script src="<?= e(url('/assets/app.js')) ?>?v=<?= e((string) @filemtime(ROOT_PATH . '/public/assets/app.js')) ?>" nonce="<?= e($nonce) ?>" data-cfasync="false" defer></script>
</body>
</html>
