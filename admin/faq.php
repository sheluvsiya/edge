<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
admin_post_guard();

// Two tabs: questions shown on the website, and hidden ones.
const FAQ_TABS = ['published' => 'Published', 'hidden' => 'Hidden'];

/** The tab the admin was looking at when they pressed a button (so they stay on it). */
function posted_tab(): string
{
    $tab = (string)($_POST['return_tab'] ?? '');
    return isset(FAQ_TABS[$tab]) ? $tab : 'published';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['faq_id'] ?? 0);

    if ($action === 'save') {
        $question = trim((string)($_POST['question'] ?? ''));
        $answer   = trim((string)($_POST['answer'] ?? ''));
        $order    = max(0, (int)($_POST['sort_order'] ?? 0));
        $published = isset($_POST['is_published']) ? 1 : 0;
        if ($question === '' || $answer === '') {
            flash('error', 'Both the question and the answer are needed.');
            admin_redirect('faq.php');
        }
        if (strlen($question) > 255) {
            flash('error', 'Keep the question under 255 characters.');
            admin_redirect('faq.php');
        }
        if ($id > 0) {
            db_execute('UPDATE faq SET question = ?, answer = ?, sort_order = ?, is_published = ?, updated_at = NOW() WHERE faq_id = ? AND deleted_at IS NULL',
                [$question, $answer, $order, $published, $id]);
            flash('success', 'FAQ updated.');
        } else {
            db_execute('INSERT INTO faq (question, answer, sort_order, is_published) VALUES (?, ?, ?, ?)',
                [$question, $answer, $order, $published]);
            flash('success', 'FAQ added.');
        }
        admin_redirect('faq.php?tab=' . ($published === 1 ? 'published' : 'hidden'));
    }

    if ($action === 'toggle' && $id > 0) {
        db_execute('UPDATE faq SET is_published = 1 - is_published, updated_at = NOW() WHERE faq_id = ? AND deleted_at IS NULL', [$id]);
        flash('success', 'FAQ visibility changed.');
        admin_redirect('faq.php?tab=' . posted_tab());
    }

    if ($action === 'delete' && $id > 0) {
        // Soft delete: kept in the database and listed under Deleted items, where it can be restored.
        db_execute('UPDATE faq SET deleted_at = NOW(), is_published = 0 WHERE faq_id = ? AND deleted_at IS NULL', [$id]);
        flash('success', 'FAQ moved to Deleted items.');
        admin_redirect('faq.php?tab=' . posted_tab());
    }
}

// The faq table comes from admin/sql/admin_update.sql. If it has not been created yet, explain that
// instead of stopping with a database error.
$faqTableMissing = false;
try {
    $rows = db_fetch_all('SELECT faq_id, question, answer, sort_order, is_published FROM faq WHERE deleted_at IS NULL ORDER BY sort_order, faq_id');
} catch (Throwable $e) {
    $rows = [];
    $faqTableMissing = true;
}
$published = count(array_filter($rows, fn($r) => (int)$r['is_published'] === 1));
$hiddenCount = count($rows) - $published;

/** The FAQ form, used for both "Add" and "Edit" pop-outs. */
function faq_modal(string $modalId, array $f): void
{
    $fid = (int)($f['faq_id'] ?? 0);
    $isNew = $fid === 0;
    ?>
<div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post">
      <?php admin_csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="faq_id" value="<?= $fid ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="<?= e($modalId) ?>-title"><?= $isNew ? 'Add FAQ' : 'Edit FAQ #' . $fid ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Question</label>
          <input class="form-control" name="question" maxlength="255" required value="<?= e((string)($f['question'] ?? '')) ?>" placeholder="e.g. What file formats do you accept?"></div>
        <div class="mb-3"><label class="form-label">Answer</label>
          <textarea class="form-control faq-editor" name="answer" rows="10" required placeholder="Write the answer customers will see…"><?= e((string)($f['answer'] ?? '')) ?></textarea></div>
        <div class="row g-3 align-items-end">
          <div class="col-md-4"><label class="form-label">Display order</label>
            <input class="form-control" type="number" min="0" name="sort_order" value="<?= (int)($f['sort_order'] ?? 0) ?>">
            <div class="form-text">Lower numbers appear first.</div></div>
          <div class="col-md-8">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="<?= e($modalId) ?>-pub" name="is_published" value="1" <?= ($isNew || (int)($f['is_published'] ?? 0) === 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="<?= e($modalId) ?>-pub">Show this question on the website</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-edge"><?= $isNew ? 'Add FAQ' : 'Save changes' ?></button>
      </div>
    </form>
  </div>
</div>
    <?php
}

admin_header('FAQ');
admin_page_head(
    'FAQ',
    'Questions and answers for the website. Write them here; hide a question to take it off the site without deleting it.',
    '<button type="button" class="btn btn-edge" data-bs-toggle="modal" data-bs-target="#faq-add"' . ($faqTableMissing ? ' disabled' : admin_write_attr()) . '><i class="mdi mdi-plus me-1"></i>Add FAQ</button>'
);

if ($faqTableMissing): ?>
    <div class="alert alert-warning" role="alert"><strong>The FAQ table has not been created yet.</strong>
        Run <code>admin/sql/admin_update.sql</code> once in phpMyAdmin (3d_edge &gt; SQL tab), then reload this page.</div>
<?php endif;

$activeTab = admin_tabs([
    'published' => [FAQ_TABS['published'], $published],
    'hidden'    => [FAQ_TABS['hidden'], $hiddenCount],
]);
// Keep only the questions of the open tab.
$rows = array_values(array_filter($rows, fn($r) => ((int)$r['is_published'] === 1) === ($activeTab === 'published')));
$emptyText = $activeTab === 'hidden'
    ? 'No hidden questions. Hide a question to take it off the website without deleting it.'
    : 'No published questions. Use “Add FAQ” to write one.';
?>
<div class="faq-list">
<?php if (!$rows): ?>
    <div class="card"><div class="card-body empty-state"><i class="mdi mdi-help-circle-outline"></i><p><?= e($emptyText) ?></p></div></div>
<?php endif; ?>
<?php foreach ($rows as $r): $fid = (int)$r['faq_id']; $live = (int)$r['is_published'] === 1; ?>
    <article class="card faq-row">
        <div class="faq-order" title="Display order"><?= (int)$r['sort_order'] ?></div>
        <div class="faq-main">
            <div class="faq-question"><?= e((string)$r['question']) ?></div>
            <p class="faq-answer"><?= e(admin_excerpt((string)$r['answer'], 220)) ?></p>
        </div>
        <div class="faq-side">
            <div class="faq-actions">
                <?= admin_status($live ? 'published' : 'hidden', !$live) ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#faq-edit-<?= $fid ?>"<?= admin_write_attr() ?>><i class="mdi mdi-pencil-outline"></i> Edit</button>
                <form method="post">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                    <input type="hidden" name="faq_id" value="<?= $fid ?>">
                    <button class="btn btn-sm btn-outline-secondary"<?= admin_write_attr() ?>><?= $live ? 'Hide' : 'Publish' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Move this question to Deleted items?')">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                    <input type="hidden" name="faq_id" value="<?= $fid ?>">
                    <button class="btn btn-sm btn-outline-danger" aria-label="Delete"<?= admin_write_attr() ?>><i class="mdi mdi-delete-outline"></i></button>
                </form>
            </div>
        </div>
    </article>
<?php endforeach; ?>
</div>

<?php faq_modal('faq-add', []); ?>
<?php foreach ($rows as $r): faq_modal('faq-edit-' . (int)$r['faq_id'], $r); endforeach; ?>
<?php admin_footer(); ?>
