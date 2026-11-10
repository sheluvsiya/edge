<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
admin_post_guard();

$adminId = (int)($adminUser['admin_id'] ?? 0);
const NEWS_IMAGE_DIR = 'news';

/** Store an optional image for news; returns the root-relative path or null. Redirects on error. */
function news_image_from_upload(string $field): ?string
{
    $files = admin_files_indexed($field);
    if (!$files) {
        return null;
    }
    $result = admin_store_image(reset($files), NEWS_IMAGE_DIR);
    if (!$result['ok']) {
        flash('error', $result['error']);
        admin_redirect('news.php');
    }
    // Stored root-relative: the home page uses this path directly.
    return 'admin/uploads/' . NEWS_IMAGE_DIR . '/' . $result['file'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'publish') {
        $content = trim((string)($_POST['content'] ?? ''));
        if ($content === '') {
            flash('error', 'News content is required.');
            admin_redirect('news.php');
        }
        $imagePath = news_image_from_upload('news_image');
        try {
            db_execute('INSERT INTO news (admin_id, content, image) VALUES (?, ?, ?)', [$adminId, $content, $imagePath]);
            flash('success', 'News published. It now shows on the home page.');
        } catch (Throwable $e) {
            flash('error', 'The database would not save this news item: ' . $e->getMessage());
        }
        admin_redirect('news.php');
    }

    if ($action === 'update') {
        $id = (int)($_POST['news_id'] ?? 0);
        $content = trim((string)($_POST['content'] ?? ''));
        if ($id <= 0 || $content === '') {
            flash('error', 'News content cannot be empty.');
            admin_redirect('news.php');
        }
        $newImage = news_image_from_upload('news_image');
        try {
            if ($newImage !== null) {
                db_execute('UPDATE news SET content = ?, image = ? WHERE news_id = ?', [$content, $newImage, $id]);
            } else {
                db_execute('UPDATE news SET content = ? WHERE news_id = ?', [$content, $id]);
            }
            flash('success', 'News item updated.');
        } catch (Throwable $e) {
            flash('error', 'The database would not save this change: ' . $e->getMessage());
        }
        admin_redirect('news.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['news_id'] ?? 0);
        // Move to news_trash instead of erasing. The home page only reads the news table.
        $conn = db();
        $conn->begin_transaction();
        try {
            db_execute('INSERT INTO news_trash (news_id, admin_id, content, image, deleted_at)
                        SELECT news_id, admin_id, content, image, NOW() FROM news WHERE news_id = ?', [$id]);
            db_execute('DELETE FROM news WHERE news_id = ?', [$id]);
            $conn->commit();
            flash('success', 'News item moved to Deleted items.');
        } catch (Throwable $e) {
            $conn->rollback();
            flash('error', 'That news item could not be deleted.');
        }
        admin_redirect('news.php');
    }
}

$rows = db_fetch_all(
    'SELECT n.news_id, n.content, n.image, u.username
     FROM news n
     LEFT JOIN admin a ON a.admin_id = n.admin_id
     LEFT JOIN `user` u ON u.user_id = a.user_id
     ORDER BY n.news_id DESC'
);

admin_header('News');
admin_page_head(
    'News',
    'Publish announcements for the home page. The newest item is shown there; deleted items stay in Deleted items.',
    '<button type="button" class="btn btn-edge" data-bs-toggle="modal" data-bs-target="#news-create"><i class="mdi mdi-plus me-1"></i>Publish news</button>'
);
?>
<?php if (!$rows): ?>
    <div class="card"><div class="card-body empty-state"><i class="mdi mdi-newspaper-variant-outline"></i><p>No news published yet.</p></div></div>
<?php endif; ?>

<div class="news-list">
<?php foreach ($rows as $i => $r): $nid = (int)$r['news_id']; ?>
    <article class="card news-row">
        <div class="news-media">
            <?php if (!empty($r['image'])): ?>
                <img src="../<?= e((string)$r['image']) ?>" alt="News image">
            <?php else: ?>
                <span class="news-media-empty"><i class="mdi mdi-newspaper-variant-outline"></i></span>
            <?php endif; ?>
        </div>
        <div class="news-body">
            <div class="news-meta">
                <span>#<?= $nid ?> · by <?= e((string)($r['username'] ?? 'Admin')) ?></span>
                <?php if ($i === 0): ?><span class="status-pill">On home page</span><?php endif; ?>
            </div>
            <p class="news-clip"><?= e(admin_excerpt((string)$r['content'], 170)) ?></p>
            <div class="news-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#news-edit-<?= $nid ?>"><i class="mdi mdi-pencil-outline"></i> Edit</button>
                <form method="post" onsubmit="return confirm('Move this news item to Deleted items? It will disappear from the home page.')">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="news_id" value="<?= $nid ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="mdi mdi-delete-outline"></i> Delete</button>
                </form>
            </div>
        </div>
    </article>
<?php endforeach; ?>
</div>

<?php
// Pop-out: publish
?>
<div class="modal fade" id="news-create" tabindex="-1" aria-labelledby="news-create-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php admin_csrf_field(); ?>
      <input type="hidden" name="action" value="publish">
      <div class="modal-header"><h5 class="modal-title" id="news-create-title">Publish news</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Content</label>
          <textarea class="form-control news-editor" rows="10" required name="content" placeholder="Write the announcement…"></textarea></div>
        <div class="mb-1"><label class="form-label">Image (optional)</label>
          <input class="form-control" type="file" name="news_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
          <div class="form-text">JPG, PNG or WEBP, up to 5 MB.</div></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-edge">Publish</button></div>
    </form>
  </div>
</div>

<?php foreach ($rows as $r): $nid = (int)$r['news_id']; ?>
<div class="modal fade" id="news-edit-<?= $nid ?>" tabindex="-1" aria-labelledby="news-edit-title-<?= $nid ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php admin_csrf_field(); ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="news_id" value="<?= $nid ?>">
      <div class="modal-header"><h5 class="modal-title" id="news-edit-title-<?= $nid ?>">Edit news #<?= $nid ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Content</label>
          <textarea class="form-control news-editor" rows="16" required name="content"><?= e((string)$r['content']) ?></textarea></div>
        <?php if (!empty($r['image'])): ?>
          <div class="mb-3"><label class="form-label d-block">Current image</label>
            <img src="../<?= e((string)$r['image']) ?>" alt="" class="modal-preview modal-preview-lg"></div>
        <?php endif; ?>
        <div class="mb-1"><label class="form-label">Replace image (optional)</label>
          <input class="form-control" type="file" name="news_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-edge">Save changes</button></div>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php admin_footer(); ?>
