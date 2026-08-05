<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$email = $argv[1] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Usage: php scripts/create-admin.php admin@example.com\n"); exit(1); }
echo "Password (input visible): "; $password = trim((string)fgets(STDIN));
if (strlen($password) < 12) { fwrite(STDERR, "Use at least 12 characters.\n"); exit(1); }
execute('INSERT INTO admins(email,password_hash,name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)', [strtolower($email), password_hash($password, PASSWORD_DEFAULT), 'Administrator']);
echo "Admin account ready.\n";

