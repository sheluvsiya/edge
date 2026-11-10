<?php
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/checkout_helpers.php';
require_once __DIR__ . '/include/paypal.php';

require_login();
$customerId = (int)$_SESSION['auth_user']['customer_id'];
$userId     = (int)$_SESSION['auth_user']['user_id'];

// ---- Settings for this page ----
const EXPRESS_SHIPPING_FEE = 95.00;   // Express delivery charge in rand. Change here.
const TAX_RATE = 0.00;                // e.g. 0.15 for 15% VAT. 0 = no tax shown.
const PROVINCES = [
    'gauteng' => 'Gauteng', 'western-cape' => 'Western Cape', 'eastern-cape' => 'Eastern Cape',
    'kwazulu-natal' => 'KwaZulu-Natal', 'free-state' => 'Free State', 'limpopo' => 'Limpopo',
    'mpumalanga' => 'Mpumalanga', 'north-west' => 'North West', 'northern-cape' => 'Northern Cape',
];
const SHIPPING_OPTIONS = [
    'free'    => ['label' => 'Free Shipping',    'fee' => 0.00,                 'days' => '5-7 days'],
    'express' => ['label' => 'Express Shipping', 'fee' => EXPRESS_SHIPPING_FEE, 'days' => '1-2 days'],
];

$cart    = checkout_cart($conn, $customerId);
$saved   = checkout_shipping_address($conn, $customerId);
$errors  = [];
$old     = [];

// ---- Pay: validate the form, save the address, create the PayPal order ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect('checkout.php');
    }
    if (!$cart['cart_id'] || !$cart['items']) {
        flash('error', 'Your cart is empty.');
        redirect('cart.php');
    }
    if ($cart['expired']) {
        flash('error', 'Some items in your cart have expired. Please check your cart and add them again.');
        redirect('cart.php');
    }

    $old = [
        'first_name' => trim((string)($_POST['first_name'] ?? '')),
        'last_name'  => trim((string)($_POST['last_name'] ?? '')),
        'email'      => trim((string)($_POST['email'] ?? '')),
        'phone'      => trim((string)($_POST['phone'] ?? '')),
        'address'    => trim((string)($_POST['address'] ?? '')),
        'suburb'     => trim((string)($_POST['suburb'] ?? '')),
        'city'       => trim((string)($_POST['city'] ?? '')),
        'province'   => (string)($_POST['province'] ?? ''),
        'postal'     => trim((string)($_POST['postal_code'] ?? '')),
        'note'       => trim((string)($_POST['note'] ?? '')),
        'shipping'   => (string)($_POST['shipping'] ?? 'free'),
    ];

    if ($old['first_name'] === '') $errors['first_name'] = 'Enter your first name.';
    if ($old['last_name'] === '')  $errors['last_name']  = 'Enter your last name.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($old['phone'] === '' || strlen(preg_replace('/\D/', '', $old['phone'])) < 9) $errors['phone'] = 'Enter a valid phone number.';
    if ($old['address'] === '')    $errors['address'] = 'Enter your street address.';
    if ($old['city'] === '')       $errors['city'] = 'Enter your city.';
    if (!isset(PROVINCES[$old['province']])) $errors['province'] = 'Choose your province.';
    if (!preg_match('/^\d{4}$/', $old['postal'])) $errors['postal_code'] = 'Enter a 4-digit postal code.';
    if (!isset(SHIPPING_OPTIONS[$old['shipping']])) $errors['shipping'] = 'Choose a shipping method.';

    if (!$errors) {
        if (!paypal_configured()) {
            flash('error', 'Online payment is not set up yet. Please contact us to pay.');
            redirect('checkout.php');
        }

        // Save the address (the address table holds one row per saved address).
        db_execute(
            "INSERT INTO address (customer_id, address_type, province, city, postal_code, suburb,
                                  address_line1, building_type)
             VALUES (?, 'shipping', ?, ?, ?, ?, ?, 'house')",
            [$customerId, $old['province'], $old['city'], $old['postal'],
             $old['suburb'] !== '' ? $old['suburb'] : null, $old['address']]
        );

        // Totals are worked out here, from the database. The browser's numbers are ignored.
        $shippingFee = SHIPPING_OPTIONS[$old['shipping']]['fee'];
        $subtotal    = $cart['total'];
        $taxes       = round(($subtotal + $shippingFee) * TAX_RATE, 2);
        $total       = number_format($subtotal + $shippingFee + $taxes, 2, '.', '');

        $stmt = db_execute(
            'INSERT INTO checkout (cart_id, user_id, total_amount, fee_amount, shipping_fee, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))',
            [$cart['cart_id'], $userId, $total, $taxes, number_format($shippingFee, 2, '.', '')]
        );
        $checkoutId = (int)$stmt->insert_id;

        try {
            $paypalOrder = paypal_create_order(
                $total,
                'CHK-' . $checkoutId,
                APP_URL . '/paypal_return.php?checkout=' . $checkoutId,
                APP_URL . '/paypal_cancel.php'
            );
        } catch (RuntimeException $e) {
            error_log('[3D Edge PayPal] create order failed: ' . $e->getMessage());
            flash('error', $e->getMessage());
            redirect('checkout.php');
        }
        header('Location: ' . $paypalOrder['approve_url']);
        exit;
    }
}

// ---- Page values: the form starts with the saved address, if there is one ----
if (!$old) {
    $old = [
        'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '',
        'address' => $saved['address_line1'] ?? '', 'suburb' => $saved['suburb'] ?? '',
        'city' => $saved['city'] ?? '', 'province' => $saved['province'] ?? '',
        'postal' => $saved['postal_code'] ?? '', 'note' => '', 'shipping' => 'free',
    ];
    $user = db_fetch_one('SELECT username, email FROM `user` WHERE user_id = ?', [$userId]);
    if ($user) { $old['email'] = (string)$user['email']; }
}

$selectedShipping = SHIPPING_OPTIONS[$old['shipping']] ?? SHIPPING_OPTIONS['free'];
$shippingFee      = $selectedShipping['fee'];
$taxes            = round(($cart['total'] + $shippingFee) * TAX_RATE, 2);
$grandTotal       = $cart['total'] + $shippingFee + $taxes;
$csrf             = csrf_token();
$msg              = flash('error');
$ok               = flash('success');
$paymentReady     = paypal_configured() && !$cart['expired'] && (bool)$cart['items'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout | 3D Edge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/navbar_css.css">
    <link rel="stylesheet" href="css/footer_css.css">
    <style>
        :root { --ink:#111; --muted:#6b6b6b; --line:#e4e4e4; --soft:#f6f6f6; --red:#b4391b; }
        * { box-sizing: border-box; }
        body { margin:0; background:#f2f2f2; font-family:"Inter", system-ui, -apple-system, "Segoe UI", sans-serif; color:var(--ink); font-size:14px; }
        .co-wrap { padding: 28px 16px 40px; }
        .co-page { max-width:1080px; margin:0 auto; background:#fff; border:1px solid var(--line); border-radius:14px; overflow:hidden; }
        .co-top { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:16px 28px; border-bottom:1px solid var(--line); }
        .co-top .brand { font-weight:800; font-size:18px; letter-spacing:-.3px; }
        .co-top a { color:var(--muted); text-decoration:none; font-size:13px; }
        .co-layout { display:grid; grid-template-columns:minmax(0,1fr) 360px; }
        .co-main { padding:28px 32px 36px; }
        .co-side { padding:28px 28px 32px; background:var(--soft); border-left:1px solid var(--line); }
        .crumbs { font-size:12px; color:var(--muted); margin-bottom:18px; }
        .crumbs b { color:var(--ink); font-weight:600; }
        h2 { font-size:16px; font-weight:700; margin:0 0 14px; }
        .section { margin-bottom:26px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px 14px; }
        .field { display:flex; flex-direction:column; gap:5px; }
        .field.full { grid-column:1 / -1; }
        label { font-size:12px; color:var(--muted); }
        input, textarea, select { font:inherit; font-size:13px; color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:9px 11px; background:#fff; outline:0; width:100%; }
        input:focus, textarea:focus, select:focus { border-color:#9a9a9a; }
        input::placeholder, textarea::placeholder { color:#b4b4b4; }
        textarea { min-height:84px; resize:vertical; }
        .phone { display:flex; gap:6px; }
        .phone select { width:84px; flex-shrink:0; }
        .field-error { color:var(--red); font-size:12px; }
        .field.has-error input, .field.has-error select { border-color:var(--red); }
        .methods { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .method { position:relative; display:flex; flex-direction:column; gap:4px; border:1px solid var(--line); border-radius:10px; padding:14px 14px 13px; cursor:pointer; background:#fff; }
        .method input { position:absolute; opacity:0; pointer-events:none; }
        .method .row { display:flex; justify-content:space-between; align-items:center; font-weight:600; font-size:13px; }
        .method .name { display:flex; align-items:center; }
        .method .dot { width:14px; height:14px; border-radius:50%; border:1px solid #bdbdbd; display:inline-block; margin-right:8px; flex-shrink:0; }
        .method small { color:var(--muted); font-size:12px; padding-left:22px; }
        .method.selected { border-color:#9a9a9a; background:var(--soft); }
        .method.selected .dot { border:4px solid var(--ink); }
        .co-side h2 { margin-bottom:16px; }
        .item { display:grid; grid-template-columns:52px 1fr auto; gap:12px; align-items:center; margin-bottom:16px; }
        .thumb { width:52px; height:52px; border-radius:8px; background:#d9d9d9; overflow:hidden; display:flex; align-items:center; justify-content:center; }
        .thumb img { width:100%; height:100%; object-fit:cover; }
        .item .name { font-weight:600; font-size:13px; }
        .item .meta { color:var(--muted); font-size:12px; margin-top:2px; }
        .item .price { font-weight:600; font-size:13px; white-space:nowrap; }
        .promo { display:flex; gap:8px; margin:18px 0 20px; }
        .promo input { flex:1; }
        .promo button { border:1px solid var(--line); background:#fff; border-radius:8px; padding:0 16px; font:inherit; font-size:13px; font-weight:600; cursor:pointer; }
        .totals { border-top:1px solid var(--line); padding-top:14px; }
        .trow { display:flex; justify-content:space-between; color:var(--muted); font-size:13px; margin-bottom:9px; }
        .trow.total { color:var(--ink); font-weight:700; font-size:15px; margin-top:14px; padding-top:12px; border-top:1px solid var(--line); }
        .continue { display:block; width:100%; margin-top:20px; border:0; border-radius:10px; background:var(--ink); color:#fff; font:inherit; font-weight:700; font-size:14px; padding:14px; cursor:pointer; }
        .continue:disabled { background:#9a9a9a; cursor:not-allowed; }
        .notice { padding:12px 16px; border-radius:8px; font-size:13px; margin:0 0 18px; }
        .notice-error { background:#fdf0ec; border:1px solid #e7b9ab; color:#7a2411; }
        .notice-ok { background:#f1fbd9; border:1px solid #b8e04a; color:#2d4a00; }
        .empty { padding:40px 0; text-align:center; color:var(--muted); }
        @media (max-width:860px) { .co-layout { grid-template-columns:1fr; } .co-side { border-left:0; border-top:1px solid var(--line); } }
        @media (max-width:560px) { .co-wrap { padding:0; } .co-page { border-radius:0; border-left:0; border-right:0; } .co-main, .co-side { padding:22px 16px; } .grid2, .methods { grid-template-columns:1fr; } .co-top { padding:12px 16px; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/include/navbar.php'; ?>

    <div class="co-wrap">
    <div class="co-page">
        <header class="co-top">
            <div class="brand">3D EDGE</div>
            <a href="cart.php">&larr; Back to cart</a>
        </header>

        <?php if ($msg): ?><div class="notice notice-error" role="alert"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="notice notice-ok"><?= e($ok) ?></div><?php endif; ?>

        <?php if (!$cart['items']): ?>
            <div class="co-main"><p class="empty">Your cart is empty. <a href="products.php">Browse products</a>.</p></div>
        <?php else: ?>
        <div class="co-layout">

            <!-- LEFT: shipping details -->
            <section class="co-main">
                <div class="crumbs">Cart &nbsp;&gt;&nbsp; <b>Shipping</b> &nbsp;&gt;&nbsp; Payment</div>

                <?php if ($cart['expired']): ?>
                    <div class="notice notice-error">Some items have expired. <a href="cart.php">Review your cart</a> before paying.</div>
                <?php elseif (!paypal_configured()): ?>
                    <div class="notice notice-error">Online payment is not set up yet (PayPal keys are missing in .env).</div>
                <?php endif; ?>

                <form method="post" action="checkout.php" id="checkout-form" novalidate>
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

                    <div class="section">
                        <h2>Shipping Address</h2>
                        <div class="grid2">
                            <?php
                            $field = function (string $name, string $label, string $type = 'text', string $placeholder = '', bool $full = false) use ($old, $errors) {
                                $cls = 'field' . ($full ? ' full' : '') . (isset($errors[$name]) ? ' has-error' : '');
                                echo '<div class="' . $cls . '"><label for="' . e($name) . '">' . e($label) . '</label>';
                                echo '<input id="' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" placeholder="' . e($placeholder) . '" value="' . e((string)($old[$name] ?? '')) . '">';
                                if (isset($errors[$name])) echo '<span class="field-error">' . e($errors[$name]) . '</span>';
                                echo '</div>';
                            };
                            $field('first_name', 'First Name *', 'text', 'Enter first name');
                            $field('last_name', 'Last Name *', 'text', 'Enter last name');
                            $field('email', 'Email *', 'email', 'you@example.com');
                            ?>
                            <div class="field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
                                <label for="phone">Phone Number *</label>
                                <input id="phone" name="phone" type="tel" placeholder="082 123 4567" value="<?= e($old['phone']) ?>">
                                <?php if (isset($errors['phone'])): ?><span class="field-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                            </div>
                            <?php
                            $field('address', 'Street Address *', 'text', '12 Example Street', true);
                            $field('suburb', 'Suburb (optional)', 'text', 'Sandton');
                            $field('city', 'City *', 'text', 'Johannesburg');
                            ?>
                            <div class="field<?= isset($errors['province']) ? ' has-error' : '' ?>">
                                <label for="province">Province *</label>
                                <select id="province" name="province">
                                    <option value="">Choose a province</option>
                                    <?php foreach (PROVINCES as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= $old['province'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['province'])): ?><span class="field-error"><?= e($errors['province']) ?></span><?php endif; ?>
                            </div>
                            <?php $field('postal_code', 'Postal Code *', 'text', '2196'); ?>
                            <div class="field full">
                                <label for="note">Delivery notes (optional)</label>
                                <textarea id="note" name="note" placeholder="Gate code, building name, delivery instructions"><?= e($old['note']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h2>Shipping Method</h2>
                        <div class="methods" role="radiogroup" aria-label="Shipping method">
                            <?php foreach (SHIPPING_OPTIONS as $key => $opt): $isSel = $old['shipping'] === $key; ?>
                            <label class="method<?= $isSel ? ' selected' : '' ?>">
                                <input type="radio" name="shipping" value="<?= e($key) ?>" <?= $isSel ? 'checked' : '' ?>>
                                <div class="row"><span class="name"><span class="dot"></span><?= e($opt['label']) ?></span><span>R<?= number_format($opt['fee'], 0) ?></span></div>
                                <small><?= e($opt['days']) ?></small>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>
            </section>

            <!-- RIGHT: cart summary -->
            <aside class="co-side">
                <h2>Your Cart</h2>
                <?php foreach ($cart['items'] as $item): ?>
                <div class="item">
                    <div class="thumb"><span style="color:#888;font-size:11px;">&nbsp;</span></div>
                    <div>
                        <div class="name"><?= e($item['name']) ?></div>
                        <div class="meta">Qty <?= (int)$item['quantity'] ?> &times; R<?= number_format($item['unit_price'], 2) ?></div>
                    </div>
                    <div class="price">R<?= number_format($item['line_total'], 2) ?></div>
                </div>
                <?php endforeach; ?>

                <div class="promo">
                    <input type="text" placeholder="Discount code" aria-label="Discount code">
                    <button type="button" title="Discount codes are not active yet">Apply</button>
                </div>

                <div class="totals">
                    <div class="trow"><span>Subtotal</span><span>R<?= number_format($cart['total'], 2) ?></span></div>
                    <div class="trow"><span>Shipping</span><span id="ship-amount">R<?= number_format($shippingFee, 2) ?></span></div>
                    <div class="trow"><span>Estimated taxes</span><span>R<?= number_format($taxes, 2) ?></span></div>
                    <div class="trow total"><span>Total</span><span id="grand-total">R<?= number_format($grandTotal, 2) ?></span></div>
                </div>

                <button class="continue" type="submit" form="checkout-form" <?= $paymentReady ? '' : 'disabled' ?>>Continue to Payment</button>
            </aside>

        </div>
        <?php endif; ?>
    </div>
    </div>

    <?php require_once __DIR__ . '/include/footer.php'; ?>

    <script>
        // Highlight the chosen shipping method and show its charge.
        (function () {
            const fees = <?= json_encode(array_map(fn($o) => $o['fee'], SHIPPING_OPTIONS)) ?>;
            const subtotal = <?= json_encode((float)$cart['total']) ?>;
            const taxRate = <?= json_encode((float)TAX_RATE) ?>;
            const fmt = n => 'R' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.querySelectorAll('.method input').forEach(function (input) {
                input.addEventListener('change', function () {
                    document.querySelectorAll('.method').forEach(function (m) { m.classList.remove('selected'); });
                    input.closest('.method').classList.add('selected');
                    const fee = fees[input.value] || 0;
                    const taxes = (subtotal + fee) * taxRate;
                    document.getElementById('ship-amount').textContent = fmt(fee);
                    document.getElementById('grand-total').textContent = fmt(subtotal + fee + taxes);
                });
            });
        })();
    </script>
</body>
</html>
