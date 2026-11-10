<?php
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/vat.php';
require_once __DIR__ . '/include/quote_cart.php';

if (!isset($_SESSION['auth_user'])) {
    header('Location: login.php');
    exit;
}

$customer_id = (int)$_SESSION['auth_user']['customer_id'];

quote_cart_ensure($conn);

// --- Release any reservations that have expired since last visit ---
release_expired_reservations($conn);

// --- Get or create the cart ---
$cart = db_one($conn, "SELECT cart_id FROM cart WHERE customer_id = ? LIMIT 1", [$customer_id]);
if ($cart) {
    $cart_id = (int)$cart['cart_id'];
} else {
    $stmt = $conn->prepare("INSERT INTO cart (customer_id) VALUES (?)");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $cart_id = $stmt->insert_id;
}

// ==========================================
// HANDLE CART ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ----- Remove an item -----
    if (isset($_POST['remove_item_id'])) {
        $item = db_one($conn,
            "SELECT ci.cart_item_id, ci.product_id, ci.quantity, ci.reserved_until, COALESCE(p.made_to_order, 0) AS made_to_order
               FROM cart_item ci
               LEFT JOIN product p ON p.product_id = ci.product_id
              WHERE ci.cart_item_id = ? AND ci.cart_id = ?",
            [(int)$_POST['remove_item_id'], $cart_id]
        );

        if ($item) {
            $is_reserved = !empty($item['reserved_until'])
                        && strtotime($item['reserved_until']) > time();

            // Only give stock back if we were still holding it (made-to-order never took any)
            if ($is_reserved && !empty($item['product_id']) && !(int)$item['made_to_order']) {
                $stmt = $conn->prepare(
                    "UPDATE product SET quantity = quantity + ? WHERE product_id = ?"
                );
                $stmt->bind_param("ii", $item['quantity'], $item['product_id']);
                $stmt->execute();
            }

            $stmt = $conn->prepare(
                "DELETE FROM cart_item WHERE cart_item_id = ?"
            );
            $stmt->bind_param("i", $item['cart_item_id']);
            $stmt->execute();
        }
    }

    // ----- Update quantity -----
    if (isset($_POST['update_item_id']) && isset($_POST['quantity'])) {
        $newQty = max(1, (int)$_POST['quantity']);

        $item = db_one($conn,
            "SELECT ci.cart_item_id, ci.product_id, ci.quantity, ci.reserved_until, COALESCE(p.made_to_order, 0) AS made_to_order
               FROM cart_item ci
               LEFT JOIN product p ON p.product_id = ci.product_id
              WHERE ci.cart_item_id = ? AND ci.cart_id = ?",
            [(int)$_POST['update_item_id'], $cart_id]
        );

        if ($item && !empty($item['product_id'])) {
            $is_reserved = !empty($item['reserved_until'])
                        && strtotime($item['reserved_until']) > time();

            if ($is_reserved && (int)$item['made_to_order']) {
                // Made-to-order: no stock to reserve, just the per-order limit
                $newQty = min($newQty, MADE_TO_ORDER_MAX);

                $stmt = $conn->prepare(
                    "UPDATE cart_item SET quantity = ? WHERE cart_item_id = ?"
                );
                $stmt->bind_param("ii", $newQty, $item['cart_item_id']);
                $stmt->execute();
            } elseif ($is_reserved) {
                $diff = $newQty - (int)$item['quantity'];

                if ($diff > 0) {
                    // Need to reserve more stock
                    $stock = (int)db_value($conn,
                        "SELECT quantity FROM product WHERE product_id = ?",
                        [$item['product_id']]
                    );
                    if ($stock < $diff) {
                        $diff   = $stock;
                        $newQty = (int)$item['quantity'] + $stock;
                    }
                    if ($diff > 0) {
                        $stmt = $conn->prepare(
                            "UPDATE product SET quantity = quantity - ? WHERE product_id = ?"
                        );
                        $stmt->bind_param("ii", $diff, $item['product_id']);
                        $stmt->execute();
                    }
                } elseif ($diff < 0) {
                    // Give stock back
                    $restore = abs($diff);
                    $stmt = $conn->prepare(
                        "UPDATE product SET quantity = quantity + ? WHERE product_id = ?"
                    );
                    $stmt->bind_param("ii", $restore, $item['product_id']);
                    $stmt->execute();
                }

                $stmt = $conn->prepare(
                    "UPDATE cart_item SET quantity = ? WHERE cart_item_id = ?"
                );
                $stmt->bind_param("ii", $newQty, $item['cart_item_id']);
                $stmt->execute();
            }
            // Expired items: quantity changes are blocked (see the view below)
        }
    }

    header('Location: cart.php');
    exit;
}

// ==========================================
// FETCH CART ITEMS
// ==========================================
$cart_items = [];
$total = 0;

$query = "SELECT 
            ci.cart_item_id,
            ci.quantity,
            ci.reserved_until,
            ci.quote_id,
            q.quote_status,
            q.final_price AS q_final,
            q.estimated_price AS q_estimate,
            " . quote_cart_q($conn, 'color') . " AS q_colour,
            qm.material_type AS q_material,
            p.product_id,
            p.product_name,
            p.colour,
            p.size,
            p.made_to_order,
            p.price AS product_price,
            p.discounted_price,
            pi.img_url AS product_img,
            m.material_id,
            m.material_type,
            m.price_per_kg,
            mi.img_url AS material_img
          FROM cart_item ci
          LEFT JOIN quote q ON q.quote_id = ci.quote_id
          LEFT JOIN material qm ON qm.material_id = q.material_id
          LEFT JOIN product p ON ci.product_id = p.product_id
          LEFT JOIN product_img pi ON p.product_id = pi.product_id AND pi.cover_img = 1
          LEFT JOIN material m ON ci.material_id = m.material_id
          LEFT JOIN material_img mi ON m.material_id = mi.material_id AND mi.cover_img = 1
          WHERE ci.cart_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $cart_id);
$stmt->execute();
$result = $stmt->get_result();

$now = time();
$uploadBase = 'admin/uploads/';   // img_url in the DB is relative to this

while ($row = $result->fetch_assoc()) {
    // Display data (approved quote vs product vs material)
    if (!empty($row['quote_id'])) {
        $row['display_name']  = 'Custom print · Quote #QT-' . str_pad((string)$row['quote_id'], 4, '0', STR_PAD_LEFT);
        $row['options_text']  = implode(' · ', array_filter([$row['q_material'], $row['q_colour']]));
        $row['display_price'] = quote_cart_price(['final_price' => $row['q_final'], 'estimated_price' => $row['q_estimate']]);
        $row['display_image'] = 'icons/pen-tool.svg';
        $row['item_type']     = 'Approved quote';
        $row['product_link']  = 'myProfile.php?tab=orders&quote=' . (int)$row['quote_id'];
        $row['quantity']      = 1;
        // A quote line stays in the cart until it is paid for or removed (no stock is held, so no timer)
        if (!in_array($row['quote_status'], ['accepted', 'in_production'], true)) { continue; }
    } elseif (!empty($row['product_id'])) {
        $row['display_name']  = $row['product_name'];
        $row['options_text']  = implode(' · ', array_filter([$row['colour'], $row['size']])); // "Red · 1kg"
        $row['display_price'] = $row['discounted_price'] ?? $row['product_price'];
        $row['display_image'] = !empty($row['product_img'])
            ? $uploadBase . $row['product_img']
            : 'products/placeholder.png';
        $row['item_type']     = 'Product';
        $row['product_link']  = 'product.php?id=' . $row['product_id'];
    } else {
        $row['display_name']  = $row['material_type'] . ' (Material)';
        $row['display_price'] = $row['price_per_kg'];
        $row['display_image'] = !empty($row['material_img'])
            ? $uploadBase . $row['material_img']
            : 'materials/placeholder.png';
        $row['item_type']     = 'Material';
        $row['product_link']  = 'materials.php#material-' . $row['material_id'];
    }

    // Reservation status (quote lines never expire)
    $is_reserved = !empty($row['quote_id']) || (!empty($row['reserved_until'])
                && strtotime($row['reserved_until']) > $now);

    if ($is_reserved) {
        $row['reservation_status'] = 'reserved';
        $row['minutes_left'] = floor((strtotime($row['reserved_until']) - $now)/60);
    } else {
        $row['reservation_status'] = 'expired';
        $row['minutes_left'] = 0;
    }

    $row['subtotal'] = $row['display_price'] * $row['quantity'];
    $total += $row['subtotal'];
    $cart_items[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart - 3D Edge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/faq_gallery_css.css">
    <link rel="stylesheet" href="css/navbar_css.css">
    <link rel="stylesheet" href="css/footer_css.css">
    <link rel="stylesheet" href="css/cart_css.css">
</head>
<body>

    <?php require_once __DIR__ . '/include/navbar.php'; ?>

    <main class="cart-page">
        <section class="page-header">
            <h1>Your <span class="highlight-box">Cart</span></h1>
            <p>Items are reserved for 1 hour. Expired items release their stock.</p>
        </section>

        <?php if (empty($cart_items)): ?>
            <div class="empty-cart">
                <p>Your cart is currently empty.</p>
                <a href="products.php" class="cta-button">Browse Products &rarr;</a>
            </div>
        <?php else: ?>
            <div class="cart-layout">
                <div class="cart-items">
                    <?php foreach ($cart_items as $item): 
                        $expired = $item['reservation_status'] === 'expired';
                    ?>
                        <div class="cart-item <?= $expired ? 'item-expired' : '' ?>">

                            <a href="<?= e($item['product_link']) ?>" class="cart-item-image">
                                <img src="<?= e($item['display_image']) ?>" alt="<?= e($item['display_name']) ?>">
                            </a>

                            <div class="cart-item-details">
                                <span class="item-type-badge"><?= e($item['item_type']) ?></span>
                                <h3>
                                    <a href="<?= e($item['product_link']) ?>"><?= e($item['display_name']) ?></a>
                                </h3>
                                <?php if (!empty($item['options_text'])): ?>
                                    <p class="item-options"><?= e($item['options_text']) ?></p>
                                <?php endif; ?>
                                <p class="item-price">R <?= number_format($item['display_price'], 2) ?></p>

                                <?php if (!empty($item['quote_id'])): ?>
                                    <span class="status-badge reserved">✅ Approved by you · ready to pay</span>
                                <?php elseif ($item['reservation_status'] === 'reserved'): ?>
                                    <span class="status-badge reserved">
                                        ✅ Reserved for <?= (int)$item['minutes_left'] ?> more min<?= $item['minutes_left'] == 1 ? '' : 's' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge expired">
                                        ⚠️ <?= !empty($item['made_to_order']) ? 'Cart item expired' : 'Reservation expired — stock released' ?>
                                    </span>
                                    <p class="expired-note">
                                        <a href="<?= e($item['product_link']) ?>">Add it again from the product page &rarr;</a>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="cart-item-actions">
                                <?php if (!$expired && empty($item['quote_id'])): ?>
                                    <form action="cart.php" method="POST" class="qty-form">
                                        <input type="hidden" name="update_item_id" value="<?= (int)$item['cart_item_id'] ?>">
                                        <input type="number" name="quantity" value="<?= (int)$item['quantity'] ?>" min="1" <?= !empty($item['made_to_order']) ? 'max="' . MADE_TO_ORDER_MAX . '"' : '' ?> class="qty-input">
                                        <button type="submit" class="update-btn">Update</button>
                                    </form>
                                <?php endif; ?>

                                <form action="cart.php" method="POST" class="remove-form">
                                    <input type="hidden" name="remove_item_id" value="<?= (int)$item['cart_item_id'] ?>">
                                    <button type="submit" class="remove-btn">Remove</button>
                                </form>
                            </div>

                            <div class="cart-item-subtotal">
                                <p>R <?= number_format($item['subtotal'], 2) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="cart-summary">
                    <h2>Order Summary</h2>
                    <div class="summary-row">
                        <span>Subtotal</span>
                        <span>R <?= number_format($total, 2) ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Shipping</span>
                        <span>Calculated at checkout</span>
                    </div>
                    <div class="summary-row vat-row">
                        <span>Includes <?= htmlspecialchars(vat_label()) ?></span>
                        <span>R <?= number_format(vat_in((float)$total), 2) ?></span>
                    </div>
                    <div class="summary-row total-row">
                        <span>Total</span>
                        <span>R <?= number_format($total, 2) ?></span>
                    </div>
                    <a href="checkout_personal.php" class="checkout-btn">Proceed to Checkout &rarr;</a>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php require_once __DIR__ . '/include/footer.php'; ?>
    
</body>
</html>