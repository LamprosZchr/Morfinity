<?php
declare(strict_types=1);

const MORFINITY_ENTITLEMENT_KEY = 'studio_access';

function stripe_enabled(): bool {
    return trim((string)env('STRIPE_SECRET_KEY', '')) !== '';
}

function stripe_secret_key(): string {
    $key = trim((string)env('STRIPE_SECRET_KEY', ''));
    if ($key === '') throw new ConfigurationException('STRIPE_SECRET_KEY is required for Stripe payments.');
    if (!str_starts_with($key, 'sk_test_') && !env_bool('STRIPE_LIVE_MODE_ENABLED', false)) {
        throw new ConfigurationException('Live Stripe keys are disabled. Use a test key or explicitly enable live mode after approval.');
    }
    return $key;
}

function stripe_request(string $method, string $path, array $params = []): array {
    if (!function_exists('curl_init')) throw new ConfigurationException('The PHP cURL extension is required for Stripe.');
    $method = strtoupper($method);
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    if ($method === 'GET' && $params) $url .= '?' . http_build_query($params);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . stripe_secret_key(),
            'Stripe-Version: ' . (string)env('STRIPE_API_VERSION', '2025-06-30.basil'),
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    if ($method !== 'GET') curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false) throw new RuntimeException('Stripe connection failed: ' . $error);
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException('Stripe returned an invalid response.');
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Stripe request failed: ' . (string)($data['error']['message'] ?? 'unknown error'));
    }
    return $data;
}

function stripe_catalog(bool $force = false): array {
    if (!stripe_enabled()) return [];
    $ttl = max(30, (int)env('STRIPE_CATALOG_TTL', '300'));
    $cache = ROOT . '/storage/private/stripe-catalog.json';
    if (!$force && is_file($cache) && filemtime($cache) >= time() - $ttl) {
        $cached = json_decode((string)file_get_contents($cache), true);
        if (is_array($cached)) return $cached;
    }
    $catalog = []; $startingAfter = null;
    do {
        $params = ['active'=>'true', 'limit'=>100, 'expand'=>['data.product']];
        if ($startingAfter !== null) $params['starting_after'] = $startingAfter;
        $prices = stripe_request('GET', 'prices', $params);
        foreach ($prices['data'] ?? [] as $price) {
        $product = $price['product'] ?? null;
        if (!is_array($product) || empty($product['active']) || empty($price['active'])) continue;
        if (!isset($price['unit_amount']) || !is_int($price['unit_amount']) || $price['unit_amount'] < 1) continue;
        $key = (string)($price['metadata']['morfinity_entitlement_key'] ?? $product['metadata']['morfinity_entitlement_key'] ?? '');
        if ($key !== MORFINITY_ENTITLEMENT_KEY) continue;
        $catalog[] = [
            'price_id'=>(string)$price['id'], 'product_id'=>(string)$product['id'],
            'name'=>(string)$product['name'], 'description'=>(string)($product['description'] ?? ''),
            'images'=>array_values(array_filter($product['images'] ?? [])),
            'currency'=>strtoupper((string)$price['currency']), 'unit_amount'=>(int)($price['unit_amount'] ?? 0),
            'type'=>(string)$price['type'], 'recurring'=>$price['recurring'] ?? null,
            'entitlement_key'=>$key,
        ];
        }
        $last = end($prices['data']);
        $startingAfter = is_array($last) ? (string)($last['id'] ?? '') : '';
    } while (!empty($prices['has_more']) && $startingAfter !== '');
    usort($catalog, fn(array $a, array $b): int => [$a['unit_amount'],$a['name']] <=> [$b['unit_amount'],$b['name']]);
    $encoded = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) file_put_contents($cache, $encoded, LOCK_EX);
    return $catalog;
}

function stripe_catalog_price(string $priceId): array|false {
    foreach (stripe_catalog() as $price) if (hash_equals($price['price_id'], $priceId)) return $price;
    foreach (stripe_catalog(true) as $price) if (hash_equals($price['price_id'], $priceId)) return $price;
    return false;
}

function stripe_customer_for_user(array $user): string {
    if (!empty($user['stripe_customer_id'])) return (string)$user['stripe_customer_id'];
    $customer = stripe_request('POST', 'customers', [
        'email'=>$user['email'], 'name'=>$user['name'],
        'metadata'=>['morfinity_user_id'=>(string)$user['id']],
    ]);
    execute('UPDATE users SET stripe_customer_id=? WHERE id=? AND stripe_customer_id IS NULL', [$customer['id'], $user['id']]);
    $stored = query_one('SELECT stripe_customer_id FROM users WHERE id=?', [$user['id']]);
    return (string)($stored['stripe_customer_id'] ?? $customer['id']);
}

function stripe_create_checkout(array $user, array $price): string {
    $customer = stripe_customer_for_user($user);
    $metadata = ['morfinity_user_id'=>(string)$user['id'], 'morfinity_entitlement_key'=>$price['entitlement_key']];
    $params = [
        'mode'=>$price['type'] === 'recurring' ? 'subscription' : 'payment',
        'customer'=>$customer, 'client_reference_id'=>(string)$user['id'],
        'line_items'=>[['price'=>$price['price_id'], 'quantity'=>1]],
        'success_url'=>url('/account?checkout=success&session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url'=>url('/plans?checkout=cancelled'), 'metadata'=>$metadata,
        'allow_promotion_codes'=>'true',
    ];
    if ($price['type'] === 'recurring') $params['subscription_data'] = ['metadata'=>$metadata];
    else $params['payment_intent_data'] = ['metadata'=>$metadata];
    $session = stripe_request('POST', 'checkout/sessions', $params);
    if (empty($session['url'])) throw new RuntimeException('Stripe Checkout did not return a URL.');
    return (string)$session['url'];
}

function stripe_create_portal(array $user): string {
    $customer = stripe_customer_for_user($user);
    $session = stripe_request('POST', 'billing_portal/sessions', ['customer'=>$customer, 'return_url'=>url('/account')]);
    if (empty($session['url'])) throw new RuntimeException('Stripe Portal did not return a URL.');
    return (string)$session['url'];
}

function stripe_redirect(string $target): never {
    $parts = parse_url($target);
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowed = ($parts['scheme'] ?? '') === 'https' && ($host === 'checkout.stripe.com' || $host === 'billing.stripe.com');
    if (!$allowed) throw new RuntimeException('Stripe returned an unsafe redirect URL.');
    header('Location: ' . $target, true, 303); exit;
}

function stripe_verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool {
    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[$key][] = $value;
    }
    $timestamp = isset($parts['t'][0]) ? (int)$parts['t'][0] : 0;
    if ($timestamp < 1 || abs(time() - $timestamp) > $tolerance) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($parts['v1'] ?? [] as $signature) if (hash_equals($expected, $signature)) return true;
    return false;
}

function stripe_event_user_id(array $object): int {
    return (int)($object['metadata']['morfinity_user_id'] ?? $object['client_reference_id'] ?? 0);
}

function stripe_subscription_id_from_invoice(array $invoice): string {
    $value = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? '';
    return is_array($value) ? (string)($value['id'] ?? '') : (string)$value;
}

function stripe_subscription_expires(array $subscription): ?int {
    $value = $subscription['cancel_at'] ?? $subscription['current_period_end'] ?? $subscription['items']['data'][0]['current_period_end'] ?? 0;
    return (int)$value > 0 ? (int)$value : null;
}

function stripe_upsert_entitlement(int $userId, string $key, string $status, string $sourceType, string $sourceId, ?int $expires): void {
    if ($userId < 1 || $key !== MORFINITY_ENTITLEMENT_KEY) return;
    $expiresAt = $expires ? gmdate('Y-m-d H:i:s', $expires) : null;
    execute(
        'INSERT INTO user_entitlements(user_id,entitlement_key,status,source_type,source_id,expires_at) VALUES(?,?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE status=VALUES(status),source_type=VALUES(source_type),source_id=VALUES(source_id),expires_at=VALUES(expires_at),updated_at=UTC_TIMESTAMP()',
        [$userId,$key,$status,$sourceType,$sourceId,$expiresAt]
    );
}

function stripe_process_event(array $event): void {
    $type = (string)($event['type'] ?? '');
    $object = $event['data']['object'] ?? [];
    if (!is_array($object)) return;
    if (in_array($type, ['checkout.session.completed','checkout.session.async_payment_succeeded'], true)) {
        if (($object['payment_status'] ?? '') !== 'paid') return;
        $userId = stripe_event_user_id($object); $key = (string)($object['metadata']['morfinity_entitlement_key'] ?? '');
        $sourceType = ($object['mode'] ?? '') === 'subscription' ? 'subscription' : 'payment';
        $sourceId = (string)($object['subscription'] ?? $object['payment_intent'] ?? $object['id'] ?? '');
        stripe_upsert_entitlement($userId,$key,'active',$sourceType,$sourceId,null);
        return;
    }
    if (str_starts_with($type, 'customer.subscription.')) {
        $userId = stripe_event_user_id($object); $key = (string)($object['metadata']['morfinity_entitlement_key'] ?? '');
        $stripeStatus = (string)($object['status'] ?? '');
        $active = in_array($stripeStatus, ['active','trialing'], true);
        $expires = stripe_subscription_expires($object);
        stripe_upsert_entitlement($userId,$key,$active?'active':'inactive','subscription',(string)($object['id'] ?? ''),$expires);
        return;
    }
    if (in_array($type, ['invoice.paid','invoice.payment_failed'], true)) {
        $subscriptionId = stripe_subscription_id_from_invoice($object);
        if ($subscriptionId === '') return;
        $subscription = stripe_request('GET', 'subscriptions/' . rawurlencode($subscriptionId));
        $userId = stripe_event_user_id($subscription); $key = (string)($subscription['metadata']['morfinity_entitlement_key'] ?? '');
        $expires = stripe_subscription_expires($subscription);
        stripe_upsert_entitlement($userId,$key,$type==='invoice.paid'?'active':'inactive','subscription',$subscriptionId,$expires);
        return;
    }
    if ($type === 'charge.refunded' && (int)($object['amount_refunded'] ?? 0) >= (int)($object['amount'] ?? PHP_INT_MAX)) {
        $paymentIntent = (string)($object['payment_intent'] ?? '');
        if ($paymentIntent !== '') execute("UPDATE user_entitlements SET status='inactive',updated_at=UTC_TIMESTAMP() WHERE source_type='payment' AND source_id=?", [$paymentIntent]);
    }
}

function stripe_handle_webhook(): never {
    $payload = (string)file_get_contents('php://input');
    $signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    $secret = trim((string)env('STRIPE_WEBHOOK_SECRET', ''));
    if ($secret === '' || !stripe_verify_signature($payload, $signature, $secret)) {
        http_response_code(400); echo 'Invalid signature'; exit;
    }
    $event = json_decode($payload, true);
    if (!is_array($event) || empty($event['id'])) { http_response_code(400); echo 'Invalid event'; exit; }
    db()->beginTransaction();
    try {
        $insert = db()->prepare('INSERT IGNORE INTO stripe_webhook_events(event_id,event_type) VALUES(?,?)');
        $insert->execute([(string)$event['id'], (string)($event['type'] ?? '')]);
        if ($insert->rowCount() === 1) stripe_process_event($event);
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack(); log_exception($error); http_response_code(500); echo 'Webhook processing failed'; exit;
    }
    http_response_code(200); echo 'ok'; exit;
}
