# DiarDrive — File Browser Self-Hosted ala Google Drive

Aplikasi penyimpanan cloud pribadi (self-hosted) dengan tampilan terinspirasi
Google Drive, dibangun di atas PHP 8.5 + MariaDB + Caddy + Cloudflare Tunnel.

## Fitur

- **Tampilan Google Drive**: Grid & List view, sidebar (My Drive / Starred / Trash), breadcrumb folder.
- **Upload & Download**: Drag & drop upload, download dengan dukungan Range (streaming video/audio).
- **Manajemen Folder**: Buat, rename, hapus folder.
- **Bintang & Trash**: Favorite file, soft-delete ke Trash, restore / permanent delete.
- **Multi-User**: Setiap user punya folder storage terpisah (`storage/user1`, `storage/user2`, dll).
- **JWT Auth**: Stateless, token tersimpan di `localStorage` browser, tidak ada session/cookie.
- **Admin Panel**: Superadmin bisa CRUD user melalui UI (di menu "Kelola User").

## Struktur Folder

```
diardrive/
├── app/
│   ├── api.php          # JSON API dispatcher
│   ├── auth.php         # JWT & authentication
│   ├── bootstrap.php    # Entry initialization
│   ├── db.php           # PDO connection
│   ├── helpers.php      # Utility functions
│   ├── stream.php       # File streaming w/ Range support
│   └── views/
│       ├── icons.php    # Inline SVG icons
│       └── shell.php    # SPA shell
├── bin/
│   ├── create-user.php  # CLI: buat user baru
│   └── selftest.php     # CLI: self-test koneksi & JWT
├── config/
│   ├── config.example.php
│   └── config.php       # (git-ignored)
├── database/
│   └── schema.sql       # DDL MariaDB
├── public/
│   ├── index.php        # Front controller
│   └── assets/
│       ├── app.css
│       ├── app.js
│       ├── favicon.svg
│       └── logo.svg
├── storage/             # User files (git-ignored)
├── deploy/
│   └── Caddyfile.example
└── .gitignore
```

## Instalasi (di Server)

1. Clone / copy project ke `/home/diardrive/`.
2. Jalankan `database/schema.sql` di MariaDB:
   ```bash
   mysql -h 127.0.0.1 -P 3307 -u diardrive -p diardrive < database/schema.sql
   ```
3. Sesuaikan `config/config.php` (DSN, user, pass, storage root).
4. Buat superadmin:
   ```bash
   php bin/create-user.php "Achmad Diaraji" achmad@diaraji.my.id "Neonecromancer@4321"
   ```
5. Pastikan `storage/` writable oleh `www-data`.
6. Tambahkan blok Caddy dari `deploy/Caddyfile.example` ke `/etc/caddy/Caddyfile`, lalu:
   ```bash
   sudo systemctl reload caddy
   ```
7. Tambahkan route di Cloudflare Tunnel untuk `folder.diaraji.my.id` → `http://localhost:80`.

## API Overview

Semua endpoint (kecuali `POST /auth/login`) membutuhkan header:
`Authorization: Bearer <token>`.

| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| POST | `/api/auth/login` | Login, return token + user |
| GET  | `/api/auth/me` | Ambil data user saat ini |
| GET  | `/api/files?view=my|starred|trash&folder_id=N&search=Q` | List files & folders |
| POST | `/api/folders` | Buat folder `{name, folder_id}` |
| POST | `/api/upload` | Upload file (multipart) |
| POST | `/api/files/action` | `{action: star|trash|restore|delete|rename, id, ...}` |
| POST | `/api/folders/action` | `{action: delete|rename, id, ...}` |
| GET  | `/api/download/:id` | Download file (Range support) |
| GET  | `/api/admin/users` | List users (admin only) |
| POST | `/api/admin/users` | `{action: create|delete, ...}` (admin only) |

## Keamanan

- JWT HS256, secret tersimpan di `storage/jwt.secret` (0600).
- Semua output HTML di-escape (`e()`).
- Semua query SQL pakai prepared statement.
- CSP header ketat, `nosniff`, `X-Frame-Options: DENY`.
- Upload: rename file random + timestamp, tidak ada eksekusi file di storage.
- Caddy memblokir akses langsung ke folder `storage/` dan `.php` di dalamnya.
