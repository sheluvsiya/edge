<?php
// 1. Connect to the database
require_once __DIR__ . '/Start_session.php';

// 2. Fetch all images from the database
$result = $conn->query("SELECT * FROM gallery_img ORDER BY gallery_id ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $images[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery & Reviews - 3D Edge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/faq_gallery_css.css">
    <link rel="stylesheet" href="css/navbar_css.css">
    <link rel="stylesheet" href="css/footer_css.css">

</head>

<body>

    <!-- ================= HEADER ================= -->
    <?php require_once __DIR__ . '/include/navbar.php'; ?>
    <!-- ================= MAIN CONTENT ================= -->
    <main class="gallery-page">

        <!-- Page Header -->
        <section class="page-header">
            <h1>Reviews & <span class="highlight-box">Gallery</span></h1>
            <p>See what our clients are saying and explore some of the parts we've brought to life.</p>
        </section>

        <!-- ===== GOOGLE REVIEWS SECTION (I used WeWidget) ===== -->
        <section class="reviews-section">
            <h2>What Our Clients Say</h2>
            <p class="section-subtitle">Real reviews from our Google Business Profile.</p>

            <div class="wewidget-container">
                <!--WeWidget embed code -->
                <div data-review-widget="f26d76dc-0dc6-4068-a3eb-8e94b9af0b02"></div>
                <script src="https://app.wewidget.app/widget.js" data-widget-id="f26d76dc-0dc6-4068-a3eb-8e94b9af0b02"></script>
            </div>
        </section>

        <!-- ===== GALLERY SECTION ===== -->
        <section class="gallery-section">
            <h2>Project Gallery</h2>
            <p class="section-subtitle">Click any image to enlarge it.</p>

            <div class="gallery-grid">
                <!-- php loop -->
                <?php if (empty($images)): ?>
                    <p>No images found in the gallery yet.</p>
                <?php else: ?>
                    <?php foreach ($images as $image): ?>
                        <a href="#gallery-<?php echo $image['gallery_id']; ?>" class="gallery-item">
                            <img src="<?php echo htmlspecialchars($image['gallery_image_url']); ?>" alt="<?php echo htmlspecialchars($image['gallery_alt_text']); ?>">
                            <div class="gallery-overlay"><span>Enlarge</span></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

    </main>

    <!-- ================= PURE CSS LIGHTBOX MODALS ================= -->
    <!-- This is how the image is enlarged  -->
    <?php foreach ($images as $image): ?>
        <div id="gallery-<?php echo $image['gallery_id']; ?>" class="lightbox">
            <a href="#" class="lightbox-close">&times;</a> <!--The X at the corner. the X HTML entity is &times;-->
            <div class="lightbox-content">
                <img src="<?php echo htmlspecialchars($image['gallery_image_url']); ?>" alt="<?php echo htmlspecialchars($image['gallery_alt_text']); ?>">
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ================= FOOTER ================= -->
        <?php require_once __DIR__ . '/include/footer.php'; ?>


</body>
</html>