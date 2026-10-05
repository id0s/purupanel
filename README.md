# PuruPanel

PuruPanel adalah kontrol panel web hosting mandiri (self-hosted) berbasis PHP dan Nginx yang dirancang ringan, efisien, dan cocok dijalankan pada server Linux (termasuk STB Armbian, VPS, atau Bare Metal).

Panel ini mendukung manajemen virtual host Nginx otomatis, wildcard subdomain, isolasi multi-framework (Laravel, CodeIgniter 4, WordPress, Generic PHP, Static HTML), reverse proxy manager, file manager, serta integrasi database MySQL/MariaDB dan SQLite.

---

## 1. Spesifikasi dan Kebutuhan Sistem

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
- **Database Server**: MariaDB / MySQL Server (untuk user database) dan SQLite3 (untuk database internal panel)
- **Akses**: Hak akses `root` atau `sudo` untuk konfigurasi awal

---

## 2. Struktur Direktori Proyek

```
purupanel/
├── assets/                  # File asset tambahan (CSS, JS, gambar)
├── core/                    # Logika inti dan database SQLite
│   ├── database.php         # Koneksi dan inisialisasi schema database internal
│   ├── panel.db             # Database SQLite internal panel
│   └── vhost_manager.php    # Modul manajemen virtual host, proxy, dan domain
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

## 3. Panduan Deployment dari Awal (Fresh Server)

### Langkah 1: Update Sistem dan Instalasi Dependensi

```bash
sudo apt update && sudo apt upgrade -y

# Instalasi Nginx, PHP-FPM, SQLite, MariaDB, dan utility pendukung
sudo apt install -y nginx mariadb-server sqlite3 unzip git curl     php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mysql php8.4-curl     php8.4-zip php8.4-mbstring php8.4-xml php8.4-gd
```

### Langkah 2: Buat Struktur Direktori Sistem

```bash
sudo mkdir -p /var/www/purupanel
sudo mkdir -p /var/www/purupanel/sites
sudo mkdir -p /var/www/purupanel/logs
sudo mkdir -p /var/www/purupanel/ssl
sudo mkdir -p /var/www/purupanel/core
sudo mkdir -p /var/www/purupanel/templates
sudo mkdir -p /etc/nginx/purupanel-vhosts
```

### Langkah 3: Salin File Proyek & Atur Hak Akses

```bash
sudo chown -R www-data:www-data /var/www/purupanel /etc/nginx/purupanel-vhosts
sudo chmod -R 775 /var/www/purupanel /etc/nginx/purupanel-vhosts
sudo chmod 777 /var/www/purupanel/core
sudo chmod 664 /var/www/purupanel/core/panel.db 2>/dev/null || true
```

### Langkah 4: Konfigurasi Sudoers (Agar PHP Dapat Mengelola Nginx)

File `/etc/sudoers.d/purupanel`:
```text
www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t, /usr/sbin/nginx -s reload, /usr/bin/systemctl reload nginx, /usr/bin/systemctl restart nginx, /usr/bin/systemctl is-active *
```

### Langkah 5: Konfigurasi Nginx Utama

1. Tambahkan baris include di `/etc/nginx/nginx.conf`:
   ```nginx
   include /etc/nginx/purupanel-vhosts/*.conf;
   ```

2. Konfigurasi server block `/etc/nginx/sites-available/purupanel` (listen 80, root `/var/www/purupanel`).
3. Aktifkan dan reload:
   ```bash
   sudo ln -sfn /etc/nginx/sites-available/purupanel /etc/nginx/sites-enabled/purupanel
   sudo nginx -t && sudo systemctl reload nginx
   ```
