<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
admin_post_guard();

const MATERIAL_STATUSES = ['available', 'unavailable'];
// Two tabs: materials on the store, and materials hidden from it (status "unavailable").
const MATERIAL_TABS = ['available' => 'Available', 'hidden' => 'Hidden'];

/** Which tab a material with this status belongs to. */
function material_tab_for(string $status): string
{
    return $status === 'available' ? 'available' : 'hidden';
}

/** The tab the admin was looking at when they pressed a button (so they stay on it). */
function posted_tab(): string
{
    $tab = (string)($_POST['return_tab'] ?? '');
    return isset(MATERIAL_TABS[$tab]) ? $tab : 'available';
}

/**
 * Keep the filament products in step with one material.
 * Every colour listed in material_color becomes a product (category "material") linked to the material.
 * Colours removed from the list, and every colour of a hidden or deleted material, are set unavailable.
 * A product the admin has deleted from Deleted items is never recreated.
 */
function filament_sync(int $materialId): void
{
    $m = db_fetch_one(
        'SELECT material_type, material_color, price_per_kg, status, deleted_at FROM material WHERE material_id = ?',
        [$materialId]
    );
    if (!$m) {
        return;
    }
    $live   = $m['deleted_at'] === null && $m['status'] === 'available';
    $status = $live ? 'available' : 'unavailable';

    // Unique colours, in the order they were typed.
    $colours = [];
    foreach (explode(',', (string)$m['material_color']) as $c) {
        $c = mb_substr(trim($c), 0, 60);
        if ($c !== '' && !in_array(mb_strtolower($c), array_map('mb_strtolower', $colours), true)) {
            $colours[] = $c;
        }
    }

    foreach ($colours as $colour) {
        $name = $m['material_type'] . ' filament · ' . $colour;
        $existing = db_fetch_one(
            "SELECT product_id, deleted_at FROM product WHERE material_id = ? AND product_category = 'material' AND colour = ? ORDER BY product_id LIMIT 1",
            [$materialId, $colour]
        );
        if ($existing && $existing['deleted_at'] === null) {
            db_execute('UPDATE product SET product_name = ?, price = ?, status = ?, updated_at = NOW() WHERE product_id = ?',
                [$name, $m['price_per_kg'], $status, (int)$existing['product_id']]);
        } elseif (!$existing) {
            db_execute(
                "INSERT INTO product (product_name, summary, description, product_category, quantity, made_to_order, price, status, material_id, colour)
                 VALUES (?, ?, ?, 'material', 0, 1, ?, ?, ?, ?)",
                [$name, 'Filament, sold per kg', $m['material_type'] . ' filament in ' . $colour . '.', $m['price_per_kg'], $status, $materialId, $colour]
            );
        }
        // An existing row that was deleted stays deleted.
    }

    // Colours no longer listed (or every colour, when the material is hidden or deleted) are taken off the store.
    $sql = "UPDATE product SET status = 'unavailable', updated_at = NOW()
            WHERE material_id = ? AND product_category = 'material' AND deleted_at IS NULL";
    $params = [$materialId];
    if ($live && $colours) {
        $sql .= ' AND colour NOT IN (' . implode(',', array_fill(0, count($colours), '?')) . ')';
        $params = array_merge($params, $colours);
    }
    if (!$live || $colours) {
        db_execute($sql, $params);
    }
}

/** The "Sync filament products" button for the page header. */
function filament_sync_form_html(): string
{
    ob_start();
    ?>
<form method="post" class="d-inline"><?php admin_csrf_field(); ?><input type="hidden" name="action" value="sync_all"><button class="btn btn-outline-secondary me-2"<?= admin_write_attr() ?>><i class="mdi mdi-refresh me-1"></i>Sync filament products</button></form>
<?php
    return (string)ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['material_id'] ?? 0);

    if ($action === 'save') {
        if ($id > 0 && !db_fetch_one('SELECT 1 AS x FROM material WHERE material_id = ? AND deleted_at IS NULL', [$id])) {
            flash('error', 'That material no longer exists.');
            admin_redirect('materials.php');
        }
        $type   = trim((string)($_POST['material_type'] ?? ''));
        $price  = (float)($_POST['price_per_kg'] ?? -1);
        $status = (string)($_POST['status'] ?? 'available');
        $color  = trim((string)($_POST['material_color'] ?? ''));
        if (strlen($color) > 255) { $color = substr($color, 0, 255); }
        $num = static fn(string $k): ?float => trim((string)($_POST[$k] ?? '')) === '' ? null : (float)$_POST[$k];
        $weight = $num('material_weight');
        $length = $num('material_length');
        $density = $num('density');
        $description = trim((string)($_POST['description'] ?? ''));

        if ($type === '' || $price < 0 || !in_array($status, MATERIAL_STATUSES, true)) {
            flash('error', 'Please enter a material type, a valid price per kg and a status.');
            admin_redirect('materials.php');
        }
        $isNew = $id === 0;
        if (!$isNew) {
            db_execute(
                'UPDATE material SET material_type=?, description=?, material_weight=?, material_length=?, material_color=?, density=?, price_per_kg=?, status=?, updated_at=NOW() WHERE material_id=?',
                [$type, $description, $weight, $length, $color, $density, $price, $status, $id]
            );
        } else {
            $stmt = db_execute(
                'INSERT INTO material (material_type, description, material_weight, material_length, material_color, density, price_per_kg, status) VALUES (?,?,?,?,?,?,?,?)',
                [$type, $description, $weight, $length, $color, $density, $price, $status]
            );
            $id = (int)$stmt->insert_id;
        }
        filament_sync($id);
        $coverIndex = isset($_POST['cover_index']) && $_POST['cover_index'] !== '' ? (int)$_POST['cover_index'] : null;
        $messages = admin_save_images('material_img', 'material_id', $id, 'material_images', 'materials', $coverIndex);
        flash($messages ? 'error' : 'success', $messages ? implode(' ', $messages) : ($isNew ? 'Material added.' : 'Material updated.'));
        admin_redirect('materials.php?tab=' . material_tab_for($status));
    }

    if ($action === 'sync_all') {
        foreach (db_fetch_all('SELECT material_id FROM material') as $row) {
            filament_sync((int)$row['material_id']);
        }
        flash('success', 'Filament products updated from the materials list.');
        admin_redirect('materials.php?tab=' . posted_tab());
    }

    if ($action === 'set_cover' && $id > 0) {
        $imageId = (int)($_POST['material_img_id'] ?? 0);
        db_execute('UPDATE material_img SET cover_img = (material_img_id = ?) WHERE material_id = ?', [$imageId, $id]);
        flash('success', 'Cover image updated.');
        admin_redirect('materials.php?tab=' . posted_tab());
    }

    if ($action === 'delete_image' && $id > 0) {
        $removed = admin_delete_image('material_img', 'material_id', $id, (int)($_POST['material_img_id'] ?? 0));
        flash($removed ? 'success' : 'error', $removed ? 'Image removed.' : 'That image no longer exists.');
        admin_redirect('materials.php?tab=' . posted_tab());
    }

    if ($action === 'toggle' && $id > 0) {
        $next = (string)($_POST['to'] ?? '') === 'available' ? 'available' : 'unavailable';
        db_execute('UPDATE material SET status = ? WHERE material_id = ? AND deleted_at IS NULL', [$next, $id]);
        filament_sync($id);
        flash('success', $next === 'available' ? 'Material is now available.' : 'Material hidden from the store.');
        admin_redirect('materials.php?tab=' . posted_tab());
    }

    if ($action === 'delete' && $id > 0) {
        // Soft delete: kept in the database, hidden from the store and from this list.
        db_execute("UPDATE material SET deleted_at = NOW(), status = 'unavailable' WHERE material_id = ? AND deleted_at IS NULL", [$id]);
        filament_sync($id);
        flash('success', 'Material moved to Deleted items.');
        admin_redirect('materials.php?tab=' . posted_tab());
    }
}

$rows = db_fetch_all(
    "SELECT m.*,
            (SELECT img_url FROM material_img WHERE material_id = m.material_id ORDER BY cover_img DESC, material_img_id ASC LIMIT 1) AS cover_img,
            (SELECT COUNT(*) FROM material_img WHERE material_id = m.material_id) AS image_count,
            (SELECT COUNT(*) FROM product WHERE material_id = m.material_id AND deleted_at IS NULL) AS product_count
     FROM material m WHERE m.deleted_at IS NULL ORDER BY m.material_id DESC"
);
$imagesByMaterial = [];
foreach (db_fetch_all('SELECT material_img_id, material_id, img_url, cover_img FROM material_img ORDER BY cover_img DESC, material_img_id ASC') as $img) {
    $imagesByMaterial[(int)$img['material_id']][] = $img;
}

/** Modal form for one material (id 0 = new). */
function material_modal(string $modalId, array $m, array $images): void
{
    $mid = (int)($m['material_id'] ?? 0);
    $isNew = $mid === 0;
    $formId = 'material-form-' . ($isNew ? 'new' : $mid);
    $listId = 'cover-list-m-' . ($isNew ? 'new' : $mid);
    ?>
<div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <form class="modal-content" id="<?= e($formId) ?>" method="post" enctype="multipart/form-data">
      <?php admin_csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="material_id" value="<?= $mid ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="<?= e($modalId) ?>-title"><?= $isNew ? 'Add material' : 'Edit material #' . $mid ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label">Material type</label>
            <input class="form-control" required name="material_type" value="<?= e((string)($m['material_type'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Colours</label>
            <input class="form-control" name="material_color" maxlength="255" placeholder="e.g. Black, White, Grey" value="<?= e((string)($m['material_color'] ?? '')) ?>">
            <div class="form-text">Separate colours with commas.</div></div>
          <div class="col-12"><label class="form-label">Description</label>
            <textarea class="form-control" rows="2" name="description"><?= e((string)($m['description'] ?? '')) ?></textarea></div>
          <div class="col-md-4"><label class="form-label">Weight left (g)</label>
            <input class="form-control" type="number" step="0.01" min="0" name="material_weight" value="<?= e((string)($m['material_weight'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Length left (m)</label>
            <input class="form-control" type="number" step="0.01" min="0" name="material_length" value="<?= e((string)($m['material_length'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Density (g/cm³)</label>
            <input class="form-control" type="number" step="0.01" min="0" name="density" value="<?= e((string)($m['density'] ?? '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Price per kg (R)</label>
            <input class="form-control" type="number" step="0.01" min="0" required name="price_per_kg" value="<?= e(number_format((float)($m['price_per_kg'] ?? 0), 2, '.', '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Status</label>
            <select class="form-select" name="status">
              <?php foreach (MATERIAL_STATUSES as $s): ?>
                <option value="<?= e($s) ?>" <?= (($m['status'] ?? 'available') === $s) ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>

        <hr class="my-4">
        <h6 class="section-label">Images</h6>
        <?php if ($images): ?>
          <div class="image-picker mb-3">
            <?php foreach ($images as $img): ?>
              <div class="image-tile <?= (int)$img['cover_img'] === 1 ? 'is-cover' : '' ?>">
                <img src="uploads/<?= e((string)$img['img_url']) ?>" alt="Material image">
                <?php if ((int)$img['cover_img'] === 1): ?>
                  <span class="cover-badge">Cover</span>
                <?php else: ?>
                  <button type="submit" class="btn btn-sm btn-light cover-btn" form="mcover-<?= (int)$img['material_img_id'] ?>">Make cover</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-sm btn-outline-danger cover-btn" form="mdelimg-<?= (int)$img['material_img_id'] ?>" onclick="return confirm('Remove this image permanently?')"><i class="mdi mdi-delete-outline"></i> Remove</button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <label class="form-label">Add images (JPG, PNG or WEBP, up to 5 MB each)</label>
        <input class="form-control" type="file" name="material_images[]" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
               data-cover-list="<?= e($listId) ?>" data-cover-name="cover_index">
        <div class="cover-choice mt-2" id="<?= e($listId) ?>"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-edge"><?= $isNew ? 'Add material' : 'Save changes' ?></button>
      </div>
    </form>
  </div>
</div>
    <?php
}

admin_header('Materials');
admin_page_head(
    'Materials',
    'Maintain print materials, their specifications, price per kilogram and images.',
    filament_sync_form_html() . '<button type="button" class="btn btn-edge" data-bs-toggle="modal" data-bs-target="#material-add"' . admin_write_attr() . '><i class="mdi mdi-plus me-1"></i>Add material</button>'
);

// Count each tab, draw the tab bar, then keep only the materials of the open tab.
$tabCounts = [];
foreach (MATERIAL_TABS as $key => $label) {
    $tabCounts[$key] = [$label, count(array_filter($rows, fn($r) => material_tab_for((string)$r['status']) === $key))];
}
$activeTab = admin_tabs($tabCounts);
$rows = array_values(array_filter($rows, fn($r) => material_tab_for((string)$r['status']) === $activeTab));
$emptyText = $activeTab === 'hidden' ? 'No hidden materials.' : 'No available materials. Use “Add material” to create one.';
?>
<?php
// Same format as before: "250 g" / "12.5 m", or a dash when the value is empty.
$fmtUnit = static fn($v, string $unit): string => $v === null ? admin_dash() : e(rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') . ' ' . $unit);
?>
<div class="card table-card">
<div class="table-responsive">
<table class="table admin-table table-wide align-middle">
    <thead><tr>
        <th>Image</th><th>ID</th><th>Type</th><th>Description</th><th>Colours</th><th class="text-end">Density (g/cm³)</th>
        <th class="text-end">Weight left</th><th class="text-end">Length left</th><th>Customer sees</th><th class="text-end">Price / kg</th>
        <th class="text-end">Products</th><th class="text-end">Images</th><th>Status</th><th>Created</th><th>Updated</th><th class="col-actions text-end">Actions</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="16" class="empty-cell"><?= e($emptyText) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $mid = (int)$r['material_id']; $inStock = material_in_stock($r); ?>
        <tr>
            <td data-label="Image"><?php if ($r['cover_img']): ?><img class="thumb" src="uploads/<?= e((string)$r['cover_img']) ?>" alt=""><?php else: ?><span class="thumb-empty"><i class="mdi mdi-image-outline"></i></span><?php endif; ?></td>
            <td data-label="ID"><?= $mid ?></td>
            <td data-label="Type"><strong><?= e((string)$r['material_type']) ?></strong></td>
            <td data-label="Description" class="cell-wrap"><?= admin_clip($r['description'] ?? '', 70) ?></td>
            <td data-label="Colours"><?= admin_chips($r['material_color'] ?? '') ?></td>
            <td data-label="Density (g/cm³)" class="text-end"><?= $r['density'] !== null ? e(rtrim(rtrim(number_format((float)$r['density'], 2, '.', ''), '0'), '.')) : admin_dash() ?></td>
            <td data-label="Weight left" class="text-end"><?= $fmtUnit($r['material_weight'], 'g') ?></td>
            <td data-label="Length left" class="text-end"><?= $fmtUnit($r['material_length'], 'm') ?></td>
            <td data-label="Customer sees"><?= $inStock ? admin_pill('In stock') : admin_pill('Out of stock', 'is-bad') ?></td>
            <td data-label="Price / kg" class="text-end"><?= admin_money($r['price_per_kg']) ?></td>
            <td data-label="Products" class="text-end"><?= (int)$r['product_count'] ?></td>
            <td data-label="Images" class="text-end"><?= (int)$r['image_count'] ?></td>
            <td data-label="Status"><?= admin_status((string)$r['status']) ?></td>
            <td data-label="Created"><?= admin_date($r['created_at'] ?? null) ?></td>
            <td data-label="Updated"><?= admin_date($r['updated_at'] ?? null) ?></td>
            <td data-label="Actions" class="col-actions">
                <div class="row-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#material-edit-<?= $mid ?>"<?= admin_write_attr() ?>><i class="mdi mdi-pencil-outline"></i> Edit</button>
                <form method="post">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                    <input type="hidden" name="material_id" value="<?= $mid ?>">
                    <input type="hidden" name="to" value="<?= $r['status'] === 'available' ? 'unavailable' : 'available' ?>">
                    <button class="btn btn-sm btn-outline-secondary"<?= admin_write_attr() ?>><?= $r['status'] === 'available' ? 'Hide' : 'Show' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Move this material to Deleted items? <?= (int)$r['product_count'] > 0 ? (int)$r['product_count'] . ' product(s) still use it. ' : '' ?>You can restore it later.')">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
                    <input type="hidden" name="material_id" value="<?= $mid ?>">
                    <button class="btn btn-sm btn-outline-danger" aria-label="Delete material <?= $mid ?>"<?= admin_write_attr() ?>><i class="mdi mdi-delete-outline"></i></button>
                </form>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<?php material_modal('material-add', [], []); ?>
<?php foreach ($rows as $r): $mid = (int)$r['material_id'];
    material_modal('material-edit-' . $mid, $r, $imagesByMaterial[$mid] ?? []);
endforeach; ?>
<?php foreach ($imagesByMaterial as $imgs): foreach ($imgs as $img): ?>
    <form id="mcover-<?= (int)$img['material_img_id'] ?>" method="post" class="d-none">
        <?php admin_csrf_field(); ?>
        <input type="hidden" name="action" value="set_cover">
        <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
        <input type="hidden" name="material_id" value="<?= (int)$img['material_id'] ?>">
        <input type="hidden" name="material_img_id" value="<?= (int)$img['material_img_id'] ?>">
    </form>
    <form id="mdelimg-<?= (int)$img['material_img_id'] ?>" method="post" class="d-none">
        <?php admin_csrf_field(); ?>
        <input type="hidden" name="action" value="delete_image">
        <input type="hidden" name="return_tab" value="<?= e($activeTab) ?>">
        <input type="hidden" name="material_id" value="<?= (int)$img['material_id'] ?>">
        <input type="hidden" name="material_img_id" value="<?= (int)$img['material_img_id'] ?>">
    </form>
<?php endforeach; endforeach; ?>
<?php admin_footer(); ?>
