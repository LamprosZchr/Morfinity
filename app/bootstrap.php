<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

function load_env(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false) putenv("$key=$value");
    }
}

load_env(ROOT . '/.env');
date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));
ini_set('display_errors', env('APP_DEBUG', 'false') === 'true' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT . '/storage/logs/php.log');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('morfinity_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Lax'
    ]);
    session_start();
}

function env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', 'localhost'), env('DB_PORT', '3306'), env('DB_NAME', 'morfinity'));
    $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = ''): string { return rtrim(env('APP_URL', ''), '/') . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path))); exit; }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419); render('error', ['title' => 'Session expired', 'message' => 'Refresh the page and try again.']); exit;
    }
}
function flash(string $type, string $message): void { $_SESSION['flash'][] = compact('type', 'message'); }
function old(string $key, string $default = ''): string { return e($_SESSION['old'][$key] ?? $default); }
function set_old(array $data): void { $_SESSION['old'] = $data; }
function clear_old(): void { unset($_SESSION['old']); }

function query_all(string $sql, array $params = []): array { $s = db()->prepare($sql); $s->execute($params); return $s->fetchAll(); }
function query_one(string $sql, array $params = []): array|false { $s = db()->prepare($sql); $s->execute($params); return $s->fetch(); }
function execute(string $sql, array $params = []): bool { $s = db()->prepare($sql); return $s->execute($params); }
function setting(string $key, string $default = ''): string {
    static $settings;
    if ($settings === null) {
        try { $settings = array_column(query_all('SELECT setting_value, setting_key FROM settings'), 'setting_value', 'setting_key'); }
        catch (Throwable) { $settings = []; }
    }
    return $settings[$key] ?? $default;
}

function render(string $view, array $data = [], int $status = 200): void {
    http_response_code($status); extract($data, EXTR_SKIP);
    $pageTitle = $title ?? 'MORFINITY';
    ob_start(); require ROOT . '/app/views/' . $view . '.php'; $content = ob_get_clean();
    require ROOT . '/app/views/layout.php';
}

function is_admin(): bool { return isset($_SESSION['admin_id']); }
function require_admin(): void { if (!is_admin()) { flash('error', 'Please sign in.'); redirect('/admin/login'); } }
function slugify(string $text): string { $text = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text) ?? '', '-')); return $text ?: bin2hex(random_bytes(4)); }
function money(float|string $amount): string { return setting('currency_symbol', '€') . number_format((float)$amount, 2); }
function cart_count(): int { return array_sum(array_map('intval', $_SESSION['cart'] ?? [])); }

function cart_details(): array {
    $cart = $_SESSION['cart'] ?? [];
    if (!$cart) return ['items' => [], 'subtotal' => 0.0];
    $ids = array_keys($cart); $marks = implode(',', array_fill(0, count($ids), '?'));
    $products = query_all("SELECT p.*, b.name brand_name FROM products p LEFT JOIN brands b ON b.id=p.brand_id WHERE p.id IN ($marks) AND p.status='active'", $ids);
    $items = []; $subtotal = 0.0;
    foreach ($products as $p) {
        $qty = max(1, (int)$cart[$p['id']]); $price = $p['sale_price'] ?: $p['price'];
        $p['quantity'] = $qty; $p['unit_price'] = (float)$price; $p['line_total'] = $qty * (float)$price;
        $subtotal += $p['line_total']; $items[] = $p;
    }
    return compact('items', 'subtotal');
}

function validate_upload(string $field, string $folder, bool $private = false): ?string {
    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK || $_FILES[$field]['size'] > 5 * 1024 * 1024) throw new RuntimeException('Image upload failed or exceeds 5 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($types[$mime])) throw new RuntimeException('Only JPG, PNG, and WebP images are allowed.');
    $base = $private ? ROOT . '/storage/private' : ROOT . '/public/uploads';
    $dir = $base . '/' . $folder; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], "$dir/$name")) throw new RuntimeException('Could not save image.');
    return ($private ? 'private://' : '/uploads/') . $folder . '/' . $name;
}
