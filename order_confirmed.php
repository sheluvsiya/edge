<?php

declare(strict_types=1);

require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/checkout_config.php';

verify_csrf();

$userId = require_login();

$checkout = $_SESSION['checkout'] ?? [];

$paymentMethod = (string) ($checkout['payment_method'] ?? '');

if (!in_array($paymentMethod, ['paypal', 'pay_on_collection'], true)) {
    header('Location: checkout_payment.php');
    exit;
}

$mysqli   = db();
$customer = get_customer($mysqli, $userId);
$customerId = (int) $customer['customer_id'];

// The order was saved by save_order_from_checkout(), which also empties the cart.
// So the items and totals must come from the saved order, not the live cart.
$orderId = (int) ($checkout['order_id'] ?? 0);
if ($orderId <= 0) {
    header('Location: myProfile.php?tab=orders');
    exit;
}

$stmt = $mysqli->prepare('
    SELECT ch.total_amount, ch.shipping_fee
      FROM `order` o
      JOIN checkout ch ON ch.checkout_id = o.checkout_id
     WHERE o.order_id = ? AND o.customer_id = ?
     LIMIT 1
');
$stmt->bind_param('ii', $orderId, $customerId);
$stmt->execute();
$orderRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$orderRow) {
    header('Location: myProfile.php?tab=orders');
    exit;
}

$stmt = $mysqli->prepare('
    SELECT COALESCE(oi.item_name, \'Item\') AS name,
           oi.quantity,
           oi.unit_price AS price
      FROM order_item oi
     WHERE oi.order_id = ?
');
$stmt->bind_param('i', $orderId);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$isCollection = !empty($checkout['is_collection']);
$shipping     = $checkout['shipping'] ?? [];

// total_amount is what was actually charged (products + shipping), stored at save time.
$total        = (float) $orderRow['total_amount'];
$shippingFee  = $isCollection ? 0.00 : (float) $orderRow['shipping_fee'];
$subtotal     = $total - $shippingFee;

$paypalOrderId = (string) ($checkout['paypal_order_id'] ?? '');
$payerName     = (string) ($checkout['paypal_payer'] ?? '');

// Optional: clear the cart from the session (adjust to your own cart-clearing logic)
// unset($_SESSION['checkout']);

$page = 4;
require __DIR__ . '/include/checkout_header.php';
?>

<a class="back" href="index.php">‹ &nbsp; Back to home</a>

<div class="eyebrow">Thank you</div>
<h1>Order<br>confirmed.</h1>

<p class="lead">
    Your payment was received. A confirmation email will be sent to you shortly.
</p>

<div class="stepper">
    <div class="step done"><b>✓</b>CONTACT</div>
    <div class="step done"><b>✓</b>SHIPPING</div>
    <div class="step done"><b>✓</b>PAYMENT</div>
</div>

<section class="section">
    <div class="section-heading">
        <span class="number">✓</span>
        <div>
            <h2>Payment received</h2>
            <p>
                <?= $payerName !== ''
                    ? 'Thank you, ' . e($payerName) . '.'
                    : 'Thank you for your order.' ?>
            </p>
        </div>
    </div>

    <div class="notice">
        <b>♢</b>
        <span>
            <strong>PayPal order ID</strong><br>
            <?= e($paypalOrderId) ?>
        </span>
    </div>

    <div class="notice">
        <b>♢</b>
        <span>
            <strong>Payment method</strong><br>
            <?php if ($paymentMethod === 'paypal'): ?>
                PayPal<?= $payerName !== '' ? ' (' . e($payerName) . ')' : '' ?>
                <?php if ($paypalOrderId !== ''): ?>
                    <br><small style="color:#9aa395;">PayPal order ID: <?= e($paypalOrderId) ?></small>
                <?php endif; ?>
            <?php else: ?>
                Pay on collection
                <br><small style="color:#9aa395;">Payment due when you collect your order.</small>
            <?php endif; ?>
        </span>
    </div>

</section>

<section class="section">
    <div class="section-heading">
        <span class="number">01</span>
        <div>
            <h2>Order summary</h2>
            <p>Your items and delivery details.</p>
        </div>
    </div>

    <table style="width:100%; border-collapse: collapse; margin-bottom: 20px;">
        <thead>
            <tr style="border-bottom: 1px solid #2b322a;">
                <th style="text-align:left; padding:10px 0; font-size:12px; text-transform:uppercase; color:#9aa395;">Item</th>
                <th style="text-align:center; padding:10px 0; font-size:12px; text-transform:uppercase; color:#9aa395;">Qty</th>
                <th style="text-align:right; padding:10px 0; font-size:12px; text-transform:uppercase; color:#9aa395;">Price</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr style="border-bottom: 1px solid #1f231f;">
                    <td style="padding:12px 0; color:#ffffff;"><?= e($item['name']) ?></td>
                    <td style="padding:12px 0; text-align:center; color:#9aa395;"><?= (int) $item['quantity'] ?></td>
                    <td style="padding:12px 0; text-align:right; color:#ffffff;">
                        R<?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" style="padding:12px 0; text-align:right; color:#9aa395;">Subtotal</td>
                <td style="padding:12px 0; text-align:right; color:#ffffff;">R<?= number_format($subtotal, 2) ?></td>
            </tr>
            <tr>
                <td colspan="2" style="padding:6px 0; text-align:right; color:#9aa395;">Shipping</td>
                <td style="padding:6px 0; text-align:right; color:#ffffff;">
                    <?= $isCollection ? 'Collection' : 'R' . number_format($shippingFee, 2) ?>
                </td>
            </tr>
            <tr style="border-top: 1px solid #2b322a;">
                <td colspan="2" style="padding:14px 0; text-align:right; font-weight:700; color:#ffffff;">
                    <?= $paymentMethod === 'paypal' ? 'Total paid' : 'Total due on collection' ?>
                </td>

                <td style="padding:14px 0; text-align:right; font-weight:800; color:#ccff33; font-size:18px;">
                    R<?= number_format($total, 2) ?>
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="notice">
        <b>♢</b>
        <span>
            <strong><?= $isCollection ? 'Collection' : 'Delivery' ?> details</strong><br>
            <?php if ($isCollection): ?>
                97 Edison Crescent, Sunninghill, Sandton, Gauteng, 2191<br>
                <small style="color:#9aa395;">Mon – Fri, 08:00 – 17:00</small>
            <?php else: ?>
                <?= e((string) ($shipping['street_address'] ?? '')) ?><br>
                <?php if (!empty($shipping['local_area'])): ?>
                    <?= e((string) $shipping['local_area']) ?><br>
                <?php endif; ?>
                <?= e((string) ($shipping['city'] ?? '')) ?>,
                <?= e((string) ($shipping['zone'] ?? $shipping['province'] ?? '')) ?><br>
                <?= e((string) ($shipping['postal_code'] ?? '')) ?>
            <?php endif; ?>
        </span>
    </div>

    <div class="actions">
        <a class="btn primary" href="index.php">Return to home <span>→</span></a>
    </div>
</section>

<?php require __DIR__ . '/include/checkout_footer.php'; ?>