<?php
require_once __DIR__ . '/Start_session.php';

$pageTitle = 'Products';
$pageCss = 'products.css';

$uploadBase = 'admin/uploads/'; // img_url in the DB is relative to this

// Display names for the product.product_category enum
$categoryNames = [
    'models'        => '3D printed models',
    'printer'       => 'FDM printers',
    'resin_printer' => 'Resin printers',
    'material'      => 'Filament & resin',
    'accessory'     => 'Spares & accessories',
];

$priceExpr = 'COALESCE(p.discounted_price, p.price)'; // sale price if there is one
$groupKey = "COALESCE(p.group_code, CONCAT('#', p.product_id))"; // products without a group are their own group

/* --- Filters from the query string (?q=...&category[]=...) so links can be shared --- */
$search = trim($_GET['q'] ?? '');
$categories = array_values(array_intersect((array)($_GET['category'] ?? []), array_keys($categoryNames)));
$materialIds = array_values(array_unique(array_filter(array_map('intval', (array)($_GET['material'] ?? [])))));
$maxPrice = isset($_GET['max']) && is_numeric($_GET['max']) && $_GET['max'] > 0 ? (float)$_GET['max'] : null;
$inStock = ($_GET['instock'] ?? '') === '1';

// Sort options: label + ORDER BY
$sorts = [
    'newest'     => ['Newest', 'p.created_at DESC, p.product_id DESC'],
    'price_asc'  => ['Price: low to high', 'p.min_price ASC'],  // cheapest option in the group
    'price_desc' => ['Price: high to low', 'p.min_price DESC'],
    'rating'     => ['Top rated', 'avg_rating IS NULL, avg_rating DESC'],
    'name'       => ['Name', 'p.product_name ASC'],
];
$sort = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'newest';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;

/* --- Sidebar data --- */
$stmt = $conn->prepare("SELECT material_id, material_type FROM material WHERE status = 'available' ORDER BY material_type");
$stmt->execute();
$materials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$materialNames = array_column($materials, 'material_type', 'material_id');

$stmt = $conn->prepare("SELECT COALESCE(MAX(COALESCE(discounted_price, price)), 0) AS top FROM product WHERE status = 'available'");
$stmt->execute();
$priceCeil = (float)$stmt->get_result()->fetch_assoc()['top'];
$priceCeil = max(100, ceil($priceCeil / 100) * 100); // round up for the slider max
if ($maxPrice !== null && $maxPrice >= $priceCeil) $maxPrice = null; // slider at max = any price

/* --- WHERE clause built from the filters, values go in $params --- */
$where = ["p.status = 'available'"];
$params = [];

if ($search !== '') {
    $where[] = '(p.product_name LIKE ? OR p.summary LIKE ? OR p.colour LIKE ?)'; // "red" finds red options
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($categories) {
    $where[] = 'p.product_category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
    array_push($params, ...$categories);
}
if ($materialIds) {
    $where[] = 'p.material_id IN (' . implode(',', array_fill(0, count($materialIds), '?')) . ')';
    array_push($params, ...$materialIds);
}
if ($maxPrice !== null) {
    $where[] = "$priceExpr <= ?";
    $params[] = $maxPrice;
}
if ($inStock) {
    $where[] = '(p.quantity > 0 OR p.made_to_order = 1)'; // made-to-order is always available
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// Total matches for pagination (one per group, not per colour)
$stmt = $conn->prepare("SELECT COUNT(DISTINCT $groupKey) AS total FROM product p $whereSql");
if ($params) {
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
}
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['total'];

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages); // page number typed too high
$offset = ($page - 1) * $perPage;

// One card per group: inner query ranks each group's rows (in stock + cheapest first), outer keeps rank 1
$stmt = $conn->prepare("
    SELECT p.*,
           (SELECT pi.img_url FROM product_img pi JOIN product g ON g.product_id = pi.product_id
            WHERE COALESCE(g.group_code, CONCAT('#', g.product_id)) = p.gkey
            ORDER BY pi.product_id = p.product_id DESC, pi.cover_img DESC, pi.product_img_id ASC LIMIT 1) AS cover_img_url,
           (SELECT AVG(r.rating) FROM review r JOIN product g ON g.product_id = r.product_id
            WHERE COALESCE(g.group_code, CONCAT('#', g.product_id)) = p.gkey AND r.status IN ('approved', 'pending', 'rejected')) AS avg_rating,
           (SELECT COUNT(*) FROM review r JOIN product g ON g.product_id = r.product_id
            WHERE COALESCE(g.group_code, CONCAT('#', g.product_id)) = p.gkey AND r.status IN ('approved', 'pending', 'rejected')) AS review_count,
           (SELECT COUNT(DISTINCT g.colour) FROM product g WHERE g.group_code = p.group_code AND g.status = 'available') AS colour_count,
           (SELECT COUNT(DISTINCT g.size) FROM product g WHERE g.group_code = p.group_code AND g.status = 'available') AS size_count
    FROM (
        SELECT p.product_id, p.product_name, p.summary, p.product_category, p.price, p.discounted_price,
               p.quantity, p.made_to_order, p.model_url, p.created_at, p.group_code, $groupKey AS gkey,
               ROW_NUMBER() OVER (PARTITION BY $groupKey ORDER BY (p.quantity > 0 OR p.made_to_order = 1) DESC, $priceExpr ASC, p.product_id ASC) AS rn,
               MIN($priceExpr) OVER (PARTITION BY $groupKey) AS min_price,
               MAX($priceExpr) OVER (PARTITION BY $groupKey) AS max_price,
               SUM(GREATEST(p.quantity, 0)) OVER (PARTITION BY $groupKey) AS group_qty,
               MAX(p.made_to_order) OVER (PARTITION BY $groupKey) AS group_mto
        FROM product p
        $whereSql
    ) p
    WHERE p.rn = 1
    ORDER BY {$sorts[$sort][1]}
    LIMIT $perPage OFFSET $offset
");
if ($params) {
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
}
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* --- Active filter chips: each links to the same page without that one filter --- */
$chipDefs = []; // [label, query key, value to remove or null for the whole key]
if ($search !== '') $chipDefs[] = ['"' . $search . '"', 'q', null];
foreach ($categories as $c) $chipDefs[] = [$categoryNames[$c], 'category', $c];
foreach ($materialIds as $m) if (isset($materialNames[$m])) $chipDefs[] = [$materialNames[$m], 'material', $m];
if ($maxPrice !== null) $chipDefs[] = ['Up to R' . number_format($maxPrice, 2), 'max', null];
if ($inStock) $chipDefs[] = ['In stock', 'instock', null];

$chips = [];
foreach ($chipDefs as [$label, $key, $value]) {
    $query = $_GET;
    unset($query['page']); // back to page 1 when a filter changes
    if ($value === null) {
        unset($query[$key]);
    } else {
        $query[$key] = array_values(array_diff((array)($query[$key] ?? []), [(string)$value]));
        if (!$query[$key]) unset($query[$key]);
    }
    $chips[] = [$label, 'products.php' . ($query ? '?' . http_build_query($query) : '')];
}

require_once __DIR__ . '/include/head.php';
?>
<body>
<?php include __DIR__ . '/include/navbar.php'; ?>
<main id="main" class="shop-main">

<div class="container-xl page-pad">
  <!-- Header: title + sort dropdown -->
  <div class="catalogue-head">
    <div>
      <h1 class="page-heading">Our <span class="highlight-box">Products</span></h1>
      <p class="page-lede">Printers, filament, accessories and parts printed in our Sandton workshop.</p>
    </div>
    <!-- Sort form keeps current filters as hidden inputs -->
    <form method="get" class="sort-form">
      <?php foreach ($_GET as $k => $v): if ($k === 'sort' || $k === 'page') continue; foreach ((array)$v as $vv): ?>
        <input type="hidden" name="<?= htmlspecialchars($k) . (is_array($v) ? '[]' : '') ?>" value="<?= htmlspecialchars((string)$vv) ?>">
      <?php endforeach; endforeach; ?>
      <label for="sort" class="visually-hidden">Sort by</label>
      <select name="sort" id="sort" class="form-select" data-auto-submit>
        <?php foreach ($sorts as $key => [$label]): ?>
          <option value="<?= $key ?>" <?= $sort === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-outline-dark btn-sm">Sort</button></noscript>
    </form>
  </div>

  <div class="row g-4">
    <!-- Filters: sidebar on desktop, slide-in panel on mobile -->
    <div class="col-lg-3 order-lg-last">
      <button class="btn btn-outline-dark w-100 d-lg-none mb-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#filters" aria-controls="filters">
        <i class="bi bi-sliders" aria-hidden="true"></i> Filter<?= $chips ? ' (' . count($chips) . ')' : '' ?>
      </button>

      <div class="offcanvas-lg offcanvas-end filter-panel" tabindex="-1" id="filters" aria-labelledby="filtersLabel">
        <div class="offcanvas-header">
          <h2 class="offcanvas-title h5" id="filtersLabel">Filter products</h2>
          <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#filters" aria-label="Close filters"></button>
        </div>
        <div class="offcanvas-body">
          <form method="get" id="filterForm" class="w-100">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">

            <div class="filter-group">
              <label for="q" class="filter-title">Search</label>
              <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="q" name="q" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Name or keyword">
              </div>
            </div>

            <fieldset class="filter-group">
              <legend class="filter-title">Category</legend>
              <?php foreach ($categoryNames as $val => $label): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="category[]" value="<?= $val ?>" id="cat-<?= $val ?>" <?= in_array($val, $categories, true) ? 'checked' : '' ?> data-auto-submit>
                  <label class="form-check-label" for="cat-<?= $val ?>"><?= $label ?></label>
                </div>
              <?php endforeach; ?>
            </fieldset>

            <?php if ($materials): ?>
            <fieldset class="filter-group">
              <legend class="filter-title">Material</legend>
              <div class="material-checks">
                <?php foreach ($materials as $m): ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="material[]" value="<?= $m['material_id'] ?>" id="mat-<?= $m['material_id'] ?>" <?= in_array((int)$m['material_id'], $materialIds, true) ? 'checked' : '' ?> data-auto-submit>
                    <label class="form-check-label" for="mat-<?= $m['material_id'] ?>"><?= htmlspecialchars($m['material_type']) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <?php endif; ?>

            <div class="filter-group">
              <label for="max" class="filter-title d-flex justify-content-between">
                <span>Max price</span>
                <output for="max" id="maxOut"><?= $maxPrice !== null ? 'R' . number_format($maxPrice, 2) : 'Any' ?></output>
              </label>
              <input type="range" class="form-range" id="max" name="max" min="0" max="<?= $priceCeil ?>" step="50"
                     value="<?= $maxPrice ?? $priceCeil ?>" data-ceil="<?= $priceCeil ?>">
            </div>

            <div class="filter-group">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="instock" value="1" id="instock" <?= $inStock ? 'checked' : '' ?> data-auto-submit>
                <label class="form-check-label" for="instock">In stock only</label>
              </div>
            </div>

            <div class="d-flex gap-2 filter-actions">
              <button type="submit" class="btn btn-ink flex-grow-1">Apply filters</button>
              <?php if ($chips): ?><a href="products.php" class="btn btn-outline-dark">Clear</a><?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Results column -->
    <div class="col-lg-9">
      <!-- Count + removable filter chips -->
      <div class="results-bar">
        <span class="result-count"><?= $total ?> product<?= $total === 1 ? '' : 's' ?></span>
        <?php foreach ($chips as [$label, $href]): ?>
          <a href="<?= htmlspecialchars($href) ?>" class="filter-chip" aria-label="Remove filter <?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?> <i class="bi bi-x" aria-hidden="true"></i></a>
        <?php endforeach; ?>
        <?php if (count($chips) > 1): ?><a href="products.php" class="clear-link">Clear all</a><?php endif; ?>
      </div>

      <?php if (!$products): ?>
        <!-- Empty states: no match vs no products at all -->
        <div class="empty-block">
          <i class="bi bi-search" aria-hidden="true"></i>
          <?php if ($chips): ?>
            <h2>No products match these filters</h2>
            <p>Remove a filter or two to see more of the range.</p>
            <a href="products.php" class="btn btn-ink">Clear filters</a>
          <?php else: ?>
            <h2>The catalogue is being stocked</h2>
            <p>Need something specific? Send us your idea and we'll quote on printing it.</p>
            <a href="request_quote.php" class="btn btn-ink">Request a quote</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <!-- Product grid -->
        <div class="row g-3 g-md-4">
          <?php foreach ($products as $p):
            $url = 'product.php?id=' . $p['product_id'];
            $qty = (int)$p['group_mto'] ? 1 : (int)$p['group_qty']; // made-to-order is never sold out
            $img = $p['cover_img_url'] ? $uploadBase . $p['cover_img_url'] : null;
            $priceNow = $p['discounted_price'] !== null ? $p['discounted_price'] : $p['price'];
            $rating = round((float)$p['avg_rating'] * 2) / 2; // nearest half star
            $hasOptions = (int)$p['colour_count'] > 1 || (int)$p['size_count'] > 1;
            $priceVaries = (float)$p['min_price'] < (float)$p['max_price'];
            // "3 colours · 2 sizes"
            $optionText = [];
            if ((int)$p['colour_count'] > 1) $optionText[] = $p['colour_count'] . ' colours';
            if ((int)$p['size_count'] > 1) $optionText[] = $p['size_count'] . ' sizes';
          ?>
            <div class="col-6 col-md-4">
              <article class="product-card">
                <a href="<?= $url ?>" class="plate" tabindex="-1" aria-hidden="true">
                  <?php if ($img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <i class="bi bi-box" aria-hidden="true"></i>
                  <?php endif; ?>
                  <?php if ($p['discounted_price'] !== null): ?><span class="tag tag-sale">Sale</span><?php endif; ?>
                  <?php if ($qty <= 0): ?><span class="tag tag-out">Sold out</span><?php endif; ?>
                  <?php if ($p['model_url']): ?><span class="tag tag-3d"><i class="bi bi-box" aria-hidden="true"></i> 3D view</span><?php endif; ?>
                </a>
                <div class="card-body-x">
                  <span class="card-cat"><?= htmlspecialchars($categoryNames[$p['product_category']] ?? '') ?></span>
                  <h2 class="card-name"><a href="<?= $url ?>"><?= htmlspecialchars($p['product_name']) ?></a></h2>
                  <?php if ($p['summary']): ?><p class="card-summary"><?= htmlspecialchars($p['summary']) ?></p><?php endif; ?>
                  <?php if ($optionText): ?><p class="card-options"><?= implode(' · ', $optionText) ?></p><?php endif; ?>
                  <!-- Stars always show: empty stars and "No reviews yet" until a review is approved -->
                  <div class="card-rating">
                    <span class="stars" aria-label="<?= (int)$p['review_count'] > 0 ? number_format($rating, 1) . ' out of 5 stars' : 'No reviews yet' ?>">
                      <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="bi <?= $rating >= $i ? 'bi-star-fill' : ($rating >= $i - 0.5 ? 'bi-star-half' : 'bi-star') ?>" aria-hidden="true"></i>
                      <?php endfor; ?>
                    </span>
                    <span><?= (int)$p['review_count'] > 0 ? '(' . (int)$p['review_count'] . ')' : 'No reviews yet' ?></span>
                  </div>
                  <div class="card-foot">
                    <div class="price">
                      <?php if ($priceVaries): ?>
                        <span class="price-now"><small>From</small> R<?= number_format((float)$p['min_price'], 2) ?></span>
                      <?php else: ?>
                        <span class="price-now">R<?= number_format((float)$priceNow, 2) ?></span>
                        <?php if ($p['discounted_price'] !== null): ?><s class="price-was">R<?= number_format((float)$p['price'], 2) ?></s><?php endif; ?>
                      <?php endif; ?>
                    </div>
                    <?php if ($hasOptions && $qty > 0): ?>
                      <!-- Has colours/sizes: customer picks one on the product page first -->
                      <a href="<?= $url ?>" class="btn btn-ink btn-cart">Choose options</a>
                    <?php else: ?>
                      <!-- data-add-to-cart holds the product_id for the cart -->
                      <button type="button" class="btn btn-ink btn-cart" data-add-to-cart="<?= $p['product_id'] ?>" <?= $qty <= 0 ? 'disabled' : '' ?>>
                        <?= $qty <= 0 ? 'Sold out' : 'Add to cart' ?>
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): $query = $_GET; ?>
          <!-- Pagination: keeps the current filters in each link -->
          <nav class="pager" aria-label="Pages">
            <ul class="pagination">
              <?php $query['page'] = max(1, $page - 1); ?>
              <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="?<?= htmlspecialchars(http_build_query($query)) ?>">Previous</a></li>
              <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): $query['page'] = $i; ?>
                <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="?<?= htmlspecialchars(http_build_query($query)) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a></li>
              <?php endfor; ?>
              <?php $query['page'] = min($totalPages, $page + 1); ?>
              <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>"><a class="page-link" href="?<?= htmlspecialchars(http_build_query($query)) ?>">Next</a></li>
            </ul>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

</main>
<?php include __DIR__ . '/include/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="JS/shop.js"></script> <!-- auto-submit filters, price slider -->
</body>
</html>