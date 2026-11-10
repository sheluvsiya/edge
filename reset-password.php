<?php
declare(strict_types=1);
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';

require_guest();
$error = null;
$valid = false;
$userId = (int)($_GET['uid'] ?? $_POST['uid'] ?? 0);
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');

if ($userId > 0 && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $row = db_fetch_one('SELECT token_duration, token FROM reset_token WHERE user_id = ? AND token_duration > NOW() ORDER BY reset_token_id DESC LIMIT 1', [$userId]);
    if ($row && hash_equals($row['token'], hash('sha256', 'reset:' . $token))) {
        $valid = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (!$valid) {
        $error = 'This password reset link is invalid or has expired.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Password must be at least 8 characters and include uppercase, lowercase and a number.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            db_execute('UPDATE `user` SET password_hash = ?, account_status = \'active\' WHERE user_id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
            db_execute('DELETE FROM reset_token WHERE user_id = ?', [$userId]);
            flash('success', 'Your password has been changed. You can now sign in.');
            redirect('login.php');
        }
    }
}

$pageTitle = 'Reset password';
require __DIR__ . '/include/auth-layout.php';
?>
<h1 class="auth-title">Set a new password</h1>
<p class="auth-subtitle">Choose a new password for your 3D Edge account.</p>
<?php if ($error): ?><div class="alert auth-alert auth-alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e($error) ?></span></div><?php endif; ?>
<?php if (!$valid && !$error): ?><div class="alert auth-alert auth-alert-danger" role="alert"><i class="bi bi-link-45deg"></i><span>This password reset link is invalid or has expired. Please request a new one.</span></div><?php endif; ?>
<?php if ($valid): ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="uid" value="<?= e((string)$userId) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div class="mb-3">
        <label class="form-label" for="password">New password</label>
        <div class="input-group">
            <span class="input-group-text auth-input-icon"><i class="bi bi-lock"></i></span>
            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" placeholder="Create a new password" required>
            <button class="btn password-toggle" type="button" onclick="togglePassword('password', this)" aria-label="Show password"><i class="bi bi-eye"></i></button>
        </div>
        <div class="password-hint">8+ characters, uppercase, lowercase and a number.</div>
    </div>

    <div class="mb-4">
        <label class="form-label" for="confirm_password">Confirm new password</label>
        <div class="input-group">
            <span class="input-group-text auth-input-icon"><i class="bi bi-shield-lock"></i></span>
            <input class="form-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" placeholder="Confirm your new password" required>
            <button class="btn password-toggle" type="button" onclick="togglePassword('confirm_password', this)" aria-label="Show password"><i class="bi bi-eye"></i></button>
        </div>
    </div>

    <button class="btn-edge" type="submit"><i class="bi bi-check2-circle me-2"></i>Update password</button>
</form>
<?php else: ?><div class="text-center mt-3"><a class="auth-inline-link" href="forgot-password.php">Request a new reset link</a></div><?php endif; ?>
<?php require __DIR__ . '/include/auth-footer.php'; ?>
