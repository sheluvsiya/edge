<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_files.php';
admin_post_guard();

// The only statuses an admin can pick. "shipping" is the value stored in the database
// (the customer pages read it), and it is shown as "Shipped".
const ORDER_STATUSES = ['pending', 'paid', 'shipping', 'cancelled'];
const ORDER_STATUS_LABELS = ['pending' => 'Pending', 'paid' => 'Paid', 'shipping' => 'Shipped', 'cancelled' => 'Cancelled'];

// Tabs, in this order. Older orders that already hold "delivered" or "failed" stay visible:
// delivered orders are listed under Shipped, failed ones under Cancelled.
const ORDER_TABS = [
    'paid'      => ['Paid',      ['paid']],
    'pending'   => ['Pending',   ['pending']],
    'shipped'   => ['Shipped',   ['shipping', 'delivered']],
    'cancelled' => ['Cancelled', ['cancelled', 'failed']],
];

/** Which tab an order with this status belongs to. */
function order_tab_for(string $status): string
{
    foreach (ORDER_TABS as $key => [, $statuses]) {
        if (in_array($status, $statuses, true)) { return $key; }
    }
    return 'pending';
}

/** Label for any status, including older ones that are no longer in the dropdown. */
function order_status_label(string $status): string
{
    return ORDER_STATUS_LABELS[$status] ?? admin_label($status);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['order_id'] ?? 0);
    $status = (string)($_POST['order_status'] ?? '');
    $tracking = trim((string)($_POST['tracking_num'] ?? ''));
    // Courier references can contain letters, digits and hyphens (up to 40 characters).
    if ($tracking !== '' && !preg_match('/^[A-Za-z0-9-]{1,40}$/', $tracking)) {
        flash('error', 'Tracking number can only contain letters, numbers and hyphens (max 40 characters).');
        admin_redirect('orders.php');
    }
    // An older order may still hold "delivered" or "failed"; saving it unchanged is allowed.
    $current = $id > 0 ? db_fetch_one('SELECT order_status FROM `order` WHERE order_id = ?', [$id]) : null;
    if ($id > 0 && $current === null) {
        flash('error', 'That order no longer exists.');
        admin_redirect('orders.php');
    }
    $currentStatus = (string)($current['order_status'] ?? '');
    if ($id > 0 && (in_array($status, ORDER_STATUSES, true) || ($status !== '' && $status === $currentStatus))) {
        db_execute('UPDATE `order` SET order_status = ?, tracking_num = ? WHERE order_id = ?',
            [$status, $tracking === '' ? null : $tracking, $id]);
        flash('success', 'Order ' . admin_ref('3DE', $id) . ' updated.');
        // Follow the order to the tab it now belongs to.
        admin_redirect('orders.php?tab=' . order_tab_for($status));
    }
    flash('error', 'Invalid order status.');
    admin_redirect('orders.php');
}

// checkout and payment are LEFT JOINs (as on the customer's profile page), so an order that has no
// checkout or payment row yet still appears here instead of silently disappearing.
$rows = db_fetch_all(
    "SELECT o.order_id, o.order_status, o.tracking_num, o.created_at,
            u.username, u.email, cu.first_name, cu.last_name, COALESCE(cu.phone_num, u.phone_num) AS phone,
            ch.total_amount, ch.fee_amount, ch.shipping_fee,
            p.payment_method, p.payment_status,
            (SELECT COUNT(*) FROM order_item oi WHERE oi.order_id = o.order_id) AS item_count
     FROM `order` o
     JOIN customer cu ON cu.customer_id = o.customer_id
     JOIN `user` u ON u.user_id = cu.user_id
     LEFT JOIN checkout ch ON ch.checkout_id = o.checkout_id
     LEFT JOIN payment p ON p.payment_id = o.payment_id
     ORDER BY o.order_id DESC"
);

admin_header('Orders');
admin_page_head('Orders', 'Track each order from payment to delivery, see what was ordered, and record the shipping (tracking) number.');

// Count each tab, draw the tab bar, then keep only the orders of the open tab.
$tabCounts = [];
foreach (ORDER_TABS as $key => [$label, $statuses]) {
    $tabCounts[$key] = [$label, count(array_filter($rows, fn($r) => in_array($r['order_status'], $statuses, true)))];
}
$activeTab = admin_tabs($tabCounts);
$rows = array_values(array_filter($rows, fn($r) => order_tab_for((string)$r['order_status']) === $activeTab));
$emptyText = 'No ' . strtolower(ORDER_TABS[$activeTab][0]) . ' orders.';

// What was in each order (one query for every order on this tab). Same joins the customer's order page uses.
$itemsByOrder = [];
if ($rows) {
    $orderIds = array_map(static fn($r) => (int)$r['order_id'], $rows);
    $itemRows = db_fetch_all(
        'SELECT oi.order_id, oi.quote_id, ci.quantity, pr.product_name, pr.price, pr.discounted_price, mat.material_type, pr.model_url, (SELECT img_url FROM product_img WHERE product_id = pr.product_id ORDER BY cover_img DESC, product_img_id ASC LIMIT 1) AS product_img
         FROM order_item oi
         LEFT JOIN cart_item ci ON ci.cart_item_id = oi.cart_item_id
         LEFT JOIN product pr ON pr.product_id = ci.product_id
         LEFT JOIN material mat ON mat.material_id = ci.material_id
         WHERE oi.order_id IN (' . placeholders($orderIds) . ')
         ORDER BY oi.order_id',
        $orderIds
    );
    foreach ($itemRows as $item) {
        $itemsByOrder[(int)$item['order_id']][] = $item;
    }
}

// Files for each order: product pictures and 3D models, plus the pictures and CAD files of any custom print.
$quoteFiles = admin_quote_files();
$filesByOrder = [];
foreach ($itemsByOrder as $orderId => $lines) {
    $list = [];
    foreach ($lines as $line) {
        admin_add_file($list, $line['product_img'] ?? null, 'uploads/');
        admin_add_file($list, $line['model_url'] ?? null, 'uploads/');
        foreach ($quoteFiles[(int)($line['quote_id'] ?? 0)] ?? [] as $f) { $list[$f['url']] = $f; }
    }
    $filesByOrder[$orderId] = array_values($list);
}

// The "Live status" window needs admin/tracking.php. Only offer it when that page exists.
$hasTracking = is_file(__DIR__ . '/tracking.php');
?>
<div class="card table-card">
<div class="table-responsive">
<table class="table admin-table table-wide align-middle">
    <thead><tr>
        <th>Order</th><th>Customer</th><th>Email</th><th>Phone</th><th class="text-end">Items</th>
        <th class="text-end">Shipping</th><th class="text-end">Fees</th><th class="text-end">Total</th>
        <th>Payment method</th><th>Payment status</th><th>Status</th><th>Tracking number</th><th>Date</th><th class="text-end col-actions">Actions</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="14" class="empty-cell"><?= e($emptyText) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $oid = (int)$r['order_id']; $fid = 'order-' . $oid; $hasTrackingNum = trim((string)($r['tracking_num'] ?? '')) !== ''; ?>
        <tr>
            <td data-label="Order"><strong><?= e(admin_ref('3DE', $oid)) ?></strong>
                <form id="<?= e($fid) ?>" method="post" class="d-none">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                </form>
            </td>
            <td data-label="Customer"><strong><?= e((string)$r['username']) ?></strong></td>
            <td data-label="Email"><?= admin_or_dash($r['email'] ?? '') ?></td>
            <td data-label="Phone"><?= admin_or_dash($r['phone'] ?? '') ?></td>
            <td data-label="Items" class="text-end"><?= (int)$r['item_count'] ?></td>
            <td data-label="Shipping" class="text-end"><?= admin_money($r['shipping_fee'] ?? null) ?></td>
            <td data-label="Fees" class="text-end"><?= admin_money($r['fee_amount'] ?? null) ?></td>
            <td data-label="Total" class="text-end"><strong><?= admin_money($r['total_amount'] ?? null) ?></strong></td>
            <td data-label="Payment method"><?= $r['payment_method'] ? e(admin_label((string)$r['payment_method'])) : admin_dash() ?></td>
            <td data-label="Payment status"><?= $r['payment_status'] ? admin_status((string)$r['payment_status']) : admin_pill('Not recorded', 'is-muted') ?></td>
            <td data-label="Status">
                <select class="form-select form-select-sm order-status" form="<?= e($fid) ?>" name="order_status" aria-label="Status for order <?= $oid ?>"<?= admin_write_attr() ?>>
                    <?php if (!in_array($r['order_status'], ORDER_STATUSES, true)): ?>
                        <?php // Older order: show its current status so saving does not change it by accident. ?>
                        <option value="<?= e((string)$r['order_status']) ?>" selected><?= e(order_status_label((string)$r['order_status'])) ?></option>
                    <?php endif; ?>
                    <?php foreach (ORDER_STATUSES as $s): ?>
                        <option value="<?= e($s) ?>" <?= $r['order_status'] === $s ? 'selected' : '' ?>><?= e(ORDER_STATUS_LABELS[$s]) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td data-label="Tracking number">
                <input class="form-control form-control-sm tracking-input" form="<?= e($fid) ?>" name="tracking_num" maxlength="40" placeholder="Tracking number" value="<?= e((string)($r['tracking_num'] ?? '')) ?>" aria-label="Tracking number for order <?= $oid ?>"<?= admin_write_attr() ?>>
            </td>
            <td data-label="Date"><?= admin_date($r['created_at'] ?? null, true) ?></td>
            <td data-label="Actions" class="text-end text-nowrap col-actions">
                <button class="btn btn-sm btn-edge" form="<?= e($fid) ?>"<?= admin_write_attr() ?>>Save</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#items-<?= $oid ?>"><i class="mdi mdi-format-list-bulleted"></i> Items</button>
                <?= admin_files_button('order-' . $oid, $filesByOrder[$oid] ?? []) ?>
                <?php if ($hasTracking && $hasTrackingNum): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary live-btn" data-bs-toggle="modal" data-bs-target="#live-<?= $oid ?>"><i class="mdi mdi-broadcast"></i> Live status</button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<?php // One "Items" window per order: every line of the order in its own row and columns. ?>
<?php foreach ($rows as $r): $oid = (int)$r['order_id']; $lines = $itemsByOrder[$oid] ?? []; ?>
<div class="modal fade" id="items-<?= $oid ?>" tabindex="-1" aria-labelledby="items-title-<?= $oid ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="items-title-<?= $oid ?>">Items · order <?= e(admin_ref('3DE', $oid)) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
        <table class="table admin-table item-table align-middle mb-0">
          <thead><tr><th>Type</th><th>Item</th><th class="text-end">Quantity</th><th class="text-end">Unit price</th><th class="text-end">Line total</th></tr></thead>
          <tbody>
          <?php if (!$lines): ?><tr><td colspan="5" class="empty-cell">No items are recorded for this order.</td></tr><?php endif; ?>
          <?php foreach ($lines as $line):
              $qty = (int)($line['quantity'] ?? 1);
              if (!empty($line['quote_id'])) {
                  $type = 'Custom print'; $name = 'Quote QT-' . str_pad((string)(int)$line['quote_id'], 4, '0', STR_PAD_LEFT); $unit = null;
              } elseif (!empty($line['product_name'])) {
                  $type = 'Product'; $name = (string)$line['product_name']; $unit = effective_price($line);
              } elseif (!empty($line['material_type'])) {
                  $type = 'Material'; $name = (string)$line['material_type']; $unit = null;
              } else {
                  $type = 'Item'; $name = 'Item no longer available'; $unit = null;
              }
          ?>
            <tr>
              <td data-label="Type"><?= e($type) ?></td>
              <td data-label="Item"><?= e($name) ?></td>
              <td data-label="Quantity" class="text-end"><?= $qty ?></td>
              <td data-label="Unit price" class="text-end"><?= admin_money($unit) ?></td>
              <td data-label="Line total" class="text-end"><?= $unit !== null ? admin_money($unit * $qty) : admin_dash() ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <p class="form-text mt-2 mb-0">Unit prices are today's shop prices. Custom prints and materials are priced on their own quote or order, so they show no unit price here.</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php foreach ($rows as $r): $oid = (int)$r['order_id']; echo admin_files_modal('order-' . $oid, 'Files · order ' . admin_ref('3DE', $oid), $filesByOrder[$oid] ?? []); endforeach; ?>
<?php if ($hasTracking): foreach ($rows as $r): if (trim((string)($r['tracking_num'] ?? '')) === '') { continue; } $oid = (int)$r['order_id']; ?>
<div class="modal fade" id="live-<?= $oid ?>" tabindex="-1" aria-labelledby="live-title-<?= $oid ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="live-title-<?= $oid ?>">Live status · order <?= e(admin_ref('3DE', $oid)) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body live-panel" data-status-url="tracking.php?order=<?= $oid ?>" aria-live="polite">
        <p class="text-muted mb-0">Checking Bob Go…</p>
      </div>
      <div class="modal-footer justify-content-between">
        <span class="small text-muted live-updated">Refreshes every 30 seconds while this window is open.</span>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
<?php if ($hasTracking): ?>
<script>
// Live status window: load the Bob Go status when it opens, refresh every 30 seconds while it is open.
(function () {
  var timers = new WeakMap();
  function load(modal) {
    var panel = modal.querySelector('.live-panel');
    if (!panel) { return; }
    fetch(panel.getAttribute('data-status-url'), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        panel.innerHTML = html;
        var stamp = modal.querySelector('.live-updated');
        if (stamp) { stamp.textContent = 'Updated ' + new Date().toLocaleTimeString() + '. Refreshes every 30 seconds while this window is open.'; }
      })
      .catch(function () { panel.innerHTML = '<p class="text-danger mb-0">Could not load the status. Check your connection.</p>'; });
  }
  document.addEventListener('show.bs.modal', function (ev) {
    var modal = ev.target;
    if (!modal.querySelector || !modal.querySelector('.live-panel')) { return; }
    load(modal);
    timers.set(modal, setInterval(function () { load(modal); }, 30000));
  });
  document.addEventListener('hidden.bs.modal', function (ev) {
    var id = timers.get(ev.target);
    if (id) { clearInterval(id); timers.delete(ev.target); }
  });
})();
</script>
<?php endif; ?>
<?php admin_footer(); ?>
