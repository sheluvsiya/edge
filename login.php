<?php

declare(strict_types=1);

require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/mailer.php';


$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $error = 'Please enter your email/username and password.';
        } else {
            $user = db_fetch_one(
                'SELECT * FROM `user` WHERE email = ? OR username = ? LIMIT 1',
                [$identifier, $identifier]
            );

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $error = 'The email/username or password is incorrect.';
            } elseif ($user['account_status'] !== 'active') {
                $error = 'Your account is not active. Please verify your email address before signing in.';
            } else {
                $userId = (int)$user['user_id'];

                db_execute(
                    'UPDATE `user` SET last_login = NOW() WHERE user_id = ?',
                    [$userId]
                );

                // Create a new PHP session ID after successful authentication.
                session_regenerate_id(true);

                if ($user['role'] === 'admin') {

                    // Find the admin record associated with this user.
                    $admin = db_fetch_one(
                        'SELECT admin_id FROM admin WHERE user_id = ? LIMIT 1',
                        [$userId]
                    );

                    if (!$admin) {
                        $error = 'No administrator profile is associated with this account.';
                    } else {
                        $adminId = (int)$admin['admin_id'];

                        // Store administrator details in the PHP session.
                        $_SESSION['auth_user'] = [
                            'admin_id' => $adminId,
                            'user_id'  => $userId,
                            'role'     => 'admin',
                        ];

                        // Create the administrator's database session.
                        db_execute(
                            'INSERT INTO admin_session
                                (admin_id, admin_session_duration)
                             VALUES (?, DATE_ADD(NOW(), INTERVAL 9 HOUR))',
                            [$adminId]
                        );
                    }
                } else {

                    // Find the customer record associated with this user.
                    $customer = db_fetch_one(
                        'SELECT customer_id FROM customer WHERE user_id = ? LIMIT 1',
                        [$userId]
                    );

                    if (!$customer) {
                        $error = 'No customer profile is associated with this account.';
                    } else {
                        $customerId = (int)$customer['customer_id'];

                        // Store customer details in the PHP session.
                        $_SESSION['auth_user'] = [
                            'customer_id' => $customerId,
                            'user_id'     => $userId,
                            'role'        => $user['role'],
                        ];

                        // Create the customer's database session.
                        db_execute(
                            'INSERT INTO customer_session
                                (customer_id, customer_session_duration)
                             VALUES (?, DATE_ADD(NOW(), INTERVAL 2 HOUR))',
                            [$customerId]
                        );
                    }
                }

                // Only complete login if the appropriate profile was found.
                if ($error === null) {
                    // Security email only when this IP address has not been used on this account before.
                    // Use the direct connection address; forwarded headers are not trusted here.
                    $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
                    if ($ip !== false
                        && function_exists('record_login_ip')
                        && call_user_func('record_login_ip', $userId, $ip)
                    ) {
                        if (function_exists('send_new_ip_alert')) {
                            call_user_func('send_new_ip_alert', $user['email'], $user['username'], $ip);
                        }
                    }

                    flash('success', 'Welcome back, ' . $user['username'] . '.');

                    redirect(
                        $user['role'] === 'admin'
                            ? 'admin/index.php'
                            : 'index.php'
                    );
                }
            }
        }
    }
}

$pageTitle = 'Login';
require __DIR__ . '/include/auth-layout.php';
?>
<h1 class="auth-title">Welcome back</h1>
<p class="auth-subtitle">Sign in to your 3D Edge account to manage quotes, orders and projects.</p>
<?php if ($error): ?><div class="alert auth-alert auth-alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e($error) ?></span></div><?php endif; ?>
<form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="mb-3">
        <label class="form-label" for="identifier">Email or username</label>
        <div class="input-group">
            <span class="input-group-text auth-input-icon"><i class="bi bi-person"></i></span>
            <input class="form-control" id="identifier" name="identifier" type="text" autocomplete="username" value="<?= old('identifier') ?>" placeholder="Enter your email or username" required>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <div class="input-group">
            <span class="input-group-text auth-input-icon"><i class="bi bi-lock"></i></span>
            <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
            <button class="btn password-toggle" type="button" onclick="togglePassword('password', this)" aria-label="Show password"><i class="bi bi-eye"></i></button>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="remember">
            <label class="form-check-label" for="remember">Remember me</label>
        </div>
        <a class="auth-inline-link" href="forgot-password.php">Forgot password?</a>
    </div>

    <button class="btn-edge" type="submit"><i class="bi bi-box-arrow-in-right me-2"></i>Sign in</button>
</form>

<div class="divider">or</div>

<div class="text-center auth-bottom-text">
    <span>Don't have an account?</span>
    <a class="auth-inline-link ms-1" href="register.php">Create one</a>
</div>
<?php require __DIR__ . '/include/auth-footer.php'; ?>