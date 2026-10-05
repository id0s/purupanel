# PuruPanel

PuruPanel adalah kontrol panel web hosting mandiri (self-hosted) berbasis PHP dan Nginx yang dirancang ringan, efisien, dan ditujukan untuk berjalan pada lingkungan Linux dengan sumber daya terbatas (seperti STB Armbian, Single Board Computer, VPS kecil, maupun Bare Metal Server).

Panel ini mengotomatiskan manajemen virtual host Nginx, wildcard subdomain, isolasi multi-framework (Laravel, CodeIgniter 4, WordPress, Generic PHP, Static HTML), reverse proxy manager, file manager terintegrasi, serta pemisahan basis data antara sistem panel dan aplikasi pengguna.

---

## 1. Rasional dan Alasan Pemilihan Tech Stack

Setiap komponen dalam PuruPanel dipilih berdasarkan pertimbangan efisiensi memori (RAM), beban CPU, keandalan operasional, dan kemudahan pemeliharaan pada server berspesifikasi terbatas (RAM 1 GB - 2 GB).

### A. PHP 8.4 Native (In-Engine Core)
- **Tanpa Overhead Framework**: Modul inti panel dibangun dengan PHP native tanpa framework besar (seperti Laravel atau Symfony). Hal ini mengeliminasi waktu inisialisasi framework (*bootstrap overhead*) dan konsumsi memori tambahan pada setiap request panel.
- **Efisiensi Memori**: PHP native hanya mengeksekusi kode yang diperlukan secara langsung, menjaga konsumsi RAM per request tetap berada pada kisaran 2 MB - 5 MB.
- **Ketersediaan Fitur Modern**: PHP 8.4 menyediakan fitur performa tinggi seperti JIT Compiler, Typed Properties, Readonly Classes, dan penanganan error yang ketat.

### B. Nginx Web Server
- **Arsitektur Event-Driven (Asynchronous)**: Berbeda dengan Apache prefork/worker yang membuat thread atau proses terpisah untuk setiap koneksi, Nginx menggunakan model non-blocking event-driven. Ini memungkinkan ribuan koneksi bersamaan ditangani dengan penggunaan memori yang konstan dan sangat rendah.
- **Performa Reverse Proxy & Static Files**: Nginx memiliki performa tercepat dalam menyajikan file statis dan melakukan proxy forwarding ke service internal (Docker, Node.js, Go, Python) dengan latensi minimal.
- **Konfigurasi Modular**: Direktori `purupanel-vhosts/` memungkinkan penambahan, pengeditan, dan penghapusan virtual host secara terisolasi tanpa menyentuh konfigurasi utama Nginx.

### C. SQLite 3 untuk Internal Panel Database
- **Nol Konsumsi RAM Saat Idle (Zero Background Footprint)**: SQLite adalah embedded engine (in-process) yang hanya aktif saat ada file `.db` yang dibaca atau ditulis. Berbeda dengan service database server yang berjalan terus-menerus dan memakan 100 MB - 300 MB RAM, SQLite mengonsumsi 0 MB RAM saat panel tidak diakses.
- **Isolasi Keandalan Sistem**: Jika database MySQL/MariaDB mengalami kelebihan beban (*OOM Killer* atau query berat) dan layanannya mati, **PuruPanel tetap berjalan normal** karena basis data internalnya terisolasi dalam file SQLite. Administrator tetap bisa login ke panel untuk memperbaiki atau me-restart layanan server.
- **Kecepatan Read/Write Tinggi dengan Mode WAL**: Penggunaan `PRAGMA journal_mode=WAL` (Write-Ahead Logging) memungkinkan operasi pembacaan dan penulisan berjalan konkuren tanpa saling mengunci (*non-blocking reads*).
- **Portabilitas dan Backup Instan**: Seluruh metadata panel (daftar situs, reverse proxy, riwayat log) tersimpan dalam satu file (`core/panel.db`), memudahkan proses pencadangan dan migrasi.

### D. MariaDB / MySQL untuk Database Aplikasi Pengguna
- **Pemisahan Peran**: MariaDB difokuskan sepenuhnya untuk menampung data aplikasi web klien (seperti WordPress, Laravel, CodeIgniter, e-commerce) yang membutuhkan fitur relational DBMS lengkap, foreign key constraints kompleks, dan konkurensi data besar.
- **Kompatibilitas Standar Industri**: Memastikan kompatibilitas penuh dengan sistem manajemen database populer seperti phpMyAdmin, DBeaver, dan CLI MySQL standar.

### E. Komunikasi Unix Domain Socket (UDS)
- **Menghindari TCP Overhead**: Komunikasi antara Nginx dan PHP-FPM menggunakan Unix socket (`unix:/run/php/php8.4-fpm.sock`) alih-alih port jaringan TCP lokal (`127.0.0.1:9000`).
- **Peningkatan Throughput**: Unix socket beroperasi langsung di dalam memori kernel Linux tanpa overhead routing TCP, checksum, maupun handshake koneksi.

### F. Antarmuka Web Native dan Monaco Editor
- **Tanpa Node.js Build Tooling pada Server**: Seluruh aset antarmuka panel disajikan secara statis tanpa memerlukan runtime Node.js atau proses compile build pada server produksi.
- **Monaco Code Editor**: Memberikan kemampuan pengeditan kode berstandar VS Code langsung di browser untuk kebutuhan penyesuaian file secara cepat.

---

## 2. Spesifikasi dan Kebutuhan Sistem

- **Sistem Operasi**: Debian 11/12/13, Ubuntu 22.04/24.04, atau Armbian (aarch64 / x86_64)
- **Web Server**: Nginx (versi 1.18 ke atas)
- **PHP**: PHP 8.2 / 8.3 / 8.4 (FPM) dengan ekstensi:
  - `php-fpm`
  - `php-sqlite3`
  - `php-mysql` / `php-mysqli` / `php-pdo`
  - `php-curl`
  - `php-zip`
  - `php-mbstring`
  - `php-xml`
  - `php-gd`
- **Database Server**: MariaDB / MySQL Server (untuk aplikasi klien) dan SQLite3 (untuk internal panel)
- **Akses**: Hak akses `root` atau `sudo` untuk konfigurasi awal

---

## 3. Struktur Direktori Proyek

```
purupanel/
├── assets/                  # File asset tambahan (CSS, JS, gambar)
├── core/                    # Logika inti dan database SQLite
│   ├── database.php         # Koneksi dan inisialisasi schema database internal
│   ├── panel.db             # Database SQLite internal panel
│   └── vhost_manager.php    # Modul manajemen virtual host, proxy, dan domain
├── nginx-configs/           # Referensi konfigurasi Nginx
│   └── purupanel.conf       # Template server block Nginx untuk PuruPanel
├── ssl/                     # Tempat penyimpanan sertifikat SSL wildcard / custom
├── templates/               # Template ZIP framework (laravel.zip, ci.zip)
├── apple-touch-icon.png     # Asset ikon aplikasi
├── favicon.ico              # Asset favicon
├── favicon.svg              # Asset favicon SVG
├── favicon-16x16.png        # Asset favicon 16x16
├── favicon-32x32.png        # Asset favicon 32x32
├── index.php                # Entry point utama aplikasi (UI, routing, API handler)
└── README.md                # Dokumentasi instalasi dan deployment
```

---

## 4. Panduan Deployment dari Awal (Fresh Server)

Berikut langkah demi langkah melakukan deployment PuruPanel pada server baru:

### Langkah 1: Update Sistem dan Instalasi Dependensi

Jalankan perintah berikut di terminal server:

```bash
sudo apt update && sudo apt upgrade -y

# Instalasi Nginx, PHP-FPM, SQLite, MariaDB, dan utility pendukung
sudo apt install -y nginx mariadb-server sqlite3 unzip git curl \
    php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mysql php8.4-curl \
    php8.4-zip php8.4-mbstring php8.4-xml php8.4-gd
```

*Catatan*: Jika server Anda menggunakan versi PHP lain (seperti PHP 8.3), sesuaikan nama package `php8.3-*` dan path socket PHP-FPM (`/run/php/php8.3-fpm.sock`).

---

### Langkah 2: Buat Struktur Direktori Sistem

PuruPanel membutuhkan beberapa folder utama untuk menyimpan website klien, virtual host Nginx, log, dan database:

```bash
# Buat direktori utama panel
sudo mkdir -p /var/www/purupanel
sudo mkdir -p /var/www/purupanel/sites
sudo mkdir -p /var/www/purupanel/logs
sudo mkdir -p /var/www/purupanel/ssl
sudo mkdir -p /var/www/purupanel/core
sudo mkdir -p /var/www/purupanel/templates

# Buat direktori vhost khusus Nginx untuk website yang dibuat PuruPanel
sudo mkdir -p /etc/nginx/purupanel-vhosts
```

---

### Langkah 3: Salin File Proyek ke Server

Salin seluruh source code PuruPanel ke direktori `/var/www/purupanel/`:

```bash
# Clone langsung dari GitHub:
sudo git clone https://github.com/id0s/purupanel.git /var/www/purupanel
```

---

### Langkah 4: Konfigurasi Hak Akses dan Permission

Web server `www-data` harus memiliki hak membaca dan menulis pada folder panel, database internal, serta direktori vhost:

```bash
sudo chown -R www-data:www-data /var/www/purupanel
sudo chmod -R 775 /var/www/purupanel
sudo chmod 777 /var/www/purupanel/core
sudo chmod 664 /var/www/purupanel/core/panel.db 2>/dev/null || true

# Berikan izin tulis ke direktori vhost Nginx
sudo chown -R www-data:www-data /etc/nginx/purupanel-vhosts
sudo chmod -R 775 /etc/nginx/purupanel-vhosts
```

---

### Langkah 5: Konfigurasi Sudoers (Agar PHP Dapat Mengelola Nginx)

PuruPanel perlu melakukan validasi sintaks (`nginx -t`) dan reload Nginx (`systemctl reload nginx`) secara otomatis saat pengguna menambah atau mengubah domain:

1. Buka file sudoers:
   ```bash
   sudo visudo -f /etc/sudoers.d/purupanel
   ```

2. Tambahkan baris konfigurasi berikut:
   ```text
   www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t, /usr/sbin/nginx -s reload, /usr/bin/systemctl reload nginx, /usr/bin/systemctl restart nginx, /usr/bin/systemctl is-active *
   ```

3. Simpan dan pastikan permission file sudoers benar:
   ```bash
   sudo chmod 0440 /etc/sudoers.d/purupanel
   ```

---

### Langkah 6: Konfigurasi Nginx Utama

1. Buka file konfigurasi utama Nginx `/etc/nginx/nginx.conf`:
   ```bash
   sudo nano /etc/nginx/nginx.conf
   ```

2. Pastikan di dalam blok `http { ... }` terdapat baris include untuk `purupanel-vhosts`:
   ```nginx
   include /etc/nginx/conf.d/*.conf;
   include /etc/nginx/sites-enabled/*;
   include /etc/nginx/purupanel-vhosts/*.conf;
   ```

3. Buat server block untuk PuruPanel di `/etc/nginx/sites-available/purupanel`:
   ```bash
   sudo nano /etc/nginx/sites-available/purupanel
   ```

   Isi dengan konfigurasi berikut:
   ```nginx
   server {
       listen 80 default_server;
       listen [::]:80 default_server;
       server_name _;

       root /var/www/purupanel;
       index index.php index.html;

       access_log /var/www/purupanel/logs/panel-access.log;
       error_log /var/www/purupanel/logs/panel-error.log;

       client_max_body_size 512M;

       location / {
           try_files $uri $uri/ /index.php?$args;
       }

       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/run/php/php8.4-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }

       location ~ /\. {
           deny all;
       }

       location ~ ^/core/ {
           deny all;
       }

       gzip on;
       gzip_types text/plain text/css application/json application/javascript text/xml application/xml;
       gzip_min_length 1000;
   }
   ```

4. Aktifkan konfigurasi dan reload Nginx:
   ```bash
   sudo ln -sfn /etc/nginx/sites-available/purupanel /etc/nginx/sites-enabled/purupanel
   sudo nginx -t
   sudo systemctl reload nginx
   ```

---

### Langkah 7: Pengaturan Domain dan Wildcard DNS

Untuk menggunakan fitur instant subdomain (contoh: `*.purujekuto.my.id`):
1. Masuk ke DNS Management domain Anda (misalnya Cloudflare).
2. Tambahkan DNS Record:
   - **Type**: `A`
   - **Name**: `@` -> `IP_PUBLIC_SERVER`
   - **Name**: `*` (Wildcard) -> `IP_PUBLIC_SERVER`
3. Sesuaikan setting `wildcard_domain` di database atau menu settings panel (default: `purujekuto.my.id`).

---

## 5. Kredensial dan Login Awal

Setelah deployment selesai, buka browser dan akses IP server atau domain Anda:
- **URL**: `http://IP_SERVER/` atau `http://panel.purujekuto.my.id/`
- **Username Default**: `admin`
- **Password Default**: `purupanel2024`

*Catatan Keamanan*: Ubah konstanta `PANEL_USER` dan `PANEL_PASS` pada baris awal file `index.php` untuk mengamankan panel di lingkungan production.

---

## 6. Fitur Utama

1. **Vhost & Domain Management**:
   - Pembuatan website instan dengan subdomain otomatis (`nama-subdomain.domain.com`) atau custom domain.
   - Deteksi dan isolasi root directory otomatis untuk framework (Laravel -> `/public`, CI4 -> `/public`, Generic -> `/public_html`).

2. **Reverse Proxy Manager**:
   - Meneruskan traffic domain/subdomain langsung ke port service internal (Docker container, Node.js, Python, Gunicorn, Go, dll).
   - Dukungan WebSocket Upgrade Header untuk aplikasi realtime.

3. **File Manager Terintegrasi**:
   - Monaco Code Editor untuk mengedit file kode langsung di browser.
   - Upload file, ekstraksi ZIP, manajemen permission, dan pembuatan file/folder baru.

4. **Database & phpMyAdmin Integration**:
   - Pembuatan database dan user MySQL/MariaDB per website.
   - Akses cepat phpMyAdmin terintegrasi.

---

## 7. Pemeliharaan dan Perintah Berguna

- **Cek Status Web Server**:
  ```bash
  sudo systemctl status nginx
  sudo systemctl status php8.4-fpm
  sudo systemctl status mariadb
  ```

- **Uji Konfigurasi Nginx**:
  ```bash
  sudo nginx -t
  ```

- **Melihat Log Error Panel**:
  ```bash
  tail -f /var/www/purupanel/logs/panel-error.log
  ```

- **Melihat Log Nginx Global**:
  ```bash
  tail -f /var/log/nginx/error.log
  ```
