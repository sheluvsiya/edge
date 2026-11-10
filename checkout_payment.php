<?php

declare(strict_types=1);

require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/checkout_config.php';
require_once __DIR__ . '/include/save_order.php';

verify_csrf();

$userId = require_login();

if (empty($_SESSION['checkout'])) {
    header('Location: personal.php');
    exit;
}

$checkout = $_SESSION['checkout'] ?? [];
$isCollection = !empty($checkout['is_collection']);
$shipping = $checkout['shipping'] ?? [];

if (empty($shipping) || !is_array($shipping)) {
    header('Location: checkout_shipping.php');
    exit;
}

$mysqli = db();
$customer = get_customer($mysqli, $userId);

if (!$customer) {
    header('Location: personal.php');
    exit;
}

$customerId = (int) $customer['customer_id'];

$items = cart_items($mysqli, $customerId);
$totals = cart_totals($items);

if (!$items) {
    header('Location: ../cart.php?error=empty');
    exit;
}

$errors = [];

if ($isCollection) {
    $shippingFee = 0.00;
} else {
    $rateId = $shipping['shipping_rate_id'] ?? null;
    $savedFee = $shipping['shipping_fee'] ?? null;

    if (
        $rateId === null ||
        $rateId === '' ||
        !is_numeric($savedFee) ||
        (float) $savedFee < 0
    ) {
        header('Location: checkout_shipping.php');
        exit;
    }

    $shippingFee = (float) $savedFee;
}

$subtotal = (float) ($totals['subtotal'] ?? 0);
$subtotalWithShipping = $subtotal + $shippingFee;
$vat = $subtotalWithShipping * 0.15;
$total = $subtotalWithShipping + $vat;

$totals['shipping'] = $shippingFee;
$totals['total'] = $total;

// ============================================================
// Handle POST — only the "Pay on collection" flow submits a form.
// PayPal is handled client-side and POSTs to process_paypal_order.php
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method = (string) ($_POST['payment_method'] ?? '');

    if ($method === 'pay_on_collection') {
        if (!$isCollection) {
            $errors[] = 'Pay on collection is only available when collecting your order from the showroom.';
        } else {
            $_SESSION['checkout']['payment_method'] = 'pay_on_collection';
            $_SESSION['checkout']['order_total']    = $total;

            try {
                save_order_from_checkout($mysqli, $userId, $customerId);
                header('Location: order_confirmed.php');
                exit;
            } catch (Throwable $e) {
                error_log('Order save failed: ' . $e->getMessage());
                $errors[] = 'Could not save your order. Please try again.';
            }
        }
    } else {
        $errors[] = 'Choose a valid payment method.';
    }
}

$page = 3;
require __DIR__ . '/include/checkout_header.php';
?>

<a class="back" href="checkout_shipping.php">‹ &nbsp; Back to shipping</a>

<div class="eyebrow">One last step</div>
<h1>Make it<br>official.</h1>

<p class="lead">
    Choose how you'd like to pay. Your payment details are never stored on this website.
</p>

<div class="stepper">
    <div class="step done"><b>✓</b>CONTACT</div>
    <div class="step done"><b>✓</b>SHIPPING</div>
    <div class="step active"><b>03</b>PAYMENT</div>
</div>

<?php if ($errors): ?>
    <div class="alert">
        <?= e(implode(' ', $errors)) ?>
    </div>
<?php endif; ?>

<section class="section">
    <div class="section-heading">
        <span class="number">02</span>
        <div>
            <h2>
                <?= $isCollection ? 'Showroom collection' : 'Delivery details' ?>
            </h2>
            <p>
                <?= $isCollection
                    ? 'You have chosen to collect your order from 3D Edge.'
                    : 'Your selected Bob Go delivery option is saved for this checkout.' ?>
            </p>
        </div>
    </div>

    <?php if ($isCollection): ?>
        <div class="notice">
            <b>♢</b>
            <span>
                <strong>Collection address</strong><br>
                97 Edison Crescent, Sunninghill, Sandton, Gauteng, 2191
            </span>
        </div>
    <?php else: ?>
        <div class="notice">
            <b>♢</b>
            <span>
                <strong>Delivery address</strong><br>
                <?= e((string) ($shipping['street_address'] ?? '')) ?><br>

                <?php if (!empty($shipping['building_type'])): ?>
                    <?= e((string) $shipping['building_type']) ?><br>
                <?php endif; ?>

                <?= e((string) ($shipping['local_area'] ?? '')) ?><br>
                <?= e((string) ($shipping['city'] ?? '')) ?>,
                <?= e((string) ($shipping['zone'] ?? $shipping['province'] ?? '')) ?><br>
                <?= e((string) ($shipping['postal_code'] ?? '')) ?>

                <br><br>
                <strong>Shipping fee:</strong>
                R<?= number_format($shippingFee, 2) ?>

                <?php if (!empty($shipping['shipping_rate_id'])): ?>
                    <br>
                    <strong>Shipping rate reference:</strong>
                    <?= e((string) $shipping['shipping_rate_id']) ?>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="actions">
        <a class="btn secondary" href="checkout_shipping.php">Change shipping</a>
    </div>
</section>

<section class="section">
    <div class="section-heading">
        <span class="number">03</span>
        <div>
            <h2>Payment method</h2>
            <p>Select an available payment option for this order.</p>
        </div>
    </div>

    <div class="notice" style="margin-bottom: 20px;">
        <b>♢</b>
        <span>
            <strong>Order total:</strong>
            R<?= number_format($total, 2) ?>
            <br>
            <small style="color:#9aa395;">
                Includes shipping<?= $isCollection ? ' — collection, no fee' : ' (R' . number_format($shippingFee, 2) . ')' ?>
            </small>
        </span>
    </div>

    <?php if ($isCollection): ?>

        <!-- ===== Collection: choice between Pay on collection and PayPal ===== -->

        <label class="payment-choice">
            <input type="radio" name="pay_choice" value="pay_on_collection" id="choice_collection" checked>
            <span>
                <strong>Pay on collection</strong>
                <span>Pay when you collect your order from 3D Edge.</span>
            </span>
        </label>

        <label class="payment-choice">
            <input type="radio" name="pay_choice" value="paypal" id="choice_paypal">
            <span>
                <strong>Pay now with PayPal</strong>
                <span>Pay online now and collect at your convenience.</span>
            </span>
        </label>

        <!-- PayPal button shows only when the PayPal choice is selected -->
        <div id="paypal-wrap" style="display:none; margin-top: 20px;">
            <div id="paypal-button-container"></div>
        </div>

        <!-- Pay-on-collection form submits when the first option is selected -->
        <form method="post" id="collect_form" style="margin-top: 20px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="payment_method" value="pay_on_collection">
            <div class="actions">
                <a class="btn secondary" href="checkout_shipping.php">Back</a>
                <button class="btn primary" type="submit">
                    Review order <span>→</span>
                </button>
            </div>
        </form>

        <!-- Back button used when PayPal is selected -->
        <div class="actions" id="paypal_back_row" style="display:none; margin-top: 20px;">
            <a class="btn secondary" href="checkout_shipping.php">Back</a>
        </div>

    <?php else: ?>

        <!-- ===== Delivery: PayPal only ===== -->

        <div id="paypal-button-container"></div>

        <div class="actions" style="margin-top: 20px;">
            <a class="btn secondary" href="checkout_shipping.php">Back</a>
        </div>

    <?php endif; ?>
</section>

<!-- PayPal SDK -->


<script>
    document.addEventListener('DOMContentLoaded', function() {

        // Draw the PayPal buttons.
        // This must only run when the button box is VISIBLE: PayPal measures the box when it draws,
        // and a hidden box (display: none) gets the wrong size, which made the buttons blank or
        // cut off on phones. See the bottom of this script for when it is called.
        var paypalRendered = false;

        function renderPaypal() {
            if (paypalRendered) return; // only draw once
            paypalRendered = true;

            paypal.Buttons({
                createOrder: function(data, actions) {
                    return actions.order.create({
                        purchase_units: [{
                            amount: {
                                value: '<?= number_format($total / 16.52, 2, '.', '') ?>',
                                currency_code: 'USD'
                            },
                            description: '3D Edge order'
                        }]
                    });
                },

                onApprove: function(data, actions) {
                    return actions.order.capture().then(function(details) {
                        fetch('process_paypal_order.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    order_id: data.orderID,
                                    payer_name: (details.payer && details.payer.name) ?
                                        details.payer.name.given_name :
                                        ''
                                })
                            })
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(result) {
                                if (result.success) {
                                    window.location.href = 'order_confirmed.php';
                                } else {
                                    alert('Payment captured but order could not be saved: ' +
                                        (result.error || 'unknown error'));
                                }
                            })
                            .catch(function(err) {
                                console.error(err);
                                alert('Payment captured. Please contact support with your PayPal order ID: ' + data.orderID);
                            });
                    });
                },

                onError: function(err) {
                    console.error('PayPal error:', err);
                    alert('Payment failed. Please try again.');
                },

                onCancel: function() {
                    // User closed the popup — nothing to do
                }
            }).render('#paypal-button-container');
        }

        // ---- Collection flow: toggle between pay-on-collection form and PayPal ----
        var collectForm = document.getElementById('collect_form');
        var paypalWrap = document.getElementById('paypal-wrap');
        var paypalBackRow = document.getElementById('paypal_back_row');

        if (collectForm) {
            var radios = document.querySelectorAll('input[name="pay_choice"]');

            radios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    var isPaypal = this.value === 'paypal' && this.checked;

                    collectForm.style.display = isPaypal ? 'none' : 'block';
                    paypalWrap.style.display = isPaypal ? 'block' : 'none';
                    if (paypalBackRow) paypalBackRow.style.display = isPaypal ? 'flex' : 'none';

                    // The box is visible now, so it is safe to draw the buttons (first time only)
                    if (isPaypal) renderPaypal();
                });
            });
        } else {
            // Delivery flow: PayPal is the only option and its box is visible straight away
            renderPaypal();
        }
    });
</script>

<?php require __DIR__ . '/include/checkout_footer.php'; ?>