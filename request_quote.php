<?php
include("Start_session.php");
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/reCaptcha_config.php';
require_login();
$sessionUser = auth_user();
$customerId = (int)$sessionUser['customer_id'];
/*
|--------------------------------------------------------------------------
| AJAX: Get colors for selected material
|--------------------------------------------------------------------------
*/
if (isset($_GET['get_colors']) && isset($_GET['material_id'])) {

    $materialId = (int)$_GET['material_id'];

    $stmt = $conn->prepare("
        SELECT material_color
        FROM material
        WHERE material_id = ?
    ");

    if (!$stmt) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $stmt->bind_param("i", $materialId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    $colors = [];

    if ($row && !empty($row['material_color'])) {

        $colors = array_map(
            'trim',
            explode(',', $row['material_color'])
        );
    }

    header('Content-Type: application/json');

    echo json_encode($colors);

    exit;
}

//Declaring variable
$errors = [];
$success = "";

// Check if a quote was successfully created
$display_price = null;

/*
|--------------------------------------------------------------------------
| One-time quote success message
|--------------------------------------------------------------------------
*/
if (isset($_SESSION['quote_success'])) {

    $success = $_SESSION['quote_success']['message'];
    $display_price = $_SESSION['quote_success']['price'];

    unset($_SESSION['quote_success']);
}

/*
|--------------------------------------------------------------------------
| One-time quote error messages
|--------------------------------------------------------------------------
*/
if (isset($_SESSION['quote_errors'])) {

    $errors = $_SESSION['quote_errors'];

    unset($_SESSION['quote_errors']);
}



//Make sure code runs after submit button is pressed only


if ($_SERVER["REQUEST_METHOD"] === "POST") {
 if (!isset($customerId)) {
        echo "<p class='error-message'>You need to be logged in to request a quote.</p>";
    } else {

    $uploaded_image_paths = [];
$cad_file_url = null;

        /*
        |--------------------------------------------------------------------------
        | Verify Google reCAPTCHA
        |--------------------------------------------------------------------------
        | The quote will NOT be created unless Google confirms
        | that the reCAPTCHA was successfully completed.
        |--------------------------------------------------------------------------
        */

      $recaptcha_token = $_POST['recaptcha_token'] ?? '';

if (empty($recaptcha_token)) {

    $errors[] = "Please complete the reCAPTCHA verification.";

} else {

    $verify_url = 'https://www.google.com/recaptcha/api/siteverify';

    $response = file_get_contents($verify_url . '?' . http_build_query([
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $recaptcha_token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]));

    $result = json_decode($response, true);

    if (
        empty($result['success']) ||
        ($result['action'] ?? '') !== 'quote_request' ||
        ($result['score'] ?? 0) < 0.5
    ) {
        $errors[] = "reCAPTCHA verification failed. Please try again.";
    }
}

    // Read form values
    $selected_material_id = isset($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
    $service_type         = isset($_POST['service_type']) ? trim($_POST['service_type']) : '';
    $height               = isset($_POST['height']) ? (float) $_POST['height'] : 0.00;
    $width                = isset($_POST['width']) ? (float) $_POST['width'] : 0.00;
    $breadth              = isset($_POST['breadth']) ? (float) $_POST['breadth'] : 0.00;
    $quantity             = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1;
    $quote_description    = isset($_POST['quote_description']) ? trim($_POST['quote_description']) : '';

    // JavaScript converts kg to grams before submission.
    $weight_in_grams = isset($_POST['weight']) ? (float) $_POST['weight'] : 0.00;

   
    // Basic validation
if (!in_array($service_type, ['print_and_design', 'design', 'print'], true)) {
    $errors[] = "Please select a valid service type.";
}

/*
|--------------------------------------------------------------------------
| 3D Model Only
|--------------------------------------------------------------------------
| When the customer only wants a 3D model:
| - Material is not required
| - Color is not required
| - Weight is not required
| - Material stock is not checked
|--------------------------------------------------------------------------
*/
$is_model_only = ($service_type === 'design');

if (!$is_model_only) {

    // Material is required for printing services.
    if ($selected_material_id <= 0) {
        $errors[] = "Please select a valid material.";
    }

    // Weight is required for printing services.
    if ($weight_in_grams <= 0) {
        $errors[] = "Estimated weight must be greater than 0.";
    }
}

    if ($quantity <= 0) {
        $errors[] = "Quantity must be at least 1.";
    }

    if ($height < 0 || $width < 0 || $breadth < 0) {
        $errors[] = "Dimensions cannot be negative.";
    }

    // At least one upload is required.
    $has_photo = false;
    $has_cad = false;

    if (isset($_FILES['upload_photos']['name']) && is_array($_FILES['upload_photos']['name'])) {
        foreach ($_FILES['upload_photos']['name'] as $key => $name) {
            if (
                !empty($name) &&
                isset($_FILES['upload_photos']['error'][$key]) &&
                $_FILES['upload_photos']['error'][$key] === UPLOAD_ERR_OK
            ) {
                $has_photo = true;
                break;
            }
        }
    }

    if (
        isset($_FILES['file_url']['error']) &&
        $_FILES['file_url']['error'] === UPLOAD_ERR_OK
    ) {
        $has_cad = true;
    }

    if (!$has_photo && !$has_cad) {
        $errors[] = "Please upload at least one reference photo or CAD design file.";
    }

    if (empty($errors) && !$is_model_only) {

        // Check that the selected material is available.
        $stmt = $conn->prepare("
            SELECT material_type, price_per_kg, material_weight, material_length
            FROM material
            WHERE material_id = ?
              AND status = 'available'
              AND material_weight > 0
        ");

        if (!$stmt) {
            $errors[] = "Could not prepare the material lookup.";
        } else {
            $stmt->bind_param("i", $selected_material_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $material = $result->fetch_assoc();
            $stmt->close();

            if (!$material) {
                $errors[] = "Selected material is currently unavailable or out of stock.";
            }
        }
    }

  /*
|--------------------------------------------------------------------------
| Material stock and weight calculation
|--------------------------------------------------------------------------
| Only performed when printing is requested.
|--------------------------------------------------------------------------
*/

$weight_in_kg = 0.00;

if (empty($errors) && !$is_model_only) {

    $price_per_kg = (float) $material['price_per_kg'];
    $stock_weight_kg = (float) $material['material_weight'];

    // Weight entered is per identical part.
    $weight_in_kg = $weight_in_grams / 1000;

    // Inventory must account for quantity.
    $total_weight_in_kg = $weight_in_kg * $quantity;

    if ($total_weight_in_kg > $stock_weight_kg) {
        $errors[] = "There is not enough material in stock for this quantity.";
    }
}

    if (empty($errors)) {

    /*
|--------------------------------------------------------------------------
| File Upload Limits
|--------------------------------------------------------------------------
| CAD:
| - Maximum 1 file
| - Maximum size: 1 GB
|
| Images:
| - Maximum 10 images
| - Maximum size: 20 MB per image
|--------------------------------------------------------------------------
*/

$max_cad_bytes = 1 * 1024 * 1024 * 1024; // 1 GB
$max_image_bytes = 20 * 1024 * 1024;     // 20 MB per image

$max_cad_files = 1;
$max_image_files = 10;

$allowed_img_extensions = ['png', 'jpg', 'jpeg'];
$allowed_cad_extensions = ['step', 'iges', 'obj', 'stl', 'zip'];



        $upload_dir = 'uploads/cad/';
        $img_dir = 'uploads/images/';
       

      // Validate CAD upload.
if ($has_cad) {

    /*
     * Only one CAD file is allowed.
     * The HTML input is already a single-file input,
     * but this server-side check prevents bypassing that restriction.
     */
    $cad_name = $_FILES['file_url']['name'];

    if (is_array($cad_name)) {

        $cad_file_count = count(array_filter($cad_name));

        if ($cad_file_count > $max_cad_files) {
            $errors[] = "Only one CAD design file is allowed.";
        }

    } else {

        $cad_ext = strtolower(pathinfo($cad_name, PATHINFO_EXTENSION));

        if (!in_array($cad_ext, $allowed_cad_extensions, true)) {

            $errors[] = "Unsupported CAD file type: ." . htmlspecialchars($cad_ext);

        } elseif ($_FILES['file_url']['size'] > $max_cad_bytes) {

            $errors[] = "The CAD file is larger than the 1 GB limit.";
        }
    }
}


       // Validate every image before moving any files.
if ($has_photo) {

    // Count only files that actually have a filename.
    $image_names = array_filter(
        $_FILES['upload_photos']['name'],
        function ($name) {
            return !empty($name);
        }
    );

    $image_count = count($image_names);

    // Maximum of 10 images.
    if ($image_count > $max_image_files) {

        $errors[] = "You can upload a maximum of 10 images.";

    } else {

        foreach ($_FILES['upload_photos']['name'] as $key => $name) {

            if (empty($name)) {
                continue;
            }

            $upload_error = $_FILES['upload_photos']['error'][$key];

            if ($upload_error !== UPLOAD_ERR_OK) {
                $errors[] = "One of the selected images could not be uploaded.";
                break;
            }

            $img_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($img_ext, $allowed_img_extensions, true)) {

                $errors[] = "Unsupported image type: ." . htmlspecialchars($img_ext);
                break;
            }

            // Maximum 20 MB per image.
            if ($_FILES['upload_photos']['size'][$key] > $max_image_bytes) {

                $errors[] = "Image '" . htmlspecialchars($name) . "' is larger than the 20MB limit.";
                break;
            }
        }
    }
}

        if (empty($errors)) {

            // Create upload folders if they do not already exist.
            if ($has_cad && !is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            if ($has_photo && !is_dir($img_dir)) {
                mkdir($img_dir, 0755, true);
            }

            // Move CAD file.
            if ($has_cad) {
                $unique_cad_name = uniqid('cad_', true) . '.' . $cad_ext;
                $target_cad_path = $upload_dir . $unique_cad_name;

                if (move_uploaded_file($_FILES['file_url']['tmp_name'], $target_cad_path)) {
                    $cad_file_url = $target_cad_path;
                } else {
                    $errors[] = "Failed to save the CAD file.";
                }
            }

            // Move image files.
            if (empty($errors) && $has_photo) {
                foreach ($_FILES['upload_photos']['name'] as $key => $name) {

                    if (empty($name)) {
                        continue;
                    }

                    $img_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $unique_img_name = uniqid('img_', true) . '.' . $img_ext;
                    $target_img_path = $img_dir . $unique_img_name;

                    if (move_uploaded_file($_FILES['upload_photos']['tmp_name'][$key], $target_img_path)) {
                        $uploaded_image_paths[] = $target_img_path;
                    } else {
                        $errors[] = "Failed to save one of the reference images.";
                        break;
                    }
                }
            }
        }

        if (empty($errors)) {

          $markup_fee = 10.00;

/*
|--------------------------------------------------------------------------
| Calculate estimated price
|--------------------------------------------------------------------------
| 3D Model Only does not use material or weight pricing.
| For now, the quote starts at the setup fee.
|--------------------------------------------------------------------------
*/

if ($is_model_only) {

    $estimated_price = null;

} else {
   
    $base_price = $weight_in_kg * $price_per_kg * $quantity;
    $estimated_price = $base_price + $markup_fee;
}

// Save the quote.
         if ($is_model_only) {

    $insert_quote_stmt = $conn->prepare("
        INSERT INTO quote (
            service_type,
            customer_id,
            material_id,
            height,
            width,
            breadth,
            weight,
            quantity,
            quote_description,
            estimated_price,
            quote_status
        )
        VALUES (?, ?, NULL, ?, ?, ?, 0, ?, ?, ?, 'pending')
    ");

    $insert_quote_stmt->bind_param(
        "sidddisd",
        $service_type,
        $customerId,
        $height,
        $width,
        $breadth,
        $quantity,
        $quote_description,
        $estimated_price
    );

} else {

    $insert_quote_stmt = $conn->prepare("
        INSERT INTO quote (
            service_type,
            customer_id,
            material_id,
            height,
            width,
            breadth,
            weight,
            quantity,
            quote_description,
            estimated_price,
            quote_status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");

    $insert_quote_stmt->bind_param(
        "siiddddisd",
        $service_type,
        $customerId,
        $selected_material_id,
        $height,
        $width,
        $breadth,
        $weight_in_grams,
        $quantity,
        $quote_description,
        $estimated_price
    );
}

                if ($insert_quote_stmt->execute()) {
                    $new_quote_id = $conn->insert_id;
                    $insert_quote_stmt->close();

                    // Save each image and associate the CAD file with it.
                    $insert_img_stmt = $conn->prepare("
                        INSERT INTO quote_img (quote_id, quote_img_url, quote_cad_url)
                        VALUES (?, ?, ?)
                    ");

                    if (!$insert_img_stmt) {
                        $errors[] = "Could not prepare the uploaded-file database entry.";
                    } else {

                        foreach ($uploaded_image_paths as $image_path) {
                            $insert_img_stmt->bind_param(
                                "iss",
                                $new_quote_id,
                                $image_path,
                                $cad_file_url
                            );
                            $insert_img_stmt->execute();
                        }

                        // If the customer supplied only a CAD file, still create a
                        // quote_img row so the CAD path is not lost.
                        if (empty($uploaded_image_paths) && $cad_file_url !== null) {
                            $empty_image = null;

                            $insert_img_stmt->bind_param(
                                "iss",
                                $new_quote_id,
                                $empty_image,
                                $cad_file_url
                            );
                            $insert_img_stmt->execute();
                        }

                        $insert_img_stmt->close();
                    }

                  

                } else {
                    $errors[] = "Database entry error: Could not save the quotation.";
                    $insert_quote_stmt->close();
                }
            }
        
        }

        // Clean up files if the database operation failed.
        if (!empty($errors)) {
            foreach ($uploaded_image_paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if ($cad_file_url !== null && is_file($cad_file_url)) {
                unlink($cad_file_url);
            }
        }
    }


// Prevent browser refresh from resubmitting the previous POST request.
// If validation/database errors occurred, keep them for exactly one redirected
// page load, then display them normally. A subsequent refresh will be a clean GET.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
   if (empty($errors)) {
   $_SESSION['quote_success'] = [
    'message' => 'Quote was successfully created',
    'price' => $estimated_price !== null
        ? number_format($estimated_price, 2, '.', '')
        : null

];  

header("Location: request_quote.php");
exit();
}
}

// Display errors saved from the redirected POST request.
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_SESSION['quote_errors'])) {
    $errors = $_SESSION['quote_errors'];
    unset($_SESSION['quote_errors']);
}

// 3. UI RENDERING PIPELINE: Query live state records for dropdown rendering paths

}

$material_query = $conn->query("SELECT material_id, material_type, price_per_kg, material_length, status FROM material WHERE deleted_at IS NULL ORDER BY material_id");
$materials_list = [];
if ($material_query) {
    while ($row = $material_query->fetch_assoc()) {
        $materials_list[] = $row;
    }
}
?>



<html>
<head>
<title>3D Edge</title>
 <link rel="stylesheet" href="CSS/quote_css.css">
 <link rel="stylesheet" href="CSS/navbar_css.css">
  <link rel="stylesheet" href="CSS/footer_css.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
<script src="https://www.google.com/recaptcha/api.js?render=<?php echo htmlspecialchars(RECAPTCHA_SITE_KEY); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
</head>
<body class="body">
<!-- SUCCESS POP-UP: centred card on a blurred backdrop -->
<?php if (!empty($success)): ?>
    <div id="successModal" class="qm-overlay" role="dialog" aria-modal="true" aria-labelledby="qm-success-title">
        <div class="qm-card">
            <div class="qm-icon qm-icon-ok" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
            </div>
            <h3 class="qm-title" id="qm-success-title">Quote request sent</h3>
            <p class="qm-text"><?php echo htmlspecialchars($success); ?></p>

            <?php if ($display_price !== null): ?>
                <div class="qm-summary">
                    <span class="qm-summary-label">Estimated price</span>
                    <span class="qm-summary-value">R<?php echo $display_price; ?></span>
                    <span class="qm-summary-note">A guide only. We will confirm the final price after reviewing your request.</span>
                </div>
            <?php endif; ?>

            <div class="qm-actions">
                <a href="myProfile.php?tab=orders" class="qm-btn qm-btn-light">View my quotes</a>
                <button type="button" class="qm-btn qm-btn-dark" onclick="closeSuccessModal()">Continue</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ERROR POP-UP -->
<?php if (!empty($errors)): ?>
    <div id="errorModal" class="qm-overlay" role="alertdialog" aria-modal="true" aria-labelledby="qm-error-title">
        <div class="qm-card">
            <div class="qm-icon qm-icon-err" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v6"/><circle cx="12" cy="17" r=".6" fill="currentColor"/></svg>
            </div>
            <h3 class="qm-title" id="qm-error-title">We could not send your request</h3>
            <p class="qm-text">Please check the following and try again.</p>
            <ul class="qm-list">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="qm-actions">
                <button type="button" class="qm-btn qm-btn-dark" onclick="closeErrorModal()">Got it</button>
            </div>
        </div>
    </div>
<?php endif; ?>

 <?php require_once __DIR__ . '\include\navbar.php'; ?>

    <!-- Main Content Container Grid -->
    <main class="hero-container">
        
        <!-- Left Side Column -->
        <section class="hero-text">
            <div class="tagline">Scan · Design · Print</div>
            <h1>Print It<br><span>Your</span><br>Way.</h1>
            <p>Sharp, reliable print for brands that refuse to blend in. Build your order below and get a quick estimate in seconds.</p>
            
            <div class="badge-container">
                <div class="badge">Fast Turnaround</div>
                <div class="badge">Premium Stock</div>
                <div class="badge">Local Delivery</div>
            </div>
        </section>

        <!-- Right Side Column: Instant Estimate Widget Card -->
             
               <!-- Updated Quote Request Card with Database Layout Alignment -->
        <section class="quote-card">
            <div class="card-header">
                <h2 class="card-title">Instant Quote Request</h2>
            </div>

            <form method="POST" enctype="multipart/form-data" id="quote-form">
                    <input type="hidden" name="recaptcha_token" id="recaptcha_token">
                <!-- Dual File Upload Boxes Section -->
                            
                               <!-- File Upload Target Modules Box Section Block -->
                <div class="upload-row">
                    <!-- Photo Upload Module -->
                    <div class="upload-box" id="photo-upload-box" style="position: relative;">
                        <!-- Wrapped content in clickable trigger layout area -->
                        <div onclick="if(!event.target.classList.contains('remove-file-btn')) document.getElementById('upload-photo').click();">
                            <label style="cursor: pointer;">Upload Photo/s</label>
                            <span class="upload-icon">↑</span>
                            <span id="photo-upload-status">PNG, JPG</span>
                        </div>
                        
                        <!-- Visual Feedback Container Display Layer -->
                        <div id="photo-preview-wrap" style="display: none; margin-top: 15px; background: #000000; padding: 12px; border-radius: 8px; border: 1px solid #27272a; text-align: left;">
                            <div id="photo-file-list" style="font-size: 11px; color: #ccff33; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-bottom: 5px;"></div>
                            <span class="remove-file-btn" id="remove-photos-action" style="font-size: 10px; color: #ef4444; font-weight: 800; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block;">✕ Remove Files</span>
                        </div>
                        <input type="file" id="upload-photo" name="upload_photos[]" accept="image/png, image/jpeg" multiple style="display: none;">
                    </div>
                    
                    <!-- CAD File Upload Module -->
                    <div class="upload-box" id="cad-upload-box" style="position: relative;">
                        <!-- Wrapped content in clickable trigger layout area -->
                        <div onclick="if(!event.target.classList.contains('remove-file-btn')) document.getElementById('file_url').click();">
                            <label for="file_url" style="cursor: pointer;">Upload CAD Design File</label>
                            <span class="upload-icon">↑</span>
                            <span id="cad-upload-status">STEP, OBJ, STL</span>
                        </div>
                        
                        <!-- Visual Feedback Container Display Layer -->
                        <div id="cad-preview-wrap" style="display: none; margin-top: 15px; background: #000000; padding: 12px; border-radius: 8px; border: 1px solid #27272a; text-align: left;">
                            <div id="cad-file-name" style="font-size: 11px; color: #ccff33; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-bottom: 5px;"></div>
                            <span class="remove-file-btn" id="remove-cad-action" style="font-size: 10px; color: #ef4444; font-weight: 800; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block;">✕ Remove File</span>
                        </div>
                        <input type="file" id="file_url" name="file_url" accept=".step,.iges,.obj,.stl,.zip" style="display: none;">
                    </div>
                </div>



                
            <!-- Material Grid Selection -->
<div class="form-section-block" id="material-section">
    <label class="section-label">Material:</label>
    <div class="material-grid">
        <?php foreach ($materials_list as $index => $mat): 
            // Condition: Disable if status isn't available OR length is 0 or less
            $isDisabled = ($mat['status'] !== 'available' || floatval($mat['material_length']) <= 0);
            
            // Auto-check the first available material as a default choice
            $checkedAttr = (!$isDisabled && $index === 0) ? 'checked' : '';
        ?>
            <label class="custom-radio <?php echo $isDisabled ? 'disabled-option' : ''; ?>">
                <input type="radio" 
                       name="material_id" 
                       value="<?php echo $mat['material_id']; ?>" 
                       <?php echo $checkedAttr; ?>
                       <?php echo $isDisabled ? 'disabled' : ''; ?>>
                <span class="checkmark"></span> 
                <?php echo htmlspecialchars($mat['material_type']); ?>
                <small class="mat-rate" style="display:block;font-size:11px;font-weight:700;color:#94a3b8;padding-left:5px;">R<?php echo number_format((float)$mat['price_per_kg'], 0); ?>/kg</small>
                <?php if ($isDisabled): ?>
                    <span style="font-size: 9px; color: #ef4444; display: block; font-weight: 800; padding-left:5px;">OUT OF STOCK</span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="material-help-text">
    Not sure which material to choose?
    <a href="materials.php">Click here to learn more.</a>
</p>
</div>




            
 <!-- Color Selection -->
<div class="form-section-block" id="color-section">
    <label class="section-label">Color:</label>

    <div class="color-flex" id="color-container">
        <p style="color: #64748b; font-size: 12px;">
            Select a material to see available colors.
        </p>
    </div>
</div>

                <!-- NEW SECTION: Technical Dimensions & Weight Unit Selection (Below Color) -->
                <div class="form-section-block" id="technical-section">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <label class="section-label" style="margin-bottom: 0;">Technical Specifications</label>
                        
                        <!-- Inline Unit Selection Toggle for Weight Metric Options -->
                        <div style="display: flex; gap: 8px; background-color: #000000; padding: 4px; border-radius: 8px; border: 1px solid #27272a;">
                            <label style="font-size: 11px; font-weight: 800; color: #ffffff; padding: 6px 12px; border-radius: 6px; cursor: pointer; background-color: #121214; border: 1px solid #a3e635;" id="unit-g-label">
                                <input type="radio" name="weight_unit" value="g" checked style="display:none;" onclick="document.getElementById('weight-suffix').innerText='(g)'"> GRAMS (g)
                            </label>
                            <label style="font-size: 11px; font-weight: 800; color: #64748b; padding: 6px 12px; border-radius: 6px; cursor: pointer;" id="unit-kg-label">
                                <input type="radio" name="weight_unit" value="kg" style="display:none;" onclick="document.getElementById('weight-suffix').innerText='(kg)'"> KILOGRAMS (kg)
                            </label>
                        </div>
                    </div>

                    <!-- 3-Column Dimension Numerical Configuration Grid (Decimal 10,2) -->
                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label for="height" style="display: block; color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">Height (mm)</label>
                            <input type="number" step="0.01" id="height" name="height" placeholder="0.00" style="width: 100%; background-color: #000000; border: 1px solid #27272a; color: #ffffff; padding: 14px; border-radius: 10px; outline: none;">
                        </div>
                        <div class="form-group">
                            <label for="width" style="display: block; color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">Width (mm)</label>
                            <input type="number" step="0.01" id="width" name="width" placeholder="0.00" style="width: 100%; background-color: #000000; border: 1px solid #27272a; color: #ffffff; padding: 14px; border-radius: 10px; outline: none;">
                        </div>
                        <div class="form-group">
                            <label for="breadth" style="display: block; color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">Breadth (mm)</label>
                            <input type="number" step="0.01" id="breadth" name="breadth" placeholder="0.00" style="width: 100%; background-color: #000000; border: 1px solid #27272a; color: #ffffff; padding: 14px; border-radius: 10px; outline: none;">
                        </div>
                    </div>

                    <!-- Part Mass Mass Weight Entry field -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="weight" style="display: block; color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">
                            Estimated Weight <span id="weight-suffix" style="color:#a3e635;">(g)</span>
                        </label>
                        <input type="number" step="0.01" id="weight" name="weight" placeholder="0.00" style="width: 100%; background-color: #000000; border: 1px solid #27272a; color: #ffffff; padding: 14px; border-radius: 10px; outline: none;">
                    </div>
                </div>

                <!-- Service Type Selection -->
                <div class="form-section-block">
                    <label class="section-label">Service Type *</label>
                    <span class="section-subtext">If you don't have a print-ready 3D file, we can model it for you</span>
                    <div class="service-grid">
                        <label class="custom-radio">
                            <input type="radio" name="service_type" value="print_and_design" required checked>
                            <span class="checkmark"></span> 3D Model & Print
                        </label>
                        <label class="custom-radio">
            <input type="radio" name="service_type" value="design">
                            <span class="checkmark"></span> 3D Model Only
                        </label>
                        <label class="custom-radio">
                            <input type="radio" name="service_type" value="print">
                            <span class="checkmark"></span> Print Only
                        </label>
                    </div>
                </div>

                <!-- Order Quantity Block (Below Service Type) -->
                <div class="form-section-block">
                    <label class="section-label" for="quantity">Order Quantity *</label>
                    <span class="section-subtext">Specify the number of identical production copies required</span>
                    <div style="max-width: 200px;">
                        <input type="number" id="quantity" name="quantity" value="1" min="1" required style="width: 100%; background-color: #000000; border: 1px solid #27272a; color: #ffffff; padding: 14px; border-radius: 10px; outline: none; font-weight: 700; font-size: 16px; text-align: center;">
                    </div>
                </div>

                <!-- Notes Input Section -->
                <div class="form-section-block">
                    <label class="section-label" for="quote_description">Notes:</label>
                    <textarea id="quote_description" name="quote_description" class="notes-box" placeholder="Describe any additional specifications or structural requirements here..."></textarea>
                </div>

                                <!-- Live Price Estimation Display Container Block -->
                <div class="form-section-block" id="estimator-panel" style="border-color: #ccff33; background-color: #121214;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <label class="section-label" style="margin-bottom: 5px;">Estimated From</label>
                            <span style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 800;">Calculated Live</span>
                        </div>
                        <div style="text-align: right;">
                            <div id="live-price-display" style="font-size: 36px; font-weight: 800; color: #ccff33; line-height: 1;">  R0.00</div>
                            <span style="font-size: 12px; color: #64748b; font-weight: 600;">ex VAT &amp; setup fee</span>
                            <div id="live-price-note" style="font-size: 11px; color: #94a3b8; font-weight: 600; margin-top: 4px;"></div>
                        </div>
                    </div>
                </div>
  <!-- Recaptcha button -->
 

                <!-- Centered Form Submission Action Button -->
                <div class="form-submit-container">
                    <button type="submit" class="btn-main-submit">Request A Quote</button>
                </div>
            </form>
        </section>

    </main>

    <!-- Three Column Features Grid -->
    <section class="features-section">
        <div class="feature-item">
            <h3>01 / Quality</h3>
            <h4>Print That Pops.</h4>
            <p>Colour-accurate, crisp finishes that make every piece feel considered.</p>
        </div>
        <div class="feature-item">
            <h3>02 / Speed</h3>
            <h4>No Waiting Around.</h4>
            <p>Clear timelines, quick proofs, and dependable delivery from our studio.</p>
        </div>
        <div class="feature-item">
            <h3>03 / Expertise</h3>
            <h4>Ideas Made Real.</h4>
            <p>From first sketch to finished stock, we help your work land with impact.</p>
        </div>
    </section>

    <!-- Bottom Info Footer Area -->
  <?php require_once __DIR__ . '\include\footer.php'; ?>

<?php require_once __DIR__ . '\include\function_request_quote.php'; ?>

</body>




</html>