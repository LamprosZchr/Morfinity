<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$checks = []; $failed = false;
function report_check(string $label, bool $passed, string $detail = ''): void {
    global $checks, $failed;
    $checks[] = [$label, $passed, $detail];
    if (!$passed) $failed = true;
}

report_check('PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
foreach (['pdo', 'pdo_mysql', 'fileinfo', 'session', 'json', 'curl'] as $extension) report_check("Extension: $extension", extension_loaded($extension));

$root = dirname(__DIR__);
report_check('.env exists and is readable', is_file($root . '/.env') && is_readable($root . '/.env'));

try {
    require $root . '/app/bootstrap.php';
    foreach (['APP_ENV', 'APP_URL', 'APP_TIMEZONE', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        $value = env($key);
        report_check("Environment: $key", $value !== null && trim($value) !== '');
    }
    foreach (['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_API_VERSION', 'STRIPE_CATALOG_TTL'] as $key) {
        $value = env($key);
        report_check("Stripe environment: $key", $value !== null && trim($value) !== '');
    }
    report_check('Stripe remains in Test mode', str_starts_with((string)env('STRIPE_SECRET_KEY', ''), 'sk_test_') && !env_bool('STRIPE_LIVE_MODE_ENABLED', false));
    foreach ([ROOT . '/storage/logs', ROOT . '/storage/private', ROOT . '/uploads'] as $directory) {
        report_check('Writable: ' . str_replace(ROOT, '.', $directory), is_dir($directory) && is_writable($directory));
    }
    try { db()->query('SELECT 1')->fetchColumn(); report_check('Database connectivity', true); }
    catch (Throwable) { report_check('Database connectivity', false, 'Connection failed; review server configuration and private logs.'); }
    foreach (['users','user_entitlements','stripe_webhook_events'] as $table) {
        try { db()->query("SELECT 1 FROM $table LIMIT 1"); report_check("Database table: $table", true); }
        catch (Throwable) { report_check("Database table: $table", false, 'Run database/migrations/20260807_stripe_accounts.sql.'); }
    }
} catch (Throwable $bootstrapError) {
    $detail = $bootstrapError instanceof ConfigurationException ? $bootstrapError->getMessage() : 'Bootstrap failed; inspect private PHP logs.';
    report_check('Application bootstrap', false, $detail);
}

foreach ($checks as [$label, $passed, $detail]) echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
echo $failed ? "Readiness check failed.\n" : "All readiness checks passed.\n";
exit($failed ? 1 : 0);
