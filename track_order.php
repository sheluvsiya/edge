<?php
declare(strict_types=1);
/*
 * Customer live shipping status, loaded into myProfile.php.
 * Returns a small HTML fragment. Only the customer who owns the order or quote can see it.
 */
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/bobgo_live.php';

require_login();
$userId = (int)($_SESSION['auth_user']['user_id'] ?? 0);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

// Find this customer's id from their login. Every lookup below is limited to it.
$customer = db_fetch_one('SELECT customer_id FROM customer WHERE user_id = ? LIMIT 1', [$userId]);
if (!$customer) {
    live_message('That order could not be found.', 'text-danger');
}
$customerId = (int)$customer['customer_id'];

$orderId = (int)($_GET['order'] ?? 0);
$quoteId = (int)($_GET['quote'] ?? 0);

if ($quoteId > 0) {
    $row = db_fetch_one(
        'SELECT tracking_num FROM quote WHERE quote_id = ? AND customer_id = ? LIMIT 1',
        [$quoteId, $customerId]
    );
    $noun = 'quote request';
} elseif ($orderId > 0) {
    $row = db_fetch_one(
        'SELECT tracking_num FROM `order` WHERE order_id = ? AND customer_id = ? LIMIT 1',
        [$orderId, $customerId]
    );
    $noun = 'order';
} else {
    $row = null;
    $noun = 'order';
}
if (!$row) {
    live_message('That ' . $noun . ' could not be found.', 'text-danger');
}

$reference = trim((string)($row['tracking_num'] ?? ''));
if ($reference === '') {
    live_message('Not shipped yet. Live status appears once your ' . $noun . ' has a tracking number.');
}

bobgo_live_render($reference);
