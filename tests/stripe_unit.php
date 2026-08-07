<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/stripe.php';

$failures = [];
function expect_true(bool $condition, string $label): void { global $failures; if (!$condition) $failures[] = $label; }

$payload = '{"id":"evt_test","type":"checkout.session.completed"}';
$secret = 'whsec_test_fixture';
$timestamp = time();
$signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
expect_true(stripe_verify_signature($payload, "t=$timestamp,v1=$signature", $secret), 'valid signature');
expect_true(!stripe_verify_signature($payload . 'x', "t=$timestamp,v1=$signature", $secret), 'tampered payload');
expect_true(!stripe_verify_signature($payload, 't=' . ($timestamp - 301) . ",v1=$signature", $secret), 'expired signature');
expect_true(stripe_event_user_id(['metadata'=>['morfinity_user_id'=>'42']]) === 42, 'metadata user id');
expect_true(stripe_event_user_id(['client_reference_id'=>'17']) === 17, 'client reference user id');
expect_true(stripe_subscription_id_from_invoice(['subscription'=>'sub_123']) === 'sub_123', 'legacy invoice subscription');
expect_true(stripe_subscription_id_from_invoice(['parent'=>['subscription_details'=>['subscription'=>'sub_456']]]) === 'sub_456', 'Basil invoice subscription');
expect_true(stripe_subscription_expires(['items'=>['data'=>[['current_period_end'=>2000000000]]]]) === 2000000000, 'Basil subscription period end');

if ($failures) { fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL); exit(1); }
echo "Stripe unit checks passed.\n";
