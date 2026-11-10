<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
admin_post_guard();

// PRODUCT_CATEGORIES (value => label) is defined once in include/functions.php and shared with the shop.
const PRODUCT_STATUSES   = ['available', 'unavailable'];
// Two tabs: products on the store, and products hidden from it (status "unavailable").
const PRODUCT_TABS = ['available' => 'Available', 'hidden' => 'Hidden'];

/** Which tab a product with this status belongs to. */
function product_tab_for(string $status): string
{
    return $status === 'available' ? 'available' : 'hidden';
}

/** The tab the admin was looking at when they pressed a button (so they stay on it). */
function posted_tab(): string
{
    $tab = (string)($_POST['return_tab'] ?? '');
    return isset(PRODUCT_TABS[$tab]) ? $tab : 'available';
}

/** Read the optional cover choice from the form (null when nothing was chosen). */
function posted_cover_index(): ?int
{
    return isset($_POST['cover_index']) && $_POST['cover_index'] !== '' ? (int)$_POST['cover_index'] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['product_id'] ?? 0);

    if ($action === 'save') {
        $existing = $id > 0 ? db_fetch_one('SELECT product_id FROM product WHERE product_id = ? AND deleted_at IS NULL', [$id]) : [];
        if ($id > 0 && !$existing) {
            flash('error', 'That product no longer exists.');
            admin_redirect('products.php');
        }
        $name     = trim((string)($_POST['product_name'] ?? ''));
        $category = (string)($_POST['product_category'] ?? '');
        $status   = (string)($_POST['status'] ?? 'available');
        $price    = (float)($_POST['price'] ?? -1);
        $discRaw  = trim((string)($_POST['discounted_price'] ?? ''));
        $discount = $discRaw === '' ? null : (float)$discRaw;
        $qty      = max(0, (int)($_POST['quantity'] ?? 0));
        // Made to order: printed when ordered, so the shop ignores stock and limits the quantity per order instead.
        $madeToOrder = isset($_POST['made_to_order']) ? 1 : 0;
        $matId    = (int)($_POST['material_id'] ?? 0);
        $materialId = $matId > 0 && db_fetch_one('SELECT 1 AS x FROM material WHERE material_id = ? AND deleted_at IS NULL', [$matId]) ? $matId : null;

        if ($name === '' || !array_key_exists($category, PRODUCT_CATEGORIES) || !in_array($status, PRODUCT_STATUSES, true)
            || $price < 0 || ($discount !== null && $discount < 0)) {
            flash('error', 'Please check the product name, category, price and status.');
            admin_redirect('products.php');
        }
        if ($discount !== null && $discount >= $price) {
            flash('error', 'The discounted price must be lower than the normal price. Leave it empty for no discount.');
            admin_redirect('products.php');
        }
        $colour    = mb_substr(trim((string)($_POST['colour'] ?? '')), 0, 60) ?: null;
        $size      = mb_substr(trim((string)($_POST['size'] ?? '')), 0, 60) ?: null;
        $groupCode = mb_substr(trim((string)($_POST['group_code'] ?? '')), 0, 60) ?: null;
        $summary = trim((string)($_POST['summary'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $isNew = $id === 0;
        if (!$isNew) {
            db_execute(
                'UPDATE product SET product_name=?, summary=?, description=?, product_category=?, quantity=?, made_to_order=?, price=?, discounted_price=?, status=?, material_id=?, colour=?, size=?, group_code=?, updated_at=NOW() WHERE product_id=?',
                [$name, $summary, $description, $category, $qty, $madeToOrder, $price, $discount, $status, $materialId, $colour, $size, $groupCode, $id]
            );
        } else {
            $stmt = db_execute(
                'INSERT INTO product (product_name, summary, description, product_category, quantity, made_to_order, price, discounted_price, status, material_id, colour, size, group_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$name, $summary, $description, $category, $qty, $madeToOrder, $price, $discount, $status, $materialId, $colour, $size, $groupCode]
            );
            $id = (int)$stmt->insert_id;
        }

        $messages = [];
        // Optional .glb 3D model (replaces the old one). Stored relative to admin/uploads/.
        if (isset($_POST['remove_model'])) {
            db_execute('UPDATE product SET model_url = NULL WHERE product_id = ?', [$id]);
        }
        foreach (admin_files_indexed('model_file') as $file) {
            $result = admin_store_model($file);
            if ($result['ok']) {
                db_execute('UPDATE product SET model_url = ? WHERE product_id = ?', ['models/' . $result['file'], $id]);
            } else {
                $messages[] = $result['error'];
            }
        }
        // Optional new images, with the chosen cover.
        $messages = array_merge($messages, admin_save_images('product_img', 'product_id', $id, 'product_images', 'products', posted_cover_index()));
        flash($messages ? 'error' : 'success', $messages ? implode(' ', $messages) : ($isNew ? 'Product created.' : 'Product updated.'));
        // Show the product on the tab it now belongs to (Available or Hidden).
        admin_redirect('products.php?tab=' . product_tab_for($status));
    }

    if ($action === 'set_cover' && $id > 0) {
        $imageId = (int)($_POST['product_img_id'] ?? 0);
        db_execute('UPDATE product_img SET cover_img = (product_img_id = ?) WHERE product_id = ?', [$imageId, $id]);
        flash('success', 'Cover image updated.');
        admin_redirect('products.php?tab=' . posted_tab());
    }

    if ($action === 'delete_image' && $id > 0) {
        $removed = admin_delete_image('product_img', 'product_id', $id, (int)($_POST['product_img_id'] ?? 0));
        flash($removed ? 'success' : 'error', $removed ? 'Image removed.' : 'That image no longer exists.');
        admin_redirect('products.php?tab=' . posted_tab());
    }

    if ($action === 'toggle' && $id > 0) {
        $next = (string)($_POST['to'] ?? '') === 'available' ? 'available' : 'unavailable';
        db_execute('UPDATE product SET status = ? WHERE product_id = ? AND deleted_at IS NULL', [$next, $id]);
        flash('success', $next === 'available' ? 'Product is now available on the store.' : 'Product hidden from the store.');
        admin_redirect('products.php?tab=' . posted_tab());
    }

    if ($action === 'delete' && $id > 0) {
        // Soft delete: kept in the database, hidden from the store and from this list.
        db_execute("UPDATE product SET deleted_at = NOW(), status = 'unavailable' WHERE product_id = ? AND deleted_at IS NULL", [$id]);
        flash('success', 'Product moved to Deleted items.');
        admin_redirect('products.php?tab=' . posted_tab());
    }
}

$materials = db_fetch_all('SELECT material_id, material_type FROM material WHERE deleted_at IS NULL ORDER BY material_type');
$rows = db_fetch_all(
    "SELECT p.*, m.material_type,
            (SELECT img_url FROM product_img WHERE product_id = p.product_id ORDER BY cover_img DESC, product_img_id ASC LIMIT 1) AS cover_img,
            (SELECT COUNT(*) FROM product_img WHERE product_id = p.product_id) AS image_count
     FROM product p
     LEFT JOIN material m ON m.material_id = p.material_id
     WHERE p.deleted_at IS NULL
     ORDER BY p.product_id DESC"
);

/** Modal form for one product (id 0 = new). Rendered for "Add" and for each "Edit". */
function product_modal(string $modalId, array $p, array $materials, array $images): void
{
    $pid = (int)($p['product_id'] ?? 0);
    $isNew = $pid === 0;
    $formId = 'product-form-' . ($isNew ? 'new' : $pid);
    $listId = 'cover-list-' . ($isNew ? 'new' : $pid);
    ?>
<div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
    <form class="modal-content" id="<?= e($formId) ?>" method="post" enctype="multipart/form-data">
      <?php admin_csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="product_id" value="<?= $pid ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="<?= e($modalId) ?>-title"><?= $isNew ? 'Add product' : 'Edit product #' . $pid ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label">Product name</label>
            <input class="form-control" name="product_name" required value="<?= e((string)($p['product_name'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Category</label>
            <select class="form-select" name="product_category">
              <?php foreach (PRODUCT_CATEGORIES as $value => $label): ?>
                <option value="<?= e((string)$value) ?>" <?= (($p['product_category'] ?? 'models') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><label class="form-label">Colour</label>
            <input class="form-control" name="colour" maxlength="60" placeholder="e.g. Black" value="<?= e((string)($p['colour'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Size</label>
            <input class="form-control" name="size" maxlength="60" placeholder="e.g. Medium" value="<?= e((string)($p['size'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Group code</label>
            <input class="form-control" name="group_code" maxlength="60" placeholder="same code = same product" value="<?= e((string)($p['group_code'] ?? '')) ?>">
            <div class="form-text">Products with the same group code appear as colour and size choices on one page.</div></div>
          <div class="col-12"><label class="form-label">Short summary</label>
            <input class="form-control" name="summary" value="<?= e((string)($p['summary'] ?? '')) ?>"></div>
          <div class="col-12"><label class="form-label">Description</label>
            <textarea class="form-control" rows="3" name="description"><?= e((string)($p['description'] ?? '')) ?></textarea></div>
          <div class="col-md-4"><label class="form-label">Material (optional)</label>
            <select class="form-select" name="material_id">
              <option value="">None</option>
              <?php foreach ($materials as $m): ?>
                <option value="<?= (int)$m['material_id'] ?>" <?= ((int)($p['material_id'] ?? 0) === (int)$m['material_id']) ? 'selected' : '' ?>><?= e($m['material_type']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-2"><label class="form-label">Stock</label>
            <input class="form-control" type="number" min="0" name="quantity" value="<?= (int)($p['quantity'] ?? 0) ?>"></div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="<?= e($modalId) ?>-mto" name="made_to_order" value="1" <?= (int)($p['made_to_order'] ?? 0) === 1 ? 'checked' : '' ?>>
              <label class="form-check-label" for="<?= e($modalId) ?>-mto">Made to order (printed when ordered)</label>
            </div>
            <div class="form-text">The shop then ignores stock and limits each order to <?= (int)MADE_TO_ORDER_MAX ?> items. Larger orders must request a quote.</div>
          </div>
          <div class="col-md-3"><label class="form-label">Price (R)</label>
            <input class="form-control" type="number" min="0" step="0.01" name="price" required value="<?= e(number_format((float)($p['price'] ?? 0), 2, '.', '')) ?>"></div>
          <div class="col-md-3"><label class="form-label">Discounted (R)</label>
            <input class="form-control" type="number" min="0" step="0.01" name="discounted_price" value="<?= e(isset($p['discounted_price']) && $p['discounted_price'] !== null ? number_format((float)$p['discounted_price'], 2, '.', '') : '') ?>"></div>
          <div class="col-md-4"><label class="form-label">Status</label>
            <select class="form-select" name="status">
              <?php foreach (PRODUCT_STATUSES as $s): ?>
                <option value="<?= e($s) ?>" <?= (($p['status'] ?? 'available') === $s) ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>

        <hr class="my-4">
        <h6 class="section-label">Images</h6>
        <?php if ($images): ?>
          <div class="image-picker mb-3">
            <?php foreach ($images as $img): ?>
              <div class="image-tile <?= (int)$img['cover_img'] === 1 ? 'is-cover' : '' ?>">
                <img src="uploads/<?= e((string)$img['img_url']) ?>" alt="Product image">
                <?php if ((int)$img['cover_img'] === 1): ?>
                  <span class="cover-badge">Cover</span>
                <?php else: ?>
                  <button type="submit" class="btn btn-sm btn-light cover-btn" form="cover-<?= (int)$img['product_img_id'] ?>">Make cover</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-sm btn-outline-danger cover-btn" form="delimg-<?= (int)$img['product_img_id'] ?>" onclick="return confirm('Remove this image permanently?')"><i class="mdi mdi-delete-outline"></i> Remove</button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <label class="form-label">Add images (JPG, PNG or WEBP, up to 5 MB each)</label>
        <input class="form-control" type="file" name="product_images[]" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
               data-cover-list="<?= e($listId) ?>" data-cover-name="cover_index">
        <div class="cover-choice mt-2" id="<?= e($listId) ?>"></div>

        <hr class="my-4">
        <h6 class="section-label">3D model (.glb)</h6>
        <?php if (!empty($p['model_url'])): ?>
          <div class="model-current mb-2"><i class="mdi mdi-cube-scan"></i> <?= e(basename((string)$p['model_url'])) ?>
            <label class="ms-3 small"><input type="checkbox" name="remove_model" value="1"> Remove this model</label></div>
        <?php endif; ?>
        <input class="form-control" type="file" name="model_file" accept=".glb,model/gltf-binary">
        <div class="form-text">Optional. Upload a .glb file up to 50 MB. It replaces the current model.</div>
      </div>
      <div class="modal-footer">
        <?php if (!$isNew): ?>
          <button type="button" class="btn btn-outline-secondary me-auto" data-copy-product="<?= e($formId) ?>"><i class="mdi mdi-palette-swatch-outline"></i> Add another colour</button>
        <?php endif; ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-edge"><?= $isNew ? 'Create product' : 'Save changes' ?></button>
      </div>
    </form>
  </div>
</div>
    <?php
}

// Images for each product, grouped, so each edit pop-out can show them.
$imagesByProduct = [];
foreach (db_fetch_all('SELECT product_img_id, product_id, img_url, cover_img FROM product_img ORDER BY cover_img DESC, product_img_id ASC') as $img) {
    $imagesByProduct[(int)$img['product_id']][] = $img;
}

admin_header('Products');
admin_page_head(
    'Products',
    'Manage what the store sells: details, pricing, stock, images, the cover photo and the optional 3D model.',
    '<button type="button" class="btn btn-edge" data-bs-toggle="modal" data-bs-target="#product-add"' . admin_write_attr() . '><i class="mdi mdi-plus me-1"></i>Add product</button>'
);

// Count each tab, draw the tab bar, then keep only the products of the open tab.
$tabCounts = [];
foreach (PRODUCT_TABS as $key => $label) {
    $tabCounts[$key] = [$label, count(array_filter($rows, fn($r) => product_tab_for((string)$r['status']) === $key))];
}
$activeTab = admin_tabs($tabCounts);
$rows = array_values(array_filter($rows, fn($r) => product_tab_for((string)$r['status']) === $activeTab));
$emptyText = $activeTab === 'hidden' ? 'No hidden products.' : 'No available products. Use “Add product” to create one.';
?>
<div class="card table-card product-table-card">
<div class="table-responsive">
<table class="table admin-table table-wide products-table align-middle">
    <thead><tr>
        <th>Image</th><th>ID</th><th>Name</th><th>Summary</th><th>Description</th><th>Category</th><th>Material</th>
        <th>Colour</th><th>Size</th><th>Group code</th><th>Made to order</th><th>3D model</th><th>Images</th>
        <th class="text-end">Stock</th><th class="text-end">Price</th><th class="text-end">Discounted</th><th>Status</th>
        <th>Created</th><th>Updated</th><th class="col-actions text-end">Actions</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="20" class="empty-cell"><?= e($emptyText) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $pid = (int)$r['product_id']; $mto = (int)($r['made_to_order'] ?? 0) === 1; $qtyNow = (int)$r['quantity']; ?>
        <tr>
            <td data-label="Image"><?php if ($r['cover_img']): ?><img class="thumb" src="uploads/<?= e((string)$r['cover_img']) ?>" alt=""><?php else: ?><span class="thumb-empty"><i class="mdi mdi-image-outline"></i></span><?php endif; ?></td>
            <td data-label="ID"><?= $pid ?></td>
            <td data-label="Name"><strong><?= e((string)$r['product_name']) ?></strong></td>
            <td data-label="Summary" class="cell-wrap"><?= admin_clip($r['summary'] ?? '', 70) ?></td>
            <td data-label="Description" class="cell-wrap"><?= admin_clip($r['description'] ?? '', 70) ?></td>
            <td data-label="Category"><?= e(PRODUCT_CATEGORIES[$r['product_category']] ?? ucfirst((string)$r['product_category'])) ?></td>
            <td data-label="Material"><?= admin_or_dash($r['material_type'] ?? '') ?></td>
            <td data-label="Colour"><?= admin_or_dash($r['colour'] ?? '') ?></td>
            <td data-label="Size"><?= admin_or_dash($r['size'] ?? '') ?></td>
            <td data-label="Group code"><?= !empty($r['group_code']) ? '<code class="group-code">' . e((string)$r['group_code']) . '</code>' : admin_dash() ?></td>
            <td data-label="Made to order"><?= $mto ? admin_pill('Yes') : admin_pill('No', 'is-muted') ?></td>
            <td data-label="3D model"><?= !empty($r['model_url']) ? '<span class="status-pill model-pill" title="' . e(basename((string)$r['model_url'])) . '">.glb</span>' : admin_pill('None', 'is-muted') ?></td>
            <td data-label="Images"><?= (int)$r['image_count'] ?></td>
            <td data-label="Stock" class="text-end"><?php
                if ($mto) {
                    echo admin_dash();
                } else {
                    echo $qtyNow;
                    if ($qtyNow <= 0) { echo ' ' . admin_pill('Out', 'is-bad'); }
                    elseif ($qtyNow <= LOW_STOCK_THRESHOLD) { echo ' ' . admin_pill('Low', 'is-warn'); }
                }
            ?></td>
            <td data-label="Price" class="text-end"><?= admin_money($r['price']) ?></td>
            <td data-label="Discounted" class="text-end"><?= admin_money($r['discounted_price'] ?? null) ?></td>
            <td data-label="Status"><?= admin_status((string)$r['status']) ?></td>
            <td data-label="Created"><?= admin_date($r['created_at'] ?? null) ?></td>
            <td data-label="Updated"><?= admin_date($r['updated_at'] ?? null) ?></td>
            <td data-label="Actions" class="col-actions">
                <div class="row-actions">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#product-edit-<?= $pid ?>"<?= admin_write_attr() ?>><i class="mdi mdi-pencil-outline"></i> Edit</button>
                    <form method="post">
                        <?php admin_csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                        <input type="hidden" name="product_id" value="<?= $pid ?>">
                        <input type="hidden" name="to" value="<?= $r['status'] === 'available' ? 'unavailable' : 'available' ?>">
                        <button class="btn btn-sm btn-outline-secondary"<?= admin_write_attr() ?>><?= $r['status'] === 'available' ? 'Hide' : 'Show' ?></button>
                    </form>
                    <form method="post" onsubmit="return confirm('Move this product to Deleted items? You can restore it later.')">
                        <?php admin_csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                        <input type="hidden" name="product_id" value="<?= $pid ?>">
                        <button class="btn btn-sm btn-outline-danger" aria-label="Delete product <?= $pid ?>"<?= admin_write_attr() ?>><i class="mdi mdi-delete-outline"></i></button>
                    </form>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<div class="product-tiles" aria-label="Products">
<?php if (!$rows): ?><div class="card product-tile"><div class="empty-cell p-0"><?= e($emptyText) ?></div></div><?php endif; ?>
<?php foreach ($rows as $r): $pid = (int)$r['product_id']; ?>
    <article class="card product-tile">
        <div class="tile-image">
            <?php if ($r['cover_img']): ?><img src="uploads/<?= e((string)$r['cover_img']) ?>" alt="<?= e((string)$r['product_name']) ?>"><?php else: ?><i class="mdi mdi-image-outline"></i><?php endif; ?>
        </div>
        <div class="tile-name"><?= e((string)$r['product_name']) ?></div>
        <div class="tile-meta"><span>R <?= number_format((float)$r['price'], 2) ?></span><?= admin_status((string)$r['status']) ?></div>
        <div class="tile-extra small text-muted"><?= (int)$r['image_count'] ?> image<?= (int)$r['image_count'] === 1 ? '' : 's' ?><?= !empty($r['colour']) ? ' · ' . e((string)$r['colour']) : '' ?><?= !empty($r['group_code']) ? ' · Group ' . e((string)$r['group_code']) : '' ?><?= (int)($r['made_to_order'] ?? 0) === 1 ? ' · Made to order' : '' ?></div>
        <div class="tile-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#product-edit-<?= $pid ?>"<?= admin_write_attr() ?>>Edit</button>
            <form method="post">
                <?php admin_csrf_field(); ?>
                <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                <input type="hidden" name="product_id" value="<?= $pid ?>">
                <input type="hidden" name="to" value="<?= $r['status'] === 'available' ? 'unavailable' : 'available' ?>">
                <button class="btn btn-sm btn-outline-secondary"<?= admin_write_attr() ?>><?= $r['status'] === 'available' ? 'Hide' : 'Show' ?></button>
            </form>
            <form method="post" onsubmit="return confirm('Move this product to Deleted items?')">
                <?php admin_csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                <input type="hidden" name="product_id" value="<?= $pid ?>">
                <button class="btn btn-sm btn-outline-danger" aria-label="Delete"<?= admin_write_attr() ?>><i class="mdi mdi-delete-outline"></i></button>
            </form>
        </div>
    </article>
<?php endforeach; ?>
</div>

<?php product_modal('product-add', [], $materials, []); ?>
<?php foreach ($rows as $r): $pid = (int)$r['product_id'];
    product_modal('product-edit-' . $pid, $r, $materials, $imagesByProduct[$pid] ?? []);
endforeach; ?>

<?php // "Make cover" forms live outside the modal forms (forms cannot be nested). ?>
<?php foreach ($imagesByProduct as $pid => $imgs): foreach ($imgs as $img): ?>
    <form id="cover-<?= (int)$img['product_img_id'] ?>" method="post" class="d-none">
        <?php admin_csrf_field(); ?>
        <input type="hidden" name="action" value="set_cover">
        <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
        <input type="hidden" name="product_id" value="<?= (int)$pid ?>">
        <input type="hidden" name="product_img_id" value="<?= (int)$img['product_img_id'] ?>">
    </form>
    <form id="delimg-<?= (int)$img['product_img_id'] ?>" method="post" class="d-none">
        <?php admin_csrf_field(); ?>
        <input type="hidden" name="action" value="delete_image">
        <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
        <input type="hidden" name="product_id" value="<?= (int)$pid ?>">
        <input type="hidden" name="product_img_id" value="<?= (int)$img['product_img_id'] ?>">
    </form>
<?php endforeach; endforeach; ?>
<?php admin_footer(); ?>
