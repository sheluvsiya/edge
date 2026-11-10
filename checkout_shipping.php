<?php
require_once __DIR__ . '/include/checkout_config.php';
require_once __DIR__ . '/Start_session.php';

$userId = require_login();
$mysqli = db();
$customer = require_customer();

if ($customer <= 0) {
    header('Location: login.php');
    exit;
}

$errors = [];

$provinces = [
    'gauteng' => 'Gauteng',
    'limpopo' => 'Limpopo',
    'mpumalanga' => 'Mpumalanga',
    'western-cape' => 'Western Cape',
    'northern-cape' => 'Northern Cape',
    'north-west' => 'North West',
    'eastern-cape' => 'Eastern Cape',
    'free-state' => 'Free State',
    'kwazulu-natal' => 'KwaZulu-Natal'
];

$types = [
    'apartment' => 'Apartment',
    'house' => 'House',
    'hotel' => 'Hotel',
    'office' => 'Office'
];

$stmt = $mysqli->prepare("
    SELECT *
    FROM address
    WHERE customer_id = ? AND address_type = 'shipping'
    ORDER BY address_id DESC
    LIMIT 1
");
$stmt->bind_param("i", $customer);
$stmt->execute();
$result = $stmt->get_result();
$saved = $result->fetch_assoc() ?: [];
$stmt->close();

$data = $_SESSION['checkout']['shipping'] ?? $saved;
$isCollection = $_SESSION['checkout']['is_collection'] ?? false;
//Set values for if collect or ship
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isCollection = isset($_POST['collect_option']);
    $data = [];
    $_SESSION['checkout']['is_collection'] = $isCollection;

    if ($isCollection) {
        $data = [
            'address_line1' => '97 Edison Cres',
            'address_line2' => 'Sunninghill',
            'suburb' => 'Sunninghill',
            'city' => 'Sandton',
            'province' => 'gauteng',
            'postal_code' => '2191',
            'building_type' => 'office',
            'building_name' => 'Showroom Warehouse',
            'unit_num' => '',
            'access_code' => '',
            'shipping_fee' => '0.00',
            'shipping_rate_id' => ''
        ];
    } else {
        foreach ([
            'address_line1',
            'address_line2',
            'suburb',
            'city',
            'postal_code',
            'building_name',
            'unit_num',
            'access_code',
            'shipping_rate_id',
            'shipping_fee'
        ] as $key) {
            $data[$key] = trim((string)($_POST[$key] ?? ''));
        }

        $data['province'] = (string)($_POST['province'] ?? '');
        $data['building_type'] = (string)($_POST['building_type'] ?? '');

        if ($data['address_line1'] === '') {
            $errors[] = 'Address line 1 is required.';
        }
        if ($data['city'] === '') {
            $errors[] = 'City is required.';
        }
        if (!preg_match('/^\d{4}$/', $data['postal_code'])) {
            $errors[] = 'Enter a 4-digit South African postal code.';
        }
        if (!isset($provinces[$data['province']])) {
            $errors[] = 'Choose a valid province.';
        }
        if (!isset($types[$data['building_type']])) {
            $errors[] = 'Choose a building type.';
        }
        if (empty($data['shipping_rate_id']) || $data['shipping_fee'] === '') {
            $errors[] = 'Please calculate and select a delivery rate option.';
        }
    }

    //Pass teh data to payment.php
  if (!$errors) {
    $_SESSION['checkout']['is_collection'] = $isCollection;
    $_SESSION['checkout']['shipping'] = $data;

    header('Location: checkout_payment.php');
    exit;
}
}

$items = cart_items($mysqli, (int)$customer);
$page = 2;
require __DIR__ . '/include/checkout_header.php';
?>

<a class="back" href="checkout_personal.php">‹ &nbsp; Back to contact</a>
<div class="eyebrow">Delivery details</div>
<h1>Where to<br>send it.</h1>
<p class="lead">Tell us where your order should go. We currently ship within South Africa.</p>
<div class="stepper">
    <div class="step done"><b>✓</b>CONTACT</div>
    <div class="step active"><b>02</b>SHIPPING</div>
    <div class="step"><b>03</b>PAYMENT</div>
</div>

<?php if ($errors): ?><div class="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" id="shipping_form">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<section class="section">
<div class="section-heading"><span class="number">02</span><div><h2>Shipping options</h2><p>Choose between delivery or instant showroom collection.</p></div></div>

<div class="delivery-toggle-container">
    <label class="switch-label">
        <span class="switch-text">I want to collect this order from your showroom</span>
        <div class="switch">
            <input type="checkbox" id="collect_toggle" name="collect_option" value="1" <?= $isCollection ? 'checked' : '' ?>>
            <span class="slider round"></span>
        </div>
    </label>
</div>

<div id="showroom_block" class="collection-showroom-card" style="<?= $isCollection ? '' : 'display: none;' ?>">
    <div class="showroom-icon">📍</div>
    <div class="showroom-details">
        <strong>Showroom Location:</strong>
        <p class="showroom-address">97 Edison Cres, Sunninghill, Sandton, 2191</p>
        <p class="showroom-hours">🕒 Mon - Fri: 08:00 - 17:00 (Closed Weekends & Public Holidays)</p>
    </div>
</div>

<div class="form-grid" id="address_form_grid" style="<?= $isCollection ? 'display: none;' : '' ?>">
     <!-- New Geoapify search field -->
    <div class="field full">
        <label for="autocomplete">Search for your address</label>
        <div id="autocomplete"></div>
    </div>
    <div class="field full"><label for="address_line1">Address line 1</label><input id="address_line1" name="address_line1" autocomplete="address-line1" value="<?= e((string)($data['address_line1'] ?? '')) ?>" required></div>
    <div class="field full"><label for="address_line2">Address line 2 <span class="hint">(optional)</span></label><input id="address_line2" name="address_line2" autocomplete="address-line2" value="<?= e((string)($data['address_line2'] ?? '')) ?>"></div>
    <div class="field"><label for="suburb">Suburb</label><input id="suburb" name="suburb" value="<?= e((string)($data['suburb'] ?? '')) ?>"></div>
    <div class="field"><label for="city">City / town</label><input id="city" name="city" autocomplete="address-level2" value="<?= e((string)($data['city'] ?? '')) ?>" required></div>
    <div class="field"><label for="province">Province</label><select id="province" name="province" required><option value="">Select province</option><?php foreach ($provinces as $key=>$label): ?><option value="<?= e($key) ?>" <?= (($data['province'] ?? '') === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="postal_code">Postal code</label><input id="postal_code" name="postal_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="postal-code" value="<?= e((string)($data['postal_code'] ?? '')) ?>" required></div>
    <div class="field"><label for="building_type">Building type</label><select id="building_type" name="building_type" required><option value="">Select type</option><?php foreach ($types as $key=>$label): ?><option value="<?= e($key) ?>" <?= (($data['building_type'] ?? '') === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="building_name">Building name <span class="hint">(optional)</span></label><input id="building_name" name="building_name" value="<?= e((string)($data['building_name'] ?? '')) ?>"></div>
    <div class="field"><label for="unit_num">Unit / flat number <span class="hint">(optional)</span></label><input id="unit_num" name="unit_num" value="<?= e((string)($data['unit_num'] ?? '')) ?>"></div>
    <div class="field"><label for="access_code">Access code <span class="hint">(optional)</span></label><input id="access_code" name="access_code" value="<?= e((string)($data['access_code'] ?? '')) ?>"></div>
</div>

<div id="shipping_rates" class="shipping-rates" style="display:none; margin-top:20px; padding:15px; background:#f9f9f9; border-radius:4px;">
    <h3>Available delivery options</h3>
    <div id="rates_list" style="display:flex; flex-direction:column; gap:10px; margin-bottom:15px;"></div>
    <p>Selected shipping fee: <strong id="shipping_fee_display">R0.00</strong></p>
    
    <input type="hidden" name="shipping_rate_id" id="shipping_rate_id_input" value="<?= e((string)($data['shipping_rate_id'] ?? '')) ?>">
    <input type="hidden" name="shipping_fee" id="shipping_fee_input" value="<?= e((string)($data['shipping_fee'] ?? '')) ?>">
</div>

<button type="button" id="calculate_shipping" class="btn secondary" style="margin-top:15px;">
    Calculate shipping
</button>

<p id="shipping_message" role="status" style="margin-top:10px; color:#555;"></p>

<div class="actions">
    <a class="btn secondary" href="checkout_personal.php">Back</a>
    <button class="btn primary" type="submit">Continue to payment <span>→</span></button>
</div>
</section>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
// --- Geoapify Address Autocomplete ---
const apiKey = 'b5dbe11b33744f318d6130ea8e3153c8'; // Replace with your actual API key

if (document.getElementById('autocomplete')) {
    const addressAutocomplete = new autocomplete.GeocoderAutocomplete(
        document.getElementById('autocomplete'),
        apiKey,
        {
            placeholder: 'Start typing your address...',
            lang: 'en',
            limit: 5,
            filter: {
                countrycode: ['za'] // Restrict results to South Africa
            }
        }
    );

    // Listen for the 'select' event
    addressAutocomplete.on('select', (location) => {
        if (!location || !location.properties) return;

        const props = location.properties;
        
        // Fill the form fields with the returned address parts
        document.getElementById('address_line1').value = props.address_line1 || '';
        document.getElementById('address_line2').value = props.address_line2 || '';
        document.getElementById('suburb').value = props.suburb || props.city || '';
        document.getElementById('city').value = props.city || '';
        document.getElementById('postal_code').value = props.postcode || '';
        
        // Handle province (state) mapping if needed
        if (props.state) {
            const provinceSelect = document.getElementById('province');
            // Geoapify returns full state names, so we'll attempt to set the select value
            const stateValue = props.state.toLowerCase().replace(/ /g, '-');
            if (provinceSelect.querySelector(`option[value="${stateValue}"]`)) {
                provinceSelect.value = stateValue;
            }
        }

        // Notify the user that fields have been filled
        const messageEl = document.getElementById('shipping_message');
        if (messageEl) {
            messageEl.textContent = 'Address selected. Please confirm the details below.';
        }
        // Autocomplete fills fields via JS, which doesn't fire input events,
        // so clear any rates from the previous address manually.
        if (typeof clearRates === 'function') {
            clearRates('Address changed. Calculate shipping again.');
        }
    });
}
    const form = document.getElementById('shipping_form');
    const toggle = document.getElementById('collect_toggle');
    const showroomBlock = document.getElementById('showroom_block');
    const addressGrid = document.getElementById('address_form_grid');
    const calculateButton = document.getElementById('calculate_shipping');
    const ratesContainer = document.getElementById('shipping_rates');
    const ratesList = document.getElementById('rates_list');
    const shippingMessage = document.getElementById('shipping_message');
    const shippingFeeDisplay = document.getElementById('shipping_fee_display');
    const shippingFeeHidden = document.getElementById('shipping_fee_input');
    const shippingRateIdHidden = document.getElementById('shipping_rate_id_input');

    const addressInputs = [
        'address_line1',
        'address_line2',
        'suburb',
        'city',
        'province',
        'postal_code'
    ].map(id => document.getElementById(id)).filter(Boolean);

    const requiredInputs = addressGrid.querySelectorAll(
        'input[required], select[required]'
    );

    let requestNumber = 0;
    let activeController = null;

    function clearRates(message = '') {
        requestNumber++;
        ratesList.replaceChildren();
        ratesContainer.style.display = 'none';
        shippingRateIdHidden.value = '';
        shippingFeeHidden.value = '';
        shippingFeeDisplay.textContent = 'R0.00';
        shippingMessage.textContent = message;
    }

    function toggleView(isCollecting) {
        showroomBlock.style.display = isCollecting ? 'flex' : 'none';
        addressGrid.style.display = isCollecting ? 'none' : 'grid';
        calculateButton.style.display = isCollecting ? 'none' : 'inline-block';

        requiredInputs.forEach(input => {
            input.required = !isCollecting;
        });

        if (isCollecting) {
            clearRates('Showroom collection selected.');
        }
    }

    toggle.addEventListener('change', function () {
        toggleView(this.checked);
    });

    // A change to the delivery address invalidates previously calculated rates.
    addressInputs.forEach(input => {
        input.addEventListener('change', function () {
            clearRates('Address changed. Calculate shipping again.');
        });

        input.addEventListener('input', function () {
            clearRates('Address changed. Calculate shipping again.');
        });
    });

    calculateButton.addEventListener('click', async function () {
        if (toggle.checked) {
            clearRates('Showroom collection does not require a delivery quote.');
            return;
        }

        for (const field of requiredInputs) {
            if (!field.checkValidity()) {
                field.reportValidity();
                return;
            }
        }

        const currentRequest = ++requestNumber;

        calculateButton.disabled = true;
        shippingMessage.textContent = 'Calculating shipping rates...';
        ratesList.replaceChildren();
        ratesContainer.style.display = 'none';

        shippingRateIdHidden.value = '';
        shippingFeeHidden.value = '';
        shippingFeeDisplay.textContent = 'R0.00';

        // Cancel any previous in-flight request
        if (activeController) {
            activeController.abort();
        }
        activeController = new AbortController();

        try {
            // Includes the address fields and hidden CSRF token.
            const formData = new FormData(form);

            const response = await fetch('api/get_shipping_rates.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'Cache-Control': 'no-cache'
                },
                signal: activeController.signal
            });

            const responseText = await response.text();

            let result;

            try {
                result = JSON.parse(responseText);
            } catch {
                throw new Error(
                    'The server returned an invalid response. Check the PHP error log.'
                );
            }

            if (currentRequest !== requestNumber) {
                return;
            }

            if (!response.ok) {
                throw new Error(
                    result.error || 'Unable to calculate shipping rates.'
                );
            }

            if (!Array.isArray(result.rates) || result.rates.length === 0) {
                throw new Error(
                    'No delivery rates are available for this address.'
                );
            }

            let selectedRate = false;

            result.rates.forEach((rate, index) => {
                const amount = Number(rate.amount);

                if (
                    rate.id === undefined ||
                    rate.id === null ||
                    !Number.isFinite(amount) ||
                    amount < 0
                ) {
                    return;
                }

                const label = document.createElement('label');
                label.style.cssText =
                    'display:flex;align-items:center;gap:10px;' +
                    'padding:12px;border:1px solid #ddd;border-radius:6px;' +
                    'cursor:pointer;background:#fff;';

                const radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'live_rate_selection';
                radio.value = String(rate.id);
                radio.required = true;

                // Restore the previous selection only if Bob Go returned it.
                if (
                    !selectedRate &&
                    shippingRateIdHidden.value === String(rate.id)
                ) {
                    radio.checked = true;
                    selectedRate = true;
                }

               const description = document.createElement('span');

description.textContent =
    `${rate.name || 'Standard Delivery'} — R${amount.toFixed(2)}`;

description.style.cssText = `
    display: inline-block;
    color: white;
    background: black;
    font-size: 16px;
    font-weight: 600;
    visibility: visible;
    opacity: 1;
`;

radio.style.cssText = `
    display: inline-block;
    visibility: visible;
    opacity: 1;
    flex-shrink: 0;
    width: 16px;
    height: 16px;
`;

label.style.cssText = `
    display: flex;  
    align-items: center;
    gap: 10px;
    padding: 14px;
    margin-bottom: 10px;
    border: 1px solid black;
    border-radius: 6px;
    color: white;
    background: black;
    cursor: pointer;
`;

radio.addEventListener('change', function () {
                    if (!this.checked) {
                        return;
                    }

                    shippingRateIdHidden.value = String(rate.id);
                    shippingFeeHidden.value = amount.toFixed(2);
                    shippingFeeDisplay.textContent = `R${amount.toFixed(2)}`;

                    shippingMessage.textContent =
                        'Delivery option selected.';
                });

                label.append(radio, description);
                ratesList.appendChild(label);

                if (radio.checked) {
                    shippingRateIdHidden.value = String(rate.id);
                    shippingFeeHidden.value = amount.toFixed(2);
                    shippingFeeDisplay.textContent = `R${amount.toFixed(2)}`;
                }
            });

            if (ratesList.children.length === 0) {
                throw new Error(
                    'The shipping service returned no valid delivery options.'
                );
            }

            ratesContainer.style.display = 'block';

            shippingMessage.textContent = selectedRate
                ? 'Select a delivery option or continue with your current selection.'
                : 'Choose one of the delivery options below.';

        } catch (error) {
            if (error.name === 'AbortError') return;
            if (currentRequest === requestNumber) {
                clearRates(error.message);
            }
        } finally {
            calculateButton.disabled = false;
        }
    });

    // Restore the correct layout when the page opens.
    toggleView(toggle.checked);

    // Automatically calculate rates for a previously saved delivery address.
    if (
        !toggle.checked &&
        document.getElementById('address_line1').value.trim() !== ''
    ) {
        calculateButton.click();
    }
});
</script>

<?php require __DIR__ . '/include/checkout_footer.php'; ?>
