<?php
declare(strict_types=1);

require_once __DIR__ . '/../Start_session.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/require_admin.php';

// Re-check the admin role in the database on every request, so a role change
// made on the Users page takes effect straight away (not after the session expires).
$adminUser = auth_user() ?? [];
$current = db_fetch_one(
    "SELECT u.user_id, u.username, a.admin_id, COALESCE(a.admin_type, 'user') AS admin_type
     FROM `user` u JOIN admin a ON a.user_id = u.user_id
     WHERE u.user_id = ? AND u.role = 'admin' LIMIT 1",
    [(int)($adminUser['user_id'] ?? 0)]
);
if ($current === null) {
    flash('error', 'Your administrator access has been removed.');
    redirect('login.php');
}
$adminUser['username'] = (string)$current['username'];
$adminUser['admin_id'] = (int)$current['admin_id'];
$adminUser['admin_type'] = (string)$current['admin_type'];

/**
 * Redirect to another admin page and stay in the admin area.
 * The target is relative (e.g. "news.php"), so it resolves inside /admin.
 * The shared redirect() helper goes to the site root, which is the customer side.
 */
function admin_redirect(string $target): void
{
    header('Location: ' . $target);
    exit;
}

// admin_type values are stored in the database. "user" is shown as "Super" (full access).
const ADMIN_TYPES = ['user', 'news', 'order', 'quote'];
const ADMIN_TYPE_LABELS = ['user' => 'Super', 'news' => 'News', 'order' => 'Orders', 'quote' => 'Quotes'];

/*
 * Access by category. Every admin can SEE every page (view only). A category lists the
 * pages it may CHANGE. Super ("user") can change everything.
 * Trash is checked item by item (see trash.php); Dashboard and Profile never change anything.
 */
const ADMIN_WRITE = [
    'user'  => ['products', 'materials', 'orders', 'quotes', 'faq', 'reviews', 'news', 'gallery', 'users'],
    'news'  => ['news', 'gallery', 'faq'],
    'order' => ['products', 'materials', 'orders', 'quotes'],
    'quote' => ['quotes'],
];
const ADMIN_SECTION_MAP = [
    'index.php' => 'dashboard', 'products.php' => 'products', 'materials.php' => 'materials',
    'orders.php' => 'orders', 'quotes.php' => 'quotes', 'faq.php' => 'faq', 'reviews.php' => 'reviews',
    'news.php' => 'news', 'gallery.php' => 'gallery', 'users.php' => 'users',
    'trash.php' => 'trash', 'profile.php' => 'profile',
];
const TRASH_SECTION_MAP = [
    'product' => 'products', 'material' => 'materials', 'review' => 'reviews',
    'news' => 'news', 'gallery' => 'gallery', 'faq' => 'faq',
];

function admin_category(): string
{
    global $adminUser;
    return (string)($adminUser['admin_type'] ?? 'user');
}

/** Can the signed-in admin change things in this section? */
function admin_can_write(string $section): bool
{
    if ($section === 'trash') {
        return true; // each restore is checked against its own section
    }
    if (in_array($section, ['dashboard', 'profile'], true)) {
        return false;
    }
    return in_array($section, ADMIN_WRITE[admin_category()] ?? [], true);
}

$adminActive = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$adminSection = ADMIN_SECTION_MAP[$adminActive] ?? 'dashboard';
$canWrite = admin_can_write($adminSection);

/** Sidebar links: [file, label, icon]. */
function admin_links(): array
{
    return [
        ['index.php',     'Dashboard',       'mdi-view-dashboard-outline'],
        ['products.php',  'Products',        'mdi-cube-outline'],
        ['materials.php', 'Materials',       'mdi-layers-outline'],
        ['orders.php',    'Orders',          'mdi-cart-outline'],
        ['quotes.php',    'Quote requests',  'mdi-file-document-outline'],
        ['faq.php',       'FAQ',             'mdi-help-circle-outline'],
        ['reviews.php',   'Reviews',         'mdi-star-outline'],
        ['news.php',      'News',            'mdi-newspaper-variant-outline'],
        ['gallery.php',   'Gallery',         'mdi-image-multiple-outline'],
        ['users.php',     'Users & admins',  'mdi-account-multiple-outline'],
        ['trash.php',     'Deleted items',   'mdi-delete-restore'],
    ];
}

/** Count rows that are waiting for the admin. Returns 0 if a table is missing (before the update). */
function admin_count(string $table, string $where): int
{
    try {
        return (int)(db_fetch_one("SELECT COUNT(*) AS n FROM `$table` WHERE $where")['n'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Items waiting for an admin. Counted once per request and reused by the bell, badge and menu. */
function admin_pending_counts(): array
{
    static $counts = null;
    if ($counts === null) {
        $counts = [
            'quotes'  => admin_count('quote', "quote_status IN ('pending','reviewed')"),
            'reviews' => admin_count('review', "status = 'pending' AND deleted_at IS NULL"),
        ];
    }
    return $counts;
}

function admin_notification_count(): int
{
    return array_sum(admin_pending_counts());
}

function admin_header(string $title): void
{
    global $adminUser, $adminActive, $adminSection, $canWrite;
    $flashSuccess = flash('success');
    $flashError = flash('error');
    $pendingCounts = admin_pending_counts();
    $notifyTotal = admin_notification_count();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title><?= e($title) ?> | 3D EDGE Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/vendors/mdi/css/materialdesignicons.min.css">
<link rel="stylesheet" href="assets/vendors/css/vendor.bundle.base.css">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/3d-edge-admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/3d-edge-admin.css') ?>">
</head>
<body class="<?= trim(($canWrite ? '' : 'view-only ') . ($adminSection === 'profile' ? 'profile-page' : '')) ?>">
<div class="container-scroller">

<nav class="navbar default-layout-navbar admin-nav fixed-top d-flex flex-row" aria-label="Admin navigation bar">
    <div class="navbar-brand-wrapper admin-nav-brand d-flex align-items-center">
        <a class="navbar-brand brand-logo" href="index.php" aria-label="3D EDGE Admin">
            <img src="assets/images/3d-edge-logo.png" alt="3D EDGE — Scan . Design . Print">
        </a>
    </div>

    <div class="admin-nav-main d-flex align-items-center">
        <button class="navbar-toggler admin-icon-btn d-none d-lg-inline-flex" type="button" data-toggle="minimize" aria-label="Collapse sidebar">
            <i class="mdi mdi-menu" aria-hidden="true"></i>
        </button>

        <form class="admin-search" role="search" action="#" onsubmit="return false;">
            <i class="mdi mdi-magnify" aria-hidden="true"></i>
            <input type="search" id="admin-search" placeholder="Search" aria-label="Search admin pages" autocomplete="off">
            <kbd class="admin-kbd" aria-hidden="true">Ctrl + K</kbd>
        </form>
    </div>

    <ul class="admin-nav-actions d-flex align-items-center list-unstyled mb-0">
        <li>
            <button type="button" class="admin-icon-btn" id="admin-theme-toggle" aria-label="Switch light and dark mode" title="Light / dark mode">
                <i class="mdi mdi-white-balance-sunny" aria-hidden="true"></i>
            </button>
        </li>
        <li class="dropdown">
            <button type="button" class="admin-icon-btn admin-bell" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications, <?= $notifyTotal ?> waiting">
                <i class="mdi mdi-bell-outline" aria-hidden="true"></i>
                <?php if ($notifyTotal > 0): ?>
                    <span class="admin-badge" aria-hidden="true"><?= $notifyTotal ?></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-menu dropdown-menu-end admin-dropdown">
                <div class="admin-dropdown-head">Waiting for you</div>
                <a class="dropdown-item" href="quotes.php?tab=pending"><span>Quote requests</span><span class="admin-count"><?= $pendingCounts['quotes'] ?></span></a>
                <a class="dropdown-item" href="reviews.php"><span>Reviews to check</span><span class="admin-count"><?= $pendingCounts['reviews'] ?></span></a>
            </div>
        </li>
        <li class="dropdown">
            <button type="button" class="admin-avatar-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu for <?= e((string)($adminUser['username'] ?? 'admin')) ?>">
                <img src="assets/images/admin-avatar.svg" alt="" width="40" height="40">
            </button>
            <div class="dropdown-menu dropdown-menu-end admin-dropdown">
                <div class="admin-dropdown-head"><?= e((string)($adminUser['username'] ?? 'Administrator')) ?></div>
                <a class="dropdown-item" href="profile.php"><i class="mdi mdi-account-circle-outline"></i> My profile</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="../logout.php"><i class="mdi mdi-logout"></i> Log out</a>
            </div>
        </li>
    </ul>

    <button class="navbar-toggler admin-icon-btn d-lg-none admin-menu-btn" type="button" data-toggle="offcanvas" aria-label="Open menu">
        <i class="mdi mdi-menu" aria-hidden="true"></i>
    </button>
</nav>

<div class="container-fluid page-body-wrapper">
<nav class="sidebar sidebar-offcanvas" id="sidebar">
    <ul class="nav">
        <li class="nav-item nav-profile">
            <div class="nav-link">
                <div class="nav-profile-image admin-avatar"><i class="mdi mdi-cube-scan"></i></div>
                <div class="nav-profile-text">
                    <p class="mb-0">3D EDGE</p>
                    <small>Administrator</small>
                </div>
            </div>
        </li>
        <li class="nav-item nav-category">Workspace</li>
        <?php foreach (admin_links() as [$file, $label, $icon]): ?>
            <li class="nav-item<?= $adminActive === $file ? ' active' : '' ?>">
                <a class="nav-link" href="<?= e($file) ?>"<?= $adminActive === $file ? ' aria-current="page"' : '' ?>>
                    <span class="menu-title"><?= e($label) ?></span>
                    <i class="menu-icon mdi <?= e($icon) ?>"></i>
                </a>
            </li>
        <?php endforeach; ?>
        <li class="nav-item nav-category">Account</li>
        <li class="nav-item"><a class="nav-link" href="profile.php"><span class="menu-title">Admin profile</span><i class="menu-icon mdi mdi-account-circle-outline"></i></a></li>
        <li class="nav-item"><a class="nav-link" href="../logout.php"><span class="menu-title">Log out</span><i class="menu-icon mdi mdi-logout"></i></a></li>
    </ul>
</nav>

<div class="main-panel">
<div class="content-wrapper">
<?php if ($flashSuccess): ?>
    <div class="alert alert-success" role="status"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>
<?php if (!$canWrite && !in_array($adminSection, ['dashboard', 'profile'], true)): ?>
    <div class="view-only-note" role="note"><i class="mdi mdi-eye-outline"></i>
        <span>View only. Your category (<strong><?= e(ADMIN_TYPE_LABELS[admin_category()] ?? 'Super') ?></strong>) can see this page but not change it.</span></div>
<?php endif; ?>
<?php
}

function admin_footer(): void
{
    ?>
</div>
<footer class="footer">
    <div class="container-fluid d-flex justify-content-between">
        <span class="text-muted">&copy; <?= date('Y') ?> 3D EDGE</span>
        <span class="text-muted">Admin workspace</span>
    </div>
</footer>
</div>
</div>
</div>
<script src="assets/vendors/js/vendor.bundle.base.js"></script>
<script src="assets/js/off-canvas.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/off-canvas.js') ?>"></script>
<script src="assets/js/misc.js"></script>
<script src="assets/js/admin.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/admin.js') ?>"></script>
</body>
</html>
<?php
}

function admin_csrf_field(): void
{
    echo '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function admin_post_guard(?string $section = null): void
{
    global $adminSection, $adminActive;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid or expired form token. Refresh the page and try again.');
    }
    // 'any' skips the section check; trash.php checks each item itself.
    $check = $section ?? $adminSection;
    if ($check !== 'any' && !admin_can_write($check)) {
        flash('error', 'Your category (' . (ADMIN_TYPE_LABELS[admin_category()] ?? 'Super') . ') can view this page but not change it.');
        admin_redirect($adminActive);
    }
}

/**
 * Validate and store one uploaded image.
 * Returns ['ok' => true, 'file' => 'name.jpg'] or ['ok' => false, 'error' => '...'].
 * Files go to admin/uploads/<subdir>/ and get a random name.
 */
function admin_store_image(array $file, string $subdir, int $maxBytes = 5242880): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => ($file['name'] ?? 'File') . ' could not be uploaded.'];
    }
    $name = (string)($file['name'] ?? '');
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => "$name is larger than " . (int)($maxBytes / 1048576) . ' MB.'];
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = ['jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP];
    $info = @getimagesize((string)$file['tmp_name']);
    if (!isset($types[$ext]) || $info === false || $info[2] !== $types[$ext]) {
        return ['ok' => false, 'error' => "$name must be a JPG, PNG or WEBP image."];
    }
    $dir = __DIR__ . '/uploads/' . $subdir . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'The upload folder could not be created.'];
    }
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . $stored)) {
        return ['ok' => false, 'error' => "$name could not be saved."];
    }
    return ['ok' => true, 'file' => $stored];
}

/** Normalise the multi-file input (name[], tmp_name[] ...) into a list of single files. */
function admin_files(string $field): array
{
    $files = $_FILES[$field] ?? null;
    if (!$files) {
        return [];
    }
    // A single-file input (name="x") gives strings; a multiple input (name="x[]") gives arrays.
    if (!is_array($files['name'])) {
        $files = [
            'name'     => [$files['name']],
            'tmp_name' => [$files['tmp_name']],
            'error'    => [$files['error']],
            'size'     => [$files['size']],
        ];
    }
    $list = [];
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $list[] = ['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
    }
    return $list;
}

/** Consistent page header: title, a short description, and optional action buttons (HTML). */
function admin_page_head(string $title, string $description, string $actionsHtml = ''): void
{
    echo '<div class="page-head">';
    echo '<div><h2 class="page-title">' . e($title) . '</h2>';
    echo '<p class="page-desc">' . e($description) . '</p></div>';
    if ($actionsHtml !== '') {
        echo '<div class="page-actions">' . $actionsHtml . '</div>';
    }
    echo '</div>';
}

/**
 * Tab bar driven by ?tab=. $tabs is [key => [label, count]].
 * Returns the active key (falls back to the first tab if the value is unknown).
 */
function admin_tabs(array $tabs, string $param = 'tab'): string
{
    $active = (string)($_GET[$param] ?? '');
    if (!isset($tabs[$active])) {
        $active = (string)array_key_first($tabs);
    }
    echo '<ul class="nav admin-tabs" role="tablist">';
    foreach ($tabs as $key => [$label, $count]) {
        $isActive = $key === $active;
        echo '<li class="nav-item"><a class="nav-link' . ($isActive ? ' active' : '') . '" href="?' . e($param) . '=' . e($key) . '"'
            . ($isActive ? ' aria-current="page"' : '') . '>' . e($label)
            . ($count === null ? '' : ' <span class="tab-count">' . (int)$count . '</span>') . '</a></li>';
    }
    echo '</ul>';
    return $active;
}

/** "in_production" -> "In production". */
function admin_label(string $value): string
{
    return ucfirst(str_replace('_', ' ', $value));
}

/** Pill badge for a status value. "muted" styles neutral states. */
function admin_status(string $value, bool $muted = false): string
{
    $cls = $muted ? 'status-pill is-muted' : 'status-pill';
    return '<span class="' . $cls . '">' . e(admin_label($value)) . '</span>';
}

/**
 * Upload files from a multiple-file input, keeping each file's position in the list
 * (so a radio button "cover_index = 2" maps to the third selected file).
 * Returns [index => ['name'=>..,'tmp_name'=>..,'error'=>..,'size'=>..]] for selected files only.
 */
function admin_files_indexed(string $field): array
{
    $files = $_FILES[$field] ?? null;
    if (!$files) {
        return [];
    }
    // A single-file input (name="x") is treated as one file at index 0.
    if (!is_array($files['name'])) {
        $files = [
            'name'     => [$files['name']],
            'tmp_name' => [$files['tmp_name']],
            'error'    => [$files['error']],
            'size'     => [$files['size']],
        ];
    }
    $list = [];
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $list[$i] = ['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
    }
    return $list;
}

/**
 * Store one uploaded .glb 3D model. Checks the extension, the size and the file header
 * ("glTF" magic bytes), so renamed files are rejected.
 * Returns ['ok'=>true,'file'=>'name.glb'] or ['ok'=>false,'error'=>'...'].
 */
function admin_store_model(array $file, string $subdir = 'models', int $maxBytes = 52428800): array
{
    $name = (string)($file['name'] ?? '');
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => "$name could not be uploaded."];
    }
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'glb') {
        return ['ok' => false, 'error' => "$name must be a .glb 3D model."];
    }
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => "$name is larger than " . (int)($maxBytes / 1048576) . ' MB.'];
    }
    $head = (string)@file_get_contents((string)$file['tmp_name'], false, null, 0, 4);
    if ($head !== 'glTF') {
        return ['ok' => false, 'error' => "$name is not a valid .glb file."];
    }
    $dir = __DIR__ . '/uploads/' . $subdir . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'The model folder could not be created.'];
    }
    $stored = bin2hex(random_bytes(12)) . '.glb';
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . $stored)) {
        return ['ok' => false, 'error' => "$name could not be saved."];
    }
    return ['ok' => true, 'file' => $stored];
}

/** Shorten text for a table cell without splitting it awkwardly. */
function admin_excerpt(string $text, int $max = 90): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    return strlen($text) > $max ? rtrim(substr($text, 0, $max)) . '…' : $text;
}

/**
 * Save uploaded images for a product or material and choose the cover.
 * $table is product_img or material_img, $fk is product_id or material_id,
 * $coverIndex is the position chosen with the "Cover" radio buttons (or null).
 * Returns a list of error messages (empty when everything saved).
 */
function admin_save_images(string $table, string $fk, int $id, string $field, string $subdir, ?int $coverIndex): array
{
    $pk = $table . '_id';
    $errors = [];
    $saved = []; // [file index => new image id]
    foreach (admin_files_indexed($field) as $index => $file) {
        $result = admin_store_image($file, $subdir);
        if (!$result['ok']) { $errors[] = $result['error']; continue; }
        // Stored relative to admin/uploads/, which is how the customer pages read it.
        $stmt = db_execute("INSERT INTO `$table` (`$fk`, img_url, cover_img) VALUES (?, ?, 0)",
            [$id, $subdir . '/' . $result['file']]);
        $saved[$index] = (int)$stmt->insert_id;
    }
    if ($saved) {
        $hasCover = (bool)db_fetch_one("SELECT 1 AS x FROM `$table` WHERE `$fk` = ? AND cover_img = 1 LIMIT 1", [$id]);
        $chosen = ($coverIndex !== null && isset($saved[$coverIndex])) ? $saved[$coverIndex] : null;
        if ($chosen !== null) {
            db_execute("UPDATE `$table` SET cover_img = 0 WHERE `$fk` = ?", [$id]);
            db_execute("UPDATE `$table` SET cover_img = 1 WHERE `$pk` = ?", [$chosen]);
        } elseif (!$hasCover) {
            db_execute("UPDATE `$table` SET cover_img = 1 WHERE `$pk` = ?", [reset($saved)]);
        }
    }
    return $errors;
}

/* ==========================================================================
   Shared helpers added in the admin audit. Everything above is unchanged,
   so pages that are not part of this update keep working as before.
   ========================================================================== */

/** Attributes that disable a write control for view-only admins (add inside a button tag). */
function admin_write_attr(): string
{
    global $canWrite;
    return $canWrite ? '' : ' disabled title="Your category can view this page but not change it"';
}

/** "R 1,234.50", or a dash when there is no value. */
function admin_money($amount): string
{
    return ($amount === null || $amount === '') ? admin_dash() : 'R ' . number_format((float)$amount, 2);
}

/** "14 Oct 2026" (or with the time), or a dash when empty or not a date. */
function admin_date(?string $value, bool $withTime = false): string
{
    $time = $value ? strtotime($value) : false;
    if ($time === false) {
        return admin_dash();
    }
    return e(date($withTime ? 'd M Y, H:i' : 'd M Y', $time));
}

/** A muted dash for an empty cell (already HTML, do not escape again). */
function admin_dash(): string
{
    return '<span class="text-muted">—</span>';
}

/** Cell text, or a dash when the value is empty. Escapes the text. */
function admin_or_dash($value): string
{
    $text = trim((string)($value ?? ''));
    return $text === '' ? admin_dash() : e($text);
}

/** "Black, White, Grey" becomes one chip per colour (the customer pages split the list the same way). */
function admin_chips(?string $csv): string
{
    $items = array_filter(array_map('trim', explode(',', (string)$csv)), static fn($v) => $v !== '');
    if (!$items) {
        return admin_dash();
    }
    $html = '<div class="chip-list">';
    foreach ($items as $item) {
        $html .= '<span class="chip">' . e($item) . '</span>';
    }
    return $html . '</div>';
}

/** Customer-facing reference, e.g. admin_ref('3DE', 12) gives "3DE-0012" (as shown on the customer's profile). */
function admin_ref(string $prefix, int $id): string
{
    return $prefix . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/** Pill with custom text. $tone is '', 'is-muted', 'is-warn' or 'is-bad'. */
function admin_pill(string $text, string $tone = ''): string
{
    return '<span class="status-pill' . ($tone !== '' ? ' ' . e($tone) : '') . '">' . e($text) . '</span>';
}

/** A shortened text cell with the full text on hover. */
function admin_clip($text, int $max = 60): string
{
    $text = trim((string)($text ?? ''));
    if ($text === '') {
        return admin_dash();
    }
    return '<span class="cell-clip" title="' . e($text) . '">' . e(admin_excerpt($text, $max)) . '</span>';
}

/**
 * Remove one image of a product or material: the database row and the file on disk.
 * If it was the cover, the oldest remaining image becomes the cover.
 * The file is only deleted when it sits inside admin/uploads and no other row uses it.
 */
function admin_delete_image(string $table, string $fk, int $ownerId, int $imageId): bool
{
    $pk = $table . '_id';
    $row = db_fetch_one("SELECT img_url, cover_img FROM `$table` WHERE `$pk` = ? AND `$fk` = ?", [$imageId, $ownerId]);
    if (!$row) {
        return false;
    }
    db_execute("DELETE FROM `$table` WHERE `$pk` = ? AND `$fk` = ?", [$imageId, $ownerId]);
    if ((int)$row['cover_img'] === 1) {
        db_execute("UPDATE `$table` SET cover_img = 1 WHERE `$fk` = ? ORDER BY `$pk` ASC LIMIT 1", [$ownerId]);
    }
    $relative = (string)$row['img_url'];
    if (!db_fetch_one("SELECT 1 AS x FROM `$table` WHERE img_url = ? LIMIT 1", [$relative])) {
        $base = realpath(__DIR__ . '/uploads');
        $path = realpath(__DIR__ . '/uploads/' . $relative);
        if ($base !== false && $path !== false && str_starts_with($path, $base . DIRECTORY_SEPARATOR) && is_file($path)) {
            @unlink($path);
        }
    }
    return true;
}
