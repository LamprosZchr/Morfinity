<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

final class ConfigurationException extends RuntimeException {}
final class UserInputException extends RuntimeException {}

/** Load a dotenv file without overwriting variables supplied by the host. */
function load_env(string $file, bool $required = false): void {
    if (!is_file($file) || !is_readable($file)) {
        if ($required) throw new ConfigurationException('The application environment file is missing or unreadable.');
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) throw new ConfigurationException('The application environment file could not be read.');

    foreach ($lines as $number => $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'export ')) $line = ltrim(substr($line, 7));
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/s', $line, $matches)) {
            throw new ConfigurationException('Invalid environment-file syntax on line ' . ($number + 1) . '.');
        }

        $key = $matches[1];
        if (getenv($key) !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER)) continue;
        $value = parse_env_value($matches[2], $number + 1);
        if (!putenv($key . '=' . $value)) throw new ConfigurationException('An environment variable could not be loaded.');
        $_ENV[$key] = $value;
    }
}

function parse_env_value(string $raw, int $lineNumber): string {
    $value = trim($raw);
    if ($value === '') return '';

    $quote = $value[0];
    if ($quote === '"' || $quote === "'") {
        $length = strlen($value); $end = null; $escaped = false;
        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $value[$i] === '\\' && !$escaped) { $escaped = true; continue; }
            if ($value[$i] === $quote && !$escaped) { $end = $i; break; }
            $escaped = false;
        }
        if ($end === null) throw new ConfigurationException("Unclosed quoted value on environment-file line $lineNumber.");
        $tail = trim(substr($value, $end + 1));
        if ($tail !== '' && !str_starts_with($tail, '#')) throw new ConfigurationException("Unexpected content on environment-file line $lineNumber.");
        $value = substr($value, 1, $end - 1);
        return $quote === '"' ? stripcslashes($value) : $value;
    }

    // For unquoted values, # starts a comment only when preceded by whitespace.
    $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
    return rtrim($value);
}

function env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function env_bool(string $key, bool $default = false): bool {
    $value = env($key);
    if ($value === null) return $default;
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

$environment = strtolower((string)env('APP_ENV', 'production'));
load_env(ROOT . '/.env', $environment === 'production');
$environment = strtolower((string)env('APP_ENV', 'production'));
$debug = env_bool('APP_DEBUG', false) && $environment !== 'production';

ensure_runtime_directories();
date_default_timezone_set((string)env('APP_TIMEZONE', 'Europe/Nicosia'));
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
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

function ensure_runtime_directories(): void {
    foreach ([ROOT . '/storage/logs', ROOT . '/storage/private', ROOT . '/uploads'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ConfigurationException('A required runtime directory could not be created.');
        }
    }
}

function is_https(): bool {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;

    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($forwardedProto === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    if (strtolower((string)($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')) === 'on') return true;

    $cfVisitor = json_decode((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
    return is_array($cfVisitor) && strtolower((string)($cfVisitor['scheme'] ?? '')) === 'https';
}

function required_db_config(): array {
    $keys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS']; $config = []; $missing = [];
    foreach ($keys as $key) {
        $value = env($key);
        if ($value === null || trim($value) === '') $missing[] = $key;
        else $config[$key] = $value;
    }
    if ($missing) throw new ConfigurationException('Missing required database configuration: ' . implode(', ', $missing) . '.');
    if (!ctype_digit($config['DB_PORT']) || (int)$config['DB_PORT'] < 1 || (int)$config['DB_PORT'] > 65535) {
        throw new ConfigurationException('DB_PORT must be a valid TCP port.');
    }
    return $config;
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $config = required_db_config();
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['DB_HOST'], (int)$config['DB_PORT'], $config['DB_NAME']);
    $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function app_url(): string {
    $configured = trim((string)env('APP_URL', ''));
    if ($configured === '') throw new ConfigurationException('APP_URL is required.');
    $parts = parse_url($configured);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
        throw new ConfigurationException('APP_URL must be a valid HTTP or HTTPS URL.');
    }
    return rtrim($configured, '/');
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = ''): string { return app_url() . '/' . ltrim($path, '/'); }
function redirect(string $path): never {
    $target = $path;
    if (preg_match('#^https?://#i', $path)) {
        $targetParts = parse_url($path); $appParts = parse_url(app_url());
        $invalid = !is_array($targetParts)
            || strcasecmp((string)($targetParts['host'] ?? ''), (string)($appParts['host'] ?? '')) !== 0
            || strcasecmp((string)($targetParts['scheme'] ?? ''), (string)($appParts['scheme'] ?? '')) !== 0
            || isset($targetParts['user']) || isset($targetParts['pass']);
        if ($invalid) $target = '/';
    } else {
        $target = url('/' . ltrim($path, '/'));
    }
    header('Location: ' . $target, true, 302); exit;
}
function validation_redirect_target(): string {
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer === '') return '/';
    $parts = parse_url($referer); $app = parse_url(app_url());
    if (!is_array($parts) || strcasecmp((string)($parts['host'] ?? ''), (string)($app['host'] ?? '')) !== 0) return '/';
    return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
function debug_enabled(): bool { return env_bool('APP_DEBUG', false) && strtolower((string)env('APP_ENV', 'production')) !== 'production'; }
function safe_exception_message(Throwable $exception): string {
    if (!debug_enabled()) return 'We could not complete that request. Please try again later.';
    $message = preg_replace('/(?:password|passwd|pwd)\s*[=:]\s*\S+/i', 'password=[redacted]', $exception->getMessage()) ?? 'Unexpected error';
    $message = str_replace([ROOT, str_replace('\\', '/', ROOT)], '[project]', $message);
    return get_debug_type($exception) . ': ' . $message;
}
function log_exception(Throwable $exception): void {
    error_log(sprintf('[%s] %s: %s in %s:%d', date('c'), get_debug_type($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
}
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) throw new UserInputException('Your session expired. Refresh the page and try again.'); }
function flash(string $type, string $message): void { $_SESSION['flash'][] = compact('type', 'message'); }
function old(string $key, string $default = ''): string { return e($_SESSION['old'][$key] ?? $default); }
function set_old(array $data): void { $_SESSION['old'] = $data; }
function clear_old(): void { unset($_SESSION['old']); }

function query_all(string $sql, array $params = []): array { $s = db()->prepare($sql); $s->execute($params); return $s->fetchAll(); }
function query_one(string $sql, array $params = []): array|false { $s = db()->prepare($sql); $s->execute($params); return $s->fetch(); }
function execute(string $sql, array $params = []): bool { $s = db()->prepare($sql); return $s->execute($params); }
function setting(string $key, string $default = ''): string {
    static $settings;
    if ($settings === null) { $settings = array_column(query_all('SELECT setting_value, setting_key FROM settings'), 'setting_value', 'setting_key'); }
    return $settings[$key] ?? $default;
}
function render(string $view, array $data = [], int $status = 200): void { http_response_code($status); extract($data, EXTR_SKIP); $pageTitle = $title ?? 'MORFINITY'; ob_start(); require ROOT . '/app/views/' . $view . '.php'; $content = ob_get_clean(); require ROOT . '/app/views/layout.php'; }
function is_admin(): bool { return isset($_SESSION['admin_id']); }
function require_admin(): void { if (!is_admin()) { flash('error', 'Please sign in.'); redirect('/admin/login'); } }
function slugify(string $text): string { $text = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text) ?? '', '-')); return $text ?: bin2hex(random_bytes(4)); }
function money(float|string $amount): string { return setting('currency_symbol', '€') . number_format((float)$amount, 2); }
function cart_count(): int { return array_sum(array_map('intval', $_SESSION['cart'] ?? [])); }
function cart_details(): array {
    $cart = $_SESSION['cart'] ?? []; if (!$cart) return ['items' => [], 'subtotal' => 0.0];
    $ids = array_keys($cart); $marks = implode(',', array_fill(0, count($ids), '?'));
    $products = query_all("SELECT p.*, b.name brand_name FROM products p LEFT JOIN brands b ON b.id=p.brand_id WHERE p.id IN ($marks) AND p.status='active'", $ids); $items = []; $subtotal = 0.0;
    foreach ($products as $p) { $qty = max(1, (int)$cart[$p['id']]); $price = $p['sale_price'] ?: $p['price']; $p['quantity'] = $qty; $p['unit_price'] = (float)$price; $p['line_total'] = $qty * (float)$price; $subtotal += $p['line_total']; $items[] = $p; }
    return compact('items', 'subtotal');
}
function validate_upload(string $field, string $folder, bool $private = false): ?string {
    if (empty($_FILES[$field]['tmp_name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK || $_FILES[$field]['size'] > 5 * 1024 * 1024) throw new UserInputException('Image upload failed or exceeds 5 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']); $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($types[$mime])) throw new UserInputException('Only JPG, PNG, and WebP images are allowed.');
    $base = $private ? ROOT . '/storage/private' : ROOT . '/uploads'; $dir = $base . '/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Upload directory is unavailable.');
    $name = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], "$dir/$name")) throw new RuntimeException('Could not store the uploaded image.');
    return ($private ? 'private://' : '/uploads/') . $folder . '/' . $name;
}

require_once ROOT . '/app/auth.php';
require_once ROOT . '/app/stripe.php';
