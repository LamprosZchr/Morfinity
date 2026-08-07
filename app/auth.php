<?php
declare(strict_types=1);

function current_user(): array|false {
    static $loaded = false, $user = false;
    if ($loaded) return $user;
    $loaded = true;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id > 0) $user = query_one('SELECT * FROM users WHERE id=? AND status=?', [$id, 'active']);
    return $user;
}

function is_user_signed_in(): bool { return current_user() !== false; }

function require_user(): array {
    $user = current_user();
    if (!$user) {
        $_SESSION['return_to'] = validation_redirect_target();
        flash('error', 'Sign in to continue.');
        redirect('/account/login');
    }
    return $user;
}

function sign_in_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
}

function sign_out_user(): void {
    unset($_SESSION['user_id'], $_SESSION['return_to']);
    session_regenerate_id(true);
}

function safe_return_to(string $fallback = '/account'): string {
    $target = (string)($_SESSION['return_to'] ?? $fallback);
    unset($_SESSION['return_to']);
    return str_starts_with($target, '/') && !str_starts_with($target, '//') ? $target : $fallback;
}

function user_entitlements(int $userId): array {
    return query_all('SELECT * FROM user_entitlements WHERE user_id=? ORDER BY entitlement_key', [$userId]);
}

function user_has_entitlement(int $userId, string $key): bool {
    $row = query_one(
        "SELECT id FROM user_entitlements WHERE user_id=? AND entitlement_key=? AND status='active' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())",
        [$userId, $key]
    );
    return $row !== false;
}
