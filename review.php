<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/checkout_config.php';
verify_csrf();
$userId = require_login();
if (empty($_SESSION['checkout']['email'])) { header('Location: personal.php'); exit; }
if (empty($_SESSION['checkout']['shipping'])) { header('Location: shipping.php'); exit; }
if (($_SESSION['checkout']['payment_method'] ?? '') !== 'pay_on_collection') { header('Location: payment.php'); exit; }
$pdo = db(); $customer = get_customer($pdo, $userId);
$items = cart_items($pdo, (int)$customer['customer_id']); $totals = cart_totals($items);
if (!$items) { header('Location: ../cart.php?error=empty'); exit; }
$data = $_SESSION['checkout']['shipping']; $email = (string)$_SESSION['checkout']['email']; $phone = (string)$_SESSION['checkout']['phone'];
$provinces = ['gauteng'=>'Gauteng','limpopo'=>'Limpopo','mpumalanga'=>'Mpumalanga','western-cape'=>'Western Cape','northern-cape'=>'Northern Cape','north-west'=>'North West','eastern-cape'=>'Eastern Cape','free-state'=>'Free State','kwazulu-natal'=>'KwaZulu-Natal'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        // Re-read the cart inside the transaction so the order total is server-calculated.
        $items = cart_items($pdo, (int)$customer['customer_id']);
        $totals = cart_totals($items);
        if (!$items) throw new RuntimeException('Your cart is empty or contains unavailable items.');
        $stmt = $pdo->prepare("INSERT INTO address (customer_id,address_type,province,city,country,postal_code,suburb,address_line1,address_line2,building_type,building_name,unit_num,access_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $customer['customer_id'],'shipping',$data['province'],$data['city'],'south africa',$data['postal_code'],
            $data['suburb'] ?: null,$data['address_line1'],$data['address_line2'] ?: null,$data['building_type'],
            $data['building_name'] ?: null,$data['unit_num'] ?: null,$data['access_code'] ?: null
        ]);
        $cartStmt = $pdo->prepare('SELECT cart_id FROM cart WHERE customer_id = ? ORDER BY cart_id DESC LIMIT 1 FOR UPDATE');
        $cartStmt->execute([$customer['customer_id']]); $cart = $cartStmt->fetch();
        if (!$cart) throw new RuntimeException('No cart was found for this customer.');
        $expiry = date('Y-m-d H:i:s', time() + 3600);
        $firstItem = $items[0];
        $insertCheckout = $pdo->prepare('INSERT INTO checkout (cart_id,product_id,material_id,user_id,total_amount,fee_amount,shipping_fee,expires_at) VALUES (?,?,?,?,?,?,?,?)');
        $firstCartRow = $pdo->prepare('SELECT product_id, material_id FROM cart_item WHERE cart_item_id = ?');
        $firstCartRow->execute([$firstItem['cart_item_id']]); $firstRefs = $firstCartRow->fetch() ?: [];
        $insertCheckout->execute([(int)$cart['cart_id'],$firstRefs['product_id'] ?? null,$firstRefs['material_id'] ?? null,$userId,$totals['total'],0,$totals['shipping'],$expiry]);
        $checkoutId = (int)$pdo->lastInsertId();
        $insertOrder = $pdo->prepare("INSERT INTO `order` (customer_id,checkout_id,order_status) VALUES (?,?, 'pending')");
        $insertOrder->execute([$customer['customer_id'],$checkoutId]); $orderId = (int)$pdo->lastInsertId();
        $insertOrderItem = $pdo->prepare('INSERT INTO order_item (order_id,cart_item_id) VALUES (?,?)');
        foreach ($items as $item) $insertOrderItem->execute([$orderId,$item['cart_item_id']]);
        $payment = $pdo->prepare("INSERT INTO payment (order_id,checkout_id,user_id,payment_method,amount,payment_status) VALUES (?,?,?,?,?,'pending')");
        // Requires payment_status ENUM to include 'pending' (see README.md migration).
        $payment->execute([$orderId,$checkoutId,$userId,'pay_on_collection',$totals['total']]);
        $paymentId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE `order` SET payment_id = ? WHERE order_id = ?')->execute([$paymentId,$orderId]);
        $pdo->commit();
        $_SESSION['checkout']['completed_order_id'] = $orderId;
        unset($_SESSION['checkout']['shipping'], $_SESSION['checkout']['email'], $_SESSION['checkout']['phone'], $_SESSION['checkout']['payment_method']);
        header('Location: success.php'); exit;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'We could not place your order: ' . $ex->getMessage();
    }
}
$page = 3;
require __DIR__ . '/template_top.php';
?>
<a class="back" href="payment.php">‹ &nbsp; Back to payment</a>
<div class="eyebrow">Check everything</div><h1>Ready to<br>roll.</h1>
<p class="lead">Review your details before placing your order.</p>
<div class="stepper"><div class="step done"><b>✓</b>CONTACT</div><div class="step done"><b>✓</b>SHIPPING</div><div class="step active"><b>03</b>REVIEW</div></div>
<?php if ($errors): ?><div class="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<div class="review-card"><strong>Contact</strong><br><?= e($customer['first_name'].' '.$customer['last_name']) ?><br><?= e($email) ?><br><?= e($phone) ?><p><a href="personal.php">Edit contact →</a></p></div>
<div class="review-card"><strong>Shipping address</strong><br><?= e($data['address_line1']) ?><?= !empty($data['address_line2']) ? ', '.e($data['address_line2']) : '' ?><br><?= e((string)($data['suburb'] ?? '')) ?> <?= e($data['city']) ?><br><?= e($provinces[$data['province']] ?? $data['province']) ?>, <?= e($data['postal_code']) ?><br>South Africa<p><a href="shipping.php">Edit address →</a></p></div>
<div class="review-card"><strong>Payment</strong><br>Pay on collection</div>
<form method="post" data-confirm="Place this order? Your order will be saved in the database.">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<div class="actions"><a class="btn secondary" href="payment.php">Back</a><button class="btn primary" type="submit">Place order <span>R<?= number_format($totals['total'],2) ?></span></button></div>
<p class="hint">By placing your order, you agree to the store's terms and privacy policy.</p>
</form>
<?php require __DIR__ . '/template_bottom.php'; ?>
