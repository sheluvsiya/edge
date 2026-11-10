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
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Always return the same success message to avoid revealing registered accounts.
            $user = db_fetch_one('SELECT user_id, username, email FROM `user` WHERE email = ? LIMIT 1', [$email]);
            if ($user) {
                db_execute('DELETE FROM reset_token WHERE user_id = ?', [(int)$user['user_id']]);
                $token = create_token();
                $hash = hash('sha256', 'reset:' . $token);
                $expires = date('Y-m-d H:i:s', time() + RESET_TOKEN_MINUTES * 60);
                db_execute('INSERT INTO reset_token (user_id, token_duration, token) VALUES (?, ?, ?)', [(int)$user['user_id'], $expires, $hash]);
                send_reset_email($user['email'], $user['username'], $token, (int)$user['user_id']);
            }
            flash('success', 'If an account exists for that email address, a password reset link has been sent.');
            redirect('forgot-password.php');
        }
    }
}

$pageTitle = 'Forgot password';
require __DIR__ . '/include/auth-layout.php';
?>
<h1 class="auth-title">Reset password</h1>
<p class="auth-subtitle">Enter the email address linked to your 3D Edge account and we'll send you a secure reset link.</p>
<?php if ($error): ?><div class="alert auth-alert auth-alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e($error) ?></span></div><?php endif; ?>

<div class="reset-icon"><i class="bi bi-key"></i></div>

<form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="mb-4">
        <label class="form-label" for="email">Email address</label>
        <div class="input-group">
            <span class="input-group-text auth-input-icon"><i class="bi bi-envelope"></i></span>
            <input class="form-control" id="email" name="email" type="email" autocomplete="email" placeholder="you@example.com" required>
        </div>
    </div>

    <button class="btn-edge" type="submit"><i class="bi bi-send me-2"></i>Send reset link</button>
</form>

<div class="auth-links">
    <a href="login.php"><i class="bi bi-arrow-left me-1"></i>Back to login</a>
    <a href="register.php">Create an account</a>
</div>
<?php require __DIR__ . '/include/auth-footer.php'; ?>
