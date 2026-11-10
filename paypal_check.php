<?php
// TEMPORARY diagnostic for the PayPal keys. Admins only. Delete this file after use.
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/paypal.php';

$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Admins only. Sign in as an administrator first.');
}

// Show what the site actually read from .env. Secrets are masked.
function mask(string $v): string {
    if ($v === '') return '(EMPTY - not set in .env)';
    return substr($v, 0, 4) . str_repeat('*', max(0, strlen($v) - 8)) . substr($v, -4) . '  (length ' . strlen($v) . ')';
}
echo '<pre style="font:14px/1.6 monospace;padding:20px">';
echo "PAYPAL_ENV        = " . htmlspecialchars(PAYPAL_ENV) . "\n";
echo "PAYPAL_BASE_URL   = " . htmlspecialchars(PAYPAL_BASE_URL ?: '(empty - uses PayPal default for PAYPAL_ENV)') . "\n";
echo "Calling           = " . htmlspecialchars(paypal_base_url()) . "/v1/oauth2/token\n";
echo "PAYPAL_CLIENT_ID  = " . htmlspecialchars(mask(PAYPAL_CLIENT_ID)) . "\n";
echo "PAYPAL_SECRET     = " . htmlspecialchars(mask(PAYPAL_SECRET)) . "\n\n";

try {
    $res = paypal_request('POST', '/v1/oauth2/token', [
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET),
        'Content-Type: application/x-www-form-urlencoded',
    ], 'grant_type=client_credentials');
    echo "HTTP status       = " . $res['status'] . "\n";
    if ($res['status'] === 200 && !empty($res['data']['access_token'])) {
        echo "RESULT            = SUCCESS. The keys work and PayPal issued a token.\n";
    } else {
        echo "PayPal said       = " . htmlspecialchars(json_encode($res['data'])) . "\n";
        echo "\nMost likely causes:\n";
        echo " - PAYPAL_ENV is 'live' but the keys are from the SANDBOX app (or the reverse).\n";
        echo " - The keys were copied from a different app row, or the secret was regenerated.\n";
        echo " - A key is empty or was cut off when pasting.\n";
    }
} catch (RuntimeException $e) {
    echo "ERROR             = " . htmlspecialchars($e->getMessage()) . "\n";
}
echo '</pre>';
