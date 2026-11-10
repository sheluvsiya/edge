<?php
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/checkout_helpers.php';
require_once __DIR__ . '/include/paypal.php';
require_once __DIR__ . '/include/mailer.php';

require_login();
$customerId = (int)$_SESSION['auth_user']['customer_id'];
$userId     = (int)$_SESSION['auth_user']['user_id'];

$checkoutId = (int)($_GET['checkout'] ?? 0);
$token      = (string)($_GET['token'] ?? ''); // PayPal order ID
if ($checkoutId <= 0 || !preg_match('/^[A-Z0-9]{10,40}$/', $token)) {
    flash('error', 'That payment link is not valid.');
    redirect('cart.php');
}

// The checkout must belong to this customer.
$checkout = db_fetch_one('SELECT * FROM checkout WHERE checkout_id = ? AND user_id = ? LIMIT 1', [$checkoutId, $userId]);
if (!$checkout) {
    flash('error', 'We could not find that checkout.');
    redirect('cart.php');
}

// Already paid? (The customer may refresh this page.) Show their order instead of charging again.
$existing = db_fetch_one('SELECT order_id FROM `order` WHERE checkout_id = ? LIMIT 1', [$checkoutId]);
if ($existing) {
    redirect('myProfile.php?tab=orders&order=' . (int)$existing['order_id']);
}

try {
    // 1. Check PayPal's record matches OUR checkout before taking any money.
    $paypal = paypal_get_order($token);
    $unit   = $paypal['purchase_units'][0] ?? [];
    $expectedAmount = number_format((float)$checkout['total_amount'], 2, '.', '');
    if (($unit['reference_id'] ?? '') !== 'CHK-' . $checkoutId
        || ($unit['amount']['currency_code'] ?? '') !== PAYPAL_CURRENCY
        || ($unit['amount']['value'] ?? '') !== $expectedAmount) {
        error_log('[3D Edge PayPal] mismatch for checkout ' . $checkoutId . ' order ' . $token);
        flash('error', 'The payment did not match your order, so nothing was charged. Please try again.');
        redirect('checkout.php');
    }

    // 2. Take the money (or reuse a capture that already happened).
    $status = $paypal['status'] ?? '';
    if ($status === 'COMPLETED') {
        $captureId = (string)($unit['payments']['captures'][0]['id'] ?? '');
    } elseif ($status === 'APPROVED') {
        $captureId = paypal_capture_order($token);
    } else {
        flash('error', 'The payment was not approved on PayPal. You have not been charged.');
        redirect('checkout.php');
    }
    if ($captureId === '') {
        throw new RuntimeException('PayPal did not return a payment reference.');
    }
} catch (RuntimeException $e) {
    error_log('[3D Edge PayPal] return failed: ' . $e->getMessage());
    flash('error', $e->getMessage());
    redirect('checkout.php');
}

// 3. Record the order. If this fails after the capture, the capture ID is in the log to reconcile.
$cartId = (int)$checkout['cart_id'];
$items  = db_fetch_all('SELECT cart_item_id FROM cart_item WHERE cart_id = ?', [$cartId]);
$conn->begin_transaction();
try {
    $stmt = db_execute(
        "INSERT INTO `order` (customer_id, checkout_id, created_at, order_status) VALUES (?, ?, NOW(), 'paid')",
        [$customerId, $checkoutId]
    );
    $orderId = (int)$stmt->insert_id;

    $stmt = db_execute(
        "INSERT INTO payment (order_id, checkout_id, user_id, payment_method, transaction_ref, amount, payment_status, created_at)
         VALUES (?, ?, ?, 'paypal', ?, ?, 'paid', NOW())",
        [$orderId, $checkoutId, $userId, $captureId, $checkout['total_amount']]
    );
    $paymentId = (int)$stmt->insert_id;
    db_execute('UPDATE `order` SET payment_id = ? WHERE order_id = ?', [$paymentId, $orderId]);

    foreach ($items as $item) {
        db_execute('INSERT INTO order_item (order_id, cart_item_id, created_at) VALUES (?, ?, NOW())',
            [$orderId, (int)$item['cart_item_id']]);
    }
    // Close this cart. The next visit starts a new one; the order keeps its items.
    db_execute('UPDATE cart SET checked_out_at = NOW(), updated_at = NOW() WHERE cart_id = ?', [$cartId]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[3D Edge PayPal] PAID BUT NOT RECORDED. Checkout ' . $checkoutId . ', PayPal order ' . $token
        . ', capture ' . $captureId . ': ' . $e->getMessage());
    flash('error', 'Your payment went through, but we could not save the order. Please contact us with reference ' . $captureId . '.');
    redirect('myProfile.php?tab=orders');
}

// 4. Confirmation email. A mail failure must not stop the customer seeing their order.
$user = db_fetch_one('SELECT email, username FROM `user` WHERE user_id = ?', [$userId]);
if ($user) {
    $orderNo = '3DE-' . str_pad((string)$orderId, 4, '0', STR_PAD_LEFT);
    $content = '<p>Hello ' . e((string)$user['username']) . ',</p>'
        . '<p>Thank you. We have received your payment of <strong>R' . number_format((float)$checkout['total_amount'], 2)
        . '</strong> for order <strong>#' . e($orderNo) . '</strong>.</p>'
        . '<p>You can follow your order in your account under Orders &amp; returns. We will email your tracking number once it ships.</p>';
    send_app_email((string)$user['email'], (string)$user['username'],
        'Order ' . $orderNo . ' confirmed', email_shell('Thank you for your order', $content));
}

flash('success', 'Payment received. Your order #3DE-' . str_pad((string)$orderId, 4, '0', STR_PAD_LEFT) . ' is confirmed.');
redirect('myProfile.php?tab=orders&order=' . $orderId);
