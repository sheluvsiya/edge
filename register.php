<?php
declare(strict_types=1);
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/mailer.php';

require_guest();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone_num'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        set_old(compact('firstName', 'lastName', 'username', 'email', 'phone'));

        if ($firstName === '' || $lastName === '' || $username === '' || $email === '' || $password === '') {
            $error = 'Please complete all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
            $error = 'Username must be 3–50 characters and may contain letters, numbers and underscores.';
        } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Password must be at least 8 characters and include uppercase, lowercase and a number.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $existing = db_fetch_one('SELECT user_id, email, username FROM `user` WHERE email = ? OR username = ? LIMIT 1', [$email, $username]);

            if ($existing) {
                $error = strcasecmp($existing['email'], $email) === 0 ? 'That email address is already registered.' : 'That username is already in use.';
            } else {
                $db = db();
                $db->begin_transaction();
                try {
                    db_execute("INSERT INTO `user` (username, email, password_hash, phone_num, account_status, role) VALUES (?, ?, ?, ?, 'inactive', 'customer')", [$username, $email, password_hash($password, PASSWORD_DEFAULT), $phone !== '' ? $phone : null]);
                    $userId = (int)$db->insert_id;

                    db_execute('INSERT INTO customer (user_id, first_name, last_name, phone_num) VALUES (?, ?, ?, ?)', [$userId, $firstName, $lastName, $phone !== '' ? $phone : null]);

                    db_execute('DELETE FROM reset_token WHERE user_id = ?', [$userId]);
                    $token = create_token();
                    $tokenHash = hash('sha256', 'verify:' . $token);
                    $expires = date('Y-m-d H:i:s', time() + VERIFY_TOKEN_MINUTES * 60);
                    db_execute('INSERT INTO reset_token (user_id, token_duration, token) VALUES (?, ?, ?)', [$userId, $expires, $tokenHash]);
                    $db->commit();

                    $sent = send_verification_email($email, $firstName, $token, $userId);
                    clear_old();
                    if (!$sent) {
                        flash('error', 'Your account was created, but the verification email could not be sent. Check the SMTP settings and use the resend option.');
                    } else {
                        flash('success', 'Registration successful. Check your email to verify your account.');
                    }
                    redirect('login.php');
                } catch (Throwable $e) {
                    try { $db->rollback(); } catch (Throwable $ignored) {}
                    error_log('[3D Edge Registration] ' . $e->getMessage());
                    $error = 'We could not create your account. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Create account';
require __DIR__ . '/include/auth-layout.php';
?>
<h1 class="auth-title">Create your account</h1>
<p class="auth-subtitle">Create your 3D Edge customer account and start managing your printing journey.</p>
<?php if ($error): ?><div class="alert auth-alert auth-alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e($error) ?></span></div><?php endif; ?>
<form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="first_name">First name *</label>
            <div class="input-group">
                <span class="input-group-text auth-input-icon"><i class="bi bi-person"></i></span>
                <input class="form-control" id="first_name" name="first_name" value="<?= old('firstName') ?>" placeholder="First name" autocomplete="given-name" required>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="last_name">Last name *</label>
            <div class="input-group">
                <span class="input-group-text auth-input-icon"><i class="bi bi-person"></i></span>
                <input class="form-control" id="last_name" name="last_name" value="<?= old('lastName') ?>" placeholder="Last name" autocomplete="family-name" required>
            </div>
        </div>

        <div class="col-12">
            <label class="form-label" for="username">Username *</label>
            <div class="input-group">
                <span class="input-group-text auth-input-icon"><i class="bi bi-at"></i></span>
                <input class="form-control" id="username" name="username" autocomplete="username" value="<?= old('username') ?>" placeholder="Choose a username" required>
            </div>
        </div>

        <div class="col-12">
            <label class="form-label" for="email">Email address *</label>
            <div class="input-group">
                <span class="input-group-text auth-input-icon"><i class="bi bi-envelope"></i></span>
                <input class="form-control" id="email" name="email" type="email" autocomplete="email" value="<?= old('email') ?>" placeholder="you@example.com" required>
            </div>
        </div>

        <div class="col-12">
            <label class="form-label" for="phone_num">Phone number</label>
            <div class="input-group">
                <span class="input-group-text auth-input-icon"><i class="bi bi-telephone"></i></span>
                <input class="form-control" id="phone_num" name="phone_num" type="tel" autocomplete="tel" value="<?= old('phone') ?>" placeholder="Optional">
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label" for="password">Password *</label>
            <div class="input-group">
                <span class="input-group-text auth-input-icon"><i class="bi bi-lock"></i></span>
                <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" placeholder="Create password" required>
                <button class="btn password-toggle" type="button" onclick="togglePassword('password', this)" aria-label="Show password"><i class="bi bi-eye"></i></button>
            </div>
            <div class="password-strength"><div id="passwordStrength" class="password-strength-bar"></div></div>
            <div id="passwordStrengthText" class="password-strength-text">8+ characters, uppercase, lowercase and a number.</div>
        </div>

        <div class="col-md-6">
            <label class="form-label" for="confirm_password">Confirm password *</label>
            <div class="input-group">
                <span class="input-group-text auth-input-icon"><i class="bi bi-shield-lock"></i></span>
                <input class="form-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" placeholder="Confirm password" required>
                <button class="btn password-toggle" type="button" onclick="togglePassword('confirm_password', this)" aria-label="Show password"><i class="bi bi-eye"></i></button>
            </div>
        </div>
    </div>

    <div class="form-check my-4">
        <input class="form-check-input" type="checkbox" id="terms" required>
        <label class="form-check-label" for="terms">I agree to create a 3D Edge customer account.</label>
    </div>

    <button class="btn-edge" type="submit"><i class="bi bi-person-plus me-2"></i>Create account</button>
</form>

<div class="auth-links"><span>Already registered?</span><a href="login.php">Sign in</a></div>
<?php require __DIR__ . '/include/auth-footer.php'; ?>
