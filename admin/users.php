<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
admin_post_guard();


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_role') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $role   = (string)($_POST['role'] ?? '');
    $type   = (string)($_POST['admin_type'] ?? 'user');
    $me     = (int)$adminUser['user_id'];

    if ($userId === $me) {
        flash('error', 'You cannot change your own role here.');
        admin_redirect('users.php');
    }
    if (!in_array($role, ['customer', 'admin'], true) || !in_array($type, ADMIN_TYPES, true)) {
        flash('error', 'Invalid role selection.');
        admin_redirect('users.php');
    }
    $exists = db_fetch_one('SELECT user_id, username FROM `user` WHERE user_id = ?', [$userId]);
    if (!$exists) {
        flash('error', 'That user no longer exists.');
        admin_redirect('users.php');
    }

    $conn = db();
    $conn->begin_transaction();
    try {
        if ($role === 'admin') {
            db_execute("UPDATE `user` SET role = 'admin' WHERE user_id = ?", [$userId]);
            $adminRow = db_fetch_one('SELECT admin_id FROM admin WHERE user_id = ? LIMIT 1', [$userId]);
            if ($adminRow) {
                db_execute('UPDATE admin SET admin_type = ? WHERE user_id = ?', [$type, $userId]);
            } else {
                db_execute('INSERT INTO admin (user_id, admin_type) VALUES (?, ?)', [$userId, $type]);
            }
            $message = $exists['username'] . ' is now an administrator.';
            $tab = 'admins';
        } else {
            db_execute("UPDATE `user` SET role = 'customer' WHERE user_id = ?", [$userId]);
            // The admin row is kept because news and quotes reference it. Login only grants
            // admin access when role = 'admin', so this account is now a customer.
            $adminRow = db_fetch_one('SELECT admin_id FROM admin WHERE user_id = ? LIMIT 1', [$userId]);
            if ($adminRow) {
                db_execute('DELETE FROM admin_session WHERE admin_id = ?', [(int)$adminRow['admin_id']]);
            }
            $message = $exists['username'] . ' is now a customer.';
            $tab = 'customers';
        }
        $conn->commit();
        flash('success', $message);
    } catch (Throwable $e) {
        $conn->rollback();
        flash('error', 'The role could not be saved. Please try again.');
        $tab = 'customers';
    }
    admin_redirect('users.php?tab=' . $tab);
}

$rows = db_fetch_all(
    'SELECT u.user_id, u.username, u.email, u.phone_num, u.role, a.admin_type
     FROM `user` u
     LEFT JOIN admin a ON a.user_id = u.user_id
     ORDER BY u.username'
);
$customers = array_values(array_filter($rows, fn($r) => $r['role'] !== 'admin'));
$admins    = array_values(array_filter($rows, fn($r) => $r['role'] === 'admin'));

admin_header('Users & admins');
admin_page_head(
    'Users & administrators',
    'Customers and administrators are listed on separate tabs. Changing a role or category saves straight away.'
);
$active = admin_tabs(['customers' => ['Customers', count($customers)], 'admins' => ['Administrators', count($admins)]]);
$list = $active === 'admins' ? $admins : $customers;
$isAdminTab = $active === 'admins';
?>
<div class="card table-card">
<div class="table-responsive">
<table class="table admin-table align-middle">
    <thead><tr>
        <th>User ID</th><th>Username</th><th>Email</th><th>Phone number</th><th>Role</th>
        <?php if ($isAdminTab): ?><th>Category</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php if (!$list): ?>
        <tr><td colspan="<?= $isAdminTab ? 6 : 5 ?>" class="empty-cell"><?= $isAdminTab ? 'No administrators yet.' : 'No customers yet.' ?></td></tr>
    <?php endif; ?>
    <?php foreach ($list as $r):
        $uid = (int)$r['user_id'];
        $isMe = $uid === (int)$adminUser['user_id'];
        $formId = 'user-' . $uid;
    ?>
        <tr>
            <td data-label="User ID"><?= $uid ?></td>
            <td data-label="Username"><strong><?= e((string)$r['username']) ?></strong><?= $isMe ? ' <span class="status-pill">You</span>' : '' ?></td>
            <td data-label="Email"><?= e((string)$r['email']) ?></td>
            <td data-label="Phone number"><?= e((string)($r['phone_num'] ?? '') ?: '—') ?></td>
            <td data-label="Role">
                <?php if ($isMe): ?>
                    Administrator
                <?php else: ?>
                    <form id="<?= e($formId) ?>" method="post" data-autosave>
                        <?php admin_csrf_field(); ?>
                        <input type="hidden" name="action" value="set_role">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                    </form>
                    <select class="form-select form-select-sm" form="<?= e($formId) ?>" name="role" aria-label="Role for <?= e((string)$r['username']) ?>">
                        <option value="customer" <?= $r['role'] === 'customer' ? 'selected' : '' ?>>Customer</option>
                        <option value="admin" <?= $r['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                    </select>
                    <noscript><button class="btn btn-sm btn-edge" form="<?= e($formId) ?>">Save</button></noscript>
                <?php endif; ?>
            </td>
            <?php if ($isAdminTab): ?>
            <td data-label="Category">
                <?php if ($isMe): ?>
                    <?= e(ADMIN_TYPE_LABELS[(string)($r['admin_type'] ?? 'user')] ?? 'Super') ?>
                <?php else: ?>
                    <select class="form-select form-select-sm" form="<?= e($formId) ?>" name="admin_type" aria-label="Category for <?= e((string)$r['username']) ?>">
                        <?php foreach (ADMIN_TYPE_LABELS as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ((string)($r['admin_type'] ?? 'user')) === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<?php if ($isAdminTab): ?>
<p class="page-note mt-3"><strong>Super</strong> can open every admin page. <strong>News</strong>, <strong>Orders</strong> and <strong>Quotes</strong> are saved for each administrator but not yet enforced page by page.</p>
<?php endif; ?>
<?php admin_footer(); ?>
