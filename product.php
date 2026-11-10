<?php
require_once __DIR__ . '/Start_session.php';

$pageCss = 'product.css';
$uploadBase = 'admin/uploads/'; // img_url / model_url in the DB are relative to this
$lowStock = 5;                  // same rule as the admin panel
$maxPerOrder = 5;               // printed-to-order models: more than this needs a quote

$categoryNames = [
    'models'        => '3D printed models',
    'printer'       => 'FDM printers',
    'resin_printer' => 'Resin printers',
    'material'      => 'Filament & resin',
    'accessory'     => 'Spares & accessories',
];

// Product ID from the URL (?id=5), 0 if missing
$id = (int)($_GET['id'] ?? 0);

// Product + its material. Only 'available' products are shown
$stmt = $conn->prepare("
    SELECT p.*, m.material_id AS mat_id, m.material_type, m.status AS material_status
    FROM product p
    LEFT JOIN material m ON m.material_id = p.material_id
    WHERE p.product_id = ? AND p.status = 'available'
");
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

// Not found or hidden: show a 404 page
if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    require_once __DIR__ . '/include/head.php'; ?>
    <body>
    <?php include __DIR__ . '/include/navbar.php'; ?>
    <main id="main" class="shop-main">
    <div class="container-xl page-pad">
      <div class="empty-block">
        <i class="bi bi-box" aria-hidden="true"></i>
        <h1 class="h2">This product isn't available</h1>
        <p>It may have sold out for good or been removed from the catalogue.</p>
        <a href="products.php" class="btn btn-ink">Browse products</a>
      </div>
    </div>
    </main>
    <?php include __DIR__ . '/include/footer.php'; ?>
    </body>
    </html>
    <?php exit;
}

// Printed models are made when ordered, so stock doesn't apply to them
$madeToOrder = (int)$product['made_to_order'] === 1;

/* --- Options: other colours/sizes in the same group --- */
$siblings = [];
if ($product['group_code'] !== null && $product['group_code'] !== '') {
    $stmt = $conn->prepare("
        SELECT product_id, colour, size, quantity, made_to_order
        FROM product
        WHERE group_code = ? AND status = 'available'
        ORDER BY product_id
    ");
    $stmt->bind_param('s', $product['group_code']);
    $stmt->execute();
    $siblings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$groupIds = $siblings ? array_map('intval', array_column($siblings, 'product_id')) : [$id];

// Finds the option with this colour + size, or the first with just the colour/size if that combo doesn't exist
function product_find_option(array $siblings, ?string $colour, ?string $size, string $keep): ?array
{
    foreach ($siblings as $s) if ($s['colour'] === $colour && $s['size'] === $size) return $s;
    foreach ($siblings as $s) if ($s[$keep] === ($keep === 'colour' ? $colour : $size)) return $s;
    return null;
}

// Colour buttons: each links to that colour in the current size
$colourOptions = [];
foreach (array_unique(array_filter(array_column($siblings, 'colour'), 'strlen')) as $c) {
    $opt = product_find_option($siblings, $c, $product['size'], 'colour');
    // Colour not made in the current size: hint which sizes it does come in, e.g. "1kg only"
    $hint = '';
    if ($opt['size'] !== $product['size'] && $product['size'] !== null) {
        $hasSizes = array_unique(array_column(array_filter($siblings, fn($s) => $s['colour'] === $c), 'size'));
        $hint = implode(' / ', array_filter($hasSizes, 'strlen')) . ' only';
    }
    $colourOptions[] = ['label' => $c, 'id' => (int)$opt['product_id'], 'hint' => $hint,
                        'out' => !(int)$opt['made_to_order'] && (int)$opt['quantity'] <= 0, 'active' => $c === $product['colour']];
}

// Size buttons: each links to that size in the current colour
$sizeOptions = [];
foreach (array_unique(array_filter(array_column($siblings, 'size'), 'strlen')) as $sz) {
    $opt = product_find_option($siblings, $product['colour'], $sz, 'size');
    $sizeOptions[] = ['label' => $sz, 'id' => (int)$opt['product_id'],
                      'missing' => $opt['colour'] !== $product['colour'], // this size doesn't exist in the current colour
                      'out' => !(int)$opt['made_to_order'] && (int)$opt['quantity'] <= 0, 'active' => $sz === $product['size']];
}
// Only show a picker when there's an actual choice
if (count($colourOptions) < 2) $colourOptions = [];
if (count($sizeOptions) < 2) $sizeOptions = [];

$pageTitle = $product['product_name'] . ($product['colour'] ? ' - ' . $product['colour'] : '');
$qty = (int)$product['quantity'];
$categoryLabel = $categoryNames[$product['product_category']] ?? '';
$priceNow = $product['discounted_price'] !== null ? $product['discounted_price'] : $product['price'];

// Stock label + colour class
if ($madeToOrder) {
    $stockText = 'Printed to order';
    $stockClass = 'stock-in';
    $qty = $maxPerOrder; // quantity box goes up to 5
} elseif ($qty <= 0) {
    $stockText = 'Out of stock';
    $stockClass = 'stock-out';
} elseif ($qty <= $lowStock) {
    $stockText = "Only {$qty} left";
    $stockClass = 'stock-low';
} else {
    $stockText = "{$qty} in stock";
    $stockClass = 'stock-in';
}

// All photos, cover first. If this option has none, use the photos of the first option in the group that does
$in = implode(',', array_fill(0, count($groupIds), '?'));
$stmt = $conn->prepare("SELECT product_id, img_url FROM product_img WHERE product_id IN ($in)
                        ORDER BY product_id = ? DESC, product_id ASC, cover_img DESC, product_img_id ASC");
$stmt->bind_param(str_repeat('i', count($groupIds) + 1), ...[...$groupIds, $id]);
$stmt->execute();
$images = [];
$imgOwner = null; // product_id the photos belong to
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $imgOwner ??= (int)$row['product_id'];
    if ((int)$row['product_id'] === $imgOwner) $images[] = $uploadBase . $row['img_url'];
}

// Photos borrowed from another colour: tell the customer which colour they're seeing
$photoNote = null;
if ($images && $imgOwner !== $id && $product['colour']) {
    foreach ($siblings as $s) {
        if ((int)$s['product_id'] === $imgOwner && $s['colour'] && $s['colour'] !== $product['colour']) {
            $photoNote = "Photo shows the {$s['colour']} version. Yours will be {$product['colour']}.";
        }
    }
}

// Optional .glb 3D model (product.model_url, e.g. 'models/part.glb')
$model = $product['model_url'] ? $uploadBase . $product['model_url'] : null;

/* --- Reviews: approved reviews show full details; pending/rejected show username + stars only --- */
$stmt = $conn->prepare("
    SELECT r.review_id, r.rating, r.comment, r.date_posted, r.status, u.username
    FROM review r
    JOIN customer c ON c.customer_id = r.customer_id
    JOIN user u ON u.user_id = c.user_id
    WHERE r.product_id IN ($in)
      AND r.status IN ('approved', 'pending', 'rejected')
    ORDER BY r.date_posted DESC
");
$stmt->bind_param(str_repeat('i', count($groupIds)), ...$groupIds);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Only approved reviews show their full details and photos.
$approvedReviews = array_values(array_filter(
    $reviews,
    static fn($r) => $r['status'] === 'approved'
));

// Review photos grouped by approved review: $reviewImages[review_id] = [img1, img2, ...]
$reviewImages = [];
if ($approvedReviews) {
    $ids = array_column($approvedReviews, 'review_id');
    $stmt = $conn->prepare("SELECT review_id, review_img_url FROM review_img WHERE review_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $stmt->bind_param(str_repeat('s', count($ids)), ...$ids);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $ri) {
        $reviewImages[$ri['review_id']][] = $uploadBase . $ri['review_img_url'];
    }
}

// All review statuses affect the public average and star breakdown.
$reviewCount = count($reviews);
$avgRating = $reviewCount ? array_sum(array_column($reviews, 'rating')) / $reviewCount : 0;
$avgStars = round($avgRating * 2) / 2; // nearest half star
$breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($reviews as $r) $breakdown[max(1, min(5, (int)$r['rating']))]++;

// 4 related products from the same category, in stock first
// Skips this product's own group, and shows each other group once (its lowest id)
$groupCode = $product['group_code'] ?? '';
$stmt = $conn->prepare("
    SELECT p.product_id, p.product_name, p.price, p.discounted_price,
           (SELECT img_url FROM product_img WHERE product_id = p.product_id
            ORDER BY cover_img DESC, product_img_id ASC LIMIT 1) AS cover_img_url
    FROM product p
    WHERE p.status = 'available' AND p.product_category = ? AND p.product_id <> ?
      AND (p.group_code IS NULL OR p.group_code <> ?)
      AND NOT EXISTS (SELECT 1 FROM product x WHERE x.group_code = p.group_code
                      AND x.status = 'available' AND x.product_id < p.product_id)
    ORDER BY p.quantity > 0 DESC, p.created_at DESC
    LIMIT 4
");
$stmt->bind_param('sis', $product['product_category'], $id, $groupCode);
$stmt->execute();
$related = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/include/head.php';
?>
<body>
<?php include __DIR__ . '/include/navbar.php'; ?>
<main id="main" class="shop-main">

<div class="container-xl page-pad">
  <!-- Breadcrumb -->
  <nav aria-label="Breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="products.php">Products</a></li>
      <li class="breadcrumb-item"><a href="products.php?category[]=<?= htmlspecialchars($product['product_category']) ?>"><?= htmlspecialchars($categoryLabel) ?></a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($product['product_name']) ?></li>
    </ol>
  </nav>

  <div class="row g-4 g-lg-5">
    <!-- Left: photos / 3D model -->
    <div class="col-lg-7">
      <div class="gallery">
        <div class="plate plate-lg stage">
          <?php if ($images): ?>
            <!-- Main photo: hover shows Enlarge, click opens the lightbox (shop.js) -->
            <button type="button" class="zoomable" id="galleryZoom" data-photos="<?= htmlspecialchars(json_encode($images)) ?>" data-index="0" aria-label="Enlarge photo">
              <img src="<?= htmlspecialchars($images[0]) ?>" alt="<?= htmlspecialchars($product['product_name']) ?>" id="galleryMain">
              <span class="zoom-overlay"><span>Enlarge</span></span>
            </button>
          <?php elseif (!$model): ?>
            <i class="bi bi-box" aria-hidden="true"></i>
          <?php endif; ?>

          <?php if ($model): ?>
            <!-- 3D model: drag to spin 360, scroll/pinch to zoom -->
            <model-viewer
    id="modelViewer"
    src="<?= htmlspecialchars($model) ?>"
    camera-controls>
</model-viewer>
            <?php if ($images): ?>
              <button type="button" class="view-toggle" id="viewToggle" aria-pressed="false">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i> <span>Rotate in 3D</span>
              </button>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if ($photoNote): ?>
          <p class="model-hint"><i class="bi bi-info-circle" aria-hidden="true"></i> <?= htmlspecialchars($photoNote) ?></p>
        <?php endif; ?>

        <?php if ($model): ?>
          <p class="model-hint" id="modelHint" <?= $images ? 'hidden' : '' ?>><i class="bi bi-hand-index" aria-hidden="true"></i> Drag to spin it 360°, scroll or pinch to zoom.</p>
        <?php endif; ?>

        <?php if (count($images) > 1 || ($model && $images)): ?>
          <!-- Thumbnails: photos swap the main image, the 3D tile shows the model -->
          <div class="thumbs" role="list" aria-label="Product photos">
            <?php foreach ($images as $i => $src): ?>
              <button type="button" class="thumb<?= $i === 0 ? ' active' : '' ?>" data-gallery-src="<?= htmlspecialchars($src) ?>" data-index="<?= $i ?>" aria-label="Show photo <?= $i + 1 ?>" <?= $i === 0 ? 'aria-current="true"' : '' ?> role="listitem">
                <img src="<?= htmlspecialchars($src) ?>" alt="">
              </button>
            <?php endforeach; ?>
            <?php if ($model): ?>
              <button type="button" class="thumb thumb-3d" data-show-3d aria-label="Show 3D model" role="listitem">
                <i class="bi bi-box" aria-hidden="true"></i> 3D
              </button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right: buy box -->
    <div class="col-lg-5">
      <div class="buy-box">
        <span class="card-cat"><?= htmlspecialchars($categoryLabel) ?></span>
        <h1 class="product-title"><?= htmlspecialchars($product['product_name']) ?></h1>

        <?php if ($reviewCount): ?>
          <!-- Average rating, jumps to reviews -->
          <a href="#reviews" class="rating-link">
            <span class="stars" aria-label="<?= number_format($avgStars, 1) ?> out of 5 stars">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="bi <?= $avgStars >= $i ? 'bi-star-fill' : ($avgStars >= $i - 0.5 ? 'bi-star-half' : 'bi-star') ?>" aria-hidden="true"></i>
              <?php endfor; ?>
            </span>
            <span><?= number_format($avgRating, 1) ?> from <?= $reviewCount ?> review<?= $reviewCount === 1 ? '' : 's' ?></span>
          </a>
        <?php else: ?>
          <p class="rating-link text-muted mb-0">No reviews yet</p>
        <?php endif; ?>

        <?php if ($product['summary']): ?><p class="product-summary"><?= htmlspecialchars($product['summary']) ?></p><?php endif; ?>

        <!-- Price with optional sale price -->
        <div class="price price-lg">
          <span class="price-now">R<?= number_format((float)$priceNow, 2) ?></span>
          <?php if ($product['discounted_price'] !== null): ?>
            <s class="price-was">R<?= number_format((float)$product['price'], 2) ?></s>
            <span class="tag tag-sale static">Save R<?= number_format($product['price'] - $product['discounted_price'], 2) ?></span>
          <?php endif; ?>
        </div>
        <p class="vat-note">Price includes VAT</p>

        <?php if ($colourOptions): ?>
          <!-- Colour picker: each button links to that colour's product row -->
          <div class="option-group">
            <p class="option-label">Colour: <strong><?= htmlspecialchars($product['colour']) ?></strong></p>
            <div class="option-list">
              <?php foreach ($colourOptions as $o): ?>
                <a href="product.php?id=<?= $o['id'] ?>" class="opt-btn<?= $o['active'] ? ' active' : '' ?><?= $o['out'] ? ' out' : '' ?>"
                   <?= $o['out'] ? 'title="Sold out"' : '' ?> <?= $o['active'] ? 'aria-current="true"' : '' ?>><?= htmlspecialchars($o['label']) ?><?php if ($o['hint']): ?> <small class="opt-hint">· <?= htmlspecialchars($o['hint']) ?></small><?php endif; ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($sizeOptions): ?>
          <!-- Size/option picker: same idea as colours -->
          <div class="option-group">
            <p class="option-label">Option: <strong><?= htmlspecialchars($product['size']) ?></strong></p>
            <div class="option-list">
              <?php foreach ($sizeOptions as $o): ?>
                <?php if ($o['missing']): ?>
                  <!-- Size not made in this colour: shown but not clickable -->
                  <span class="opt-btn unavailable" title="Not available in <?= htmlspecialchars($product['colour']) ?>" aria-disabled="true"><?= htmlspecialchars($o['label']) ?></span>
                <?php else: ?>
                  <a href="product.php?id=<?= $o['id'] ?>" class="opt-btn<?= $o['active'] ? ' active' : '' ?><?= $o['out'] ? ' out' : '' ?>"
                     <?= $o['out'] ? 'title="Sold out"' : '' ?> <?= $o['active'] ? 'aria-current="true"' : '' ?>><?= htmlspecialchars($o['label']) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <p class="stock <?= $stockClass ?>"><i class="bi bi-circle-fill" aria-hidden="true"></i> <?= $stockText ?></p>

        <?php if ($qty > 0): ?>
          <div class="buy-row">
            <!-- Quantity stepper, +/- handled by shop.js -->
            <div class="qty-stepper">
              <button type="button" class="qty-btn" data-qty-step="-1" aria-label="Decrease quantity"><i class="bi bi-dash" aria-hidden="true"></i></button>
              <label for="qty" class="visually-hidden">Quantity</label>
              <input type="number" id="qty" name="quantity" value="1" min="1" max="<?= $qty ?>" inputmode="numeric">
              <button type="button" class="qty-btn" data-qty-step="1" aria-label="Increase quantity"><i class="bi bi-plus" aria-hidden="true"></i></button>
            </div>
            <button type="button" class="btn btn-ink btn-lg flex-grow-1" data-add-to-cart="<?= $product['product_id'] ?>">Add to cart</button>
          </div>
          <?php if ($madeToOrder): ?>
            <p class="small text-muted mt-2">Need more than <?= $maxPerOrder ?>? <a href="request_quote.php">Request a quote</a>.</p>
          <?php endif; ?>
        <?php else: ?>
          <button type="button" class="btn btn-ink btn-lg w-100" disabled>Sold out</button>
          <p class="small text-muted mt-2">Need it sooner? <a href="request_quote.php">Ask us to print one</a>.</p>
        <?php endif; ?>

        <dl class="spec-list">
          <div><dt>Category</dt><dd><a href="products.php?category[]=<?= htmlspecialchars($product['product_category']) ?>"><?= htmlspecialchars($categoryLabel) ?></a></dd></div>
          <?php if ($product['colour'] && !$colourOptions): ?><div><dt>Colour</dt><dd><?= htmlspecialchars($product['colour']) ?></dd></div><?php endif; ?>
          <?php if ($product['size'] && !$sizeOptions): ?><div><dt>Size</dt><dd><?= htmlspecialchars($product['size']) ?></dd></div><?php endif; ?>
          <?php if ($product['material_type']): ?>
            <div><dt>Material</dt><dd>
              <?php if ($product['material_status'] === 'available'): ?>
                <a href="materials.php#material-<?= (int)$product['mat_id'] ?>"><?= htmlspecialchars($product['material_type']) ?></a>
              <?php else: ?><?= htmlspecialchars($product['material_type']) ?><?php endif; ?>
            </dd></div>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

  <?php if ($product['description']): ?>
    <section class="detail-section" aria-labelledby="descHeading">
      <h2 id="descHeading" class="section-heading">About this product</h2>
      <div class="description"><?= nl2br(htmlspecialchars($product['description'])) ?></div>
    </section>
  <?php endif; ?>

  <!-- Reviews -->
  <section class="detail-section" id="reviews" aria-labelledby="reviewsHeading">
    <h2 id="reviewsHeading" class="section-heading">Reviews</h2>

    <?php if (!$reviews): ?>
      <p class="text-muted">No reviews yet.</p>
    <?php else: ?>
      <div class="row g-4">
        <?php if ($reviewCount): ?>
          <div class="col-md-4 col-lg-3">
            <!-- Aggregate rating counts approved reviews only -->
            <div class="review-summary">
              <div class="avg-number"><?= number_format($avgRating, 1) ?></div>
              <span class="stars" aria-label="<?= number_format($avgStars, 1) ?> out of 5 stars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <i class="bi <?= $avgStars >= $i ? 'bi-star-fill' : ($avgStars >= $i - 0.5 ? 'bi-star-half' : 'bi-star') ?>" aria-hidden="true"></i>
                <?php endfor; ?>
              </span>
              <p class="text-muted small mb-3"><?= $reviewCount ?> approved review<?= $reviewCount === 1 ? '' : 's' ?></p>
              <?php foreach ($breakdown as $star => $n): $pct = round($n / $reviewCount * 100); ?>
                <div class="bar-row">
                  <span><?= $star ?> <i class="bi bi-star-fill" aria-hidden="true"></i></span>
                  <div class="bar" role="img" aria-label="<?= $n ?> approved reviews with <?= $star ?> stars"><span style="width: <?= $pct ?>%"></span></div>
                  <span class="bar-n"><?= $n ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="col-md-8 col-lg-9">
          <div class="review-list">
            <?php foreach ($reviews as $r): ?>
              <article class="review">
                <header class="review-head">
                  <span class="avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($r['username'], 0, 1))) ?></span>
                  <div>
                    <div class="review-user"><?= htmlspecialchars($r['username']) ?></div>
                    <?php if ($r['status'] === 'approved'): ?>
                      <time datetime="<?= date('Y-m-d', strtotime($r['date_posted'])) ?>" class="review-date"><?= date('j F Y', strtotime($r['date_posted'])) ?></time>
                    <?php endif; ?>
                  </div>
                  <div class="ms-auto">
                    <span class="stars" aria-label="<?= (int)$r['rating'] ?> out of 5 stars">
                      <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="bi <?= $r['rating'] >= $i ? 'bi-star-fill' : 'bi-star' ?>" aria-hidden="true"></i>
                      <?php endfor; ?>
                    </span>
                  </div>
                </header>
                <?php if ($r['status'] === 'approved' && $r['comment']): ?><p class="review-text"><?= nl2br(htmlspecialchars($r['comment'])) ?></p><?php endif; ?>
                <?php if ($r['status'] === 'approved' && !empty($reviewImages[$r['review_id']])): ?>
                  <div class="review-photos">
                    <?php foreach ($reviewImages[$r['review_id']] as $src): ?>
                      <!-- Hover shows Enlarge, click opens the lightbox; arrows go through this review's photos -->
                      <button type="button" class="review-photo zoomable" data-lightbox="review-<?= (int)$r['review_id'] ?>" data-src="<?= htmlspecialchars($src) ?>" aria-label="Enlarge photo from <?= htmlspecialchars($r['username']) ?>">
                        <img src="<?= htmlspecialchars($src) ?>" alt="" loading="lazy">
                        <span class="zoom-overlay sm"><span>Enlarge</span></span>
                      </button>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($related): ?>
    <!-- You might also like: same category -->
    <section class="detail-section" aria-labelledby="relatedHeading">
      <h2 id="relatedHeading" class="section-heading">You might also like</h2>
      <div class="row g-3 g-md-4">
        <?php foreach ($related as $rp):
          $img = $rp['cover_img_url'] ? $uploadBase . $rp['cover_img_url'] : null;
          $rpPrice = $rp['discounted_price'] !== null ? $rp['discounted_price'] : $rp['price'];
        ?>
          <div class="col-6 col-md-3">
            <a href="product.php?id=<?= $rp['product_id'] ?>" class="mini-card">
              <span class="plate">
                <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><i class="bi bi-box" aria-hidden="true"></i><?php endif; ?>
              </span>
              <span class="mini-name"><?= htmlspecialchars($rp['product_name']) ?></span>
              <span class="price-now">R<?= number_format((float)$rpPrice, 2) ?></span>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<!-- Lightbox for product + review photos (same look as the gallery page), opened by shop.js -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Enlarged photo" hidden>
  <button type="button" class="lb-btn lb-close" data-lb-close aria-label="Close">&times;</button>
  <button type="button" class="lb-btn lb-prev" data-lb-step="-1" aria-label="Previous photo"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
  <img src="" alt="Enlarged photo" id="lightboxImg">
  <button type="button" class="lb-btn lb-next" data-lb-step="1" aria-label="Next photo"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
  <p class="lb-count" id="lightboxCount"></p>
</div>

</main>
<?php include __DIR__ . '/include/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="JS/shop.js"></script>
<?php if ($model): ?>
<!-- Google's <model-viewer> web component for the .glb file -->
<script type="module" src="https://cdn.jsdelivr.net/npm/@google/model-viewer@4.0.0/dist/model-viewer.min.js"></script>
<?php endif; ?>
</body>
</html>