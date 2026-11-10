<?php declare(strict_types=1);

require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/checkout_config.php';
require_once __DIR__ . '/include/save_order.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$userId = require_login();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body']);
    exit;
}

$orderId = trim((string) ($input['order_id'] ?? ''));
if ($orderId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing PayPal order ID']);
    exit;
}

// Save payment info in the session so save_order can pick it up
$_SESSION['checkout']['payment_method']  = 'paypal';
$_SESSION['checkout']['paypal_order_id'] = $orderId;
$_SESSION['checkout']['paypal_payer']    = (string) ($input['payer_name'] ?? '');
$_SESSION['checkout']['paid_at']         = date('Y-m-d H:i:s');

try {
    $mysqli   = db();
    $customer = get_customer($mysqli, $userId);
    $customerId = (int) $customer['customer_id'];

    save_order_from_checkout($mysqli, $userId, $customerId);

    echo json_encode(['success' => true, 'order_id' => $orderId]);

} catch (Throwable $e) {
    error_log('PayPal order save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save order.']);
}