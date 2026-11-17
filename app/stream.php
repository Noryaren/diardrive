<?php
declare(strict_types=1);

function stream_file(int $fileId): void
{
    $user = require_user();
    $userId = $user['id'];

    $stmt = db()->prepare('SELECT * FROM files WHERE id = ? AND user_id = ?');
    $stmt->execute([$fileId, $userId]);
    $file = $stmt->fetch();

    if (!$file || !is_file($file['disk_path'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File tidak ditemukan.';
        exit;
    }

    $filePath   = $file['disk_path'];
    $fileSize   = (int) $file['file_size'];
    $mimeType   = $file['mime_type'] ?: 'application/octet-stream';
    $origName   = $file['original_name'];
    $inline     = isset($_GET['inline']) && $_GET['inline'] === '1';

    $disposition = $inline ? 'inline' : 'attachment';

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename*=UTF-8\'\'' . rawurlencode($origName));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=10800');

    // Handle Range requests for video/audio streaming or large downloads
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && str_starts_with($range, 'bytes=')) {
        $rangeVal = substr($range, 6);
        [$rangeStart, $rangeEnd] = explode('-', $rangeVal, 2);

        $start = (int) $rangeStart;
        $end   = $rangeEnd !== '' ? (int) $rangeEnd : $fileSize - 1;
        $end   = min($end, $fileSize - 1);

        if ($start <= $end && $start < $fileSize) {
            $length = $end - $start + 1;

            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
            header("Content-Length: {$length}");

            $fp = fopen($filePath, 'rb');
            if ($fp !== false) {
                fseek($fp, $start);
                $buffer = 8192;
                $sent = 0;
                while ($sent < $length && !feof($fp)) {
                    $chunkSize = min($buffer, $length - $sent);
                    echo fread($fp, $chunkSize);
                    flush();
                    $sent += $chunkSize;
                }
                fclose($fp);
            }
            exit;
        }
    }

    header('Content-Length: ' . $fileSize);
    readfile($filePath);
    exit;
}
