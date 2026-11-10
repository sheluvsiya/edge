# 3D EDGE admin audit: notes

Nothing outside `admin/` was changed. This file lists what was changed in `admin/`, what could not be checked, and the few
**customer-side** changes the admin depends on (not applied, because you asked for the customer side to stay untouched).

## 1. Customer-side gaps that make an admin control have no effect

| # | Where | Problem | Effect today |
|---|-------|---------|--------------|
| 1 | `help.php` | The FAQ is five hard-coded `<details>` blocks. | Everything saved on **admin/faq.php** is invisible to customers. |
| 2 | `index.php` (latest reviews) | The query has no `status = 'approved'` filter. | A **pending or declined** review can still show on the home page. Product pages do filter correctly. |
| 3 | `index.php` (news), `gallery&review.php` | No `deleted_at IS NULL` filter. | If news or gallery items are soft-deleted in the admin, they would still show. (Check how your `news.php` / `gallery.php` delete.) |

### Patch 1: `help.php` (replace the five `<details>` blocks inside `<div class="faq-list">`)

```php
<?php
require_once __DIR__ . '/include/functions.php';
$faqs = db_fetch_all('SELECT question, answer FROM faq WHERE is_published = 1 AND deleted_at IS NULL ORDER BY sort_order, faq_id');
?>
<div class="faq-list">
    <?php foreach ($faqs as $faq): ?>
    <details>
        <summary><span class="faq-icon">?</span> <?= e($faq['question']) ?></summary>
        <p><?= nl2br(e($faq['answer'])) ?></p>
    </details>
    <?php endforeach; ?>
</div>
```
(Run `admin/sql/admin_update.sql` first. It seeds the table with today's five questions.)

### Patch 2: `index.php`, review query
Add `WHERE review.status = 'approved'` before `ORDER BY review.date_posted DESC`.

### Patch 3: `index.php` / `gallery&review.php`
Add `WHERE deleted_at IS NULL` to the news query and `WHERE deleted_at IS NULL` to the gallery query.

## 2. Changes made (all inside `admin/`)

| File | Change |
|------|--------|
| `_layout.php` | Pending counts computed once per request (was 6 queries per page, now 2). New shared helpers (`admin_money`, `admin_date`, `admin_chips`, `admin_pill`, `admin_clip`, `admin_ref`, `admin_write_attr`, `admin_delete_image`). Existing functions unchanged. |
| `products.php` | Table layout fixed: wider scrolling table, even cell spacing, Edit / Hide / Delete in one row that stays pinned on the right. **New control: Made to order** (the shop reads `product.made_to_order` but the admin had no way to set it). Discounted price must be lower than the price. Per-image **Remove** (was add-only). One column per field: summary, description, size, group code, made to order, 3D model, discounted price, created, updated. Low / out-of-stock tags use the shop's threshold. |
| `materials.php` | Per-image **Remove**. One column per field: description, density, colours as chips, products using it, created, updated. New **Customer sees** column (In stock / Out of stock, the same rule the shop applies). |
| `orders.php` | One column per field: customer, email, phone, items, shipping, fees, total, payment method, payment status, status, tracking. **Items window** per order. `checkout` and `payment` are now LEFT JOINs (an order without a checkout row used to vanish from the list). Reference shown as `3DE-0001`, as on the customer's profile. **Live status** button only appears if `admin/tracking.php` exists. |
| `reviews.php` | Tabs: Pending, Approved and **Declined** (declined reviews can be restored to Pending). Approve / Decline buttons sit in one Actions column that stays visible while the table scrolls. Customer photos are visible. |
| `faq.php` | No database error if the `faq` table is missing (shows a setup message). The Published / Hidden pill now sits in the same row as Edit, Hide and Delete. The "not on the website yet" banner was removed. |
| All five pages | Write buttons are **disabled** for view-only categories (they used to look clickable and then fail with a message). |
| `assets/css/3d-edge-admin.css` | Appended styles only (wide tables, chips, extra pill tones, review thumbnails). |
| `sql/admin_update.sql` | New. Idempotent. Adds `deleted_at` columns, the product fields the shop already reads, and the `faq` table (seeded with the five live questions). |

## 3. Not in the files provided: could not be audited

`_layout.php` links to these, but they were not in the zip: `index.php`, `quotes.php`, `news.php`, `gallery.php`,
`users.php`, `trash.php`, `profile.php`, `tracking.php`, `assets/js/admin.js`, `assets/js/misc.js`, `assets/css/style.css`,
`assets/vendors/*`, `assets/images/*`. The stylesheet also contains rules for pages and widgets none of the supplied pages use
(dashboard stats and charts, news and gallery grids, colour rows, filaments), which suggests a newer or fuller admin exists.
If these files are missing from the live site, those menu links are 404s.

`admin.js` must provide: the "cover" radio list for new uploads (`data-cover-list`), "Add another colour"
(`data-copy-product`), the theme toggle, the top-bar search, and the live-status refresh (`data-live-url`).

## 4. Decisions for you

- **Delivered status.** The customer database and profile page support `delivered`, but the admin dropdown stops at `shipping`
  (deliberate in the original code). Admins cannot mark an order delivered. Say if you want it added.
- **Live tracking.** No Bob Go integration exists in the customer code or `.env`. Provide `tracking.php` or the credentials if you want it.
- **`.env` is committed** to git (and `.gitignore` is empty). It holds database and SMTP settings. Rotate the SMTP app password and add `.env` to `.gitignore`.

## 5. Testing

PHP was not available where this was prepared, so **none of the code was run or linted**. Apply to a copy first and open each page.

## 6. Bob Go live status (added)

`admin/tracking.php` reads the shipment status from Bob Go (read-only; nothing is booked or changed). The Live status button on
**Orders** appears for any order that has a tracking number, and the window refreshes every 30 seconds while it is open.

Add to the site's `.env` (never commit it):

```
BOBGO_API_KEY=your-key
BOBGO_API_BASE=https://api.sandbox.bobgo.co.za
```

Use `https://api.bobgo.co.za` with a live key. A sandbox key only works with the sandbox address, and a live key only with the live one
(a mismatch gives HTTP 401). The tracking number saved on the order must be Bob Go's tracking reference, not the courier's waybill number.
