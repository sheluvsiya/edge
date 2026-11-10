<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
admin_post_guard('any'); // each restore is checked against its own section below

// Restore actions. Nothing is ever erased from the database; restoring only puts the record back.
$restorers = [
    'product'  => static function (int $id): void {
        db_execute("UPDATE product SET deleted_at = NULL, status = 'unavailable' WHERE product_id = ? AND deleted_at IS NOT NULL", [$id]);
    },
    'material' => static function (int $id): void {
        db_execute("UPDATE material SET deleted_at = NULL, status = 'unavailable' WHERE material_id = ? AND deleted_at IS NOT NULL", [$id]);
    },
    'review'   => static function (int $id): void {
        db_execute("UPDATE review SET deleted_at = NULL, status = 'pending' WHERE review_id = ? AND deleted_at IS NOT NULL", [$id]);
    },
    'news'     => static function (int $id): void {
        $conn = db();
        $conn->begin_transaction();
        try {
            db_execute('INSERT INTO news (news_id, admin_id, content, image)
                        SELECT news_id, admin_id, content, image FROM news_trash WHERE news_id = ?', [$id]);
            db_execute('DELETE FROM news_trash WHERE news_id = ?', [$id]);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    },
    'faq'      => static function (int $id): void {
        db_execute('UPDATE faq SET deleted_at = NULL, is_published = 0 WHERE faq_id = ? AND deleted_at IS NOT NULL', [$id]);
    },
    'gallery'  => static function (int $id): void {
        $conn = db();
        $conn->begin_transaction();
        try {
            db_execute('INSERT INTO gallery_img (gallery_id, gallery_image_url, gallery_alt_text)
                        SELECT gallery_id, gallery_image_url, gallery_alt_text FROM gallery_img_trash WHERE gallery_id = ?', [$id]);
            db_execute('DELETE FROM gallery_img_trash WHERE gallery_id = ?', [$id]);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    },
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $type = (string)($_POST['type'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $section = TRASH_SECTION_MAP[$type] ?? null;
    if ($section === null || $id <= 0 || !isset($restorers[$type])) {
        flash('error', 'Invalid restore request.');
    } elseif (!admin_can_write($section)) {
        flash('error', 'Your category cannot restore ' . $section . '.');
    } else {
        try {
            $restorers[$type]($id);
            flash('success', 'Item restored.');
        } catch (Throwable $e) {
            flash('error', 'That item could not be restored (it may already exist again).');
        }
    }
    admin_redirect('trash.php?tab=' . ($section ?? 'products'));
}

$data = [
    'products'  => ['Products',  db_fetch_all("SELECT product_id AS id, product_name AS name, deleted_at FROM product WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"), 'product'],
    'materials' => ['Materials', db_fetch_all("SELECT material_id AS id, material_type AS name, deleted_at FROM material WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"), 'material'],
    'reviews'   => ['Reviews',   db_fetch_all("SELECT r.review_id AS id, CONCAT('Review on ', p.product_name, ' (', r.rating, ' stars)') AS name, r.deleted_at
                                               FROM review r JOIN product p ON p.product_id = r.product_id
                                               WHERE r.deleted_at IS NOT NULL ORDER BY r.deleted_at DESC"), 'review'],
    'news'      => ['News',      db_fetch_all("SELECT news_id AS id, LEFT(content, 120) AS name, deleted_at FROM news_trash ORDER BY deleted_at DESC"), 'news'],
    'faq'       => ['FAQ',       db_fetch_all("SELECT faq_id AS id, question AS name, deleted_at FROM faq WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"), 'faq'],
    'gallery'   => ['Gallery',   db_fetch_all("SELECT gallery_id AS id, gallery_image_url, gallery_alt_text AS name, deleted_at FROM gallery_img_trash ORDER BY deleted_at DESC"), 'gallery'],
];

admin_header('Deleted items');
admin_page_head('Deleted items', 'Deleted records are kept in the database and hidden from the admin and the website. Each section has its own tab; restore puts a record back.');

$tabs = [];
foreach ($data as $key => [$label, $items]) { $tabs[$key] = [$label, count($items)]; }
$active = admin_tabs($tabs);
[, $items, $type] = $data[$active];
?>
<div class="card table-card">
<?php if (!$items): ?>
    <p class="empty-cell mb-0">Nothing deleted in <?= e(strtolower($data[$active][0])) ?>.</p>
<?php else: ?>
<div class="table-responsive">
<table class="table admin-table align-middle">
    <thead><tr><th>ID</th><th><?= $type === 'gallery' ? 'Image' : 'Item' ?></th><th>Deleted</th><th class="text-end">Restore</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
        <tr>
            <td data-label="ID">#<?= (int)$it['id'] ?></td>
            <td data-label="Item" class="cell-wrap">
                <?php if ($type === 'gallery'): ?><img class="thumb" src="../<?= e((string)$it['gallery_image_url']) ?>" alt=""><?php endif; ?>
                <?= e((string)$it['name']) ?>
            </td>
            <td data-label="Deleted"><?= e(date('d M Y H:i', strtotime((string)$it['deleted_at']))) ?></td>
            <td data-label="Restore" class="text-end">
                <?php if (admin_can_write(TRASH_SECTION_MAP[$type])): ?>
                <form method="post" class="d-inline">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="type" value="<?= e($type) ?>">
                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                    <button class="btn btn-sm btn-outline-primary"><i class="mdi mdi-restore"></i> Restore</button>
                </form>
                <?php else: ?>
                    <span class="status-pill is-muted">View only</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php admin_footer(); ?>
