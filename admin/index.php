<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

const DASH_RANGES = [
    'day'   => 'Today',
    'week'  => 'This week',
    'month' => 'This month',
    'year'  => 'This year',
];

/**
 * Buckets for a range: [ [key, label], ... ] in display order.
 * The key matches the SQL grouping expression below.
 */
function dash_buckets(string $range): array
{
    $b = [];
    if ($range === 'day') {
        for ($h = 0; $h < 24; $h++) { $b[] = [sprintf('%02d', $h), sprintf('%02d:00', $h)]; }
    } elseif ($range === 'week') {
        for ($d = 6; $d >= 0; $d--) {
            $ts = strtotime("-$d days");
            $b[] = [date('Y-m-d', $ts), date('D j', $ts)];
        }
    } elseif ($range === 'month') {
        $days = (int)date('t');
        for ($d = 1; $d <= $days; $d++) { $b[] = [sprintf('%02d', $d), (string)$d]; }
    } else {
        for ($m = 1; $m <= 12; $m++) { $b[] = [sprintf('%02d', $m), date('M', mktime(0, 0, 0, $m, 1))]; }
    }
    return $b;
}

/**
 * Totals per bucket for one measure. $select is the SQL aggregate, $from the FROM/JOIN part
 * using the alias $x for the timestamp column $col.
 */
function dash_series(string $range, string $select, string $from, string $col, string $extraWhere = ''): array
{
    $keyExpr = [
        'day'   => "LPAD(HOUR($col), 2, '0')",
        'week'  => "DATE_FORMAT($col, '%Y-%m-%d')",
        'month' => "LPAD(DAY($col), 2, '0')",
        'year'  => "LPAD(MONTH($col), 2, '0')",
    ][$range];
    $where = [
        'day'   => "DATE($col) = CURDATE()",
        'week'  => "DATE($col) >= CURDATE() - INTERVAL 6 DAY",
        'month' => "YEAR($col) = YEAR(CURDATE()) AND MONTH($col) = MONTH(CURDATE())",
        'year'  => "YEAR($col) = YEAR(CURDATE())",
    ][$range];
    $sql = "SELECT $keyExpr AS k, $select AS v $from WHERE $where" . ($extraWhere !== '' ? " AND $extraWhere" : '') . " GROUP BY k";
    $found = [];
    foreach (db_fetch_all($sql) as $row) { $found[(string)$row['k']] = (float)$row['v']; }
    $out = [];
    foreach (dash_buckets($range) as [$key, $label]) {
        $out[] = ['label' => $label, 'value' => $found[$key] ?? 0.0];
    }
    return $out;
}

/** Simple responsive bar chart drawn in SVG (no external library). */
function dash_bar_chart(array $series, string $colour, string $prefix = ''): string
{
    $n = count($series);
    $max = max(1.0, max(array_column($series, 'value')));
    $w = 640; $h = 240; $padL = 8; $padB = 30; $padT = 14;
    $plotH = $h - $padB - $padT;
    $slot = ($w - $padL * 2) / max(1, $n);
    $barW = max(4, $slot * 0.62);
    $step = $n > 12 ? 2 : 1; // label every other bar on dense ranges
    $svg = '<svg class="dash-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" preserveAspectRatio="none">';
    // gridlines
    for ($g = 0; $g <= 4; $g++) {
        $y = $padT + $plotH - ($plotH * $g / 4);
        $svg .= '<line x1="' . $padL . '" x2="' . ($w - $padL) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" class="grid"/>';
    }
    foreach ($series as $i => $point) {
        $bh = $plotH * ($point['value'] / $max);
        $x = $padL + $i * $slot + ($slot - $barW) / 2;
        $y = $padT + $plotH - $bh;
        $label = $prefix . number_format($point['value'], $prefix ? 2 : 0);
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW, 1) . '" height="' . round(max($bh, 1), 1)
            . '" rx="3" fill="' . $colour . '"><title>' . e($point['label'] . ': ' . $label) . '</title></rect>';
        if ($i % $step === 0) {
            $svg .= '<text x="' . round($x + $barW / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="axis">' . e($point['label']) . '</text>';
        }
    }
    return $svg . '</svg>';
}

$range = (string)($_GET['range'] ?? 'week');
if (!isset(DASH_RANGES[$range])) { $range = 'week'; }
$_GET['range'] = $range; // keep the selector highlighting the period the charts show

// Sales = totals of orders that were not cancelled or failed.
$sales    = dash_series($range, 'SUM(c.total_amount)', 'FROM `order` o JOIN checkout c ON c.checkout_id = o.checkout_id', 'o.created_at',
                        "o.order_status NOT IN ('cancelled', 'failed')");
$orders   = dash_series($range, 'COUNT(*)', 'FROM `order` o', 'o.created_at');
$requests = dash_series($range, 'COUNT(*)', 'FROM quote q', 'q.created_at');
$sum = fn(array $s) => array_sum(array_column($s, 'value'));

$salesTotal = $sum($sales);
$orderTotal = (int)$sum($orders);
$requestTotal = (int)$sum($requests);

// Stat cards open the page they describe.
$stats = [
    ['Live products',  (int)(db_fetch_one("SELECT COUNT(*) AS n FROM product WHERE status = 'available' AND deleted_at IS NULL")['n'] ?? 0), 'mdi-cube-outline', 'products.php'],
    ['Pending quotes', (int)(db_fetch_one("SELECT COUNT(*) AS n FROM quote WHERE quote_status IN ('pending','reviewed')")['n'] ?? 0), 'mdi-file-document-outline', 'quotes.php?tab=pending'],
    ['Open orders',    (int)(db_fetch_one("SELECT COUNT(*) AS n FROM `order` WHERE order_status IN ('pending','paid','shipping')")['n'] ?? 0), 'mdi-cart-outline', 'orders.php'],
    ['Customers',      (int)(db_fetch_one("SELECT COUNT(*) AS n FROM `user` WHERE role = 'customer' AND account_status = 'active'")['n'] ?? 0), 'mdi-account-group-outline', 'users.php?tab=customers'],
];

$recentQuotes = db_fetch_all(
    "SELECT q.quote_id, q.service_type, q.quote_status, q.quantity, q.estimated_price, q.created_at, u.username
     FROM quote q
     JOIN customer c ON c.customer_id = q.customer_id
     JOIN `user` u ON u.user_id = c.user_id
     ORDER BY q.quote_id DESC
     LIMIT 5"
);

admin_header('Dashboard');
admin_page_head('Welcome back, ' . ($adminUser['username'] ?? 'admin'), 'A quick view of the 3D EDGE shop. Pick a period to update the charts.');
?>
<section class="stats-grid">
    <?php foreach ($stats as [$label, $value, $icon, $href]): ?>
        <a class="card stat-card stat-link" href="<?= e($href) ?>">
            <div class="stat-icon"><i class="mdi <?= e($icon) ?>"></i></div>
            <div class="flex-grow-1">
                <div class="stat-label"><?= e($label) ?></div>
                <div class="stat-number"><?= $value ?></div>
            </div>
            <i class="mdi mdi-chevron-right stat-go" aria-hidden="true"></i>
        </a>
    <?php endforeach; ?>
</section>

<div class="range-bar mt-4">
<?php
$rangeTabs = [];
foreach (DASH_RANGES as $k => $label) { $rangeTabs[$k] = [$label, null]; }
admin_tabs($rangeTabs, 'range');
?>
</div>

<section class="chart-grid">
    <article class="card chart-card">
        <div class="chart-head">
            <div><h3 class="section-title">Sales</h3><p class="chart-sub">R <?= number_format($salesTotal, 2) ?> · <?= e(strtolower(DASH_RANGES[$range])) ?></p></div>
            <a class="small" href="orders.php">Orders →</a>
        </div>
        <?= dash_bar_chart($sales, '#b7f32b', 'R ') ?>
    </article>
    <article class="card chart-card">
        <div class="chart-head">
            <div><h3 class="section-title">Orders</h3><p class="chart-sub"><?= $orderTotal ?> placed · <?= e(strtolower(DASH_RANGES[$range])) ?></p></div>
            <a class="small" href="orders.php">Orders →</a>
        </div>
        <?= dash_bar_chart($orders, '#1b221c') ?>
    </article>
    <article class="card chart-card">
        <div class="chart-head">
            <div><h3 class="section-title">Quote requests</h3><p class="chart-sub"><?= $requestTotal ?> received · <?= e(strtolower(DASH_RANGES[$range])) ?></p></div>
            <a class="small" href="quotes.php">Requests →</a>
        </div>
        <?= dash_bar_chart($requests, '#6f9e1c') ?>
    </article>
</section>

<div class="card table-card mt-4">
    <div class="card-header-plain d-flex justify-content-between align-items-center">
        <h3 class="section-title mb-0">Latest quote requests</h3>
        <a class="btn btn-sm btn-outline-secondary" href="quotes.php">View all</a>
    </div>
    <?php if (!$recentQuotes): ?>
        <p class="empty-cell mb-0">No quote requests yet.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table admin-table align-middle">
            <thead><tr><th>#</th><th>Customer</th><th>Service</th><th>Qty</th><th>Est. price</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recentQuotes as $q): ?>
                <tr>
                    <td data-label="#"><?= (int)$q['quote_id'] ?></td>
                    <td data-label="Customer"><?= e((string)$q['username']) ?></td>
                    <td data-label="Service"><?= e(admin_label((string)$q['service_type'])) ?></td>
                    <td data-label="Qty"><?= (int)$q['quantity'] ?></td>
                    <td data-label="Est. price"><?= $q['estimated_price'] !== null ? 'R ' . number_format((float)$q['estimated_price'], 2) : '—' ?></td>
                    <td data-label="Status"><?= admin_status((string)$q['quote_status']) ?></td>
                    <td data-label="Date"><?= e(date('d M Y', strtotime((string)$q['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php admin_footer(); ?>
