<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
admin_post_guard();

// Statuses an admin can set from the Pending / Approved tabs.
const SHOWN_REVIEW_STATUSES = ['pending', 'approved'];
// One tab per status (the tab key is the status itself). Declined reviews are stored as "rejected"
// and are also kept in Deleted items, so they can be restored from there or from the Declined tab.
const REVIEW_TABS = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Declined'];

/** The tab the admin was looking at when they pressed a button (so they stay on it). */
function posted_tab(): string
{
    $tab = (string)($_POST['return_tab'] ?? '');
    return isset(REVIEW_TABS[$tab]) ? $tab : 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['review_id'] ?? 0);

    if ($action === 'moderate' && $id > 0) {
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, SHOWN_REVIEW_STATUSES, true)) {
            flash('error', 'Invalid review status.');
        } else {
            db_execute('UPDATE review SET status = ?, updated_at = NOW() WHERE review_id = ? AND deleted_at IS NULL', [$status, $id]);
            flash('success', 'Review ' . $status . '.');
        }
        admin_redirect('reviews.php?tab=' . posted_tab());
    }
    if ($action === 'decline' && $id > 0) {
        // Declined: removed from display and kept in Deleted items (product pages hide it too).
        db_execute("UPDATE review SET status = 'rejected', deleted_at = NOW(), updated_at = NOW() WHERE review_id = ? AND deleted_at IS NULL", [$id]);
        flash('success', 'Review declined. You can find it on the Declined tab.');
        admin_redirect('reviews.php?tab=' . posted_tab());
    }
    if ($action === 'restore' && $id > 0) {
        // Put a declined review back in the Pending list so it can be looked at again.
        db_execute("UPDATE review SET status = 'pending', deleted_at = NULL, updated_at = NOW() WHERE review_id = ? AND status = 'rejected'", [$id]);
        flash('success', 'Review restored to Pending.');
        admin_redirect('reviews.php?tab=pending');
    }
}

$rows = db_fetch_all(
    "SELECT r.review_id, r.order_id, r.rating, r.comment, r.status, r.date_posted, r.updated_at,
            u.username, c.first_name, c.last_name, p.product_name
     FROM review r
     JOIN customer c ON c.customer_id = r.customer_id
     JOIN `user` u ON u.user_id = c.user_id
     JOIN product p ON p.product_id = r.product_id
     WHERE (r.status IN ('pending', 'approved') AND r.deleted_at IS NULL) OR r.status = 'rejected'
     ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.review_id DESC"
);

admin_header('Reviews');
admin_page_head('Customer reviews', 'Approve or decline what customers write. Approved reviews show on product pages; declined reviews stay on the Declined tab, where they can be restored.');
$tabCounts = [];
foreach (REVIEW_TABS as $key => $label) {
    $tabCounts[$key] = [$label, count(array_filter($rows, fn($r) => $r['status'] === $key))];
}
$activeTab = admin_tabs($tabCounts);
// Keep only the reviews of the open tab.
$rows = array_values(array_filter($rows, fn($r) => $r['status'] === $activeTab));
$emptyText = 'No ' . strtolower(REVIEW_TABS[$activeTab]) . ' reviews.';

// Photos the customer attached (shown on the product page once the review is approved).
$photosByReview = [];
if ($rows) {
    $reviewIds = array_map(static fn($r) => (int)$r['review_id'], $rows);
    foreach (db_fetch_all('SELECT review_id, review_img_url FROM review_img WHERE review_id IN (' . implode(',', array_fill(0, count($reviewIds), '?')) . ') ORDER BY review_img_id', $reviewIds) as $photo) {
        $photosByReview[(int)$photo['review_id']][] = (string)$photo['review_img_url'];
    }
}
?>
<div class="card table-card">
<div class="table-responsive">
<table class="table admin-table table-wide reviews-table align-middle">
    <thead><tr>
        <th>Review</th><th>Rating</th><th>Customer</th><th>Product</th><th>Order</th><th>Comment</th><th>Photos</th>
        <th>Posted</th><th><?= $activeTab === 'rejected' ? 'Declined' : 'Updated' ?></th><th class="col-actions text-end">Actions</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="10" class="empty-cell"><?= e($emptyText) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $rid = (int)$r['review_id']; $stars = max(0, min(5, (int)$r['rating'])); $photos = $photosByReview[$rid] ?? []; ?>
        <tr>
            <td data-label="Review">#<?= $rid ?></td>
            <td data-label="Rating"><div class="stars" aria-label="<?= $stars ?> out of 5"><?= str_repeat('★', $stars) ?><span><?= str_repeat('★', 5 - $stars) ?></span></div><div class="small text-muted"><?= $stars ?> / 5</div></td>
            <td data-label="Customer"><strong><?= e((string)$r['username']) ?></strong><div class="small text-muted"><?= e(trim((string)$r['first_name'] . ' ' . (string)$r['last_name'])) ?></div></td>
            <td data-label="Product"><?= e((string)$r['product_name']) ?></td>
            <td data-label="Order"><?= !empty($r['order_id']) ? e(admin_ref('3DE', (int)$r['order_id'])) : admin_dash() ?></td>
            <td data-label="Comment" class="cell-wrap"><?= trim((string)($r['comment'] ?? '')) !== '' ? nl2br(e((string)$r['comment'])) : admin_dash() ?></td>
            <td data-label="Photos"><?php if ($photos): ?>
                <div class="review-thumbs"><?php foreach ($photos as $photo): ?><a href="uploads/<?= e($photo) ?>" target="_blank" rel="noopener"><img class="review-thumb" src="uploads/<?= e($photo) ?>" alt="Photo attached to review <?= $rid ?>"></a><?php endforeach; ?></div>
            <?php else: ?><?= admin_dash() ?><?php endif; ?></td>
            <td data-label="Posted"><?= admin_date($r['date_posted'] ?? null) ?></td>
            <td data-label="<?= $activeTab === 'rejected' ? 'Declined' : 'Updated' ?>"><?= admin_date($r['updated_at'] ?? null) ?></td>
            <td data-label="Actions" class="col-actions">
                <div class="row-actions">
                <?php if ($activeTab === 'rejected'): ?>
                    <form method="post">
                        <?php admin_csrf_field(); ?>
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                        <input type="hidden" name="review_id" value="<?= $rid ?>">
                        <button class="btn btn-sm btn-edge"<?= admin_write_attr() ?>><i class="mdi mdi-undo-variant"></i> Restore to Pending</button>
                    </form>
                <?php else: ?>
                    <form method="post">
                        <?php admin_csrf_field(); ?>
                        <input type="hidden" name="action" value="moderate">
                        <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                        <input type="hidden" name="review_id" value="<?= $rid ?>">
                        <input type="hidden" name="status" value="<?= $activeTab === 'pending' ? 'approved' : 'pending' ?>">
                        <button class="btn btn-sm <?= $activeTab === 'pending' ? 'btn-edge' : 'btn-outline-secondary' ?>"<?= admin_write_attr() ?>><?= $activeTab === 'pending' ? '<i class="mdi mdi-check"></i> Approve' : 'Move to Pending' ?></button>
                    </form>
                    <form method="post" onsubmit="return confirm('Decline this review? It will move to the Declined tab and be hidden from the shop.')">
                        <?php admin_csrf_field(); ?>
                        <input type="hidden" name="action" value="decline">
                        <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                        <input type="hidden" name="review_id" value="<?= $rid ?>">
                        <button class="btn btn-sm btn-outline-danger"<?= admin_write_attr() ?>><i class="mdi mdi-close-circle-outline"></i> Decline</button>
                    </form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<?php admin_footer(); ?>
