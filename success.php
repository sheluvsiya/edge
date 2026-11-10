<?php
require_once __DIR__ . '/config.php';
$userId = require_login();
$orderId = (int)($_SESSION['checkout']['completed_order_id'] ?? 0);
if (!$orderId) { header('Location: ../index.php'); exit; }
$pdo = db();
$stmt = $pdo->prepare("SELECT o.order_id,o.created_at,o.order_status,ch.total_amount FROM `order` o JOIN checkout ch ON ch.checkout_id=o.checkout_id JOIN customer c ON c.customer_id=o.customer_id WHERE o.order_id=? AND c.user_id=?");
$stmt->execute([$orderId,$userId]); $order = $stmt->fetch();
if (!$order) { http_response_code(404); exit('Order not found.'); }
unset($_SESSION['checkout']['completed_order_id']);
$page = 0;
require __DIR__ . '/template_top.php';
?>
<div class="eyebrow">Order received</div><h1>You're all<br>set.</h1>
<p class="lead">Thanks for shopping with 3D Edge. Your order has been saved successfully.</p>
<div class="review-card"><strong>Order #<?= (int)$order['order_id'] ?></strong><br>Status: <?= e(ucfirst($order['order_status'])) ?><br>Total: R<?= number_format((float)$order['total_amount'],2) ?><br>Payment: Pay on collection</div>
<div class="actions"><a class="btn primary" href="../index.php">Continue shopping <span>→</span></a></div>
<?php require __DIR__ . '/template_bottom.php'; ?>
