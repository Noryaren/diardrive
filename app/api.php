<?php
declare(strict_types=1);

/**
 * JSON API for DiarDrive.
 */

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new ApiException('Body JSON tidak valid.', 400);
    }

    return $data;
}

function api_dispatch(string $method, string $path): void
{
    $path = '/' . trim($path, '/');

    // --- Public endpoints ---
    if ($method === 'POST' && $path === '/auth/login') {
        $input    = json_input();
        $email    = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        $token = attempt_login($email, $password);
        if ($token === null) {
            throw new ApiException('Email atau password salah.', 401);
        }

        $user = current_user();
        json_response([
            'ok'    => true,
            'token' => $token,
            'user'  => $user,
        ]);
    }

    // --- Protected endpoints ---
    $user = require_user();
    $userId = $user['id'];

    if ($method === 'GET' && $path === '/auth/me') {
        json_response([
            'ok'   => true,
            'user' => $user,
        ]);
    }

    // List files & folders
    if ($method === 'GET' && $path === '/files') {
        $folderId = isset($_GET['folder_id']) && $_GET['folder_id'] !== '' ? (int) $_GET['folder_id'] : null;
        $view     = (string) ($_GET['view'] ?? 'my'); // my, starred, trash
        $search   = clean_search_term($_GET['search'] ?? '');

        // Breadcrumb path if folderId is set
        $breadcrumb = [];
        if ($folderId !== null && $view === 'my' && $search === '') {
            $curr = $folderId;
            while ($curr !== null) {
                $stmt = db()->prepare('SELECT id, name, parent_id FROM folders WHERE id = ? AND user_id = ?');
                $stmt->execute([$curr, $userId]);
                $fRow = $stmt->fetch();
                if (!$fRow) break;
                array_unshift($breadcrumb, ['id' => (int)$fRow['id'], 'name' => $fRow['name']]);
                $curr = $fRow['parent_id'] !== null ? (int)$fRow['parent_id'] : null;
            }
        }

        $folders = [];
        $files   = [];

        if ($search !== '') {
            // Search across all non-trashed (or trashed depending on view)
            $isTrash = $view === 'trash' ? 1 : 0;
            $sLike = like_term($search);

            $fStmt = db()->prepare('SELECT id, parent_id AS folder_id, name, created_at FROM folders WHERE user_id = ? AND name LIKE ? ORDER BY name ASC');
            $fStmt->execute([$userId, $sLike]);
            $folders = $fStmt->fetchAll();

            $fiStmt = db()->prepare('SELECT id, folder_id, name, original_name, mime_type, file_size, is_starred, is_trashed, created_at FROM files WHERE user_id = ? AND is_trashed = ? AND (name LIKE ? OR original_name LIKE ?) ORDER BY name ASC');
            $fiStmt->execute([$userId, $isTrash, $sLike, $sLike]);
            $files = $fiStmt->fetchAll();
        } else {
            if ($view === 'starred') {
                $fStmt = db()->prepare('SELECT id, parent_id AS folder_id, name, created_at FROM folders WHERE user_id = ? AND 0 = 1');
                $fStmt->execute([$userId]);
                $folders = $fStmt->fetchAll();

                $fiStmt = db()->prepare('SELECT id, folder_id, name, original_name, mime_type, file_size, is_starred, is_trashed, created_at FROM files WHERE user_id = ? AND is_starred = 1 AND is_trashed = 0 ORDER BY name ASC');
                $fiStmt->execute([$userId]);
                $files = $fiStmt->fetchAll();
            } elseif ($view === 'trash') {
                $fStmt = db()->prepare('SELECT id, parent_id AS folder_id, name, created_at FROM folders WHERE user_id = ? AND 0 = 1');
                $fStmt->execute([$userId]);
                $folders = $fStmt->fetchAll();

                $fiStmt = db()->prepare('SELECT id, folder_id, name, original_name, mime_type, file_size, is_starred, is_trashed, created_at FROM files WHERE user_id = ? AND is_trashed = 1 ORDER BY trashed_at DESC, name ASC');
                $fiStmt->execute([$userId]);
                $files = $fiStmt->fetchAll();
            } else {
                // Normal folder view
                $fStmt = db()->prepare('SELECT id, parent_id AS folder_id, name, created_at FROM folders WHERE user_id = ? AND ' . ($folderId === null ? 'parent_id IS NULL' : 'parent_id = ?') . ' ORDER BY name ASC');
                $fStmt->execute($folderId === null ? [$userId] : [$userId, $folderId]);
                $folders = $fStmt->fetchAll();

                $fiStmt = db()->prepare('SELECT id, folder_id, name, original_name, mime_type, file_size, is_starred, is_trashed, created_at FROM files WHERE user_id = ? AND is_trashed = 0 AND ' . ($folderId === null ? 'folder_id IS NULL' : 'folder_id = ?') . ' ORDER BY name ASC');
                $fiStmt->execute($folderId === null ? [$userId] : [$userId, $folderId]);
                $files = $fiStmt->fetchAll();
            }
        }

        // Format sizes & ids
        foreach ($folders as &$fol) {
            $fol['id'] = (int) $fol['id'];
            $fol['folder_id'] = $fol['folder_id'] !== null ? (int) $fol['folder_id'] : null;
            $fol['type'] = 'folder';
        }
        unset($fol);

        foreach ($files as &$fil) {
            $fil['id'] = (int) $fil['id'];
            $fil['folder_id'] = $fil['folder_id'] !== null ? (int) $fil['folder_id'] : null;
            $fil['file_size_formatted'] = format_bytes((int) $fil['file_size']);
            $fil['is_starred'] = (int) $fil['is_starred'] === 1;
            $fil['is_trashed'] = (int) $fil['is_trashed'] === 1;
            $fil['type'] = 'file';
        }
        unset($fil);

        json_response([
            'ok'         => true,
            'folder_id'  => $folderId,
            'breadcrumb' => $breadcrumb,
            'folders'    => $folders,
            'files'      => $files,
        ]);
    }

    // Create Folder
    if ($method === 'POST' && $path === '/folders') {
        $input    = json_input();
        $name     = trim((string) ($input['name'] ?? ''));
        $parentId = isset($input['folder_id']) && $input['folder_id'] !== '' ? (int) $input['folder_id'] : null;

        if ($name === '') {
            throw new ApiException('Nama folder tidak boleh kosong.', 400);
        }

        if ($parentId !== null) {
            $check = db()->prepare('SELECT id FROM folders WHERE id = ? AND user_id = ?');
            $check->execute([$parentId, $userId]);
            if (!$check->fetch()) {
                throw new ApiException('Folder induk tidak ditemukan.', 404);
            }
        }

        $stmt = db()->prepare('INSERT INTO folders (user_id, parent_id, name, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$userId, $parentId, mb_substr($name, 0, 255)]);

        json_response(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }

    // Upload File
    if ($method === 'POST' && $path === '/upload') {
        $folderId = isset($_POST['folder_id']) && $_POST['folder_id'] !== '' ? (int) $_POST['folder_id'] : null;

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new ApiException('File gagal diunggah atau tidak ada file yang dikirim.', 400);
        }

        $fileInfo = $_FILES['file'];
        $origName = basename((string) $fileInfo['name']);
        $tmpName  = (string) $fileInfo['tmp_name'];
        $fileSize = (int) $fileInfo['size'];
        $mimeType = mime_content_type($tmpName) ?: 'application/octet-stream';

        if ($folderId !== null) {
            $check = db()->prepare('SELECT id FROM folders WHERE id = ? AND user_id = ?');
            $check->execute([$folderId, $userId]);
            if (!$check->fetch()) {
                throw new ApiException('Folder tujuan tidak ditemukan.', 404);
            }
        }

        $userDir = user_storage_dir($userId);
        $diskName = bin2hex(random_bytes(16)) . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
        $diskPath = $userDir . '/' . $diskName;

        if (!move_uploaded_file($tmpName, $diskPath)) {
            throw new ApiException('Gagal menyimpan file ke disk.', 500);
        }

        $stmt = db()->prepare('INSERT INTO files (user_id, folder_id, name, original_name, mime_type, file_size, disk_path, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $userId,
            $folderId,
            mb_substr($origName, 0, 255),
            mb_substr($origName, 0, 255),
            mb_substr($mimeType, 0, 128),
            $fileSize,
            $diskPath,
        ]);

        json_response(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }

    // File Actions (Star, Trash, Restore, Delete, Rename)
    if ($method === 'POST' && $path === '/files/action') {
        $input  = json_input();
        $action = (string) ($input['action'] ?? '');
        $fileId = isset($input['id']) ? (int) $input['id'] : 0;

        if ($fileId <= 0) {
            throw new ApiException('ID file tidak valid.', 400);
        }

        $stmt = db()->prepare('SELECT * FROM files WHERE id = ? AND user_id = ?');
        $stmt->execute([$fileId, $userId]);
        $file = $stmt->fetch();

        if (!$file) {
            throw new ApiException('File tidak ditemukan.', 404);
        }

        if ($action === 'star') {
            $val = (int) ($input['starred'] ?? 1);
            $upd = db()->prepare('UPDATE files SET is_starred = ? WHERE id = ?');
            $upd->execute([$val ? 1 : 0, $fileId]);
            json_response(['ok' => true]);
        }

        if ($action === 'trash') {
            $upd = db()->prepare('UPDATE files SET is_trashed = 1, trashed_at = NOW() WHERE id = ?');
            $upd->execute([$fileId]);
            json_response(['ok' => true]);
        }

        if ($action === 'restore') {
            $upd = db()->prepare('UPDATE files SET is_trashed = 0, trashed_at = NULL WHERE id = ?');
            $upd->execute([$fileId]);
            json_response(['ok' => true]);
        }

        if ($action === 'delete') {
            // Permanent delete
            if (is_file($file['disk_path'])) {
                @unlink($file['disk_path']);
            }
            $del = db()->prepare('DELETE FROM files WHERE id = ?');
            $del->execute([$fileId]);
            json_response(['ok' => true]);
        }

        if ($action === 'rename') {
            $newName = trim((string) ($input['name'] ?? ''));
            if ($newName === '') {
                throw new ApiException('Nama baru tidak boleh kosong.', 400);
            }
            $upd = db()->prepare('UPDATE files SET name = ? WHERE id = ?');
            $upd->execute([mb_substr($newName, 0, 255), $fileId]);
            json_response(['ok' => true]);
        }

        throw new ApiException('Aksi tidak dikenal.', 400);
    }

    // Folder Actions (Delete folder, Rename folder)
    if ($method === 'POST' && $path === '/folders/action') {
        $input    = json_input();
        $action   = (string) ($input['action'] ?? '');
        $folderId = isset($input['id']) ? (int) $input['id'] : 0;

        if ($folderId <= 0) {
            throw new ApiException('ID folder tidak valid.', 400);
        }

        $stmt = db()->prepare('SELECT * FROM folders WHERE id = ? AND user_id = ?');
        $stmt->execute([$folderId, $userId]);
        $folder = $stmt->fetch();

        if (!$folder) {
            throw new ApiException('Folder tidak ditemukan.', 404);
        }

        if ($action === 'delete') {
            // Cascade delete files recursively or via FK
            // For safety, delete files in this folder first or let DB cascade
            $delFiles = db()->prepare('SELECT disk_path FROM files WHERE folder_id = ? AND user_id = ?');
            $delFiles->execute([$folderId, $userId]);
            while ($f = $delFiles->fetch()) {
                if (is_file($f['disk_path'])) @unlink($f['disk_path']);
            }
            $del = db()->prepare('DELETE FROM folders WHERE id = ?');
            $del->execute([$folderId]);
            json_response(['ok' => true]);
        }

        if ($action === 'rename') {
            $newName = trim((string) ($input['name'] ?? ''));
            if ($newName === '') {
                throw new ApiException('Nama baru tidak boleh kosong.', 400);
            }
            $upd = db()->prepare('UPDATE folders SET name = ? WHERE id = ?');
            $upd->execute([mb_substr($newName, 0, 255), $folderId]);
            json_response(['ok' => true]);
        }

        throw new ApiException('Aksi folder tidak dikenal.', 400);
    }

    // --- Admin Endpoints ---
    if ($user['is_admin']) {
        if ($method === 'GET' && $path === '/admin/users') {
            $stmt = db()->query('SELECT id, name, email, is_admin, created_at FROM users ORDER BY id ASC');
            $users = $stmt->fetchAll();
            foreach ($users as &$u) {
                $u['id'] = (int) $u['id'];
                $u['is_admin'] = (int) $u['is_admin'] === 1;
            }
            unset($u);
            json_response(['ok' => true, 'users' => $users]);
        }

        if ($method === 'POST' && $path === '/admin/users') {
            $input  = json_input();
            $action = (string) ($input['action'] ?? 'create');

            if ($action === 'create') {
                $name  = trim((string) ($input['name'] ?? ''));
                $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
                $pass  = (string) ($input['password'] ?? '');
                $admin = !empty($input['is_admin']) ? 1 : 0;

                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
                    throw new ApiException('Data user tidak valid (password minimal 6 karakter).', 400);
                }

                $chk = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $chk->execute([$email]);
                if ($chk->fetch()) {
                    throw new ApiException('Email sudah terdaftar.', 400);
                }

                $ins = db()->prepare('INSERT INTO users (name, email, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?, NOW())');
                $ins->execute([mb_substr($name, 0, 80), mb_substr($email, 0, 190), password_hash($pass, PASSWORD_DEFAULT), $admin]);

                json_response(['ok' => true, 'id' => (int) db()->lastInsertId()]);
            }

            if ($action === 'delete') {
                $targetId = (int) ($input['id'] ?? 0);
                if ($targetId === $userId) {
                    throw new ApiException('Tidak dapat menghapus akun sendiri.', 400);
                }
                // Delete user storage files
                $fStmt = db()->prepare('SELECT disk_path FROM files WHERE user_id = ?');
                $fStmt->execute([$targetId]);
                while ($f = $fStmt->fetch()) {
                    if (is_file($f['disk_path'])) @unlink($f['disk_path']);
                }
                $del = db()->prepare('DELETE FROM users WHERE id = ?');
                $del->execute([$targetId]);
                json_response(['ok' => true]);
            }

            throw new ApiException('Aksi admin tidak dikenal.', 400);
        }
    }

    throw new ApiException('Endpoint API tidak ditemukan.', 404);
}
