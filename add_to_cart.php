<?php
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode([
        'success'  => false,
        'message'  => 'Please log in to add items to your cart.',
        'redirect' => 'login.php'
    ]);
    exit;
}

$customer_id = (int)$_SESSION['auth_user']['customer_id'];
$product_id  = (int)($_POST['product_id'] ?? 0);
$quantity    = max(1, (int)($_POST['quantity'] ?? 1));

if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product.']);
    exit;
}

// --- Release any expired reservations first so stale holds don't block the user ---
release_expired_reservations($conn);

// --- Verify product ---
$product = db_one($conn,
    "SELECT product_id, quantity, status, made_to_order FROM product WHERE product_id = ?",
    [$product_id]
);

if (!$product || $product['status'] !== 'available') {
    echo json_encode(['success' => false, 'message' => 'Product is no longer available.']);
    exit;
}

$available_stock = (int)$product['quantity'];
$made_to_order   = (int)$product['made_to_order'] === 1; // printed when ordered: no stock, max per order instead
$no_stock_msg    = $made_to_order
    ? 'Max ' . MADE_TO_ORDER_MAX . ' per order. Need more? Request a quote.'
    : 'Not enough stock available.';

// --- Get or create the customer's cart ---
$cart = db_one($conn, "SELECT cart_id FROM cart WHERE customer_id = ? LIMIT 1", [$customer_id]);
if ($cart) {
    $cart_id = (int)$cart['cart_id'];
} else {
    $stmt = $conn->prepare("INSERT INTO cart (customer_id) VALUES (?)");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $cart_id = $stmt->insert_id;
}


$reservation_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
$now = date('Y-m-d H:i:s');

// --- Is the product already in the cart? ---
$existing = db_one($conn,
    "SELECT cart_item_id, quantity, reserved_until
       FROM cart_item
      WHERE cart_id = ? AND product_id = ?
      LIMIT 1",
    [$cart_id, $product_id]
);

// Made-to-order: "stock" = what's left of the limit after what's already reserved in this cart
if ($made_to_order) {
    $in_cart = ($existing && !empty($existing['reserved_until']) && strtotime($existing['reserved_until']) > time())
        ? (int)$existing['quantity'] : 0;
    $available_stock = max(0, MADE_TO_ORDER_MAX - $in_cart);
}

if ($existing) {
    $cart_item_id = (int)$existing['cart_item_id'];
    $current_qty  = (int)$existing['quantity'];
    $is_reserved  = !empty($existing['reserved_until'])
                 && strtotime($existing['reserved_until']) > time();

    if ($is_reserved) {
        // ==== Already reserved: add to the existing quantity and extend the timer ====
        $new_cart_qty = $current_qty + $quantity;

        // Cap at what stock allows
        if ($available_stock < $quantity) {
            if ($available_stock <= 0) {
                echo json_encode(['success' => false, 'message' => $no_stock_msg]);
                exit;
            }
            $new_cart_qty = $current_qty + $available_stock;
        }
        $additional_reserve = $new_cart_qty - $current_qty;

        // Deduct the extra from stock (not for made-to-order)
        if (!$made_to_order) {
            $stmt = $conn->prepare(
                "UPDATE product SET quantity = quantity - ? WHERE product_id = ?"
            );
            $stmt->bind_param("ii", $additional_reserve, $product_id);
            $stmt->execute();
        }

        // Update the cart row + refresh reservation
        $stmt = $conn->prepare(
            "UPDATE cart_item SET quantity = ?, reserved_until = ? WHERE cart_item_id = ?"
        );
        $stmt->bind_param("isi", $new_cart_qty, $reservation_expiry, $cart_item_id);
        $stmt->execute();

    } else {
        // ==== Expired: treat as a brand-new reservation — REPLACE the quantity ====
        $new_cart_qty = min($quantity, $available_stock);

        if ($new_cart_qty <= 0) {
            echo json_encode(['success' => false, 'message' => $no_stock_msg]);
            exit;
        }

        // Deduct the fresh quantity from stock (not for made-to-order)
        if (!$made_to_order) {
            $stmt = $conn->prepare(
                "UPDATE product SET quantity = quantity - ? WHERE product_id = ?"
            );
            $stmt->bind_param("ii", $new_cart_qty, $product_id);
            $stmt->execute();
        }

        // Reset the cart row to the new quantity and reactivate the reservation
        $stmt = $conn->prepare(
            "UPDATE cart_item SET quantity = ?, reserved_until = ? WHERE cart_item_id = ?"
        );
        $stmt->bind_param("isi", $new_cart_qty, $reservation_expiry, $cart_item_id);
        $stmt->execute();
    }

    // Refresh or create the cart_session row (both branches need this)
    $sess = db_one($conn,
        "SELECT cart_session_id FROM cart_session WHERE cart_item_id = ? LIMIT 1",
        [$cart_item_id]
    );
    if ($sess) {
        $stmt = $conn->prepare(
            "UPDATE cart_session SET cart_session_duration = ? WHERE cart_session_id = ?"
        );
        $stmt->bind_param("si", $reservation_expiry, $sess['cart_session_id']);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO cart_session (cart_item_id, cart_session_duration) VALUES (?, ?)"
        );
        $stmt->bind_param("is", $cart_item_id, $reservation_expiry);
        $stmt->execute();
    }

} else {
    // --- Brand-new item ---
    if ($available_stock < $quantity) {
        if ($available_stock <= 0) {
            echo json_encode(['success' => false, 'message' => $no_stock_msg]);
            exit;
        }
        $quantity = $available_stock; // cap at what's left
    }

    // Deduct from product stock (not for made-to-order)
    if (!$made_to_order) {
        $stmt = $conn->prepare(
            "UPDATE product SET quantity = quantity - ? WHERE product_id = ?"
        );
        $stmt->bind_param("ii", $quantity, $product_id);
        $stmt->execute();
    }

    // Insert cart_item
    $stmt = $conn->prepare(
        "INSERT INTO cart_item (cart_id, product_id, quantity, reserved_until)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("iiis", $cart_id, $product_id, $quantity, $reservation_expiry);
    $stmt->execute();
    $cart_item_id = $stmt->insert_id;

    // Create the timer row
    $stmt = $conn->prepare(
        "INSERT INTO cart_session (cart_item_id, cart_session_duration) VALUES (?, ?)"
    );
    $stmt->bind_param("is", $cart_item_id, $reservation_expiry);
    $stmt->execute();
}

// --- Return updated cart count ---
$cart_count = (int)db_value($conn,
    "SELECT COALESCE(SUM(quantity), 0) FROM cart_item WHERE cart_id = ?",
    [$cart_id]
);

echo json_encode([
    'success'        => true,
    'message'        => 'Added to cart.',
    'cart_count'     => $cart_count,
    'reserved_until' => $reservation_expiry
]);