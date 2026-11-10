<?php
declare(strict_types=1);
/*
 * Live status for one order, from Bob Go.
 *
 * Called by the "Live status" window on admin/orders.php:  tracking.php?order=12
 * Returns a small block of HTML (not a full page). Read-only: nothing is changed in the database.
 *
 * Setup (see AUDIT_NOTES.md): put these two lines in the site's .env file
 *   BOBGO_API_KEY=your-key
 *   BOBGO_API_BASE=https://api.sandbox.bobgo.co.za      (sandbox key)
 *   BOBGO_API_BASE=https://api.bobgo.co.za              (live key)
 * A sandbox key only works with the sandbox address, and a live key only with the live one.
 */
require_once __DIR__ . '/_layout.php';   // signs the admin in and checks the role, like every admin page

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

const BOBGO_ALLOWED_BASES = ['https://api.bobgo.co.za', 'https://api.sandbox.bobgo.co.za'];

/** Read a setting from the environment, falling back to the site's .env file. */
function bobgo_env(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = (string)($_ENV[$key] ?? $_SERVER[$key] ?? '');
    }
    if ($value === '') {
        $file = __DIR__ . '/../.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$name, $val] = explode('=', $line, 2);
                if (trim($name) === $key) {
                    $value = $val;
                    break;
                }
            }
        }
    }
    return trim((string)$value, " \t\n\r\0\x0B\"'");
}

/** One calm message in the Live status window. $tone is "text-muted" or "text-danger". */
function live_message(string $text, string $tone = 'text-muted'): void
{
    echo '<p class="' . e($tone) . ' mb-0">' . e($text) . '</p>';
    exit;
}

/** "2023-06-05 14:15:41+02:00" -> "5 Jun 2023, 14:15" in South African time. */
function live_time(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('j M Y, H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

/** Seconds since 1970 for sorting; 0 when the time cannot be read. */
function live_stamp(?string $value): int
{
    try {
        return (new DateTimeImmutable(trim((string)$value)))->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
}

// 1. Which order, and what is its tracking number?
$orderId = (int)($_GET['order'] ?? 0);
$quoteId = (int)($_GET['quote'] ?? 0);
// Quote requests keep their own tracking number (quote.tracking_num); orders use order.tracking_num.
if ($quoteId > 0) {
    $order = db_fetch_one('SELECT tracking_num FROM quote WHERE quote_id = ?', [$quoteId]);
    $noun = 'quote request';
} else {
    $order = $orderId > 0 ? db_fetch_one('SELECT tracking_num FROM `order` WHERE order_id = ?', [$orderId]) : null;
    $noun = 'order';
}
if (!$order) {
    live_message('That ' . $noun . ' could not be found.', 'text-danger');
}
$reference = trim((string)($order['tracking_num'] ?? ''));
if ($reference === '') {
    live_message('This ' . $noun . ' has no tracking number yet. Add the Bob Go tracking reference and save.');
}

// 2. Settings
$apiKey = bobgo_env('BOBGO_API_KEY');
$base = rtrim(bobgo_env('BOBGO_API_BASE') ?: 'https://api.bobgo.co.za', '/');
if ($apiKey === '') {
    live_message('Bob Go is not set up yet: BOBGO_API_KEY is missing from the .env file.', 'text-danger');
}
if (!in_array($base, BOBGO_ALLOWED_BASES, true)) {
    live_message('BOBGO_API_BASE in the .env file must be https://api.bobgo.co.za or https://api.sandbox.bobgo.co.za.', 'text-danger');
}

// 3. Ask Bob Go
$url = $base . '/v2/tracking?tracking_reference=' . rawurlencode($reference);
$headers = ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'];
$body = '';
$code = 0;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $result = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($result === false) {
        error_log('Bob Go tracking: ' . curl_error($ch));
        curl_close($ch);
        live_message('Could not reach Bob Go. Check the server\'s internet connection and try again.', 'text-danger');
    }
    curl_close($ch);
    $body = (string)$result;
} else {
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 15, 'ignore_errors' => true]]);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        live_message('Could not reach Bob Go. Check the server\'s internet connection and try again.', 'text-danger');
    }
    $body = (string)$result;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
}

// 4. Understand the answer
if ($code === 401 || $code === 403) {
    live_message('Bob Go did not accept the API key (HTTP ' . $code . '). A sandbox key only works with the sandbox address and a live key only with the live address. Check BOBGO_API_KEY and BOBGO_API_BASE in .env.', 'text-danger');
}
$data = json_decode($body, true);
if ($code === 429) {
    live_message('Bob Go says too many requests were made. Wait a minute and it will refresh again.');
}
if ($code >= 500 || $code === 404 || !is_array($data) || $data === []) {
    // Bob Go answers 500 for a reference it does not know.
    live_message('Bob Go has no shipment with the reference "' . $reference . '" yet. Check that the number is the Bob Go tracking reference (not the courier\'s own waybill number) and that it belongs to the same environment (sandbox or live) as the key.');
}
if ($code >= 400) {
    live_message('Bob Go returned an error (HTTP ' . $code . ').', 'text-danger');
}

// The answer is a list, even for one reference: use the first item.
$t = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;

$status      = trim((string)($t['status_friendly'] ?? '')) ?: trim((string)($t['status'] ?? '')) ?: 'Unknown';
$courier     = trim((string)($t['courier_name'] ?? ''));
$service     = trim((string)($t['service_level'] ?? ''));
$phone       = trim((string)($t['courier_phone'] ?? ''));
$lastSeen    = live_time($t['last_checkpoint_time'] ?? '');
$estFrom     = live_time($t['shipment_estimated_delivery_date_from'] ?? '');
$estTo       = live_time($t['shipment_estimated_delivery_date_to'] ?? '');
$checkpoints = is_array($t['checkpoints'] ?? null) ? $t['checkpoints'] : [];
// Newest first. Bob Go does not promise an order, so sort on the event time.
usort($checkpoints, static fn($a, $b) => live_stamp($b['time'] ?? '') <=> live_stamp($a['time'] ?? ''));
$milestones = [
    'collected_time'        => 'Collected',
    'in_transit_time'       => 'In transit',
    'out_for_delivery_time' => 'Out for delivery',
    'ready_for_pickup_time' => 'Ready for pickup',
    'delivered_time'        => 'Delivered',
];
$moves = is_array($t['shipment_movement_events'] ?? null) ? $t['shipment_movement_events'] : [];

// 5. Show it
echo '<p class="live-status-now"><span class="live-dot"></span>' . e($status) . '</p>';
$meta = array_filter([
    $courier !== '' ? $courier . ($service !== '' ? ' · ' . $service : '') : $service,
    'Reference ' . $reference,
    $phone !== '' ? 'Courier phone ' . $phone : '',
]);
echo '<p class="small text-muted mb-1">' . e(implode('  ·  ', $meta)) . '</p>';
if ($estFrom !== '' || $estTo !== '') {
    echo '<p class="small mb-1"><strong>Estimated delivery:</strong> ' . e($estFrom . ($estTo !== '' && $estTo !== $estFrom ? ' to ' . $estTo : '')) . '</p>';
}
if ($lastSeen !== '') {
    echo '<p class="small text-muted mb-3">Courier last reported: ' . e($lastSeen) . '</p>';
}

$reached = [];
foreach ($milestones as $key => $label) {
    if (trim((string)($moves[$key] ?? '')) !== '') {
        $reached[] = '<span class="status-pill me-1">' . e($label) . ' ' . e(live_time($moves[$key])) . '</span>';
    }
}
if ($reached) {
    echo '<div class="mb-3">' . implode(' ', $reached) . '</div>';
}

if (!$checkpoints) {
    echo '<p class="text-muted mb-0">The courier has not reported any movement yet.</p>';
} else {
    echo '<ul class="live-events">';
    foreach ($checkpoints as $c) {
        $when  = live_time($c['time'] ?? '');
        $label = trim((string)($c['status_friendly'] ?? '')) ?: trim((string)($c['status'] ?? ''));
        $where = trim((string)($c['location'] ?? ''));
        $note  = trim((string)($c['message'] ?? ''));
        echo '<li><strong>' . e($label !== '' ? $label : 'Update') . '</strong>'
            . ($when !== '' ? ' <span class="small text-muted">' . e($when) . '</span>' : '')
            . ($where !== '' ? '<div class="small">' . e($where) . '</div>' : '')
            . ($note !== '' ? '<div class="small text-muted">' . e($note) . '</div>' : '')
            . '</li>';
    }
    echo '</ul>';
}
