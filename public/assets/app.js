(function () {
    'use strict';

    var state = {
        token: localStorage.getItem('diardrive_token'),
        user: null,
        currentFolderId: null,
        view: 'my', // 'my', 'starred', 'trash', 'admin'
        search: '',
        viewMode: localStorage.getItem('diardrive_view_mode') || 'grid', // 'grid', 'list'
        theme: localStorage.getItem('diardrive_theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'),
        breadcrumb: [],
        folders: [],
        files: [],
        users: [] // for admin view
    };

    document.documentElement.setAttribute('data-theme', state.theme);

    function h(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    async function api(path, options) {
        options = options || {};
        options.headers = options.headers || {};
        if (state.token) {
            options.headers['Authorization'] = 'Bearer ' + state.token;
        }

        var res = await fetch(DIAR.base + '/api' + path, options);
        if (res.status === 401) {
            logout();
            throw new Error('Silakan masuk kembali.');
        }

        var data = await res.json();
        if (!res.ok) {
            throw new Error(data.message || 'Terjadi kesalahan.');
        }

        return data;
    }

    function logout() {
        localStorage.removeItem('diardrive_token');
        state.token = null;
        state.user = null;
        initApp();
    }

    // Modal creation utility
    function showModal(title, bodyHtml, onConfirm, confirmText) {
        var modal = document.createElement('div');
        modal.className = 'modal-backdrop';
        modal.innerHTML =
            '<div class="modal-card">' +
                '<h2 class="modal-title">' + title + '</h2>' +
                '<div>' + bodyHtml + '</div>' +
                '<div class="modal-actions">' +
                    '<button class="btn btn-close">Batal</button>' +
                    '<button class="btn btn-primary btn-confirm">' + (confirmText || 'Konfirmasi') + '</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);

        modal.querySelector('.btn-close').addEventListener('click', function() {
            modal.remove();
        });

        modal.querySelector('.btn-confirm').addEventListener('click', async function() {
            var btn = modal.querySelector('.btn-confirm');
            btn.disabled = true;
            try {
                await onConfirm(modal);
                modal.remove();
            } catch(e) {
                alert(e.message);
                btn.disabled = false;
            }
        });
    }

    async function initApp() {
        if (!state.token) {
            renderLogin();
            return;
        }

        try {
            var data = await api('/auth/me');
            state.user = data.user;
            renderLayout();
            loadDrive();
        } catch (e) {
            renderLogin();
        }
    }

    function renderLogin() {
        var app = document.getElementById('app');
        app.innerHTML =
            '<div class="login-screen">' +
                '<form class="login-card" id="login-form">' +
                    '<div class="login-brand">' +
                        '<svg class="icon"><use href="#icon-drive"></use></svg>' +
                        '<span>DiarDrive</span>' +
                    '</div>' +
                    '<p class="login-tagline">Cloud Penyimpanan Pribadi di Server Anda</p>' +
                    '<div id="login-err" class="login-error" style="display:none;"></div>' +
                    '<div class="field">' +
                        '<label for="email">Email</label>' +
                        '<input type="email" id="email" required placeholder="email@anda.id">' +
                    '</div>' +
                    '<div class="field">' +
                        '<label for="password">Password</label>' +
                        '<input type="password" id="password" required placeholder="••••••••">' +
                    '</div>' +
                    '<button type="submit" class="btn btn-primary" style="width:100%;margin-top:10px;">Masuk</button>' +
                '</form>' +
            '</div>';

        document.getElementById('login-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            var errBox = document.getElementById('login-err');
            errBox.style.display = 'none';

            var email = document.getElementById('email').value;
            var password = document.getElementById('password').value;

            try {
                var data = await api('/auth/login', {
                    method: 'POST',
                    body: JSON.stringify({ email: email, password: password })
                });

                state.token = data.token;
                localStorage.setItem('diardrive_token', data.token);
                state.user = data.user;
                renderLayout();
                loadDrive();
            } catch (e) {
                errBox.innerText = e.message;
                errBox.style.display = 'block';
            }
        });
    }

    function renderLayout() {
        var app = document.getElementById('app');
        app.innerHTML =
            '<div class="app-layout">' +
                '<header class="app-header">' +
                    '<a href="#" class="header-brand" id="brand-logo">' +
                        '<svg class="icon"><use href="#icon-drive"></use></svg>' +
                        '<span>DiarDrive</span>' +
                    '</a>' +
                    '<div class="header-search">' +
                        '<svg class="icon icon-search"><use href="#icon-search"></use></svg>' +
                        '<input type="text" id="search-input" placeholder="Cari di DiarDrive" value="' + h(state.search) + '">' +
                    '</div>' +
                    '<div class="header-user">' +
                        '<button class="btn" id="theme-toggle" title="Ubah Tema">' +
                            '<svg class="icon"><use href="#icon-' + (state.theme === 'dark' ? 'sun' : 'moon') + '"></use></svg>' +
                        '</button>' +
                        '<div class="user-avatar">' + (state.user ? state.user.name.charAt(0).toUpperCase() : 'U') + '</div>' +
                        '<button class="btn btn-logout" id="logout-btn" title="Keluar">' +
                            '<svg class="icon"><use href="#icon-logout"></use></svg>' +
                        '</button>' +
                    '</div>' +
                '</header>' +
                '<aside class="app-sidebar">' +
                    '<div class="new-btn-wrapper">' +
                        '<button class="btn-new" id="new-action-btn">' +
                            '<svg class="icon"><use href="#icon-plus"></use></svg>' +
                            '<span>Baru</span>' +
                        '</button>' +
                    '</div>' +
                    '<nav class="nav-menu">' +
                        '<a href="#" class="nav-item active" data-view="my">' +
                            '<svg class="icon"><use href="#icon-folder"></use></svg>' +
                            '<span>Drive Saya</span>' +
                        '</a>' +
                        '<a href="#" class="nav-item" data-view="starred">' +
                            '<svg class="icon"><use href="#icon-star"></use></svg>' +
                            '<span>Berbintang</span>' +
                        '</a>' +
                        '<a href="#" class="nav-item" data-view="trash">' +
                            '<svg class="icon"><use href="#icon-trash"></use></svg>' +
                            '<span>Sampah</span>' +
                        '</a>' +
                        (state.user && state.user.is_admin ?
                        '<a href="#" class="nav-item" data-view="admin">' +
                            '<svg class="icon"><use href="#icon-user"></use></svg>' +
                            '<span>Kelola User</span>' +
                        '</a>' : '') +
                    '</nav>' +
                '</aside>' +
                '<main class="app-main">' +
                    '<div class="main-toolbar">' +
                        '<div class="breadcrumb" id="breadcrumb-container">Drive Saya</div>' +
                        '<div class="view-controls">' +
                            '<button class="btn" id="toggle-view-mode" title="Ubah Tampilan">' +
                                '<svg class="icon"><use href="#icon-' + (state.viewMode === 'grid' ? 'list' : 'grid') + '"></use></svg>' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="content-area" id="main-content"></div>' +
                '</main>' +
            '</div>';

        // Setup Event Listeners
        document.getElementById('logout-btn').addEventListener('click', logout);

        document.getElementById('theme-toggle').addEventListener('click', function() {
            state.theme = state.theme === 'dark' ? 'light' : 'dark';
            localStorage.setItem('diardrive_theme', state.theme);
            document.documentElement.setAttribute('data-theme', state.theme);
            this.querySelector('use').setAttribute('href', '#icon-' + (state.theme === 'dark' ? 'sun' : 'moon'));
        });

        document.getElementById('brand-logo').addEventListener('click', function(e) {
            e.preventDefault();
            navigateToFolder(null);
        });

        // Sidebar Navigation
        document.querySelectorAll('.nav-item').forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                document.querySelectorAll('.nav-item').forEach(function(el) { el.classList.remove('active'); });
                item.classList.add('active');
                state.view = item.getAttribute('data-view');
                state.currentFolderId = null;
                state.search = '';
                document.getElementById('search-input').value = '';
                loadDrive();
            });
        });

        // Search Input
        var searchTimeout = null;
        document.getElementById('search-input').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                state.search = e.target.value;
                loadDrive();
            }, 400);
        });

        // Grid/List toggle
        document.getElementById('toggle-view-mode').addEventListener('click', function() {
            state.viewMode = state.viewMode === 'grid' ? 'list' : 'grid';
            localStorage.setItem('diardrive_view_mode', state.viewMode);
            this.querySelector('use').setAttribute('href', '#icon-' + (state.viewMode === 'grid' ? 'list' : 'grid'));
            renderFiles();
        });

        // NEW Button (Upload & Create Folder)
        document.getElementById('new-action-btn').addEventListener('click', function() {
            showModal('Buat Folder Baru',
                '<div class="field"><label>Nama Folder</label><input type="text" id="new-folder-name" placeholder="Folder tanpa nama" required></div>',
                async function(modal) {
                    var name = modal.querySelector('#new-folder-name').value;
                    await api('/folders', {
                        method: 'POST',
                        body: JSON.stringify({ name: name, folder_id: state.currentFolderId })
                    });
                    loadDrive();
                }, 'Buat');
        });

        // Initialize drag and drop behavior
        setupDragAndDrop();
    }

    function setupDragAndDrop() {
        var contentArea = document.getElementById('main-content');
        if (!contentArea) return;

        ['dragenter', 'dragover'].forEach(function(eventName) {
            contentArea.addEventListener(eventName, function(e) {
                e.preventDefault();
                contentArea.style.background = 'var(--bg-hover)';
            }, false);
        });

        ['dragleave', 'drop'].forEach(function(eventName) {
            contentArea.addEventListener(eventName, function(e) {
                e.preventDefault();
                contentArea.style.background = '';
            }, false);
        });

        contentArea.addEventListener('drop', function(e) {
            var dt = e.dataTransfer;
            var files = dt.files;
            handleFilesUpload(files);
        }, false);
    }

    async function handleFilesUpload(files) {
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var formData = new FormData();
            formData.append('file', file);
            if (state.currentFolderId) {
                formData.append('folder_id', state.currentFolderId);
            }

            try {
                await fetch(DIAR.base + '/api/upload', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + state.token },
                    body: formData
                });
            } catch(e) {
                alert('Gagal mengunggah ' + file.name + ': ' + e.message);
            }
        }
        loadDrive();
    }

    async function loadDrive() {
        var content = document.getElementById('main-content');
        content.innerHTML = '<p style="text-align:center;padding:40px;">Memuat data...</p>';

        if (state.view === 'admin') {
            await loadAdminUsers();
            return;
        }

        try {
            var urlParams = '?view=' + state.view;
            if (state.currentFolderId) {
                urlParams += '&folder_id=' + state.currentFolderId;
            }
            if (state.search) {
                urlParams += '&search=' + encodeURIComponent(state.search);
            }

            var data = await api('/files' + urlParams);
            state.folders = data.folders;
            state.files = data.files;
            state.breadcrumb = data.breadcrumb || [];

            renderBreadcrumbs();
            renderFiles();
        } catch (e) {
            content.innerHTML = '<p class="login-error">' + e.message + '</p>';
        }
    }

    function renderBreadcrumbs() {
        var bc = document.getElementById('breadcrumb-container');
        if (state.view !== 'my') {
            bc.innerText = state.view === 'starred' ? 'Berbintang' : 'Sampah';
            return;
        }

        var html = '<span class="breadcrumb-item" data-id="">Drive Saya</span>';
        state.breadcrumb.forEach(function(folder) {
            html += ' <span style="color:var(--text-secondary);">&gt;</span> <span class="breadcrumb-item" data-id="' + folder.id + '">' + h(folder.name) + '</span>';
        });

        bc.innerHTML = html;

        bc.querySelectorAll('.breadcrumb-item').forEach(function(el) {
            el.addEventListener('click', function() {
                var folderId = el.getAttribute('data-id');
                navigateToFolder(folderId ? parseInt(folderId) : null);
            });
        });
    }

    function navigateToFolder(id) {
        state.currentFolderId = id;
        state.search = '';
        document.getElementById('search-input').value = '';
        loadDrive();
    }

    function renderFiles() {
        var content = document.getElementById('main-content');

        if (state.folders.length === 0 && state.files.length === 0) {
            content.innerHTML =
                '<div class="empty-state">' +
                    '<svg class="icon"><use href="#icon-drive"></use></svg>' +
                    '<h3>Tidak ada item di sini</h3>' +
                    '<p>Seret file ke sini untuk mengunggah.</p>' +
                '</div>';
            return;
        }

        var html = '';

        if (state.viewMode === 'grid') {
            // Render Folders
            if (state.folders.length > 0) {
                html += '<h3 class="section-title">Folder</h3><div class="file-grid">';
                state.folders.forEach(function(folder) {
                    html +=
                        '<div class="file-card folder-item" data-id="' + folder.id + '">' +
                            '<svg class="icon"><use href="#icon-folder"></use></svg>' +
                            '<div class="file-card-info">' +
                                '<div class="file-card-name">' + h(folder.name) + '</div>' +
                            '</div>' +
                            '<div class="file-card-actions">' +
                                '<button class="btn btn-close btn-folder-delete" data-id="' + folder.id + '" title="Hapus"><svg class="icon icon-sm"><use href="#icon-trash"></use></svg></button>' +
                            '</div>' +
                        '</div>';
                });
                html += '</div>';
            }

            // Render Files
            if (state.files.length > 0) {
                html += '<h3 class="section-title">File</h3><div class="file-grid">';
                state.files.forEach(function(file) {
                    var starIcon = file.is_starred ? '#icon-star' : '#icon-star';
                    var starClass = file.is_starred ? 'style="color:gold;"' : '';
                    html +=
                        '<div class="file-card file-item" data-id="' + file.id + '">' +
                            '<svg class="icon"><use href="#icon-file"></use></svg>' +
                            '<div class="file-card-info">' +
                                '<div class="file-card-name">' + h(file.name) + '</div>' +
                                '<div class="file-card-size">' + file.file_size_formatted + '</div>' +
                            '</div>' +
                            '<div class="file-card-actions">' +
                                (state.view === 'trash' ?
                                '<button class="btn btn-close btn-file-action" data-action="restore" data-id="' + file.id + '" title="Kembalikan"><svg class="icon icon-sm"><use href="#icon-trash-restore"></use></svg></button>' +
                                '<button class="btn btn-close btn-file-action" data-action="delete" data-id="' + file.id + '" title="Hapus Permanen"><svg class="icon icon-sm"><use href="#icon-trash"></use></svg></button>' :
                                '<button class="btn btn-close btn-file-action" data-action="star" data-starred="' + (file.is_starred ? 0 : 1) + '" data-id="' + file.id + '" title="Bintang" ' + starClass + '><svg class="icon icon-sm"><use href="' + starIcon + '"></use></svg></button>' +
                                '<button class="btn btn-close btn-file-action" data-action="trash" data-id="' + file.id + '" title="Buang"><svg class="icon icon-sm"><use href="#icon-trash"></use></svg></button>') +
                            '</div>' +
                        '</div>';
                });
                html += '</div>';
            }
        } else {
            // List / Table View
            html += '<table class="file-table"><thead><tr><th>Nama</th><th>Ukuran</th><th>Tanggal dibuat</th></tr></thead><tbody>';
            state.folders.forEach(function(folder) {
                html +=
                    '<tr class="folder-item" data-id="' + folder.id + '">' +
                        '<td class="name-cell"><svg class="icon"><use href="#icon-folder"></use></svg><span>' + h(folder.name) + '</span></td>' +
                        '<td>—</td>' +
                        '<td>' + folder.created_at + '</td>' +
                    '</tr>';
            });
            state.files.forEach(function(file) {
                html +=
                    '<tr class="file-item" data-id="' + file.id + '">' +
                        '<td class="name-cell"><svg class="icon"><use href="#icon-file"></use></svg><span>' + h(file.name) + '</span></td>' +
                        '<td>' + file.file_size_formatted + '</td>' +
                        '<td>' + file.created_at + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
        }

        content.innerHTML = html;

        // Folder & File clicks & actions setup
        content.querySelectorAll('.folder-item').forEach(function(el) {
            el.addEventListener('click', function(e) {
                if (e.target.closest('.btn')) return;
                navigateToFolder(parseInt(el.getAttribute('data-id')));
            });
        });

        content.querySelectorAll('.file-item').forEach(function(el) {
            el.addEventListener('click', function(e) {
                if (e.target.closest('.btn')) return;
                var fileId = el.getAttribute('data-id');
                window.open(DIAR.base + '/api/download/' + fileId + '?t=' + encodeURIComponent(state.token), '_blank');
            });
        });

        content.querySelectorAll('.btn-file-action').forEach(function(btn) {
            btn.addEventListener('click', async function(e) {
                e.stopPropagation();
                var action = btn.getAttribute('data-action');
                var id = btn.getAttribute('data-id');
                var starred = btn.getAttribute('data-starred');

                var body = { action: action, id: parseInt(id) };
                if (starred !== null) body.starred = parseInt(starred);

                await api('/files/action', {
                    method: 'POST',
                    body: JSON.stringify(body)
                });
                loadDrive();
            });
        });

        content.querySelectorAll('.btn-folder-delete').forEach(function(btn) {
            btn.addEventListener('click', async function(e) {
                e.stopPropagation();
                var id = btn.getAttribute('data-id');
                if (confirm('Apakah Anda yakin ingin menghapus folder ini beserta seluruh isinya secara permanen?')) {
                    await api('/folders/action', {
                        method: 'POST',
                        body: JSON.stringify({ action: 'delete', id: parseInt(id) })
                    });
                    loadDrive();
                }
            });
        });
    }

    async function loadAdminUsers() {
        var content = document.getElementById('main-content');
        var bc = document.getElementById('breadcrumb-container');
        bc.innerText = 'Kelola User';

        try {
            var data = await api('/admin/users');
            state.users = data.users;

            var html = '<button class="btn btn-primary" id="btn-add-user" style="margin-bottom:20px;">+ Tambah User Baru</button>' +
                       '<table class="file-table"><thead><tr><th>Nama</th><th>Email</th><th>Admin</th><th>Aksi</th></tr></thead><tbody>';

            state.users.forEach(function(u) {
                html += '<tr>' +
                            '<td>' + h(u.name) + '</td>' +
                            '<td>' + h(u.email) + '</td>' +
                            '<td>' + (u.is_admin ? 'Ya' : 'Tidak') + '</td>' +
                            '<td><button class="btn btn-danger btn-delete-user" data-id="' + u.id + '">Hapus</button></td>' +
                        '</tr>';
            });
            html += '</tbody></table>';

            content.innerHTML = html;

            document.getElementById('btn-add-user').addEventListener('click', function() {
                showModal('Tambah User Baru',
                    '<div class="field"><label>Nama</label><input type="text" id="add-user-name" required></div>' +
                    '<div class="field"><label>Email</label><input type="email" id="add-user-email" required></div>' +
                    '<div class="field"><label>Password</label><input type="password" id="add-user-pass" required></div>' +
                    '<div class="field"><label><input type="checkbox" id="add-user-admin"> Admin</label></div>',
                    async function(modal) {
                        var name = modal.querySelector('#add-user-name').value;
                        var email = modal.querySelector('#add-user-email').value;
                        var pass = modal.querySelector('#add-user-pass').value;
                        var admin = modal.querySelector('#add-user-admin').checked;

                        await api('/admin/users', {
                            method: 'POST',
                            body: JSON.stringify({ action: 'create', name: name, email: email, password: pass, is_admin: admin })
                        });
                        loadDrive();
                    }, 'Simpan'
                );
            });

            content.querySelectorAll('.btn-delete-user').forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    var id = btn.getAttribute('data-id');
                    if (confirm('Apakah Anda yakin ingin menghapus user ini?')) {
                        await api('/admin/users', {
                            method: 'POST',
                            body: JSON.stringify({ action: 'delete', id: parseInt(id) })
                        });
                        loadDrive();
                    }
                });
            });

        } catch(e) {
            content.innerHTML = '<p class="login-error">' + e.message + '</p>';
        }
    }

    document.addEventListener('DOMContentLoaded', initApp);
})();
