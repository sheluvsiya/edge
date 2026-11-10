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
            $user = db_fetch_one("SELECT user_id, username, email FROM `user` WHERE email = ? AND account_status = 'inactive' LIMIT 1", [$email]);
            if ($user) {
                db_execute('DELETE FROM reset_token WHERE user_id = ?', [(int)$user['user_id']]);
                $token = create_token();
                $hash = hash('sha256', 'verify:' . $token);
                $expires = date('Y-m-d H:i:s', time() + VERIFY_TOKEN_MINUTES * 60);
                db_execute('INSERT INTO reset_token (user_id, token_duration, token) VALUES (?, ?, ?)', [(int)$user['user_id'], $expires, $hash]);
                send_verification_email($user['email'], $user['username'], $token, (int)$user['user_id']);
            }
            flash('success', 'If that account needs verification, a new verification email has been sent.');
            redirect('resend-verification.php');
        }
    }
}

$pageTitle = 'Resend verification';
require __DIR__ . '/include/auth-layout.php';
?>
<h1 class="auth-title">Verify your email</h1>
<p class="auth-subtitle">Enter your email address to receive a new verification link.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="mb-4"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" required></div>
    <button class="btn-edge" type="submit">Send verification email</button>
</form>
<div class="auth-links"><a href="login.php">Back to login</a></div>
<?php require __DIR__ . '/include/auth-footer.php'; ?>
