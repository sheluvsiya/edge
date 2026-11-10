<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
admin_post_guard();

const GALLERY_DIR = 'gallery';
$allowedImage = ['jpg', 'jpeg', 'png', 'webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'upload') {
        $alt = trim((string)($_POST['alt_text'] ?? ''));
        $saved = 0;
        $errors = [];
        foreach (admin_files_indexed('gallery_images') as $file) {
            $result = admin_store_image($file, GALLERY_DIR, 8388608);
            if (!$result['ok']) { $errors[] = $result['error']; continue; }
            $text = $alt !== '' ? $alt : pathinfo((string)$file['name'], PATHINFO_FILENAME);
            // Stored root-relative: the Reviews & Gallery page uses this path directly.
            db_execute('INSERT INTO gallery_img (gallery_image_url, gallery_alt_text) VALUES (?, ?)',
                ['admin/uploads/' . GALLERY_DIR . '/' . $result['file'], substr($text, 0, 255)]);
            $saved++;
        }
        if ($saved) { flash('success', "Uploaded $saved image(s) to the gallery."); }
        if ($errors) { flash('error', implode(' ', $errors)); }
        if (!$saved && !$errors) { flash('error', 'Choose at least one image to upload.'); }
        admin_redirect('gallery.php');
    }

    if ($action === 'update') {
        $id = (int)($_POST['gallery_id'] ?? 0);
        $alt = substr(trim((string)($_POST['alt_text'] ?? '')), 0, 255);
        $newUrl = null;
        $files = admin_files_indexed('replacement_image');
        if ($files) {
            $result = admin_store_image(reset($files), GALLERY_DIR, 8388608);
            if (!$result['ok']) { flash('error', $result['error']); admin_redirect('gallery.php'); }
            $newUrl = 'admin/uploads/' . GALLERY_DIR . '/' . $result['file'];
        }
        if ($id > 0 && $newUrl !== null) {
            db_execute('UPDATE gallery_img SET gallery_alt_text = ?, gallery_image_url = ? WHERE gallery_id = ?', [$alt, $newUrl, $id]);
        } elseif ($id > 0) {
            db_execute('UPDATE gallery_img SET gallery_alt_text = ? WHERE gallery_id = ?', [$alt, $id]);
        }
        flash('success', 'Gallery image updated.');
        admin_redirect('gallery.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['gallery_id'] ?? 0);
        // Move to gallery_img_trash instead of erasing. The customer gallery reads gallery_img only.
        $conn = db();
        $conn->begin_transaction();
        try {
            db_execute('INSERT INTO gallery_img_trash (gallery_id, gallery_image_url, gallery_alt_text, deleted_at)
                        SELECT gallery_id, gallery_image_url, gallery_alt_text, NOW() FROM gallery_img WHERE gallery_id = ?', [$id]);
            db_execute('DELETE FROM gallery_img WHERE gallery_id = ?', [$id]);
            $conn->commit();
            flash('success', 'Gallery image moved to Deleted items.');
        } catch (Throwable $e) {
            $conn->rollback();
            flash('error', 'That image could not be deleted.');
        }
        admin_redirect('gallery.php');
    }
}

$images = db_fetch_all('SELECT gallery_id, gallery_image_url, gallery_alt_text FROM gallery_img ORDER BY gallery_id DESC');

admin_header('Gallery');
admin_page_head(
    'Gallery',
    'Images here appear on the Reviews & Gallery page of the website. Edit a description or replace an image, or move an image to Deleted items.',
    '<button type="button" class="btn btn-edge" data-bs-toggle="modal" data-bs-target="#gallery-upload"><i class="mdi mdi-cloud-upload-outline me-1"></i>Upload images</button>'
);
?>
<?php if (!$images): ?>
    <div class="card"><div class="card-body empty-state"><i class="mdi mdi-image-multiple-outline"></i><p>No gallery images yet. Use “Upload images” to add some.</p></div></div>
<?php endif; ?>

<div class="gallery-grid">
<?php foreach ($images as $img): $gid = (int)$img['gallery_id']; ?>
    <article class="card gallery-card">
        <div class="gallery-frame"><img src="../<?= e((string)$img['gallery_image_url']) ?>" alt="<?= e((string)$img['gallery_alt_text']) ?>"></div>
        <div class="card-body">
            <p class="gallery-caption mb-3"><?= e((string)($img['gallery_alt_text'] ?: 'No description')) ?></p>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" data-bs-toggle="modal" data-bs-target="#gallery-edit-<?= $gid ?>"><i class="mdi mdi-pencil-outline me-1"></i>Edit</button>
                <form method="post" onsubmit="return confirm('Move this image to Deleted items? It will disappear from the gallery.')">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="gallery_id" value="<?= $gid ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="mdi mdi-delete-outline"></i></button>
                </form>
            </div>
        </div>
    </article>
<?php endforeach; ?>
</div>

<div class="modal fade" id="gallery-upload" tabindex="-1" aria-labelledby="gallery-upload-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php admin_csrf_field(); ?>
      <input type="hidden" name="action" value="upload">
      <div class="modal-header"><h5 class="modal-title" id="gallery-upload-title">Upload gallery images</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Images</label>
          <input class="form-control" type="file" name="gallery_images[]" multiple required accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
          <div class="form-text">JPG, PNG or WEBP, up to 8 MB each. Select several at once.</div></div>
        <div class="mb-1"><label class="form-label">Description (optional)</label>
          <input class="form-control" type="text" name="alt_text" maxlength="255" placeholder="e.g. Carbon fibre bracket print"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-edge">Upload</button></div>
    </form>
  </div>
</div>

<?php foreach ($images as $img): $gid = (int)$img['gallery_id']; ?>
<div class="modal fade" id="gallery-edit-<?= $gid ?>" tabindex="-1" aria-labelledby="gallery-edit-title-<?= $gid ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php admin_csrf_field(); ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="gallery_id" value="<?= $gid ?>">
      <div class="modal-header"><h5 class="modal-title" id="gallery-edit-title-<?= $gid ?>">Edit image #<?= $gid ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <img src="../<?= e((string)$img['gallery_image_url']) ?>" alt="" class="modal-preview mb-3">
        <div class="mb-3"><label class="form-label">Description</label>
          <input class="form-control" type="text" name="alt_text" maxlength="255" value="<?= e((string)$img['gallery_alt_text']) ?>"></div>
        <div class="mb-1"><label class="form-label">Replace image (optional)</label>
          <input class="form-control" type="file" name="replacement_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-edge">Save changes</button></div>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php admin_footer(); ?>
