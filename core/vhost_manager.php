<?php
class VhostManager {
    private $db;
    private $settings;
    private $vhostDir;
    private $sitesPath;

    public function __construct() {
        $this->db = Database::getInstance()->getDb();
        $this->loadSettings();
        $this->vhostDir = $this->settings['vhost_config_path'];
        $this->sitesPath = $this->settings['sites_path'];
    }

    private function loadSettings() {
        $result = $this->db->query("SELECT key, value FROM panel_settings");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $this->settings[$row['key']] = $row['value'];
        }
    }

    public function getSetting($key) {
        return $this->settings[$key] ?? null;
    }

    public function getAllSites($userId = null, $role = 'admin') {
        if ($role === 'admin' || $userId === null) {
            $stmt = $this->db->prepare("SELECT * FROM sites WHERE (type != 'proxy' OR type IS NULL) ORDER BY created_at DESC");
        } else {
            $stmt = $this->db->prepare("SELECT * FROM sites WHERE (type != 'proxy' OR type IS NULL) AND user_id = :uid ORDER BY created_at DESC");
            $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        $sites = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sites[] = $row;
        }
        return $sites;
    }

    public function getSite($id) {
        $stmt = $this->db->prepare("SELECT * FROM sites WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_ASSOC);
    }

    public function getSiteByDomain($domain) {
        $stmt = $this->db->prepare("SELECT * FROM sites WHERE domain = :domain");
        $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_ASSOC);
    }

    public function resolveDomain($domainInput, $isCustom = false) {
        $input = trim(strtolower($domainInput));
        $input = preg_replace('#^https?://#', '', $input);
        $input = rtrim($input, '/');

        if ($isCustom || strpos($input, '.') !== false) {
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $input)) {
                return ['valid' => false, 'message' => 'Format custom domain tidak valid. Contoh: contoh.com atau web.sekolah.sch.id'];
            }
            return ['valid' => true, 'domain' => $input, 'is_custom' => true];
        } else {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i', $input)) {
                return ['valid' => false, 'message' => 'Format subdomain tidak valid (hanya huruf, angka, tanda minus)'];
            }
            $wildcard = $this->settings['wildcard_domain'];
            return ['valid' => true, 'domain' => $input . '.' . $wildcard, 'is_custom' => false];
        }
    }

    public function createSite($domainInput, $phpVersion = '8.4', $isCustom = false, $userId = 1, $createDb = true, $framework = 'generic') {
        $resolved = $this->resolveDomain($domainInput, $isCustom);
        if (!$resolved['valid']) {
            return ['success' => false, 'message' => $resolved['message']];
        }
        $domain = $resolved['domain'];
        
        // Check if domain already exists
        if ($this->getSiteByDomain($domain)) {
            return ['success' => false, 'message' => "Domain '{$domain}' sudah terdaftar"];
        }

        $allowedFrameworks = ['laravel', 'ci4', 'wordpress', 'generic', 'static'];
        if (!in_array($framework, $allowedFrameworks, true)) {
            $framework = 'generic';
        }

        $sitePath = $this->sitesPath . '/' . $domain;
        $logPath = $sitePath . '/logs';
        $tmpPath = $sitePath . '/tmp';

        $isPublicRoot = in_array($framework, ['laravel', 'ci4'], true);
        $vhostWebRoot = $isPublicRoot ? ($sitePath . '/public') : ($sitePath . '/public_html');
        // Project root stored in DB: for laravel/ci4, point to $sitePath so File Manager, PuruCode & Terminal
        // have access to all project files (.env, app, routes, artisan, composer.json).
        // For generic/wordpress/static, point to public_html where web files reside.
        $rootPathForDb = $isPublicRoot ? $sitePath : $vhostWebRoot;

        // Build directories according to framework
        if ($framework === 'laravel') {
            $dirs = [
                $sitePath . '/public',
                $sitePath . '/app/Http/Controllers',
                $sitePath . '/app/Models',
                $sitePath . '/bootstrap/cache',
                $sitePath . '/config',
                $sitePath . '/database/migrations',
                $sitePath . '/resources/views',
                $sitePath . '/routes',
                $sitePath . '/storage/app/public',
                $sitePath . '/storage/framework/cache/data',
                $sitePath . '/storage/framework/sessions',
                $sitePath . '/storage/framework/views',
                $sitePath . '/storage/logs',
                $logPath,
                $tmpPath
            ];
        } elseif ($framework === 'ci4') {
            $dirs = [
                $sitePath . '/public',
                $sitePath . '/app/Controllers',
                $sitePath . '/app/Models',
                $sitePath . '/app/Views',
                $sitePath . '/app/Config',
                $sitePath . '/writable/cache',
                $sitePath . '/writable/logs',
                $sitePath . '/writable/session',
                $logPath,
                $tmpPath
            ];
        } else {
            $dirs = [$vhostWebRoot, $logPath, $tmpPath];
        }

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                return ['success' => false, 'message' => 'Failed to create directory: ' . $dir];
            }
        }

        // Provision MySQL Database & User if requested
        $dbName = null;
        $dbUser = null;
        $dbPass = null;
        
        if ($createDb) {
            $subPart = explode('.', $domain)[0];
            $cleanSub = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $subPart));
            if (empty($cleanSub)) $cleanSub = 'site';
            $dbName = 'db_' . substr($cleanSub, 0, 14);
            $dbUser = 'u_' . substr($cleanSub, 0, 12);
            $dbPass = 'Puru_' . bin2hex(random_bytes(6));
            
            try {
                $pdo = new PDO('mysql:host=localhost', 'purupanel', 'purupanel_sql_2026', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                foreach ([$dbUser, $dbName] as $usr) {
                    foreach (['localhost', '127.0.0.1', '%'] as $hst) {
                        $pdo->exec("CREATE USER IF NOT EXISTS '{$usr}'@'{$hst}' IDENTIFIED BY '{$dbPass}'");
                        $pdo->exec("ALTER USER '{$usr}'@'{$hst}' IDENTIFIED BY '{$dbPass}'");
                        $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$usr}'@'{$hst}'");
                    }
                }
                $pdo->exec("FLUSH PRIVILEGES");
            } catch (Exception $e) {
                $this->logActivity('db_error', "Failed to auto-create DB for {$domain}: " . $e->getMessage());
            }
        }

        // Generate framework specific starter and landing files
        $this->provisionFrameworkBoilerplate($sitePath, $vhostWebRoot, $domain, $phpVersion, $framework, $dbName, $dbUser, $dbPass);

        // Set ownership
        exec("chown -R www-data:www-data " . escapeshellarg($sitePath));
        if ($framework === 'laravel') {
            @chmod($sitePath . '/storage', 0775);
            @chmod($sitePath . '/bootstrap/cache', 0775);
            exec("chmod -R 775 " . escapeshellarg($sitePath . '/storage') . " " . escapeshellarg($sitePath . '/bootstrap/cache') . " 2>/dev/null");
        } elseif ($framework === 'ci4') {
            @chmod($sitePath . '/writable', 0775);
            exec("chmod -R 775 " . escapeshellarg($sitePath . '/writable') . " 2>/dev/null");
        }

        // Create nginx vhost config
        $vhostConfig = $this->generateNginxConfig($domain, $vhostWebRoot, $logPath, $phpVersion, $framework);
        $configFile = $this->vhostDir . '/' . $domain . '.conf';
        file_put_contents($configFile, $vhostConfig);

        // Save to database with user_id, database credentials and framework
        $stmt = $this->db->prepare("INSERT INTO sites (domain, root_path, php_version, status, user_id, db_name, db_user, db_pass, framework) VALUES (:domain, :root, :php, 'active', :user_id, :db_name, :db_user, :db_pass, :framework)");
        $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
        $stmt->bindValue(':root', $rootPathForDb, SQLITE3_TEXT);
        $stmt->bindValue(':php', $phpVersion, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':db_name', $dbName, SQLITE3_TEXT);
        $stmt->bindValue(':db_user', $dbUser, SQLITE3_TEXT);
        $stmt->bindValue(':db_pass', $dbPass, SQLITE3_TEXT);
        $stmt->bindValue(':framework', $framework, SQLITE3_TEXT);
        $stmt->execute();

        // Reload nginx
        $this->reloadNginx();

        // Log activity
        $this->logActivity('create_site', "Created {$framework} site: $domain (vhost root: {$vhostWebRoot})");

        return [
            'success' => true,
            'message' => 'Website ' . strtoupper($framework) . ' berhasil di-deploy!',
            'domain' => $domain,
            'path' => $rootPathForDb,
            'vhost_root' => $vhostWebRoot,
            'framework' => $framework,
            'url' => 'http://' . $domain
        ];
    }

        public function deleteSite($id) {
        $site = $this->getSite($id);
        if (!$site) {
            return ['success' => false, 'message' => 'Site not found'];
        }

        $domain = $site['domain'];
        $configFile = $this->vhostDir . '/' . $domain . '.conf';
        $sitePath = $this->sitesPath . '/' . $domain;

        // Remove nginx config
        if (file_exists($configFile)) {
            unlink($configFile);
        }

        // Remove site files
        if (is_dir($sitePath)) {
            $this->recursiveDelete($sitePath);
        }

        // Remove from database
        $stmt = $this->db->prepare("DELETE FROM sites WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        // Reload nginx
        $this->reloadNginx();

        $this->logActivity('delete_site', "Deleted site: $domain");
        return ['success' => true, 'message' => 'Site deleted successfully'];
    }

    public function toggleSiteStatus($id) {
        $site = $this->getSite($id);
        if (!$site) {
            return ['success' => false, 'message' => 'Site not found'];
        }

        $newStatus = $site['status'] === 'active' ? 'disabled' : 'active';
        $stmt = $this->db->prepare("UPDATE sites SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->bindValue(':status', $newStatus, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        // Regenerate nginx config with new status
        $configFile = $this->vhostDir . '/' . $site['domain'] . '.conf';
        if ($newStatus === 'disabled') {
            // Rename config to .disabled
            if (file_exists($configFile)) {
                rename($configFile, $configFile . '.disabled');
            }
        } else {
            if (file_exists($configFile . '.disabled')) {
                rename($configFile . '.disabled', $configFile);
            }
        }

        $this->reloadNginx();
        $this->logActivity('toggle_site', "Site {$site['domain']} status: $newStatus");
        return ['success' => true, 'message' => "Site status changed to $newStatus"];
    }

    private function generateNginxConfig($domain, $rootPath, $logPath, $phpVersion, $framework = 'generic') {
        $phpSocket = "/run/php/php{$phpVersion}-fpm.sock";
        
        $serverNames = $domain;
        if (!str_starts_with($domain, 'www.') && substr_count($domain, '.') === 1) {
            $serverNames .= " www.{$domain}";
        }

        $config = "# {$domain} — PuruPanel vhost ({$framework})\n";
        $config .= "# Auto-generated: " . date('Y-m-d H:i:s') . "\n";
        $config .= "server {\n";
        $config .= "    listen 80;\n";
        $config .= "    listen [::]:80;\n";
        $config .= "    server_name {$serverNames};\n";
        $config .= "    root {$rootPath};\n";
        $config .= "    index index.php index.html index.htm;\n\n";
        
        // Large uploads & timeouts
        $config .= "    client_max_body_size 512M;\n";
        $config .= "    client_body_timeout 300s;\n\n";

        // Logs — separate per site
        $config .= "    access_log {$logPath}/access.log;\n";
        $config .= "    error_log {$logPath}/error.log;\n\n";
        
        // Security headers
        $config .= "    add_header X-Frame-Options \"SAMEORIGIN\" always;\n";
        $config .= "    add_header X-Content-Type-Options \"nosniff\" always;\n";
        $config .= "    add_header X-XSS-Protection \"1; mode=block\" always;\n";
        $config .= "    add_header Referrer-Policy \"no-referrer-when-downgrade\" always;\n\n";
        
        // Framework-tailored Clean URL Routing
        if ($framework === 'laravel' || $framework === 'ci4') {
            $config .= "    # " . strtoupper($framework) . " Clean URL rewrite\n";
            $config .= "    location / {\n";
            $config .= "        try_files \$uri \$uri/ /index.php?\$query_string;\n";
            $config .= "    }\n\n";
        } elseif ($framework === 'static' || $framework === 'spa') {
            $config .= "    # SPA / HTML fallback routing\n";
            $config .= "    location / {\n";
            $config .= "        try_files \$uri \$uri/ /index.html;\n";
            $config .= "    }\n\n";
        } else {
            // WordPress & Generic PHP
            $config .= "    # Standard PHP routing\n";
            $config .= "    location / {\n";
            $config .= "        try_files \$uri \$uri/ /index.php?\$args;\n";
            $config .= "    }\n\n";
        }
        
        // PHP-FPM FastCGI Handler
        $config .= "    location ~ \\.php$ {\n";
        $config .= "        include snippets/fastcgi-php.conf;\n";
        $config .= "        fastcgi_pass unix:{$phpSocket};\n";
        $config .= "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
        $config .= "        fastcgi_read_timeout 300s;\n";
        $config .= "        include fastcgi_params;\n";
        $config .= "    }\n\n";

        // Framework-specific security rules
        if ($framework === 'laravel') {
            $config .= "    # Block access to Laravel internal and sensitive files\n";
            $config .= "    location ~* ^/(composer\\.(json|lock)|package\\.(json|lock)|artisan|phpunit\\.xml|README\\.md) {\n";
            $config .= "        deny all;\n";
            $config .= "    }\n\n";
        } elseif ($framework === 'ci4') {
            $config .= "    # Block access to CodeIgniter 4 sensitive files\n";
            $config .= "    location ~* ^/(spark|composer\\.(json|lock)|env) {\n";
            $config .= "        deny all;\n";
            $config .= "    }\n\n";
        } elseif ($framework === 'wordpress') {
            $config .= "    # Protect WordPress wp-config & disable PHP execution in uploads\n";
            $config .= "    location ~* /wp-config\\.php {\n";
            $config .= "        deny all;\n";
            $config .= "    }\n";
            $config .= "    location ~* /(?:uploads|files)/.*\\.php$ {\n";
            $config .= "        deny all;\n";
            $config .= "    }\n\n";
        }
        
        // Deny hidden files except .well-known
        $config .= "    location ~ /\\.(?!well-known).* {\n";
        $config .= "        deny all;\n";
        $config .= "    }\n\n";
        
        // Deny sensitive files
        $config .= "    location ~* \\.(ini|log|sql|bak|swp|old|env)$ {\n";
        $config .= "        deny all;\n";
        $config .= "    }\n\n";
        
        // Static file caching
        $config .= "    location ~* \\.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {\n";
        $config .= "        expires 30d;\n";
        $config .= "        add_header Cache-Control \"public, immutable\";\n";
        $config .= "    }\n\n";
        
        // Gzip compression
        $config .= "    gzip on;\n";
        $config .= "    gzip_types text/plain text/css application/json application/javascript text/xml application/xml image/svg+xml;\n";
        $config .= "    gzip_min_length 1000;\n";
        $config .= "    gzip_vary on;\n";
        
        $config .= "}\n";
        
        return $config;
    }

    private function provisionFrameworkBoilerplate($sitePath, $vhostWebRoot, $domain, $phpVersion, $framework, $dbName, $dbUser, $dbPass) {
        $userIni = "upload_max_filesize = 512M\npost_max_size = 512M\nmax_execution_time = 300\nmemory_limit = 256M\n";
        $templatesDir = dirname(__DIR__) . '/templates';

        if ($framework === 'laravel') {
            $laravelZip = $templatesDir . '/laravel.zip';
            $extractedFromTemplate = false;

            if (file_exists($laravelZip)) {
                // Extract official Laravel starter template
                exec("/usr/bin/unzip -q -o " . escapeshellarg($laravelZip) . " -d " . escapeshellarg($sitePath) . " 2>&1", $unzipOut, $unzipCode);
                if ($unzipCode === 0) {
                    $extractedFromTemplate = true;
                }
            }

            // Ensure public directory and user.ini exist
            if (!is_dir($vhostWebRoot)) @mkdir($vhostWebRoot, 0755, true);
            file_put_contents($vhostWebRoot . '/.user.ini', $userIni);
            if (!file_exists($vhostWebRoot . '/robots.txt')) {
                file_put_contents($vhostWebRoot . '/robots.txt', "User-agent: *\nDisallow:\n");
            }

            // Generate .env file
            $appKey = 'base64:' . base64_encode(random_bytes(32));
            $dbNameStr = $dbName ?: 'laravel';
            $dbUserStr = $dbUser ?: 'root';
            $dbPassStr = $dbPass ?: '';

            $envContent = <<<ENV
APP_NAME=Laravel
APP_ENV=local
APP_KEY={$appKey}
APP_DEBUG=true
APP_TIMEZONE=Asia/Jakarta
APP_URL=http://{$domain}

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE={$dbNameStr}
DB_USERNAME={$dbUserStr}
DB_PASSWORD={$dbPassStr}

SESSION_DRIVER=file
SESSION_LIFETIME=120
QUEUE_CONNECTION=sync
ENV;
            file_put_contents($sitePath . '/.env', $envContent . "\n");
            file_put_contents($sitePath . '/.env.example', $envContent . "\n");

            // If not extracted from template, provide fallback boilerplate
            if (!$extractedFromTemplate) {
                if (!is_dir($sitePath . '/routes')) @mkdir($sitePath . '/routes', 0755, true);
                $routeContent = "<?php\n\nuse Illuminate\Support\Facades\Route;\n\nRoute::get('/', function () {\n    return view('welcome');\n});\n";
                file_put_contents($sitePath . '/routes/web.php', $routeContent);

                $indexContent = $this->getFrameworkLandingPage($domain, $phpVersion, 'laravel', $dbName, $dbUser, $dbPass);
                file_put_contents($vhostWebRoot . '/index.php', $indexContent);
            }

        } elseif ($framework === 'ci4') {
            $ciZip = $templatesDir . '/ci.zip';
            $extractedFromTemplate = false;

            if (file_exists($ciZip)) {
                // Extract official CodeIgniter 4 template
                exec("/usr/bin/unzip -q -o " . escapeshellarg($ciZip) . " -d " . escapeshellarg($sitePath) . " 2>&1", $unzipOut, $unzipCode);
                if ($unzipCode === 0) {
                    $extractedFromTemplate = true;
                }
            }

            // Ensure public directory and user.ini exist
            if (!is_dir($vhostWebRoot)) @mkdir($vhostWebRoot, 0755, true);
            file_put_contents($vhostWebRoot . '/.user.ini', $userIni);
            if (!file_exists($vhostWebRoot . '/robots.txt')) {
                file_put_contents($vhostWebRoot . '/robots.txt', "User-agent: *\nDisallow:\n");
            }

            $dbNameStr = $dbName ?: 'ci4';
            $dbUserStr = $dbUser ?: 'root';
            $dbPassStr = $dbPass ?: '';

            $envContent = <<<ENV
CI_ENVIRONMENT = development

app.baseURL = 'http://{$domain}/'
app.forceGlobalSecureRequests = false

database.default.hostname = 127.0.0.1
database.default.database = {$dbNameStr}
database.default.username = {$dbUserStr}
database.default.password = {$dbPassStr}
database.default.DBDriver = MySQLi
database.default.DBPrefix = ''
database.default.port = 3306
ENV;
            file_put_contents($sitePath . '/.env', $envContent . "\n");
            file_put_contents($sitePath . '/env', $envContent . "\n");

            // If not extracted from template, fallback
            if (!$extractedFromTemplate) {
                $indexContent = $this->getFrameworkLandingPage($domain, $phpVersion, 'ci4', $dbName, $dbUser, $dbPass);
                file_put_contents($vhostWebRoot . '/index.php', $indexContent);
            }

        } elseif ($framework === 'wordpress') {
            file_put_contents($vhostWebRoot . '/.user.ini', $userIni);
            file_put_contents($vhostWebRoot . '/robots.txt', "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n");

            $indexContent = $this->getFrameworkLandingPage($domain, $phpVersion, 'wordpress', $dbName, $dbUser, $dbPass);
            file_put_contents($vhostWebRoot . '/index.php', $indexContent);

        } elseif ($framework === 'static') {
            file_put_contents($vhostWebRoot . '/robots.txt', "User-agent: *\nDisallow:\n");

            $indexContent = $this->getFrameworkLandingPage($domain, $phpVersion, 'static', $dbName, $dbUser, $dbPass);
            file_put_contents($vhostWebRoot . '/index.html', $indexContent);

        } else {
            // generic php
            file_put_contents($vhostWebRoot . '/.user.ini', $userIni);
            file_put_contents($vhostWebRoot . '/robots.txt', "User-agent: *\nAllow: /\n");

            $indexContent = $this->getDefaultLandingPage($domain, $phpVersion);
            file_put_contents($vhostWebRoot . '/index.php', $indexContent);
        }
    }

    private function getFrameworkLandingPage($domain, $phpVersion, $framework, $dbName = null, $dbUser = null, $dbPass = null) {
        $meta = [
            'laravel' => [
                'name' => 'Laravel 11 Ready',
                'icon' => '🚀',
                'color' => '#FF2D20',
                'docroot' => '/public',
                'boot_code' => "if (file_exists(__DIR__ . '/../vendor/autoload.php') && file_exists(__DIR__ . '/../bootstrap/app.php')) {\n    require __DIR__ . '/../vendor/autoload.php';\n    \$app = require_once __DIR__ . '/../bootstrap/app.php';\n    \$kernel = \$app->make(Illuminate\\Contracts\\Http\\Kernel::class);\n    \$response = \$kernel->handle(\$request = Illuminate\\Http\\Request::capture())->send();\n    \$kernel->terminate(\$request, \$response);\n    exit;\n}"
            ],
            'ci4' => [
                'name' => 'CodeIgniter 4 Ready',
                'icon' => '🔥',
                'color' => '#EF4444',
                'docroot' => '/public',
                'boot_code' => "if (file_exists(__DIR__ . '/../vendor/autoload.php') && file_exists(__DIR__ . '/../app/Config/Paths.php')) {\n    define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);\n    require FCPATH . '../app/Config/Paths.php';\n    \$paths = new Config\\Paths();\n    require \$paths->systemDirectory . '/Boot.php';\n    exit(CodeIgniter\\Boot::bootWeb(\$paths));\n}"
            ],
            'wordpress' => [
                'name' => 'WordPress Ready',
                'icon' => '🌐',
                'color' => '#21759B',
                'docroot' => '/public_html',
                'boot_code' => "if (file_exists(__DIR__ . '/wp-config.php') || file_exists(__DIR__ . '/wp-load.php')) {\n    define('WP_USE_THEMES', true);\n    require __DIR__ . '/wp-blog-header.php';\n    exit;\n}"
            ],
            'static' => [
                'name' => 'Static & SPA Ready',
                'icon' => '⚡',
                'color' => '#0EA5E9',
                'docroot' => '/public_html',
                'boot_code' => ""
            ]
        ];

        $info = $meta[$framework] ?? $meta['laravel'];
        $bootPhp = $info['boot_code'] ? "<?php\n" . $info['boot_code'] . "\n?>\n" : "";

        $dbHtml = "";
        if ($dbName) {
            $dbHtml = <<<DBHTML
        <div style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:1.25rem; margin-top:1.25rem; text-align:left;">
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.08em; color:#94a3b8; font-weight:700; margin-bottom:0.75rem;">
                🔑 Pre-Provisioned MySQL Database
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:0.75rem; font-family:'JetBrains Mono',monospace; font-size:0.82rem;">
                <div><span style="color:#64748b;">DB:</span> <strong style="color:#38bdf8;">{$dbName}</strong></div>
                <div><span style="color:#64748b;">User:</span> <strong style="color:#38bdf8;">{$dbUser}</strong></div>
                <div><span style="color:#64748b;">Pass:</span> <strong style="color:#38bdf8;">{$dbPass}</strong></div>
                <div><span style="color:#64748b;">Host:</span> <strong style="color:#38bdf8;">127.0.0.1:3306</strong></div>
            </div>
        </div>
DBHTML;
        }

        return $bootPhp . <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$domain} — {$info['name']}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0B0F17;
            --card: #131A26;
            --border: rgba(255,255,255,0.08);
            --text: #F1F5F9;
            --muted: #94A3B8;
            --accent: {$info['color']};
            --radius: 18px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .container { max-width: 680px; width: 100%; }
        .hero-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 24px 48px -12px rgba(0,0,0,0.5);
            position: relative;
            overflow: hidden;
        }
        .hero-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
            background: var(--accent);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            color: var(--accent);
            margin-bottom: 1.25rem;
        }
        h1 {
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.5rem;
            color: #FFFFFF;
        }
        .sub {
            color: var(--muted);
            font-size: 0.95rem;
            margin-bottom: 2rem;
        }
        .specs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.85rem;
            margin-bottom: 1.5rem;
            text-align: left;
        }
        .spec-item {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border);
            padding: 1rem;
            border-radius: 12px;
        }
        .spec-label {
            font-size: 0.68rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.25rem;
        }
        .spec-val {
            font-size: 0.9rem;
            font-weight: 700;
            color: #FFFFFF;
            font-family: 'JetBrains Mono', monospace;
        }
        .steps {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.25rem;
            text-align: left;
            margin-top: 1.25rem;
        }
        .step-row {
            display: flex;
            gap: 0.85rem;
            padding: 0.6rem 0;
            font-size: 0.82rem;
            line-height: 1.45;
            color: #cbd5e1;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .step-row:last-child { border-bottom: none; }
        .step-num {
            width: 22px; height: 22px; border-radius: 50%;
            background: var(--accent); color: #FFFFFF;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72rem; font-weight: 800; flex-shrink: 0;
        }
        .code-box {
            background: #06090e;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.78rem;
            color: #38bdf8;
            margin-top: 0.4rem;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="hero-card">
            <div class="badge">
                <span>{$info['icon']}</span>
                <span>{$info['name']} Vhost Active</span>
            </div>
            <h1>{$domain}</h1>
            <p class="sub">Nginx Virtual Host & FastCGI Pool are pre-configured and live on Armbian STB</p>

            <div class="specs-grid">
                <div class="spec-item">
                    <div class="spec-label">Vhost Web Root</div>
                    <div class="spec-val">{$info['docroot']}</div>
                </div>
                <div class="spec-item">
                    <div class="spec-label">PHP Engine</div>
                    <div class="spec-val">PHP {$phpVersion} FPM</div>
                </div>
                <div class="spec-item">
                    <div class="spec-label">Upload Body Limit</div>
                    <div class="spec-val">512 MB</div>
                </div>
                <div class="spec-item">
                    <div class="spec-label">Clean URL Rewrite</div>
                    <div class="spec-val">Active</div>
                </div>
            </div>

            {$dbHtml}

            <div class="steps">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.08em; color:#94a3b8; font-weight:700; margin-bottom:0.4rem;">
                    🚀 Next Steps (Getting Started)
                </div>
                <div class="step-row">
                    <div class="step-num">1</div>
                    <div>
                        <strong>Upload Project Files:</strong> Gunakan <strong>File Manager</strong> di PuruPanel (mendukung Drag &amp; Drop Folder utuh!) atau SFTP untuk menaruh kode Anda.
                    </div>
                </div>
                <div class="step-row">
                    <div class="step-num">2</div>
                    <div>
                        <strong>Web Terminal &amp; CLI:</strong> Buka <strong>PuruCode Web IDE</strong> dan jalankan perintah langsung di root proyek:
                        <div class="code-box">composer install && php artisan key:generate</div>
                    </div>
                </div>
                <div class="step-row">
                    <div class="step-num">3</div>
                    <div>
                        <strong>Automated Seamless Boot:</strong> Ketika file <code>vendor/autoload.php</code> tersedia, PuruPanel otomatis mem-boot aplikasi Anda tanpa perlu menghapus halaman ini.
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

        private function getDefaultLandingPage($domain, $phpVersion) {
        $panelUrl = 'http://' . $_SERVER['HTTP_HOST'] ?? 'localhost';
        $year = date('Y');
        
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($domain) . ' — Ready</title>
    <style>
        :root {
            --bg: #0a0e17; --card: #111827; --border: #1e3a5f;
            --text: #c9d1d9; --muted: #8b949e; --accent: #38bdf8;
            --green: #22c55e; --orange: #f59e0b;
            --radius: 10px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg); color: var(--text);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
        }
        .wrapper { max-width: 680px; width: 100%; }
        .hero {
            text-align: center; padding: 3rem 2rem;
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); margin-bottom: 1.5rem;
        }
        .hero .icon { font-size: 3.5rem; margin-bottom: 1rem; }
        .hero h1 { font-size: 1.8rem; color: #e2e8f0; margin-bottom: 0.5rem; word-break: break-all; }
        .hero .domain { font-size: 1.1rem; color: var(--accent); font-weight: 500; word-break: break-all; }
        .hero .status {
            display: inline-block; margin-top: 1rem;
            background: rgba(34,197,94,0.12); color: var(--green);
            padding: 0.35rem 1.2rem; border-radius: 2rem;
            font-size: 0.85rem; font-weight: 500;
        }
        .hero .status .dot {
            display: inline-block; width: 8px; height: 8px;
            background: var(--green); border-radius: 50%; margin-right: 0.5rem;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

        .info-grid {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 1rem; margin-bottom: 1.5rem;
        }
        @media (max-width: 500px) { .info-grid { grid-template-columns: 1fr; } }
        .info-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 1.25rem;
        }
        .info-card .label { font-size: 0.7rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 0.4rem; }
        .info-card .value { font-size: 1rem; font-weight: 600; color: #e2e8f0; }
        .info-card .value code {
            background: rgba(56,189,248,0.1); color: var(--accent);
            padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.9rem;
        }
        .info-card .path {
            font-size: 0.8rem; color: var(--muted);
            font-family: "SF Mono", "Fira Code", monospace;
            word-break: break-all; margin-top: 0.25rem;
        }

        .steps {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 1.5rem;
        }
        .steps h3 { font-size: 0.95rem; color: #e2e8f0; margin-bottom: 1rem; }
        .step {
            display: flex; gap: 0.75rem; padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }
        .step:last-child { border-bottom: none; }
        .step-num {
            width: 28px; height: 28px; min-width: 28px;
            background: var(--border); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem; font-weight: 700; color: var(--accent);
        }
        .step-content { font-size: 0.85rem; line-height: 1.5; }
        .step-content strong { color: #e2e8f0; }
        .step-content code {
            background: rgba(56,189,248,0.08); color: var(--accent);
            padding: 0.1rem 0.4rem; border-radius: 3px; font-size: 0.82rem;
        }
        .step-content a { color: var(--accent); text-decoration: none; }
        .step-content a:hover { text-decoration: underline; }

        .footer {
            text-align: center; margin-top: 1.5rem;
            font-size: 0.75rem; color: var(--muted);
        }
        .footer a { color: var(--accent); text-decoration: none; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="hero">
        <div class="icon">🚀</div>
        <h1>' . htmlspecialchars($domain) . '</h1>
        <div class="domain">*.' . htmlspecialchars($this->settings['wildcard_domain']) . '</div>
        <div class="status"><span class="dot"></span>Site is Live</div>
    </div>

    <div class="info-grid">
        <div class="info-card">
            <div class="label">PHP Version</div>
            <div class="value"><code>PHP ' . htmlspecialchars($phpVersion) . '</code></div>
        </div>
        <div class="info-card">
            <div class="label">Web Root</div>
            <div class="path">/public_html/</div>
        </div>
        <div class="info-card">
            <div class="label">Server</div>
            <div class="value">Nginx + PHP-FPM</div>
        </div>
        <div class="info-card">
            <div class="label">Panel</div>
            <div class="value"><a href="' . $panelUrl . '" style="color:var(--accent);text-decoration:none;">PuruPanel</a></div>
        </div>
    </div>

    <div class="steps">
        <h3>📁 Getting Started</h3>
        <div class="step">
            <div class="step-num">1</div>
            <div class="step-content">
                <strong>Upload your files</strong> to <code>public_html/</code> via SFTP or the panel file manager.<br>
                <span style="color:var(--muted)">Delete this <code>index.php</code> file when ready.</span>
            </div>
        </div>
        <div class="step">
            <div class="step-num">2</div>
            <div class="step-content">
                <strong>PHP works out of the box</strong> — create <code>.php</code> files and they\'ll be executed with PHP ' . htmlspecialchars($phpVersion) . '.
            </div>
        </div>
        <div class="step">
            <div class="step-num">3</div>
            <div class="step-content">
                <strong>Check PHP info</strong> — visit <a href="/info.php">/info.php</a> after creating it to see your PHP configuration.
            </div>
        </div>
    </div>

    <div class="footer">
        Powered by <a href="' . $panelUrl . '">PuruPanel</a> &bull; ' . $year . '
    </div>
</div>
</body>
</html>';
    }

    public function reloadNginx() {
        exec('sudo /usr/sbin/nginx -t 2>&1', $output, $returnCode);
        if ($returnCode === 0) {
            exec('sudo /usr/sbin/nginx -s reload 2>&1', $reloadOutput, $reloadCode);
            return $reloadCode === 0;
        }
        return false;
    }

    public function getNginxTestResult() {
        exec('sudo /usr/sbin/nginx -t 2>&1', $output, $returnCode);
        return ['valid' => $returnCode === 0, 'output' => implode("\n", $output)];
    }

    private function recursiveDelete($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function logActivity($action, $details) {
        $stmt = $this->db->prepare("INSERT INTO activity_log (action, details) VALUES (:action, :details)");
        $stmt->bindValue(':action', $action, SQLITE3_TEXT);
        $stmt->bindValue(':details', $details, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function getActivityLog($limit = 50) {
        $result = $this->db->query("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT $limit");
        $logs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $logs[] = $row;
        }
        return $logs;
    }

    public function getDiskUsage($path) {
        $total = 0;
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $total += $file->getSize();
            }
        }
        return $total;
    }

    public function getSystemStats() {
        $diskFree = disk_free_space('/var/www/purupanel/sites');
        $diskTotal = disk_total_space('/var/www/purupanel/sites');
        $memInfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $memInfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $avail);
        
        $load = sys_getloadavg();
        
        return [
            'disk_free' => $diskFree,
            'disk_total' => $diskTotal,
            'disk_used_percent' => round(($diskTotal - $diskFree) / $diskTotal * 100, 1),
            'mem_total' => isset($total[1]) ? (int)$total[1] * 1024 : 0,
            'mem_available' => isset($avail[1]) ? (int)$avail[1] * 1024 : 0,
            'mem_used_percent' => isset($total[1], $avail[1]) ? round((1 - $avail[1]/$total[1]) * 100, 1) : 0,
            'load_1' => $load[0],
            'load_5' => $load[1],
            'load_15' => $load[2],
            'uptime' => $this->getUptime(),
            'php_version' => PHP_VERSION,
            'nginx_version' => $this->getNginxVersion(),
        ];
    }

    private function getUptime() {
        $uptime = file_get_contents('/proc/uptime');
        $uptime = explode(' ', $uptime)[0];
        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        $mins = floor(($uptime % 3600) / 60);
        return "{$days}d {$hours}h {$mins}m";
    }

    private function getNginxVersion() {
        exec('nginx -v 2>&1', $output);
        return isset($output[0]) ? str_replace('nginx version: nginx/', '', $output[0]) : 'Unknown';
    }

    public function updateSettings($settings) {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO panel_settings (key, value) VALUES (:key, :value)");
        foreach ($settings as $key => $value) {
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->bindValue(':value', $value, SQLITE3_TEXT);
            $stmt->execute();
        }
        return ['success' => true];
    }

    // ============================================
    // REVERSE PROXY METHODS
    // ============================================
    public function createProxy($domainInput, $targetUrl, $websocket = false, $isCustom = false) {
        $resolved = $this->resolveDomain($domainInput, $isCustom);
        if (!$resolved['valid']) {
            return ['success' => false, 'message' => $resolved['message']];
        }
        $domain = $resolved['domain'];

        if ($this->getSiteByDomain($domain)) {
            return ['success' => false, 'message' => "Domain '{$domain}' sudah terdaftar"];
        }

        // Validate target URL
        $target = trim($targetUrl);
        if (!preg_match('#^(https?://)?[\w.-]+(:\d+)?(/.*)?$#', $target)) {
            return ['success' => false, 'message' => 'Invalid target URL format'];
        }

        // Add http:// if no scheme
        if (!preg_match('#^https?://#', $target)) {
            $target = 'http://' . $target;
        }

        // Create nginx reverse proxy config
        $vhostConfig = $this->generateProxyNginxConfig($domain, $target, $websocket);
        $configFile = $this->vhostDir . '/' . $domain . '.conf';
        file_put_contents($configFile, $vhostConfig);

        // Save to database
        $stmt = $this->db->prepare("INSERT INTO sites (domain, root_path, type, proxy_target, proxy_websocket, status) VALUES (:domain, '', 'proxy', :target, :ws, 'active')");
        $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
        $stmt->bindValue(':target', $target, SQLITE3_TEXT);
        $stmt->bindValue(':ws', $websocket ? 1 : 0, SQLITE3_INTEGER);
        $stmt->execute();

        $this->reloadNginx();
        $this->logActivity('create_proxy', "Created proxy: $domain → $target");

        return [
            'success' => true,
            'message' => 'Proxy created',
            'domain' => $domain,
            'target' => $target,
            'url' => 'http://' . $domain
        ];
    }

    public function getProxySites() {
        $result = $this->db->query("SELECT * FROM sites WHERE type = 'proxy' ORDER BY created_at DESC");
        $sites = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sites[] = $row;
        }
        return $sites;
    }

    private function generateProxyNginxConfig($domain, $targetUrl, $websocket = false) {
        $parsed = parse_url($targetUrl);
        $upstream = $parsed['host'] . ':' . ($parsed['port'] ?? '80');
        $isHttps = ($parsed['scheme'] ?? 'http') === 'https';

        $serverNames = $domain;
        if (!str_starts_with($domain, 'www.') && substr_count($domain, '.') === 1) {
            $serverNames .= " www.{$domain}";
        }

        $config = "# {$domain} — PuruPanel Reverse Proxy\n";
        $config .= "# Auto-generated: " . date('Y-m-d H:i:s') . "\n";
        $config .= "# Target: {$targetUrl}\n";
        $config .= "server {\n";
        $config .= "    listen 80;\n";
        $config .= "    listen [::]:80;\n";
        $config .= "    server_name {$serverNames};\n\n";

        $config .= "    access_log /var/www/purupanel/logs/{$domain}-access.log;\n";
        $config .= "    error_log /var/www/purupanel/logs/{$domain}-error.log;\n\n";

        // Proxy settings
        $config .= "    location / {\n";
        $config .= "        proxy_pass {$targetUrl};\n";
        $config .= "        proxy_set_header Host \$host;\n";
        $config .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
        $config .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
        $config .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
        $config .= "        proxy_set_header X-Forwarded-Host \$host;\n";
        $config .= "        proxy_set_header X-Forwarded-Port \$server_port;\n\n";

        // Timeouts
        $config .= "        proxy_connect_timeout 60s;\n";
        $config .= "        proxy_send_timeout 60s;\n";
        $config .= "        proxy_read_timeout 60s;\n\n";

        // Buffering
        $config .= "        proxy_buffering off;\n";
        $config .= "        proxy_buffer_size 4k;\n";
        $config .= "        proxy_buffers 8 4k;\n\n";

        // WebSocket support
        if ($websocket) {
            $config .= "        # WebSocket support\n";
            $config .= "        proxy_http_version 1.1;\n";
            $config .= "        proxy_set_header Upgrade \$http_upgrade;\n";
            $config .= "        proxy_set_header Connection \"upgrade\";\n";
            $config .= "        proxy_read_timeout 86400s;\n\n";
        }

        // Redirect handling
        $config .= "        proxy_redirect off;\n";
        $config .= "    }\n\n";

        // Health check endpoint
        $config .= "    location /purupanel-health {\n";
        $config .= "        access_log off;\n";
        $config .= "        return 200 \"OK\";\n";
        $config .= "        add_header Content-Type text/plain;\n";
        $config .= "    }\n";

        $config .= "}\n";

        return $config;
    }

    public function updateProxy($id, $targetUrl, $websocket = false) {
        $site = $this->getSite($id);
        if (!$site || $site['type'] !== 'proxy') {
            return ['success' => false, 'message' => 'Proxy not found'];
        }

        $target = trim($targetUrl);
        if (!preg_match('#^(https?://)?[\w.-]+(:\d+)?(/.*)?$#', $target)) {
            return ['success' => false, 'message' => 'Invalid target URL'];
        }
        if (!preg_match('#^https?://#', $target)) {
            $target = 'http://' . $target;
        }

        // Regenerate config
        $vhostConfig = $this->generateProxyNginxConfig($site['domain'], $target, $websocket);
        $configFile = $this->vhostDir . '/' . $site['domain'] . '.conf';
        file_put_contents($configFile, $vhostConfig);

        // Update DB
        $stmt = $this->db->prepare("UPDATE sites SET proxy_target = :target, proxy_websocket = :ws, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->bindValue(':target', $target, SQLITE3_TEXT);
        $stmt->bindValue(':ws', $websocket ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        $this->reloadNginx();
        $this->logActivity('update_proxy', "Updated proxy: {$site['domain']} → {$target}");

        return ['success' => true, 'message' => 'Proxy updated'];
    }

    // ============================================
    // MULTI-TENANT USER METHODS
    // ============================================
    public function registerUser($username, $email, $password, $fullName = '') {
        $username = trim(strtolower($username));
        $email = trim(strtolower($email));
        
        // Validate
        if (empty($username) || empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Semua kolom wajib diisi'];
        }
        
        // Check existing
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = :u OR email = :e");
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $res = $stmt->execute();
        if ($res->fetchArray()) {
            return ['success' => false, 'message' => 'Username atau Email sudah terdaftar'];
        }
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("INSERT INTO users (username, email, password_hash, full_name, role, auth_provider) VALUES (:u, :e, :p, :f, 'member', 'local')");
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':f', $fullName ?: $username, SQLITE3_TEXT);
        $stmt->execute();
        
        $userId = $this->db->lastInsertRowID();
        return [
            'success' => true,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'email' => $email,
                'full_name' => $fullName ?: $username,
                'role' => 'member',
                'auth_provider' => 'local'
            ]
        ];
    }

    public function authenticateUser($usernameOrEmail, $password) {
        $input = trim(strtolower($usernameOrEmail));
        
        // Legacy admin fallback
        if (($input === 'admin' || $input === 'admin@purujekuto.my.id') && $password === 'purupanel2024') {
            return [
                'id' => 1,
                'username' => 'admin',
                'email' => 'admin@purujekuto.my.id',
                'full_name' => 'Console Administrator',
                'role' => 'admin',
                'auth_provider' => 'local'
            ];
        }
        
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = :in OR email = :in LIMIT 1");
        $stmt->bindValue(':in', $input, SQLITE3_TEXT);
        $res = $stmt->execute();
        $user = $res->fetchArray(SQLITE3_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }
        return null;
    }

    public function createOrGetGoogleUser($email, $name = '', $avatar = '') {
        $email = trim(strtolower($email));
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :e LIMIT 1");
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $res = $stmt->execute();
        $user = $res->fetchArray(SQLITE3_ASSOC);
        
        if ($user) {
            return $user;
        }
        
        // Register new Google member
        $username = explode('@', $email)[0];
        // Ensure unique username
        $baseUser = $username;
        $idx = 1;
        while (true) {
            $chk = $this->db->prepare("SELECT id FROM users WHERE username = :u");
            $chk->bindValue(':u', $username, SQLITE3_TEXT);
            if (!$chk->execute()->fetchArray()) break;
            $username = $baseUser . $idx++;
        }
        
        $dummyPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("INSERT INTO users (username, email, password_hash, full_name, avatar_url, role, auth_provider) VALUES (:u, :e, :p, :f, :a, 'member', 'google')");
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $stmt->bindValue(':p', $dummyPass, SQLITE3_TEXT);
        $stmt->bindValue(':f', $name ?: $username, SQLITE3_TEXT);
        $stmt->bindValue(':a', $avatar, SQLITE3_TEXT);
        $stmt->execute();
        
        $userId = $this->db->lastInsertRowID();
        return [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'full_name' => $name ?: $username,
            'avatar_url' => $avatar,
            'role' => 'member',
            'auth_provider' => 'google'
        ];
    }

}
