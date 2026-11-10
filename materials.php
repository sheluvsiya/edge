<?php
require_once __DIR__ . '/Start_session.php';

$pageTitle = 'Materials';
$pageCss = 'materials.css';
$uploadBase = 'admin/uploads/'; // img_url in the DB is relative to this

// Available materials with cover image and how many products use each, cheapest first
$stmt = $conn->prepare("
    SELECT m.material_id, m.material_type, m.description, m.material_color, m.density, m.price_per_kg,
           m.material_weight, m.material_length,
           (SELECT img_url FROM material_img WHERE material_id = m.material_id
            ORDER BY cover_img DESC, material_img_id ASC LIMIT 1) AS cover_img_url,
           (SELECT COUNT(DISTINCT COALESCE(group_code, CONCAT('#', product_id))) FROM product
            WHERE material_id = m.material_id AND status = 'available') AS product_count -- colours/sizes count once
    FROM material m
    WHERE m.status = 'available'
    ORDER BY m.price_per_kg ASC, m.material_type ASC
");
$stmt->execute();
$materials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Extra values each card and table row needs
foreach ($materials as $k => $m) {
    // Same rule as request_quote.php: no stock weight or length means it can't be quoted
    $materials[$k]['in_stock'] = (float)$m['material_weight'] > 0 && (float)$m['material_length'] > 0;
    $materials[$k]['img'] = $m['cover_img_url'] ? $uploadBase . $m['cover_img_url'] : null;
    // "Black, White, Grey" -> ['Black', 'White', 'Grey']
    $materials[$k]['colors'] = array_values(array_filter(array_map('trim', explode(',', $m['material_color'] ?? ''))));
    // 1.20 -> 1.2, 1.00 -> 1
    $materials[$k]['density_text'] = $m['density'] !== null ? rtrim(rtrim((string)$m['density'], '0'), '.') : null;
}

require_once __DIR__ . '/include/head.php';
?>
<body>
<?php include __DIR__ . '/include/navbar.php'; ?>
<main id="main" class="shop-main">

<div class="container-xl page-pad">
  <div class="materials-head">
    <h1 class="page-heading">Our <span class="highlight-box">Materials</span></h1>
    <p class="page-lede">Every print starts with choosing what it's made from. Here's what we print in, what each material is good for, and what it costs.</p>
  </div>

  <?php if (!$materials): ?>
    <!-- Empty state: no materials available -->
    <div class="empty-block">
      <i class="bi bi-layers" aria-hidden="true"></i>
      <h2>Materials are being added</h2>
      <p>Tell us what you need printed and we'll recommend the right material.</p>
      <a href="request_quote.php" class="btn btn-ink">Request a quote</a>
    </div>
  <?php else: ?>

    <div class="row g-4">
      <?php foreach ($materials as $m): ?>
        <div class="col-md-6">
          <!-- id is used for links from the compare table and product pages -->
          <article class="material-card<?= $m['in_stock'] ? '' : ' is-out' ?>" id="material-<?= $m['material_id'] ?>">
            <div class="plate plate-wide">
              <?php if ($m['img']): ?>
                <img src="<?= htmlspecialchars($m['img']) ?>" alt="<?= htmlspecialchars($m['material_type']) ?> sample" loading="lazy">
              <?php else: ?>
                <i class="bi bi-layers" aria-hidden="true"></i>
              <?php endif; ?>
              <?php if (!$m['in_stock']): ?><span class="tag tag-out">Out of stock</span><?php endif; ?>
            </div>

            <div class="material-body">
              <div class="material-title-row">
                <h2 class="material-name"><?= htmlspecialchars($m['material_type']) ?></h2>
                <div class="material-price">R<?= number_format((float)$m['price_per_kg'], 2) ?><span>per kg</span></div>
              </div>

              <?php if ($m['colors']): ?>
                <!-- Colours we can print this material in (same list as the quote form) -->
                <p class="print-colours-label">Print colours</p>
                <ul class="print-colours">
                  <?php foreach ($m['colors'] as $c): ?>
                    <li><?= htmlspecialchars($c) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if ($m['description']): ?>
 <!-- Shows 4 lines; shop.js adds Read more if the text is longer -->
                <div class="material-desc clamped" id="desc-<?= $m['material_id'] ?>" data-clamp><?= nl2br(htmlspecialchars($m['description'])) ?></div>
                <button type="button" class="read-more" aria-controls="desc-<?= $m['material_id'] ?>" aria-expanded="false" hidden>Read more</button>
              <?php endif; ?>

              <dl class="spec-list spec-inline">
                <?php if ($m['density_text'] !== null): ?>
                  <div><dt>Density</dt><dd><?= htmlspecialchars($m['density_text']) ?> g/cm³</dd></div>
                <?php endif; ?>
                <div><dt>Products</dt><dd><?= (int)$m['product_count'] ?> in the shop</dd></div>
              </dl>

              <div class="material-actions">
                <?php if ($m['in_stock']): ?>
                  <!-- Passes the material to the quote form -->
                  <a href="request_quote.php?material=<?= $m['material_id'] ?>" class="btn btn-ink">Get a quote in <?= htmlspecialchars($m['material_type']) ?></a>
                <?php else: ?>
                  <button type="button" class="btn btn-ink" disabled>Out of stock for quotes</button>
                <?php endif; ?>

                <?php if ((int)$m['product_count'] > 0): ?>
                  <!-- Product catalogue filtered to this material -->
                  <a href="products.php?material[]=<?= $m['material_id'] ?>" class="btn btn-outline-dark">Shop <?= htmlspecialchars($m['material_type']) ?> products</a>
                <?php endif; ?>
              </div>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Compare table: shop.js works out material cost = volume x density x price -->
    <section class="detail-section" aria-labelledby="compareHeading">
      <h2 id="compareHeading" class="section-heading">Compare materials</h2>
      <p class="text-muted mb-3">Enter your part's volume to see what the material alone would cost. Your quote also covers design and print time.</p>

      <div class="estimate-input">
        <label for="volume" class="form-label">Part volume</label>
        <div class="input-group">
          <input type="number" id="volume" class="form-control" min="0" step="1" value="100" inputmode="decimal">
          <span class="input-group-text">cm³</span>
        </div>
      </div>

      <div class="table-responsive compare-wrap">
        <table class="table compare-table align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">Material</th>
              <th scope="col">Density</th>
              <th scope="col">Price per kg</th>
              <th scope="col">Weight</th>
              <th scope="col">Material cost</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($materials as $m): $hasDensity = $m['density'] !== null && (float)$m['density'] > 0; ?>
              <!-- data-* values are read by shop.js -->
              <tr data-density="<?= $hasDensity ? (float)$m['density'] : '' ?>" data-price="<?= (float)$m['price_per_kg'] ?>">
                <th scope="row">
                  <a href="#material-<?= $m['material_id'] ?>"><?= htmlspecialchars($m['material_type']) ?></a>
                  <?php if (!$m['in_stock']): ?> <span class="out-note">Out of stock</span><?php endif; ?>
                </th>
                <td><?= $hasDensity ? htmlspecialchars($m['density_text']) . ' g/cm³' : '—' ?></td>
                <td>R<?= number_format((float)$m['price_per_kg'], 2) ?></td>
                <td class="est-weight">—</td> <!-- filled by JS -->
                <td class="est-cost">—</td>   <!-- filled by JS -->
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</div>

</main>
<?php include __DIR__ . '/include/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="JS/shop.js"></script> <!-- volume -> cost calculator -->
</body>
</html>