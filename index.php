<?php
session_start();

// Security: simple auth (change these in production!)
define('PANEL_USER', 'admin');
define('PANEL_PASS', 'purupanel2024');

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/vhost_manager.php';

$vhost = new VhostManager();

// ============================================
// FILE MANAGER HELPER FUNCTIONS
// ============================================
function getSiteRoot($siteId, $vhost) {
    $site = $vhost->getSite($siteId);
    if (!$site) return null;
    $path = trim($site['root_path'] ?? '');
    if (empty($path)) {
        $defaultPath = '/var/www/purupanel/sites/' . $site['domain'];
        if (!is_dir($defaultPath)) {
            @mkdir($defaultPath, 0755, true);
            @chown($defaultPath, 'www-data');
        }
        return realpath($defaultPath) ?: $defaultPath;
    }
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
        @chown($path, 'www-data');
    }
    return realpath($path) ?: $path;
}

function getFrameworkBadgeMeta($fw) {
    $map = [
        'laravel' => [
            'name' => 'Laravel',
            'icon' => '🚀',
            'color' => '#FF2D20',
            'bg' => 'rgba(255, 45, 32, 0.1)',
            'border' => 'rgba(255, 45, 32, 0.25)',
            'vhost' => '/public'
        ],
        'ci4' => [
            'name' => 'CodeIgniter 4',
            'icon' => '🔥',
            'color' => '#EF4444',
            'bg' => 'rgba(239, 68, 68, 0.1)',
            'border' => 'rgba(239, 68, 68, 0.25)',
            'vhost' => '/public'
        ],
        'wordpress' => [
            'name' => 'WordPress',
            'icon' => '🌐',
            'color' => '#21759B',
            'bg' => 'rgba(33, 117, 155, 0.1)',
            'border' => 'rgba(33, 117, 155, 0.25)',
            'vhost' => '/public_html'
        ],
        'static' => [
            'name' => 'Static / SPA',
            'icon' => '⚡',
            'color' => '#0EA5E9',
            'bg' => 'rgba(14, 165, 233, 0.1)',
            'border' => 'rgba(14, 165, 233, 0.25)',
            'vhost' => '/public_html'
        ],
        'generic' => [
            'name' => 'PHP Native',
            'icon' => '🐘',
            'color' => '#6366F1',
            'bg' => 'rgba(99, 102, 241, 0.1)',
            'border' => 'rgba(99, 102, 241, 0.25)',
            'vhost' => '/public_html'
        ],
    ];
    return $map[$fw] ?? $map['generic'];
}

function safePath($baseRoot, $subPath, $allowNew = false) {
    $subPath = trim((string)$subPath, "/\\");
    if ($allowNew) {
        $parent = dirname($subPath);
        $parentFull = ($parent === '.' || $parent === '') ? $baseRoot : realpath($baseRoot . '/' . $parent);
        if (!$parentFull || strpos($parentFull, $baseRoot) !== 0) return null;
        $name = basename($subPath);
        if ($name === '' || $name === '.' || $name === '..') return null;
        return $parentFull . '/' . $name;
    }
    $full = realpath($baseRoot . ($subPath ? '/' . $subPath : ''));
    if (!$full || strpos($full, $baseRoot) !== 0) return null;
    return $full;
}
function fmTree($siteId, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];

    function buildDirTree($dir, $baseRoot, $depth = 0) {
        if ($depth > 7) return [];
        $entries = @scandir($dir);
        if (!$entries) return [];

        $dirs = [];
        $files = [];

        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            // hide .git
            if ($e === '.git') continue;

            $full = $dir . '/' . $e;
            $rel = ltrim(str_replace($baseRoot, '', $full), '/');
            $rel = str_replace('\\', '/', $rel);

            if (is_dir($full)) {
                $dirs[] = [
                    'name' => $e,
                    'path' => $rel,
                    'type' => 'dir',
                    'children' => buildDirTree($full, $baseRoot, $depth + 1)
                ];
            } else {
                $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
                $files[] = [
                    'name' => $e,
                    'path' => $rel,
                    'type' => 'file',
                    'ext' => $ext,
                    'size' => filesize($full),
                    'modified' => date('Y-m-d H:i', filemtime($full))
                ];
            }
        }
        usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return array_merge($dirs, $files);
    }

    $tree = buildDirTree($root, $root);
    return ['success' => true, 'tree' => $tree, 'root_path' => $root];
}

function fmSearch($siteId, $query, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $query = trim((string)$query);
    if (strlen($query) < 2) return ['success' => false, 'message' => 'Query minimal 2 karakter'];
    if (strlen($query) > 100) return ['success' => false, 'message' => 'Query terlalu panjang'];

    $cmd = sprintf('grep -rnI --exclude-dir=".git" --max-count=40 %s %s 2>/dev/null',
        escapeshellarg($query),
        escapeshellarg($root)
    );
    $output = shell_exec($cmd);
    $matches = [];
    if ($output) {
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (!$line) continue;
            $parts = explode(':', $line, 3);
            if (count($parts) >= 3) {
                $rel = ltrim(str_replace($root, '', $parts[0]), '/');
                $rel = str_replace('\\', '/', $rel);
                $matches[] = [
                    'file' => $rel,
                    'line' => (int)$parts[1],
                    'text' => trim($parts[2])
                ];
            }
        }
    }
    return ['success' => true, 'query' => $query, 'matches' => array_slice($matches, 0, 40)];
}

function fmFileList($siteId, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];

    $files = [];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $count = 0;
        foreach ($it as $f) {
            if ($count++ > 500) break;
            if ($f->isFile()) {
                $rel = ltrim(str_replace($root, '', $f->getRealPath()), '/');
                $rel = str_replace('\\', '/', $rel);
                $files[] = [
                    'path' => $rel,
                    'name' => $f->getBasename(),
                    'size' => $f->getSize(),
                    'ext' => strtolower($f->getExtension())
                ];
            }
        }
    } catch (Exception $e) {}
    return ['success' => true, 'files' => $files];
}

function fmPhpLint($siteId, $path, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $target = safePath($root, $path);
    if (!$target || !file_exists($target)) return ['success' => false, 'message' => 'File tidak ditemukan'];

    $output = shell_exec('php -l ' . escapeshellarg($target) . ' 2>&1');
    $cleanOutput = str_replace($root . '/', '', $output ?? '');
    $cleanOutput = str_replace($root, '', $cleanOutput);
    $hasError = (strpos($output ?? '', 'No syntax errors detected') === false);
    return [
        'success' => true,
        'has_error' => $hasError,
        'message' => trim($cleanOutput)
    ];
}

function termExec($siteId, $cmd, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $cmd = trim((string)$cmd);
    if (!$cmd) return ['success' => false, 'message' => 'Command kosong'];

    // Disallow dangerous system-level commands
    $blacklist = ['rm -rf /', 'mkfs', 'dd if=', ':(){', 'shutdown', 'reboot', 'init 0'];
    foreach ($blacklist as $bad) {
        if (stripos($cmd, $bad) !== false) {
            return ['success' => false, 'message' => 'Command diblokir demi keamanan!'];
        }
    }

    $bashCmd = 'cd ' . escapeshellarg($root) . ' && ' . $cmd;
    $output = shell_exec('timeout 180 bash -c ' . escapeshellarg($bashCmd) . ' 2>&1');
    return ['success' => true, 'output' => $output ?? '', 'cwd' => $root];
}


function fmListDir($siteId, $dir, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $target = safePath($root, $dir);
    if (!$target) return ['success' => false, 'message' => 'Invalid path'];
    if (!is_dir($target)) return ['success' => false, 'message' => 'Not a directory'];

    $items = [];
    $entries = scandir($target);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $target . '/' . $entry;
        $rel = ltrim($dir . '/' . $entry, '/');
        $items[] = [
            'name' => $entry,
            'path' => $rel,
            'type' => is_dir($full) ? 'dir' : 'file',
            'size' => is_file($full) ? filesize($full) : 0,
            'modified' => date('Y-m-d H:i', filemtime($full)),
            'perms' => substr(sprintf('%o', fileperms($full)), -3),
            'writable' => is_writable($full),
        ];
    }
    usort($items, function($a, $b) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
        return strcasecmp($a['name'], $b['name']);
    });

    return ['success' => true, 'items' => $items, 'current_dir' => $dir ?: '/', 'parent' => $dir ? dirname($dir) : null];
}

function fmUpload($siteId, $dir, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $target = safePath($root, $dir);
    if (!$target || !is_dir($target)) return ['success' => false, 'message' => 'Invalid directory'];

    $uploaded = [];
    $errors = [];
    $paths = $_POST['paths'] ?? [];
    $dirs = $_POST['dirs'] ?? [];

    // Pre-create any directories sent in dirs[] (including empty folders)
    if (!empty($dirs) && is_array($dirs)) {
        foreach ($dirs as $relDir) {
            $relDir = str_replace('\\', '/', trim((string)$relDir));
            $relDir = ltrim($relDir, '/');
            $parts = explode('/', $relDir);
            $cleanDir = [];
            foreach ($parts as $p) {
                if ($p === '' || $p === '.') continue;
                if ($p === '..') continue;
                $cleanDir[] = $p;
            }
            if (!empty($cleanDir)) {
                $destFolder = $target . '/' . implode('/', $cleanDir);
                if (!is_dir($destFolder)) {
                    @mkdir($destFolder, 0755, true);
                    @chown($destFolder, 'www-data');
                    @chgrp($destFolder, 'www-data');
                }
            }
        }
    }

    $processOne = function($tmpName, $relPath, $err) use ($root, $target, &$uploaded, &$errors) {
        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error ($err) for " . htmlspecialchars($relPath);
            return;
        }
        $relPath = str_replace('\\', '/', trim((string)$relPath));
        $relPath = ltrim($relPath, '/');
        $parts = explode('/', $relPath);
        $clean = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') {
                $errors[] = "Security violation: directory traversal in " . htmlspecialchars($relPath);
                return;
            }
            $clean[] = $p;
        }
        if (empty($clean)) {
            $errors[] = "Invalid filename";
            return;
        }

        $dest = $target . '/' . implode('/', $clean);
        $destDir = dirname($dest);

        if (!is_dir($destDir)) {
            if (!@mkdir($destDir, 0755, true)) {
                $errors[] = "Gagal membuat direktori: " . htmlspecialchars(dirname(implode('/', $clean)));
                return;
            }
            @chown($destDir, 'www-data');
            @chgrp($destDir, 'www-data');
        }

        $realDestDir = realpath($destDir);
        $realRoot = realpath($root);
        if (!$realDestDir || strpos($realDestDir, $realRoot) !== 0) {
            $errors[] = "Security validation failed for path";
            return;
        }

        if (move_uploaded_file($tmpName, $dest)) {
            @chown($dest, 'www-data');
            @chgrp($dest, 'www-data');
            $uploaded[] = implode('/', $clean);
        } else {
            $errors[] = "Gagal menyimpan file: " . htmlspecialchars(implode('/', $clean));
        }
    };

    if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
        $count = count($_FILES['files']['name']);
        for ($i = 0; $i < $count; $i++) {
            $tmp = $_FILES['files']['tmp_name'][$i];
            $err = $_FILES['files']['error'][$i];
            $rel = isset($paths[$i]) && $paths[$i] !== '' ? $paths[$i] : $_FILES['files']['name'][$i];
            $processOne($tmp, $rel, $err);
        }
    } else {
        $idx = 0;
        foreach ($_FILES as $k => $file) {
            if (is_array($file['name'])) {
                for ($j = 0; $j < count($file['name']); $j++) {
                    $tmp = $file['tmp_name'][$j];
                    $err = $file['error'][$j];
                    $rel = isset($paths[$idx]) && $paths[$idx] !== '' ? $paths[$idx] : $file['name'][$j];
                    $processOne($tmp, $rel, $err);
                    $idx++;
                }
            } else {
                $tmp = $file['tmp_name'];
                $err = $file['error'];
                $rel = isset($paths[$idx]) && $paths[$idx] !== '' ? $paths[$idx] : $file['name'];
                $processOne($tmp, $rel, $err);
                $idx++;
            }
        }
    }

    $ok = count($uploaded) > 0;
    $msg = $ok ? ('Berhasil upload ' . count($uploaded) . ' item') : (!empty($errors) ? implode('; ', $errors) : 'Tidak ada file diupload');
    return ['success' => $ok, 'message' => $msg, 'files' => $uploaded, 'errors' => $errors];
}

function fmDelete($siteId, $path, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $target = safePath($root, $path);
    if (!$target) return ['success' => false, 'message' => 'Invalid path'];
    if ($target === $root) return ['success' => false, 'message' => 'Cannot delete root'];

    if (is_dir($target)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath()); }
        rmdir($target);
    } else {
        unlink($target);
    }
    return ['success' => true, 'message' => 'Deleted'];
}

function fmRename($siteId, $old, $new, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $src = safePath($root, $old);
    if (!$src) return ['success' => false, 'message' => 'Invalid path'];
    $dst = dirname($src) . '/' . basename($new);
    if (!safePath($root, dirname($old) . '/' . basename($new))) return ['success' => false, 'message' => 'Invalid new name'];
    if (file_exists($dst)) return ['success' => false, 'message' => 'Already exists'];
    rename($src, $dst);
    return ['success' => true, 'message' => 'Renamed'];
}

function fmMkdir($siteId, $dir, $name, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $target = safePath($root, $dir);
    if (!$target || !is_dir($target)) return ['success' => false, 'message' => 'Invalid directory'];
    $newDir = $target . '/' . basename($name);
    if (file_exists($newDir)) return ['success' => false, 'message' => 'Already exists'];
    mkdir($newDir, 0755);
    chown($newDir, 'www-data');
    chgrp($newDir, 'www-data');
    return ['success' => true, 'message' => 'Folder created'];
}

function fmSaveFile($siteId, $path, $content, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $target = safePath($root, $path, true);
    if (!$target) return ['success' => false, 'message' => 'Invalid path'];
    if (is_dir($target)) return ['success' => false, 'message' => 'Cannot edit directory'];
    $dir = dirname($target);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($target, $content);
    chown($target, 'www-data');
    chgrp($target, 'www-data');
    return ['success' => true, 'message' => 'Saved'];
}

function fmReadFile($siteId, $path, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $target = safePath($root, $path);
    if (!$target || is_dir($target)) return ['success' => false, 'message' => 'Invalid file'];
    if (filesize($target) > 5242880) return ['success' => false, 'message' => 'File terlalu besar (>5MB)'];
    return ['success' => true, 'content' => file_get_contents($target), 'name' => basename($target)];
}

function fmExtractZip($siteId, $path, $vhost) {
    $root = getSiteRoot($siteId, $vhost);
    if (!$root) return ['success' => false, 'message' => 'Site not found'];
    $target = safePath($root, $path);
    if (!$target || !file_exists($target)) return ['success' => false, 'message' => 'File ZIP tidak ditemukan'];
    if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'zip') {
        return ['success' => false, 'message' => 'Hanya file .zip yang dapat diekstrak'];
    }

    $destDir = dirname($target);
    $cmd = "cd " . escapeshellarg($destDir) . " && /usr/bin/unzip -q -o " . escapeshellarg($target) . " 2>&1";
    exec($cmd, $out, $code);
    if ($code !== 0) {
        return ['success' => false, 'message' => 'Gagal mengekstrak ZIP: ' . implode("\n", $out)];
    }

    // Set ownership and permissions
    exec("chown -R www-data:www-data " . escapeshellarg($destDir));
    @chmod($destDir . '/storage', 0775);
    @chmod($destDir . '/bootstrap/cache', 0775);
    @chmod($destDir . '/writable', 0775);
    exec("chmod -R 775 " . escapeshellarg($destDir . '/storage') . " " . escapeshellarg($destDir . '/bootstrap/cache') . " " . escapeshellarg($destDir . '/writable') . " 2>/dev/null");

    return ['success' => true, 'message' => 'File ZIP berhasil diekstrak!'];
}

// Auth check & Session Data
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$currentUserId = $_SESSION['user_id'] ?? 1;
$currentUserEmail = $_SESSION['user_email'] ?? 'admin@purujekuto.my.id';
$currentUserName = $_SESSION['user_name'] ?? 'Console Admin';
$currentUserRole = $_SESSION['user_role'] ?? 'admin';
$currentUserAvatar = $_SESSION['user_avatar'] ?? '';
$authProvider = $_SESSION['auth_provider'] ?? 'local';

// Default action: landing page for visitors, dashboard for members
$action = $_GET['action'] ?? ($isLoggedIn ? 'dashboard' : 'landing');
$ajax = isset($_GET['ajax']);

// Handle login & registration
if (($action === 'login' || $action === 'register') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isRegisterMode = isset($_POST['password_confirm']) && !empty($_POST['password_confirm']);
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $passConfirm = $_POST['password_confirm'] ?? '';
    
    if ($isRegisterMode) {
        // Register new Member
        if ($pass !== $passConfirm) {
            $loginError = 'Konfirmasi password tidak cocok.';
        } elseif (strlen($pass) < 6) {
            $loginError = 'Password minimal 6 karakter.';
        } else {
            $email = str_contains($user, '@') ? $user : $user . '@' . ($vhost->getSetting('wildcard_domain') ?: 'purujekuto.my.id');
            $regRes = $vhost->registerUser($user, $email, $pass, $user);
            if ($regRes['success']) {
                $u = $regRes['user'];
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = $u['id'];
                $_SESSION['user_email'] = $u['email'];
                $_SESSION['user_name'] = $u['full_name'];
                $_SESSION['user_role'] = $u['role'];
                $_SESSION['auth_provider'] = 'local';
                $vhost->logActivity('auth_register', "New member registered: {$user} ({$email})");
                header('Location: ?action=dashboard');
                exit;
            } else {
                $loginError = $regRes['message'];
            }
        }
    } else {
        // Sign In
        $authRes = $vhost->authenticateUser($user, $pass);
        if ($authRes) {
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $authRes['id'];
            $_SESSION['user_email'] = $authRes['email'];
            $_SESSION['user_name'] = $authRes['full_name'] ?: $authRes['username'];
            $_SESSION['user_role'] = $authRes['role'] ?? 'member';
            $_SESSION['auth_provider'] = $authRes['auth_provider'] ?? 'local';
            $vhost->logActivity('auth_login', "Member logged in: {$user}");
            header('Location: ?action=dashboard');
            exit;
        } else {
            $loginError = 'Username atau Password salah. Silakan coba lagi.';
        }
    }
}

// Handle phpMyAdmin 1-Click SSO Token Generation & Redirect
if ($action === 'pma_sso') {
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        header('Location: ?action=login');
        exit;
    }
    $user = trim($_GET['user'] ?? '');
    $pass = trim($_GET['pass'] ?? '');
    
    if (empty($user) && !empty($_GET['site_id'])) {
        $site = $vhost->getSite((int)$_GET['site_id']);
        if ($site && !empty($site['db_user'])) {
            $user = $site['db_user'];
            $pass = $site['db_pass'];
        }
    }
    
    if (empty($user)) {
        die('Error: Database user not specified.');
    }
    
    $token = bin2hex(random_bytes(24));
    $payload = [
        'user' => $user,
        'pass' => $pass,
        'expires' => time() + 90
    ];
    @file_put_contents('/tmp/pma_sso_' . $token . '.json', json_encode($payload));
    @chmod('/tmp/pma_sso_' . $token . '.json', 0666);
    
    // Choose appropriate host based on request
    $reqHost = $_SERVER['HTTP_HOST'] ?? '';
    $wildcard = $vhost->getSetting('wildcard_domain') ?: 'purujekuto.my.id';
    
    // If accessing via IP directly
    if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $reqHost)) {
        $ipOnly = explode(':', $reqHost)[0];
        $redirectUrl = "http://{$ipOnly}/phpmyadmin/index.php?server=3&sso_token={$token}";
    } else {
        $redirectUrl = "http://pma.{$wildcard}/index.php?server=3&sso_token={$token}";
    }
    
    header("Location: {$redirectUrl}");
    exit;
}

// Handle Google SSO Login initiation
if ($action === 'google_login') {
    $clientId = $vhost->getSetting('google_client_id');
    $clientSecret = $vhost->getSetting('google_client_secret');
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $redirectUri = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/?action=google_callback';
    
    if (!empty($clientId) && !empty($clientSecret) && $clientId !== 'demo') {
        // Real Google OAuth 2.0 flow
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account'
        ]);
        header("Location: $authUrl");
        exit;
    } else {
        // Instant Google SSO Demo for STB (allows testing SSO right away)
        if (isset($_GET['confirm']) && $_GET['confirm'] === '1') {
            $gUser = $vhost->createOrGetGoogleUser('admin@purujekuto.my.id', 'Console Administrator', 'https://lh3.googleusercontent.com/a/default-user');
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $gUser['id'] ?? 1;
            $_SESSION['user_email'] = $gUser['email'] ?? 'admin@purujekuto.my.id';
            $_SESSION['user_name'] = $gUser['full_name'] ?? 'Console Administrator';
            $_SESSION['user_role'] = $gUser['role'] ?? 'admin';
            $_SESSION['user_avatar'] = 'https://lh3.googleusercontent.com/a/default-user';
            $_SESSION['auth_provider'] = 'google';
            $vhost->logActivity('auth_login', 'User logged in via Google SSO (Demo Account: admin@purujekuto.my.id)');
            header('Location: ?action=dashboard');
            exit;
        }
        // Redirect to login with google_dialog modal triggered
        header('Location: ?action=login&sso_dialog=1');
        exit;
    }
}

// Handle Google OAuth callback
if ($action === 'google_callback') {
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    
    if (!empty($code)) {
        $clientId = $vhost->getSetting('google_client_id');
        $clientSecret = $vhost->getSetting('google_client_secret');
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $redirectUri = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/?action=google_callback';
        
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ]));
        $res = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($res, true);
        if (!empty($data['access_token'])) {
            $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $data['access_token']]);
            $userRes = curl_exec($ch);
            curl_close($ch);
            
            $userInfo = json_decode($userRes, true);
            if (!empty($userInfo['email'])) {
                $gUser = $vhost->createOrGetGoogleUser($userInfo['email'], $userInfo['name'] ?? '', $userInfo['picture'] ?? '');
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = $gUser['id'] ?? 1;
                $_SESSION['user_email'] = $gUser['email'] ?? $userInfo['email'];
                $_SESSION['user_name'] = $gUser['full_name'] ?? ($userInfo['name'] ?? explode('@', $userInfo['email'])[0]);
                $_SESSION['user_role'] = $gUser['role'] ?? 'admin';
                $_SESSION['user_avatar'] = $userInfo['picture'] ?? '';
                $_SESSION['auth_provider'] = 'google';
                $vhost->logActivity('auth_login', 'User logged in via Google SSO (' . $userInfo['email'] . ')');
                header('Location: ?action=dashboard');
                exit;
            }
        }
    }
    header('Location: ?action=login&error=oauth_failed');
    exit;
}

// Handle logout
if ($action === 'logout') {
    session_destroy();
    header('Location: ?action=landing');
    exit;
}

// Raw file streaming (images, downloads)
if ($action === 'fm_raw' && $isLoggedIn) {
    $siteId = (int)($_GET['site_id'] ?? 0);
    $path = $_GET['path'] ?? '';
    $root = getSiteRoot($siteId, $vhost);
    if ($root) {
        $target = safePath($root, $path);
        if ($target && is_file($target)) {
            $mime = mime_content_type($target) ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($target));
            if (isset($_GET['download'])) {
                header('Content-Disposition: attachment; filename="' . basename($target) . '"');
            }
            readfile($target);
            exit;
        }
    }
    http_response_code(404);
    echo "File not found";
    exit;
}

// AJAX handlers
if ($ajax && $isLoggedIn) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Unknown action'];
    
    try {
        switch ($action) {
            case 'create_site':
                $domainType = $_POST['domain_type'] ?? 'subdomain';
                $phpVersion = $_POST['php_version'] ?? '8.4';
                $framework = trim($_POST['framework'] ?? 'laravel');
                $isCustom = ($domainType === 'custom');
                $domainInput = $isCustom ? trim($_POST['custom_domain'] ?? '') : trim($_POST['subdomain'] ?? '');
                $createDb = !isset($_POST['create_db']) || $_POST['create_db'] === '1' || $_POST['create_db'] === 'true';
                $userId = (int)($_SESSION['user_id'] ?? 1);
                
                if (empty($domainInput)) {
                    $response = ['success' => false, 'message' => ($isCustom ? 'Custom domain wajib diisi' : 'Subdomain wajib diisi')];
                } else {
                    $response = $vhost->createSite($domainInput, $phpVersion, $isCustom, $userId, $createDb, $framework);
                }
                break;
                
            case 'delete_site':
                $id = (int)($_POST['id'] ?? $_POST['site_id'] ?? 0);
                $response = $vhost->deleteSite($id);
                break;
                
            case 'toggle_site':
                $id = (int)($_POST['id'] ?? $_POST['site_id'] ?? 0);
                $response = $vhost->toggleSiteStatus($id);
                break;
                
            case 'get_stats':
                $response = ['success' => true, 'stats' => $vhost->getSystemStats()];
                break;
                
            case 'nginx_test':
                $response = $vhost->getNginxTestResult();
                break;
                
            case 'update_settings':
                $settings = [];
                if (isset($_POST['panel_name'])) $settings['panel_name'] = trim($_POST['panel_name']);
                if (isset($_POST['wildcard_domain'])) $settings['wildcard_domain'] = trim($_POST['wildcard_domain']);
                if (isset($_POST['default_php_version'])) $settings['default_php_version'] = trim($_POST['default_php_version']);
                if (isset($_POST['max_sites'])) $settings['max_sites'] = trim($_POST['max_sites']);
                if (isset($_POST['google_client_id'])) $settings['google_client_id'] = trim($_POST['google_client_id']);
                if (isset($_POST['google_client_secret'])) $settings['google_client_secret'] = trim($_POST['google_client_secret']);
                $response = $vhost->updateSettings($settings);
                break;

            // File Manager actions
            case 'fm_list':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $dir = $_POST['dir'] ?? '';
                $response = fmListDir($siteId, $dir, $vhost);
                break;
            case 'fm_upload':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $dir = $_POST['dir'] ?? '';
                $response = fmUpload($siteId, $dir, $vhost);
                break;
            case 'fm_delete':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $path = $_POST['path'] ?? '';
                $response = fmDelete($siteId, $path, $vhost);
                break;
            case 'fm_rename':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $old = $_POST['old'] ?? '';
                $new = $_POST['new'] ?? '';
                $response = fmRename($siteId, $old, $new, $vhost);
                break;
            case 'fm_mkdir':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $name = $_POST['name'] ?? '';
                $dir = $_POST['dir'] ?? '';
                $response = fmMkdir($siteId, $dir, $name, $vhost);
                break;
            case 'fm_save':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $path = $_POST['path'] ?? '';
                $content = $_POST['content'] ?? '';
                $response = fmSaveFile($siteId, $path, $content, $vhost);
                break;
            case 'fm_read':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $path = $_POST['path'] ?? '';
                $response = fmReadFile($siteId, $path, $vhost);
                break;
            case 'fm_tree':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $response = fmTree($siteId, $vhost);
                break;
            case 'fm_search':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $query = $_POST['query'] ?? '';
                $response = fmSearch($siteId, $query, $vhost);
                break;
            case 'fm_file_list':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $response = fmFileList($siteId, $vhost);
                break;
            case 'fm_php_lint':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $path = $_POST['path'] ?? '';
                $response = fmPhpLint($siteId, $path, $vhost);
                break;
            case 'fm_extract':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $path = $_POST['path'] ?? '';
                $response = fmExtractZip($siteId, $path, $vhost);
                break;
            case 'term_exec':
                $siteId = (int)($_POST['site_id'] ?? 0);
                $cmd = $_POST['cmd'] ?? '';
                $response = termExec($siteId, $cmd, $vhost);
                break;

            // Reverse Proxy actions
            case 'create_proxy':
                $domainType = $_POST['domain_type'] ?? 'subdomain';
                $isCustom = ($domainType === 'custom');
                $domainInput = $isCustom ? trim($_POST['custom_domain'] ?? '') : trim($_POST['subdomain'] ?? '');
                $target = trim($_POST['target'] ?? $_POST['target_url'] ?? '');
                $websocket = !empty($_POST['websocket']);
                
                if (empty($domainInput)) {
                    $response = ['success' => false, 'message' => ($isCustom ? 'Custom domain wajib diisi' : 'Subdomain wajib diisi')];
                } elseif (empty($target)) {
                    $response = ['success' => false, 'message' => 'Target URL is required'];
                } else {
                    $response = $vhost->createProxy($domainInput, $target, $websocket, $isCustom);
                }
                break;
            case 'update_proxy':
                $id = (int)($_POST['id'] ?? 0);
                $target = trim($_POST['target'] ?? $_POST['target_url'] ?? '');
                $websocket = !empty($_POST['websocket']);
                $response = $vhost->updateProxy($id, $target, $websocket);
                break;
            case 'delete_proxy':
                $id = (int)($_POST['id'] ?? $_POST['site_id'] ?? 0);
                $response = $vhost->deleteSite($id);
                break;
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// If not logged in, show login page
$publicActions = ['landing', 'login', 'register', 'google_login', 'google_callback'];
if (!$isLoggedIn && !in_array($action, $publicActions, true)) {
    $action = 'landing';
}

// Page rendering
function renderHeader($title, $vhost) {
    $panelName = $vhost->getSetting('panel_name') ?: 'PuruPanel';
    $wildcard = $vhost->getSetting('wildcard_domain') ?: 'purujekuto.my.id';
    $currentAction = $_GET['action'] ?? 'dashboard';
    
    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . ' &mdash; ' . $panelName . ' Cloud</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=stb">
    <link rel="alternate icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=stb">
    <link rel="alternate icon" type="image/x-icon" href="/favicon.ico?v=stb">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=stb">
    <script>
    (function() {
        const t = localStorage.getItem("puru_theme") || "light";
        document.documentElement.setAttribute("data-theme", t);
    })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;1,6..72,400&family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root, [data-theme="light"] {
            /* RoomMaster Color Palette - Light Mode */
            --rm-canvas: #F4F2EC;         /* Warm Linen Canvas */
            --rm-bento: #E9E6DE;          /* Bento group container */
            --rm-card: #FFFFFF;           /* Crisp floating white micro-card */
            --rm-card-subtle: #FAF9F6;    /* Subtle card hover */
            --rm-border: rgba(24, 25, 28, 0.08); /* Clean micro-border */
            --rm-border-light: rgba(24, 25, 28, 0.05);
            
            --rm-cobalt: #0055FF;         /* RoomMaster vivid electric blue */
            --rm-cobalt-hover: #0044CC;
            --rm-cobalt-subtle: #EEF4FF;   /* Soft blue badge */
            --rm-cobalt-glow: 0 8px 24px rgba(0, 85, 255, 0.25);
            
            --rm-text: #141518;           /* Deep charcoal, maximum readability */
            --rm-text-secondary: #585A62; /* Secondary body */
            --rm-text-muted: #878A94;     /* Timestamps & minor labels */
            
            --rm-sidebar: #ECEAE4;
            --rm-input-bg: #FFFFFF;
            
            --rm-live-bg: #D1FADF;        /* Mint green status */
            --rm-live-text: #027A48;
            --rm-live-dot: #12B76A;
            --rm-warn-bg: #FEF0C7;
            --rm-warn-text: #B54708;
            --rm-danger-bg: #FEE4E2;
            --rm-danger-text: #B42318;
            
            --rm-radius-bento: 24px;
            --rm-radius-card: 18px;
            --rm-radius-pill: 9999px;
            
            --rm-shadow-card: 0 16px 36px -10px rgba(18, 20, 26, 0.06), 0 2px 6px rgba(18, 20, 26, 0.03);
            --rm-shadow-hover: 0 24px 48px -12px rgba(18, 20, 26, 0.10), 0 4px 12px rgba(18, 20, 26, 0.05);
            --rm-shadow-float: 0 20px 45px -10px rgba(0, 0, 0, 0.08);

            --bg-base: var(--rm-canvas);
            --bg-surface: var(--rm-card);
            --bg-surface2: var(--rm-bento);
            --border: var(--rm-border);
            --border-hover: var(--rm-cobalt);
            --text-primary: var(--rm-text);
            --text-secondary: var(--rm-text-secondary);
            --text-muted: var(--rm-text-muted);
            --accent-primary: var(--rm-cobalt);
            --accent-cyan: var(--rm-cobalt);
            --emerald: #027A48;
            --font-mono: "JetBrains Mono", Consolas, monospace;
            --font-brand: "Outfit", "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, sans-serif;
        }

        [data-theme="dark"] {
            /* RoomMaster Hitam Pekat / Doff (Pitch Matte Black & Charcoal) */
            --rm-canvas: #000000;         /* True pitch-black matte canvas */
            --rm-bento: #0B0B0B;          /* Deep matte container */
            --rm-card: #121212;           /* Pure matte charcoal floating card */
            --rm-card-subtle: #191919;    /* Matte subtle hover & meta containers */
            --rm-border: rgba(255, 255, 255, 0.09); /* Crisp micro-borders */
            --rm-border-light: rgba(255, 255, 255, 0.05);
            
            --rm-cobalt: #3B82F6;         /* Vivid electric cobalt against matte black */
            --rm-cobalt-hover: #60A5FA;
            --rm-cobalt-subtle: rgba(59, 130, 246, 0.15);
            --rm-cobalt-glow: 0 8px 25px rgba(59, 130, 246, 0.45);
            
            --rm-text: #FFFFFF;           /* Crisp pure white */
            --rm-text-secondary: #A1A1AA; /* Neutral zinc body */
            --rm-text-muted: #71717A;     /* Muted neutral zinc */
            
            --rm-sidebar: #080808;        /* Pitch-black matte sidebar */
            --rm-input-bg: #0D0D0D;       /* Pure matte dark input */
            
            --rm-live-bg: rgba(16, 185, 129, 0.14);
            --rm-live-text: #34D399;
            --rm-live-dot: #10B981;
            --rm-warn-bg: rgba(245, 158, 11, 0.14);
            --rm-warn-text: #FBBF24;
            --rm-danger-bg: rgba(239, 68, 68, 0.14);
            --rm-danger-text: #F87171;
            
            --rm-shadow-card: 0 16px 36px -10px rgba(0, 0, 0, 0.9), 0 0 1px rgba(255, 255, 255, 0.09);
            --rm-shadow-hover: 0 24px 48px -12px rgba(0, 0, 0, 0.95), 0 0 1px rgba(255, 255, 255, 0.16);
            --rm-shadow-float: 0 25px 50px -10px rgba(0, 0, 0, 0.9);

            --bg-base: var(--rm-canvas);
            --bg-surface: var(--rm-card);
            --bg-surface2: var(--rm-bento);
            --border: var(--rm-border);
            --border-hover: var(--rm-cobalt);
            --text-primary: var(--rm-text);
            --text-secondary: var(--rm-text-secondary);
            --text-muted: var(--rm-text-muted);
            --accent-primary: var(--rm-cobalt);
            --accent-cyan: var(--rm-cobalt);
            --emerald: #34D399;
        }

        /* -------------------------------------------------------------
           BLUE DOT PULSE / HEARTBEAT ANIMATION
           ------------------------------------------------------------- */
        @keyframes rm-blue-pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(0, 85, 255, 0.85);
                opacity: 1;
                transform: scale(1);
            }
            40% {
                box-shadow: 0 0 0 8px rgba(0, 85, 255, 0);
                opacity: 0.3;
                transform: scale(1.25);
            }
            70% {
                opacity: 1;
                transform: scale(0.95);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(0, 85, 255, 0);
                opacity: 1;
                transform: scale(1);
            }
        }

        .page-eyebrow .dot,
        .eyebrow-pill .dot,
        .blue-pulse-dot {
            background: var(--rm-cobalt) !important;
            animation: rm-blue-pulse 1.8s infinite cubic-bezier(0.4, 0, 0.6, 1) !important;
            display: inline-block !important;
        }

        /* Theme Segmented Switcher (RoomMaster Polish) */
        .theme-segmented-switch {
            display: inline-flex;
            align-items: center;
            background: var(--rm-card-subtle);
            border: 1px solid var(--rm-border);
            border-radius: 9999px;
            padding: 3px;
            gap: 2px;
        }
        [data-theme="dark"] .theme-segmented-switch {
            background: #111111;
            border-color: rgba(255, 255, 255, 0.12);
        }
        .theme-seg-btn {
            border: none;
            background: transparent;
            color: var(--rm-text-secondary);
            font-size: 0.72rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 9999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
            line-height: 1;
        }
        .theme-seg-btn:hover {
            color: var(--rm-text);
        }
        .theme-seg-btn.active {
            background: var(--rm-card);
            color: var(--rm-text);
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            border: 1px solid var(--rm-border);
        }
        [data-theme="dark"] .theme-seg-btn {
            color: #9CA3AF;
        }
        [data-theme="dark"] .theme-seg-btn:hover {
            color: #FFFFFF;
        }
        [data-theme="dark"] .theme-seg-btn.active {
            background: #1A1A1A;
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
        }

        /* Theme Switcher inside top-announce-bar (Adaptive Light & Dark) */
        .top-announce-bar .theme-segmented-switch {
            background: rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.08);
            padding: 3px;
            gap: 2px;
        }
        .top-announce-bar .theme-seg-btn {
            color: #585A62;
        }
        .top-announce-bar .theme-seg-btn:hover {
            color: #141518;
        }
        .top-announce-bar .theme-seg-btn.active {
            background: #FFFFFF;
            color: #141518;
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
        }
        [data-theme="dark"] .top-announce-bar .theme-segmented-switch {
            background: rgba(255, 255, 255, 0.08) !important;
            border: 1px solid rgba(255, 255, 255, 0.14) !important;
        }
        [data-theme="dark"] .top-announce-bar .theme-seg-btn {
            color: rgba(255, 255, 255, 0.75) !important;
        }
        [data-theme="dark"] .top-announce-bar .theme-seg-btn:hover {
            color: #FFFFFF !important;
        }
        [data-theme="dark"] .top-announce-bar .theme-seg-btn.active {
            background: #1A1A1A !important;
            color: #FFFFFF !important;
            border: 1px solid rgba(255, 255, 255, 0.16) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.5) !important;
        }

        /* View Mode Capsule (Grid / Table) */
        .view-pill-btn {
            border-radius: 9999px;
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.35rem 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .view-pill-btn.active {
            background: var(--rm-card) !important;
            color: var(--rm-text) !important;
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            border-color: var(--rm-border) !important;
        }
        .view-pill-btn:not(.active) {
            background: transparent !important;
            color: var(--rm-text-secondary) !important;
        }
        .view-pill-btn:not(.active):hover {
            color: var(--rm-text) !important;
        }

        /* Legacy single button fallback */
        .btn-theme-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: var(--rm-radius-pill);
            background: rgba(255, 255, 255, 0.12);
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.18);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .btn-theme-toggle:hover {
            background: rgba(255, 255, 255, 0.22);
            transform: translateY(-1px);
        }
        
        [data-theme="dark"] .sidebar {
            background: var(--rm-sidebar) !important;
        }
        [data-theme="dark"] .nav-item.active a {
            background: var(--rm-card) !important;
            color: var(--rm-cobalt) !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2) !important;
        }
        [data-theme="dark"] .user-profile-card {
            background: var(--rm-card) !important;
            border-color: var(--rm-border) !important;
        }
        [data-theme="dark"] input[type="text"], 
        [data-theme="dark"] input[type="password"], 
        [data-theme="dark"] input[type="number"], 
        [data-theme="dark"] select {
            background: var(--rm-input-bg) !important;
            border-color: var(--rm-border) !important;
            color: var(--rm-text) !important;
        }
        [data-theme="dark"] .tag-pill {
            background: var(--rm-bento) !important;
            color: var(--rm-text-secondary) !important;
            border-color: var(--rm-border) !important;
        }
        
        /* Handled in main top-announce-bar rules */
        [data-theme="dark"] .bento-container {
            border-color: rgba(255, 255, 255, 0.06) !important;
        }
        [data-theme="dark"] .modal-content {
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8) !important;
        }
        [data-theme="dark"] .toast {
            border-color: rgba(255, 255, 255, 0.12) !important;
            color: var(--rm-text) !important;
        }
        [data-theme="dark"] .btn-secondary,
        [data-theme="dark"] .btn-white {
            background: #18181A !important;
            border-color: var(--rm-border) !important;
            color: var(--rm-text) !important;
        }
        [data-theme="dark"] .btn-secondary:hover,
        [data-theme="dark"] .btn-white:hover {
            background: var(--rm-card-subtle) !important;
        }
        [data-theme="dark"] .nav-links a:hover {
            background: rgba(255, 255, 255, 0.05) !important;
            color: var(--rm-text) !important;
        }
        [data-theme="dark"] .sidebar-logo .wildcard-pill {
            background: rgba(255, 255, 255, 0.08) !important;
            color: var(--rm-text-secondary) !important;
        }
        [data-theme="dark"] textarea {
            background: var(--rm-input-bg) !important;
            border-color: var(--rm-border) !important;
            color: var(--rm-text) !important;
        }
        [data-theme="dark"] select option {
            background: #121212 !important;
            color: #F3F4F6 !important;
        }

        [data-theme="dark"] pre {
            background: #060606 !important;
            border-color: var(--rm-border) !important;
            color: var(--rm-text-secondary) !important;
        }


        /* -------------------------------------------------------------
           RESPONSIVE MOBILE & DRAWER NAVIGATION SYSTEM
           ------------------------------------------------------------- */
        .btn-sidebar-toggle {
            display: none;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #FFFFFF;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .btn-sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.22);
            transform: translateY(-1px);
        }
        .btn-sidebar-close {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--rm-text-muted);
            cursor: pointer;
            padding: 4px;
            line-height: 1;
        }
        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(4px);
            z-index: 2400;
        }
        .sidebar-backdrop.active {
            display: block;
        }

        @media (max-width: 960px) {
            .btn-sidebar-toggle {
                display: inline-flex !important;
            }
            .btn-sidebar-close {
                display: block !important;
            }
            .announce-desc, .announce-link, .announce-stb-pill {
                display: none !important;
            }
            .sidebar {
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                bottom: 0 !important;
                height: 100vh !important;
                width: 275px !important;
                transform: translateX(-100%);
                transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                z-index: 2500 !important;
                box-shadow: 0 0 50px rgba(0, 0, 0, 0.5);
            }
            .sidebar.mobile-open {
                transform: translateX(0) !important;
            }
            .main {
                margin-left: 0 !important;
                padding: 1.5rem 1rem 4rem !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .page-title {
                font-size: 1.85rem !important;
            }
            .page-header-rm {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 1rem !important;
            }
            .bento-container {
                padding: 1.25rem !important;
            }
            .card {
                padding: 1.25rem !important;
            }
        }

        @media (max-width: 600px) {
            .theme-btn-label {
                display: none !important;
            }
            .btn-theme-toggle {
                padding: 4px 8px !important;
            }
            .top-announce-bar {
                padding: 0.5rem 1rem !important;
            }
            .card table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            /* Modal Mobile Precision */
            .modal {
                padding: 0.5rem !important;
                align-items: center !important;
            }
            .modal-content {
                width: 100% !important;
                max-width: calc(100vw - 1rem) !important;
                padding: 1.25rem 1rem !important;
                border-radius: 18px !important;
                box-sizing: border-box !important;
                margin: auto !important;
                max-height: 90vh !important;
            }
            .modal-title {
                font-size: 1.3rem !important;
                padding-right: 2rem !important;
            }
            .modal-tab-btn {
                font-size: 0.75rem !important;
                padding: 0.45rem 0.5rem !important;
            }
            .subdomain-input-wrap .subdomain-suffix {
                padding: 0 0.5rem !important;
                font-size: 0.75rem !important;
            }
            .modal-actions {
                display: flex !important;
                flex-direction: row !important;
                gap: 0.5rem !important;
            }
            .modal-actions .btn-white {
                flex: 1 !important;
                justify-content: center !important;
                text-align: center !important;
                padding: 0.65rem 0.5rem !important;
                font-size: 0.85rem !important;
            }
            .modal-actions .btn-primary, .modal-actions .btn-cobalt {
                flex: 1.5 !important;
                justify-content: center !important;
                text-align: center !important;
                padding: 0.65rem 0.5rem !important;
                font-size: 0.85rem !important;
                white-space: nowrap !important;
            }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        html {
            font-size: 13px;
        }

        body {
            font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--rm-canvas);
            color: var(--rm-text);
            min-height: 100vh;
            font-size: 12.5px;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        /* Top Announcement Bar (RoomMaster signature) - Adaptive Light & Dark */
        .top-announce-bar {
            background: #FAF8F5;
            color: #141518;
            border-bottom: 1px solid var(--rm-border);
            font-size: 0.8rem;
            padding: 0.5rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 500;
            position: relative;
            z-index: 100;
            width: 100%;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(18, 20, 26, 0.04);
            transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }
        @media (max-width: 1150px) {
            .announce-stb-pill { display: none !important; }
        }
        @media (max-width: 850px) {
            .announce-desc, .announce-link { display: none !important; }
        }
        .top-announce-bar .announce-desc {
            color: #141518;
        }
        .top-announce-bar .announce-desc strong {
            color: #000000;
            font-weight: 700;
        }
        .top-announce-bar a.announce-link {
            color: var(--rm-cobalt, #0055FF);
            text-decoration: none;
            margin-left: 0.5rem;
            font-weight: 600;
            transition: color 0.15s;
        }
        .top-announce-bar a.announce-link:hover {
            color: var(--rm-cobalt-hover, #0044CC);
            text-decoration: underline;
        }
        .top-announce-bar .badge-update {
            background: var(--rm-cobalt, #0055FF);
            color: #FFFFFF;
            padding: 2px 8px;
            border-radius: var(--rm-radius-pill);
            font-size: 0.72rem;
            font-weight: 700;
            margin-right: 0.5rem;
            letter-spacing: 0.02em;
        }
        .announce-stb-pill {
            font-size: 0.75rem;
            color: var(--rm-text-secondary, #585A62);
            background: rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.07);
            padding: 3px 10px;
            border-radius: 9999px;
            font-weight: 500;
        }
        .top-announce-bar .btn-sidebar-toggle {
            background: rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.09);
            color: #141518;
        }
        .top-announce-bar .btn-sidebar-toggle:hover {
            background: rgba(0, 0, 0, 0.08);
        }

        /* Dark Mode Overrides for Top Announcement Bar */
        [data-theme="dark"] .top-announce-bar {
            background: #000000 !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
            color: #FFFFFF !important;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
        }
        [data-theme="dark"] .top-announce-bar .announce-desc {
            color: #E2E8F0 !important;
        }
        [data-theme="dark"] .top-announce-bar .announce-desc strong {
            color: #FFFFFF !important;
        }
        [data-theme="dark"] .top-announce-bar a.announce-link {
            color: #60A5FA !important;
        }
        [data-theme="dark"] .top-announce-bar a.announce-link:hover {
            color: #93C5FD !important;
        }
        [data-theme="dark"] .top-announce-bar .badge-update {
            background: #2563EB !important;
            color: #FFFFFF !important;
        }
        [data-theme="dark"] .announce-stb-pill {
            color: #9CA3AF !important;
            background: rgba(255, 255, 255, 0.06) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
        }
        [data-theme="dark"] .top-announce-bar .btn-sidebar-toggle {
            background: rgba(255, 255, 255, 0.12) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            color: #FFFFFF !important;
        }
        [data-theme="dark"] .top-announce-bar .btn-sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.22) !important;
        }

        /* Layout Structure */
        .layout {
            display: flex;
            min-height: calc(100vh - 35px);
        }

        /* RoomMaster Warm Sidebar - Pro Ultra-Compact */
        .sidebar {
            width: 200px;
            background: var(--rm-sidebar);
            border-right: 1px solid var(--rm-border);
            padding: 0.85rem 0;
            position: fixed;
            top: 36px;
            height: calc(100vh - 36px);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            z-index: 50;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .sidebar::-webkit-scrollbar {
            display: none;
            width: 0;
        }

        .sidebar-logo {
            padding: 0 0.9rem 0.85rem;
            border-bottom: 1px solid var(--rm-border);
        }
        .sidebar-logo a {
            font-family: var(--font-brand, "Outfit", sans-serif);
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--rm-text);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            letter-spacing: -0.015em;
        }
        .sidebar-logo a .brand-accent {
            color: var(--rm-cobalt);
        }
        .sidebar-logo .brand-icon {
            width: 26px;
            height: 26px;
            background: var(--rm-cobalt);
            color: white;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 8px rgba(0, 85, 255, 0.25);
            flex-shrink: 0;
        }
        .sidebar-logo .brand-icon svg {
            width: 15px;
            height: 15px;
            fill: currentColor;
        }
        .sidebar-logo .wildcard-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 0.35rem;
            padding: 2px 7px;
            border-radius: var(--rm-radius-pill);
            background: rgba(0,0,0,0.05);
            font-size: 0.65rem;
            color: var(--rm-text-secondary);
            font-weight: 500;
        }
        .sidebar-logo .wildcard-pill .dot {
            width: 4px;
            height: 4px;
            background: var(--rm-live-dot);
            border-radius: 50%;
            box-shadow: 0 0 5px var(--rm-live-dot);
        }

        .nav-section-title {
            padding: 0.85rem 0.9rem 0.3rem;
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--rm-text-muted);
            font-weight: 700;
        }

        .nav-links {
            list-style: none;
            padding: 0 0.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }

        .nav-item a {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.45rem 0.65rem;
            border-radius: 8px;
            color: var(--rm-text-secondary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.78rem;
            transition: all 0.15s ease;
        }
        .nav-item a:hover {
            color: var(--rm-text);
            background: rgba(0, 0, 0, 0.04);
        }
        .nav-item.active a {
            color: var(--rm-cobalt);
            background: var(--rm-card);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            font-weight: 700;
        }

        /* Sidebar Footer / Profile */
        .sidebar-footer {
            margin-top: auto;
            padding: 0.75rem 0.6rem;
            border-top: 1px solid var(--rm-border);
        }
        .user-profile-card {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.4rem 0.55rem;
            border-radius: 8px;
            background: var(--rm-card);
            border: 1px solid var(--rm-border);
            box-shadow: 0 1px 4px rgba(0,0,0,0.02);
        }
        .user-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--rm-cobalt);
            color: white;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
        }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-weight: 700; font-size: 0.74rem; color: var(--rm-text); white-space: nowrap; }
        .user-role { font-size: 0.62rem; color: var(--rm-text-muted); }
        .btn-logout {
            color: var(--rm-text-muted);
            text-decoration: none;
            padding: 3px;
            border-radius: 4px;
            transition: all 0.15s;
        }
        .btn-logout:hover { color: #DC2626; }

        /* Main Content Container - Compact High Density */
        .main {
            margin-left: 200px;
            flex: 1;
            padding: 1rem 1.5rem 2.5rem;
            max-width: 100%;
        }

        /* RoomMaster Editorial Page Header */
        .page-header-rm {
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 0.6rem;
        }
        .page-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            background: var(--rm-bento);
            border-radius: var(--rm-radius-pill);
            font-size: 0.64rem;
            font-weight: 600;
            color: var(--rm-text-secondary);
            margin-bottom: 0.25rem;
        }
        .page-eyebrow .dot {
            width: 4px;
            height: 4px;
            background: var(--rm-cobalt);
            border-radius: 50%;
        }
        .page-title {
            font-family: "Newsreader", Georgia, serif;
            font-size: 1.35rem;
            font-weight: 600;
            letter-spacing: -0.02em;
            color: var(--rm-text);
            line-height: 1.2;
        }
        .page-subtitle {
            font-size: 0.78rem;
            color: var(--rm-text-secondary);
            margin-top: 0.15rem;
        }

        /* RoomMaster Bento Container */
        .bento-container {
            background: var(--rm-bento);
            border-radius: 14px;
            padding: 1rem;
            margin-bottom: 1.25rem;
            border: 1px solid rgba(0,0,0,0.03);
        }
        .bento-title {
            font-family: "Newsreader", Georgia, serif;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--rm-text);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* RoomMaster Floating White Micro-Cards */
        .card {
            background: var(--rm-card);
            border: 1px solid var(--rm-border);
            border-radius: 12px;
            box-shadow: var(--rm-shadow-card);
            padding: 0.9rem 1rem;
            transition: all 0.2s ease;
        }
        .card:hover {
            box-shadow: var(--rm-shadow-hover);
        }

        /* RoomMaster Signature Pill Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.42rem 0.95rem;
            font-size: 0.76rem;
            font-weight: 600;
            border-radius: var(--rm-radius-pill);
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            font-family: "Plus Jakarta Sans", sans-serif;
            white-space: nowrap !important;
            flex-shrink: 0 !important;
            line-height: 1.2;
        }
        .btn-primary {
            background: var(--rm-cobalt);
            color: #FFFFFF;
            box-shadow: var(--rm-cobalt-glow);
        }
        .btn-primary:hover {
            background: var(--rm-cobalt-hover);
            transform: translateY(-1px);
        }
        .btn-secondary, .btn-white {
            background: var(--rm-card);
            color: var(--rm-text);
            border-color: var(--rm-border);
            box-shadow: 0 1px 4px rgba(0,0,0,0.03);
        }
        .btn-secondary:hover, .btn-white:hover {
            background: var(--rm-canvas);
            border-color: rgba(0,0,0,0.15);
        }
        .btn-sm {
            padding: 0.25rem 0.55rem;
            font-size: 0.7rem;
            white-space: nowrap !important;
            flex-shrink: 0 !important;
            line-height: 1.2;
        }
        .btn-danger {
            background: var(--rm-danger-bg);
            color: var(--rm-danger-text);
            border-color: rgba(239, 68, 68, 0.2);
        }
        .btn-danger:hover {
            background: #FEE2E2;
        }

        /* RoomMaster Status Pills */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: var(--rm-radius-pill);
            font-size: 0.75rem;
            font-weight: 700;
        }
        .status-pill.live, .status-pill.active {
            background: var(--rm-live-bg);
            color: var(--rm-live-text);
        }
        .status-pill.live .dot, .status-pill.active .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--rm-live-dot);
            box-shadow: 0 0 6px var(--rm-live-dot);
        }
        .status-pill.disabled {
            background: #F3F4F6;
            color: #6B7280;
        }
        .status-pill.warning {
            background: var(--rm-warn-bg);
            color: var(--rm-warn-text);
        }

        .tag-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: var(--rm-radius-pill);
            background: var(--rm-canvas);
            color: var(--rm-text-secondary);
            font-size: 0.72rem;
            font-weight: 600;
            border: 1px solid var(--rm-border);
        }

        /* Form Inputs */
        input[type="text"], input[type="password"], input[type="number"], select, textarea {
            width: 100%;
            padding: 0.7rem 1rem;
            background: var(--rm-input-bg);
            border: 1px solid var(--rm-border);
            border-radius: 12px;
            color: var(--rm-text);
            font-size: 0.88rem;
            font-family: inherit;
            outline: none;
            transition: all 0.15s ease;
        }
        input:focus, select:focus, textarea:focus {
            border-color: var(--rm-cobalt);
            box-shadow: 0 0 0 3px rgba(0, 85, 255, 0.12);
        }

        /* Tables (Clean Light SaaS style) */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.85rem;
        }
        th {
            text-align: left;
            padding: 0.85rem 1.25rem;
            font-weight: 700;
            color: var(--rm-text-muted);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--rm-border);
        }
        td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--rm-border-light);
            color: var(--rm-text);
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover {
            background: var(--rm-card-subtle);
        }

        /* Modal Styles */
        .fw-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(95px, 1fr));
            gap: 0.6rem;
            margin-bottom: 0.75rem;
        }
        .fw-card {
            background: var(--rm-card);
            border: 1.5px solid var(--rm-border);
            border-radius: 14px;
            padding: 0.75rem 0.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .fw-card:hover {
            border-color: var(--rm-cobalt);
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(0, 85, 255, 0.10);
        }
        .fw-card.active {
            border-color: var(--rm-cobalt);
            background: var(--rm-cobalt-subtle);
            box-shadow: 0 0 0 2px rgba(0, 85, 255, 0.25);
        }
        .fw-card .fw-icon {
            font-size: 1.5rem;
            margin-bottom: 0.35rem;
            line-height: 1;
        }
        .fw-card .fw-name {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--rm-text);
            margin-bottom: 0.15rem;
        }
        .fw-card.active .fw-name {
            color: var(--rm-cobalt);
        }
        .fw-card .fw-vhost {
            font-size: 0.65rem;
            color: var(--rm-text-muted);
            font-family: var(--font-mono);
        }
        .fw-card.active .fw-vhost {
            color: var(--rm-cobalt);
            font-weight: 600;
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 3000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            box-sizing: border-box;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: var(--rm-card);
            border: 1px solid var(--rm-border);
            border-radius: 20px;
            padding: clamp(1.25rem, 4vw, 2rem);
            width: 100%;
            max-width: 520px;
            box-shadow: 0 25px 60px -15px rgba(0,0,0,0.5);
            position: relative;
            box-sizing: border-box;
            overflow: hidden;
            margin: auto;
            max-height: 92vh;
            overflow-y: auto;
        }
        .modal-title {
            font-family: "Newsreader", Georgia, serif;
            font-size: 1.45rem;
            font-weight: 600;
            color: var(--rm-text);
            margin-bottom: 0.4rem;
            line-height: 1.25;
        }
        .modal-desc {
            font-size: 0.85rem;
            color: var(--rm-text-secondary);
            margin-bottom: 1.25rem;
            line-height: 1.45;
        }
        .modal-close {
            position: absolute;
            top: 1.15rem;
            right: 1.15rem;
            background: none;
            border: none;
            font-size: 1.4rem;
            cursor: pointer;
            color: var(--rm-text-muted);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
            z-index: 10;
        }
        .modal-close:hover { 
            color: var(--rm-text); 
            background: var(--rm-card-subtle);
        }

        /* Modal Domain Tabs & Controls */
        .modal-tab-container {
            background: var(--rm-card-subtle);
            border: 1px solid var(--rm-border);
            padding: 4px;
            border-radius: 9999px;
            display: flex;
            margin-bottom: 1.25rem;
            width: 100%;
            box-sizing: border-box;
        }
        .modal-tab-btn {
            flex: 1;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.8rem;
            padding: 0.5rem 0.75rem;
            text-align: center;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
        }
        .modal-tab-btn.active {
            background: var(--rm-card);
            color: var(--rm-text);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .modal-tab-btn:not(.active) {
            background: transparent;
            color: var(--rm-text-secondary);
        }
        .modal-tab-btn:not(.active):hover {
            color: var(--rm-text);
        }

        /* Subdomain input with wildcard suffix */
        .subdomain-input-wrap {
            display: flex;
            align-items: center;
            background: var(--rm-card-subtle);
            border: 1px solid var(--rm-border);
            border-radius: 12px;
            overflow: hidden;
            width: 100%;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .subdomain-input-wrap:focus-within {
            border-color: var(--rm-cobalt);
        }
        .subdomain-input-wrap input {
            flex: 1;
            min-width: 0;
            width: 100%;
            border: none !important;
            background: transparent !important;
            padding: 0.65rem 0.85rem !important;
            font-size: 0.88rem !important;
            color: var(--rm-text) !important;
            outline: none !important;
            box-shadow: none !important;
        }
        .subdomain-input-wrap .subdomain-suffix {
            padding: 0 0.85rem;
            color: var(--rm-text-muted);
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
            background: rgba(127, 127, 127, 0.08);
            border-left: 1px solid var(--rm-border-light);
            height: 100%;
            display: flex;
            align-items: center;
            user-select: none;
        }

        /* Modal Action Buttons */
        .modal-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
            width: 100%;
            box-sizing: border-box;
        }

        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--rm-text-secondary);
        }

        /* Toast Notifications */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--rm-card);
            border: 1px solid var(--rm-border);
            padding: 0.9rem 1.4rem;
            border-radius: var(--rm-radius-pill);
            box-shadow: 0 15px 35px -5px rgba(0,0,0,0.15);
            display: none;
            align-items: center;
            gap: 0.6rem;
            font-weight: 600;
            z-index: 2000;
        }
    </style>
</head>
<body>
    <div class="top-announce-bar">
        <div style="display:flex; align-items:center; gap:0.75rem">
            <button class="btn-sidebar-toggle" onclick="toggleMobileSidebar()" id="btn_mobile_sidebar" aria-label="Toggle Navigation">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
            <span class="badge-update">New Update</span>
            <span class="announce-desc">PuruPanel Cloud &bull; Inspired by <strong>roommaster.com</strong></span>
            <a href="?action=dashboard" class="announce-link">Explore &rarr;</a>
        </div>
        <div style="display:flex; align-items:center; gap:0.75rem">
            <span class="announce-stb-pill">Armbian STB &bull; Wildcard Cloudflare</span>
            <div class="theme-segmented-switch">
                <button type="button" class="theme-seg-btn" id="btn_theme_light" onclick="setTheme(\'light\')" title="Light Theme">
                    <span>☀️</span> <span class="theme-btn-label">Light</span>
                </button>
                <button type="button" class="theme-seg-btn" id="btn_theme_dark" onclick="setTheme(\'dark\')" title="Hitam Doff Dark Theme">
                    <span>🌙</span> <span class="theme-btn-label">Dark</span>
                </button>
            </div>
        </div>
    </div>
    <div class="sidebar-backdrop" id="sidebar_backdrop" onclick="closeMobileSidebar()"></div>
    
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-logo">
                <div style="display:flex; align-items:center; justify-content:space-between">
                    <a href="?action=dashboard">
                        <span class="brand-icon">
                            <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                        </span>
                        <span>Puru<span class="brand-accent">Panel</span></span>
                    </a>
                    <button class="btn-sidebar-close" onclick="closeMobileSidebar()">&times;</button>
                </div>
                <div class="wildcard-pill">
                    <span class="dot"></span>
                    <span>*.' . htmlspecialchars($wildcard) . '</span>
                </div>
            </div>

            <div class="nav-section-title">Platform</div>
            <ul class="nav-links">
                <li class="nav-item ' . ($currentAction === 'dashboard' ? 'active' : '') . '">
                    <a href="?action=dashboard">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item ' . ($currentAction === 'sites' ? 'active' : '') . '">
                    <a href="?action=sites">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        <span>Websites & Vhosts</span>
                    </a>
                </li>
                <li class="nav-item ' . ($currentAction === 'proxy' ? 'active' : '') . '">
                    <a href="?action=proxy">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>
                        <span>Reverse Proxy</span>
                    </a>
                </li>
                <li class="nav-item ' . ($currentAction === 'nginx' ? 'active' : '') . '">
                    <a href="?action=nginx">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span>Settings & Engine</span>
                    </a>
                </li>
            </ul>

            <div class="sidebar-footer">
                <!-- Sidebar Theme Switcher -->
            <div style="padding: 0 0.2rem 0.85rem;">
                <div class="theme-segmented-switch" style="width:100%; display:flex; justify-content:center; background:var(--rm-card-subtle); border:1px solid var(--rm-border);">
                    <button type="button" class="theme-seg-btn" id="btn_sb_light" onclick="setTheme(\'light\')" style="flex:1; justify-content:center;" title="Switch to Light Theme">
                        <span>☀️</span> Light
                    </button>
                    <button type="button" class="theme-seg-btn" id="btn_sb_dark" onclick="setTheme(\'dark\')" style="flex:1; justify-content:center;" title="Switch to Hitam Doff Theme">
                        <span>🌙</span> Dark
                    </button>
                </div>
            </div>
            <div class="user-profile-card">
                    <div class="user-avatar">A</div>
                    <div class="user-info">
                        <div class="user-name">Admin Console</div>
                        <div class="user-role">STB Host &bull; 192.168.60.105</div>
                    </div>
                    <a href="?action=logout" class="btn-logout" title="Sign Out">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    </a>
                </div>
            </div>
        </aside>
        <main class="main">';
}

function renderFooter() {
    return '    </main>
    </div>
    <div id="toast-container"></div>
    <script>
    function showToast(msg, type = "success") {
        const container = document.getElementById("toast-container");
        const toast = document.createElement("div");
        toast.className = "toast toast-" + type;
        toast.textContent = msg;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 3500);
    }

    function apiCall(action, data, callback) {
        const formData = new URLSearchParams();
        for (let key in data) {
            formData.append(key, data[key]);
        }
        fetch("?ajax=1&action=" + encodeURIComponent(action), {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: formData.toString()
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast(res.message || "Success");
            } else {
                showToast(res.message || "Error", "error");
            }
            if (callback) callback(res);
        })
        .catch(err => {
            showToast("Network error", "error");
            if (callback) callback({success: false});
        });
    }

    function openModal(id) {
        document.getElementById(id).classList.add("active");
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove("active");
    }
    function confirmDelete(id, domain) {
        if (confirm("Delete site: " + domain + "?\\n\\nThis will remove ALL files and cannot be undone!")) {
            apiCall("delete_site", {id: id}, function(res) {
                if (res.success) location.reload();
            });
        }
    }
    </script>
    <script>
    function toggleMobileSidebar() {
        const sb = document.querySelector(".sidebar");
        const bd = document.getElementById("sidebar_backdrop");
        if (!sb) return;
        sb.classList.toggle("mobile-open");
        if (bd) bd.classList.toggle("active");
    }
    function closeMobileSidebar() {
        const sb = document.querySelector(".sidebar");
        const bd = document.getElementById("sidebar_backdrop");
        if (sb) sb.classList.remove("mobile-open");
        if (bd) bd.classList.remove("active");
    }
    function setTheme(t) {
        document.documentElement.setAttribute("data-theme", t);
        localStorage.setItem("puru_theme", t);
        syncThemeBtn(t);
    }
    function toggleTheme() {
        const cur = document.documentElement.getAttribute("data-theme") || "light";
        setTheme(cur === "light" ? "dark" : "light");
    }
    function syncThemeBtn(t) {
        const btnL = document.getElementById("btn_theme_light");
        const btnD = document.getElementById("btn_theme_dark");
        if (btnL && btnD) {
            if (t === "dark") {
                btnD.classList.add("active");
                btnL.classList.remove("active");
            } else {
                btnL.classList.add("active");
                btnD.classList.remove("active");
            }
        }
        const sbL = document.getElementById("btn_sb_light");
        const sbD = document.getElementById("btn_sb_dark");
        if (sbL && sbD) {
            if (t === "dark") {
                sbD.classList.add("active");
                sbL.classList.remove("active");
            } else {
                sbL.classList.add("active");
                sbD.classList.remove("active");
            }
        }
        const icon = document.getElementById("theme_icon");
        const text = document.getElementById("theme_text");
        if (icon && text) {
            if (t === "dark") {
                icon.innerText = "☀️";
                text.innerText = "Light";
            } else {
                icon.innerText = "🌙";
                text.innerText = "Dark";
            }
        }
    }
    syncThemeBtn(localStorage.getItem("puru_theme") || "light");


</script>
</body>
</html>';
}

// ============================================
// ============================================
// SAAS LANDING PAGE (Inspired by RoomMaster.com)
// ============================================
if ($action === 'landing') {
    $panelName = $vhost->getSetting('panel_name') ?: 'PuruPanel';
    $wildcard = $vhost->getSetting('wildcard_domain') ?: 'purujekuto.my.id';
    $stats = $vhost->getSystemStats();
    $sites = $vhost->getAllSites($currentUserId, $currentUserRole);
    $activeSites = count(array_filter($sites, fn($s) => $s['status'] === 'active'));
    $totalSites = count($sites);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($panelName) ?> Cloud &mdash; Self-Hosted Cloud Platform for Armbian STB</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=stb">
        <link rel="alternate icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=stb">
        <link rel="alternate icon" type="image/x-icon" href="/favicon.ico?v=stb">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=stb">
        <meta name="description" content="Transform any low-cost Armbian TV Box into a high-speed cloud hosting server. Instant wildcard subdomains, Monaco code editor, automated Nginx & PHP 8.4 pools, and zero-trust Google SSO.">
        <script>
        (function() {
            const t = localStorage.getItem('puru_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;1,6..72,400&family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
        <style>
            :root, [data-theme="light"] {
                --rm-canvas: #F4F2EC;
                --rm-bento: #E9E6DE;
                --rm-card: #FFFFFF;
                --rm-card-subtle: #FAF9F6;
                --rm-border: rgba(24, 25, 28, 0.08);
                --rm-border-light: rgba(24, 25, 28, 0.04);
                --rm-cobalt: #0055FF;
                --rm-cobalt-hover: #0044CC;
                --rm-cobalt-subtle: #EEF4FF;
                --rm-cobalt-glow: 0 8px 25px rgba(0, 85, 255, 0.35);
                --rm-text: #141518;
                --rm-text-secondary: #585A62;
                --rm-text-muted: #878A94;
                --rm-sidebar: #EFECE4;
                --rm-input-bg: #FFFFFF;
                --rm-live-bg: #DCFCE7;
                --rm-live-text: #15803D;
                --rm-live-dot: #16A34A;
                --rm-shadow-card: 0 16px 36px -10px rgba(0, 0, 0, 0.06), 0 0 1px rgba(0, 0, 0, 0.04);
                --rm-shadow-hover: 0 24px 48px -12px rgba(0, 0, 0, 0.12), 0 0 1px rgba(0, 0, 0, 0.06);
                --rm-radius-pill: 9999px;
                --rm-radius-card: 20px;
                --rm-radius-bento: 24px;
            }

            [data-theme="dark"] {
                --rm-canvas: #000000;
                --rm-bento: #0B0B0B;
                --rm-card: #121212;
                --rm-card-subtle: #191919;
                --rm-border: rgba(255, 255, 255, 0.09);
                --rm-border-light: rgba(255, 255, 255, 0.05);
                --rm-cobalt: #3B82F6;
                --rm-cobalt-hover: #60A5FA;
                --rm-cobalt-subtle: rgba(59, 130, 246, 0.15);
                --rm-cobalt-glow: 0 8px 25px rgba(59, 130, 246, 0.45);
                --rm-text: #FFFFFF;
                --rm-text-secondary: #A1A1AA;
                --rm-text-muted: #71717A;
                --rm-sidebar: #080808;
                --rm-input-bg: #0D0D0D;
                --rm-live-bg: rgba(16, 185, 129, 0.14);
                --rm-live-text: #34D399;
                --rm-live-dot: #10B981;
                --rm-shadow-card: 0 16px 36px -10px rgba(0, 0, 0, 0.9), 0 0 1px rgba(255, 255, 255, 0.09);
                --rm-shadow-hover: 0 24px 48px -12px rgba(0, 0, 0, 0.95), 0 0 1px rgba(255, 255, 255, 0.16);
            }

            @keyframes rm-blue-pulse {
                0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); transform: scale(0.98); }
                50% { box-shadow: 0 0 0 6px rgba(59, 130, 246, 0); transform: scale(1.08); }
                100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); transform: scale(0.98); }
            }

            *, *::before, *::after {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                min-width: 0;
            }
            html, body {
                width: 100%;
                max-width: 100vw;
                overflow-x: hidden !important;
                position: relative;
            }
            body {
                font-family: "Plus Jakarta Sans", -apple-system, sans-serif;
                background-color: var(--rm-canvas);
                color: var(--rm-text);
                min-height: 100vh;
                font-size: 15px;
                line-height: 1.6;
                -webkit-font-smoothing: antialiased;
            }

            /* Container */
            .landing-container {
                width: 100%;
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 clamp(1rem, 3.5vw, 1.5rem);
                box-sizing: border-box;
                overflow-x: hidden;
            }

            /* Sticky Navbar */
            .landing-nav {
                position: sticky;
                top: 0;
                z-index: 1000;
                background: rgba(var(--rm-canvas), 0.85);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border-bottom: 1px solid var(--rm-border);
                transition: all 0.3s ease;
            }
            [data-theme="light"] .landing-nav { background: rgba(244, 242, 236, 0.88); }
            [data-theme="dark"] .landing-nav { background: rgba(0, 0, 0, 0.88); }

            .landing-nav {
                width: 100%;
                max-width: 100vw;
            }
            .nav-inner {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0.75rem clamp(0.75rem, 3vw, 1.5rem);
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.5rem;
                width: 100%;
                box-sizing: border-box;
            }
            .brand-lockup {
                display: flex;
                align-items: center;
                gap: 0.55rem;
                text-decoration: none;
                color: var(--rm-text);
                font-family: var(--font-brand, 'Outfit', sans-serif);
                font-weight: 700;
                font-size: clamp(1.15rem, 3.5vw, 1.3rem);
                letter-spacing: -0.015em;
                flex-shrink: 0;
            }
            .brand-lockup .brand-accent {
                color: var(--rm-cobalt);
            }
            .brand-icon {
                width: 32px;
                height: 32px;
                border-radius: 9px;
                background: var(--rm-cobalt);
                color: #FFFFFF;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 12px rgba(0, 85, 255, 0.3);
                flex-shrink: 0;
            }
            .brand-icon svg {
                width: 17px;
                height: 17px;
                fill: currentColor;
            }
            .wildcard-capsule {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 0.75rem;
                font-weight: 600;
                padding: 3px 10px;
                border-radius: 9999px;
                background: var(--rm-card-subtle);
                border: 1px solid var(--rm-border);
                color: var(--rm-text-secondary);
            }
            .pulse-dot {
                width: 7px;
                height: 7px;
                border-radius: 50%;
                background: var(--rm-cobalt);
                animation: rm-blue-pulse 1.8s infinite cubic-bezier(0.4, 0, 0.6, 1);
            }

            .nav-links {
                display: flex;
                align-items: center;
                gap: 1.75rem;
            }
            .nav-links a {
                text-decoration: none;
                color: var(--rm-text-secondary);
                font-size: 0.88rem;
                font-weight: 600;
                transition: color 0.2s;
            }
            .nav-links a:hover {
                color: var(--rm-text);
            }

            .nav-actions {
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }

            /* Theme Segmented Switch */
            .theme-segmented-switch {
                display: inline-flex;
                align-items: center;
                background: var(--rm-card-subtle);
                border: 1px solid var(--rm-border);
                border-radius: 9999px;
                padding: 3px;
                gap: 2px;
            }
            .theme-seg-btn {
                border: none;
                background: transparent;
                color: var(--rm-text-secondary);
                font-size: 0.72rem;
                font-weight: 600;
                padding: 4px 10px;
                border-radius: 9999px;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                gap: 5px;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                font-family: inherit;
                line-height: 1;
            }
            .theme-seg-btn:hover { color: var(--rm-text); }
            .theme-seg-btn.active {
                background: var(--rm-card);
                color: var(--rm-text);
                font-weight: 700;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
                border: 1px solid var(--rm-border);
            }
            [data-theme="dark"] .theme-seg-btn.active {
                background: #1A1A1A;
                color: #FFFFFF;
                border: 1px solid rgba(255, 255, 255, 0.16);
            }

            /* Buttons */
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                padding: 0.6rem 1.25rem;
                border-radius: var(--rm-radius-pill);
                font-size: 0.88rem;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                text-decoration: none;
                border: 1px solid transparent;
                font-family: inherit;
            }
            .btn-white {
                background: var(--rm-card);
                color: var(--rm-text);
                border-color: var(--rm-border);
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            }
            .btn-white:hover {
                background: var(--rm-card-subtle);
                border-color: rgba(0, 0, 0, 0.15);
                transform: translateY(-1px);
            }
            [data-theme="dark"] .btn-white {
                background: #18181A;
                border-color: rgba(255, 255, 255, 0.12);
            }
            .btn-cobalt {
                background: var(--rm-cobalt);
                color: #FFFFFF;
                box-shadow: var(--rm-cobalt-glow);
            }
            .btn-cobalt:hover {
                background: var(--rm-cobalt-hover);
                transform: translateY(-1px);
                box-shadow: 0 10px 28px rgba(0, 85, 255, 0.45);
            }
            .btn-google {
                background: var(--rm-card);
                color: var(--rm-text);
                border: 1px solid var(--rm-border);
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            }
            .btn-google:hover {
                background: var(--rm-card-subtle);
                border-color: var(--rm-cobalt);
                transform: translateY(-1px);
            }
            .btn-lg {
                padding: 0.75rem 1.6rem;
                font-size: 0.95rem;
            }

            /* Hero Section */
            .hero-section {
                padding: clamp(2rem, 6vw, 5rem) 0 clamp(1.5rem, 4vw, 3rem);
                text-align: center;
                display: flex;
                flex-direction: column;
                align-items: center;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            .eyebrow-pill {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                padding: 5px 14px;
                border-radius: var(--rm-radius-pill);
                background: var(--rm-card-subtle);
                border: 1px solid var(--rm-border);
                font-size: clamp(0.72rem, 2.4vw, 0.8rem);
                font-weight: 600;
                color: var(--rm-text-secondary);
                margin-bottom: 1.25rem;
                max-width: 100%;
                text-align: center;
                line-height: 1.4;
                word-break: break-word;
            }
            .hero-title {
                font-family: "Newsreader", Georgia, serif;
                font-size: clamp(1.85rem, 6.2vw, 4.2rem);
                font-weight: 500;
                line-height: 1.15;
                letter-spacing: -0.03em;
                color: var(--rm-text);
                max-width: 860px;
                width: 100%;
                margin-bottom: 1.25rem;
                word-wrap: break-word;
                overflow-wrap: break-word;
                hyphens: auto;
            }
            .hero-title em {
                font-style: italic;
                font-family: "Newsreader", Georgia, serif;
                color: var(--rm-cobalt);
            }
            .hero-subtitle {
                font-size: clamp(0.92rem, 3.2vw, 1.15rem);
                color: var(--rm-text-secondary);
                max-width: 660px;
                width: 100%;
                margin-bottom: 2rem;
                font-weight: 400;
                line-height: 1.6;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }
            .hero-cta-group {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.75rem;
                flex-wrap: wrap;
                width: 100%;
                max-width: 640px;
                margin-bottom: 2rem;
            }
            .hardware-badge-strip {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.6rem;
                flex-wrap: wrap;
                font-size: clamp(0.72rem, 2.2vw, 0.78rem);
                color: var(--rm-text-muted);
                padding: 0.5rem;
                max-width: 100%;
                text-align: center;
            }

            /* Live Showcase Window */
            .showcase-wrapper {
                margin: 2rem auto 4rem;
                border-radius: var(--rm-radius-bento);
                background: var(--rm-bento);
                padding: 1.25rem;
                border: 1px solid var(--rm-border);
                box-shadow: 0 30px 70px -15px rgba(0, 0, 0, 0.15);
            }
            .showcase-window {
                background: var(--rm-card);
                border-radius: 18px;
                border: 1px solid var(--rm-border);
                overflow: hidden;
                box-shadow: var(--rm-shadow-card);
            }
            .showcase-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0.85rem 1.25rem;
                border-bottom: 1px solid var(--rm-border-light);
                background: var(--rm-card-subtle);
            }
            .window-controls {
                display: flex;
                align-items: center;
                gap: 7px;
            }
            .win-dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
            }
            .win-dot.red { background: #FF5F56; }
            .win-dot.yellow { background: #FFBD2E; }
            .win-dot.green { background: #27C93F; }

            .showcase-tabs {
                display: flex;
                align-items: center;
                background: var(--rm-canvas);
                border: 1px solid var(--rm-border);
                border-radius: 9999px;
                padding: 3px;
                gap: 3px;
            }
            .showcase-tab-btn {
                border: none;
                background: transparent;
                color: var(--rm-text-secondary);
                font-size: 0.78rem;
                font-weight: 600;
                padding: 5px 12px;
                border-radius: 9999px;
                cursor: pointer;
                transition: all 0.2s;
                font-family: inherit;
            }
            .showcase-tab-btn.active {
                background: var(--rm-card);
                color: var(--rm-text);
                font-weight: 700;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            }

            .showcase-body {
                padding: clamp(1.25rem, 3vw, 2rem);
            }
            .showcase-tab-content { display: none; }
            .showcase-tab-content.active { display: block; }

            /* Bento Grid Features */
            .section-header {
                text-align: center;
                margin-bottom: 2.5rem;
            }
            .section-title {
                font-family: "Newsreader", Georgia, serif;
                font-size: clamp(1.8rem, 4vw, 2.75rem);
                font-weight: 500;
                letter-spacing: -0.02em;
                margin-bottom: 0.6rem;
            }
            .section-subtitle {
                font-size: 0.95rem;
                color: var(--rm-text-secondary);
                max-width: 600px;
                margin: 0 auto;
            }

            .bento-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 1.5rem;
                margin-bottom: 4rem;
            }
            .bento-card {
                background: var(--rm-card);
                border: 1px solid var(--rm-border);
                border-radius: var(--rm-radius-card);
                padding: 1.75rem;
                box-shadow: var(--rm-shadow-card);
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                transition: transform 0.25s, box-shadow 0.25s, border-color 0.25s;
            }
            .bento-card:hover {
                transform: translateY(-3px);
                box-shadow: var(--rm-shadow-hover);
                border-color: var(--rm-cobalt);
            }
            .bento-card.col-span-2 { grid-column: span 2; }
            .bento-card.col-span-3 { grid-column: span 3; }

            .bento-icon {
                width: 44px;
                height: 44px;
                border-radius: 12px;
                background: var(--rm-cobalt-subtle);
                color: var(--rm-cobalt);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.35rem;
                margin-bottom: 1.25rem;
            }
            .bento-card-title {
                font-size: 1.15rem;
                font-weight: 700;
                margin-bottom: 0.5rem;
                color: var(--rm-text);
            }
            .bento-card-desc {
                font-size: 0.88rem;
                color: var(--rm-text-secondary);
                line-height: 1.55;
            }

            /* Comparison Table */
            .comparison-card {
                background: var(--rm-card);
                border: 1px solid var(--rm-border);
                border-radius: var(--rm-radius-bento);
                padding: 2rem;
                margin-bottom: 4rem;
                box-shadow: var(--rm-shadow-card);
                overflow-x: auto;
            }
            .comp-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
                font-size: 0.88rem;
            }
            .comp-table th, .comp-table td {
                padding: 0.85rem 1rem;
                border-bottom: 1px solid var(--rm-border-light);
            }
            .comp-table th {
                font-weight: 700;
                color: var(--rm-text-secondary);
                font-size: 0.78rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            .comp-table tr.highlight-row {
                background: var(--rm-card-subtle);
                font-weight: 700;
            }
            .comp-table tr.highlight-row td {
                color: var(--rm-cobalt);
            }

            /* CTA Final Banner */
            .cta-banner {
                background: var(--rm-bento);
                border: 1px solid var(--rm-border);
                border-radius: var(--rm-radius-bento);
                padding: clamp(2.5rem, 5vw, 4rem) 2rem;
                text-align: center;
                margin-bottom: 4rem;
                display: flex;
                flex-direction: column;
                align-items: center;
            }

            /* Footer */
            .landing-footer {
                border-top: 1px solid var(--rm-border);
                padding: 3rem 0 2rem;
                font-size: 0.85rem;
                color: var(--rm-text-secondary);
            }
            .footer-grid {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 1.5rem;
            }

            /* Mobile & Tablet Responsiveness Enhancements */
            @media (max-width: 960px) {
                .bento-grid { grid-template-columns: 1fr; gap: 1rem; }
                .bento-card.col-span-2, .bento-card.col-span-3 { grid-column: span 1; }
                .bento-card { padding: 1.35rem; }
                .nav-links { display: none; }
            }
            @media (max-width: 768px) {
                .showcase-header {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 0.65rem;
                    padding: 0.75rem 1rem;
                }
                .window-controls {
                    justify-content: space-between;
                }
                .showcase-tabs {
                    width: 100%;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    justify-content: flex-start;
                    padding: 3px;
                    scrollbar-width: none;
                }
                .showcase-tabs::-webkit-scrollbar { display: none; }
                .showcase-tab-btn {
                    flex-shrink: 0;
                    white-space: nowrap;
                    font-size: 0.72rem;
                    padding: 5px 9px;
                }
                .showcase-body { padding: 1rem 0.75rem !important; }
            }
            @media (max-width: 640px) {
                .brand-lockup .wildcard-capsule { display: none; }
                .theme-seg-btn .theme-label { display: none; }
                .theme-seg-btn { padding: 4px 8px; }
                .tunnel-flow-box {
                    flex-direction: column !important;
                    padding: 1rem !important;
                    gap: 0.75rem !important;
                }
                .tunnel-flow-arrow {
                    transform: rotate(90deg);
                    margin: -2px auto;
                }
            }
            @media (max-width: 600px) {
                .hero-cta-group {
                    flex-direction: column;
                    width: 100%;
                    gap: 0.65rem;
                }
                .hero-cta-group .btn {
                    width: 100%;
                    justify-content: center;
                    padding: 0.75rem 1.25rem;
                    font-size: 0.92rem;
                }
                .hardware-badge-strip {
                    gap: 0.4rem;
                }
                .hardware-badge-strip .dot-sep {
                    display: none;
                }
                .hardware-badge-strip span:not(.dot-sep) {
                    background: var(--rm-card-subtle);
                    border: 1px solid var(--rm-border);
                    padding: 4px 10px;
                    border-radius: 9999px;
                    display: inline-block;
                }
                .footer-grid { flex-direction: column; text-align: center; gap: 1rem; }
                .showcase-wrapper {
                    padding: 0.65rem;
                    margin: 1.5rem auto 3rem;
                    border-radius: 18px;
                }
                .cta-banner {
                    padding: 2rem 1.25rem;
                    border-radius: 18px;
                }
                .cta-banner h2 {
                    font-size: clamp(1.6rem, 5vw, 2.4rem) !important;
                }
            }
            @media (max-width: 480px) {
                .nav-actions .btn-white {
                    display: none;
                }
                .nav-actions .btn-cobalt {
                    padding: 0.42rem 0.8rem;
                    font-size: 0.76rem;
                }
                .table-scroll-hint {
                    display: block !important;
                }
            }
        </style>
    </head>
    <body>
        <!-- Top Sticky Navbar -->
        <nav class="landing-nav">
            <div class="nav-inner">
                <a href="?action=landing" class="brand-lockup">
                    <span class="brand-icon">
                        <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    </span>
                    <span>Puru<span class="brand-accent">Panel</span></span>
                    <span class="wildcard-capsule">
                        <span class="pulse-dot"></span>
                        <span>*.<?= htmlspecialchars($wildcard) ?></span>
                    </span>
                </a>

                <div class="nav-links">
                    <a href="#features">Features</a>
                    <a href="#showcase">Live Showcase</a>
                    <a href="#architecture">Architecture</a>
                    <a href="#comparison">Why STB?</a>
                    <a href="?action=login">Member Area</a>
                </div>

                <div class="nav-actions">
                    <div class="theme-segmented-switch">
                        <button type="button" class="theme-seg-btn" id="btn_nav_light" onclick="setTheme('light')" title="Light Theme">
                            <span>☀️</span> <span class="theme-label">Light</span>
                        </button>
                        <button type="button" class="theme-seg-btn" id="btn_nav_dark" onclick="setTheme('dark')" title="Hitam Doff Theme">
                            <span>🌙</span> <span class="theme-label">Dark</span>
                        </button>
                    </div>

                    <?php if ($isLoggedIn): ?>
                        <a href="?action=dashboard" class="btn btn-cobalt" style="padding:0.45rem 1rem; font-size:0.8rem">
                            Console Dashboard &rarr;
                        </a>
                    <?php else: ?>
                        <a href="?action=login" class="btn btn-white" style="padding:0.45rem 1rem; font-size:0.8rem">
                            Sign In
                        </a>
                        <a href="?action=login&mode=register" class="btn btn-cobalt" style="padding:0.45rem 1.1rem; font-size:0.8rem">
                            Get Started
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <div class="landing-container">
            <!-- Hero Section -->
            <section class="hero-section">
                <div class="eyebrow-pill">
                    <span class="pulse-dot"></span>
                    <span>Armbian STB Cloud Engine &bull; Wildcard Cloudflare Tunnel</span>
                </div>
                <h1 class="hero-title">
                    Transform any Armbian TV Box into a <em>High-Speed Cloud Server</em>.
                </h1>
                <p class="hero-subtitle">
                    Instant wildcard subdomains under <strong>*.<?= htmlspecialchars($wildcard) ?></strong>, zero-config Cloudflare Tunnels, in-browser Monaco IDE, automated Nginx & PHP 8.4 pools, and zero-trust Google SSO. 100% self-hosted on 5W hardware.
                </p>

                <div class="hero-cta-group">
                    <a href="?action=login" class="btn btn-cobalt btn-lg">
                        <span>🚀 Launch Member Console</span>
                        <span>&rarr;</span>
                    </a>
                    <a href="?action=google_login" class="btn btn-google btn-lg" title="Sign in with Google">
                        <svg width="18" height="18" viewBox="0 0 24 24">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                        </svg>
                        <span>Continue with Google</span>
                    </a>
                    <a href="#showcase" class="btn btn-white btn-lg">
                        <span>⚡ Explore Live Showcase</span>
                    </a>
                </div>

                <div class="hardware-badge-strip">
                    <span>⚡ 5W Power Consumption</span> <span class="dot-sep">&bull;</span>
                    <span>Quad-Core Cortex-A53</span> <span class="dot-sep">&bull;</span>
                    <span>2GB RAM / 16GB eMMC</span> <span class="dot-sep">&bull;</span>
                    <span>Zero Router Port-Forwarding</span>
                </div>
            </section>

            <!-- Live Showcase Window (#showcase) -->
            <section class="showcase-wrapper" id="showcase">
                <div class="showcase-window">
                    <div class="showcase-header">
                        <div class="window-controls">
                            <span class="win-dot red"></span>
                            <span class="win-dot yellow"></span>
                            <span class="win-dot green"></span>
                            <span style="font-size:0.75rem; color:var(--rm-text-muted); font-weight:600; margin-left:8px">PuruPanel Cloud &bull; STB Host (192.168.60.105)</span>
                        </div>
                        <div class="showcase-tabs">
                            <button type="button" class="showcase-tab-btn active" onclick="switchShowcaseTab('telemetry')">📊 Telemetry Health</button>
                            <button type="button" class="showcase-tab-btn" onclick="switchShowcaseTab('ide')">⚡ PuruCode Web IDE</button>
                            <button type="button" class="showcase-tab-btn" onclick="switchShowcaseTab('tunnel')">🌐 Wildcard Tunnel Route</button>
                        </div>
                    </div>

                    <div class="showcase-body">
                        <!-- Tab 1: Live Telemetry -->
                        <div class="showcase-tab-content active" id="tab_showcase_telemetry">
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1.25rem">
                                <div style="background:var(--rm-card-subtle); border:1px solid var(--rm-border); border-radius:16px; padding:1.25rem">
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted); font-weight:700; text-transform:uppercase; margin-bottom:0.5rem">Active Websites</div>
                                    <div style="font-size:2rem; font-weight:700; color:var(--rm-text); line-height:1; margin-bottom:0.5rem"><?= $activeSites ?> <span style="font-size:1rem; color:var(--rm-text-muted)">/ <?= $totalSites ?> total</span></div>
                                    <span style="display:inline-flex; align-items:center; gap:5px; font-size:0.75rem; color:var(--rm-live-text); background:var(--rm-live-bg); padding:2px 8px; border-radius:9999px"><span class="pulse-dot" style="width:6px; height:6px"></span> Live on *.<?= htmlspecialchars($wildcard) ?></span>
                                </div>
                                <div style="background:var(--rm-card-subtle); border:1px solid var(--rm-border); border-radius:16px; padding:1.25rem">
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted); font-weight:700; text-transform:uppercase; margin-bottom:0.5rem">Disk Storage</div>
                                    <div style="font-size:2rem; font-weight:700; color:var(--rm-text); line-height:1; margin-bottom:0.5rem"><?= $stats['disk_used_percent'] ?>%</div>
                                    <div style="height:6px; background:var(--rm-border); border-radius:9999px; overflow:hidden; margin-top:8px">
                                        <div style="width:<?= $stats['disk_used_percent'] ?>%; height:100%; background:var(--rm-cobalt); border-radius:9999px"></div>
                                    </div>
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted); margin-top:4px"><?= $stats['disk_free'] ?> Free of <?= $stats['disk_total'] ?></div>
                                </div>
                                <div style="background:var(--rm-card-subtle); border:1px solid var(--rm-border); border-radius:16px; padding:1.25rem">
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted); font-weight:700; text-transform:uppercase; margin-bottom:0.5rem">RAM Memory</div>
                                    <div style="font-size:2rem; font-weight:700; color:var(--rm-text); line-height:1; margin-bottom:0.5rem"><?= $stats['mem_used_percent'] ?>%</div>
                                    <div style="height:6px; background:var(--rm-border); border-radius:9999px; overflow:hidden; margin-top:8px">
                                        <div style="width:<?= $stats['mem_used_percent'] ?>%; height:100%; background:#10B981; border-radius:9999px"></div>
                                    </div>
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted); margin-top:4px">Free: <?= $stats['mem_free_mb'] ?> MB of <?= $stats['mem_total_mb'] ?> MB</div>
                                </div>
                                <div style="background:var(--rm-card-subtle); border:1px solid var(--rm-border); border-radius:16px; padding:1.25rem">
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted); font-weight:700; text-transform:uppercase; margin-bottom:0.5rem">CPU Load Avg</div>
                                    <div style="font-size:2rem; font-weight:700; color:var(--rm-text); line-height:1; margin-bottom:0.5rem"><?= number_format((float)$stats['load_1'], 2) ?></div>
                                    <div style="font-size:0.75rem; color:var(--rm-cobalt); font-weight:600">Optimal 4-Core Armbian SOC</div>
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted); margin-top:4px">5m: <?= number_format((float)$stats['load_5'], 2) ?> &bull; 15m: <?= number_format((float)$stats['load_15'], 2) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab 2: PuruCode IDE Preview -->
                        <div class="showcase-tab-content" id="tab_showcase_ide">
                            <div style="background:#0D0D0D; color:#F3F4F6; border-radius:14px; padding:1.25rem; font-family:'JetBrains Mono', monospace; font-size:0.85rem; border:1px solid rgba(255,255,255,0.1)">
                                <div style="display:flex; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.08); padding-bottom:0.75rem; margin-bottom:0.75rem">
                                    <span style="color:#60A5FA; font-weight:700">⚡ purucode &bull; index.php</span>
                                    <span style="font-size:0.75rem; color:#A1A1AA">PHP 8.4 FPM &bull; UTF-8</span>
                                </div>
                                <pre style="margin:0; line-height:1.6"><span style="color:#F43F5E">&lt;?php</span>
<span style="color:#64748B">// PuruPanel Automated Virtual Host</span>
<span style="color:#38BDF8">$app</span> = <span style="color:#A78BFA">new</span> CloudApp([
    <span style="color:#FBBF24">'domain'</span> =&gt; <span style="color:#34D399">'app.<?= htmlspecialchars($wildcard) ?>'</span>,
    <span style="color:#FBBF24">'engine'</span> =&gt; <span style="color:#34D399">'Armbian Linux on STB'</span>,
    <span style="color:#FBBF24">'tunnel'</span> =&gt; <span style="color:#34D399">'Cloudflare Zero Trust'</span>,
    <span style="color:#FBBF24">'sso'</span>    =&gt; <span style="color:#34D399">'Google OAuth 2.0'</span>
]);

<span style="color:#38BDF8">$app</span>-&gt;<span style="color:#60A5FA">run</span>();</pre>
                            </div>
                        </div>

                        <!-- Tab 3: Tunnel Routing -->
                        <div class="showcase-tab-content" id="tab_showcase_tunnel">
                            <div class="tunnel-flow-box" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; padding:1.5rem; background:var(--rm-card-subtle); border-radius:16px; border:1px solid var(--rm-border)">
                                <div style="text-align:center; flex:1; min-width:130px">
                                    <div style="font-size:1.8rem; margin-bottom:0.25rem">🌍</div>
                                    <div style="font-weight:700; font-size:0.85rem">Worldwide User</div>
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted)">HTTPS Request</div>
                                </div>
                                <div class="tunnel-flow-arrow" style="color:var(--rm-cobalt); font-weight:800">&rarr;</div>
                                <div style="text-align:center; flex:1; min-width:130px">
                                    <div style="font-size:1.8rem; margin-bottom:0.25rem">🛡️</div>
                                    <div style="font-weight:700; font-size:0.85rem">Cloudflare Edge</div>
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted)">Wildcard SSL *.my.id</div>
                                </div>
                                <div class="tunnel-flow-arrow" style="color:var(--rm-cobalt); font-weight:800">&rarr;</div>
                                <div style="text-align:center; flex:1; min-width:130px">
                                    <div style="font-size:1.8rem; margin-bottom:0.25rem">⚡</div>
                                    <div style="font-weight:700; font-size:0.85rem">STB Tunnel Daemon</div>
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted)">Zero Port Forwarding</div>
                                </div>
                                <div class="tunnel-flow-arrow" style="color:var(--rm-cobalt); font-weight:800">&rarr;</div>
                                <div style="text-align:center; flex:1; min-width:130px">
                                    <div style="font-size:1.8rem; margin-bottom:0.25rem">🚀</div>
                                    <div style="font-weight:700; font-size:0.85rem">Nginx & PHP 8.4</div>
                                    <div style="font-size:0.72rem; color:var(--rm-text-muted)">Isolated Vhost Pool</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Bento Features Section (#features) -->
            <section id="features" style="padding: 2rem 0 4rem">
                <div class="section-header">
                    <div class="eyebrow-pill"><span>Engine Architecture</span></div>
                    <h2 class="section-title">Everything you need to host like a pro.</h2>
                    <p class="section-subtitle">PuruPanel packs enterprise cloud virtualization principles into a lightweight, robust platform built specifically for Armbian STB micro-hardware.</p>
                </div>

                <div class="bento-grid">
                    <div class="bento-card col-span-2">
                        <div>
                            <div class="bento-icon">🌐</div>
                            <h3 class="bento-card-title">Instant Wildcard Subdomain Propagation</h3>
                            <p class="bento-card-desc">Deploy sites under <code>*.<?= htmlspecialchars($wildcard) ?></code> in 1 click. Thanks to native Cloudflare Wildcard Tunnel integration, every new subdomain is instantly reachable worldwide with automatic SSL encryption and zero router port-forwarding.</p>
                        </div>
                        <div style="margin-top:1.5rem; display:flex; gap:0.5rem; flex-wrap:wrap">
                            <span class="wildcard-capsule">No Static IP Needed</span>
                            <span class="wildcard-capsule">Zero Port-Forwarding</span>
                            <span class="wildcard-capsule">Automatic Cloudflare SSL</span>
                        </div>
                    </div>

                    <div class="bento-card">
                        <div>
                            <div class="bento-icon">⚡</div>
                            <h3 class="bento-card-title">PuruCode Web IDE</h3>
                            <p class="bento-card-desc">Code directly on your TV Box from any browser with a full Monaco-powered IDE, syntax highlighting, integrated shell terminal, and multi-file tree manager.</p>
                        </div>
                        <div style="margin-top:1.5rem">
                            <span class="wildcard-capsule">Monaco Engine</span>
                        </div>
                    </div>

                    <div class="bento-card">
                        <div>
                            <div class="bento-icon">🌱</div>
                            <h3 class="bento-card-title">5W Ultra-Green Server</h3>
                            <p class="bento-card-desc">An Armbian STB consumes under 5 Watts under full load. Run your private cloud 24/7 without worrying about electricity bills or fan noise.</p>
                        </div>
                        <div style="margin-top:1.5rem">
                            <span class="wildcard-capsule">~$0.50/month electricity</span>
                        </div>
                    </div>

                    <div class="bento-card">
                        <div>
                            <div class="bento-icon">🔒</div>
                            <h3 class="bento-card-title">Isolated Nginx & PHP 8.4 Pools</h3>
                            <p class="bento-card-desc">Each virtual host runs in an isolated document root with dedicated unix sockets, preventing cross-site contamination and maximizing memory efficiency.</p>
                        </div>
                        <div style="margin-top:1.5rem">
                            <span class="wildcard-capsule">PHP 8.4 Default</span>
                        </div>
                    </div>

                    <div class="bento-card">
                        <div>
                            <div class="bento-icon">🇬</div>
                            <h3 class="bento-card-title">Google SSO & Zero Trust</h3>
                            <p class="bento-card-desc">Sign in with one click via Google Single Sign-On (OAuth 2.0). Enterprise-grade identity management with role-based access and complete security audit logging.</p>
                        </div>
                        <div style="margin-top:1.5rem">
                            <span class="wildcard-capsule">OAuth 2.0 Verified</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Comparison Table Section (#comparison) -->
            <section id="comparison" style="margin-bottom: 4rem">
                <div class="section-header">
                    <div class="eyebrow-pill"><span>Hardware Economics</span></div>
                    <h2 class="section-title">Why Armbian STB beats Traditional VPS.</h2>
                    <p class="section-subtitle">Stop paying recurring monthly fees for cloud servers when you can repurpose an affordable TV box into a high-performance personal cloud.</p>
                </div>

                <div class="comparison-card">
                    <div class="table-scroll-hint" style="display:none; font-size:0.75rem; color:var(--rm-text-muted); margin-bottom:0.75rem; text-align:center">
                        👈 Geser tabel ke samping untuk melihat detail perbandingan 👉
                    </div>
                    <table class="comp-table">
                        <thead>
                            <tr>
                                <th>Feature</th>
                                <th>Traditional Cloud VPS</th>
                                <th>Raspberry Pi 4</th>
                                <th>Armbian STB (PuruPanel)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="highlight-row">
                                <td>Hardware Investment</td>
                                <td>$120 / year (Recurring)</td>
                                <td>$85+ (Hardware only)</td>
                                <td>$15 one-time (Repurposed TV Box)</td>
                            </tr>
                            <tr>
                                <td>Power Draw</td>
                                <td>Cloud Datacenter</td>
                                <td>15W (Requires fan)</td>
                                <td><strong>5W Silent Passive Cooling</strong></td>
                            </tr>
                            <tr>
                                <td>Public Access</td>
                                <td>Public IPv4 included</td>
                                <td>Requires Port Forwarding</td>
                                <td><strong>Zero-Config Cloudflare Wildcard</strong></td>
                            </tr>
                            <tr>
                                <td>In-Browser Web IDE</td>
                                <td>Setup VSCode Server manually</td>
                                <td>Manual config</td>
                                <td><strong>Built-in PuruCode Monaco IDE</strong></td>
                            </tr>
                            <tr>
                                <td>Single Sign-On</td>
                                <td>Custom setup</td>
                                <td>Manual setup</td>
                                <td><strong>Google SSO Integrated</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- CTA Banner -->
            <section class="cta-banner">
                <div class="eyebrow-pill"><span>Get Started in 60 Seconds</span></div>
                <h2 style="font-family:'Newsreader', serif; font-size:clamp(2rem, 4vw, 3rem); font-weight:500; margin-bottom:1rem">Ready to unleash your STB Cloud?</h2>
                <p style="color:var(--rm-text-secondary); max-width:540px; margin-bottom:2rem">
                    Log in to the Member Console or continue with Google Single Sign-On to deploy your first website today.
                </p>
                <div class="hero-cta-group">
                    <a href="?action=google_login" class="btn btn-google btn-lg">
                        <svg width="18" height="18" viewBox="0 0 24 24">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                        </svg>
                        <span>Continue with Google</span>
                    </a>
                    <a href="?action=login" class="btn btn-cobalt btn-lg">
                        <span>Launch Member Console &rarr;</span>
                    </a>
                </div>
            </section>

            <!-- Footer -->
            <footer class="landing-footer">
                <div class="footer-grid">
                    <div style="display:flex; align-items:center; gap:0.6rem">
                        <span class="brand-icon" style="width:26px; height:26px; font-size:0.9rem">⚡</span>
                        <strong style="color:var(--rm-text)"><?= htmlspecialchars($panelName) ?> Cloud</strong>
                        <span>&bull; Powered by Armbian STB</span>
                    </div>
                    <div>
                        <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($panelName) ?> Cloud. All rights reserved.</span>
                    </div>
                </div>
            </footer>
        </div>

        <script>
        function setTheme(t) {
            document.documentElement.setAttribute('data-theme', t);
            localStorage.setItem('puru_theme', t);
            syncTheme();
        }
        function syncTheme() {
            const t = localStorage.getItem('puru_theme') || 'light';
            const btnL = document.getElementById('btn_nav_light');
            const btnD = document.getElementById('btn_nav_dark');
            if (btnL && btnD) {
                if (t === 'dark') {
                    btnD.classList.add('active');
                    btnL.classList.remove('active');
                } else {
                    btnL.classList.add('active');
                    btnD.classList.remove('active');
                }
            }
        }
        function switchShowcaseTab(tab) {
            document.querySelectorAll('.showcase-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.showcase-tab-content').forEach(c => c.classList.remove('active'));
            
            event.target.classList.add('active');
            const content = document.getElementById('tab_showcase_' + tab);
            if (content) content.classList.add('active');
        }
        syncTheme();
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// MEMBER AREA (Sign In / Register / Google SSO)
// ============================================
if ($action === 'login' || $action === 'register') {
    $panelName = $vhost->getSetting('panel_name') ?: 'PuruPanel';
    $wildcard = $vhost->getSetting('wildcard_domain') ?: 'purujekuto.my.id';
    $mode = $_GET['mode'] ?? ($action === 'register' ? 'register' : 'login');
    $showSsoDialog = isset($_GET['sso_dialog']) && $_GET['sso_dialog'] === '1';
    $oauthError = $_GET['error'] ?? '';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Member Area &bull; <?= htmlspecialchars($panelName) ?> Cloud</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=stb">
        <link rel="alternate icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=stb">
        <link rel="alternate icon" type="image/x-icon" href="/favicon.ico?v=stb">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=stb">
        <script>
        (function() {
            const t = localStorage.getItem('puru_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;1,6..72,400&family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
        <style>
            :root, [data-theme="light"] {
                --rm-canvas: #F4F2EC;
                --rm-bento: #E9E6DE;
                --rm-card: #FFFFFF;
                --rm-card-subtle: #FAF9F6;
                --rm-border: rgba(24, 25, 28, 0.08);
                --rm-border-light: rgba(24, 25, 28, 0.04);
                --rm-cobalt: #0055FF;
                --rm-cobalt-hover: #0044CC;
                --rm-cobalt-subtle: #EEF4FF;
                --rm-cobalt-glow: 0 8px 25px rgba(0, 85, 255, 0.35);
                --rm-text: #141518;
                --rm-text-secondary: #585A62;
                --rm-text-muted: #878A94;
                --rm-input-bg: #FFFFFF;
                --rm-radius-pill: 9999px;
            }

            [data-theme="dark"] {
                --rm-canvas: #000000;
                --rm-bento: #0B0B0B;
                --rm-card: #121212;
                --rm-card-subtle: #191919;
                --rm-border: rgba(255, 255, 255, 0.09);
                --rm-border-light: rgba(255, 255, 255, 0.05);
                --rm-cobalt: #3B82F6;
                --rm-cobalt-hover: #60A5FA;
                --rm-cobalt-subtle: rgba(59, 130, 246, 0.15);
                --rm-cobalt-glow: 0 8px 25px rgba(59, 130, 246, 0.45);
                --rm-text: #FFFFFF;
                --rm-text-secondary: #A1A1AA;
                --rm-text-muted: #71717A;
                --rm-input-bg: #0D0D0D;
            }

            @keyframes rm-blue-pulse {
                0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); transform: scale(0.98); }
                50% { box-shadow: 0 0 0 6px rgba(59, 130, 246, 0); transform: scale(1.08); }
                100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); transform: scale(0.98); }
            }

            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: "Plus Jakarta Sans", -apple-system, sans-serif;
                background-color: var(--rm-canvas);
                color: var(--rm-text);
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                padding: 2rem 1rem;
                position: relative;
                font-size: 14px;
            }

            .login-header-nav {
                width: 100%;
                max-width: 460px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                margin-bottom: 1.5rem;
            }
            .back-link {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                text-decoration: none;
                color: var(--rm-text-secondary);
                font-size: 0.82rem;
                font-weight: 600;
                transition: color 0.2s;
            }
            .back-link:hover { color: var(--rm-text); }

            .hero-headline {
                font-family: "Newsreader", Georgia, serif;
                font-size: clamp(1.8rem, 4vw, 2.4rem);
                font-weight: 500;
                line-height: 1.15;
                letter-spacing: -0.02em;
                text-align: center;
                max-width: 520px;
                margin-bottom: 0.5rem;
                color: var(--rm-text);
            }
            .hero-subhead {
                font-size: 0.88rem;
                color: var(--rm-text-secondary);
                text-align: center;
                max-width: 440px;
                margin-bottom: 1.75rem;
            }

            .login-card {
                background: var(--rm-card);
                border: 1px solid var(--rm-border);
                border-radius: 24px;
                padding: clamp(1.25rem, 4vw, 2.25rem);
                width: 100%;
                max-width: 460px;
                box-sizing: border-box;
                box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.15), 0 0 1px rgba(0,0,0,0.05);
            }
            @media (max-width: 480px) {
                .theme-seg-btn .theme-btn-label { display: none; }
                .login-card { border-radius: 20px; }
            }
            [data-theme="dark"] .login-card {
                box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8), 0 0 1px rgba(255,255,255,0.1);
            }

            .card-header-lockup {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 1.5rem;
            }
            .brand-mini {
                display: flex;
                align-items: center;
                gap: 8px;
                font-family: var(--font-brand, 'Outfit', sans-serif);
                font-weight: 700;
                font-size: 1.25rem;
                letter-spacing: -0.015em;
                color: var(--rm-text);
            }
            .brand-mini .brand-accent {
                color: var(--rm-cobalt);
            }
            .brand-mini .brand-icon-mini {
                width: 26px;
                height: 26px;
                border-radius: 7px;
                background: var(--rm-cobalt);
                color: #FFFFFF;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 3px 8px rgba(0, 85, 255, 0.25);
                flex-shrink: 0;
            }
            .brand-mini .brand-icon-mini svg {
                width: 14px;
                height: 14px;
                fill: currentColor;
            }

            /* Auth Mode Switcher Tab */
            .auth-tabs {
                display: flex;
                background: var(--rm-card-subtle);
                border: 1px solid var(--rm-border);
                border-radius: 9999px;
                padding: 3px;
                gap: 3px;
                margin-bottom: 1.5rem;
            }
            .auth-tab-btn {
                flex: 1;
                border: none;
                background: transparent;
                color: var(--rm-text-secondary);
                font-size: 0.82rem;
                font-weight: 600;
                padding: 6px 12px;
                border-radius: 9999px;
                cursor: pointer;
                text-align: center;
                transition: all 0.2s;
                text-decoration: none;
            }
            .auth-tab-btn.active {
                background: var(--rm-card);
                color: var(--rm-text);
                font-weight: 700;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            }

            /* Google SSO Button */
            .btn-google-sso {
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                padding: 0.75rem 1rem;
                border-radius: var(--rm-radius-pill);
                background: var(--rm-card-subtle);
                border: 1px solid var(--rm-border);
                color: var(--rm-text);
                font-size: 0.9rem;
                font-weight: 700;
                cursor: pointer;
                text-decoration: none;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                margin-bottom: 1.25rem;
            }
            .btn-google-sso:hover {
                background: var(--rm-card);
                border-color: var(--rm-cobalt);
                transform: translateY(-1px);
                box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
            }

            .auth-divider {
                display: flex;
                align-items: center;
                text-align: center;
                margin-bottom: 1.25rem;
                color: var(--rm-text-muted);
                font-size: 0.75rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            .auth-divider::before, .auth-divider::after {
                content: '';
                flex: 1;
                border-bottom: 1px solid var(--rm-border);
            }
            .auth-divider span {
                padding: 0 10px;
            }

            .form-group {
                margin-bottom: 1.25rem;
            }
            .form-group label {
                display: block;
                margin-bottom: 0.45rem;
                font-size: 0.8rem;
                font-weight: 700;
                color: var(--rm-text);
            }
            .form-group input {
                width: 100%;
                padding: 0.75rem 1rem;
                background: var(--rm-input-bg);
                border: 1px solid var(--rm-border);
                border-radius: 12px;
                color: var(--rm-text);
                font-size: 0.9rem;
                outline: none;
                transition: border-color 0.2s;
            }
            .form-group input:focus {
                border-color: var(--rm-cobalt);
            }

            .btn-submit {
                width: 100%;
                padding: 0.75rem 1.25rem;
                border-radius: var(--rm-radius-pill);
                background: var(--rm-cobalt);
                color: #FFFFFF;
                border: none;
                font-size: 0.92rem;
                font-weight: 700;
                cursor: pointer;
                box-shadow: var(--rm-cobalt-glow);
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .btn-submit:hover {
                background: var(--rm-cobalt-hover);
                transform: translateY(-1px);
            }

            .error-box {
                background: rgba(239, 68, 68, 0.12);
                border: 1px solid rgba(239, 68, 68, 0.25);
                color: #EF4444;
                padding: 0.75rem 1rem;
                border-radius: 12px;
                font-size: 0.82rem;
                font-weight: 600;
                margin-bottom: 1.25rem;
            }

            /* SSO Dialog Modal */
            .sso-dialog-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.65);
                backdrop-filter: blur(8px);
                z-index: 2000;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1rem;
            }
            .sso-dialog-box {
                background: var(--rm-card);
                border: 1px solid var(--rm-border);
                border-radius: 24px;
                padding: 2rem;
                width: 100%;
                max-width: 440px;
                box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.5);
                text-align: center;
            }
            .sso-account-card {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 0.85rem 1rem;
                border-radius: 14px;
                background: var(--rm-card-subtle);
                border: 1px solid var(--rm-border);
                margin: 1.25rem 0;
                text-align: left;
                cursor: pointer;
                transition: all 0.2s;
                text-decoration: none;
                color: var(--rm-text);
            }
            .sso-account-card:hover {
                border-color: var(--rm-cobalt);
                transform: translateY(-1px);
            }
            .sso-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: #4285F4;
                color: #FFFFFF;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 800;
                font-size: 1.1rem;
            }

            .bottom-pills {
                display: flex;
                gap: 0.5rem;
                margin-top: 1.75rem;
                flex-wrap: wrap;
                justify-content: center;
            }
            .micro-pill {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 12px;
                border-radius: var(--rm-radius-pill);
                background: var(--rm-card);
                border: 1px solid var(--rm-border);
                font-size: 0.72rem;
                font-weight: 600;
                color: var(--rm-text-secondary);
            }
        
            /* Segmented Switch on Login Header */
            .theme-segmented-switch {
                display: inline-flex;
                align-items: center;
                background: var(--rm-card-subtle);
                border: 1px solid var(--rm-border);
                border-radius: 9999px;
                padding: 3px;
                gap: 2px;
            }
            .theme-seg-btn {
                border: none;
                background: transparent;
                color: var(--rm-text-secondary);
                font-size: 0.72rem;
                font-weight: 600;
                padding: 4px 10px;
                border-radius: 9999px;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                gap: 5px;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                font-family: inherit;
                line-height: 1;
            }
            .theme-seg-btn:hover { color: var(--rm-text); }
            .theme-seg-btn.active {
                background: var(--rm-card);
                color: var(--rm-text);
                font-weight: 700;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
                border: 1px solid var(--rm-border);
            }
            [data-theme="dark"] .theme-seg-btn.active {
                background: #1A1A1A;
                color: #FFFFFF;
                border: 1px solid rgba(255, 255, 255, 0.16);
            }
            @media (max-width: 480px) {
                .theme-seg-btn .theme-btn-label { display: none; }
                .theme-seg-btn { padding: 4px 8px; }
            }
</style>
    </head>
    <body>
        <div class="login-header-nav">
            <a href="?action=landing" class="back-link">
                <span>&larr;</span>
                <span>Back to Home</span>
            </a>

            <div class="theme-segmented-switch">
                <button type="button" class="theme-seg-btn" id="btn_auth_light" onclick="setTheme('light')" title="Light Theme">
                    <span>☀️</span> Light
                </button>
                <button type="button" class="theme-seg-btn" id="btn_auth_dark" onclick="setTheme('dark')" title="Hitam Doff Theme">
                    <span>🌙</span> Dark
                </button>
            </div>
        </div>

        <h1 class="hero-headline">Welcome to <?= htmlspecialchars($panelName) ?> Cloud</h1>
        <p class="hero-subhead">Sign in to manage your websites, virtual hosts, and cloud storage.</p>

        <div class="login-card">
            <div class="card-header-lockup">
                <div class="brand-mini">
                    <span class="brand-icon-mini">
                        <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    </span>
                    <span>Puru<span class="brand-accent">Panel</span></span>
                </div>
                <span class="micro-pill" style="border-radius:9999px"><span class="pulse-dot" style="width:6px; height:6px"></span> STB Cloud Console</span>
            </div>

            <?php if (!empty($loginError)): ?>
                <div class="error-box"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>
            <?php if ($oauthError === 'oauth_failed'): ?>
                <div class="error-box">Google SSO authorization failed. Please try again.</div>
            <?php endif; ?>

            <!-- Google SSO Button -->
            <a href="?action=google_login" class="btn-google-sso" title="One-Click Sign-in with Google">
                <svg width="20" height="20" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                </svg>
                <span>Continue with Google</span>
            </a>

            <div class="auth-divider">
                <span>or sign in with password</span>
            </div>

            <!-- Auth Mode Switcher Tab -->
            <div class="auth-tabs">
                <button type="button" class="auth-tab-btn <?= $mode !== 'register' ? 'active' : '' ?>" onclick="setAuthMode('login')">Sign In</button>
                <button type="button" class="auth-tab-btn <?= $mode === 'register' ? 'active' : '' ?>" onclick="setAuthMode('register')">Create Account</button>
            </div>

            <form method="post" action="?action=login">
                <div class="form-group">
                    <label id="lbl_user">Username or Email</label>
                    <input type="text" name="username" placeholder="admin" required autofocus>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••••••" required>
                </div>
                <div class="form-group" id="group_confirm_pass" style="display: <?= $mode === 'register' ? 'block' : 'none' ?>;">
                    <label>Confirm Password</label>
                    <input type="password" name="password_confirm" placeholder="••••••••••••">
                </div>
                <button type="submit" class="btn-submit" id="btn_submit_text">
                    <?= $mode === 'register' ? 'Create Member Account &rarr;' : 'Sign In to Console &rarr;' ?>
                </button>
            </form>
        </div>

        <!-- Google SSO Instant Account Dialog Modal -->
        <?php if ($showSsoDialog): ?>
        <div class="sso-dialog-overlay" onclick="window.location.href='?action=login'">
            <div class="sso-dialog-box" onclick="event.stopPropagation()">
                <svg width="40" height="40" viewBox="0 0 24 24" style="margin-bottom:0.75rem">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                </svg>
                <h3 style="font-size:1.25rem; font-weight:700; margin-bottom:0.35rem">Sign in with Google</h3>
                <p style="font-size:0.82rem; color:var(--rm-text-secondary); margin-bottom:1.25rem">
                    Choose an account to continue to <strong><?= htmlspecialchars($panelName) ?> Cloud</strong>
                </p>

                <!-- 1-Click Instant SSO Account Option -->
                <a href="?action=google_login&confirm=1" class="sso-account-card">
                    <div class="sso-avatar">A</div>
                    <div style="flex:1">
                        <div style="font-weight:700; font-size:0.9rem">Console Admin</div>
                        <div style="font-size:0.75rem; color:var(--rm-text-muted)">admin@<?= htmlspecialchars($wildcard) ?></div>
                    </div>
                    <span style="font-size:0.75rem; color:var(--rm-cobalt); font-weight:700">Continue &rarr;</span>
                </a>

                <div style="padding:0.75rem; background:var(--rm-card-subtle); border-radius:12px; font-size:0.75rem; color:var(--rm-text-secondary); text-align:left; border:1px solid var(--rm-border-light); margin-bottom:1.25rem">
                    💡 <strong>Pro Tip:</strong> To use your official Google Cloud OAuth credentials, you can configure your <code>Google Client ID</code> &amp; <code>Secret</code> in <strong>Settings &amp; Engine</strong>.
                </div>

                <a href="?action=login" class="btn btn-white" style="width:100%">Cancel</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="bottom-pills">
            <div class="micro-pill">
                <span class="pulse-dot" style="width:6px; height:6px"></span>
                <span>Protected by Cloudflare Wildcard</span>
            </div>
            <div class="micro-pill">
                <span>⚡ Powered by STB Armbian</span>
            </div>
        </div>

        <script>
        function setAuthMode(m) {
            const groupConfirm = document.getElementById('group_confirm_pass');
            const btnSubmit = document.getElementById('btn_submit_text');
            const btns = document.querySelectorAll('.auth-tab-btn');
            if (m === 'register') {
                groupConfirm.style.display = 'block';
                btnSubmit.innerHTML = 'Create Member Account &rarr;';
                btns[0].classList.remove('active');
                btns[1].classList.add('active');
            } else {
                groupConfirm.style.display = 'none';
                btnSubmit.innerHTML = 'Sign In to Console &rarr;';
                btns[0].classList.add('active');
                btns[1].classList.remove('active');
            }
        }
        function setTheme(t) {
            document.documentElement.setAttribute('data-theme', t);
            localStorage.setItem('puru_theme', t);
            syncTheme();
        }
        function syncTheme() {
            const t = localStorage.getItem('puru_theme') || 'light';
            const btnL = document.getElementById('btn_auth_light');
            const btnD = document.getElementById('btn_auth_dark');
            if (btnL && btnD) {
                if (t === 'dark') {
                    btnD.classList.add('active');
                    btnL.classList.remove('active');
                } else {
                    btnL.classList.add('active');
                    btnD.classList.remove('active');
                }
            }
        }
        syncTheme();
        </script>
    </body>
    </html>
    <?php
    exit;
}

if ($action === 'dashboard') {
    $stats = $vhost->getSystemStats();
    $sites = $vhost->getAllSites($currentUserId, $currentUserRole);
    $activeSites = count(array_filter($sites, fn($s) => $s['status'] === 'active'));
    $totalSites = count($sites);
    $logs = $vhost->getActivityLog(10);
    $wildcard = $vhost->getSetting('wildcard_domain') ?: 'purujekuto.my.id';

    // Format load averages to clean 2 decimal places
    $load1 = number_format((float)$stats['load_1'], 2);
    $load5 = number_format((float)$stats['load_5'], 2);
    $load15 = number_format((float)$stats['load_15'], 2);

    // Accurate Free / Total computations
    $diskFreeGb = round($stats['disk_free'] / (1024 * 1024 * 1024), 1);
    $diskTotalGb = round($stats['disk_total'] / (1024 * 1024 * 1024), 1);
    $memFreeMb = round($stats['mem_available'] / (1024 * 1024), 0);
    $memTotalMb = round($stats['mem_total'] / (1024 * 1024), 0);

    echo renderHeader('Dashboard', $vhost);
    ?>
    <div class="page-header-rm">
        <div>
            <div class="page-eyebrow">
                <span class="dot"></span>
                <span>Dashboard &bull; Overview</span>
            </div>
            <h1 class="page-title">Cloud Infrastructure & Websites</h1>
            <p class="page-subtitle">Real-time telemetry and management for *.<?= htmlspecialchars($wildcard) ?> on STB</p>
        </div>
        <div>
            <a href="?action=sites" class="btn btn-primary">+ Deploy New Site</a>
        </div>
    </div>

    <!-- Main Bento Container: Key Operational Telemetry -->
    <div class="bento-container">
        <div class="bento-title">
            <span>Operational Health & Resources</span>
            <span class="status-pill live" id="live_telemetry_badge" title="Telemetry auto-refreshes every 3 seconds">
                <span class="dot" id="live_pulse_dot" style="transition:transform 0.2s"></span>
                <span>Realtime Telemetry (3s)</span>
            </span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            <!-- Floating Card 1: Active Sites -->
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between">
                <div>
                    <div style="font-size:0.72rem; font-weight:700; color:var(--rm-text-muted); text-transform:uppercase; letter-spacing:0.05em">Active Websites</div>
                    <div style="font-family:'Newsreader', Georgia, serif; font-size:2.1rem; font-weight:600; color:var(--rm-text); margin:0.25rem 0"><?= $activeSites ?> <span style="font-size:0.95rem; font-family:'Plus Jakarta Sans', sans-serif; color:var(--rm-text-secondary); font-weight:500">/ <?= $totalSites ?> total</span></div>
                </div>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-top:0.5rem">
                    <span class="status-pill live" style="padding:2px 8px; font-size:0.7rem"><span class="dot" style="width:5px; height:5px"></span> Live on *.<?= htmlspecialchars($wildcard) ?></span>
                    <a href="?action=sites" style="color:var(--rm-cobalt); font-weight:700; text-decoration:none; font-size:0.78rem">View &rarr;</a>
                </div>
            </div>

            <!-- Floating Card 2: Disk Usage -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:flex-start">
                    <div>
                        <div style="font-size:0.72rem; font-weight:700; color:var(--rm-text-muted); text-transform:uppercase; letter-spacing:0.05em">Disk Storage</div>
                        <div id="live_disk_pct" style="font-family:'Newsreader', Georgia, serif; font-size:2.1rem; font-weight:600; color:var(--rm-text); margin:0.25rem 0"><?= $stats['disk_used_percent'] ?>%</div>
                    </div>
                    <span class="tag-pill" style="background:var(--rm-live-bg); color:var(--rm-live-text); border:1px solid rgba(16,185,129,0.25); font-size:0.68rem; padding:2px 7px">+<?= $diskFreeGb ?> GB Free</span>
                </div>
                <div style="height:6px; background:var(--rm-card-subtle); border:1px solid var(--rm-border-light); border-radius:9999px; overflow:hidden; margin:0.65rem 0 0.4rem">
                    <div id="live_disk_bar" style="width:<?= min(100, $stats['disk_used_percent']) ?>%; height:100%; background:var(--rm-cobalt); border-radius:9999px; transition:width 0.4s ease;"></div>
                </div>
                <div id="live_disk_sub" style="font-size:0.71rem; color:var(--rm-text-muted); font-weight:500">Free: <?= $diskFreeGb ?> GB of <?= $diskTotalGb ?> GB</div>
            </div>

            <!-- Floating Card 3: Memory -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:flex-start">
                    <div>
                        <div style="font-size:0.72rem; font-weight:700; color:var(--rm-text-muted); text-transform:uppercase; letter-spacing:0.05em">RAM Memory</div>
                        <div id="live_mem_pct" style="font-family:'Newsreader', Georgia, serif; font-size:2.1rem; font-weight:600; color:var(--rm-text); margin:0.25rem 0"><?= $stats['mem_used_percent'] ?>%</div>
                    </div>
                    <span class="tag-pill" style="background:var(--rm-live-bg); color:var(--rm-live-text); border:1px solid rgba(16,185,129,0.25); font-size:0.68rem; padding:2px 7px">Optimal</span>
                </div>
                <div style="height:6px; background:var(--rm-card-subtle); border:1px solid var(--rm-border-light); border-radius:9999px; overflow:hidden; margin:0.65rem 0 0.4rem">
                    <div id="live_mem_bar" style="width:<?= min(100, $stats['mem_used_percent']) ?>%; height:100%; background:#10B981; border-radius:9999px; transition:width 0.4s ease;"></div>
                </div>
                <div id="live_mem_sub" style="font-size:0.71rem; color:var(--rm-text-muted); font-weight:500">Free RAM: <?= $memFreeMb ?> MB of <?= $memTotalMb ?> MB</div>
            </div>

            <!-- Floating Card 4: Server Load Sparkline -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:flex-start">
                    <div>
                        <div style="font-size:0.72rem; font-weight:700; color:var(--rm-text-muted); text-transform:uppercase; letter-spacing:0.05em">CPU Load Avg</div>
                        <div id="live_cpu_val" style="font-family:'Newsreader', Georgia, serif; font-size:2.1rem; font-weight:600; color:var(--rm-text); margin:0.25rem 0"><?= $load1 ?></div>
                    </div>
                    <!-- Real-Time Animated SVG Curve Line Sparkline -->
                    <svg width="70" height="34" viewBox="0 0 80 40" fill="none" style="overflow:visible">
                        <path id="sparkline_path" d="M 5 35 Q 20 20, 40 25 T 75 10" stroke="#0055FF" stroke-width="2.5" stroke-linecap="round" fill="none" style="transition:d 0.3s ease"/>
                        <circle id="sparkline_dot" cx="75" cy="10" r="3.5" fill="#0055FF" style="transition:all 0.3s ease"/>
                    </svg>
                </div>
                <div style="display:flex; gap:0.4rem; margin-top:0.45rem">
                    <span id="live_cpu_5m" class="tag-pill" style="font-size:0.68rem; padding:2px 7px">5m: <?= $load5 ?></span>
                    <span id="live_cpu_15m" class="tag-pill" style="font-size:0.68rem; padding:2px 7px">15m: <?= $load15 ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary Bento Section: Split Specs & Activity -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
        <!-- Left: System Engine Specs -->
        <div class="card">
            <h3 style="font-family:'Newsreader', Georgia, serif; font-size:1.35rem; font-weight:600; margin-bottom:1.25rem; color:var(--rm-text)">
                Host Architecture & Services
            </h3>
            <table>
                <tr>
                    <td style="color:var(--rm-text-muted); font-weight:600; width:150px">Uptime</td>
                    <td><span class="status-pill live" style="font-size:0.72rem"><span class="dot"></span> <span id="live_uptime"><?= htmlspecialchars($stats['uptime']) ?></span></span></td>
                </tr>
                <tr>
                    <td style="color:var(--rm-text-muted); font-weight:600">PHP FastCGI</td>
                    <td><span class="tag-pill" style="font-weight:700; color:var(--rm-cobalt)">PHP <?= htmlspecialchars($stats['php_version']) ?></span></td>
                </tr>
                <tr>
                    <td style="color:var(--rm-text-muted); font-weight:600">Web Server</td>
                    <td><span class="tag-pill" style="font-weight:700">Nginx <?= htmlspecialchars($stats['nginx_version']) ?></span></td>
                </tr>
                <tr>
                    <td style="color:var(--rm-text-muted); font-weight:600">Wildcard Tunnel</td>
                    <td><code>*.<?= htmlspecialchars($wildcard) ?></code></td>
                </tr>
                <tr>
                    <td style="color:var(--rm-text-muted); font-weight:600">Server Host</td>
                    <td>Armbian Linux (192.168.60.105)</td>
                </tr>
            </table>
        </div>

        <!-- Right: Recent Activity Stream (RoomMaster notification style) -->
        <div class="card">
            <h3 style="font-family:'Newsreader', Georgia, serif; font-size:1.35rem; font-weight:600; margin-bottom:1.25rem; color:var(--rm-text)">
                Deployment Activity
            </h3>
            <?php if (empty($logs)): ?>
                <div style="padding:2rem 0; text-align:center; color:var(--rm-text-muted)">
                    No recent activity recorded.
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:0.75rem;">
                    <?php foreach ($logs as $log): 
                        $isCreated = stripos($log['description'], 'created') !== false;
                        $isDeleted = stripos($log['description'], 'deleted') !== false;
                        $badgeClass = $isCreated ? 'live' : ($isDeleted ? 'warning' : 'tag-pill');
                    ?>
                        <div style="display:flex; align-items:center; justify-content:space-between; padding:0.65rem 0.9rem; background:var(--rm-card-subtle); border-radius:12px; border:1px solid var(--rm-border-light)">
                            <div style="display:flex; align-items:center; gap:0.75rem">
                                <span class="status-pill <?= $badgeClass ?>" style="padding:2px 8px; font-size:0.68rem">
                                    <?= $isCreated ? '● Created' : ($isDeleted ? '▲ Deleted' : '● Event') ?>
                                </span>
                                <span style="font-weight:600; font-size:0.85rem; color:var(--rm-text)"><?= htmlspecialchars($log['description']) ?></span>
                            </div>
                            <span style="font-size:0.72rem; color:var(--rm-text-muted); font-family:var(--font-mono)"><?= htmlspecialchars($log['created_at']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Live Telemetry Polling Script (Every 3 Seconds) -->
    <script>
    let cpuHistory = [<?= (float)$stats['load_1'] ?>, <?= (float)$stats['load_5'] ?>, <?= (float)$stats['load_15'] ?>];
    while (cpuHistory.length < 8) cpuHistory.unshift(<?= (float)$stats['load_1'] ?>);

    function updateSparkline() {
        const min = Math.min(...cpuHistory, 0);
        const max = Math.max(...cpuHistory, 0.8) * 1.2;
        const w = 80, h = 40;
        const len = cpuHistory.length;
        
        let points = cpuHistory.map((val, idx) => {
            const x = (idx / (len - 1)) * (w - 12) + 6;
            const y = h - ((val - min) / (max - min || 1)) * (h - 14) - 7;
            return [x, y];
        });
        
        let d = 'M ' + points[0][0].toFixed(1) + ' ' + points[0][1].toFixed(1);
        for (let i = 1; i < points.length; i++) {
            const prev = points[i-1];
            const curr = points[i];
            const mx = ((prev[0] + curr[0]) / 2).toFixed(1);
            d += ` C ${mx} ${prev[1].toFixed(1)}, ${mx} ${curr[1].toFixed(1)}, ${curr[0].toFixed(1)} ${curr[1].toFixed(1)}`;
        }
        
        const pathEl = document.getElementById('sparkline_path');
        const dotEl = document.getElementById('sparkline_dot');
        if (pathEl) pathEl.setAttribute('d', d);
        if (dotEl) {
            const last = points[points.length - 1];
            dotEl.setAttribute('cx', last[0].toFixed(1));
            dotEl.setAttribute('cy', last[1].toFixed(1));
        }
    }

    function fetchLiveStats() {
        apiCall('get_stats', {}, res => {
            if (!res.success || !res.stats) return;
            const s = res.stats;
            
            // 1. CPU Load
            const c1 = parseFloat(s.load_1).toFixed(2);
            const c5 = parseFloat(s.load_5).toFixed(2);
            const c15 = parseFloat(s.load_15).toFixed(2);
            document.getElementById('live_cpu_val').innerText = c1;
            document.getElementById('live_cpu_5m').innerText = '5m: ' + c5;
            document.getElementById('live_cpu_15m').innerText = '15m: ' + c15;
            
            cpuHistory.push(parseFloat(c1));
            if (cpuHistory.length > 10) cpuHistory.shift();
            updateSparkline();

            // 2. Disk
            const diskPct = parseFloat(s.disk_used_percent);
            const diskFreeGb = (s.disk_free / (1024 * 1024 * 1024)).toFixed(1);
            const diskTotalGb = (s.disk_total / (1024 * 1024 * 1024)).toFixed(1);
            document.getElementById('live_disk_pct').innerText = diskPct + '%';
            document.getElementById('live_disk_bar').style.width = Math.min(100, diskPct) + '%';
            document.getElementById('live_disk_sub').innerText = 'Free: ' + diskFreeGb + ' GB of ' + diskTotalGb + ' GB';

            // 3. RAM
            const memPct = parseFloat(s.mem_used_percent);
            const memFreeMb = Math.round(s.mem_available / (1024 * 1024));
            const memTotalMb = Math.round(s.mem_total / (1024 * 1024));
            document.getElementById('live_mem_pct').innerText = memPct + '%';
            document.getElementById('live_mem_bar').style.width = Math.min(100, memPct) + '%';
            document.getElementById('live_mem_sub').innerText = 'Free RAM: ' + memFreeMb + ' MB of ' + memTotalMb + ' MB';

            // 4. Uptime
            const upEl = document.getElementById('live_uptime');
            if (upEl && s.uptime) upEl.innerText = s.uptime;

            // Pulse heartbeat dot
            const dot = document.getElementById('live_pulse_dot');
            if (dot) {
                dot.style.transform = 'scale(1.4)';
                setTimeout(() => dot.style.transform = 'scale(1)', 250);
            }
        });
    }

    updateSparkline();
    setInterval(fetchLiveStats, 3000);
    </script>
    <?php
    echo renderFooter();
    exit;
}

if ($action === 'sites') {
    $sites = $vhost->getAllSites($currentUserId, $currentUserRole);
    $wildcard = $vhost->getSetting('wildcard_domain') ?: 'purujekuto.my.id';
    $maxSites = (int)$vhost->getSetting('max_sites');
    $totalSites = count($sites);
    $viewMode = $_GET['view'] ?? 'grid';
    
    echo renderHeader('Websites & Vhosts', $vhost);
    ?>
    <div class="page-header-rm">
        <div>
            <div class="page-eyebrow">
                <span class="dot"></span>
                <span>Websites &bull; <?= $totalSites ?> of <?= $maxSites ?> Deployed</span>
            </div>
            <h1 class="page-title">Websites & Virtual Hosts</h1>
            <p class="page-subtitle">Each site is provisioned with an isolated document root and wildcard SSL routing</p>
        </div>
        <div style="display:flex; gap:0.75rem; align-items:center">
            <div class="view-mode-capsule" style="background:var(--rm-card-subtle); border:1px solid var(--rm-border); padding:3px; border-radius:9999px; display:inline-flex; gap:3px">
                <a href="?action=sites&view=grid" class="view-pill-btn <?= $viewMode === 'grid' ? 'active' : '' ?>">Grid ◫</a>
                <a href="?action=sites&view=table" class="view-pill-btn <?= $viewMode === 'table' ? 'active' : '' ?>">Table ≡</a>
            </div>
            <button class="btn btn-primary" onclick="openModal('addSiteModal')">+ Deploy New Site</button>
        </div>
    </div>

    <?php if (empty($sites)): ?>
        <div class="card" style="text-align:center; padding:4rem 2rem;">
            <div style="font-size:2.5rem; margin-bottom:1rem">🌐</div>
            <h3 style="font-family:'Newsreader', Georgia, serif; font-size:1.6rem; font-weight:600; margin-bottom:0.5rem">No websites provisioned yet</h3>
            <p style="color:var(--rm-text-secondary); max-width:400px; margin:0 auto 1.5rem">Create your first website with automated Nginx vhost under *.<?= htmlspecialchars($wildcard) ?></p>
            <button class="btn btn-primary" onclick="openModal('addSiteModal')">+ Deploy First Site</button>
        </div>
    <?php elseif ($viewMode === 'table'): ?>
        <!-- Classic Clean Table View -->
        <div class="card" style="padding:0; overflow-x:auto; -webkit-overflow-scrolling:touch;">
            <table class="sites-table" style="width:100%; min-width:680px">
                <thead>
                    <tr>
                        <th style="padding:0.75rem 1rem">Domain</th>
                        <th style="padding:0.75rem 1rem; width:100px">Status</th>
                        <th style="padding:0.75rem 1rem; width:90px">PHP</th>
                        <th style="padding:0.75rem 1rem; width:130px">Created</th>
                        <th style="padding:0.75rem 1rem; text-align:right; white-space:nowrap; width:1%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $site): 
                        $fwMeta = getFrameworkBadgeMeta($site['framework'] ?? 'generic');
                    ?>
                    <tr>
                        <td style="padding:0.75rem 1rem">
                            <div style="display:flex; align-items:center; gap:0.5rem">
                                <a href="http://<?= htmlspecialchars($site['domain']) ?>" target="_blank" style="font-weight:700; font-size:0.95rem; color:var(--rm-cobalt); text-decoration:none; white-space:nowrap">
                                    <?= htmlspecialchars($site['domain']) ?> &nearr;
                                </a>
                                <span class="tag-pill" style="font-weight:700; font-size:0.7rem; color:<?= $fwMeta['color'] ?>; background:<?= $fwMeta['bg'] ?>; border:1px solid <?= $fwMeta['border'] ?>; white-space:nowrap">
                                    <?= $fwMeta['icon'] ?> <?= $fwMeta['name'] ?>
                                </span>
                            </div>
                            <div style="font-size:0.72rem; color:var(--rm-text-muted); font-family:var(--font-mono); margin-top:0.2rem; max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap" title="<?= htmlspecialchars($site['root_path']) ?>">
                                <span style="color:var(--rm-cobalt); font-weight:600">Vhost:</span> <?= htmlspecialchars($site['root_path']) ?><?= in_array($site['framework'] ?? '', ['laravel', 'ci4']) ? '/public' : '' ?>
                            </div>
                        </td>
                        <td style="padding:0.75rem 1rem; white-space:nowrap"><span class="status-pill <?= $site['status'] === 'active' ? 'live' : 'disabled' ?>"><span class="dot"></span> <?= ucfirst($site['status']) ?></span></td>
                        <td style="padding:0.75rem 1rem; white-space:nowrap"><span class="tag-pill">PHP <?= htmlspecialchars($site['php_version'] ?? '8.4') ?></span></td>
                        <td style="padding:0.75rem 1rem; font-size:0.75rem; color:var(--rm-text-muted); white-space:nowrap"><?= htmlspecialchars(substr($site['created_at'], 0, 10)) ?></td>
                        <td style="padding:0.75rem 1rem; text-align:right; white-space:nowrap; width:1%">
                            <div style="display:inline-flex; align-items:center; justify-content:flex-end; gap:0.4rem; flex-wrap:nowrap">
                                <a href="?action=files&site_id=<?= $site['id'] ?>" class="btn btn-sm btn-primary" style="white-space:nowrap !important">⚡ Open IDE</a>
                                <a href="?action=files&site_id=<?= $site['id'] ?>&view=table" class="btn btn-sm btn-white" style="white-space:nowrap !important">📁 Files</a>
                                <?php if (!empty($site['db_name'])): ?>
                                    <button class="btn btn-sm btn-white" title="MySQL & phpMyAdmin Access" onclick="showDbModal('<?= htmlspecialchars($site['domain']) ?>', '<?= htmlspecialchars($site['db_name']) ?>', '<?= htmlspecialchars($site['db_user']) ?>', '<?= htmlspecialchars($site['db_pass']) ?>')" style="white-space:nowrap !important">🔑 PMA</button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-white" onclick="toggleSite(<?= $site['id'] ?>)" title="Toggle"><?= $site['status'] === 'active' ? '⏸' : '▶' ?></button>
                                <button class="btn btn-sm btn-danger" onclick="deleteSite(<?= $site['id'] ?>, '<?= htmlspecialchars($site['domain']) ?>')" title="Delete">✕</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <!-- Pro Compact High-Density Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.75rem;">
            <?php foreach ($sites as $site): 
                $fwMeta = getFrameworkBadgeMeta($site['framework'] ?? 'generic');
            ?>
            <div class="card" style="border-radius:12px; padding:0.85rem 0.95rem; display:flex; flex-direction:column; justify-content:space-between; gap:0.5rem; transition:transform 0.15s, box-shadow 0.15s;">
                <div>
                    <!-- Top row: Icon + Domain + Status -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.3rem">
                        <div style="display:flex; align-items:center; gap:0.45rem; min-width:0">
                            <span style="font-size:1.15rem; line-height:1; flex-shrink:0"><?= $fwMeta['icon'] ?></span>
                            <a href="http://<?= htmlspecialchars($site['domain']) ?>" target="_blank" style="font-weight:700; font-size:0.92rem; color:var(--rm-text); text-decoration:none; display:flex; align-items:center; gap:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis">
                                <span><?= htmlspecialchars($site['domain']) ?></span>
                                <span style="color:var(--rm-cobalt); font-size:0.75rem; flex-shrink:0">&nearr;</span>
                            </a>
                        </div>
                        <span class="status-pill <?= $site['status'] === 'active' ? 'live' : 'disabled' ?>" style="padding:2px 7px; font-size:0.65rem; flex-shrink:0">
                            <span class="dot" style="width:4.5px; height:4.5px"></span> <?= $site['status'] === 'active' ? 'Live' : 'Off' ?>
                        </span>
                    </div>
                    
                    <!-- Root Path -->
                    <div style="font-size:0.65rem; color:var(--rm-text-muted); font-family:var(--font-mono); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:0.45rem" title="<?= htmlspecialchars($site['root_path']) ?>">
                        <?= htmlspecialchars($site['root_path']) ?><?= in_array($site['framework'] ?? '', ['laravel', 'ci4']) ? '/public' : '' ?>
                    </div>

                    <!-- Tech Badges -->
                    <div style="display:flex; flex-wrap:wrap; gap:0.25rem; align-items:center">
                        <span class="tag-pill" style="font-weight:700; color:<?= $fwMeta['color'] ?>; background:<?= $fwMeta['bg'] ?>; border:1px solid <?= $fwMeta['border'] ?>; padding:1.5px 6px; font-size:0.65rem">
                            <?= $fwMeta['name'] ?>
                        </span>
                        <span class="tag-pill" style="padding:1.5px 6px; font-size:0.65rem">PHP <?= htmlspecialchars($site['php_version'] ?? '8.4') ?></span>
                        <span class="tag-pill" style="padding:1.5px 6px; font-size:0.65rem">Vhost: <?= $fwMeta['vhost'] ?></span>
                        <?php if (!empty($site['db_name'])): ?>
                            <span class="tag-pill" style="color:var(--rm-cobalt); cursor:pointer; font-weight:700; padding:1.5px 6px; font-size:0.65rem" onclick="showDbModal('<?= htmlspecialchars($site['domain']) ?>', '<?= htmlspecialchars($site['db_name']) ?>', '<?= htmlspecialchars($site['db_user']) ?>', '<?= htmlspecialchars($site['db_pass']) ?>')">🗄️ <?= htmlspecialchars($site['db_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Bottom Actions & Meta in one clean line -->
                <div style="display:flex; align-items:center; justify-content:space-between; border-top:1px solid var(--rm-border-light); padding-top:0.5rem; gap:0.35rem; margin-top:0.15rem">
                    <span style="font-size:0.66rem; color:var(--rm-text-muted); font-family:var(--font-mono)"><?= htmlspecialchars(substr($site['created_at'], 0, 10)) ?> &bull; :80</span>
                    <div style="display:flex; gap:0.25rem; align-items:center">
                        <a href="?action=files&site_id=<?= $site['id'] ?>" class="btn btn-sm btn-primary" style="padding:0.22rem 0.5rem; font-size:0.68rem; border-radius:6px">⚡ IDE</a>
                        <a href="?action=files&site_id=<?= $site['id'] ?>&view=table" class="btn btn-sm btn-white" style="padding:0.22rem 0.5rem; font-size:0.68rem; border-radius:6px">📁 Files</a>
                        <?php if (!empty($site['db_name'])): ?>
                            <button type="button" class="btn btn-sm btn-white" title="Database & PMA" onclick="showDbModal('<?= htmlspecialchars($site['domain']) ?>', '<?= htmlspecialchars($site['db_name']) ?>', '<?= htmlspecialchars($site['db_user']) ?>', '<?= htmlspecialchars($site['db_pass']) ?>')" style="padding:0.22rem 0.5rem; font-size:0.68rem; border-radius:6px">🔑 PMA</button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-white" title="Toggle" onclick="toggleSite(<?= $site['id'] ?>)" style="padding:0.22rem 0.45rem; font-size:0.68rem; border-radius:6px"><?= $site['status'] === 'active' ? '⏸' : '▶' ?></button>
                        <button class="btn btn-sm btn-danger" title="Delete" onclick="deleteSite(<?= $site['id'] ?>, '<?= htmlspecialchars($site['domain']) ?>')" style="padding:0.22rem 0.45rem; font-size:0.68rem; border-radius:6px">🗑️</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Add Site Modal -->
    <!-- Add Site Modal -->
    
    <!-- MySQL Database & phpMyAdmin Info Modal -->
    <div id="dbInfoModal" class="modal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('dbInfoModal')">&times;</button>
            <div class="page-eyebrow" style="margin-bottom:0.4rem">
                <span class="dot"></span>
                <span>Database Provisioning</span>
            </div>
            <h3 class="modal-title" id="db_modal_title">MySQL &amp; phpMyAdmin</h3>
            <p class="modal-desc">
                Dedicated database instance running locally on STB MariaDB engine.
            </p>

            <div style="background:var(--rm-card-subtle); border:1px solid var(--rm-border); border-radius:16px; padding:1.25rem; margin-bottom:1.5rem">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.85rem; padding-bottom:0.85rem; border-bottom:1px solid var(--rm-border-light)">
                    <span style="font-size:0.8rem; color:var(--rm-text-secondary); font-weight:600">Database Name</span>
                    <div style="display:flex; align-items:center; gap:8px">
                        <code id="db_val_name" style="font-family:var(--font-mono); font-size:0.85rem; font-weight:700; color:var(--rm-text)"></code>
                        <button type="button" class="btn btn-sm btn-white" onclick="copyText('db_val_name')">📋</button>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.85rem; padding-bottom:0.85rem; border-bottom:1px solid var(--rm-border-light)">
                    <div>
                        <span style="font-size:0.8rem; color:var(--rm-text-secondary); font-weight:600; display:block">Username</span>
                        <span style="font-size:0.7rem; color:var(--rm-text-muted)">Bisa login pakai <code id="db_val_user_alt" style="font-family:var(--font-mono); font-size:0.75rem; color:var(--rm-cobalt)"></code></span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px">
                        <code id="db_val_user" style="font-family:var(--font-mono); font-size:0.85rem; font-weight:700; color:var(--rm-text)"></code>
                        <button type="button" class="btn btn-sm btn-white" onclick="copyText('db_val_user')">📋</button>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.85rem; padding-bottom:0.85rem; border-bottom:1px solid var(--rm-border-light)">
                    <span style="font-size:0.8rem; color:var(--rm-text-secondary); font-weight:600">Password</span>
                    <div style="display:flex; align-items:center; gap:8px">
                        <code id="db_val_pass" style="font-family:var(--font-mono); font-size:0.85rem; font-weight:700; color:var(--rm-cobalt)"></code>
                        <button type="button" class="btn btn-sm btn-white" onclick="copyText('db_val_pass')">📋</button>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center">
                    <span style="font-size:0.8rem; color:var(--rm-text-secondary); font-weight:600">Host / Port</span>
                    <code style="font-family:var(--font-mono); font-size:0.85rem; color:var(--rm-text)">localhost:3306</code>
                </div>
            </div>

            <div style="background:rgba(59,130,246,0.06); border:1px solid rgba(59,130,246,0.2); border-radius:12px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.78rem; color:var(--rm-text-secondary); display:flex; align-items:center; gap:10px">
                <span style="font-size:1.2rem">💡</span>
                <span>Gunakan <strong>1-Click Login SSO</strong> untuk langsung masuk ke database tanpa perlu mengetik ulang username &amp; password.</span>
            </div>

            <div class="modal-actions" style="display:flex; gap:10px; align-items:center">
                <button type="button" class="btn btn-white" onclick="closeModal('dbInfoModal')">Tutup</button>
                <a id="btn_manual_pma" href="http://pma.<?= htmlspecialchars($wildcard) ?>/" target="_blank" class="btn btn-white" style="text-decoration:none" title="Buka form login manual phpMyAdmin">
                    <span>Manual Login</span>
                </a>
                <button type="button" class="btn btn-cobalt" onclick="doPmaSso()" style="flex:1; justify-content:center; gap:6px; box-shadow:0 4px 12px rgba(37,99,235,0.2)">
                    <span>⚡ 1-Click Login SSO</span>
                    <span>&nearr;</span>
                </button>
            </div>
        </div>
    </div>

<div id="addSiteModal" class="modal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('addSiteModal')">&times;</button>
            <h3 class="modal-title">Deploy New Website</h3>
            <p class="modal-desc">
                Provision a website with an automated Nginx virtual host, isolated document root, and PHP-FPM pool.
            </p>

            <!-- Domain Type Switcher Pills (RoomMaster Style) -->
            <div class="modal-tab-container">
                <button type="button" id="tab_subdomain" onclick="switchDomainType('subdomain')" class="modal-tab-btn active">
                    ● Subdomain
                </button>
                <button type="button" id="tab_custom" onclick="switchDomainType('custom')" class="modal-tab-btn">
                    🌐 Custom Domain
                </button>
            </div>

            <form onsubmit="submitCreateSite(event)">
                <input type="hidden" id="domain_type" value="subdomain">
                
                <!-- Subdomain Input Group -->
                <div class="form-group" id="group_subdomain">
                    <label>Subdomain Name</label>
                    <div class="subdomain-input-wrap">
                        <input type="text" id="site_subdomain" placeholder="mysite" pattern="[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?" title="Huruf, angka, tanda minus">
                        <span class="subdomain-suffix">.<?= htmlspecialchars($wildcard) ?></span>
                    </div>
                    <div style="font-size:0.72rem; color:var(--rm-text-muted); margin-top:0.35rem">
                        ⚡ Otomatis online ke internet via Cloudflare Wildcard Tunnel.
                    </div>
                </div>

                <!-- Custom Domain Input Group -->
                <div class="form-group" id="group_custom" style="display:none">
                    <label>Domain Lengkap (Custom Domain)</label>
                    <input type="text" id="site_custom" placeholder="contoh.com atau web.sekolah.sch.id">
                    <div style="padding:0.75rem 1rem; background:var(--rm-card-subtle); border:1px solid var(--rm-border-light); border-radius:12px; font-size:0.75rem; color:var(--rm-text-secondary); margin-top:0.5rem">
                        💡 <strong>Petunjuk DNS:</strong> Buat CNAME record di DNS manager domain Anda mengarah ke <code><?= htmlspecialchars($wildcard) ?></code> atau tambahkan hostname di Cloudflare Tunnel STB.
                    </div>
                </div>

                <!-- Framework Selection (RoomMaster Interactive Picker) -->
                <div class="form-group">
                    <label style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.55rem">
                        <span>Pilih Framework &amp; Default Vhost</span>
                        <span style="font-size:0.75rem; color:var(--rm-cobalt); font-weight:700; font-family:var(--font-mono)" id="fw_docroot_indicator">Vhost: /public</span>
                    </label>
                    <input type="hidden" id="site_framework" value="laravel">

                    <div class="fw-grid">
                        <div class="fw-card active" onclick="selectFramework('laravel')" id="fw_opt_laravel">
                            <div class="fw-icon">🚀</div>
                            <div class="fw-name">Laravel</div>
                            <div class="fw-vhost">/public &bull; <span style="color:#10b981; font-weight:700">laravel.zip</span></div>
                        </div>
                        <div class="fw-card" onclick="selectFramework('ci4')" id="fw_opt_ci4">
                            <div class="fw-icon">🔥</div>
                            <div class="fw-name">CodeIgniter 4</div>
                            <div class="fw-vhost">/public &bull; <span style="color:#10b981; font-weight:700">ci.zip</span></div>
                        </div>
                        <div class="fw-card" onclick="selectFramework('wordpress')" id="fw_opt_wordpress">
                            <div class="fw-icon">🌐</div>
                            <div class="fw-name">WordPress</div>
                            <div class="fw-vhost">/public_html</div>
                        </div>
                        <div class="fw-card" onclick="selectFramework('generic')" id="fw_opt_generic">
                            <div class="fw-icon">🐘</div>
                            <div class="fw-name">PHP Native</div>
                            <div class="fw-vhost">/public_html</div>
                        </div>
                        <div class="fw-card" onclick="selectFramework('static')" id="fw_opt_static">
                            <div class="fw-icon">⚡</div>
                            <div class="fw-name">Static / SPA</div>
                            <div class="fw-vhost">/public_html</div>
                        </div>
                    </div>

                    <!-- Dynamic Framework Architectural Banner -->
                    <div id="fw_info_banner" style="padding:0.75rem 1rem; background:var(--rm-card-subtle); border:1px solid var(--rm-border); border-radius:14px; font-size:0.75rem; color:var(--rm-text-secondary); line-height:1.45">
                        <div style="font-weight:700; color:var(--rm-text); margin-bottom:0.25rem" id="fw_info_title">🚀 Laravel Starter Template (laravel.zip) &mdash; Vhost /public</div>
                        <div id="fw_info_desc">
                            Website otomatis diekstrak dari template <code>laravel.zip</code> lengkap dengan vendor autoloader, artisan CLI, konfigurasi <code>.env</code> MySQL, dan tampilan default welcome resmi Laravel. Vhost: <code>/public</code>.
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>PHP Version</label>
                    <select id="php_version">
                        <option value="8.4" selected>PHP 8.4 (Default FPM)</option>
                    </select>
                </div>

                <div class="form-group" style="background:var(--rm-card-subtle); border:1px solid var(--rm-border); padding:0.75rem 1rem; border-radius:14px">
                    <label style="display:flex; align-items:center; gap:0.6rem; cursor:pointer; margin:0; font-weight:600; font-size:0.85rem">
                        <input type="checkbox" id="site_create_db" value="1" checked style="width:auto; accent-color:var(--rm-cobalt)">
                        <span>Auto-create <strong>MySQL Database &amp; phpMyAdmin Login</strong></span>
                    </label>
                    <div style="font-size:0.72rem; color:var(--rm-text-muted); margin-top:0.35rem; padding-left:1.6rem">
                        🔑 Otomatis membuat database &amp; user terisolasi dan tombol 1-klik login ke phpMyAdmin.
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-white" onclick="closeModal('addSiteModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnDeploy">Deploy Website &rarr;</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function switchDomainType(type) {
        document.getElementById('domain_type').value = type;
        const groupSub = document.getElementById('group_subdomain');
        const groupCustom = document.getElementById('group_custom');
        const tabSub = document.getElementById('tab_subdomain');
        const tabCustom = document.getElementById('tab_custom');
        const subInput = document.getElementById('site_subdomain');
        const customInput = document.getElementById('site_custom');

        if (type === 'subdomain') {
            groupSub.style.display = 'block';
            groupCustom.style.display = 'none';
            tabSub.className = 'modal-tab-btn active';
            tabCustom.className = 'modal-tab-btn';
            subInput.required = true;
            customInput.required = false;
        } else {
            groupSub.style.display = 'none';
            groupCustom.style.display = 'block';
            tabCustom.className = 'modal-tab-btn active';
            tabSub.className = 'modal-tab-btn';
            subInput.required = false;
            customInput.required = true;
        }
    }

        let currentPmaUser = '';
    let currentPmaPass = '';

    function showDbModal(domain, dbName, dbUser, dbPass) {
        currentPmaUser = dbUser;
        currentPmaPass = dbPass;
        document.getElementById('db_modal_title').innerText = 'Database & phpMyAdmin: ' + domain;
        document.getElementById('db_val_name').innerText = dbName;
        document.getElementById('db_val_user').innerText = dbUser;
        
        let altUser = dbUser;
        if (dbUser.startsWith('u_')) {
            altUser = 'db_' + dbUser.substring(2);
        } else if (dbUser.startsWith('db_')) {
            altUser = 'u_' + dbUser.substring(3);
        }
        const altEl = document.getElementById('db_val_user_alt');
        if (altEl) altEl.innerText = altUser;
        
        document.getElementById('db_val_pass').innerText = dbPass;
        openModal('dbInfoModal');
    }

    function doPmaSso() {
        if (!currentPmaUser) {
            alert('Data database tidak ditemukan.');
            return;
        }
        const ssoUrl = '?action=pma_sso&user=' + encodeURIComponent(currentPmaUser) + '&pass=' + encodeURIComponent(currentPmaPass);
        window.open(ssoUrl, '_blank');
    }

    function copyText(elId) {
        const text = document.getElementById(elId).innerText;
        navigator.clipboard.writeText(text).then(() => {
            alert('Copied to clipboard: ' + text);
        });
    }
    
    const fwDetails = {
        laravel: {
            title: '🚀 Laravel Starter Template (laravel.zip) &mdash; Vhost /public',
            desc: 'Website otomatis diekstrak dari template <code>laravel.zip</code> lengkap dengan vendor autoloader, artisan CLI, konfigurasi <code>.env</code> MySQL, dan tampilan default welcome resmi Laravel. Vhost: <code>/public</code>.',
            indicator: 'Vhost: /public (laravel.zip)'
        },
        ci4: {
            title: '🔥 CodeIgniter 4 Starter Template (ci.zip) &mdash; Vhost /public',
            desc: 'Website otomatis diekstrak dari template <code>ci.zip</code> lengkap dengan vendor, spark CLI, konfigurasi <code>.env</code> MySQL, dan tampilan default welcome resmi CodeIgniter 4. Vhost: <code>/public</code>.',
            indicator: 'Vhost: /public (ci.zip)'
        },
        wordpress: {
            title: '🌐 WordPress Architecture (Vhost Document Root: /public_html)',
            desc: 'Nginx vhost melayani <code>/public_html</code> dengan permalinks rewrite <code>try_files $uri $uri/ /index.php?$args;</code>. Eksekusi PHP pada direktori uploads diblokir otomatis demi keamanan.',
            indicator: 'Vhost: /public_html'
        },
        generic: {
            title: '🐘 Generic / Native PHP Architecture (Vhost Document Root: /public_html)',
            desc: 'Standar FastCGI PHP server dengan <code>/public_html</code> root. Mendukung file <code>.user.ini</code> per-website, <code>robots.txt</code>, dan skrip PHP independen.',
            indicator: 'Vhost: /public_html'
        },
        static: {
            title: '⚡ Static / Single Page App (Vhost Document Root: /public_html)',
            desc: 'Cocok untuk web hasil build Vite, React, Vue, HTML/CSS. Nginx otomatis mem-fallback ke <code>index.html</code> untuk client-side routing dan kompresi gzip tinggi.',
            indicator: 'Vhost: /public_html'
        }
    };

    function selectFramework(fw) {
        document.getElementById('site_framework').value = fw;
        document.querySelectorAll('.fw-card').forEach(c => c.classList.remove('active'));
        const el = document.getElementById('fw_opt_' + fw);
        if (el) el.classList.add('active');

        const info = fwDetails[fw] || fwDetails.laravel;
        document.getElementById('fw_info_title').innerHTML = info.title;
        document.getElementById('fw_info_desc').innerHTML = info.desc;
        document.getElementById('fw_docroot_indicator').innerText = info.indicator;
    }

    function submitCreateSite(e) {
        e.preventDefault();
        const type = document.getElementById('domain_type').value;
        const sub = document.getElementById('site_subdomain').value.trim();
        const custom = document.getElementById('site_custom').value.trim();
        const php = document.getElementById('php_version').value;
        const framework = document.getElementById('site_framework').value;
        const createDb = document.getElementById('site_create_db') ? document.getElementById('site_create_db').checked : true;
        const btn = document.getElementById('btnDeploy');

        if (type === 'subdomain' && !sub) return;
        if (type === 'custom' && !custom) return;

        btn.disabled = true;
        btn.innerText = 'Provisioning...';
        apiCall('create_site', { domain_type: type, subdomain: sub, custom_domain: custom, php_version: php, framework: framework, create_db: createDb ? 1 : 0 }, res => {
            btn.disabled = false;
            btn.innerText = 'Deploy Website →';
            if (res.success) {
                showToast(res.message);
                setTimeout(() => location.reload(), 800);
            } else {
                alert('Error: ' + res.message);
            }
        });
    }
    function toggleSite(id) {
        if (!confirm('Toggle site status?')) return;
        apiCall('toggle_site', { id: id, site_id: id }, res => {
            if (res.success) location.reload();
            else alert(res.message);
        });
    }
    function deleteSite(id, domain) {
        if (!confirm('Are you sure you want to delete website ' + domain + '? This will remove Nginx vhost and web files!')) return;
        apiCall('delete_site', { id: id, site_id: id }, res => {
            if (res.success) {
                showToast(res.message);
                setTimeout(() => location.reload(), 800);
            } else {
                alert(res.message);
            }
        });
    }
    </script>
    <?php
    echo renderFooter();
    exit;
}

if ($action === 'files' || $action === 'code') {
    $siteId = (int)($_GET['site_id'] ?? 0);
    $site = $vhost->getSite($siteId);
    if (!$site) {
        header('Location: ?action=sites');
        exit;
    }
    if (($site['type'] ?? '') === 'proxy') {
        header('Location: ?action=proxy');
        exit;
    }
    $domain = $site['domain'];
    $rootPath = getSiteRoot($siteId, $vhost);
    $view = $_GET['view'] ?? 'ide';

    // If user explicitly asks for classic table view
    if ($view === 'table') {
        echo renderHeader('Files (Table) — ' . $domain, $vhost);
        ?>
        <div class="page-header-rm">
            <div>
                <div class="page-eyebrow">
                    <span class="dot"></span>
                    <span>Virtual Host Storage • Document Root</span>
                </div>
                <h1 class="page-title">File Manager & Storage</h1>
                <p class="page-subtitle"><?= htmlspecialchars($domain) ?> &bull; <code style="font-family:var(--font-mono);font-size:0.8rem"><?= htmlspecialchars($rootPath) ?></code></p>
            </div>
            <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap">
                <a href="?action=files&site_id=<?= $siteId ?>" class="btn btn-primary btn-sm">
                    <span>⚡</span> Open in PuruCode IDE
                </a>
                <button class="btn btn-white btn-sm" onclick="fmUploadFiles()" title="Pilih & upload satu atau beberapa file">⬆ Upload File</button>
                <button class="btn btn-white btn-sm" onclick="fmUploadFolder()" title="Pilih & upload seluruh isi folder beserta subdirektori">📁 Upload Folder</button>
                <button class="btn btn-white btn-sm" onclick="fmNewFolder()">➕ New Folder</button>
                <button class="btn btn-white btn-sm" onclick="fmNewFile()">📄 New File</button>
                <button class="btn btn-white btn-sm" onclick="fmRefresh()">🔄 Refresh</button>
                <input type="file" id="fm-upload-input" multiple style="display:none" onchange="fmHandleInputUpload(this, false)">
                <input type="file" id="fm-folder-input" webkitdirectory directory multiple style="display:none" onchange="fmHandleInputUpload(this, true)">
            </div>
        </div>

        <div class="bento-container" style="padding:1.5rem">
            <!-- Breadcrumbs Nav Capsule -->
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.25rem; padding:0.6rem 1.25rem; background:var(--rm-card); border-radius:var(--rm-radius-pill); border:1px solid var(--rm-border)">
                <div style="display:flex; align-items:center; gap:0.6rem; font-size:0.85rem">
                    <span style="color:var(--rm-cobalt); font-weight:700">Root Directory:</span>
                    <span id="fm-path" style="font-family:var(--font-mono); font-weight:600; color:var(--rm-text)">/</span>
                </div>
                <span style="font-size:0.75rem; color:var(--rm-text-muted)">Klik folder untuk menavigasi subdirektori</span>
            </div>

            <!-- Floating Table Card with Drag & Drop Zone -->
            <div class="card" id="fm-drop-card" style="padding:0; overflow:hidden; position:relative; min-height:280px">
                <!-- Visual Drag & Drop Overlay -->
                <div id="fm-drop-overlay" style="display:none; position:absolute; inset:0; z-index:50; background:rgba(10, 15, 29, 0.90); backdrop-filter:blur(8px); border:2.5px dashed var(--rm-cobalt, #2563eb); border-radius:inherit; align-items:center; justify-content:center; flex-direction:column; gap:12px; pointer-events:none; transition:all 0.2s">
                    <div style="width:68px; height:68px; border-radius:50%; background:rgba(37,99,235,0.2); border:1px solid rgba(59,130,246,0.3); display:flex; align-items:center; justify-content:center; font-size:2.2rem">
                        📥
                    </div>
                    <div style="font-weight:700; font-size:1.15rem; color:var(--rm-text, #fff)">Lepaskan File atau Folder di Sini</div>
                    <div style="font-size:0.85rem; color:var(--rm-text-muted, #94a3b8)">Upload otomatis mempertahankan hierarki direktori ke <span id="fm-drop-target-display" style="font-family:var(--font-mono); color:var(--rm-cobalt, #38bdf8); font-weight:700">/</span></div>
                </div>

                <table id="fm-table">
                    <thead>
                        <tr>
                            <th style="width:42%">Name</th>
                            <th>Size</th>
                            <th>Last Modified</th>
                            <th>Permissions</th>
                            <th style="text-align:right; padding-right:1.5rem">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="fm-body">
                        <tr><td colspan="5" style="text-align:center; padding:2.5rem; color:var(--rm-text-muted)">Memuat file dan direktori...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modern RoomMaster File Editor Modal -->
        <div class="modal" id="editModal">
            <div class="modal-content" style="max-width:850px">
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
                <div class="page-eyebrow" style="margin-bottom:0.4rem">
                    <span class="dot"></span>
                    <span>Quick File Editor</span>
                </div>
                <h3 class="modal-title" id="edit-title">Edit File</h3>
                <textarea id="edit-content" style="width:100%; height:420px; background:var(--rm-input-bg); color:var(--rm-text); border:1px solid var(--rm-border); border-radius:14px; padding:1rem; font-family:var(--font-mono); font-size:0.85rem; line-height:1.5; resize:vertical; outline:none"></textarea>
                <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.25rem">
                    <button class="btn btn-white btn-sm" onclick="closeModal('editModal')">Cancel</button>
                    <button class="btn btn-primary btn-sm" onclick="fmSave()">Save Changes</button>
                </div>
            </div>
        </div>

        <script>
        const SITE_ID = <?= $siteId ?>;
        let currentDir = '';

        function fmApi(action, data, cb) {
            const fd = new URLSearchParams();
            fd.append('site_id', SITE_ID);
            for (let k in data) fd.append(k, data[k]);
            fetch('?ajax=1&action=' + action, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd.toString() })
                .then(r => r.json()).then(res => {
                    if (!res.success) showToast(res.message || 'Error', 'error');
                    if (cb) cb(res);
                }).catch(() => showToast('Network error', 'error'));
        }

        function fmLoad(dir) {
            currentDir = dir || '';
            document.getElementById('fm-path').textContent = currentDir ? '/' + currentDir : '/';
            fmApi('fm_list', {dir: currentDir}, function(res) {
                if (!res.success) return;
                let html = '';
                if (res.parent !== null && res.parent !== false) {
                    html += '<tr><td colspan="5"><a href="#" onclick="fmLoad(\'' + res.parent.replace(/'/g, "\\'") + '\');return false" style="color:var(--accent);text-decoration:none">📁 ..</a></td></tr>';
                }
                if (res.items && res.items.length > 0) {
                    res.items.forEach(function(item) {
                        const icon = item.type === 'dir' ? '📁' : '📄';
                        const size = item.type === 'dir' ? '-' : formatSize(item.size);
                        const click = item.type === 'dir' ? 'onclick="fmLoad(\'' + item.path.replace(/'/g, "\\'") + '\')"' : '';
                        html += '<tr>';
                        html += '<td><a href="#" ' + click + ' style="color:var(--rm-text);font-weight:600;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px">' + icon + ' ' + escHtml(item.name) + '</a></td>';
                        html += '<td style="font-size:0.8rem;color:var(--rm-text-muted)">' + size + '</td>';
                        html += '<td style="font-size:0.8rem;color:var(--rm-text-muted)">' + item.modified + '</td>';
                        html += '<td><span class="tag-pill">' + item.perms + '</span></td>';
                        html += '<td style="text-align:right"><div style="display:inline-flex;align-items:center;gap:0.4rem">';
                        if (item.type === 'file') {
                            html += '<a href="?action=files&site_id=' + SITE_ID + '&open=' + encodeURIComponent(item.path) + '" class="btn btn-white btn-sm" style="padding:2px 8px; font-size:0.72rem" title="Open in PuruCode IDE">⚡ IDE</a>';
                            html += '<button class="btn btn-white btn-sm" style="padding:2px 8px; font-size:0.72rem" onclick="fmEdit(\'' + item.path.replace(/'/g, "\\'") + '\')" title="Edit">✏️</button>';
                        }
                        html += '<button class="btn btn-white btn-sm" style="padding:2px 8px; font-size:0.72rem" onclick="fmRenamePrompt(\'' + item.path.replace(/'/g, "\\'") + '\',\'' + escHtml(item.name).replace(/'/g, "\\'") + '\')" title="Rename">🔤</button>';
                        html += '<button class="btn btn-danger btn-sm" style="padding:2px 8px; font-size:0.72rem" onclick="fmDeleteConfirm(\'' + item.path.replace(/'/g, "\\'") + '\',\'' + escHtml(item.name).replace(/'/g, "\\'") + '\')" title="Delete">🗑</button>';
                        html += '</div></td>';
                        html += '</tr>';
                    });
                } else {
                    html += '<tr><td colspan="5" style="text-align:center;color:var(--rm-text-muted);padding:2rem">Folder kosong</td></tr>';
                }
                document.getElementById('fm-body').innerHTML = html;
            });
        }

        function fmRefresh() { fmLoad(currentDir); }

        // Recursive directory scanning from DataTransfer
        async function scanDataTransfer(dataTransfer) {
            const fileEntries = [];
            const dirEntries = [];
            const items = dataTransfer.items;
            
            if (items && items.length > 0 && (items[0].webkitGetAsEntry || items[0].getAsEntry)) {
                async function readEntriesAsync(reader) {
                    let all = [];
                    while (true) {
                        const batch = await new Promise(resolve => {
                            reader.readEntries(entries => resolve(entries || []), () => resolve([]));
                        });
                        if (!batch || batch.length === 0) break;
                        all.push(...batch);
                    }
                    return all;
                }

                async function traverse(entry, path = '') {
                    if (entry.isFile) {
                        return new Promise(resolve => {
                            entry.file(f => {
                                resolve([{ file: f, path: path + f.name }]);
                            }, () => resolve([]));
                        });
                    } else if (entry.isDirectory) {
                        const currentDirPath = path + entry.name;
                        dirEntries.push(currentDirPath);
                        const reader = entry.createReader();
                        const entries = await readEntriesAsync(reader);
                        const results = [];
                        for (const child of entries) {
                            const sub = await traverse(child, currentDirPath + '/');
                            results.push(...sub);
                        }
                        return results;
                    }
                    return [];
                }

                const promises = [];
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    const entry = (item.webkitGetAsEntry ? item.webkitGetAsEntry() : (item.getAsEntry ? item.getAsEntry() : null));
                    if (entry) {
                        promises.push(traverse(entry, ''));
                    } else if (item.kind === 'file') {
                        const f = item.getAsFile();
                        if (f) promises.push(Promise.resolve([{ file: f, path: f.name }]));
                    }
                }
                const nested = await Promise.all(promises);
                nested.forEach(arr => fileEntries.push(...arr));
            } else if (dataTransfer.files && dataTransfer.files.length > 0) {
                for (let i = 0; i < dataTransfer.files.length; i++) {
                    const f = dataTransfer.files[i];
                    const rel = f.webkitRelativePath || f.name;
                    fileEntries.push({ file: f, path: rel });
                    const slashIdx = rel.lastIndexOf('/');
                    if (slashIdx !== -1) dirEntries.push(rel.substring(0, slashIdx));
                }
            }
            return { files: fileEntries, dirs: dirEntries };
        }

        // Batched upload with progress toast (adaptive chunking by count and total bytes)
        async function batchUploadEntries(uploadPayload, targetDir, onDone) {
            const files = Array.isArray(uploadPayload) ? uploadPayload : (uploadPayload.files || []);
            const dirs = uploadPayload.dirs || [];

            if (files.length === 0 && dirs.length === 0) {
                showToast('Tidak ada file atau folder yang dipilih', 'error');
                return;
            }

            const totalFiles = files.length;
            const batches = [];
            let curBatch = [];
            let curBatchBytes = 0;
            const MAX_FILES = 25;
            const MAX_BYTES = 25 * 1024 * 1024; // 25 MB max per batch

            for (const item of files) {
                const sz = (item.file && item.file.size) ? item.file.size : 0;
                if (curBatch.length >= MAX_FILES || (curBatchBytes + sz > MAX_BYTES && curBatch.length > 0)) {
                    batches.push(curBatch);
                    curBatch = [];
                    curBatchBytes = 0;
                }
                curBatch.push(item);
                curBatchBytes += sz;
            }
            if (curBatch.length > 0) batches.push(curBatch);
            if (batches.length === 0 && dirs.length > 0) batches.push([]);

            let uploadedCount = 0;
            showToast(`Memulai upload (${totalFiles} file dalam ${batches.length} batch)...`, 'info');

            for (let b = 0; b < batches.length; b++) {
                const chunk = batches[b];
                const fd = new FormData();
                fd.append('site_id', SITE_ID);
                fd.append('dir', targetDir || '');
                fd.append('ajax', '1');
                fd.append('action', 'fm_upload');

                if (b === 0 && dirs.length > 0) {
                    dirs.forEach(d => fd.append('dirs[]', d));
                }

                chunk.forEach(item => {
                    fd.append('files[]', item.file);
                    fd.append('paths[]', item.path);
                });

                try {
                    const res = await fetch('?ajax=1&action=fm_upload', { method: 'POST', body: fd });
                    if (!res.ok) {
                        const errText = await res.text();
                        throw new Error(`HTTP ${res.status}: ${errText.slice(0, 140)}`);
                    }
                    const json = await res.json();
                    if (!json.success) {
                        showToast(`Batch ${b + 1}/${batches.length} gagal: ` + (json.message || 'Error'), 'error');
                        return;
                    }
                    uploadedCount += chunk.length;
                    showToast(`Mengupload: ${uploadedCount} / ${totalFiles} file (Batch ${b + 1}/${batches.length})...`, 'info');
                } catch (err) {
                    showToast('Upload error: ' + err.message, 'error');
                    return;
                }
            }

            showToast(`Sukses upload ${uploadedCount} file & folder! 🎉`, 'success');
            if (typeof onDone === 'function') onDone();
        }

        function fmUploadFiles() {
            document.getElementById('fm-upload-input').click();
        }
        function fmUploadFolder() {
            document.getElementById('fm-folder-input').click();
        }
        function fmHandleInputUpload(input, isFolder) {
            if (!input.files || input.files.length === 0) return;
            const entries = [];
            const dirSet = new Set();
            for (let i = 0; i < input.files.length; i++) {
                const f = input.files[i];
                const rel = f.webkitRelativePath || f.name;
                entries.push({
                    file: f,
                    path: rel
                });
                const slashIdx = rel.lastIndexOf('/');
                if (slashIdx !== -1) {
                    dirSet.add(rel.substring(0, slashIdx));
                }
            }
            batchUploadEntries({ files: entries, dirs: Array.from(dirSet) }, currentDir, () => fmRefresh());
            input.value = '';
        }

        // Drag & Drop Listeners for File Manager
        let fmDragCounter = 0;
        window.addEventListener('dragenter', function(e) {
            if (e.dataTransfer && e.dataTransfer.types && Array.from(e.dataTransfer.types).includes('Files')) {
                fmDragCounter++;
                const disp = document.getElementById('fm-drop-target-display');
                if (disp) disp.textContent = currentDir ? '/' + currentDir : '/';
                const ov = document.getElementById('fm-drop-overlay');
                if (ov) ov.style.display = 'flex';
            }
        });
        window.addEventListener('dragover', function(e) {
            if (e.dataTransfer && e.dataTransfer.types && Array.from(e.dataTransfer.types).includes('Files')) {
                e.preventDefault();
            }
        });
        window.addEventListener('dragleave', function(e) {
            fmDragCounter--;
            if (fmDragCounter <= 0) {
                fmDragCounter = 0;
                const ov = document.getElementById('fm-drop-overlay');
                if (ov) ov.style.display = 'none';
            }
        });
        window.addEventListener('drop', async function(e) {
            e.preventDefault();
            fmDragCounter = 0;
            const ov = document.getElementById('fm-drop-overlay');
            if (ov) ov.style.display = 'none';
            if (!e.dataTransfer) return;
            const payload = await scanDataTransfer(e.dataTransfer);
            if (payload.files.length > 0 || payload.dirs.length > 0) {
                batchUploadEntries(payload, currentDir, () => fmRefresh());
            }
        });
        function fmNewFolder() {
            const name = prompt('Folder name:');
            if (!name) return;
            fmApi('fm_mkdir', {dir: currentDir, name: name}, function(res) { if (res.success) fmRefresh(); });
        }
        function fmNewFile() {
            const name = prompt('File name:');
            if (!name) return;
            fmApi('fm_save', {path: currentDir ? currentDir + '/' + name : name, content: ''}, function(res) {
                if (res.success) { fmRefresh(); fmEdit(currentDir ? currentDir + '/' + name : name); }
            });
        }
        function fmEdit(path) {
            fmApi('fm_read', {path: path}, function(res) {
                if (!res.success) return;
                document.getElementById('edit-title').textContent = 'Edit: ' + res.name;
                document.getElementById('edit-content').value = res.content;
                document.getElementById('edit-content').dataset.path = path;
                openModal('editModal');
            });
        }
        function fmSave() {
            const path = document.getElementById('edit-content').dataset.path;
            const content = document.getElementById('edit-content').value;
            fmApi('fm_save', {path: path, content: content}, function(res) {
                if (res.success) { closeModal('editModal'); fmRefresh(); }
            });
        }
        function fmRenamePrompt(path, name) {
            const newName = prompt('Rename to:', name);
            if (!newName || newName === name) return;
            const newPath = path.substring(0, path.lastIndexOf('/') + 1) + newName;
            fmApi('fm_rename', {old: path, new: newPath}, function(res) { if (res.success) fmRefresh(); });
        }
        function fmDeleteConfirm(path, name) {
            if (confirm('Delete ' + name + '?')) {
                fmApi('fm_delete', {path: path}, function(res) { if (res.success) fmRefresh(); });
            }
        }
        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }
        function escHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        fmLoad('');
        </script>
        <?php
        echo renderFooter();
        exit;
    }

    // ============================================
    // PURUCODE WEB IDE (FULL-SCREEN VS CODE-LIKE)
    // ============================================
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚡ PuruCode — <?= htmlspecialchars($domain) ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=stb">
    <link rel="alternate icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=stb">
    <link rel="alternate icon" type="image/x-icon" href="/favicon.ico?v=stb">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=stb">
    <script>
    (function() {
        const t = localStorage.getItem('puru_theme') || 'dark';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,500;0,6..72,600;1,6..72,400&family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root, [data-theme="dark"] {
            /* RoomMaster Hitam Pekat / Doff Theme for PuruCode */
            --ide-canvas: #000000;         /* True pitch black */
            --ide-bg: #080808;             /* Pitch matte bg */
            --ide-surface: #0D0D0D;        /* Matte dark surface */
            --ide-surface2: #141414;       /* Matte charcoal surface */
            --ide-activity: #050505;       /* Deepest matte activity bar */
            --ide-tabs: #080808;           /* Pitch tab strip */
            --ide-tab-active: #141414;     /* Crisp matte tab */
            --ide-border: rgba(255, 255, 255, 0.09);
            --ide-border-light: rgba(255, 255, 255, 0.04);
            
            --ide-cobalt: #3B82F6;
            --ide-cobalt-hover: #60A5FA;
            --ide-cobalt-glow: 0 4px 18px rgba(59, 130, 246, 0.45);
            --ide-accent: #3B82F6;
            
            --ide-text: #FFFFFF;
            --ide-text2: #A1A1AA;
            --ide-muted: #71717A;
            --ide-green: #10B981;
            --ide-yellow: #FBBF24;
            --ide-red: #F87171;
            --ide-purple: #A78BFA;
            --ide-status: #050505;
            
            --ide-radius-pill: 9999px;
            --ide-radius-card: 14px;
            --ide-font-mono: 'JetBrains Mono', Consolas, Monaco, monospace;
        }

        [data-theme="light"] {
            /* RoomMaster Warm Linen Studio for PuruCode */
            --ide-canvas: #F4F2EC;
            --ide-bg: #ECEAE4;
            --ide-surface: #F4F2EC;
            --ide-surface2: #FFFFFF;
            --ide-activity: #E2DFD6;
            --ide-tabs: #E9E6DE;
            --ide-tab-active: #FFFFFF;
            --ide-border: rgba(24, 25, 28, 0.08);
            --ide-border-light: rgba(24, 25, 28, 0.05);
            
            --ide-cobalt: #0055FF;
            --ide-cobalt-hover: #0044CC;
            --ide-cobalt-glow: 0 4px 16px rgba(0, 85, 255, 0.25);
            --ide-accent: #0055FF;
            
            --ide-text: #141518;
            --ide-text2: #585A62;
            --ide-muted: #878A94;
            --ide-green: #027A48;
            --ide-yellow: #B54708;
            --ide-red: #B42318;
            --ide-purple: #7C3AED;
            --ide-status: #ECEAE4;
            
            --ide-radius-pill: 9999px;
            --ide-radius-card: 14px;
            --ide-font-mono: 'JetBrains Mono', Consolas, Monaco, monospace;
        }

        /* Blue indicator pulse / blink animation */
        @keyframes rm-blue-pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(0, 85, 255, 0.85);
                opacity: 1;
                transform: scale(1);
            }
            40% {
                box-shadow: 0 0 0 7px rgba(0, 85, 255, 0);
                opacity: 0.35;
                transform: scale(1.22);
            }
            70% {
                opacity: 1;
                transform: scale(0.95);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(0, 85, 255, 0);
                opacity: 1;
                transform: scale(1);
            }
        }
        [data-theme="dark"] @keyframes rm-blue-pulse {
            0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.85); opacity: 1; transform: scale(1); }
            40% { box-shadow: 0 0 0 7px rgba(59, 130, 246, 0); opacity: 0.35; transform: scale(1.22); }
            70% { opacity: 1; transform: scale(0.95); }
            100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); opacity: 1; transform: scale(1); }
        }
        .site-badge .dot, .blue-pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--ide-cobalt);
            animation: rm-blue-pulse 1.8s infinite cubic-bezier(0.4, 0, 0.6, 1);
            display: inline-block;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--ide-bg);
            color: var(--ide-text);
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            user-select: none;
        }

        /* App Container */
        #purucode-app {
            display: flex;
            flex-direction: column;
            width: 100vw;
            height: 100vh;
        }

        /* RoomMaster Studio Titlebar */
        #ide-titlebar {
            height: 44px;
            background: var(--ide-activity);
            border-bottom: 1px solid var(--ide-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            flex-shrink: 0;
            z-index: 10;
        }
        .title-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--ide-text);
            text-decoration: none;
        }
        .brand-badge .brand-icon {
            width: 24px;
            height: 24px;
            background: var(--ide-cobalt);
            color: #fff;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            box-shadow: 0 2px 8px rgba(0, 85, 255, 0.3);
        }
        .brand-badge .brand-title {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.015em;
        }
        .brand-badge .brand-icon svg {
            width: 14px;
            height: 14px;
            fill: currentColor;
        }
        .site-badge {
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--ide-surface2);
            color: var(--ide-text2);
            padding: 4px 12px;
            border-radius: var(--ide-radius-pill);
            border: 1px solid var(--ide-border);
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .breadcrumbs {
            font-size: 0.75rem;
            color: var(--ide-text2);
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            background: var(--ide-surface);
            border-radius: var(--ide-radius-pill);
            border: 1px solid var(--ide-border-light);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 380px;
        }
        .breadcrumbs span.sep { color: var(--ide-muted); }
        .breadcrumbs span.current { color: var(--ide-text); font-weight: 600; }

        .title-actions {
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .title-btn {
            background: var(--ide-surface2);
            border: 1px solid var(--ide-border);
            color: var(--ide-text);
            padding: 5px 12px;
            border-radius: var(--ide-radius-pill);
            font-size: 0.76rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
            text-decoration: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .title-btn:hover {
            background: var(--ide-surface);
            transform: translateY(-1px);
            border-color: var(--ide-cobalt);
            color: var(--ide-text);
        }
        .title-btn.btn-save {
            background: var(--ide-cobalt) !important;
            color: #FFFFFF !important;
            border-color: transparent !important;
            box-shadow: var(--ide-cobalt-glow) !important;
        }
        .title-btn.btn-save:hover {
            background: var(--ide-cobalt-hover) !important;
            transform: translateY(-1px);
        }
        .title-btn.btn-theme-toggle-ide {
            background: var(--ide-surface2);
            border-color: var(--ide-border);
        }

        /* Main Body */
        #ide-main {
            display: flex;
            flex: 1;
            overflow: hidden;
            position: relative;
        }

        /* Activity Bar */
        #ide-activity {
            width: 48px;
            background: var(--ide-activity);
            border-right: 1px solid var(--ide-border);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 0;
            flex-shrink: 0;
            gap: 12px;
            z-index: 5;
        }
        .act-btn {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            border: none;
            background: transparent;
            color: var(--ide-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            position: relative;
            transition: all 0.15s;
        }
        .act-btn:hover {
            color: var(--ide-text);
            background: rgba(255,255,255,0.05);
        }
        .act-btn.active {
            color: var(--ide-accent);
        }
        .act-btn.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 8px;
            bottom: 8px;
            width: 3px;
            background: var(--ide-accent);
            border-radius: 0 2px 2px 0;
        }
        .act-spacer { flex: 1; }

        /* Sidebar */
        #ide-sidebar {
            width: 260px;
            min-width: 180px;
            max-width: 500px;
            background: var(--ide-surface);
            border-right: 1px solid var(--ide-border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            overflow: hidden;
            position: relative;
        }
        .sidebar-header {
            padding: 10px 12px 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: var(--ide-text2);
            text-transform: uppercase;
        }
        .sidebar-tools {
            display: flex;
            gap: 4px;
        }
        .tool-icon-btn {
            background: transparent;
            border: none;
            color: var(--ide-text2);
            width: 24px;
            height: 24px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            transition: all 0.15s;
        }
        .tool-icon-btn:hover {
            background: var(--ide-surface2);
            color: #fff;
        }

        .sidebar-filter {
            padding: 4px 12px 8px;
        }
        .sidebar-filter input {
            width: 100%;
            background: var(--ide-activity);
            border: 1px solid var(--ide-border);
            color: var(--ide-text);
            padding: 5px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            outline: none;
        }
        .sidebar-filter input:focus {
            border-color: var(--ide-accent);
        }

        /* File Tree */
        #file-tree {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 4px 0 20px;
        }
        .tree-item {
            display: flex;
            align-items: center;
            padding: 4px 8px 4px 16px;
            cursor: pointer;
            font-size: 0.8rem;
            color: var(--ide-text);
            transition: background 0.1s;
            position: relative;
            white-space: nowrap;
        }
        .tree-item:hover {
            background: var(--ide-surface2);
            border-radius: 8px;
        }
        .tree-item.selected {
            background: var(--ide-surface2);
            border-left: 2px solid var(--ide-cobalt);
            color: var(--ide-text);
            border-radius: 4px 8px 8px 4px;
            font-weight: 600;
        }
        .tree-arrow {
            width: 16px;
            font-size: 0.65rem;
            color: var(--ide-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s;
        }
        .tree-arrow.expanded {
            transform: rotate(90deg);
        }
        .tree-icon {
            margin-right: 6px;
            display: inline-flex;
            align-items: center;
            font-size: 0.85rem;
        }
        .tree-label {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .tree-actions-hover {
            display: none;
            gap: 2px;
        }
        .tree-item:hover .tree-actions-hover {
            display: flex;
        }
        .tree-mini-btn {
            background: transparent;
            border: none;
            color: var(--ide-text2);
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 0.7rem;
            cursor: pointer;
        }
        .tree-mini-btn:hover {
            background: var(--ide-surface2);
            color: #fff;
        }

        /* File badges */
        .badge-php { color: #c084fc; font-weight: 700; font-size: 0.7rem; margin-right: 4px; }
        .badge-js { color: #facc15; font-weight: 700; font-size: 0.7rem; margin-right: 4px; }
        .badge-css { color: #38bdf8; font-weight: 700; font-size: 0.7rem; margin-right: 4px; }
        .badge-html { color: #fb923c; font-weight: 700; font-size: 0.7rem; margin-right: 4px; }
        .badge-json { color: #a3e635; font-weight: 700; font-size: 0.7rem; margin-right: 4px; }
        .badge-sql { color: #2dd4bf; font-weight: 700; font-size: 0.7rem; margin-right: 4px; }
        .badge-img { color: #f472b6; font-size: 0.8rem; margin-right: 4px; }

        /* Search Pane */
        #pane-search {
            display: none;
            flex-direction: column;
            flex: 1;
            padding: 10px 12px;
            overflow: hidden;
        }
        .search-box-row {
            display: flex;
            gap: 6px;
            margin-bottom: 10px;
        }
        .search-box-row input {
            flex: 1;
            background: var(--ide-activity);
            border: 1px solid var(--ide-border);
            color: var(--ide-text);
            padding: 6px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            outline: none;
        }
        .search-box-row input:focus {
            border-color: var(--ide-accent);
        }
        .search-box-row button {
            background: var(--ide-surface2);
            border: 1px solid var(--ide-border);
            color: var(--ide-text);
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
        }
        .search-box-row button:hover {
            border-color: var(--ide-accent);
            color: var(--ide-accent);
        }
        #search-results-list {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .search-hit-item {
            padding: 6px 8px;
            background: var(--ide-activity);
            border: 1px solid var(--ide-border);
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.15s;
        }
        .search-hit-item:hover {
            border-color: var(--ide-accent);
            background: var(--ide-surface2);
        }
        .search-hit-file {
            font-weight: 600;
            color: var(--ide-accent);
            margin-bottom: 2px;
            display: flex;
            justify-content: space-between;
        }
        .search-hit-line {
            color: var(--ide-muted);
            font-family: var(--ide-font-mono);
        }
        .search-hit-text {
            color: var(--ide-text2);
            font-family: var(--ide-font-mono);
            white-space: pre;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Resizers */
        .resizer-v {
            width: 4px;
            background: transparent;
            cursor: col-resize;
            transition: background 0.15s;
            z-index: 10;
        }
        .resizer-v:hover, .resizer-v.dragging {
            background: var(--ide-accent);
        }
        .resizer-h {
            height: 4px;
            background: transparent;
            cursor: row-resize;
            transition: background 0.15s;
            z-index: 10;
        }
        .resizer-h:hover, .resizer-h.dragging {
            background: var(--ide-accent);
        }

        /* Editor Area */
        #ide-editor-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: #1e1e2e;
            position: relative;
        }

        /* Tab Bar */
        #ide-tabbar {
            height: 38px;
            background: var(--ide-tabs);
            border-bottom: 1px solid var(--ide-border);
            display: flex;
            align-items: flex-end;
            overflow-x: auto;
            flex-shrink: 0;
            padding: 0 6px;
            gap: 4px;
        }
        #ide-tabbar::-webkit-scrollbar { height: 3px; }
        #ide-tabbar::-webkit-scrollbar-thumb { background: var(--ide-border); }

        .editor-tab {
            height: 34px;
            padding: 0 14px;
            background: var(--ide-tabs);
            color: var(--ide-text2);
            font-size: 0.78rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border-radius: 8px 8px 0 0;
            border: 1px solid transparent;
            border-bottom: none;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s ease;
            position: relative;
        }
        .editor-tab:hover {
            background: var(--ide-surface);
            color: var(--ide-text);
        }
        .editor-tab.active {
            background: var(--ide-tab-active);
            color: var(--ide-text);
            border-color: var(--ide-border);
            box-shadow: 0 -2px 10px rgba(0,0,0,0.04);
            font-weight: 600;
        }
        .editor-tab.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 10px;
            right: 10px;
            height: 2px;
            background: var(--ide-cobalt);
            border-radius: 2px;
        }
        .tab-dirty-dot {
            width: 7px;
            height: 7px;
            background: var(--ide-accent);
            border-radius: 50%;
            display: none;
        }
        .editor-tab.dirty .tab-dirty-dot {
            display: block;
        }
        .editor-tab.dirty:hover .tab-dirty-dot {
            display: none;
        }
        .tab-close {
            width: 18px;
            height: 18px;
            border-radius: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            color: var(--ide-muted);
            transition: all 0.15s;
        }
        .tab-close:hover {
            background: rgba(255,255,255,0.15);
            color: #fff;
        }
        .editor-tab.dirty:hover .tab-close {
            display: flex;
        }

        /* Editor Viewport */
        #editor-viewport {
            flex: 1;
            position: relative;
            overflow: hidden;
            background: #1e1e2e;
        }
        #monaco-container {
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
        }

        /* Welcome screen */
        #welcome-screen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--ide-surface);
            color: var(--ide-text2);
            z-index: 2;
        }
        .welcome-brand {
            font-size: 2.2rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 0.5rem;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .welcome-brand span.spark {
            color: var(--ide-accent);
            text-shadow: 0 0 20px rgba(56,189,248,0.7);
        }
        .welcome-desc {
            font-size: 0.9rem;
            color: var(--ide-muted);
            margin-bottom: 2rem;
        }
        .welcome-shortcuts {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px 24px;
            background: var(--ide-activity);
            padding: 1.5rem 2rem;
            border-radius: 8px;
            border: 1px solid var(--ide-border);
        }
        .shortcut-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            font-size: 0.8rem;
        }
        .shortcut-item kbd {
            background: var(--ide-surface2);
            border: 1px solid var(--ide-border);
            padding: 3px 8px;
            border-radius: 4px;
            font-family: var(--ide-font-mono);
            font-size: 0.72rem;
            color: var(--ide-accent);
        }

        /* Image preview */
        #image-preview-pane {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #11111b;
            z-index: 3;
            padding: 20px;
        }
        #image-preview-img {
            max-width: 90%;
            max-height: 80%;
            object-fit: contain;
            border-radius: 6px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            background: repeating-conic-gradient(#181825 0% 25%, #1e1e2e 0% 50%) 50% / 20px 20px;
        }
        .image-meta {
            margin-top: 15px;
            font-size: 0.8rem;
            color: var(--ide-text2);
            display: flex;
            gap: 15px;
        }

        /* Bottom Panel */
        #bottom-panel {
            height: 220px;
            background: var(--ide-activity);
            border-top: 1px solid var(--ide-border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            overflow: hidden;
            position: relative;
        }
        .panel-header {
            height: 32px;
            background: var(--ide-activity);
            border-bottom: 1px solid var(--ide-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 10px;
            flex-shrink: 0;
        }
        .panel-tabs {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .panel-tab {
            color: var(--ide-muted);
            cursor: pointer;
            padding: 4px 6px;
            border-bottom: 2px solid transparent;
            transition: all 0.15s;
        }
        .panel-tab:hover { color: var(--ide-text); }
        .panel-tab.active {
            color: var(--ide-accent);
            border-bottom-color: var(--ide-accent);
        }
        .panel-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .panel-btn {
            background: transparent;
            border: none;
            color: var(--ide-text2);
            cursor: pointer;
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .panel-btn:hover { background: var(--ide-surface2); color: #fff; }

        .panel-content {
            flex: 1;
            overflow: hidden;
            position: relative;
        }
        .panel-pane {
            width: 100%;
            height: 100%;
            display: none;
            flex-direction: column;
        }
        .panel-pane.active { display: flex; }

        /* Terminal View */
        #term-history {
            flex: 1;
            overflow-y: auto;
            padding: 8px 12px;
            font-family: var(--ide-font-mono);
            font-size: 0.8rem;
            color: #d1d5db;
            line-height: 1.5;
        }
        .term-line-cmd {
            color: var(--ide-green);
            margin-top: 4px;
        }
        .term-line-out {
            color: #9ca3af;
            white-space: pre-wrap;
            word-break: break-all;
            margin-bottom: 6px;
        }
        .term-prompt-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            background: var(--ide-activity);
            border-top: 1px solid var(--ide-border);
            font-family: var(--ide-font-mono);
            font-size: 0.8rem;
        }
        .term-prompt-text {
            color: var(--ide-accent);
            font-weight: 600;
            white-space: nowrap;
        }
        #term-input {
            flex: 1;
            background: transparent;
            border: none;
            color: #fff;
            font-family: var(--ide-font-mono);
            font-size: 0.8rem;
            outline: none;
        }
        .term-quick-btns {
            display: flex;
            gap: 4px;
            margin-left: 8px;
        }
        .term-pill {
            background: var(--ide-surface2);
            border: 1px solid var(--ide-border);
            color: var(--ide-text2);
            font-size: 0.72rem;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: var(--ide-radius-pill);
            cursor: pointer;
            font-family: var(--ide-font-mono);
            transition: all 0.15s ease;
        }
        .term-pill:hover {
            border-color: var(--ide-cobalt);
            color: var(--ide-cobalt);
            transform: translateY(-1px);
        }

        /* Problems Pane */
        #problems-content {
            padding: 12px;
            overflow-y: auto;
            flex: 1;
            font-family: var(--ide-font-mono);
            font-size: 0.8rem;
        }
        .problem-card {
            padding: 8px 12px;
            border-radius: 4px;
            margin-bottom: 6px;
            border-left: 4px solid var(--ide-green);
            background: var(--ide-surface);
        }
        .problem-card.error {
            border-left-color: var(--ide-red);
            background: rgba(248,113,113,0.08);
        }
        .problem-card.success {
            border-left-color: var(--ide-green);
            background: rgba(74,222,128,0.08);
        }

        /* Output Pane */
        #output-content {
            padding: 10px 12px;
            overflow-y: auto;
            flex: 1;
            font-family: var(--ide-font-mono);
            font-size: 0.78rem;
            color: var(--ide-text2);
            line-height: 1.5;
        }

        
        .term-input-subrow {
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            min-width: 0;
        }
        .term-run-btn {
            background: var(--ide-cobalt);
            color: #FFFFFF;
            border: none;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.15s;
        }
        .term-run-btn:hover {
            background: var(--ide-cobalt-hover);
        }
        #ide-sidebar-backdrop {
            display: none;
            position: fixed;
            top: 42px;
            left: 0;
            right: 0;
            bottom: 26px;
            background: rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(3px);
            z-index: 99;
        }

        /* PuruCode Responsive Rules */
        @media (max-width: 900px) {
            .breadcrumbs {
                display: none !important;
            }
            .title-btn {
                padding: 4px 8px !important;
                font-size: 0.72rem !important;
            }
        }
        @media (max-width: 768px) {
            #ide-titlebar {
                padding: 0 8px !important;
                height: 42px !important;
            }
            .brand-badge .brand-title {
                font-size: 1rem !important;
            }
            .site-badge, .breadcrumbs {
                display: none !important;
            }
            .title-actions {
                gap: 4px !important;
            }
            .title-btn {
                padding: 4px 7px !important;
                font-size: 0.74rem !important;
                border-radius: 8px !important;
            }
            .title-btn .title-btn-text {
                display: none !important;
            }

            /* Activity bar hidden on mobile to give 100% width to editor */
            #ide-activity {
                display: none !important;
            }
            #sidebar-resizer, #panel-resizer {
                display: none !important;
            }

            /* Sidebar turns into clean slide-over drawer */
            #ide-sidebar {
                position: fixed !important;
                top: 42px;
                left: 0;
                bottom: 26px;
                width: 85vw !important;
                max-width: 320px !important;
                min-width: unset !important;
                z-index: 100 !important;
                background: var(--ide-surface) !important;
                box-shadow: 10px 0 35px rgba(0, 0, 0, 0.65) !important;
                transform: translateX(-100%);
                transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
            }
            #ide-sidebar.mobile-open {
                transform: translateX(0) !important;
            }
            #ide-sidebar-backdrop.active {
                display: block !important;
            }
            .mobile-sidebar-close {
                display: flex !important;
            }

            /* Editor occupies 100% full width */
            #ide-editor-area {
                width: 100% !important;
                flex: 1 !important;
            }
            #ide-tabbar {
                height: 32px !important;
                padding: 0 4px !important;
            }
            .editor-tab {
                height: 30px !important;
                padding: 0 8px !important;
                font-size: 0.72rem !important;
                gap: 6px !important;
            }
            .tab-close {
                width: 16px !important;
                height: 16px !important;
                font-size: 0.7rem !important;
            }

            /* Bottom Panel (Terminal / Lint / Output) overlay */
            #bottom-panel {
                position: absolute !important;
                left: 0;
                right: 0;
                bottom: 0;
                width: 100% !important;
                height: 52vh !important;
                max-height: 54vh !important;
                z-index: 40 !important;
                box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.5) !important;
                border-top: 1.5px solid var(--ide-border) !important;
            }
            .panel-header {
                padding: 0 8px !important;
                height: 34px !important;
            }
            .panel-tabs {
                gap: 8px !important;
                font-size: 0.72rem !important;
            }
            .panel-tab {
                padding: 3px 6px !important;
            }
            #term-history {
                font-size: 0.75rem !important;
                padding: 6px 8px !important;
            }

            /* Terminal input & chips stack on mobile */
            .term-prompt-row {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 6px !important;
                padding: 6px 8px !important;
            }
            .term-input-subrow {
                display: flex !important;
                align-items: center !important;
                gap: 6px !important;
                width: 100% !important;
            }
            .term-prompt-text {
                font-size: 0.72rem !important;
                white-space: nowrap !important;
            }
            #term-input {
                font-size: 0.76rem !important;
                flex: 1 !important;
                background: rgba(255, 255, 255, 0.06) !important;
                padding: 5px 8px !important;
                border-radius: 6px !important;
                border: 1px solid var(--ide-border) !important;
                min-width: 0 !important;
            }
            .term-quick-btns {
                display: flex !important;
                gap: 4px !important;
                overflow-x: auto !important;
                white-space: nowrap !important;
                width: 100% !important;
                margin-left: 0 !important;
                padding: 2px 0 4px !important;
                -webkit-overflow-scrolling: touch !important;
            }
            .term-quick-btns::-webkit-scrollbar {
                display: none !important;
            }
            .term-pill {
                padding: 3px 9px !important;
                font-size: 0.7rem !important;
                flex-shrink: 0 !important;
            }

            /* Status bar mobile rules */
            #ide-statusbar {
                height: 26px !important;
                padding: 0 8px !important;
                font-size: 0.7rem !important;
            }
            .status-left {
                gap: 8px !important;
            }
            #status-spaces, #status-spaces + div, .status-hide-mobile {
                display: none !important;
            }
            #status-active-path {
                max-width: 130px !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                white-space: nowrap !important;
            }

            .welcome-brand {
                font-size: 1.6rem !important;
                text-align: center !important;
            }
            .welcome-desc {
                font-size: 0.8rem !important;
                text-align: center !important;
                padding: 0 12px !important;
                margin-bottom: 1rem !important;
            }
            .welcome-shortcuts {
                grid-template-columns: 1fr !important;
                gap: 8px !important;
                padding: 1rem !important;
                width: 92% !important;
            }
        }

        /* Status Bar */
        #ide-statusbar {
            height: 28px;
            background: var(--ide-activity);
            border-top: 1px solid var(--ide-border);
            color: var(--ide-text2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            font-size: 0.74rem;
            font-weight: 500;
            flex-shrink: 0;
            z-index: 10;
        }
        .status-left, .status-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .status-item {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }
        .status-item:hover { opacity: 0.85; }

        /* Context Menu */
        #context-menu {
            position: fixed;
            background: var(--ide-surface2);
            border: 1px solid var(--ide-border);
            border-radius: 12px;
            box-shadow: 0 16px 36px -10px rgba(0,0,0,0.5), 0 2px 8px rgba(0,0,0,0.2);
            display: none;
            flex-direction: column;
            min-width: 175px;
            z-index: 1000;
            padding: 6px;
        }
        .ctx-item {
            padding: 6px 12px;
            font-size: 0.78rem;
            color: var(--ide-text);
            cursor: pointer;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.1s ease;
        }
        .ctx-item:hover {
            background: var(--ide-cobalt);
            color: #FFFFFF;
        }
        .ctx-sep {
            height: 1px;
            background: var(--ide-border);
            margin: 4px 0;
        }

        /* Quick Open Modal (Ctrl+P) */
        #quick-open-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: flex-start;
            justify-content: center;
            padding-top: 60px;
            z-index: 2000;
        }
        .quick-box {
            width: 550px;
            max-width: 90vw;
            background: var(--ide-surface);
            border: 1px solid var(--ide-border);
            border-radius: 8px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.6);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .quick-input-wrap {
            padding: 10px;
            border-bottom: 1px solid var(--ide-border);
        }
        .quick-input-wrap input {
            width: 100%;
            background: var(--ide-activity);
            border: 1px solid var(--ide-accent);
            color: #fff;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
            outline: none;
        }
        .quick-list {
            max-height: 320px;
            overflow-y: auto;
            padding: 4px;
        }
        .quick-item {
            padding: 7px 10px;
            font-size: 0.8rem;
            color: var(--ide-text);
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .quick-item:hover, .quick-item.active {
            background: var(--ide-surface2);
            color: var(--ide-accent);
        }
        .quick-item-sub {
            font-size: 0.72rem;
            color: var(--ide-muted);
        }

        /* Generic Modals */
        .ide-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0,0,0,0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .ide-modal {
            background: var(--ide-surface);
            border: 1px solid var(--ide-border);
            border-radius: 8px;
            width: 420px;
            max-width: 90vw;
            padding: 18px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
        }
        .ide-modal-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 12px;
        }
        .ide-input-group {
            margin-bottom: 14px;
        }
        .ide-input-group label {
            display: block;
            font-size: 0.75rem;
            color: var(--ide-text2);
            margin-bottom: 6px;
        }
        .ide-input-group input, .ide-input-group select {
            width: 100%;
            background: var(--ide-activity);
            border: 1px solid var(--ide-border);
            color: #fff;
            padding: 7px 10px;
            border-radius: 4px;
            font-size: 0.82rem;
            outline: none;
        }
        .ide-input-group input:focus, .ide-input-group select:focus {
            border-color: var(--ide-accent);
        }
        .ide-btn-row {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 16px;
        }
        .ide-btn {
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            border: 1px solid var(--ide-border);
            background: var(--ide-surface2);
            color: var(--ide-text);
            transition: all 0.15s;
        }
        .ide-btn:hover { background: var(--ide-border); color: #fff; }
        .ide-btn-primary {
            background: var(--ide-accent);
            color: #000;
            font-weight: 600;
            border-color: var(--ide-accent);
        }
        .ide-btn-primary:hover {
            filter: brightness(1.1);
        }
        .ide-btn-danger {
            background: var(--ide-red);
            color: #fff;
            border-color: var(--ide-red);
        }

        /* Toast notifications */
        #ide-toast {
            position: fixed;
            bottom: 34px;
            right: 16px;
            background: var(--ide-surface2);
            border: 1px solid var(--ide-border);
            color: #fff;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 0.78rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            gap: 8px;
            z-index: 3000;
            transition: all 0.2s;
        }
        #ide-toast.success { border-color: var(--ide-green); }
        #ide-toast.error { border-color: var(--ide-red); }
    </style>
</head>
<body>
    <div id="purucode-app">
        <!-- RoomMaster Styled Titlebar -->
        <header id="ide-titlebar">
            <div class="title-left">
                <a href="?action=dashboard" class="brand-badge" title="Back to PuruPanel">
                    <span class="brand-icon">
                        <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    </span>
                    <span class="brand-title">Puru<span style="color:var(--ide-cobalt, #3B82F6)">Code</span></span>
                </a>
                <div class="site-badge" title="Virtual Host Root: <?= htmlspecialchars($rootPath) ?>">
                    <span class="dot"></span>
                    <span><?= htmlspecialchars($domain) ?></span>
                </div>
                <div class="breadcrumbs" id="ide-breadcrumbs">
                    <span>public_html</span>
                </div>
            </div>
            <div class="title-actions">
                <button class="title-btn title-btn-sidebar" id="btn-toggle-sidebar" onclick="toggleMobileSidebar()" title="Toggle Files Explorer">
                    📁 <span class="title-btn-text">Files</span>
                </button>
                <a href="https://<?= htmlspecialchars($domain) ?>" target="_blank" class="title-btn" title="Open live site">
                    🌐 <span class="title-btn-text">Live Site ↗</span>
                </a>
                <button class="title-btn" onclick="openQuickOpen()" title="Quick Open (Ctrl+P)">
                    ⚡ <span class="title-btn-text">Quick Open</span>
                </button>
                <button class="title-btn" onclick="toggleBottomPanel()" title="Toggle Terminal (Ctrl+`)">
                    💻 <span class="title-btn-text">Terminal</span>
                </button>
                <button class="title-btn btn-save" onclick="saveActiveFile()" title="Save (Ctrl+S)">
                    💾 <span class="title-btn-text">Save</span>
                </button>
                <a href="?action=files&site_id=<?= $siteId ?>&view=table" class="title-btn" title="Table File Manager">
                    📊 <span class="title-btn-text">Table View</span>
                </a>
                <a href="?action=sites" class="title-btn" title="Back to Sites">
                    🔙 <span class="title-btn-text">PuruPanel</span>
                </a>
                <button class="title-btn btn-theme-toggle-ide" onclick="toggleIdeTheme()" id="btn_theme_ide" title="Toggle Light/Dark Theme">
                    <span id="ide_theme_icon">🌙</span>
                    <span id="ide_theme_text">Theme</span>
                </button>
            </div>
        </header>

        <!-- Main Body -->
        <div id="ide-main">
            <!-- Visual Drag & Drop Overlay -->
            <div id="ide-drop-overlay" style="display:none; position:absolute; inset:0; z-index:9999; background:rgba(10, 15, 29, 0.90); backdrop-filter:blur(8px); border:2.5px dashed var(--ide-blue, #3b82f6); border-radius:8px; align-items:center; justify-content:center; flex-direction:column; gap:12px; pointer-events:none">
                <div style="width:68px; height:68px; border-radius:50%; background:rgba(59,130,246,0.2); border:1px solid rgba(59,130,246,0.3); display:flex; align-items:center; justify-content:center; font-size:2.2rem">
                    📥
                </div>
                <div style="font-weight:700; font-size:1.15rem; color:#fff">Lepaskan File atau Folder di Sini</div>
                <div style="font-size:0.85rem; color:var(--ide-muted, #94a3b8)">Upload otomatis mempertahankan struktur folder ke <span id="ide-drop-target-label" style="font-family:monospace; color:var(--ide-blue, #38bdf8); font-weight:700">/</span></div>
            </div>
            <!-- Activity Bar -->
            <nav id="ide-activity">
                <button class="act-btn active" id="btn-act-explorer" onclick="switchSidebar('explorer')" title="Explorer (File Tree)">
                    📁
                </button>
                <button class="act-btn" id="btn-act-search" onclick="switchSidebar('search')" title="Search Across Files (Ctrl+Shift+F)">
                    🔍
                </button>
                <button class="act-btn" onclick="openSettingsModal()" title="Editor Settings">
                    ⚙️
                </button>
                <div class="act-spacer"></div>
                <button class="act-btn" onclick="toggleBottomPanel()" title="Toggle Terminal (Ctrl+`)">
                    🖥️
                </button>
            </nav>

            <!-- Mobile Sidebar Backdrop -->
            <div id="ide-sidebar-backdrop" onclick="closeMobileSidebar()"></div>

            <!-- Sidebar -->
            <aside id="ide-sidebar">
                <!-- View 1: Explorer -->
                <div id="pane-explorer" style="display:flex;flex-direction:column;flex:1;overflow:hidden">
                    <div class="sidebar-header">
                        <span>Explorer</span>
                        <div class="sidebar-tools">
                            <button class="tool-icon-btn mobile-sidebar-close" onclick="closeMobileSidebar()" style="display:none;color:var(--ide-red)" title="Close Explorer">✕</button>
                            <button class="tool-icon-btn" onclick="promptNewFile('')" title="New File">➕</button>
                            <button class="tool-icon-btn" onclick="promptNewFolder('')" title="New Folder">📁</button>
                            <button class="tool-icon-btn" onclick="triggerUpload('')" title="Upload Files">⬆️</button>
                            <button class="tool-icon-btn" onclick="triggerUploadFolder('')" title="Upload Folder">📂</button>
                            <button class="tool-icon-btn" onclick="loadTree()" title="Refresh Explorer">🔄</button>
                            <button class="tool-icon-btn" onclick="collapseAllFolders()" title="Collapse All">🔽</button>
                        </div>
                    </div>
                    <div class="sidebar-filter">
                        <input type="text" id="tree-filter-input" placeholder="Filter files..." oninput="filterTree(this.value)">
                    </div>
                    <div id="file-tree" oncontextmenu="handleTreeRightClick(event, '')">
                        <div style="padding:15px;color:var(--ide-muted);font-size:0.8rem">Loading files...</div>
                    </div>
                </div>

                <!-- View 2: Search -->
                <div id="pane-search">
                    <div class="sidebar-header" style="padding-left:0;padding-right:0">
                        <span>Search Across Files</span>
                    </div>
                    <div class="search-box-row">
                        <input type="text" id="global-search-input" placeholder="Search text..." onkeydown="if(event.key==='Enter')doGlobalSearch()">
                        <button onclick="doGlobalSearch()">Find</button>
                    </div>
                    <div id="search-results-list">
                        <div style="color:var(--ide-muted);font-size:0.75rem;padding:8px">Tekan Enter untuk mencari teks di seluruh file project.</div>
                    </div>
                </div>
            </aside>

            <!-- Sidebar Resizer -->
            <div class="resizer-v" id="sidebar-resizer"></div>

            <!-- Editor Area -->
            <main id="ide-editor-area">
                <!-- Tab Bar -->
                <div id="ide-tabbar">
                    <!-- Dynamic tabs rendered here -->
                </div>

                <!-- Editor Viewport -->
                <div id="editor-viewport">
                    <!-- RoomMaster Welcome Screen -->
                    <div id="welcome-screen">
                        <div class="page-eyebrow" style="margin-bottom:1rem">
                            <span class="dot"></span>
                            <span>Cloud Infrastructure & Developer Workspace</span>
                        </div>
                        <div class="welcome-brand" style="font-family:'Newsreader', Georgia, serif; font-size:2.4rem; font-weight:500; letter-spacing:-0.03em">
                            PuruCode Cloud IDE
                        </div>
                        <div class="welcome-desc" style="color:var(--ide-text2); margin-top:0.4rem">
                            Workspace virtual host untuk <strong><?= htmlspecialchars($domain) ?></strong> pada Armbian STB
                        </div>
                        <div class="welcome-shortcuts">
                            <div class="shortcut-item">
                                <span>Quick Open File</span>
                                <kbd>Ctrl + P</kbd>
                            </div>
                            <div class="shortcut-item">
                                <span>Save Active File</span>
                                <kbd>Ctrl + S</kbd>
                            </div>
                            <div class="shortcut-item">
                                <span>Toggle Terminal</span>
                                <kbd>Ctrl + `</kbd>
                            </div>
                            <div class="shortcut-item">
                                <span>Search in Files</span>
                                <kbd>Ctrl + Shift + F</kbd>
                            </div>
                            <div class="shortcut-item">
                                <span>Close Active Tab</span>
                                <kbd>Ctrl + W</kbd>
                            </div>
                            <div class="shortcut-item">
                                <span>Create New File</span>
                                <kbd>Alt + N</kbd>
                            </div>
                        </div>
                    </div>

                    <!-- Monaco Container -->
                    <div id="monaco-container"></div>

                    <!-- Image Preview Pane -->
                    <div id="image-preview-pane">
                        <img id="image-preview-img" src="" alt="Preview">
                        <div class="image-meta">
                            <span id="img-meta-name"></span>
                            <span id="img-meta-size"></span>
                            <a id="img-meta-download" href="#" class="title-btn" download style="background:var(--ide-surface2)">📥 Download</a>
                        </div>
                    </div>
                </div>

                <!-- Panel Resizer -->
                <div class="resizer-h" id="panel-resizer"></div>

                <!-- Bottom Panel -->
                <div id="bottom-panel">
                    <div class="panel-header">
                        <div class="panel-tabs">
                            <span class="panel-tab active" id="tab-btn-terminal" onclick="switchBottomTab('terminal')">🖥️ TERMINAL</span>
                            <span class="panel-tab" id="tab-btn-problems" onclick="switchBottomTab('problems')">⚠️ PROBLEMS (LINT)</span>
                            <span class="panel-tab" id="tab-btn-output" onclick="switchBottomTab('output')">📋 OUTPUT</span>
                        </div>
                        <div class="panel-actions">
                            <button class="panel-btn" onclick="clearActivePanel()" title="Clear content">🧹 Clear</button>
                            <button class="panel-btn" onclick="toggleBottomPanel()" title="Close panel">✕</button>
                        </div>
                    </div>
                    <div class="panel-content">
                        <!-- Terminal Pane -->
                        <div class="panel-pane active" id="pane-terminal">
                            <div id="term-history">
                                <div style="color:var(--ide-muted)">⚡ PuruCode Integrated Terminal connected to: <?= htmlspecialchars($rootPath) ?></div>
                                <div style="color:var(--ide-muted);margin-bottom:8px">Ketik perintah shell di bawah atau gunakan tombol shortcut cepat.</div>
                            </div>
                            <div class="term-prompt-row">
                                <div class="term-input-subrow">
                                    <span class="term-prompt-text">web@<?= htmlspecialchars(explode('.', $domain)[0]) ?>:~$</span>
                                    <input type="text" id="term-input" placeholder="Ketik perintah... (e.g. ls -la, artisan, composer)" onkeydown="handleTermKey(event)">
                                    <button class="term-run-btn" onclick="runCurrentTermInput()" title="Jalankan perintah">↵</button>
                                </div>
                                <div class="term-quick-btns">
                                    <span class="term-pill" onclick="execQuickTerm('ls -la')">ls -la</span>
                                    <span class="term-pill" onclick="execQuickTerm('php -v')">php -v</span>
                                    <span class="term-pill" onclick="execQuickTerm('composer')">composer</span>
                                    <span class="term-pill" onclick="execQuickTerm('php artisan')">artisan</span>
                                    <span class="term-pill" onclick="execQuickTerm('git status')">git</span>
                                    <span class="term-pill" onclick="execQuickTerm('clear')">clear</span>
                                </div>
                            </div>
                        </div>

                        <!-- Problems Pane -->
                        <div class="panel-pane" id="pane-problems">
                            <div id="problems-content">
                                <div class="problem-card success">
                                    ✓ Belum ada file yang di-lint atau tidak ada error terdeteksi. Setiap Anda simpan file PHP (Ctrl+S), syntax check otomatis dijalankan di sini!
                                </div>
                            </div>
                        </div>

                        <!-- Output Pane -->
                        <div class="panel-pane" id="pane-output">
                            <div id="output-content">
                                [<?= date('H:i:s') ?>] PuruCode IDE session started for <?= htmlspecialchars($domain) ?>.
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <!-- Status Bar -->
        <footer id="ide-statusbar">
            <div class="status-left">
                <div class="status-item" onclick="toggleBottomPanel()" style="display:flex;align-items:center;gap:6px">
                    <span class="blue-pulse-dot" style="width:6px;height:6px"></span>
                    <span style="font-weight:600;color:var(--ide-text)">purucode</span>
                </div>
                <div class="status-item" title="Git Branch / Deployment status">
                    🌿 main
                </div>
                <div class="status-item" id="status-problems-badge" onclick="switchBottomTab('problems');openBottomPanel()">
                    ✓ No Problems
                </div>
                <div class="status-item" id="status-active-path">
                    No open file
                </div>
            </div>
            <div class="status-right">
                <div class="status-item" id="status-cursor">
                    Ln 1, Col 1
                </div>
                <div class="status-item" id="status-spaces">
                    Spaces: 4
                </div>
                <div class="status-item">
                    UTF-8
                </div>
                <div class="status-item">
                    LF
                </div>
                <div class="status-item" id="status-lang" onclick="openSettingsModal()" style="font-weight:600">
                    Plaintext
                </div>
                <div class="status-item" id="status-save-state" style="background:rgba(255,255,255,0.15);padding:1px 6px;border-radius:3px">
                    ✓ Saved
                </div>
            </div>
        </footer>
    </div>

    <!-- Hidden file upload input -->
    <input type="file" id="ide-upload-input" multiple style="display:none" onchange="handleDoUpload(this)">
    <input type="file" id="ide-folder-input" webkitdirectory directory multiple style="display:none" onchange="handleDoFolderUpload(this)">

    <!-- Context Menu -->
    <div id="context-menu">
        <div class="ctx-item" onclick="ctxAction('new_file')">📄 New File</div>
        <div class="ctx-item" onclick="ctxAction('new_folder')">📁 New Folder</div>
        <div class="ctx-sep"></div>
        <div class="ctx-item" onclick="ctxAction('upload_file')">⬆️ Upload Files...</div>
        <div class="ctx-item" onclick="ctxAction('upload_folder')">📂 Upload Folder...</div>
        <div class="ctx-sep"></div>
        <div class="ctx-item" onclick="ctxAction('rename')">🔤 Rename</div>
        <div class="ctx-item" onclick="ctxAction('copy_path')">📋 Copy Relative Path</div>
        <div class="ctx-item" onclick="ctxAction('extract')" id="ctx-extract-opt" style="display:none; color:var(--ide-blue, #38bdf8); font-weight:700">📦 Ekstrak ZIP di Sini</div>
        <div class="ctx-item" onclick="ctxAction('lint')" id="ctx-lint-opt">🔍 Check PHP Syntax</div>
        <div class="ctx-item" onclick="ctxAction('download')" id="ctx-download-opt">📥 Download</div>
        <div class="ctx-sep"></div>
        <div class="ctx-item" style="color:var(--ide-red)" onclick="ctxAction('delete')">🗑️ Delete</div>
    </div>

    <!-- Quick Open Modal (Ctrl+P) -->
    <div id="quick-open-modal" onclick="if(event.target===this)closeQuickOpen()">
        <div class="quick-box">
            <div class="quick-input-wrap">
                <input type="text" id="quick-open-input" placeholder="Cari nama file (Ketik untuk filter, Enter untuk buka)..." oninput="filterQuickOpen(this.value)" onkeydown="handleQuickKey(event)">
            </div>
            <div class="quick-list" id="quick-open-list">
                <!-- Dynamically rendered -->
            </div>
        </div>
    </div>

    <!-- Generic Modal for New File/Folder/Rename -->
    <div class="ide-modal-overlay" id="generic-modal" onclick="if(event.target===this)closeGenericModal()">
        <div class="ide-modal">
            <div class="ide-modal-title" id="gen-modal-title">Action</div>
            <div class="ide-input-group">
                <label id="gen-modal-label">Nama</label>
                <input type="text" id="gen-modal-input" onkeydown="if(event.key==='Enter')submitGenericModal()">
            </div>
            <div class="ide-btn-row">
                <button class="ide-btn" onclick="closeGenericModal()">Batal</button>
                <button class="ide-btn ide-btn-primary" id="gen-modal-submit" onclick="submitGenericModal()">Simpan</button>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div class="ide-modal-overlay" id="settings-modal" onclick="if(event.target===this)closeSettingsModal()">
        <div class="ide-modal">
            <div class="ide-modal-title">⚙️ Editor Settings</div>
            <div class="ide-input-group">
                <label>Theme</label>
                <select id="setting-theme" onchange="applySetting('theme', this.value)">
                    <option value="vs-dark">VS Dark (Default)</option>
                    <option value="vs">VS Light</option>
                    <option value="hc-black">High Contrast Dark</option>
                </select>
            </div>
            <div class="ide-input-group">
                <label>Font Size</label>
                <select id="setting-fontsize" onchange="applySetting('fontSize', parseInt(this.value))">
                    <option value="12">12px</option>
                    <option value="13">13px</option>
                    <option value="14" selected>14px</option>
                    <option value="15">15px</option>
                    <option value="16">16px</option>
                    <option value="18">18px</option>
                </select>
            </div>
            <div class="ide-input-group">
                <label>Word Wrap</label>
                <select id="setting-wordwrap" onchange="applySetting('wordWrap', this.value)">
                    <option value="off" selected>Off</option>
                    <option value="on">On</option>
                </select>
            </div>
            <div class="ide-input-group">
                <label>Minimap</label>
                <select id="setting-minimap" onchange="applySetting('minimap', this.value === 'true')">
                    <option value="true" selected>Enabled</option>
                    <option value="false">Disabled</option>
                </select>
            </div>
            <div class="ide-btn-row">
                <button class="ide-btn ide-btn-primary" onclick="closeSettingsModal()">Tutup</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="ide-toast">
        <span id="ide-toast-icon">ℹ️</span>
        <span id="ide-toast-msg">Notification</span>
    </div>

    <!-- Monaco Editor Loader -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs/loader.min.js"></script>
    <script>
    const SITE_ID = <?= $siteId ?>;
    const DOMAIN = "<?= addslashes($domain) ?>";

    // IDE State
    let editor = null;
    let openTabs = []; // { path, name, ext, dirty, model, viewState, isImage }
    let activeTabPath = null;
    let fullTreeData = [];
    let expandedDirs = new Set(['']);
    let currentUploadDir = '';
    let contextTarget = { path: '', type: 'dir', name: '' };
    let termHistoryCommands = [];
    let termHistoryIdx = -1;
    let quickFilesList = [];
    let quickActiveIdx = 0;
    let bottomPanelOpen = (window.innerWidth > 768);

    // Load Monaco Editor via AMD Loader
    require.config({ paths: { 'vs': 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs' } });
    require(['vs/editor/editor.main'], function() {
        const savedTheme = (localStorage.getItem('puru_theme') === 'light') ? 'vs' : (localStorage.getItem('purucode_theme') || 'vs-dark');
        const savedFontSize = parseInt(localStorage.getItem('purucode_fontsize')) || 14;
        const savedWordWrap = localStorage.getItem('purucode_wordwrap') || 'off';
        const savedMinimap = localStorage.getItem('purucode_minimap') !== 'false';

        editor = monaco.editor.create(document.getElementById('monaco-container'), {
            theme: savedTheme,
            fontSize: savedFontSize,
            wordWrap: savedWordWrap,
            minimap: { enabled: savedMinimap },
            automaticLayout: true,
            fontFamily: "'JetBrains Mono', Consolas, 'Courier New', monospace",
            tabSize: 4,
            scrollBeyondLastLine: false,
            bracketPairColorization: { enabled: true },
            cursorBlinking: 'smooth',
            smoothScrolling: true,
            renderLineHighlight: 'all',
            padding: { top: 8 }
        });

        // Shortcuts in Monaco
        editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, saveActiveFile);
        editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyP, openQuickOpen);
        editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyW, closeActiveTab);

        editor.onDidChangeModelContent(function() {
            if (!activeTabPath) return;
            const tab = openTabs.find(t => t.path === activeTabPath);
            if (tab && !tab.dirty) {
                tab.dirty = true;
                renderTabs();
                updateStatusBar();
            }
        });

        editor.onDidChangeCursorPosition(function(e) {
            updateCursorPosition(e.position.lineNumber, e.position.column);
        });

        // Initialize Explorer Tree & Initial file
        loadTree(function() {
            // Try to open index.php or first available file
            openInitialFile();
        });
    });

    // Global keyboard shortcuts
    window.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            saveActiveFile();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
            e.preventDefault();
            openQuickOpen();
        } else if ((e.ctrlKey || e.metaKey) && e.key === '`') {
            e.preventDefault();
            toggleBottomPanel();
        } else if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'f') {
            e.preventDefault();
            switchSidebar('search');
            document.getElementById('global-search-input').focus();
        } else if (e.key === 'Escape') {
            closeQuickOpen();
            closeGenericModal();
            closeSettingsModal();
            hideContextMenu();
        }
    });

    // Hide context menu on click outside
    window.addEventListener('click', function(e) {
        if (!e.target.closest('#context-menu')) {
            hideContextMenu();
        }
    });

    // Window resize layout fix
    window.addEventListener('resize', function() {
        if (editor) editor.layout();
    });

    // Drag and Drop Upload Support for IDE
    let ideDragCounter = 0;
    window.addEventListener('dragenter', function(e) {
        if (e.dataTransfer && e.dataTransfer.types && Array.from(e.dataTransfer.types).includes('Files')) {
            ideDragCounter++;
            const lbl = document.getElementById('ide-drop-target-label');
            if (lbl) lbl.textContent = currentUploadDir ? '/' + currentUploadDir : '/';
            const ov = document.getElementById('ide-drop-overlay');
            if (ov) ov.style.display = 'flex';
        }
    });
    window.addEventListener('dragover', function(e) {
        if (e.dataTransfer && e.dataTransfer.types && Array.from(e.dataTransfer.types).includes('Files')) {
            e.preventDefault();
        }
    });
    window.addEventListener('dragleave', function(e) {
        ideDragCounter--;
        if (ideDragCounter <= 0) {
            ideDragCounter = 0;
            const ov = document.getElementById('ide-drop-overlay');
            if (ov) ov.style.display = 'none';
        }
    });
    window.addEventListener('drop', async function(e) {
        e.preventDefault();
        ideDragCounter = 0;
        const ov = document.getElementById('ide-drop-overlay');
        if (ov) ov.style.display = 'none';
        if (!e.dataTransfer) return;
        const payload = await scanDataTransfer(e.dataTransfer);
        if (payload.files.length > 0 || payload.dirs.length > 0) {
            batchUploadEntries(payload, currentUploadDir, () => loadTree());
        }
    });

    // ----------------------------------------------------
    // API Helper
    // ----------------------------------------------------
    function fmApi(action, data, cb) {
        const fd = new URLSearchParams();
        fd.append('site_id', SITE_ID);
        for (let k in data) fd.append(k, data[k]);
        fetch('?ajax=1&action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success && res.message) {
                showToast(res.message, 'error');
            }
            if (cb) cb(res);
        })
        .catch(err => {
            showToast('Network error: ' + err.message, 'error');
        });
    }

    // ----------------------------------------------------
    // Explorer Tree Loading & Rendering
    // ----------------------------------------------------
    function loadTree(cb) {
        fmApi('fm_tree', {}, function(res) {
            if (!res.success) return;
            fullTreeData = res.tree || [];
            renderTree(fullTreeData);
            logOutput('File tree loaded (' + countTreeItems(fullTreeData) + ' items)');
            if (cb) cb();
        });
    }

    function countTreeItems(nodes) {
        let count = 0;
        for (let n of nodes) {
            count++;
            if (n.children) count += countTreeItems(n.children);
        }
        return count;
    }

    function renderTree(nodes, filterText) {
        const container = document.getElementById('file-tree');
        if (!nodes || nodes.length === 0) {
            container.innerHTML = '<div style="padding:15px;color:var(--ide-muted);font-size:0.8rem">Folder kosong</div>';
            return;
        }

        let html = '';
        function buildHtml(list, depth) {
            for (let item of list) {
                if (filterText && !itemMatchesFilter(item, filterText.toLowerCase())) {
                    continue;
                }
                const isDir = (item.type === 'dir');
                const isExpanded = expandedDirs.has(item.path);
                const indent = depth * 14 + 10;
                const isSelected = (activeTabPath === item.path);

                html += `<div class="tree-item ${isSelected ? 'selected' : ''}" 
                             style="padding-left:${indent}px" 
                             data-path="${escAttr(item.path)}" 
                             data-type="${item.type}"
                             onclick="handleTreeClick(event, '${escAttr(item.path)}', '${item.type}')"
                             oncontextmenu="handleTreeRightClick(event, '${escAttr(item.path)}', '${item.type}', '${escAttr(item.name)}')">`;

                if (isDir) {
                    html += `<span class="tree-arrow ${isExpanded ? 'expanded' : ''}">▶</span>`;
                    html += `<span class="tree-icon">${isExpanded ? '📂' : '📁'}</span>`;
                } else {
                    html += `<span class="tree-arrow"></span>`;
                    html += `<span class="tree-icon">${getFileIcon(item.ext || '')}</span>`;
                }

                html += `<span class="tree-label">${escHtml(item.name)}</span>`;

                html += `<div class="tree-actions-hover">`;
                if (isDir) {
                    html += `<button class="tree-mini-btn" title="New file here" onclick="event.stopPropagation();promptNewFile('${escAttr(item.path)}')">➕</button>`;
                }
                html += `<button class="tree-mini-btn" title="Delete" onclick="event.stopPropagation();deleteTarget('${escAttr(item.path)}', '${escAttr(item.name)}')">🗑️</button>`;
                html += `</div>`;

                html += `</div>`;

                if (isDir && isExpanded && item.children && item.children.length > 0) {
                    buildHtml(item.children, depth + 1);
                }
            }
        }

        buildHtml(nodes, 0);
        container.innerHTML = html || '<div style="padding:15px;color:var(--ide-muted);font-size:0.8rem">Tidak ada file yang cocok</div>';
    }

    function itemMatchesFilter(item, filter) {
        if (item.name.toLowerCase().includes(filter)) return true;
        if (item.children) {
            for (let c of item.children) {
                if (itemMatchesFilter(c, filter)) return true;
            }
        }
        return false;
    }

    function filterTree(val) {
        renderTree(fullTreeData, val.trim());
    }

    function collapseAllFolders() {
        expandedDirs.clear();
        renderTree(fullTreeData);
    }

    function handleTreeClick(e, path, type) {
        if (type === 'dir') {
            if (expandedDirs.has(path)) {
                expandedDirs.delete(path);
            } else {
                expandedDirs.add(path);
            }
            renderTree(fullTreeData);
        } else {
            openFile(path);
        }
    }

    function getFileIcon(ext) {
        ext = (ext || '').toLowerCase();
        switch(ext) {
            case 'php': return '<span class="badge-php">PHP</span>';
            case 'js': case 'mjs': return '<span class="badge-js">JS</span>';
            case 'ts': return '<span class="badge-js" style="color:#60a5fa">TS</span>';
            case 'css': case 'scss': return '<span class="badge-css">CSS</span>';
            case 'html': case 'htm': return '<span class="badge-html">&lt;&gt;</span>';
            case 'json': return '<span class="badge-json">{}</span>';
            case 'sql': return '<span class="badge-sql">SQL</span>';
            case 'png': case 'jpg': case 'jpeg': case 'gif': case 'webp': case 'svg': case 'ico':
                return '<span class="badge-img">🖼️</span>';
            case 'md': return '<span style="color:#94a3b8;font-weight:700;font-size:0.7rem">M↓</span>';
            case 'sh': case 'bash': return '<span style="color:#4ade80;font-weight:700;font-size:0.7rem">$_</span>';
            default: return '<span style="color:var(--ide-muted)">📄</span>';
        }
    }

    function openInitialFile() {
        function findFile(nodes) {
            for (let n of nodes) {
                if (n.type === 'file') {
                    if (n.name === 'index.php' || n.name === 'index.html') return n.path;
                }
            }
            for (let n of nodes) {
                if (n.type === 'file') return n.path;
                if (n.children) {
                    const found = findFile(n.children);
                    if (found) return found;
                }
            }
            return null;
        }
        const target = findFile(fullTreeData);
        if (target) openFile(target);
    }

    // ----------------------------------------------------
    // Tab & Editor Management
    // ----------------------------------------------------
    function openFile(path, targetLine) {
        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }
        const ext = path.split('.').pop().toLowerCase();
        const name = path.split('/').pop();
        const isImg = isImageExt(ext);

        // Check if already open
        let tab = openTabs.find(t => t.path === path);
        if (tab) {
            activateTab(path);
            if (targetLine && editor && !isImg) {
                editor.revealLineInCenter(targetLine);
                editor.setPosition({ lineNumber: targetLine, column: 1 });
            }
            return;
        }

        // Open new tab
        if (isImg) {
            openTabs.push({
                path: path,
                name: name,
                ext: ext,
                dirty: false,
                isImage: true
            });
            activateTab(path);
            renderTabs();
            return;
        }

        // Fetch text content from server
        fmApi('fm_read', { path: path }, function(res) {
            if (!res.success) return;

            const lang = getLanguageFromExt(ext);
            const modelUri = monaco.Uri.parse('file:///' + path);
            let model = monaco.editor.getModel(modelUri);
            if (!model) {
                model = monaco.editor.createModel(res.content, lang, modelUri);
            } else {
                model.setValue(res.content);
            }

            openTabs.push({
                path: path,
                name: name,
                ext: ext,
                dirty: false,
                isImage: false,
                model: model,
                viewState: null
            });

            activateTab(path);
            renderTabs();

            if (targetLine && editor) {
                editor.revealLineInCenter(targetLine);
                editor.setPosition({ lineNumber: targetLine, column: 1 });
            }

            logOutput('Opened ' + path + ' (' + (res.content.length) + ' bytes)');
        });
    }

    function activateTab(path) {
        activeTabPath = path;
        const tab = openTabs.find(t => t.path === path);
        if (!tab) return;

        // Hide welcome screen
        document.getElementById('welcome-screen').style.display = 'none';

        if (tab.isImage) {
            // Show Image View
            document.getElementById('monaco-container').style.display = 'none';
            const imgPane = document.getElementById('image-preview-pane');
            imgPane.style.display = 'flex';
            const imgUrl = '?action=fm_raw&site_id=' + SITE_ID + '&path=' + encodeURIComponent(path);
            document.getElementById('image-preview-img').src = imgUrl;
            document.getElementById('img-meta-name').textContent = tab.name;
            document.getElementById('img-meta-download').href = imgUrl + '&download=1';
            updateStatusBar();
            renderTabs();
            renderTree(fullTreeData);
            return;
        }

        // Show Monaco Editor
        document.getElementById('image-preview-pane').style.display = 'none';
        document.getElementById('monaco-container').style.display = 'block';

        if (editor && tab.model) {
            editor.setModel(tab.model);
            if (tab.viewState) editor.restoreViewState(tab.viewState);
            editor.focus();
        }

        updateBreadcrumbs(path);
        updateStatusBar();
        renderTabs();
        renderTree(fullTreeData);
    }

    function renderTabs() {
        const bar = document.getElementById('ide-tabbar');
        if (openTabs.length === 0) {
            bar.innerHTML = '';
            document.getElementById('welcome-screen').style.display = 'flex';
            document.getElementById('monaco-container').style.display = 'none';
            document.getElementById('image-preview-pane').style.display = 'none';
            updateStatusBar();
            return;
        }

        let html = '';
        for (let tab of openTabs) {
            const isActive = (tab.path === activeTabPath);
            html += `<div class="editor-tab ${isActive ? 'active' : ''} ${tab.dirty ? 'dirty' : ''}" 
                          onclick="activateTab('${escAttr(tab.path)}')"
                          onauxclick="if(event.button===1){event.preventDefault();closeTab('${escAttr(tab.path)}')}"
                          title="${escAttr(tab.path)}">
                        <span>${getFileIcon(tab.ext)}</span>
                        <span>${escHtml(tab.name)}</span>
                        <span class="tab-dirty-dot"></span>
                        <span class="tab-close" onclick="event.stopPropagation();closeTab('${escAttr(tab.path)}')">✕</span>
                     </div>`;
        }
        bar.innerHTML = html;
    }

    function closeTab(path) {
        const tab = openTabs.find(t => t.path === path);
        if (!tab) return;

        if (tab.dirty) {
            if (!confirm('File ' + tab.name + ' memiliki perubahan yang belum disimpan. Tetap tutup?')) {
                return;
            }
        }

        const idx = openTabs.findIndex(t => t.path === path);
        openTabs.splice(idx, 1);

        if (activeTabPath === path) {
            if (openTabs.length > 0) {
                const nextTab = openTabs[Math.max(0, idx - 1)];
                activateTab(nextTab.path);
            } else {
                activeTabPath = null;
                renderTabs();
            }
        } else {
            renderTabs();
        }
    }

    function closeActiveTab() {
        if (activeTabPath) closeTab(activeTabPath);
    }

    function saveActiveFile() {
        if (!activeTabPath) return;
        const tab = openTabs.find(t => t.path === activeTabPath);
        if (!tab || tab.isImage) return;

        const content = tab.model ? tab.model.getValue() : editor.getValue();
        setSaveState('Saving...');

        fmApi('fm_save', { path: tab.path, content: content }, function(res) {
            if (res.success) {
                tab.dirty = false;
                renderTabs();
                setSaveState('✓ Saved', 2500);
                showToast('Disimpan: ' + tab.name, 'success');
                logOutput('Saved ' + tab.path);

                // Auto Lint PHP
                if (tab.ext === 'php') {
                    runPhpLint(tab.path);
                }
            } else {
                setSaveState('Save Failed', 3000);
                showToast('Gagal simpan: ' + (res.message || 'Error'), 'error');
            }
        });
    }

    function runPhpLint(path) {
        fmApi('fm_php_lint', { path: path }, function(res) {
            if (!res.success) return;
            const container = document.getElementById('problems-content');
            const badge = document.getElementById('status-problems-badge');

            if (res.has_error) {
                badge.innerHTML = '⚠️ 1 Error in ' + escHtml(path.split('/').pop());
                badge.style.color = 'var(--ide-red)';
                container.innerHTML = `
                    <div class="problem-card error">
                        <div style="font-weight:700;color:var(--ide-red);margin-bottom:4px">❌ Syntax Error Terdeteksi di ${escHtml(path)}:</div>
                        <pre style="white-space:pre-wrap;color:#fca5a5;font-size:0.75rem">${escHtml(res.message)}</pre>
                    </div>`;
                switchBottomTab('problems');
                openBottomPanel();
            } else {
                badge.innerHTML = '✓ No Problems';
                badge.style.color = '#fff';
                container.innerHTML = `
                    <div class="problem-card success">
                        ✓ PHP Syntax Valid: <code>${escHtml(path)}</code> — ${escHtml(res.message)}
                    </div>`;
            }
        });
    }

    // ----------------------------------------------------
    // Terminal Handling
    // ----------------------------------------------------
    function runCurrentTermInput() {
        const input = document.getElementById('term-input');
        const cmd = input.value.trim();
        if (!cmd) return;
        termHistoryCommands.push(cmd);
        termHistoryIdx = termHistoryCommands.length;
        input.value = '';
        executeTermCommand(cmd);
    }

    function toggleMobileSidebar() {
        const sb = document.getElementById('ide-sidebar');
        const bd = document.getElementById('ide-sidebar-backdrop');
        if (window.innerWidth <= 768) {
            const isOpen = sb.classList.contains('mobile-open');
            if (isOpen) {
                closeMobileSidebar();
            } else {
                openMobileSidebar();
            }
        } else {
            sb.style.display = (sb.style.display === 'none') ? 'flex' : 'none';
            const resizer = document.getElementById('sidebar-resizer');
            if (resizer) resizer.style.display = sb.style.display;
            if (editor) editor.layout();
        }
    }

    function openMobileSidebar() {
        const sb = document.getElementById('ide-sidebar');
        const bd = document.getElementById('ide-sidebar-backdrop');
        sb.classList.add('mobile-open');
        if (bd) bd.classList.add('active');
    }

    function closeMobileSidebar() {
        const sb = document.getElementById('ide-sidebar');
        const bd = document.getElementById('ide-sidebar-backdrop');
        sb.classList.remove('mobile-open');
        if (bd) bd.classList.remove('active');
        if (editor) editor.layout();
    }

    function handleTermKey(e) {
        if (e.key === 'Enter') {
            runCurrentTermInput();
        } else if (e.key === 'ArrowUp') {
            if (termHistoryIdx > 0) {
                termHistoryIdx--;
                document.getElementById('term-input').value = termHistoryCommands[termHistoryIdx];
            }
        } else if (e.key === 'ArrowDown') {
            if (termHistoryIdx < termHistoryCommands.length - 1) {
                termHistoryIdx++;
                document.getElementById('term-input').value = termHistoryCommands[termHistoryIdx];
            } else {
                termHistoryIdx = termHistoryCommands.length;
                document.getElementById('term-input').value = '';
            }
        }
    }

    function execQuickTerm(cmd) {
        executeTermCommand(cmd);
    }

    function executeTermCommand(cmd) {
        const hist = document.getElementById('term-history');
        const lineCmd = document.createElement('div');
        lineCmd.className = 'term-line-cmd';
        lineCmd.textContent = '$ ' + cmd;
        hist.appendChild(lineCmd);
        hist.scrollTop = hist.scrollHeight;

        fmApi('term_exec', { cmd: cmd }, function(res) {
            const lineOut = document.createElement('div');
            lineOut.className = 'term-line-out';
            if (res.success) {
                lineOut.textContent = res.output || '(No output)';
            } else {
                lineOut.textContent = res.message || 'Command failed';
                lineOut.style.color = 'var(--ide-red)';
            }
            hist.appendChild(lineOut);
            hist.scrollTop = hist.scrollHeight;
        });
    }

    // ----------------------------------------------------
    // Search Across Files (Ctrl+Shift+F)
    // ----------------------------------------------------
    function doGlobalSearch() {
        const query = document.getElementById('global-search-input').value.trim();
        if (query.length < 2) {
            showToast('Query minimal 2 karakter', 'error');
            return;
        }

        const resList = document.getElementById('search-results-list');
        resList.innerHTML = '<div style="color:var(--ide-muted);font-size:0.75rem;padding:8px">Mencari...</div>';

        fmApi('fm_search', { query: query }, function(res) {
            if (!res.success) {
                resList.innerHTML = `<div style="color:var(--ide-red);font-size:0.75rem;padding:8px">${escHtml(res.message || 'Error')}</div>`;
                return;
            }

            const matches = res.matches || [];
            if (matches.length === 0) {
                resList.innerHTML = '<div style="color:var(--ide-muted);font-size:0.75rem;padding:8px">Tidak ada hasil yang ditemukan.</div>';
                return;
            }

            let html = `<div style="color:var(--ide-text2);font-size:0.72rem;padding:4px 8px">${matches.length} hasil ditemukan:</div>`;
            for (let m of matches) {
                html += `<div class="search-hit-item" onclick="openFile('${escAttr(m.file)}', ${m.line})">
                            <div class="search-hit-file">
                                <span>${escHtml(m.file)}</span>
                                <span class="search-hit-line">:${m.line}</span>
                            </div>
                            <div class="search-hit-text">${escHtml(m.text)}</div>
                         </div>`;
            }
            resList.innerHTML = html;
        });
    }

    // ----------------------------------------------------
    // Quick Open (Ctrl+P)
    // ----------------------------------------------------
    function openQuickOpen() {
        const modal = document.getElementById('quick-open-modal');
        const input = document.getElementById('quick-open-input');
        modal.style.display = 'flex';
        input.value = '';
        input.focus();

        fmApi('fm_file_list', {}, function(res) {
            if (res.success) {
                quickFilesList = res.files || [];
                renderQuickList(quickFilesList);
            }
        });
    }

    function closeQuickOpen() {
        document.getElementById('quick-open-modal').style.display = 'none';
        if (editor) editor.focus();
    }

    function filterQuickOpen(val) {
        val = val.trim().toLowerCase();
        if (!val) {
            renderQuickList(quickFilesList);
            return;
        }
        const filtered = quickFilesList.filter(f => f.path.toLowerCase().includes(val));
        renderQuickList(filtered);
    }

    function renderQuickList(list) {
        const box = document.getElementById('quick-open-list');
        quickActiveIdx = 0;
        if (!list || list.length === 0) {
            box.innerHTML = '<div style="padding:10px;color:var(--ide-muted);font-size:0.8rem">Tidak ada file yang cocok</div>';
            return;
        }

        let html = '';
        const limit = Math.min(list.length, 30);
        for (let i = 0; i < limit; i++) {
            const f = list[i];
            html += `<div class="quick-item ${i === 0 ? 'active' : ''}" 
                          data-idx="${i}" 
                          data-path="${escAttr(f.path)}"
                          onclick="openFile('${escAttr(f.path)}');closeQuickOpen()">
                        <span>${getFileIcon(f.ext)} ${escHtml(f.name)}</span>
                        <span class="quick-item-sub">${escHtml(f.path)}</span>
                     </div>`;
        }
        box.innerHTML = html;
    }

    function handleQuickKey(e) {
        const items = document.querySelectorAll('.quick-item');
        if (items.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            items[quickActiveIdx].classList.remove('active');
            quickActiveIdx = (quickActiveIdx + 1) % items.length;
            items[quickActiveIdx].classList.add('active');
            items[quickActiveIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            items[quickActiveIdx].classList.remove('active');
            quickActiveIdx = (quickActiveIdx - 1 + items.length) % items.length;
            items[quickActiveIdx].classList.add('active');
            items[quickActiveIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const targetPath = items[quickActiveIdx].dataset.path;
            if (targetPath) {
                openFile(targetPath);
                closeQuickOpen();
            }
        }
    }

    // ----------------------------------------------------
    // Context Menu & File Ops
    // ----------------------------------------------------
    function handleTreeRightClick(e, path, type, name) {
        e.preventDefault();
        e.stopPropagation();
        contextTarget = { path: path, type: type || 'dir', name: name || '' };

        const menu = document.getElementById('context-menu');
        menu.style.display = 'flex';
        menu.style.left = Math.min(e.clientX, window.innerWidth - 180) + 'px';
        menu.style.top = Math.min(e.clientY, window.innerHeight - 220) + 'px';

        const isPhp = (path.endsWith('.php'));
        const isZip = (path.toLowerCase().endsWith('.zip'));
        document.getElementById('ctx-lint-opt').style.display = isPhp ? 'flex' : 'none';
        const extractOpt = document.getElementById('ctx-extract-opt');
        if (extractOpt) extractOpt.style.display = isZip ? 'flex' : 'none';
        document.getElementById('ctx-download-opt').style.display = (type === 'file') ? 'flex' : 'none';
    }

    function hideContextMenu() {
        document.getElementById('context-menu').style.display = 'none';
    }

    function ctxAction(action) {
        hideContextMenu();
        const target = contextTarget;
        switch (action) {
            case 'new_file':
                promptNewFile(target.type === 'dir' ? target.path : getParentPath(target.path));
                break;
            case 'new_folder':
                promptNewFolder(target.type === 'dir' ? target.path : getParentPath(target.path));
                break;
            case 'upload_file':
                triggerUpload(target.type === 'dir' ? target.path : getParentPath(target.path));
                break;
            case 'upload_folder':
                triggerUploadFolder(target.type === 'dir' ? target.path : getParentPath(target.path));
                break;
            case 'rename':
                promptRename(target.path, target.name);
                break;
            case 'delete':
                deleteTarget(target.path, target.name);
                break;
            case 'copy_path':
                navigator.clipboard.writeText(target.path);
                showToast('Path disalin: ' + target.path, 'success');
                break;
            case 'lint':
                runPhpLint(target.path);
                break;
            case 'extract':
                showToast('Mengekstrak file ZIP...', 'info');
                fmApi('fm_extract', { path: target.path }, function(res) {
                    if (res.success) {
                        showToast(res.message || 'File ZIP berhasil diekstrak!', 'success');
                        loadTree();
                    } else {
                        showToast(res.message || 'Gagal mengekstrak ZIP', 'error');
                    }
                });
                break;
            case 'download':
                window.open('?action=fm_raw&site_id=' + SITE_ID + '&path=' + encodeURIComponent(target.path) + '&download=1');
                break;
        }
    }

    function getParentPath(path) {
        const idx = path.lastIndexOf('/');
        return idx !== -1 ? path.substring(0, idx) : '';
    }

    // Modal Helpers
    let modalCallback = null;
    function openGenericModal(title, label, defaultValue, cb) {
        document.getElementById('gen-modal-title').textContent = title;
        document.getElementById('gen-modal-label').textContent = label;
        const input = document.getElementById('gen-modal-input');
        input.value = defaultValue || '';
        modalCallback = cb;
        document.getElementById('generic-modal').style.display = 'flex';
        input.focus();
    }
    function closeGenericModal() {
        document.getElementById('generic-modal').style.display = 'none';
        modalCallback = null;
    }
    function submitGenericModal() {
        const val = document.getElementById('gen-modal-input').value.trim();
        if (modalCallback && val) {
            modalCallback(val);
        }
        closeGenericModal();
    }

    function promptNewFile(dir) {
        openGenericModal('Buat File Baru', 'Nama File (contoh: style.css, api.php)', '', function(name) {
            const fullPath = dir ? dir + '/' + name : name;
            fmApi('fm_save', { path: fullPath, content: '' }, function(res) {
                if (res.success) {
                    showToast('File dibuat: ' + name, 'success');
                    loadTree(function() {
                        openFile(fullPath);
                    });
                }
            });
        });
    }

    function promptNewFolder(dir) {
        openGenericModal('Buat Folder Baru', 'Nama Folder (contoh: assets, includes)', '', function(name) {
            fmApi('fm_mkdir', { dir: dir, name: name }, function(res) {
                if (res.success) {
                    showToast('Folder dibuat: ' + name, 'success');
                    if (dir) expandedDirs.add(dir);
                    loadTree();
                }
            });
        });
    }

    function promptRename(path, oldName) {
        openGenericModal('Ubah Nama', 'Nama Baru', oldName, function(newName) {
            const dir = getParentPath(path);
            const newPath = dir ? dir + '/' + newName : newName;
            fmApi('fm_rename', { old: path, new: newPath }, function(res) {
                if (res.success) {
                    showToast('Nama berhasil diubah', 'success');
                    // Update open tabs if renamed file was open
                    const tab = openTabs.find(t => t.path === path);
                    if (tab) {
                        tab.path = newPath;
                        tab.name = newName;
                        tab.ext = newName.split('.').pop().toLowerCase();
                        renderTabs();
                    }
                    loadTree();
                }
            });
        });
    }

    function deleteTarget(path, name) {
        if (!confirm('Hapus ' + (name || path) + '? Tindakan ini tidak dapat dibatalkan.')) return;
        fmApi('fm_delete', { path: path }, function(res) {
            if (res.success) {
                showToast('Berhasil dihapus', 'success');
                closeTab(path);
                loadTree();
            }
        });
    }

    // Recursive directory scanning from DataTransfer
    async function scanDataTransfer(dataTransfer) {
        const fileEntries = [];
        const dirEntries = [];
        const items = dataTransfer.items;
        
        if (items && items.length > 0 && (items[0].webkitGetAsEntry || items[0].getAsEntry)) {
            async function readEntriesAsync(reader) {
                let all = [];
                while (true) {
                    const batch = await new Promise(resolve => {
                        reader.readEntries(entries => resolve(entries || []), () => resolve([]));
                    });
                    if (!batch || batch.length === 0) break;
                    all.push(...batch);
                }
                return all;
            }

            async function traverse(entry, path = '') {
                if (entry.isFile) {
                    return new Promise(resolve => {
                        entry.file(f => {
                            resolve([{ file: f, path: path + f.name }]);
                        }, () => resolve([]));
                    });
                } else if (entry.isDirectory) {
                    const currentDirPath = path + entry.name;
                    dirEntries.push(currentDirPath);
                    const reader = entry.createReader();
                    const entries = await readEntriesAsync(reader);
                    const results = [];
                    for (const child of entries) {
                        const sub = await traverse(child, currentDirPath + '/');
                        results.push(...sub);
                    }
                    return results;
                }
                return [];
            }

            const promises = [];
            for (let i = 0; i < items.length; i++) {
                const item = items[i];
                const entry = (item.webkitGetAsEntry ? item.webkitGetAsEntry() : (item.getAsEntry ? item.getAsEntry() : null));
                if (entry) {
                    promises.push(traverse(entry, ''));
                } else if (item.kind === 'file') {
                    const f = item.getAsFile();
                    if (f) promises.push(Promise.resolve([{ file: f, path: f.name }]));
                }
            }
            const nested = await Promise.all(promises);
            nested.forEach(arr => fileEntries.push(...arr));
        } else if (dataTransfer.files && dataTransfer.files.length > 0) {
            for (let i = 0; i < dataTransfer.files.length; i++) {
                const f = dataTransfer.files[i];
                const rel = f.webkitRelativePath || f.name;
                fileEntries.push({ file: f, path: rel });
                const slashIdx = rel.lastIndexOf('/');
                if (slashIdx !== -1) dirEntries.push(rel.substring(0, slashIdx));
            }
        }
        return { files: fileEntries, dirs: dirEntries };
    }

    // Batched upload with progress toast (adaptive chunking by count and total bytes)
    async function batchUploadEntries(uploadPayload, targetDir, onDone) {
        const files = Array.isArray(uploadPayload) ? uploadPayload : (uploadPayload.files || []);
        const dirs = uploadPayload.dirs || [];

        if (files.length === 0 && dirs.length === 0) {
            showToast('Tidak ada file atau folder yang dipilih', 'error');
            return;
        }

        const totalFiles = files.length;
        const batches = [];
        let curBatch = [];
        let curBatchBytes = 0;
        const MAX_FILES = 12;
        const MAX_BYTES = 25 * 1024 * 1024; // 25 MB max per batch

        for (const item of files) {
            const sz = (item.file && item.file.size) ? item.file.size : 0;
            if (curBatch.length >= MAX_FILES || (curBatchBytes + sz > MAX_BYTES && curBatch.length > 0)) {
                batches.push(curBatch);
                curBatch = [];
                curBatchBytes = 0;
            }
            curBatch.push(item);
            curBatchBytes += sz;
        }
        if (curBatch.length > 0) batches.push(curBatch);
        if (batches.length === 0 && dirs.length > 0) batches.push([]);

        let uploadedCount = 0;
        showToast(`Memulai upload (${totalFiles} file dalam ${batches.length} batch)...`, 'info');

        for (let b = 0; b < batches.length; b++) {
            const chunk = batches[b];
            const fd = new FormData();
            fd.append('site_id', SITE_ID);
            fd.append('dir', targetDir || '');
            fd.append('ajax', '1');
            fd.append('action', 'fm_upload');

            if (b === 0 && dirs.length > 0) {
                dirs.forEach(d => fd.append('dirs[]', d));
            }

            chunk.forEach(item => {
                fd.append('files[]', item.file);
                fd.append('paths[]', item.path);
            });

            try {
                const res = await fetch('?ajax=1&action=fm_upload', { method: 'POST', body: fd });
                if (!res.ok) {
                    const errText = await res.text();
                    throw new Error(`HTTP ${res.status}: ${errText.slice(0, 140)}`);
                }
                const json = await res.json();
                if (!json.success) {
                    showToast(`Batch ${b + 1}/${batches.length} gagal: ` + (json.message || 'Error'), 'error');
                    return;
                }
                uploadedCount += chunk.length;
                showToast(`Mengupload: ${uploadedCount} / ${totalFiles} file (Batch ${b + 1}/${batches.length})...`, 'info');
            } catch (err) {
                showToast('Upload error: ' + err.message, 'error');
                return;
            }
        }

        showToast(`Sukses upload ${uploadedCount} file & folder! 🎉`, 'success');
        if (typeof onDone === 'function') onDone();

        // Check if ZIP file was uploaded and prompt for instant extraction
        const zipItem = files.find(f => f.path && f.path.toLowerCase().endsWith('.zip'));
        if (zipItem) {
            const zipRel = targetDir ? targetDir + '/' + zipItem.path : zipItem.path;
            setTimeout(() => {
                if (confirm(`File ZIP "${zipItem.path}" terdeteksi!\nMau langsung diekstrak ke folder ini sekarang?`)) {
                    showToast('Mengekstrak file ZIP...', 'info');
                    fmApi('fm_extract', { path: zipRel }, function(res) {
                        if (res.success) {
                            showToast(res.message || 'File ZIP berhasil diekstrak!', 'success');
                            loadTree();
                        } else {
                            showToast(res.message || 'Gagal mengekstrak ZIP', 'error');
                        }
                    });
                }
            }, 300);
        }
    }

    // Upload
    function triggerUpload(dir) {
        currentUploadDir = dir || '';
        document.getElementById('ide-upload-input').click();
    }

    function triggerUploadFolder(dir) {
        currentUploadDir = dir || '';
        document.getElementById('ide-folder-input').click();
    }

    function handleDoUpload(input) {
        if (!input.files || input.files.length === 0) return;
        const entries = [];
        for (let i = 0; i < input.files.length; i++) {
            const f = input.files[i];
            entries.push({ file: f, path: f.webkitRelativePath || f.name });
        }
        batchUploadEntries({ files: entries }, currentUploadDir, () => loadTree());
        input.value = '';
    }

    function handleDoFolderUpload(input) {
        if (!input.files || input.files.length === 0) return;
        const entries = [];
        const dirSet = new Set();
        for (let i = 0; i < input.files.length; i++) {
            const f = input.files[i];
            const rel = f.webkitRelativePath || f.name;
            entries.push({ file: f, path: rel });
            const slashIdx = rel.lastIndexOf('/');
            if (slashIdx !== -1) {
                dirSet.add(rel.substring(0, slashIdx));
            }
        }
        batchUploadEntries({ files: entries, dirs: Array.from(dirSet) }, currentUploadDir, () => loadTree());
        input.value = '';
    }

    // ----------------------------------------------------
    // Layout, Panes & Status
    // ----------------------------------------------------
    function switchSidebar(view) {
        const btnExp = document.getElementById('btn-act-explorer');
        const btnSrc = document.getElementById('btn-act-search');
        const paneExp = document.getElementById('pane-explorer');
        const paneSrc = document.getElementById('pane-search');

        if (view === 'explorer') {
            btnExp.classList.add('active');
            btnSrc.classList.remove('active');
            paneExp.style.display = 'flex';
            paneSrc.style.display = 'none';
        } else {
            btnSrc.classList.add('active');
            btnExp.classList.remove('active');
            paneExp.style.display = 'none';
            paneSrc.style.display = 'flex';
            document.getElementById('global-search-input').focus();
        }
    }

    function toggleBottomPanel() {
        const panel = document.getElementById('bottom-panel');
        bottomPanelOpen = !bottomPanelOpen;
        panel.style.display = bottomPanelOpen ? 'flex' : 'none';
        if (editor) editor.layout();
    }
    function openBottomPanel() {
        const panel = document.getElementById('bottom-panel');
        bottomPanelOpen = true;
        panel.style.display = 'flex';
        if (editor) editor.layout();
    }

    function switchBottomTab(tab) {
        ['terminal', 'problems', 'output'].forEach(t => {
            const btn = document.getElementById('tab-btn-' + t);
            const pane = document.getElementById('pane-' + t);
            if (t === tab) {
                btn.classList.add('active');
                pane.classList.add('active');
            } else {
                btn.classList.remove('active');
                pane.classList.remove('active');
            }
        });
    }

    function clearActivePanel() {
        const termActive = document.getElementById('pane-terminal').classList.contains('active');
        const probActive = document.getElementById('pane-problems').classList.contains('active');
        if (termActive) document.getElementById('term-history').innerHTML = '';
        else if (probActive) document.getElementById('problems-content').innerHTML = '<div style="color:var(--ide-muted);padding:8px">Cleaned</div>';
        else document.getElementById('output-content').innerHTML = '';
    }

    function logOutput(msg) {
        const out = document.getElementById('output-content');
        const time = new Date().toTimeString().split(' ')[0];
        out.innerHTML += `<div>[${time}] ${escHtml(msg)}</div>`;
        out.scrollTop = out.scrollHeight;
    }

    function updateBreadcrumbs(path) {
        const parts = path.split('/');
        let html = '<span>public_html</span>';
        parts.forEach((p, idx) => {
            html += '<span class="sep">›</span>';
            if (idx === parts.length - 1) html += `<span class="current">${escHtml(p)}</span>`;
            else html += `<span>${escHtml(p)}</span>`;
        });
        document.getElementById('ide-breadcrumbs').innerHTML = html;
    }

    function updateStatusBar() {
        const tab = openTabs.find(t => t.path === activeTabPath);
        if (!tab) {
            document.getElementById('status-active-path').textContent = 'No open file';
            document.getElementById('status-lang').textContent = 'Plaintext';
            return;
        }

        document.getElementById('status-active-path').textContent = tab.path;
        document.getElementById('status-lang').textContent = tab.isImage ? 'Image' : (getLanguageFromExt(tab.ext).toUpperCase());
    }

    function updateCursorPosition(ln, col) {
        document.getElementById('status-cursor').textContent = `Ln ${ln}, Col ${col}`;
    }

    function setSaveState(text, revertTimeout) {
        const badge = document.getElementById('status-save-state');
        badge.textContent = text;
        if (revertTimeout) {
            setTimeout(() => { badge.textContent = '✓ Saved'; }, revertTimeout);
        }
    }

    function showToast(msg, type) {
        const t = document.getElementById('ide-toast');
        const icon = document.getElementById('ide-toast-icon');
        const m = document.getElementById('ide-toast-msg');
        icon.textContent = (type === 'success' ? '✓' : (type === 'error' ? '❌' : 'ℹ️'));
        m.textContent = msg;
        t.className = type || 'info';
        t.style.display = 'flex';
        setTimeout(() => { t.style.display = 'none'; }, 3000);
    }

    // Settings
    function openSettingsModal() {
        document.getElementById('settings-modal').style.display = 'flex';
    }
    function closeSettingsModal() {
        document.getElementById('settings-modal').style.display = 'none';
    }
    function applySetting(key, val) {
        localStorage.setItem('purucode_' + key.toLowerCase(), val);
        if (!editor) return;
        if (key === 'theme') monaco.editor.setTheme(val);
        else if (key === 'fontSize') editor.updateOptions({ fontSize: val });
        else if (key === 'wordWrap') editor.updateOptions({ wordWrap: val });
        else if (key === 'minimap') editor.updateOptions({ minimap: { enabled: val } });
    }

    // Resizing logic
    (function initResizers() {
        const sidebar = document.getElementById('ide-sidebar');
        const resizerV = document.getElementById('sidebar-resizer');
        let isResizingV = false;

        resizerV.addEventListener('mousedown', function(e) {
            isResizingV = true;
            resizerV.classList.add('dragging');
            document.body.style.cursor = 'col-resize';
        });

        const panel = document.getElementById('bottom-panel');
        const resizerH = document.getElementById('panel-resizer');
        let isResizingH = false;

        resizerH.addEventListener('mousedown', function(e) {
            isResizingH = true;
            resizerH.classList.add('dragging');
            document.body.style.cursor = 'row-resize';
        });

        window.addEventListener('mousemove', function(e) {
            if (isResizingV) {
                const newWidth = Math.max(180, Math.min(600, e.clientX - 48));
                sidebar.style.width = newWidth + 'px';
                if (editor) editor.layout();
            }
            if (isResizingH) {
                const newHeight = Math.max(120, Math.min(500, window.innerHeight - e.clientY - 24));
                panel.style.height = newHeight + 'px';
                if (editor) editor.layout();
            }
        });

        window.addEventListener('mouseup', function() {
            if (isResizingV) {
                isResizingV = false;
                resizerV.classList.remove('dragging');
                document.body.style.cursor = 'default';
                if (editor) editor.layout();
            }
            if (isResizingH) {
                isResizingH = false;
                resizerH.classList.remove('dragging');
                document.body.style.cursor = 'default';
                if (editor) editor.layout();
            }
        });
    })();

    // Language Helper
    function getLanguageFromExt(ext) {
        const map = {
            'php': 'php',
            'js': 'javascript',
            'mjs': 'javascript',
            'ts': 'typescript',
            'json': 'json',
            'html': 'html',
            'htm': 'html',
            'css': 'css',
            'scss': 'scss',
            'less': 'less',
            'sql': 'sql',
            'md': 'markdown',
            'markdown': 'markdown',
            'xml': 'xml',
            'svg': 'xml',
            'yaml': 'yaml',
            'yml': 'yaml',
            'sh': 'shell',
            'bash': 'shell',
            'conf': 'ini',
            'ini': 'ini',
            'env': 'ini',
            'htaccess': 'ini',
            'py': 'python',
            'c': 'c',
            'cpp': 'cpp'
        };
        return map[ext] || 'plaintext';
    }

    function isImageExt(ext) {
        return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp'].includes(ext);
    }

    function escHtml(s) {
        if (!s) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function escAttr(s) {
        if (!s) return '';
        return s.replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    // ============================================
    // ROOMMASTER THEME SWITCHER FOR PURUCODE
    // ============================================
    function toggleIdeTheme() {
        const cur = document.documentElement.getAttribute('data-theme') || 'dark';
        const next = (cur === 'dark') ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('puru_theme', next);
        localStorage.setItem('purucode_theme', next === 'dark' ? 'vs-dark' : 'vs');
        
        syncIdeThemeBtn(next);
        if (typeof monaco !== 'undefined' && monaco.editor) {
            monaco.editor.setTheme(next === 'dark' ? 'vs-dark' : 'vs');
        }
    }

    function syncIdeThemeBtn(t) {
        const icon = document.getElementById('ide_theme_icon');
        const text = document.getElementById('ide_theme_text');
        if (!icon || !text) return;
        if (t === 'light') {
            icon.innerText = '🌙';
            text.innerText = 'Dark';
        } else {
            icon.innerText = '☀️';
            text.innerText = 'Light';
        }
    }
    syncIdeThemeBtn(localStorage.getItem('puru_theme') || 'dark');
    
    </script>
</body>
</html>
<?php
    exit;
}
// REVERSE PROXY PAGE
// ============================================
if ($action === 'proxy') {
    $proxies = $vhost->getProxySites();
    $wildcard = $vhost->getSetting('wildcard_domain') ?: 'purujekuto.my.id';
    
    echo renderHeader('Reverse Proxy', $vhost);
    ?>
    <div class="page-header-rm">
        <div>
            <div class="page-eyebrow">
                <span class="dot"></span>
                <span>Traffic Routing &bull; Reverse Proxy</span>
            </div>
            <h1 class="page-title">Reverse Proxy Routes</h1>
            <p class="page-subtitle">Forward wildcard subdomain traffic directly to local background services & ports</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="openModal('addProxyModal')">+ Add Proxy Route</button>
        </div>
    </div>

    <!-- Tip Callout -->
    <div style="background:var(--rm-card-subtle); border:1px solid var(--rm-border); padding:1.25rem 1.5rem; border-radius:18px; margin-bottom:2rem; display:flex; align-items:center; gap:1rem">
        <span style="font-size:1.5rem">💡</span>
        <div style="font-size:0.85rem; color:var(--rm-text-secondary)">
            Reverse Proxy allows you to route subdomains like <code>app.<?= htmlspecialchars($wildcard) ?></code> to internal ports like <code>http://localhost:8080</code> (Docker, Node.js, Python, Go, etc.) without writing manual Nginx configs.
        </div>
    </div>

    <?php if (empty($proxies)): ?>
        <div class="card" style="text-align:center; padding:4rem 2rem;">
            <div style="font-size:2.5rem; margin-bottom:1rem">🔀</div>
            <h3 style="font-family:'Newsreader', Georgia, serif; font-size:1.6rem; font-weight:600; margin-bottom:0.5rem">No proxy routes configured</h3>
            <p style="color:var(--rm-text-secondary); max-width:400px; margin:0 auto 1.5rem">Map a subdomain to your local backend application service</p>
            <button class="btn btn-primary" onclick="openModal('addProxyModal')">+ Add First Proxy Route</button>
        </div>
    <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:1.25rem;">
            <?php foreach ($proxies as $proxy): ?>
            <div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; padding:1.5rem 2rem">
                <div>
                    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.4rem">
                        <span style="font-weight:700; font-size:1.1rem; color:var(--rm-text)"><?= htmlspecialchars($proxy['domain']) ?></span>
                        <span style="color:var(--rm-cobalt); font-weight:800">&rarr;</span>
                        <span class="tag-pill" style="font-family:var(--font-mono); font-size:0.85rem; color:var(--rm-cobalt); background:var(--rm-card-subtle); border:1px solid var(--rm-border); font-weight:700; padding:2px 8px; border-radius:6px">
                            <?= htmlspecialchars($proxy['proxy_target']) ?>
                        </span>
                    </div>
                    <div style="display:flex; gap:0.5rem; align-items:center">
                        <span class="status-pill <?= $proxy['status'] === 'active' ? 'live' : 'disabled' ?>">
                            <span class="dot"></span> <?= ucfirst($proxy['status']) ?>
                        </span>
                        <?php if (!empty($proxy['proxy_websocket'])): ?>
                            <span class="tag-pill">🔌 WebSocket Enabled</span>
                        <?php endif; ?>
                        <span style="font-size:0.75rem; color:var(--rm-text-muted)">Created: <?= htmlspecialchars($proxy['created_at']) ?></span>
                    </div>
                </div>

                <div style="display:flex; gap:0.5rem">
                    <button class="btn btn-sm btn-white" onclick="apiCall('toggle_site',{id:<?= $proxy['id'] ?>},()=>location.reload())"><?= $proxy['status'] === 'active' ? '⏸ Disable' : '▶ Enable' ?></button>
                    <button class="btn btn-sm btn-danger" onclick="confirmDeleteProxy(<?= $proxy['id'] ?>, '<?= htmlspecialchars($proxy['domain']) ?>')">Delete</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Add Proxy Modal -->
    <!-- Add Proxy Modal -->
    <div id="addProxyModal" class="modal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('addProxyModal')">&times;</button>
            <h3 class="modal-title">Create Reverse Proxy Route</h3>
            <p class="modal-desc">
                Forward incoming traffic from a subdomain directly to an internal port or backend service.
            </p>
            <form onsubmit="submitCreateProxy(event)">
                <div class="form-group">
                    <label>Subdomain</label>
                    <div class="subdomain-input-wrap">
                        <input type="text" id="proxy_subdomain" placeholder="api" required pattern="[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?" title="Huruf, angka, tanda minus">
                        <span class="subdomain-suffix">.<?= htmlspecialchars($wildcard) ?></span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Target Backend URL</label>
                    <input type="text" id="proxy_target" placeholder="http://localhost:8080" required>
                </div>
                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer">
                        <input type="checkbox" id="proxy_websocket" value="1" style="width:auto">
                        <span>Support WebSocket (Upgrade Header)</span>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-white" onclick="closeModal('addProxyModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Proxy Route &rarr;</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function submitCreateProxy(e) {
        e.preventDefault();
        const sub = document.getElementById('proxy_subdomain').value.trim();
        const target = document.getElementById('proxy_target').value.trim();
        const ws = document.getElementById('proxy_websocket').checked ? 1 : 0;
        apiCall('create_proxy', { subdomain: sub, target: target, target_url: target, websocket: ws }, res => {
            if (res.success) {
                showToast(res.message);
                setTimeout(() => location.reload(), 800);
            } else {
                alert(res.message);
            }
        });
    }
    function confirmDeleteProxy(id, domain) {
        if (!confirm('Delete proxy route for ' + domain + '?')) return;
        apiCall('delete_site', { id: id, site_id: id }, res => {
            if (res.success) location.reload();
            else alert(res.message);
        });
    }
    </script>
    <?php
    echo renderFooter();
    exit;
}

if ($action === 'nginx') {
    $testResult = $vhost->getNginxTestResult();
    $vhostFiles = glob('/etc/nginx/purupanel-vhosts/*.conf') ?: [];
    $wildcard = $vhost->getSetting('wildcard_domain') ?: 'purujekuto.my.id';
    $panelName = $vhost->getSetting('panel_name') ?: 'PuruPanel';
    $maxSites = $vhost->getSetting('max_sites') ?: '50';
    $phpVersion = $vhost->getSetting('default_php_version') ?: '8.4';
    
    $googleClientId = $vhost->getSetting('google_client_id') ?: '';
    $googleClientSecret = $vhost->getSetting('google_client_secret') ?: '';
    
    echo renderHeader('Settings & Engine', $vhost);
    ?>
    <div class="page-header-rm">
        <div>
            <div class="page-eyebrow">
                <span class="dot"></span>
                <span>Settings & Engine Telemetry</span>
            </div>
            <h1 class="page-title">Platform Configuration</h1>
            <p class="page-subtitle">Configure host wildcards, Google OAuth SSO, Nginx test harnesses, and environment parameters</p>
        </div>
    </div>

    <!-- Google Single Sign-On (SSO) OAuth 2.0 Configuration -->
    <div class="card" style="margin-bottom:2rem">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem">
            <div>
                <h3 style="font-family:'Newsreader', Georgia, serif; font-size:1.35rem; font-weight:600; color:var(--rm-text); display:flex; align-items:center; gap:0.5rem">
                    <svg width="22" height="22" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                    Google Single Sign-On (OAuth 2.0)
                </h3>
                <p style="font-size:0.8rem; color:var(--rm-text-secondary); margin-top:0.25rem">
                    Izinkan login langsung 1-klik dengan akun Google untuk administrator dan tim
                </p>
            </div>
            <div>
                <?php if (!empty($googleClientId) && !empty($googleClientSecret)): ?>
                    <span class="status-pill live"><span class="dot"></span> OAuth 2.0 Active</span>
                <?php else: ?>
                    <span class="status-pill warning"><span class="dot"></span> Mock / Demo Mode</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- OAuth Callback URI Helper Box -->
        <div style="background:var(--rm-card-subtle); border:1px solid var(--rm-border-light); border-radius:14px; padding:1.25rem; margin-bottom:1.5rem">
            <div style="font-size:0.75rem; font-weight:700; color:var(--rm-text-muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.6rem">
                1. Authorized Redirect URI (Wajib dimasukkan di Google Cloud Console)
            </div>
            <div style="display:flex; flex-direction:column; gap:0.5rem; margin-bottom:0.75rem">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap">
                    <span style="font-size:0.8rem; color:var(--rm-text-secondary); width:130px; font-weight:600">IP Host STB:</span>
                    <code style="flex:1; font-family:var(--font-mono); font-size:0.82rem; color:var(--rm-cobalt); background:var(--rm-card); padding:0.4rem 0.8rem; border-radius:8px; border:1px solid var(--rm-border); word-break:break-all" id="oauth_uri_ip">http://192.168.60.105/?action=google_callback</code>
                    <button type="button" class="btn btn-sm btn-white" onclick="navigator.clipboard.writeText('http://192.168.60.105/?action=google_callback'); showToast('URI IP disalin!')">📋 Salin</button>
                </div>
                <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap">
                    <span style="font-size:0.8rem; color:var(--rm-text-secondary); width:130px; font-weight:600">Domain Publik:</span>
                    <code style="flex:1; font-family:var(--font-mono); font-size:0.82rem; color:var(--rm-cobalt); background:var(--rm-card); padding:0.4rem 0.8rem; border-radius:8px; border:1px solid var(--rm-border); word-break:break-all" id="oauth_uri_domain">https://<?= htmlspecialchars($wildcard) ?>/?action=google_callback</code>
                    <button type="button" class="btn btn-sm btn-white" onclick="navigator.clipboard.writeText('https://<?= htmlspecialchars($wildcard) ?>/?action=google_callback'); showToast('URI Domain disalin!')">📋 Salin</button>
                </div>
            </div>
            <div style="font-size:0.75rem; color:var(--rm-text-muted); line-height:1.5">
                💡 Masukkan kedua URL di atas ke dalam bagian <strong>Authorized redirect URIs</strong> saat membuat OAuth Client ID di Google Cloud Console.
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.5rem">
            <div class="form-group">
                <label>Google Client ID</label>
                <input type="text" id="set_google_client_id" value="<?= htmlspecialchars($googleClientId) ?>" placeholder="123456789-xxx.apps.googleusercontent.com">
            </div>
            <div class="form-group">
                <label>Google Client Secret</label>
                <input type="password" id="set_google_client_secret" value="<?= htmlspecialchars($googleClientSecret) ?>" placeholder="GOCSPX-xxxxxxxxxxxxxxxx">
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center">
            <button type="button" class="btn btn-primary" onclick="submitSaveSettings(event)">💾 Simpan Kredensial SSO &rarr;</button>
            <?php if (!empty($googleClientId) && !empty($googleClientSecret)): ?>
                <a href="?action=google_login" class="btn btn-sm btn-white" target="_blank">🚀 Test Google SSO &nearr;</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Nginx Health Banner -->
    <div class="card" style="margin-bottom:2rem; border-left:4px solid <?= $testResult['valid'] ? '#12B76A' : '#EF4444' ?>">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem">
            <div style="display:flex; align-items:center; gap:0.5rem">
                <span class="status-pill <?= $testResult['valid'] ? 'live' : 'warning' ?>">
                    <span class="dot"></span> <?= $testResult['valid'] ? 'Nginx Configuration Valid' : 'Syntax Error Detected' ?>
                </span>
            </div>
            <button class="btn btn-sm btn-white" onclick="apiCall('nginx_test', {}, res => alert(res.output))">Re-test Nginx</button>
        </div>
        <pre style="background:var(--rm-card-subtle); padding:1rem; border-radius:12px; font-family:var(--font-mono); font-size:0.78rem; color:var(--rm-text-secondary); border:1px solid var(--rm-border-light); overflow-x:auto"><?= htmlspecialchars($testResult['output']) ?></pre>
    </div>

    <!-- Settings Form -->
    <div class="card" style="margin-bottom:2rem">
        <h3 style="font-family:'Newsreader', Georgia, serif; font-size:1.35rem; font-weight:600; margin-bottom:1.25rem; color:var(--rm-text)">Panel Settings</h3>
        <form onsubmit="submitSaveSettings(event)">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem">
                <div class="form-group">
                    <label>Panel Brand Name</label>
                    <input type="text" id="set_panel_name" value="<?= htmlspecialchars($panelName) ?>" required>
                </div>
                <div class="form-group">
                    <label>Wildcard Base Domain</label>
                    <input type="text" id="set_wildcard_domain" value="<?= htmlspecialchars($wildcard) ?>" required>
                </div>
                <div class="form-group">
                    <label>Default PHP Version</label>
                    <select id="set_php_version">
                        <option value="8.4" <?= $phpVersion === '8.4' ? 'selected' : '' ?>>PHP 8.4 (Default)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Max Sites Allowed</label>
                    <input type="number" id="set_max_sites" value="<?= htmlspecialchars($maxSites) ?>" required min="1" max="500">
                </div>
            </div>
            <div style="margin-top:1.5rem">
                <button type="submit" class="btn btn-primary">Save Settings &rarr;</button>
            </div>
        </form>
    </div>

    <!-- Virtual Host Config Files -->
    <div class="card">
        <h3 style="font-family:'Newsreader', Georgia, serif; font-size:1.35rem; font-weight:600; margin-bottom:1.25rem; color:var(--rm-text)">Virtual Host Config Files</h3>
        <div style="display:flex; flex-direction:column; gap:0.5rem">
            <?php foreach ($vhostFiles as $file): 
                $name = basename($file);
                $size = filesize($file);
                $mtime = date('Y-m-d H:i', filemtime($file));
            ?>
                <div style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 1rem; background:var(--rm-card-subtle); border-radius:12px; border:1px solid var(--rm-border-light)">
                    <span style="font-family:var(--font-mono); font-size:0.85rem; font-weight:600; color:var(--rm-text)"><?= htmlspecialchars($name) ?></span>
                    <span style="font-size:0.75rem; color:var(--rm-text-muted)"><?= number_format($size / 1024, 1) ?> KB &bull; <?= $mtime ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    function submitSaveSettings(e) {
        if (e && e.preventDefault) e.preventDefault();
        const data = {
            panel_name: document.getElementById('set_panel_name') ? document.getElementById('set_panel_name').value.trim() : 'PuruPanel',
            wildcard_domain: document.getElementById('set_wildcard_domain') ? document.getElementById('set_wildcard_domain').value.trim() : '',
            default_php_version: document.getElementById('set_php_version') ? document.getElementById('set_php_version').value : '8.4',
            max_sites: document.getElementById('set_max_sites') ? document.getElementById('set_max_sites').value : '50',
            google_client_id: document.getElementById('set_google_client_id') ? document.getElementById('set_google_client_id').value.trim() : '',
            google_client_secret: document.getElementById('set_google_client_secret') ? document.getElementById('set_google_client_secret').value.trim() : ''
        };
        apiCall('update_settings', data, res => {
            if (res.success) {
                showToast('Pengaturan & SSO berhasil disimpan!');
                setTimeout(() => location.reload(), 800);
            } else {
                alert(res.message);
            }
        });
    }
    </script>
    <?php
    echo renderFooter();
    exit;
}