<?php


require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/vat.php';
require_once __DIR__ . '/include/quote_cart.php';

require_login();
$sessionUser = auth_user();
$userId = (int)$sessionUser['user_id'];
$customerId = (int)($sessionUser['customer_id'] ?? 0);

// Which part of the page to show: overview, personal, addresses or security
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'personal', 'addresses', 'security', 'orders'], true)) {
    $tab = 'overview';
}

$formError = null;
$form = [];        // what the user typed, so the form keeps it after an error
$contactForm = []; // same idea for the email + phone form on the Security tab
$addressForm = []; // same idea for the address form

// Allowed choices for the address form. The keys are exactly what the database
// accepts (the enum values in the address table); the values are what the user sees.
$addressTypes = ['shipping' => 'Shipping', 'billing' => 'Billing'];
$provinces = [
    'gauteng' => 'Gauteng',
    'kwazulu-natal' => 'KwaZulu-Natal',
    'western-cape' => 'Western Cape',
    'eastern-cape' => 'Eastern Cape',
    'northern-cape' => 'Northern Cape',
    'free-state' => 'Free State',
    'limpopo' => 'Limpopo',
    'mpumalanga' => 'Mpumalanga',
    'north-west' => 'North West',
];
$buildingTypes = ['house' => 'House', 'apartment' => 'Apartment', 'office' => 'Office', 'hotel' => 'Hotel'];

// The most addresses one customer can keep.
const MAX_ADDRESSES = 5;

/** True when this customer already has the same address (case and extra spaces ignored). $ignoreId is the address being edited. */
function address_is_duplicate(array $a, int $customerId, int $ignoreId = 0): bool
{
    $norm = static fn($v): string => preg_replace('/\s+/', ' ', mb_strtolower(trim((string)$v)));
    $keys = ['address_type', 'address_line1', 'address_line2', 'suburb', 'city', 'province', 'postal_code', 'building_type', 'building_name', 'unit_num'];
    foreach (db_fetch_all('SELECT * FROM address WHERE customer_id = ? AND address_id <> ?', [$customerId, $ignoreId]) as $row) {
        $same = true;
        foreach ($keys as $k) {
            if ($norm($a[$k] ?? '') !== $norm($row[$k] ?? '')) { $same = false; break; }
        }
        if ($same) { return true; }
    }
    return false;
}

/* ---------- Saving the forms ----------
   This only runs when a form is submitted (POST).
   All forms post back to this same page; the hidden "action" field says which one it was. */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Stay on the tab the form came from, so any error shows next to the form
    if ($action === 'security' || $action === 'contact') {
        $tab = 'security';
    } elseif ($action === 'address_save' || $action === 'address_delete') {
        $tab = 'addresses';
    } elseif ($action === 'review_save' || $action === 'quote_decision' || $action === 'quote_pay') {
        $tab = 'orders';
    } else {
        $tab = 'personal';
    }

    // CSRF check: the hidden token must match the one in the session.
    // This stops another website from submitting the form on the user's behalf.
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $formError = 'Your session expired. Please refresh the page and try again.';

    // ----- Customer approves or rejects a quote the team has reviewed -----
    } elseif ($action === 'quote_decision') {
        $decQuoteId = (int)($_POST['quote_id'] ?? 0);
        $decision   = (string)($_POST['decision'] ?? '');
        $_GET['quote'] = (string)$decQuoteId; // stay on this quote
        if (!in_array($decision, ['approve', 'reject'], true)) {
            $formError = 'Please choose Approve or Reject.';
        } else {
            // Only this customer's quote, and only while it is waiting for their answer (status "reviewed").
            // The admin pages read the same quote_status, so "Approved" / "Declined" shows there straight away.
            $newStatus = $decision === 'approve' ? 'accepted' : 'declined';
            $stmt = db_execute(
                "UPDATE quote SET quote_status = ? WHERE quote_id = ? AND customer_id = ? AND quote_status = 'reviewed'",
                [$newStatus, $decQuoteId, $customerId]
            );
            if ($stmt->affected_rows > 0) {
                if ($decision === 'approve') {
                    // Approved: put it in the cart so the customer can pay straight away
                    $added = quote_cart_add($conn, $customerId, $decQuoteId);
                    if ($added['ok']) {
                        redirect('cart.php'); // straight to the cart, where the approved quote is waiting for payment
                    }
                    flash('success', 'Thank you! You approved this quote. ' . $added['message']);
                } else {
                    flash('success', 'You rejected this quote. If you change your mind, send us a new quote request.');
                }
                redirect('myProfile.php?tab=orders&quote=' . $decQuoteId);
            }
            $formError = 'This quote is no longer waiting for your answer.';
        }

    // ----- "Pay for this quote": (re)add an approved quote to the cart and go to it -----
    } elseif ($action === 'quote_pay') {
        $payQuoteId = (int)($_POST['quote_id'] ?? 0);
        $_GET['quote'] = (string)$payQuoteId;
        $added = quote_cart_add($conn, $customerId, $payQuoteId);
        if ($added['ok']) {
            redirect('cart.php');
        }
        $formError = $added['message'];

    // ----- Review of an item from a delivered order: stars + comment, and optionally photos -----
    } elseif ($action === 'review_save') {
        $revOrderId   = (int)($_POST['order_id'] ?? 0);
        $revProductId = (int)($_POST['product_id'] ?? 0);
        $rating       = (int)($_POST['rating'] ?? 0);
        $comment      = trim((string)($_POST['comment'] ?? ''));
        $_GET['order'] = (string)$revOrderId; // keep the order open if we show an error

        // The order must be this customer's, and delivered; the product must be in it
        $owned = db_fetch_one(
            "SELECT o.order_id FROM `order` o JOIN order_item oi ON oi.order_id = o.order_id
             WHERE o.order_id = ? AND o.customer_id = ? AND o.order_status = 'delivered' AND oi.product_id = ? LIMIT 1",
            [$revOrderId, $customerId, $revProductId]
        );
        $already = $owned ? db_fetch_one('SELECT review_id FROM review WHERE customer_id = ? AND order_id = ? AND product_id = ? AND deleted_at IS NULL LIMIT 1', [$customerId, $revOrderId, $revProductId]) : null;

        // Which of the (up to 3) photos were really chosen
        $photos = [];
        if (!empty($_FILES['review_images']['name']) && is_array($_FILES['review_images']['name'])) {
            foreach ($_FILES['review_images']['name'] as $i => $nm) {
                if ($nm === '' || (int)$_FILES['review_images']['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
                $photos[] = ['tmp' => $_FILES['review_images']['tmp_name'][$i], 'size' => (int)$_FILES['review_images']['size'][$i], 'err' => (int)$_FILES['review_images']['error'][$i]];
            }
        }
        $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        if (!$owned) {
            $formError = 'You can only review items from your delivered orders.';
        } elseif ($already) {
            $formError = 'You have already reviewed this item. Thank you!';
        } elseif ($rating < 1 || $rating > 5) {
            $formError = 'Please choose a star rating from 1 to 5.';
        } elseif ($comment === '') {
            $formError = 'Please write a short comment with your stars.';
        } elseif (mb_strlen($comment) > 1500) {
            $formError = 'Your comment can be at most 1500 characters.';
        } elseif (count($photos) > 3) {
            $formError = 'You can add up to 3 photos.';
        } else {
            // Check every photo before saving anything
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            foreach ($photos as $k => $ph) {
                $mime = ($ph['err'] === UPLOAD_ERR_OK && is_uploaded_file($ph['tmp'])) ? $finfo->file($ph['tmp']) : '';
                if ($ph['err'] !== UPLOAD_ERR_OK || $ph['size'] > 5 * 1024 * 1024 || !isset($allowedTypes[$mime]) || !@getimagesize($ph['tmp'])) {
                    $formError = 'Photos must be JPG, PNG or WEBP, up to 5 MB each.';
                    break;
                }
                $photos[$k]['ext'] = $allowedTypes[$mime];
            }
            if ($formError === null) {
                $dir = __DIR__ . '/admin/uploads/reviews/';
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                // Reviews wait for the admin to approve them before they show on the product page
                $stmt = db_execute(
                    "INSERT INTO review (customer_id, order_id, product_id, rating, comment, status) VALUES (?, ?, ?, ?, ?, 'pending')",
                    [$customerId, $revOrderId, $revProductId, $rating, $comment]
                );
                $reviewId = (int)$stmt->insert_id;
                foreach ($photos as $ph) {
                    $fileName = 'rv' . $reviewId . '_' . bin2hex(random_bytes(6)) . '.' . $ph['ext'];
                    if (move_uploaded_file($ph['tmp'], $dir . $fileName)) {
                        db_execute('INSERT INTO review_img (review_id, review_img_url) VALUES (?, ?)', [$reviewId, 'reviews/' . $fileName]);
                    }
                }
                flash('success', 'Thank you! Your review was sent and will appear once it has been approved.');
                redirect('myProfile.php?tab=orders&order=' . $revOrderId);
            }
        }

    // ----- Personal information form -----
    } elseif ($action === 'personal') {

        // 1. Read what the user typed and remove extra spaces
        //    (email and phone are NOT here any more: they are saved by the contact form on the Security tab)
        $form = [
            'first_name' => trim((string)($_POST['first_name'] ?? '')),
            'last_name' => trim((string)($_POST['last_name'] ?? '')),
            'username' => trim((string)($_POST['username'] ?? '')),
            'date_of_birth' => trim((string)($_POST['date_of_birth'] ?? '')),
        ];
        // Turn the date text (e.g. 2001-05-14) into a date PHP can check; false if it isn't a real date
        $dob = $form['date_of_birth'] !== '' ? DateTime::createFromFormat('Y-m-d', $form['date_of_birth']) : null;

        // 2. Check each field. The first problem found becomes the error message.
        if ($form['first_name'] === '' || $form['last_name'] === '' || $form['username'] === '') {
            $formError = 'Please fill in your name and username.';
        } elseif (strlen($form['first_name']) > 50 || strlen($form['last_name']) > 50) {
            $formError = 'First and last name can be at most 50 characters.';
        } elseif (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $form['username'])) {
            $formError = 'Username must be 3–50 characters and may contain letters, numbers and underscores.';
        } elseif ($form['date_of_birth'] !== '' && (!$dob || $dob > new DateTime())) {
            $formError = 'Please enter a valid date of birth.';
        } elseif (db_fetch_one('SELECT user_id FROM `user` WHERE username = ? AND user_id <> ? LIMIT 1', [$form['username'], $userId])) {
            // 3. Make sure no OTHER account already uses this username (user_id <> ? skips the user's own account)
            $formError = 'That username is already taken.';
        } else {
            // 4. Save. A transaction saves both tables together: if one update fails, neither is kept
            $db = db();
            $db->begin_transaction();
            try {
                // username lives in the user table; name and date of birth live in the customer table
                db_execute('UPDATE `user` SET username = ? WHERE user_id = ?', [$form['username'], $userId]);
                db_execute(
                    'UPDATE customer SET first_name = ?, last_name = ?, date_of_birth = ? WHERE user_id = ?',
                    [$form['first_name'], $form['last_name'], $dob ? $dob->format('Y-m-d 00:00:00') : null, $userId]
                );
                $db->commit();

                // flash() keeps the message for the next page load.
                // Redirecting after saving stops the form being sent again if the user refreshes.
                flash('success', 'Your details have been saved.');
                redirect('myProfile.php?tab=personal');
            } catch (Throwable $e) {
                // Something went wrong: undo both updates and log the real error for developers
                try { $db->rollback(); } catch (Throwable $ignored) {}
                error_log('[3D Edge Profile] ' . $e->getMessage());
                $formError = 'We could not save your details. Please try again.';
            }
        }

    // ----- Contact details form (email + phone, on the Security tab) -----
    } elseif ($action === 'contact') {

        // 1. Read what the user typed
        $contactForm = [
            'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
            'phone' => trim((string)($_POST['phone'] ?? '')),
        ];
        $current = (string)($_POST['contact_password'] ?? '');
        $account = db_fetch_one('SELECT email, password_hash FROM `user` WHERE user_id = ? LIMIT 1', [$userId]);

        // Is the user changing their email? (strcasecmp = compare, ignoring upper/lower case; 0 means the same)
        $emailChanged = !$account || strcasecmp($account['email'], $contactForm['email']) !== 0;

        // 2. Check each field.
        //    The current password is only needed when the EMAIL changes: the email is used to log in
        //    and reset the password, so this stops someone taking over the account just because the
        //    user left themselves logged in. Changing only the phone number doesn't need it.
        if ($contactForm['email'] === '') {
            $formError = 'Please enter your email address.';
        } elseif (!filter_var($contactForm['email'], FILTER_VALIDATE_EMAIL) || strlen($contactForm['email']) > 100) {
            $formError = 'Please enter a valid email address.';
        } elseif ($contactForm['phone'] !== '' && !preg_match('/^[0-9+ ()-]{7,20}$/', $contactForm['phone'])) {
            $formError = 'Please enter a valid phone number.';
        } elseif ($emailChanged && ($current === '' || !password_verify($current, $account['password_hash'] ?? ''))) {
            $formError = 'To change your email, please enter your current password correctly.';
        } elseif (db_fetch_one('SELECT user_id FROM `user` WHERE email = ? AND user_id <> ? LIMIT 1', [$contactForm['email'], $userId])) {
            // 3. No OTHER account may use this email
            $formError = 'That email address is already used by another account.';
        } else {
            // 4. Save. Phone is stored in both tables (the same as register.php does).
            $phone = $contactForm['phone'] !== '' ? $contactForm['phone'] : null;
            $db = db();
            $db->begin_transaction();
            try {
                db_execute('UPDATE `user` SET email = ?, phone_num = ? WHERE user_id = ?', [$contactForm['email'], $phone, $userId]);
                db_execute('UPDATE customer SET phone_num = ? WHERE user_id = ?', [$phone, $userId]);
                $db->commit();
                flash('success', 'Your contact details have been saved.');
                redirect('myProfile.php?tab=security');
            } catch (Throwable $e) {
                try { $db->rollback(); } catch (Throwable $ignored) {}
                error_log('[3D Edge Profile] ' . $e->getMessage());
                $formError = 'We could not save your contact details. Please try again.';
            }
        }

    // ----- Security form (change password) -----
    } elseif ($action === 'security') {

        // Passwords are not trimmed: spaces can be part of a password
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        // The database only stores a hash (scrambled version) of the password, never the password itself
        $account = db_fetch_one('SELECT password_hash FROM `user` WHERE user_id = ? LIMIT 1', [$userId]);

        // password_verify() checks a typed password against the stored hash.
        // The rules for a new password are the same as in register.php.
        if ($current === '' || $new === '' || $confirm === '') {
            $formError = 'Please fill in all three password fields.';
        } elseif (!$account || !password_verify($current, $account['password_hash'])) {
            $formError = 'Your current password is incorrect.';
        } elseif (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $formError = 'New password must be at least 8 characters and include uppercase, lowercase and a number.';
        } elseif ($new !== $confirm) {
            $formError = 'The new passwords do not match.';
        } elseif (password_verify($new, $account['password_hash'])) {
            $formError = 'Your new password must be different from your current one.';
        } else {
            // Save the hash of the new password
            db_execute('UPDATE `user` SET password_hash = ? WHERE user_id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId]);

            // Give the user a new session ID, so an old stolen session can't be reused
            session_regenerate_id(true);
            flash('success', 'Your password has been changed.');
            redirect('myProfile.php?tab=security');
        }

    // ----- Address form (add a new one or save changes to an existing one) -----
    } elseif ($action === 'address_save') {

        // address_id is 0 when adding a new address, or the id of the one being edited
        $addressId = (int)($_POST['address_id'] ?? 0);

        // 1. Read what the user typed
        $addressForm = [
            'address_id' => $addressId,
            'address_type' => (string)($_POST['address_type'] ?? ''),
            'address_line1' => trim((string)($_POST['address_line1'] ?? '')),
            'address_line2' => trim((string)($_POST['address_line2'] ?? '')),
            'suburb' => trim((string)($_POST['suburb'] ?? '')),
            'city' => trim((string)($_POST['city'] ?? '')),
            'province' => (string)($_POST['province'] ?? ''),
            'postal_code' => trim((string)($_POST['postal_code'] ?? '')),
            'building_type' => (string)($_POST['building_type'] ?? ''),
            'building_name' => trim((string)($_POST['building_name'] ?? '')),
            'unit_num' => trim((string)($_POST['unit_num'] ?? '')),
            'access_code' => trim((string)($_POST['access_code'] ?? '')),
        ];
        $a = $addressForm; // shorter name for the checks below

        // 2. Check each field. Dropdown values must be one of the allowed keys,
        //    because someone could edit the page and send a value we never offered.
        if ($customerId <= 0) {
            $formError = 'Only customer accounts can save addresses.';
        } elseif ($a['address_line1'] === '' || $a['city'] === '' || $a['postal_code'] === '') {
            $formError = 'Please fill in the street address, city and postal code.';
        } elseif (!isset($addressTypes[$a['address_type']]) || !isset($provinces[$a['province']]) || !isset($buildingTypes[$a['building_type']])) {
            $formError = 'Please choose an address type, province and building type.';
        } elseif (strlen($a['address_line1']) > 200 || strlen($a['address_line2']) > 200) {
            $formError = 'Address lines can be at most 200 characters.';
        } elseif (strlen($a['suburb']) > 100 || strlen($a['city']) > 100 || strlen($a['building_name']) > 50) {
            $formError = 'Suburb and city can be at most 100 characters, building name at most 50.';
        } elseif (!preg_match('/^[0-9]{4}$/', $a['postal_code'])) {
            $formError = 'Postal code must be 4 digits, e.g. 2196.';
        } elseif ($a['unit_num'] !== '' && !preg_match('/^[A-Za-z0-9 \/-]{1,10}$/', $a['unit_num'])) {
            // letters, digits, spaces, / and -  e.g. 12B or 4/1
            $formError = 'Unit number can be at most 10 letters or numbers, e.g. 12B.';
        } elseif ($a['access_code'] !== '' && !preg_match('/^[A-Za-z0-9 #*-]{1,20}$/', $a['access_code'])) {
            // letters, digits, spaces, # * and -  e.g. 0042 or #1234
            $formError = 'Access code can be at most 20 characters: letters, numbers, # or *.';
        } elseif ($addressId > 0 && !db_fetch_one('SELECT address_id FROM address WHERE address_id = ? AND customer_id = ?', [$addressId, $customerId])) {
            // Editing: the address must exist AND belong to this customer
            $formError = 'That address could not be found.';
        } elseif (address_is_duplicate($a, $customerId, $addressId)) {
            $formError = 'You have already saved this address.';
        } elseif ($addressId === 0 && (int)(db_fetch_one('SELECT COUNT(*) AS n FROM address WHERE customer_id = ?', [$customerId])['n'] ?? 0) >= MAX_ADDRESSES) {
            $formError = 'You can save up to ' . MAX_ADDRESSES . ' addresses. Please delete one before adding another.';
        } else {
            // 3. Turn empty optional fields into NULL (no value).
            //    Postal code, unit and access code are text columns (see sql/address_update.sql),
            //    so they are saved exactly as typed and 0181 keeps its zero.
            $values = [
                $a['address_type'],
                $a['province'],
                $a['city'],
                $a['postal_code'],
                $a['suburb'] !== '' ? $a['suburb'] : null,
                $a['address_line1'],
                $a['address_line2'] !== '' ? $a['address_line2'] : null,
                $a['building_type'],
                $a['building_name'] !== '' ? $a['building_name'] : null,
                $a['unit_num'] !== '' ? $a['unit_num'] : null,
                $a['access_code'] !== '' ? $a['access_code'] : null,
            ];

            // 4. Save. "AND customer_id = ?" makes sure a customer can only change THEIR OWN
            //    addresses, even if someone edits the hidden address_id in the page.
            if ($addressId > 0) {
                db_execute(
                    'UPDATE address SET address_type = ?, province = ?, city = ?, postal_code = ?, suburb = ?, address_line1 = ?,
                            address_line2 = ?, building_type = ?, building_name = ?, unit_num = ?, access_code = ?
                     WHERE address_id = ? AND customer_id = ?',
                    array_merge($values, [$addressId, $customerId])
                );
                flash('success', 'Your address has been updated.');
            } else {
                db_execute(
                    'INSERT INTO address (address_type, province, city, postal_code, suburb, address_line1,
                                          address_line2, building_type, building_name, unit_num, access_code, customer_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge($values, [$customerId])
                );
                flash('success', 'Your address has been added.');
            }
            redirect('myProfile.php?tab=addresses');
        }

    // ----- Delete an address -----
    } elseif ($action === 'address_delete') {
        // Same safety check: only delete it if it belongs to this customer.
        // affected_rows tells us how many rows were really deleted (0 = not theirs / already gone).
        $deleted = db_execute('DELETE FROM address WHERE address_id = ? AND customer_id = ?', [(int)($_POST['address_id'] ?? 0), $customerId])->affected_rows;

        if ($deleted > 0) {
            flash('success', 'The address has been removed.');
            redirect('myProfile.php?tab=addresses');
        }
        $formError = 'That address could not be found.';
    }
}

// Success message saved by flash() before the redirect (empty if there isn't one)
$success = flash('success');

/* ---------- Loading data for the page ---------- */

// Logged-in user's details (user table joined with their customer row)
$profile = db_fetch_one(
    'SELECT u.username, u.email, u.created_at, u.last_login, u.account_status, u.phone_num AS account_phone,
            c.first_name, c.last_name, c.phone_num AS customer_phone, c.date_of_birth
     FROM `user` u
     LEFT JOIN customer c ON c.user_id = u.user_id
     WHERE u.user_id = ? LIMIT 1',
    [$userId]
);

// The account was deleted while the user was logged in: log them out safely
if (!$profile) {
    $_SESSION = [];
    flash('error', 'Your account could not be found. Please sign in again.');
    redirect('login.php');
}

// Values shown in the personal information form.
// After an error $form already holds what the user typed; otherwise fill it from the database.
if (!$form) {
    $form = [
        'first_name' => $profile['first_name'] ?? '',
        'last_name' => $profile['last_name'] ?? '',
        'username' => $profile['username'],
        'date_of_birth' => $profile['date_of_birth'] ? date('Y-m-d', strtotime($profile['date_of_birth'])) : '',
    ];
}

// Values shown in the contact details form (Security tab), the same way
if (!$contactForm) {
    $contactForm = [
        'email' => $profile['email'],
        'phone' => $profile['customer_phone'] ?: ($profile['account_phone'] ?? ''),
    ];
}

// This customer's saved addresses (shipping first, then billing)
$addresses = db_fetch_all(
    'SELECT * FROM address WHERE customer_id = ? ORDER BY address_type ASC, address_id ASC',
    [$customerId]
);

// Address form: after an error it keeps what the user typed.
// Otherwise, clicking "Edit" on a card (?tab=addresses&edit=ID) fills it with that address,
// and without ?edit it starts empty for adding a new one.
if (!$addressForm) {
    $addressForm = [
        'address_id' => 0, 'address_type' => 'shipping', 'address_line1' => '', 'address_line2' => '',
        'suburb' => '', 'city' => '', 'province' => 'gauteng', 'postal_code' => '',
        'building_type' => 'house', 'building_name' => '', 'unit_num' => '', 'access_code' => '',
    ];
    $editId = (int)($_GET['edit'] ?? 0);
    foreach ($addresses as $address) {
        if ((int)$address['address_id'] === $editId) {
            // Copy the saved address into the form (empty database values become empty text)
            $addressForm = array_merge($addressForm, array_map('strval', array_map(fn($v) => $v ?? '', $address)));
        }
    }
}

// Name for the welcome heading; falls back to the username if there is no first name
$firstName = $profile['first_name'] ?: $profile['username'];
$fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: $profile['username'];

// Two letters for the avatar circle, e.g. Prinolan Pillay -> PP
$initials = strtoupper(substr($firstName, 0, 1) . substr($profile['last_name'] ?: $profile['username'], 0, 1));

// e.g. "Oct 2026"
$memberSince = date('M Y', strtotime($profile['created_at']));

/* ---------- Orders and quotes ----------
   Loaded once and used in two places:
   - the overview shows the 4 newest ("Latest activity")
   - the Orders & returns section shows all of them */

// ALL of this customer's orders. "WHERE o.customer_id = ?" = only their own.
$orders = db_fetch_all(
    'SELECT o.order_id, o.order_status, o.created_at, ch.total_amount
     FROM `order` o
     LEFT JOIN checkout ch ON ch.checkout_id = o.checkout_id
     WHERE o.customer_id = ?
     ORDER BY o.created_at DESC',
    [$customerId]
);

// ALL of this customer's quotes
$quotes = db_fetch_all(
    'SELECT q.quote_id, q.quote_status, q.service_type, q.created_at, q.estimated_price, q.final_price, m.material_type
     FROM quote q
     LEFT JOIN material m ON m.material_id = q.material_id
     WHERE q.customer_id = ?
     ORDER BY q.created_at DESC',
    [$customerId]
);

// Totals for the stat tiles (count() counts the rows we just loaded)
$orderCount = count($orders);
$quoteCount = count($quotes);

// Friendly names for the quote.service_type values stored in the database
$serviceNames = [
    'print_and_design' => 'Print & design',
    'print' => '3D printing',
    'design' => 'CAD design',
];

// Turns a database status like 'in_production' into 'In production' for the pill
function status_label(string $status): string
{
    return ucfirst(str_replace('_', ' ', $status));
}




// Orders and quotes come from different tables, so turn each one into the same shape
// (type, icon, title, detail, date, status, price, link). Then the HTML can show them all with one loop.
$allActivity = [];

foreach ($orders as $order) {
    $allActivity[] = [
        'type' => 'orders',
        'icon' => 'icons/box-lime.svg',
        'title' => 'Order #3DE-' . str_pad((string)$order['order_id'], 4, '0', STR_PAD_LEFT),
        'detail' => 'Product order',
        'date' => $order['created_at'],
        'status' => $order['order_status'],
        'price' => $order['total_amount'],
        'link' => 'myProfile.php?tab=orders&order=' . (int)$order['order_id'],
    ];
}

foreach ($quotes as $quote) {
    $allActivity[] = [
        'type' => 'quotes',
        'icon' => 'icons/pen-tool.svg',
        'title' => 'Quote #QT-' . str_pad((string)$quote['quote_id'], 4, '0', STR_PAD_LEFT),
        'detail' => ($serviceNames[$quote['service_type']] ?? 'Custom job') . ($quote['material_type'] ? ' · ' . $quote['material_type'] : ''),
        'date' => $quote['created_at'],
        'status' => $quote['quote_status'],
        'price' => $quote['final_price'] ?? $quote['estimated_price'],
        'link' => 'myProfile.php?tab=orders&quote=' . (int)$quote['quote_id'],
    ];
}

// Mix orders and quotes together, newest first
usort($allActivity, function ($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Overview: only the 4 newest
$activity = array_slice($allActivity, 0, 4);

/* ---------- Orders & returns section ----------
   Three possible views:
   ?tab=orders                -> the list (with ?show=orders or ?show=quotes to filter)
   ?tab=orders&quote=7        -> one quote
   ?tab=orders&order=3        -> one order */

// Filter buttons: All / Orders / Quotes
$show = $_GET['show'] ?? 'all';
if (!in_array($show, ['all', 'orders', 'quotes'], true)) {
    $show = 'all';
}

// The list after filtering. array_filter keeps only the rows where the function returns true.
$orderList = $allActivity;
if ($show !== 'all') {
    $orderList = array_filter($allActivity, function ($item) use ($show) {
        return $item['type'] === $show;
    });
}

// (int) turns anything that isn't a number into 0, so ?quote=abc can't break the query
$quoteId = (int)($_GET['quote'] ?? 0);
$orderId = (int)($_GET['order'] ?? 0);

$detailQuote = null;
$quoteImages = [];
$detailOrder = null;
$orderItems = [];
$myReviews = [];

if ($tab === 'orders' && $quoteId > 0) {
    // SECURITY: "AND q.customer_id = ?" means changing ?quote=7 to ?quote=8 in the address bar
    // only works if quote 8 is also yours. Otherwise this returns nothing -> "not found".
    $detailQuote = db_fetch_one(
        'SELECT q.*, m.material_type
         FROM quote q
         LEFT JOIN material m ON m.material_id = q.material_id
         WHERE q.quote_id = ? AND q.customer_id = ? LIMIT 1',
        [$quoteId, $customerId]
    );

    // The pictures / CAD files the customer uploaded with the quote
    if ($detailQuote) {
        $quoteImages = db_fetch_all('SELECT quote_img_url, quote_cad_url FROM quote_img WHERE quote_id = ?', [$quoteId]);
    }
}

if ($tab === 'orders' && $orderId > 0) {
    // Same security check for orders. Also joins checkout (money) and payment (how they paid).
    $detailOrder = db_fetch_one(
        'SELECT o.*, ch.total_amount, ch.fee_amount, ch.shipping_fee, p.payment_method, p.payment_status
         FROM `order` o
         LEFT JOIN checkout ch ON ch.checkout_id = o.checkout_id
         LEFT JOIN payment p ON p.payment_id = o.payment_id
         WHERE o.order_id = ? AND o.customer_id = ? LIMIT 1',
        [$orderId, $customerId]
    );

    // What was in the order. Each order_item is either a cart item (a product or material) or a quote.
    if ($detailOrder) {
        $orderItems = db_fetch_all(
            'SELECT oi.product_id, oi.quote_id, ci.quantity, pr.product_name, pr.price, pr.discounted_price, mat.material_type
             FROM order_item oi
             LEFT JOIN cart_item ci ON ci.cart_item_id = oi.cart_item_id
             LEFT JOIN product pr ON pr.product_id = ci.product_id
             LEFT JOIN material mat ON mat.material_id = ci.material_id
             WHERE oi.order_id = ?',
            [$orderId]
        );
        // Reviews this customer already wrote for items in this order: [product_id => review row]
        foreach (db_fetch_all('SELECT product_id, rating, comment, status FROM review WHERE customer_id = ? AND order_id = ? AND deleted_at IS NULL', [$customerId, $orderId]) as $rv) {
            $myReviews[(int)$rv['product_id']] = $rv;
        }
    }
}

?>



<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
 <link rel="stylesheet" href="css/home_css.css">
<link rel="stylesheet" href="css/myprofile.css">
  <title>myProfile - 3D Edge</title>
</head>
<body>


<section class = "myProfile">

  <div class = "myProfile-outer">

<!-- Account information-->

  <div class = "account-info">

   <!-- Back to the home page -->
   <a href = "index.php" class = "account-back"><img src = "icons/arrow-left-grey.svg" alt = ""><p>Back to home</p></a>

   <div class = "account-heading">
     <h3>Account</h3>
   </div> 
  

  <div class = "account-namingInfo">
  <a href = "myProfile.php" class = "<?= $tab === 'overview' ? 'active' : '' ?>"><img src = "icons/circle-user.svg" alt =""><p>Account overview</p></a>
  <a href = "myProfile.php?tab=personal" class = "<?= $tab === 'personal' ? 'active' : '' ?>"><img src = "icons/user-grey.svg" alt = ""><p>Personal information</p></a>
  <a href = "myProfile.php?tab=addresses" class = "<?= $tab === 'addresses' ? 'active' : '' ?>"><img src = "icons/map-pin-grey.svg" alt = ""><p>Addresses</p></a>
  <a href = "myProfile.php?tab=security" class = "<?= $tab === 'security' ? 'active' : '' ?>"><img src = "icons/lock.svg" alt = ""><p>Security</p></a>
  <a href = "myProfile.php?tab=orders" class = "<?= $tab === 'orders' ? 'active' : '' ?>"><img src = "icons/clipboard-list.svg" alt = "" ><p>Orders & returns</p></a>
  </div>

  <div class = "account-email">
  <h3>Need a hand?</h3>
  <p class="account-item"><img src="icons/mail.svg" alt=""> <a href="mailto:info@3dedge.co.za">info@3dedge.co.za</a></p>
  </div>

  <div class = "account-logout">
  <a href = "logout.php"><img src = "icons/log-out-grey.svg" alt = ""><p>Log out</p></a>
  </div>

  </div>



<!-- For customer dashboard-->

  <div class = "account-dashboard">

  <?php if ($tab === 'overview'): ?>

  <div class = "user-space">
  <p id = "user-space-eyebrow">Your space</p>
  <h2> Welcome back, <?= e($firstName) ?>.</h2>
  <p id = "user-space-details">Manage your details, security and 3DEdge orders from one piece</p>

  </div>

  <div class = "user-profile">

    <div class = "user-avatar"><?= e($initials) ?></div>

    <div class = "user-name">
      <p class = "user-fullname"><?= e($fullName) ?></p>
      <p class = "user-email"><?= e($profile['email']) ?></p>
    </div>

  </div>

  <div class = "user-cards">

  <a href = "myProfile.php?tab=personal">
    <img src = "icons/user-dark.svg" alt = "">
    <p>Personal information</p>
    <p>Keep your name, username and date of birth up to date</p>
    <img src = "icons/chevron-right-grey.svg" alt = "">
  </a>

   <a href = "myProfile.php?tab=security">
    <img src = "icons/shield-check-dark.svg" alt = "">
    <p>Security</p>
    <p>Manage your email, phone number and password</p>
    <img src = "icons/chevron-right-grey.svg" alt = "">
  </a>

  <a href = "myProfile.php?tab=orders">
    <img src = "icons/box-dark.svg" alt = "">
    <p>Orders & returns</p>
    <p>Track your latest order and view your history</p>
    <img src = "icons/chevron-right-grey.svg" alt = "">
  </a>


  </div>

  <div class = "recent-orders">

  <div class = "latest-activity">
    <p>Latest activity</p>
    <p>Recent orders &amp; quotes</p>

    <div class = "view-all-arrow">
     <a href = "myProfile.php?tab=orders"><p>View all</p><img src = "icons/arrow-right-light.svg" alt = ""></a>
    </div>

    <!-- Quick numbers -->
    <div class = "activity-stats">
      <div class = "activity-stat">
        <p class = "stat-number"><?= $quoteCount ?></p>
        <p class = "stat-label">Quotes</p>
      </div>
      <div class = "activity-stat">
        <p class = "stat-number"><?= $orderCount ?></p>
        <p class = "stat-label">Orders</p>
      </div>
      <div class = "activity-stat">
        <p class = "stat-number"><?= e($memberSince) ?></p>
        <p class = "stat-label">Member since</p>
      </div>
    </div>

    <?php if ($activity): ?>

      <?php foreach ($activity as $item): ?>
      <a href = "<?= e($item['link']) . ((($item['type'] ?? '') === 'orders' && $item['status'] === 'delivered') ? '#reviews' : '') ?>" class = "order-item">
        <img src = "<?= e($item['icon']) ?>" alt = "">
        <div class = "order-details">
          <p class = "order-title"><?= e($item['title']) ?></p>
          <p class = "order-meta"><?= e($item['detail']) ?> · <?= e(date('d M Y', strtotime($item['date']))) ?></p>
        </div>
        <span class = "order-status status-<?= e($item['status']) ?>"><?= e(status_label($item['status'])) ?></span>
        <?php if (($item['type'] ?? '') === 'orders' && $item['status'] === 'delivered'): ?><span class = "order-review-btn">&#9733; Review</span><?php endif; ?>
        <p class = "order-price"><?= $item['price'] !== null ? 'R' . number_format((float)$item['price'], 2) : 'TBC' ?></p>
        <img src = "icons/chevron-right-grey.svg" alt = "" class = "order-arrow">
      </a>
      <?php endforeach; ?>

    <?php else: ?>

      <!-- Shown when the customer has no orders or quotes yet -->
      <div class = "activity-empty">
        <img src = "icons/pen-tool.svg" alt = "">
        <p class = "order-title">Nothing here yet</p>
        <p class = "order-meta">Got a part in mind? Send us a quote request and we'll price it for you.</p>
        <a href = "request_quote.php" class = "request-btn">Request a quote <img src = "icons/arrow-right.svg" alt = ""></a>
      </div>

    <?php endif; ?>

  </div>

  </div>

  <?php elseif ($tab === 'personal'): ?>

  <!-- Personal information: edit name, username and date of birth (email + phone are on the Security tab) -->
  <div class = "user-space">
  <p id = "user-space-eyebrow">Personal information</p>
  <h2>Your details</h2>
  <p id = "user-space-details">Keep your name and details up to date. Your email and phone number are under <a href = "myProfile.php?tab=security">Security</a>.</p>
  </div>

  <?php if ($success): ?><p class = "form-alert form-alert-success"><?= e($success) ?></p><?php endif; ?>
  <?php if ($formError): ?><p class = "form-alert form-alert-error"><?= e($formError) ?></p><?php endif; ?>

  <form method = "post" action = "myProfile.php?tab=personal" class = "profile-form">
    <!-- csrf: proves the form was sent from this page; action: tells the PHP which form this is -->
    <input type = "hidden" name = "csrf" value = "<?= e(csrf_token()) ?>">
    <input type = "hidden" name = "action" value = "personal">

    <div class = "form-row">
      <div class = "form-field">
        <label for = "first_name">First name *</label>
        <input id = "first_name" name = "first_name" type = "text" maxlength = "50" value = "<?= e($form['first_name']) ?>" required>
      </div>
      <div class = "form-field">
        <label for = "last_name">Last name *</label>
        <input id = "last_name" name = "last_name" type = "text" maxlength = "50" value = "<?= e($form['last_name']) ?>" required>
      </div>
    </div>

    <div class = "form-row">
      <div class = "form-field">
        <label for = "username">Username *</label>
        <input id = "username" name = "username" type = "text" maxlength = "50" value = "<?= e($form['username']) ?>" required>
      </div>
      <div class = "form-field">
        <label for = "date_of_birth">Date of birth</label>
        <input id = "date_of_birth" name = "date_of_birth" type = "date" max = "<?= date('Y-m-d') ?>" value = "<?= e($form['date_of_birth']) ?>">
      </div>
    </div>

    <!-- Read-only account facts -->
    <div class = "form-row">
      <div class = "form-field">
        <p class = "form-label">Account status</p>
        <p class = "form-value"><?= e(ucfirst($profile['account_status'])) ?></p>
      </div>
      <div class = "form-field">
        <p class = "form-label">Member since</p>
        <p class = "form-value"><?= e(date('d M Y', strtotime($profile['created_at']))) ?></p>
      </div>
    </div>

    <button type = "submit" class = "form-btn">Save changes</button>
  </form>

  <?php elseif ($tab === 'addresses'): ?>

  <!-- Addresses: list of saved addresses + a form to add or edit one -->
  <div class = "user-space">
  <p id = "user-space-eyebrow">Addresses</p>
  <h2>Your addresses</h2>
  <p id = "user-space-details">Save where we should deliver your orders and where invoices should go.</p>
  </div>

  <?php if ($success): ?><p class = "form-alert form-alert-success"><?= e($success) ?></p><?php endif; ?>
  <?php if ($formError): ?><p class = "form-alert form-alert-error"><?= e($formError) ?></p><?php endif; ?>

  <!-- Saved addresses -->
  <?php if ($addresses): ?>
  <div class = "address-list">
    <?php foreach ($addresses as $address): ?>
    <div class = "address-card">
      <p class = "address-type"><?= e($addressTypes[$address['address_type']]) ?></p>
      <img src = "icons/map-pin.svg" alt = "" class = "address-pin">

      <!-- Only show the optional parts that were filled in -->
      <p class = "address-text">
        <?php if ($address['unit_num'] || $address['building_name']): ?>
          <?= $address['unit_num'] ? 'Unit ' . e((string)$address['unit_num']) . ' ' : '' ?><?= e((string)$address['building_name']) ?><br>
        <?php endif; ?>
        <?= e($address['address_line1']) ?><br>
        <?php if ($address['address_line2']): ?><?= e($address['address_line2']) ?><br><?php endif; ?>
        <?php if ($address['suburb']): ?><?= e($address['suburb']) ?>, <?php endif; ?><?= e($address['city']) ?><br>
        <?= e($provinces[$address['province']] ?? $address['province']) ?>, <?= e($address['postal_code']) ?>
      </p>

      <p class = "address-meta"><?= e($buildingTypes[$address['building_type']] ?? $address['building_type']) ?><?= $address['access_code'] ? ' · Access code ' . e((string)$address['access_code']) : '' ?></p>

      <div class = "address-actions">
        <a href = "myProfile.php?tab=addresses&amp;edit=<?= (int)$address['address_id'] ?>#address-form">Edit</a>

        <!-- Deleting changes data, so it is a small form (POST) with the CSRF token, not a plain link -->
        <form method = "post" action = "myProfile.php?tab=addresses">
          <input type = "hidden" name = "csrf" value = "<?= e(csrf_token()) ?>">
          <input type = "hidden" name = "action" value = "address_delete">
          <input type = "hidden" name = "address_id" value = "<?= (int)$address['address_id'] ?>">
          <button type = "submit" class = "address-delete">Delete</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class = "activity-empty">
    <img src = "icons/map-pin.svg" alt = "">
    <p class = "order-title">No saved addresses yet</p>
    <p class = "order-meta">Add a shipping address below so checkout is quicker next time.</p>
  </div>
  <?php endif; ?>

  <p class = "form-note"><?= count($addresses) ?> of <?= MAX_ADDRESSES ?> addresses saved.<?= count($addresses) >= MAX_ADDRESSES ? ' Delete one to add a new address.' : '' ?></p>

  <!-- Add / edit form. address_id 0 = add a new address, otherwise = edit that address -->
  <form method = "post" action = "myProfile.php?tab=addresses" class = "profile-form" id = "address-form">
    <input type = "hidden" name = "csrf" value = "<?= e(csrf_token()) ?>">
    <input type = "hidden" name = "action" value = "address_save">
    <input type = "hidden" name = "address_id" value = "<?= (int)$addressForm['address_id'] ?>">

    <p class = "form-title"><?= (int)$addressForm['address_id'] > 0 ? 'Edit address' : 'Add a new address' ?></p>

    <div class = "form-row">
      <div class = "form-field">
        <label for = "address_type">Address type *</label>
        <select id = "address_type" name = "address_type">
          <?php foreach ($addressTypes as $value => $label): ?>
          <option value = "<?= e($value) ?>" <?= $addressForm['address_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class = "form-field">
        <label for = "building_type">Building type *</label>
        <select id = "building_type" name = "building_type">
          <?php foreach ($buildingTypes as $value => $label): ?>
          <option value = "<?= e($value) ?>" <?= $addressForm['building_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class = "form-field">
      <label for = "address_line1">Street address *</label>
      <input id = "address_line1" name = "address_line1" type = "text" maxlength = "200" value = "<?= e($addressForm['address_line1']) ?>" placeholder = "e.g. 97 Edison Crescent" required>
    </div>

    <div class = "form-field">
      <label for = "address_line2">Address line 2</label>
      <input id = "address_line2" name = "address_line2" type = "text" maxlength = "200" value = "<?= e($addressForm['address_line2']) ?>" placeholder = "Optional">
    </div>

    <div class = "form-row">
      <div class = "form-field">
        <label for = "building_name">Building / complex name</label>
        <input id = "building_name" name = "building_name" type = "text" maxlength = "50" value = "<?= e($addressForm['building_name']) ?>">
      </div>
      <div class = "form-field">
        <label for = "unit_num">Unit number</label>
        <input id = "unit_num" name = "unit_num" type = "text" maxlength = "10" value = "<?= e($addressForm['unit_num']) ?>" placeholder = "e.g. 12B">
      </div>
    </div>

    <div class = "form-row">
      <div class = "form-field">
        <label for = "suburb">Suburb</label>
        <input id = "suburb" name = "suburb" type = "text" maxlength = "100" value = "<?= e($addressForm['suburb']) ?>">
      </div>
      <div class = "form-field">
        <label for = "city">City *</label>
        <input id = "city" name = "city" type = "text" maxlength = "100" value = "<?= e($addressForm['city']) ?>" required>
      </div>
    </div>

    <div class = "form-row">
      <div class = "form-field">
        <label for = "province">Province *</label>
        <select id = "province" name = "province">
          <?php foreach ($provinces as $value => $label): ?>
          <option value = "<?= e($value) ?>" <?= $addressForm['province'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class = "form-field">
        <label for = "postal_code">Postal code *</label>
        <input id = "postal_code" name = "postal_code" type = "text" inputmode = "numeric" maxlength = "4" value = "<?= e($addressForm['postal_code']) ?>" placeholder = "e.g. 2196" required>
      </div>
    </div>

    <div class = "form-field">
      <label for = "access_code">Gate / access code</label>
      <input id = "access_code" name = "access_code" type = "text" maxlength = "20" value = "<?= e($addressForm['access_code']) ?>" placeholder = "Optional, e.g. #1234">
    </div>

    <div class = "form-buttons">
      <button type = "submit" class = "form-btn"><?= (int)$addressForm['address_id'] > 0 ? 'Save address' : 'Add address' ?></button>
      <?php if ((int)$addressForm['address_id'] > 0): ?>
      <a href = "myProfile.php?tab=addresses" class = "form-cancel">Cancel</a>
      <?php endif; ?>
    </div>
  </form>

  <?php elseif ($tab === 'orders'): ?>

  <?php if ($success): ?><p class = "form-alert form-alert-success"><?= e($success) ?></p><?php endif; ?>
  <?php if ($formError): ?><p class = "form-alert form-alert-error"><?= e($formError) ?></p><?php endif; ?>

  <!-- ===== Orders & returns =====
       Shows ONE of three things: a single quote, a single order, or the full list -->

  <?php if ($quoteId > 0): ?>

    <!-- ----- One quote (?tab=orders&quote=ID) ----- -->
    <a href = "myProfile.php?tab=orders" class = "account-back"><img src = "icons/arrow-left-grey.svg" alt = ""><p>All orders &amp; quotes</p></a>

    <?php if (!$detailQuote): ?>
      <!-- Not found, or it belongs to someone else (we don't say which) -->
      <p class = "form-alert form-alert-error">Quote not found.</p>
    <?php else: ?>

      <div class = "user-space">
      <p id = "user-space-eyebrow">Quote</p>
      <h2>#QT-<?= e(str_pad((string)$detailQuote['quote_id'], 4, '0', STR_PAD_LEFT)) ?></h2>
      <p id = "user-space-details">Sent <?= e(date('d M Y', strtotime($detailQuote['created_at']))) ?></p>
      </div>

      <div class = "detail-box">

        <div class = "detail-top">
          <span class = "order-status status-<?= e($detailQuote['quote_status']) ?>"><?= e(status_label($detailQuote['quote_status'])) ?></span>
          <!-- final price once 3D Edge has priced it, otherwise the estimate -->
          <p class = "detail-price">
            <?php if ($detailQuote['final_price'] !== null): ?>
              R<?= number_format((float)$detailQuote['final_price'], 2) ?> <span>final price</span>
            <?php elseif ($detailQuote['estimated_price'] !== null): ?>
              R<?= number_format((float)$detailQuote['estimated_price'], 2) ?> <span>estimate</span>
            <?php else: ?>
              TBC
            <?php endif; ?>
          </p>
        </div>

        <!-- label / value pairs -->
        <div class = "detail-grid">
          <div><p class = "form-label">Service</p><p class = "form-value"><?= e($serviceNames[$detailQuote['service_type']] ?? 'Custom job') ?></p></div>
          <div><p class = "form-label">Material</p><p class = "form-value"><?= e($detailQuote['material_type'] ?? 'Not chosen') ?></p></div>
          <div><p class = "form-label">Colour</p><p class = "form-value"><?= e($detailQuote['color'] ?? 'Not chosen') ?></p></div>
          <div><p class = "form-label">Quantity</p><p class = "form-value"><?= (int)$detailQuote['quantity'] ?></p></div>
          <div><p class = "form-label">Size in mm (H × W × B)</p><p class = "form-value"><?= e(trim_number($detailQuote['height'])) ?> × <?= e(trim_number($detailQuote['width'])) ?> × <?= e(trim_number($detailQuote['breadth'])) ?></p></div>
          <div><p class = "form-label">Ready by</p><p class = "form-value"><?= $detailQuote['completion_date'] ? e(date('d M Y', strtotime($detailQuote['completion_date']))) : 'To be confirmed' ?></p></div>
          <div><p class = "form-label">Tracking number</p><p class = "form-value"><?= !empty($detailQuote['tracking_num']) ? e((string)$detailQuote['tracking_num']) : 'Not shipped yet' ?></p></div>
        </div>

        <?php if ($detailQuote['quote_description']): ?>
        <div>
          <p class = "form-label">Your description</p>
          <p class = "detail-text"><?= nl2br(e($detailQuote['quote_description'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($detailQuote['quote_status'] === 'reviewed'): ?>
        <!-- The team has priced this quote: the customer decides -->
        <?php
          $decPrice = quote_cart_price($detailQuote);
          $decQid   = (int)$detailQuote['quote_id'];
        ?>
        <section class = "quote-decision" aria-labelledby = "qd-title">
          <div class = "qd-head">
            <span class = "qd-badge">Your answer needed</span>
            <h3 id = "qd-title">Your quote is ready</h3>
            <p>We have reviewed your request and priced the job. Approve it to go ahead, or reject it if it is not what you need.</p>
          </div>

          <?php if ($decPrice > 0): ?>
          <div class = "qd-price">
            <div>
              <span class = "qd-price-label">Quote total</span>
              <strong>R<?= number_format($decPrice, 2) ?></strong>
            </div>
            <span class = "qd-price-vat">Includes <?= e(vat_label()) ?> (R<?= number_format(vat_in($decPrice), 2) ?>)</span>
          </div>
          <?php endif; ?>

          <form method = "post" action = "myProfile.php?tab=orders&quote=<?= $decQid ?>" class = "quote-decision-form">
            <input type = "hidden" name = "csrf" value = "<?= e(csrf_token()) ?>">
            <input type = "hidden" name = "action" value = "quote_decision">
            <input type = "hidden" name = "quote_id" value = "<?= $decQid ?>">
            <div class = "qd-actions" data-qd-main>
              <button type = "submit" name = "decision" value = "approve" class = "form-btn qd-approve">Approve quote</button>
              <button type = "button" class = "quote-reject-btn" data-qd-ask>Reject quote</button>
            </div>
            <div class = "qd-confirm" data-qd-confirm hidden>
              <p>Reject this quote? We will let the team know you do not want to go ahead.</p>
              <div class = "qd-actions">
                <button type = "submit" name = "decision" value = "reject" class = "quote-reject-btn qd-reject-final">Yes, reject</button>
                <button type = "button" class = "qd-cancel" data-qd-cancel>Keep quote</button>
              </div>
            </div>
          </form>
          <p class = "qd-note">Approving adds the quote to your cart so you can pay securely at checkout.</p>
        </section>
        <?php elseif (in_array($detailQuote['quote_status'], ['accepted', 'in_production'], true)): ?>
          <?php
            $quoteOrdered = db_fetch_one("SELECT o.order_status FROM order_item oi JOIN `order` o ON o.order_id = oi.order_id
                                           WHERE oi.quote_id = ? AND o.order_status IN ('pending','paid','shipping','delivered') LIMIT 1", [(int)$detailQuote['quote_id']]);
            $quotePayable = quote_cart_price($detailQuote) > 0;
          ?>
          <?php if ($quoteOrdered): ?>
            <p class = "form-note">You approved this quote and an order has been placed for it (<?= e(status_label((string)$quoteOrdered['order_status'])) ?>). Find it under Orders.</p>
          <?php elseif ($quotePayable): ?>
            <section class = "quote-decision qd-approved">
              <div class = "qd-head">
                <span class = "qd-badge qd-badge-ok">Approved</span>
                <h3>Ready to pay</h3>
                <p>Add this quote to your cart and pay for it at checkout.</p>
              </div>
              <div class = "qd-price">
                <div><span class = "qd-price-label">Quote total</span><strong>R<?= number_format(quote_cart_price($detailQuote), 2) ?></strong></div>
                <span class = "qd-price-vat">Includes <?= e(vat_label()) ?> (R<?= number_format(vat_in(quote_cart_price($detailQuote)), 2) ?>)</span>
              </div>
              <form method = "post" action = "myProfile.php?tab=orders&quote=<?= (int)$detailQuote['quote_id'] ?>" class = "quote-decision-form">
                <input type = "hidden" name = "csrf" value = "<?= e(csrf_token()) ?>">
                <input type = "hidden" name = "action" value = "quote_pay">
                <input type = "hidden" name = "quote_id" value = "<?= (int)$detailQuote['quote_id'] ?>">
                <div class = "qd-actions"><button type = "submit" class = "form-btn qd-approve">Add to cart and pay</button></div>
              </form>
            </section>
          <?php else: ?>
            <p class = "form-note">You approved this quote. We will be in touch with the payment details.</p>
          <?php endif; ?>
        <?php elseif ($detailQuote['quote_status'] === 'declined'): ?>
          <p class = "form-note">This quote was rejected.</p>
        <?php endif; ?>

        <!-- Uploaded pictures and CAD files -->
        <?php if ($quoteImages): ?>
        <div>
          <p class = "form-label">Your files</p>
          <div class = "detail-images">
            <?php foreach ($quoteImages as $img): ?>
              <?php if ($img['quote_img_url']): ?>
                <a href = "<?= e($img['quote_img_url']) ?>" target = "_blank"><img src = "<?= e($img['quote_img_url']) ?>" alt = "Uploaded picture"></a>
              <?php endif; ?>
              <?php if ($img['quote_cad_url']): ?>
                <a href = "<?= e($img['quote_cad_url']) ?>" class = "detail-file" target = "_blank">Download CAD file</a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($detailQuote['tracking_num'])): ?>
        <div class = "live-ship" data-live-url = "track_order.php?quote=<?= (int)$detailQuote['quote_id'] ?>">
          <div class = "live-ship-head"><p class = "form-label">Live shipping status</p><button type = "button" class = "live-refresh">Refresh</button></div>
          <div class = "live-body" aria-live = "polite"><p class = "live-msg">Checking with the courier…</p></div>
        </div>
        <?php endif; ?>

      </div>

      <p class = "form-note">Questions about this quote? Email <a href = "mailto:info@3dedge.co.za">info@3dedge.co.za</a> and mention #QT-<?= e(str_pad((string)$detailQuote['quote_id'], 4, '0', STR_PAD_LEFT)) ?>.</p>

    <?php endif; ?>

  <?php elseif ($orderId > 0): ?>

    <!-- ----- One order (?tab=orders&order=ID) ----- -->
    <a href = "myProfile.php?tab=orders" class = "account-back"><img src = "icons/arrow-left-grey.svg" alt = ""><p>All orders &amp; quotes</p></a>

    <?php if (!$detailOrder): ?>
      <p class = "form-alert form-alert-error">Order not found.</p>
    <?php else: ?>

      <div class = "user-space">
      <p id = "user-space-eyebrow">Order</p>
      <h2>#3DE-<?= e(str_pad((string)$detailOrder['order_id'], 4, '0', STR_PAD_LEFT)) ?></h2>
      <p id = "user-space-details">Placed <?= e(date('d M Y', strtotime($detailOrder['created_at']))) ?></p>
      </div>

      <div class = "detail-box">

        <div class = "detail-top">
          <span class = "order-status status-<?= e($detailOrder['order_status']) ?>"><?= e(status_label($detailOrder['order_status'])) ?></span>
          <p class = "detail-price">R<?= number_format((float)$detailOrder['total_amount'], 2) ?> <span>total</span></p>
        </div>

        <!-- What was ordered -->
        <div id = "reviews">
          <p class = "form-label">Items</p>
          <?php foreach ($orderItems as $line): ?>
            <p class = "detail-line">
              <?php if ($line['quote_id']): ?>
                Custom print · Quote #QT-<?= e(str_pad((string)$line['quote_id'], 4, '0', STR_PAD_LEFT)) ?>
              <?php else: ?>
                <?= e($line['product_name'] ?? $line['material_type'] ?? 'Item') ?> × <?= (int)($line['quantity'] ?? 1) ?>
              <?php endif; ?>
            </p>

            <?php
              // Reviews are only for delivered products (not custom prints / quotes)
              $revPid = (int)($line['product_id'] ?? 0);
              if ($detailOrder['order_status'] === 'delivered' && $revPid > 0 && !$line['quote_id']):
                $mine = $myReviews[$revPid] ?? null;
            ?>
              <?php if ($mine): ?>
                <div class = "review-done">
                  <span class = "review-stars-static" aria-label = "<?= (int)$mine['rating'] ?> out of 5 stars"><?= str_repeat('★', (int)$mine['rating']) . str_repeat('☆', 5 - (int)$mine['rating']) ?></span>
                  <span>Your review<?= $mine['status'] === 'pending' ? ' is waiting for approval' : ($mine['status'] === 'rejected' ? ' was not approved' : ' is live') ?>.</span>
                </div>
              <?php else: ?>
                <details class = "review-box" <?= ($formError && ($action ?? '') === 'review_save' && (int)($_POST['product_id'] ?? 0) === $revPid) ? 'open' : '' ?>>
                  <summary>★ Review this item</summary>
                  <form method = "post" enctype = "multipart/form-data" class = "review-form" action = "myProfile.php?tab=orders&order=<?= (int)$detailOrder['order_id'] ?>">
                    <input type = "hidden" name = "csrf" value = "<?= e(csrf_token()) ?>">
                    <input type = "hidden" name = "action" value = "review_save">
                    <input type = "hidden" name = "order_id" value = "<?= (int)$detailOrder['order_id'] ?>">
                    <input type = "hidden" name = "product_id" value = "<?= $revPid ?>">

                    <p class = "form-label">Your rating</p>
                    <div class = "star-input" role = "radiogroup" aria-label = "Star rating">
                      <?php for ($st5 = 5; $st5 >= 1; $st5--): ?>
                        <input type = "radio" id = "star-<?= $revPid ?>-<?= $st5 ?>" name = "rating" value = "<?= $st5 ?>" required>
                        <label for = "star-<?= $revPid ?>-<?= $st5 ?>" title = "<?= $st5 ?> star<?= $st5 > 1 ? 's' : '' ?>">★</label>
                      <?php endfor; ?>
                    </div>

                    <label class = "form-label" for = "rv-comment-<?= $revPid ?>">Your comment</label>
                    <textarea id = "rv-comment-<?= $revPid ?>" name = "comment" rows = "4" maxlength = "1500" required placeholder = "How was the print quality, fit and finish?"></textarea>

                    <label class = "form-label" for = "rv-photos-<?= $revPid ?>">Add photos <span class = "review-optional">(optional, up to 3)</span></label>
                    <input type = "file" id = "rv-photos-<?= $revPid ?>" name = "review_images[]" accept = ".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple data-max = "3">

                    <button type = "submit" class = "form-btn">Submit review</button>
                  </form>
                </details>
              <?php endif; ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <div class = "detail-grid">
          <div><p class = "form-label">Shipping</p><p class = "form-value">R<?= number_format((float)$detailOrder['shipping_fee'], 2) ?></p></div>
          <div><p class = "form-label">Includes <?= e(vat_label()) ?></p><p class = "form-value">R<?= number_format(vat_in((float)$detailOrder['total_amount']), 2) ?></p></div>
          <div><p class = "form-label">Fees</p><p class = "form-value">R<?= number_format((float)$detailOrder['fee_amount'], 2) ?></p></div>
          <div><p class = "form-label">Payment</p><p class = "form-value"><?= $detailOrder['payment_method'] ? e(status_label($detailOrder['payment_method'])) . ' · ' . e(status_label($detailOrder['payment_status'])) : 'Not paid yet' ?></p></div>
          <div><p class = "form-label">Tracking number</p><p class = "form-value"><?= $detailOrder['tracking_num'] ? e((string)$detailOrder['tracking_num']) : 'Not shipped yet' ?></p></div>
        </div>

        <?php
          $st = (string)$detailOrder['order_status'];
          $steps = ['Order placed', 'Paid', 'Shipped', 'Delivered'];
          $reached = ['pending' => 0, 'paid' => 1, 'shipping' => 2, 'delivered' => 3][$st] ?? -1;
          $hasTrack = trim((string)($detailOrder['tracking_num'] ?? '')) !== '';
        ?>
        <?php if ($reached >= 0): ?>
        <ol class = "order-progress" aria-label = "Order progress">
          <?php foreach ($steps as $i => $label): ?>
            <li class = "<?= $i < $reached ? 'done' : ($i === $reached ? 'current' : '') ?>"><span><?= $i < $reached ? '✓' : $i + 1 ?></span><p><?= e($label) ?></p></li>
          <?php endforeach; ?>
        </ol>
        <?php else: ?>
          <p class = "form-note">This order is <?= e(strtolower(status_label($st))) ?>.</p>
        <?php endif; ?>

        <?php if ($reached >= 1): ?>
        <div class = "live-ship" data-live-url = "track_order.php?order=<?= (int)$detailOrder['order_id'] ?>">
          <div class = "live-ship-head"><p class = "form-label">Live shipping status</p><?php if ($hasTrack): ?><button type = "button" class = "live-refresh">Refresh</button><?php endif; ?></div>
          <div class = "live-body" aria-live = "polite"><p class = "live-msg"><?= $hasTrack ? 'Checking with the courier…' : 'Your parcel has not been shipped yet. A tracking number will appear here as soon as it is on its way.' ?></p></div>
        </div>
        <?php endif; ?>

      </div>

      <!-- No returns system yet: point the customer to email (see the Orders & returns notes) -->
      <p class = "form-note">Problem with this order? Email <a href = "mailto:info@3dedge.co.za">info@3dedge.co.za</a> and mention order #3DE-<?= e(str_pad((string)$detailOrder['order_id'], 4, '0', STR_PAD_LEFT)) ?>.</p>

    <?php endif; ?>

  <?php else: ?>

    <!-- ----- The full list (?tab=orders) ----- -->
    <div class = "user-space">
    <p id = "user-space-eyebrow">Orders &amp; returns</p>
    <h2>Your orders &amp; quotes</h2>
    <p id = "user-space-details">Track your quotes from request to finished part, and see every order you've placed.</p>
    </div>

    <!-- Filter buttons: each is a link that adds ?show=... -->
    <div class = "order-filters">
      <a href = "myProfile.php?tab=orders" class = "<?= $show === 'all' ? 'active' : '' ?>">All (<?= count($allActivity) ?>)</a>
      <a href = "myProfile.php?tab=orders&amp;show=orders" class = "<?= $show === 'orders' ? 'active' : '' ?>">Orders (<?= $orderCount ?>)</a>
      <a href = "myProfile.php?tab=orders&amp;show=quotes" class = "<?= $show === 'quotes' ? 'active' : '' ?>">Quotes (<?= $quoteCount ?>)</a>
    </div>

    <div class = "recent-orders">
      <?php if ($orderList): ?>

        <!-- Same row design as "Latest activity" on the overview -->
        <?php foreach ($orderList as $item): ?>
        <a href = "<?= e($item['link']) . ((($item['type'] ?? '') === 'orders' && $item['status'] === 'delivered') ? '#reviews' : '') ?>" class = "order-item">
          <img src = "<?= e($item['icon']) ?>" alt = "">
          <div class = "order-details">
            <p class = "order-title"><?= e($item['title']) ?></p>
            <p class = "order-meta"><?= e($item['detail']) ?> · <?= e(date('d M Y', strtotime($item['date']))) ?></p>
          </div>
          <span class = "order-status status-<?= e($item['status']) ?>"><?= e(status_label($item['status'])) ?></span>
        <?php if (($item['type'] ?? '') === 'orders' && $item['status'] === 'delivered'): ?><span class = "order-review-btn">&#9733; Review</span><?php endif; ?>
          <p class = "order-price"><?= $item['price'] !== null ? 'R' . number_format((float)$item['price'], 2) : 'TBC' ?></p>
          <img src = "icons/chevron-right-grey.svg" alt = "" class = "order-arrow">
        </a>
        <?php endforeach; ?>

      <?php else: ?>

        <div class = "activity-empty">
          <img src = "icons/pen-tool.svg" alt = "">
          <p class = "order-title">Nothing here yet</p>
          <p class = "order-meta">Your quotes and orders will show up here.</p>
          <a href = "request_quote.php" class = "request-btn">Request a quote <img src = "icons/arrow-right.svg" alt = ""></a>
        </div>

      <?php endif; ?>
    </div>

  <?php endif; ?>

  <?php else: ?>

  <!-- Security: contact details (email + phone) and change password -->
  <div class = "user-space">
  <p id = "user-space-eyebrow">Security</p>
  <h2>Login &amp; security</h2>
  <p id = "user-space-details">Your email is what you log in with and where password resets are sent, so changing it needs your password. Updating your phone number doesn't.</p>
  </div>

  <?php if ($success): ?><p class = "form-alert form-alert-success"><?= e($success) ?></p><?php endif; ?>
  <?php if ($formError): ?><p class = "form-alert form-alert-error"><?= e($formError) ?></p><?php endif; ?>

  <!-- Form 1: email + phone (action = contact) -->
  <form method = "post" action = "myProfile.php?tab=security" class = "profile-form">
    <input type = "hidden" name = "csrf" value = "<?= e(csrf_token()) ?>">
    <input type = "hidden" name = "action" value = "contact">

    <p class = "form-title">Contact details</p>

    <div class = "form-row">
      <div class = "form-field">
        <label for = "email">Email address *</label>
        <input id = "email" name = "email" type = "email" maxlength = "100" autocomplete = "email" value = "<?= e($contactForm['email']) ?>" required>
      </div>
      <div class = "form-field">
        <label for = "phone">Phone number</label>
        <input id = "phone" name = "phone" type = "tel" maxlength = "20" autocomplete = "tel" value = "<?= e($contactForm['phone']) ?>" placeholder = "e.g. 082 123 4567">
      </div>
    </div>

    <!-- Only checked by the PHP when the email is changed (see "contact" at the top) -->
    <div class = "form-field">
      <label for = "contact_password">Current password (only needed to change your email)</label>
      <input id = "contact_password" name = "contact_password" type = "password" autocomplete = "current-password">
    </div>

    <button type = "submit" class = "form-btn">Save contact details</button>
  </form>

  <!-- Form 2: change password (action = security) -->
  <form method = "post" action = "myProfile.php?tab=security" class = "profile-form">
    <input type = "hidden" name = "csrf" value = "<?= e(csrf_token()) ?>">
    <input type = "hidden" name = "action" value = "security">

    <p class = "form-title">Change password</p>
    <p class = "form-note">Use at least 8 characters with an uppercase letter, a lowercase letter and a number.</p>

    <div class = "form-field">
      <label for = "current_password">Current password *</label>
      <input id = "current_password" name = "current_password" type = "password" autocomplete = "current-password" required>
    </div>

    <div class = "form-row">
      <div class = "form-field">
        <label for = "new_password">New password *</label>
        <input id = "new_password" name = "new_password" type = "password" autocomplete = "new-password" minlength = "8" required>
      </div>
      <div class = "form-field">
        <label for = "confirm_password">Confirm new password *</label>
        <input id = "confirm_password" name = "confirm_password" type = "password" autocomplete = "new-password" minlength = "8" required>
      </div>
    </div>

    <?php if ($profile['last_login']): ?>
    <p class = "form-note">Last signed in <?= e(date('d M Y, H:i', strtotime($profile['last_login']))) ?></p>
    <?php endif; ?>

    <button type = "submit" class = "form-btn">Update password</button>
  </form>

  <?php endif; ?>

  </div>

 





  </div>


</section>



<script>
// Live shipping status: loads from track_order.php, then refreshes every minute while the page is open.
(function () {
  document.querySelectorAll('.live-ship').forEach(function (box) {
    var body = box.querySelector('.live-body'), url = box.getAttribute('data-live-url'), btn = box.querySelector('.live-refresh');
    if (!url || !btn) { return; }
    function load() {
      btn.disabled = true;
      fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.text(); })
        .then(function (html) { body.innerHTML = html; })
        .catch(function () { body.innerHTML = '<p class="live-msg">Could not load the live status. Please try again.</p>'; })
        .then(function () { btn.disabled = false; });
    }
    btn.addEventListener('click', load); load(); setInterval(load, 60000);
  });
})();
</script>

<script>
// Review form: stop at 3 photos before the form is sent
document.querySelectorAll('.review-form input[type=file]').forEach(function (inp) {
  inp.addEventListener('change', function () {
    var max = parseInt(inp.getAttribute('data-max'), 10) || 3;
    if (inp.files.length > max) { alert('You can add up to ' + max + ' photos.'); inp.value = ''; }
  });
});
</script>

<script>
// Arriving from the "Review" button: open the first review form so the customer can start straight away
if (location.hash === '#reviews') {
  var rb = document.querySelector('.review-box');
  if (rb) { rb.open = true; rb.scrollIntoView({ block: 'center' }); }
}
</script>

<script>
// Reject quote: ask "are you sure?" on the page itself (no browser pop-up)
document.querySelectorAll('.quote-decision-form').forEach(function (f) {
  var main = f.querySelector('[data-qd-main]'), ask = f.querySelector('[data-qd-ask]'),
      box = f.querySelector('[data-qd-confirm]'), cancel = f.querySelector('[data-qd-cancel]');
  if (!ask || !box) { return; }
  ask.addEventListener('click', function () { main.hidden = true; box.hidden = false; });
  cancel.addEventListener('click', function () { box.hidden = true; main.hidden = false; });
});
</script>
</body>
</html>