<?php
declare(strict_types=1);

// IMPORTANT: no spaces/BOM before <?php

if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return strpos($haystack, $needle) !== false;
  }
}
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return strpos($haystack, $needle) === 0;
  }
}

$config = require __DIR__ . '/config.php';

/**
 * Detect base URL automatically:
 * - If project is in C:\xampp\htdocs\sspm  -> base_url = /sspm
 * - If project is in C:\xampp\htdocs      -> base_url = (empty)
 */
function detect_base_url(): string {
  $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
  $projectRoot = realpath(__DIR__ . '/..') ?: '';

  $docRoot = str_replace('\\', '/', $docRoot);
  $projectRoot = str_replace('\\', '/', $projectRoot);

  if ($docRoot !== '' && $projectRoot !== '' && str_starts_with($projectRoot, $docRoot)) {
    $rel = substr($projectRoot, strlen($docRoot)); // like "/sspm"
    $rel = str_replace('\\', '/', $rel);
    $rel = rtrim($rel, '/');
    return $rel; // "" or "/sspm"
  }
  return ''; // fallback
}

define('BASE_URL', rtrim((string)($config['app']['base_url'] ?? ''), '/'));
if (BASE_URL === '') {
  define('AUTO_BASE_URL', detect_base_url());
} else {
  define('AUTO_BASE_URL', BASE_URL);
}


function request_is_https(): bool {
  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
  if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
  if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && str_contains(strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']), 'https')) return true;
  if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') return true;
  return false;
}

function should_force_https(): bool {
  return (bool)($GLOBALS['config']['app']['force_https'] ?? false);
}

if (should_force_https() && !request_is_https() && PHP_SAPI !== 'cli') {
  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  if (!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host)) {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://' . $host . $uri, true, 301);
    exit;
  }
}

/** Build absolute URL within this project */
function url(string $path = ''): string {
  $base = AUTO_BASE_URL;
  $path = ltrim($path, '/');

  if ($path !== '' && !str_starts_with($path, 'admin/')) {
    $path = preg_replace('/\.php(?=($|[?#]))/i', '', $path) ?? $path;
  }

  $path = '/' . $path;
  if ($base === '' || $base === '/') return $path;
  return $base . $path;
}

/** Escape HTML */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }


/** Current absolute URL for canonical tags */
function current_url(): string {
  $scheme = request_is_https() ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $uri = $_SERVER['REQUEST_URI'] ?? url('/');
  return $scheme . '://' . $host . $uri;
}

/** Normalize user-provided image paths */
function normalize_image_path(string $path): string {
  $path = trim($path);
  if ($path === '') return '';

  if (preg_match('~^https?://~i', $path)) return $path;
  if (str_starts_with($path, '/')) return $path;

  if (str_starts_with($path, 'assets/')) {
    return url($path);
  }

  return url('assets/news/' . ltrim($path, '/'));
}

/**
 * Save uploaded image and return relative path like: assets/news/abc123.jpg
 * Returns '' if no file uploaded.
 * Throws RuntimeException on upload/validation errors.
 */
function handle_image_upload(string $field = 'image', string $subDir = 'assets/news'): string {
  if (!isset($_FILES[$field])) {
    return '';
  }

  $f = $_FILES[$field];

  // No file selected
  if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return '';
  }

  $err = (int)($f['error'] ?? UPLOAD_ERR_OK);
  if ($err !== UPLOAD_ERR_OK) {
    $map = [
      UPLOAD_ERR_INI_SIZE   => 'Uploaded image exceeds upload_max_filesize',
      UPLOAD_ERR_FORM_SIZE  => 'Uploaded image exceeds MAX_FILE_SIZE',
      UPLOAD_ERR_PARTIAL    => 'Image upload was interrupted (partial upload)',
      UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload folder is missing',
      UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded image',
      UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension',
    ];
    $msg = $map[$err] ?? ('Image upload failed (error code: ' . $err . ')');
    throw new RuntimeException($msg);
  }

  $tmp = (string)($f['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    throw new RuntimeException('Invalid uploaded file');
  }

  // Validate real image
  $imgInfo = @getimagesize($tmp);
  if ($imgInfo === false) {
    throw new RuntimeException('Uploaded file is not a valid image');
  }

  $mime = (string)($imgInfo['mime'] ?? '');
  $extMap = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
  ];

  if (!isset($extMap[$mime])) {
    throw new RuntimeException('Unsupported image type. Allowed: JPG, PNG, GIF, WEBP');
  }

  // Max 10 MB
  $size = (int)($f['size'] ?? 0);
  if ($size <= 0 || $size > 10 * 1024 * 1024) {
    throw new RuntimeException('Image size must be between 1 byte and 10 MB');
  }

  $ext = $extMap[$mime];
  $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

  $relativeDir = trim(str_replace('\\', '/', $subDir), '/'); // assets/news
  $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;      // projectRoot/assets/news

  if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0777, true)) {
    throw new RuntimeException('Failed to create upload directory: ' . $absoluteDir);
  }

  if (!is_writable($absoluteDir)) {
    throw new RuntimeException('Upload directory is not writable: ' . $absoluteDir);
  }

  $absolutePath = $absoluteDir . '/' . $filename;

  if (!move_uploaded_file($tmp, $absolutePath)) {
    throw new RuntimeException('Failed to move uploaded image');
  }

  // Store RELATIVE path in DB (recommended)
  return $relativeDir . '/' . $filename;
}

/**
 * Resolve image path from uploaded file or manual text path.
 * Priority: upload > manual path
 */
function resolve_image_input(string $uploadField = 'image', string $manualField = 'image_path', string $subDir = 'assets/news'): string {
  $manual = trim((string)($_POST[$manualField] ?? ''));
  $uploaded = handle_image_upload($uploadField, $subDir);
  return $uploaded !== '' ? $uploaded : $manual;
}

/** CSRF */
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['_csrf'];
}
function csrf_verify() {
  $ok = isset($_POST['_csrf'], $_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], (string)$_POST['_csrf']);
  if (!$ok) {
    http_response_code(419);
    exit('CSRF token mismatch');
  }
}

/** Start session BEFORE any output */
if (session_status() !== PHP_SESSION_ACTIVE) {
  if (headers_sent($file, $line)) {
    http_response_code(500);
    exit("Headers already sent in: {$file} on line {$line}. Make sure every page loads bootstrap.php BEFORE header.php.");
  }
  session_name($config['app']['session_name'] ?? 'SPGSESSID');
  $secureCookie = request_is_https();
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'domain' => '',
      'secure' => $secureCookie,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  } else {
    // PHP < 7.3 has no array options support for SameSite.
    session_set_cookie_params(0, '/; samesite=Lax', '', $secureCookie, true);
  }
  session_start();
}

function fallback_sqlite_pdo(): PDO {
  static $sqlite = null;
  if ($sqlite instanceof PDO) return $sqlite;

  $candidates = [
    __DIR__ . '/../var/spg_fallback.sqlite',
    sys_get_temp_dir() . '/spg_fallback.sqlite',
  ];

  $lastEx = null;
  foreach ($candidates as $dbFile) {
    try {
      $dir = dirname($dbFile);
      if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
      }
      $sqlite = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      break;
    } catch (Throwable $e) {
      $lastEx = $e;
      $sqlite = null;
    }
  }

  if (!$sqlite instanceof PDO) {
    // last-resort runtime fallback to keep site alive even on read-only hosting
    $sqlite = new PDO('sqlite::memory:', null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }

  $schema = [
    "CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, created_at TEXT)",
    "CREATE TABLE IF NOT EXISTS admin_permissions (admin_id INTEGER NOT NULL, permission TEXT NOT NULL, PRIMARY KEY (admin_id, permission))",
    "CREATE TABLE IF NOT EXISTS news_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, category TEXT NOT NULL, title TEXT NOT NULL, excerpt TEXT NOT NULL, content TEXT, image_path TEXT NOT NULL, published_at TEXT NOT NULL, is_published INTEGER NOT NULL DEFAULT 1)",
    "CREATE TABLE IF NOT EXISTS news_gallery (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, image_path TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)",
    "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, lecturer_name TEXT, password_hash TEXT NOT NULL, created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS contact_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT, message TEXT NOT NULL, created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS membership_applications (id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT NOT NULL, last_name TEXT NOT NULL, personal_id TEXT NOT NULL, phone TEXT NOT NULL, university TEXT NOT NULL, faculty TEXT NOT NULL, email TEXT, additional_info TEXT, full_name TEXT, university_info TEXT, age TEXT, legal_address TEXT, desired_direction TEXT, motivation_text TEXT, created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS people_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, page_key TEXT NOT NULL, first_name TEXT NOT NULL, last_name TEXT NOT NULL, role_title TEXT, image_path TEXT, sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS user_courses (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, course_title TEXT NOT NULL, instructor TEXT, schedule_text TEXT, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS user_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, task_title TEXT NOT NULL, due_at TEXT, status TEXT NOT NULL DEFAULT 'todo', created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS user_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, message TEXT NOT NULL, level TEXT NOT NULL DEFAULT 'info', is_read INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS user_lecturers (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, lecturer_name TEXT NOT NULL, department TEXT, email TEXT, office_room TEXT, office_hours TEXT, created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS admin_login_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, admin_id INTEGER, ip_address TEXT, user_agent TEXT, status TEXT NOT NULL, reason TEXT, created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS admin_activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL, username TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER, details TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS partner_logos (id INTEGER PRIMARY KEY AUTOINCREMENT, image_path TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL)",
  ];
  foreach ($schema as $sql) {
    $sqlite->exec($sql);
  }

  $adminHash = '$2y$12$AwUYItlTmRoVCl7jWc/u1exQOUM0VoCO6K8jgHP3AlR3OkcM5YKnO';
  $stmt = $sqlite->prepare('INSERT OR IGNORE INTO admins (id, username, password_hash, created_at) VALUES (1, ?, ?, ?)');
  $stmt->execute(['admin', $adminHash, date('Y-m-d H:i:s')]);

  return $sqlite;
}

/** DB */
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $cfg = require __DIR__ . '/config.php';
  $db = $cfg['db'];
  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ];

  $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
  try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
    return $pdo;
  } catch (PDOException $e) {
    $msg = (string)$e->getMessage();
    $isUnknownDb = str_contains($msg, '1049') || stripos($msg, 'Unknown database') !== false;
    if ($isUnknownDb) {
      try {
        $serverDsn = "mysql:host={$db['host']};charset={$db['charset']}";
        $serverPdo = new PDO($serverDsn, $db['user'], $db['pass'], $options);
        $dbName = str_replace('`', '', (string)$db['name']);
        $charset = (string)$db['charset'];
        $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset}");
        $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
        return $pdo;
      } catch (Throwable $e2) {
        // fallback to sqlite below
      }
    }

    $pdo = fallback_sqlite_pdo();
    return $pdo;
  }
}

/** Admin auth */
function is_admin(): bool {
  return !empty($_SESSION['admin_id']);
}
function require_admin() {
  if (!is_admin()) {
    header('Location: ' . url('admin/login.php'));
    exit;
  }
}

function ensure_admin_permissions_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS admin_permissions (
      admin_id INT NOT NULL,
      permission VARCHAR(64) NOT NULL,
      PRIMARY KEY (admin_id, permission)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Backfill newly introduced permissions for existing admins
    db()->exec("INSERT IGNORE INTO admin_permissions (admin_id, permission)
      SELECT id, 'partners.manage' FROM admins");
    db()->exec("INSERT IGNORE INTO admin_permissions (admin_id, permission)
      SELECT id, 'admin.logs.view' FROM admins");
  } catch (Throwable $e) {
    // ignore if DB user lacks permissions
  }
}

function ensure_news_posts_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS news_posts (
      id INT AUTO_INCREMENT PRIMARY KEY,
      category VARCHAR(120) NOT NULL,
      title VARCHAR(255) NOT NULL,
      excerpt TEXT NOT NULL,
      content LONGTEXT DEFAULT NULL,
      image_path VARCHAR(255) NOT NULL,
      published_at DATETIME NOT NULL,
      is_published TINYINT(1) NOT NULL DEFAULT 1,
      INDEX idx_news_posts_published_at (published_at),
      INDEX idx_news_posts_is_published (is_published)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB user lacks permissions
  }
}

function ensure_news_gallery_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS news_gallery (
      id INT AUTO_INCREMENT PRIMARY KEY,
      post_id INT NOT NULL,
      image_path VARCHAR(255) NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      INDEX (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB user lacks permissions
  }
}

function available_admin_permissions(): array {
  return [
    'news.view' => 'View news list',
    'news.create' => 'Create news',
    'news.edit' => 'Edit news',
    'news.delete' => 'Delete news',
    'partners.manage' => 'Manage partners section logos',
    'people.manage' => 'Manage team members',
    'contact.view' => 'View contact submissions',
    'membership.view' => 'View membership applications',
    'university.manage' => 'Manage university system data',
    'admin.logs.view' => 'View admin login logs',
    'admins.manage' => 'Manage admins',
  ];
}

function admin_permissions_total_count(): int {
  static $count = null;
  if ($count !== null) return $count;
  ensure_admin_permissions_table();
  try {
    $stmt = db()->query("SELECT COUNT(*) AS c FROM admin_permissions");
    $count = (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    $count = 0;
  }
  return $count;
}

function admin_permissions(int $adminId): array {
  ensure_admin_permissions_table();
  try {
    $stmt = db()->prepare("SELECT permission FROM admin_permissions WHERE admin_id=?");
    $stmt->execute([$adminId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  } catch (Throwable $e) {
    return [];
  }
}

function has_permission(string $perm): bool {
  if (!is_admin()) return false;
  if ((string)($_SESSION['admin_username'] ?? '') === 'admin') return true;
  $adminId = (int)($_SESSION['admin_id'] ?? 0);
  $perms = admin_permissions($adminId);
  if (!$perms && admin_permissions_total_count() === 0) return true;
  return in_array($perm, $perms, true);
}

function require_permission(string $perm) {
  if (!has_permission($perm)) {
    http_response_code(403);
    exit('Access denied');
  }
}

function ensure_admin_login_logs_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS admin_login_logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(120) NOT NULL,
      admin_id INT DEFAULT NULL,
      ip_address VARCHAR(64) DEFAULT NULL,
      user_agent VARCHAR(255) DEFAULT NULL,
      status VARCHAR(24) NOT NULL,
      reason VARCHAR(190) DEFAULT NULL,
      created_at DATETIME NOT NULL,
      INDEX idx_admin_login_logs_created_at (created_at),
      INDEX idx_admin_login_logs_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}

function record_admin_login_log(string $username, $adminId, string $status, string $reason = '') {
  ensure_admin_login_logs_table();
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
  try {
    $stmt = db()->prepare('INSERT INTO admin_login_logs (username, admin_id, ip_address, user_agent, status, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
      $username,
      $adminId,
      $ip !== '' ? $ip : null,
      $ua !== '' ? $ua : null,
      $status,
      $reason !== '' ? $reason : null,
      date('Y-m-d H:i:s')
    ]);
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}



function ensure_admin_activity_logs_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS admin_activity_logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      admin_id INT NOT NULL,
      username VARCHAR(120) NOT NULL,
      action VARCHAR(64) NOT NULL,
      entity_type VARCHAR(64) NOT NULL,
      entity_id INT DEFAULT NULL,
      details TEXT DEFAULT NULL,
      ip_address VARCHAR(64) DEFAULT NULL,
      user_agent VARCHAR(255) DEFAULT NULL,
      created_at DATETIME NOT NULL,
      INDEX idx_admin_activity_logs_created_at (created_at),
      INDEX idx_admin_activity_logs_admin_id (admin_id),
      INDEX idx_admin_activity_logs_entity_type (entity_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}

function record_admin_activity(string $action, string $entityType, ?int $entityId = null, string $details = ''): void {
  if (!is_admin()) return;
  ensure_admin_activity_logs_table();
  $adminId = (int)($_SESSION['admin_id'] ?? 0);
  $username = (string)($_SESSION['admin_username'] ?? 'admin');
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
  try {
    $stmt = db()->prepare('INSERT INTO admin_activity_logs (admin_id, username, action, entity_type, entity_id, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
      $adminId,
      $username,
      $action,
      $entityType,
      $entityId,
      $details !== '' ? $details : null,
      $ip !== '' ? $ip : null,
      $ua !== '' ? $ua : null,
      date('Y-m-d H:i:s'),
    ]);
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}

function safe_record_admin_activity(string $action, string $entityType, ?int $entityId = null, string $details = ''): void {
  try {
    record_admin_activity($action, $entityType, $entityId, $details);
  } catch (Throwable $e) {
    // do not break main flow
  }
}

function ensure_users_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      full_name VARCHAR(190) NOT NULL,
      email VARCHAR(190) NOT NULL UNIQUE,
      lecturer_name VARCHAR(190) DEFAULT NULL,
      password_hash VARCHAR(255) NOT NULL,
      created_at DATETIME NOT NULL,
      INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
      db()->exec("ALTER TABLE users ADD COLUMN lecturer_name VARCHAR(190) DEFAULT NULL AFTER email");
    } catch (Throwable $e2) {
      // already exists
    }
    try {
      db()->exec("ALTER TABLE users ADD INDEX idx_users_lecturer_name (lecturer_name)");
    } catch (Throwable $e3) {
      // already exists
    }
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}

function is_user_logged_in(): bool {
  return !empty($_SESSION['user_id']);
}

function user_login_allowed(): bool {
  $lockUntil = (int)($_SESSION['user_login_lock_until'] ?? 0);
  return $lockUntil <= time();
}

function user_login_lock_remaining(): int {
  $lockUntil = (int)($_SESSION['user_login_lock_until'] ?? 0);
  return max(0, $lockUntil - time());
}

function user_login_register_failure() {
  $fails = (int)($_SESSION['user_login_failures'] ?? 0) + 1;
  $_SESSION['user_login_failures'] = $fails;
  if ($fails >= 5) {
    $_SESSION['user_login_lock_until'] = time() + 300;
    $_SESSION['user_login_failures'] = 0;
  }
}

function user_login_register_success() {
  unset($_SESSION['user_login_failures'], $_SESSION['user_login_lock_until']);
}

function strong_password(string $password): bool {
  if (strlen($password) < 8) return false;
  if (!preg_match('/[A-Z]/', $password)) return false;
  if (!preg_match('/[a-z]/', $password)) return false;
  if (!preg_match('/\d/', $password)) return false;
  return true;
}

function current_user() {
  if (!is_user_logged_in()) return null;
  return [
    'id' => (int)($_SESSION['user_id'] ?? 0),
    'name' => (string)($_SESSION['user_name'] ?? ''),
    'email' => (string)($_SESSION['user_email'] ?? ''),
    'lecturer_name' => (string)($_SESSION['user_lecturer_name'] ?? ''),
  ];
}

function ensure_user_courses_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS user_courses (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      course_title VARCHAR(190) NOT NULL,
      instructor VARCHAR(190) DEFAULT NULL,
      schedule_text VARCHAR(190) DEFAULT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL,
      INDEX idx_user_courses_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}

function ensure_user_tasks_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS user_tasks (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      task_title VARCHAR(190) NOT NULL,
      due_at DATETIME DEFAULT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'todo',
      created_at DATETIME NOT NULL,
      INDEX idx_user_tasks_user_id (user_id),
      INDEX idx_user_tasks_due_at (due_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}

function ensure_user_notifications_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS user_notifications (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      message VARCHAR(255) NOT NULL,
      level VARCHAR(32) NOT NULL DEFAULT 'info',
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      INDEX idx_user_notifications_user_id (user_id),
      INDEX idx_user_notifications_is_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}

function ensure_user_lecturers_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS user_lecturers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      lecturer_name VARCHAR(190) NOT NULL,
      department VARCHAR(190) DEFAULT NULL,
      email VARCHAR(190) DEFAULT NULL,
      office_room VARCHAR(64) DEFAULT NULL,
      office_hours VARCHAR(190) DEFAULT NULL,
      created_at DATETIME NOT NULL,
      INDEX idx_user_lecturers_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}

function seed_user_dashboard_data($userId) {
  ensure_user_courses_table();
  ensure_user_tasks_table();
  ensure_user_notifications_table();
  ensure_user_lecturers_table();
  try {
    $stmt = db()->prepare('SELECT COUNT(*) FROM user_courses WHERE user_id=?');
    $stmt->execute([$userId]);
    $hasCourses = (int)$stmt->fetchColumn() > 0;
    if (!$hasCourses) {
      $now = date('Y-m-d H:i:s');
      $courseStmt = db()->prepare('INSERT INTO user_courses (user_id, course_title, instructor, schedule_text, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
      $courseStmt->execute([$userId, 'Academic Writing', 'Prof. N. Beridze', 'Mon / Wed 10:00', 'active', $now]);
      $courseStmt->execute([$userId, 'Computer Science Basics', 'Prof. G. Gogelia', 'Tue / Thu 13:00', 'active', $now]);

      $taskStmt = db()->prepare('INSERT INTO user_tasks (user_id, task_title, due_at, status, created_at) VALUES (?, ?, ?, ?, ?)');
      $taskStmt->execute([$userId, 'Submit assignment #2', date('Y-m-d H:i:s', strtotime('+4 days')), 'todo', $now]);
      $taskStmt->execute([$userId, 'Prepare lab report', date('Y-m-d H:i:s', strtotime('+7 days')), 'todo', $now]);

      $notifStmt = db()->prepare('INSERT INTO user_notifications (user_id, message, level, is_read, created_at) VALUES (?, ?, ?, 0, ?)');
      $notifStmt->execute([$userId, 'Welcome to the secure student dashboard.', 'success', $now]);
      $notifStmt->execute([$userId, 'Remember to complete your profile information.', 'info', $now]);

      $lecturerStmt = db()->prepare('INSERT INTO user_lecturers (user_id, lecturer_name, department, email, office_room, office_hours, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
      $lecturerStmt->execute([$userId, 'Prof. N. Beridze', 'Humanities', 'n.beridze@spg.local', 'B-204', 'Mon 12:00-14:00', $now]);
      $lecturerStmt->execute([$userId, 'Assoc. Prof. G. Gogelia', 'Computer Science', 'g.gogelia@spg.local', 'C-310', 'Thu 11:00-13:00', $now]);
    }
  } catch (Throwable $e) {
    // ignore if DB is unavailable
  }
}

function get_user_courses(int $userId): array {
  ensure_user_courses_table();
  try {
    $stmt = db()->prepare('SELECT course_title, instructor, schedule_text, status FROM user_courses WHERE user_id=? ORDER BY id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

function get_user_tasks(int $userId): array {
  ensure_user_tasks_table();
  try {
    $stmt = db()->prepare('SELECT task_title, due_at, status FROM user_tasks WHERE user_id=? ORDER BY (due_at IS NULL), due_at ASC, id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

function get_user_notifications(int $userId): array {
  ensure_user_notifications_table();
  try {
    $stmt = db()->prepare('SELECT id, message, level, is_read, created_at FROM user_notifications WHERE user_id=? ORDER BY created_at DESC, id DESC LIMIT 8');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

function get_user_lecturers(int $userId): array {
  ensure_user_lecturers_table();
  try {
    $stmt = db()->prepare('SELECT lecturer_name, department, email, office_room, office_hours FROM user_lecturers WHERE user_id=? ORDER BY id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

function normalize_lecturer_name(string $name): string {
  $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
  return mb_substr($name, 0, 190);
}

function list_available_lecturers(): array {
  ensure_user_lecturers_table();
  try {
    $rows = db()->query("SELECT DISTINCT lecturer_name FROM user_lecturers WHERE lecturer_name<>'' ORDER BY lecturer_name ASC")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
      $name = trim((string)($r['lecturer_name'] ?? ''));
      if ($name !== '') $out[] = $name;
    }
    return array_values($out);
  } catch (Throwable $e) {
    return [];
  }
}

function get_lecturer_students(string $lecturerName): array {
  ensure_users_table();
  $lecturerName = trim($lecturerName);
  if ($lecturerName === '') return [];
  try {
    $stmt = db()->prepare('SELECT id, full_name, email, created_at FROM users WHERE lecturer_name=? ORDER BY full_name ASC, id DESC');
    $stmt->execute([$lecturerName]);
    return $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

/** Helpers for news */
function fmt_date_dmY(string $datetime): string {
  $t = strtotime($datetime);
  return $t ? date('d.m.Y', $t) : '';
}

function get_news_posts(int $limit = 50): array {
  try {
    $stmt = db()->prepare("
    SELECT id, category, title, excerpt, image_path, published_at
    FROM news_posts
    WHERE is_published=1
    ORDER BY published_at DESC, id DESC
    LIMIT :lim
  ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }

  $out = [];
  foreach ($rows as $r) {
    $out[] = [
      'id' => (int)$r['id'],
      'cat' => (string)$r['category'],
      'date' => fmt_date_dmY((string)$r['published_at']),
      'title' => (string)$r['title'],
      'text' => (string)$r['excerpt'],
      'img' => normalize_image_path((string)$r['image_path']),
    ];
  }
  return $out;
}

function get_one_news(int $id) {
  try {
    $stmt = db()->prepare("SELECT * FROM news_posts WHERE id=? AND is_published=1 LIMIT 1");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
  } catch (Throwable $e) {
    return null;
  }
  if (!$r) return null;

  return [
    'id' => (int)$r['id'],
    'cat' => (string)$r['category'],
    'date' => fmt_date_dmY((string)$r['published_at']),
    'title' => (string)$r['title'],
    'text' => (string)$r['excerpt'],
    'content' => (string)($r['content'] ?? ''),
    'img' => normalize_image_path((string)$r['image_path']),
    'published_at' => (string)$r['published_at'],
  ];
}

function get_news_gallery(int $postId): array {
  ensure_news_gallery_table();
  try {
    $stmt = db()->prepare("SELECT id, image_path FROM news_gallery WHERE post_id=? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$postId]);
    $rows = $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }

  $out = [];
  foreach ($rows as $row) {
    $out[] = [
      'id' => (int)$row['id'],
      'path' => normalize_image_path((string)$row['image_path']),
    ];
  }
  return $out;
}


function ensure_partner_logos_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS partner_logos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      image_path VARCHAR(255) NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL,
      INDEX (sort_order),
      INDEX (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB user lacks permissions
  }
}

function get_partner_logos(int $limit = 30): array {
  ensure_partner_logos_table();
  try {
    $stmt = db()->prepare("SELECT id, image_path FROM partner_logos WHERE is_active=1 ORDER BY sort_order ASC, id DESC LIMIT :lim");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }

  $out = [];
  foreach ($rows as $row) {
    $out[] = [
      'id' => (int)$row['id'],
      'img' => normalize_image_path((string)$row['image_path']),
    ];
  }
  return $out;
}

function ensure_contact_messages_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS contact_messages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      email VARCHAR(190) NOT NULL,
      phone VARCHAR(50) DEFAULT NULL,
      message TEXT NOT NULL,
      created_at DATETIME NOT NULL,
      INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB user lacks permissions
  }
}

function ensure_membership_applications_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS membership_applications (
      id INT AUTO_INCREMENT PRIMARY KEY,
      first_name VARCHAR(120) NOT NULL,
      last_name VARCHAR(120) NOT NULL,
      personal_id VARCHAR(30) NOT NULL,
      phone VARCHAR(50) NOT NULL,
      university VARCHAR(190) NOT NULL,
      faculty VARCHAR(190) NOT NULL,
      email VARCHAR(190) DEFAULT NULL,
      additional_info TEXT DEFAULT NULL,
      full_name VARCHAR(190) DEFAULT NULL,
      university_info VARCHAR(255) DEFAULT NULL,
      age VARCHAR(20) DEFAULT NULL,
      legal_address VARCHAR(255) DEFAULT NULL,
      desired_direction VARCHAR(190) DEFAULT NULL,
      motivation_text TEXT DEFAULT NULL,
      is_called TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Backward-safe column ensures for existing installs
    foreach ([
      "ALTER TABLE membership_applications ADD COLUMN full_name VARCHAR(190) DEFAULT NULL",
      "ALTER TABLE membership_applications ADD COLUMN university_info VARCHAR(255) DEFAULT NULL",
      "ALTER TABLE membership_applications ADD COLUMN age VARCHAR(20) DEFAULT NULL",
      "ALTER TABLE membership_applications ADD COLUMN legal_address VARCHAR(255) DEFAULT NULL",
      "ALTER TABLE membership_applications ADD COLUMN desired_direction VARCHAR(190) DEFAULT NULL",
      "ALTER TABLE membership_applications ADD COLUMN motivation_text TEXT DEFAULT NULL",
      "ALTER TABLE membership_applications ADD COLUMN is_called TINYINT(1) NOT NULL DEFAULT 0"
    ] as $alterSql) {
      try {
        db()->exec($alterSql);
      } catch (Throwable $e2) {
        // ignore when column already exists / permission denied
      }
    }
  } catch (Throwable $e) {
    // ignore if DB user lacks permissions
  }
}

function ensure_people_profiles_table() {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS people_profiles (
      id INT AUTO_INCREMENT PRIMARY KEY,
      page_key VARCHAR(64) NOT NULL,
      first_name VARCHAR(120) NOT NULL,
      last_name VARCHAR(120) NOT NULL,
      role_title VARCHAR(180) DEFAULT NULL,
      image_path VARCHAR(255) DEFAULT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      INDEX (page_key),
      INDEX (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // ignore if DB user lacks permissions
  }
}

function people_page_labels(): array {
  return [
    'pr-event' => 'PR & EVENT',
    'aparati' => 'აპარატი',
    'parlament' => 'სტუდენტური პარლამენტი',
    'gov' => 'სტუდენტური მთავრობა',
  ];
}

function get_people_by_page(string $pageKey): array {
  ensure_people_profiles_table();
  try {
    $stmt = db()->prepare("SELECT first_name, last_name, role_title, image_path
      FROM people_profiles
      WHERE page_key=?
      ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$pageKey]);
    $rows = $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }

  $out = [];
  foreach ($rows as $row) {
    $fullName = trim(((string)$row['first_name']) . ' ' . ((string)$row['last_name']));
    $out[] = [
      'image' => normalize_image_path((string)($row['image_path'] ?? '')),
      'name' => $fullName,
      'position' => (string)($row['role_title'] ?? ''),
    ];
  }
  return $out;
}
