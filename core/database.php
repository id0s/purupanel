<?php
class Database {
    private static $instance = null;
    private $db;

    private function __construct() {
        $this->db = new SQLite3('/var/www/purupanel/core/panel.db');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->initTables();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getDb() {
        return $this->db;
    }

    private function initTables() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain TEXT NOT NULL UNIQUE,
                root_path TEXT NOT NULL,
                php_version TEXT DEFAULT '8.4',
                ssl_enabled INTEGER DEFAULT 0,
                ssl_cert_path TEXT,
                ssl_key_path TEXT,
                status TEXT DEFAULT 'active',
                framework TEXT DEFAULT 'generic',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS ssl_certificates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain TEXT NOT NULL,
                cert_path TEXT NOT NULL,
                key_path TEXT NOT NULL,
                fullchain_path TEXT,
                issuer TEXT,
                expires_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS panel_settings (
                key TEXT PRIMARY KEY,
                value TEXT
            );

            CREATE TABLE IF NOT EXISTS activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Ensure framework column exists
        $cols = [];
        $r = $this->db->query("PRAGMA table_info(sites)");
        while ($r && ($col = $r->fetchArray(SQLITE3_ASSOC))) {
            $cols[] = $col['name'];
        }
        if (!in_array('framework', $cols, true)) {
            $this->db->exec("ALTER TABLE sites ADD COLUMN framework TEXT DEFAULT 'generic'");
        }

        // Default settings
        $stmt = $this->db->prepare("INSERT OR IGNORE INTO panel_settings (key, value) VALUES (:key, :value)");
        $defaults = [
            'panel_name' => 'PuruPanel',
            'wildcard_domain' => 'purujekuto.my.id',
            'default_php_version' => '8.4',
            'nginx_user' => 'www-data',
            'sites_path' => '/var/www/purupanel/sites',
            'vhost_config_path' => '/etc/nginx/purupanel-vhosts',
            'ssl_path' => '/var/www/purupanel/ssl',
            'log_path' => '/var/www/purupanel/logs',
            'panel_port' => '80',
            'max_sites' => '50',
        ];
        foreach ($defaults as $k => $v) {
            $stmt->bindValue(':key', $k, SQLITE3_TEXT);
            $stmt->bindValue(':value', $v, SQLITE3_TEXT);
            $stmt->execute();
        }
    }
}
