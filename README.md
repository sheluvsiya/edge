# 3D Edge multi-page checkout

## Files
- `config.php`: PDO connection and cart helpers.
- `personal.php`: contact information.
- `shipping.php`: South African shipping address.
- `payment.php`: payment method selection.
- `review.php`: final review and order creation.
- `success.php`: order confirmation.
- `template_top.php`, `template_bottom.php`: shared page layout and order summary.
- `checkout.css`, `checkout.js`: responsive design and small UI behaviours.

## Setup
1. Put this folder inside your existing site's web root, for example `htdocs/checkout/`.
2. Import your `3d_edge` database if you have not already done so.
3. Open `config.php` and set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`.
4. Ensure your existing login code sets `$_SESSION['user_id']` to the authenticated user's `user_id`. If your app uses a different session key, update `require_login()`.
5. Ensure your cart page uses `cart.customer_id` linked to the logged-in user's `customer_id`, and that cart items reference `product_id` or `material_id`.
6. Navigate to `checkout/personal.php`.

## Important database/payment notes
- This code expects the tables/columns from the supplied `3d_edge` SQL dump.
- It uses South African Rand and a sample shipping fee of R99, free at R1,500+. Change `cart_totals()` to match your actual delivery pricing.
- Your SQL schema defines `payment.payment_status` as ENUM('paid','refunded'), but pay-on-collection must not be marked paid before money is received. Before using `review.php`, run this migration:
  ```sql
  ALTER TABLE payment
    MODIFY payment_status ENUM('pending','paid','refunded') NOT NULL DEFAULT 'pending';
  ```
  Then in `review.php`, change the payment INSERT to set `payment_status` to `pending` explicitly:
  ```php
  $payment = $pdo->prepare("INSERT INTO payment (order_id,checkout_id,user_id,payment_method,amount,payment_status) VALUES (?,?,?,?,?,'pending')");
  $payment->execute([$orderId,$checkoutId,$userId,'pay_on_collection',$totals['total']]);
  ```
- Card processing is intentionally not implemented. Do not collect/store raw card numbers, expiration dates, or CVV in PHP/database. Integrate a PCI-compliant gateway (e.g. PayFast, Yoco, Peach Payments) before enabling card payments.
- The supplied schema's `checkout` table is a one-product-or-material summary. For multi-item carts, `order_item` correctly links all cart items, but the `checkout` row only records the first item in its product/material reference fields. Totals are calculated from all valid cart items.
- `cart_items()` skips unavailable products/materials and products where stock is lower than cart quantity. You may want to show a clear cart warning rather than silently skipping those items.
- Before production, add stock locking/decrementing, order confirmation emails, shipping rates from your courier, gateway webhooks, and proper order/cart status management.
