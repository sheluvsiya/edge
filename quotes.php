<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_files.php';
admin_post_guard();

// Every quote_status value the database allows.
const QUOTE_STATUSES = ['pending', 'reviewed', 'accepted', 'in_production', 'completed', 'declined'];

// Tabs, in order. "Paid" is not a quote status: a request is Paid when its order has been paid.
const QUOTE_TABS = [
    'pending'  => ['Pending',  ['pending']],
    'reviewed' => ['Reviewed', ['reviewed']],
    'paid'     => ['Paid',     null],
    'approved' => ['Approved', ['accepted', 'in_production']],
    'declined' => ['Declined', ['declined']],
    'shipped'  => ['Shipped',  ['completed']],
];

/** Does this quote row belong on the given tab? */
function quote_on_tab(array $row, string $tab): bool
{
    if ($tab === 'paid') {
        return !empty($row['is_paid']);
    }
    return in_array((string)$row['quote_status'], QUOTE_TABS[$tab][1], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $id       = (int)($_POST['quote_id'] ?? 0);
    $status   = (string)($_POST['quote_status'] ?? '');
    $estimate = trim((string)($_POST['estimated_price'] ?? ''));
    $final    = trim((string)($_POST['final_price'] ?? ''));
    $shipping = trim((string)($_POST['completion_date'] ?? ''));

    $valid = $id > 0 && in_array($status, QUOTE_STATUSES, true)
        && ($estimate === '' || (float)$estimate >= 0)
        && ($final === '' || (float)$final >= 0)
        && ($shipping === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $shipping));
    if (!$valid) {
        flash('error', 'Please check the status, prices and shipping date.');
        admin_redirect('quotes.php');
    }
    db_execute(
        'UPDATE quote SET quote_status = ?, estimated_price = ?, final_price = ?, completion_date = ? WHERE quote_id = ?',
        [$status,
         $estimate === '' ? null : (float)$estimate,
         $final === '' ? null : (float)$final,
         $shipping === '' ? null : $shipping . ' 00:00:00',
         $id]
    );
    flash('success', 'Quote #' . $id . ' updated.');
    // Stay on the tab the quote now belongs to (by its status).
    $tab = 'pending';
    foreach (QUOTE_TABS as $key => [, $statuses]) {
        if ($statuses !== null && in_array($status, $statuses, true)) { $tab = $key; break; }
    }
    admin_redirect('quotes.php?tab=' . $tab);
}

$rows = db_fetch_all(
    "SELECT q.quote_id, q.created_at, q.service_type, q.quantity, q.width, q.height, q.breadth,
            q.quote_description, q.file_url, q.quote_status, q.estimated_price, q.final_price, q.completion_date,
            u.username, u.email,
            EXISTS (SELECT 1 FROM order_item oi JOIN `order` o ON o.order_id = oi.order_id
                    WHERE oi.quote_id = q.quote_id AND o.order_status IN ('paid', 'shipping', 'delivered')) AS is_paid
     FROM quote q
     JOIN customer c ON c.customer_id = q.customer_id
     JOIN `user` u ON u.user_id = c.user_id
     ORDER BY q.quote_id DESC"
);

$quoteFiles = admin_quote_files();

// Count per tab, then keep only the rows of the active tab.
$tabCounts = [];
foreach (QUOTE_TABS as $key => [$label]) {
    $tabCounts[$key] = [$label, count(array_filter($rows, fn($r) => quote_on_tab($r, $key)))];
}
admin_header('Quote requests');
admin_page_head('Quote requests', 'Review customer quote requests, set estimates and final prices, and record when an order ships. Use the tabs to see each stage.');
$active  = admin_tabs($tabCounts);
$visible = array_values(array_filter($rows, fn($r) => quote_on_tab($r, $active)));
?>
<div class="card table-card">
<div class="table-responsive">
<table class="table admin-table align-middle">
    <thead>
        <tr>
            <th>Request #</th>
            <th>Username</th>
            <th>Details</th>
            <th>Files</th>
            <th>Update quote</th>
            <th>Estimated price</th>
            <th>Final price</th>
            <th>Shipping</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$visible): ?>
        <tr><td colspan="8" class="empty-cell">No requests in this tab.</td></tr>
    <?php endif; ?>
    <?php foreach ($visible as $r): $qid = (int)$r['quote_id']; $fid = 'quote-' . $qid; ?>
        <tr>
            <td data-label="Request #"><strong>#<?= $qid ?></strong><div class="text-muted small"><?= e(date('d M Y', strtotime((string)$r['created_at']))) ?></div></td>
            <td data-label="Username"><?= e((string)$r['username']) ?></td>
            <td data-label="Details">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#details-<?= $qid ?>">View details</button>
            </td>
            <td data-label="Files"><?= admin_files_button($fid, $quoteFiles[$qid] ?? []) ?></td>
            <td data-label="Update quote">
                <form id="<?= e($fid) ?>" method="post">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="quote_id" value="<?= $qid ?>">
                </form>
                <select class="form-select form-select-sm mb-2" form="<?= e($fid) ?>" name="quote_status" aria-label="Status for quote <?= $qid ?>">
                    <?php foreach (QUOTE_STATUSES as $s): ?>
                        <option value="<?= e($s) ?>" <?= $r['quote_status'] === $s ? 'selected' : '' ?>><?= e(admin_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-edge w-100" form="<?= e($fid) ?>">Save</button>
            </td>
            <td data-label="Estimated price">
                <input class="form-control form-control-sm" form="<?= e($fid) ?>" type="number" min="0" step="0.01" name="estimated_price" value="<?= e((string)($r['estimated_price'] ?? '')) ?>" placeholder="R 0.00" aria-label="Estimated price">
            </td>
            <td data-label="Final price">
                <input class="form-control form-control-sm" form="<?= e($fid) ?>" type="number" min="0" step="0.01" name="final_price" value="<?= e((string)($r['final_price'] ?? '')) ?>" placeholder="R 0.00" aria-label="Final price">
            </td>
            <td data-label="Shipping">
                <input class="form-control form-control-sm" form="<?= e($fid) ?>" type="date" name="completion_date" value="<?= e(!empty($r['completion_date']) ? date('Y-m-d', strtotime((string)$r['completion_date'])) : '') ?>" aria-label="Shipping date">
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<?php foreach ($visible as $r): $qid = (int)$r['quote_id']; ?>
<div class="modal fade" id="details-<?= $qid ?>" tabindex="-1" aria-labelledby="details-title-<?= $qid ?>" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="details-title-<?= $qid ?>">Quote request #<?= $qid ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <dl class="details-grid">
          <dt>Customer</dt><dd><?= e((string)$r['username']) ?> <span class="text-muted">· <?= e((string)$r['email']) ?></span></dd>
          <dt>Service</dt><dd><?= e(admin_label((string)$r['service_type'])) ?></dd>
          <dt>Quantity</dt><dd><?= (int)$r['quantity'] ?></dd>
          <dt>Dimensions (W × H × D)</dt><dd><?= e((string)($r['width'] ?? '—')) ?> × <?= e((string)($r['height'] ?? '—')) ?> × <?= e((string)($r['breadth'] ?? '—')) ?></dd>
          <dt>Requested</dt><dd><?= e(date('d M Y H:i', strtotime((string)$r['created_at']))) ?></dd>
          <dt>Status</dt><dd><?= admin_status((string)$r['quote_status']) ?></dd>
        </dl>
        <h6 class="mt-4">Customer description</h6>
        <p class="description-box"><?= nl2br(e((string)($r['quote_description'] ?? 'No description supplied.'))) ?></p>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php foreach ($visible as $r): $qid = (int)$r['quote_id']; ?>
<?= admin_files_modal('quote-' . $qid, 'Files · Quote #' . $qid, $quoteFiles[$qid] ?? []) ?>
<?php endforeach; ?>
<?php admin_footer(); ?>
