<?php
declare(strict_types=1);
/*
 * Live status for one order or quote request, from Bob Go. Admin only.
 * Called by the "Live status" window on admin/orders.php: tracking.php?order=12 or ?quote=5
 * Read-only: nothing is changed in the database.
 */
require_once __DIR__ . '/_layout.php';   // signs the admin in and checks the role
require_once __DIR__ . '/../include/bobgo_live.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

// 1. Which order or quote, and what is its tracking number?
$orderId = (int)($_GET['order'] ?? 0);
$quoteId = (int)($_GET['quote'] ?? 0);
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

bobgo_live_render($reference);
