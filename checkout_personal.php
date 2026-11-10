<?php
require_once __DIR__ . '/include/checkout_config.php';
require_once __DIR__ . '/Start_session.php';

$userId = require_login();

$customer = require_customer();

// Start a fresh checkout session — clears any stale order/payment data
// left over from a previous completed order.
if (!empty($_SESSION['checkout']['order_id'])) {
    unset(
        $_SESSION['checkout']['order_id'],
        $_SESSION['checkout']['payment_id'],
        $_SESSION['checkout']['paypal_order_id'],
        $_SESSION['checkout']['paypal_payer'],
        $_SESSION['checkout']['paid_at'],
        $_SESSION['checkout']['payment_method']
    );
}

if ($customer <= 0) {
    header('Location: login.php');
    exit;
}
$errors = [];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($phone === '' || strlen($phone) > 20) {
        $errors[] = 'Enter a valid phone number.';
    }

    if (!$errors) {
        // Update user email and phone number
        $stmt = $conn->prepare(
            'UPDATE user SET email = ?, phone_num = ? WHERE user_id = ?'
        );
        $stmt->bind_param('ssi', $email, $phone, $userId);
        $stmt->execute();
        $stmt->close();

        // Update customer phone number
        $stmt = $conn->prepare(
            'UPDATE customer SET phone_num = ? WHERE user_id = ?'
        );
        $stmt->bind_param('si', $phone, $userId);
        $stmt->execute();
        $stmt->close();

        // Save checkout details in the session
        $_SESSION['checkout']['email'] = $email;
        $_SESSION['checkout']['phone'] = $phone;

        header('Location: checkout_shipping.php');
        exit;
    }
}



// 1. Fetch the customer's actual names using their ID
$stmt = $conn->prepare(
    'SELECT first_name, last_name, phone_num FROM customer WHERE customer_id = ?'
);
$stmt->bind_param('i', $customer);
$stmt->execute();

$result = $stmt->get_result();
$customerData = $result->fetch_assoc() ?: [];
$stmt->close();


// Retrieve account details
$stmt = $conn->prepare(
    'SELECT email, phone_num FROM user WHERE user_id = ?'
);
$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$account = $result->fetch_assoc() ?: [];

$stmt->close();



// Retrieve cart items using MySQLi
$items = cart_items($conn, (int)$customer);
$totals = cart_totals($items);

// Populate form values
$emailValue = $_POST['email']
    ?? $_SESSION['checkout']['email']
    ?? $account['email']
    ?? '';

$phoneValue = $customerData['phone_num']?? '';

$page = 1;

require __DIR__ . '/include/checkout_header.php';
?>
<a class="back" href="cart.php">‹ &nbsp; Back to cart</a>
<div class="eyebrow">Almost yours</div>
<h1>Let's get<br>personal.</h1>
<p class="lead">Enter your contact details so we can keep you updated on your 3D Edge order.</p>
<div class="stepper"><div class="step active"><b>01</b>CONTACT</div><div class="step"><b>02</b>SHIPPING</div><div class="step"><b>03</b>PAYMENT</div></div>
<?php if ($errors): ?><div class="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<section class="section">
<div class="section-heading"><span class="number">01</span><div><h2>Contact information</h2><p>We'll use this to send your order updates.</p></div></div>
<div class="form-grid">
<div class="field"><label for="first_name">First name</label><input id="first_name" value="<?= e($customerData['first_name'] ?? '') ?>" disabled></div>
<div class="field"><label for="last_name">Last name</label><input id="last_name" value="<?= e($customerData['last_name'] ?? '') ?>" disabled></div>
<div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" value="<?= e((string)$emailValue) ?>" disabled></div>
<div class="field"><label for="phone">Phone number</label><input id="phone" type="tel" name="phone" autocomplete="tel" value="<?= e((string)$phoneValue) ?>" disabled></div>
</div>
<p class="hint">Your details is taken from your customer profile. To change it, update your <a href="myProfile.php" id="profile-link">profile</a> first.</p>
<div class="actions"><a href="checkout_shipping.php" class="btn primary" >Continue to shipping <span>→</span></a></div>
</section>

<?php require __DIR__ . '/include/checkout_footer.php'; ?>
