<?php
declare(strict_types=1);
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';

$userId = (int)($_GET['uid'] ?? 0);
$token = (string)($_GET['token'] ?? '');
$verified = false;
$error = null;

if ($userId > 0 && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $row = db_fetch_one('SELECT reset_token_id, token_duration, token FROM reset_token WHERE user_id = ? AND token_duration > NOW() ORDER BY reset_token_id DESC LIMIT 1', [$userId]);
    if ($row && hash_equals($row['token'], hash('sha256', 'verify:' . $token))) {
        db_execute("UPDATE `user` SET account_status = 'active' WHERE user_id = ?", [$userId]);
        db_execute('DELETE FROM reset_token WHERE user_id = ?', [$userId]);
        $verified = true;
    }
}

$pageTitle = 'Verify email';
require __DIR__ . '/include/auth-layout.php';
?>
<div class="text-center">
    <?php if ($verified): ?>
        <div style="font-size:54px;margin-bottom:12px;">✓</div>
        <h1 class="auth-title">Email verified</h1>
        <p class="auth-subtitle">Your 3D Edge account is now active.</p>
        <a class="btn btn-edge d-flex align-items-center justify-content-center" href="login.php">Continue to login</a>
    <?php else: ?>
        <div style="font-size:54px;margin-bottom:12px;">!</div>
        <h1 class="auth-title">Verification failed</h1>
        <p class="auth-subtitle">This verification link is invalid or has expired. Please register again or contact the site administrator.</p>
        <a class="btn btn-edge d-flex align-items-center justify-content-center" href="register.php">Create an account</a>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/include/auth-footer.php'; ?>
