    <!-- ================= Up to date HELP.PHP ================= -->

<?php
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/reCaptcha_config.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get in Touch - 3D Edge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/faq_gallery_css.css">
    <link rel="stylesheet" href="css/navbar_css.css">
    <link rel="stylesheet" href="css/footer_css.css">
    <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars(RECAPTCHA_SITE_KEY) ?>"></script>
</head>

<body>

    <!-- ================= HEADER ================= -->
    <?php require_once __DIR__ . '/include/navbar.php'; ?>

    <!-- ================= MAIN CONTENT ================= -->
    <main class="get-in-touch-page">

        <!-- Page Header -->
        <section class="page-header">
            <h1>Get in <span class="highlight-box">touch</span></h1>
            <p>Have questions about our services? Need help with your project? We're here to help!</p>
        </section>

        <!-- Two Column Layout inspiration comes from https://takdesigns.co.za/contact-->
        <div class="contact-layout">

            <!-- Left Column: Info Cards -->
            <div class="contact-left">

                <!-- Contact Info Card -->
                <div class="info-card">
                    <h3>Contact Information</h3>
                    <p class="card-subtitle">Reach out to us through any of these channels</p>

                    <div class="info-item">
                        <div class="icon-box">✉️</div>
                        <div>
                            <strong>Email</strong>
                            <p><a href="mailto:info@3dedge.co.za">info@3dedge.co.za</a></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="icon-box">🕒</div>
                        <div>
                            <strong>Business Hours</strong>
                            <p>Mon-Fri: 8:00 AM – 4:00 PM</p>
                            <p>Sat: 10:00 AM – 12:00 PM</p>
                        </div>
                    </div>
                </div>

                <!-- Google Maps Card -->
                <div class="info-card map-card">
                    <h3>Location</h3>
                    <p class="card-subtitle">Visit our workshop</p>

                    <!-- OpenStreetMap Embed -->
                    <div class="map-wrapper">
                        <iframe
                            src="https://www.openstreetmap.org/export/embed?bbox=28.077954053878784%2C-26.037882970997423%2C28.079871833324432%2C-26.03651168689486&amp;layer=mapnik"
                            style="border: 0;">
                        </iframe>
                        <div class="map-link">
                            <a href="https://www.openstreetmap.org/#map=19/-26.037197/28.078913" target="_blank">View
                                Larger Map &rarr;</a>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Column: Contact Form -->
            <div class="contact-right">
                <div class="form-card">

                    <?php if (isset($_GET['status']) && $_GET['status'] === 'success'): ?>
                        <p style="color: #a3e635; font-weight: 600; margin-bottom: 16px;">✅ Your message has been sent!</p>
                    <?php elseif (isset($_GET['status']) && $_GET['status'] === 'error'): ?>
                        <p style="color: #ff4d4d; font-weight: 600; margin-bottom: 16px;">❌ Something went wrong. Please try again.</p>
                    <?php endif; ?>

                    <h3>Send us a Message</h3>
                    <p class="card-subtitle">Fill out the form below and we'll get back to you soon</p>

                    <form action="send_email.php" method="POST" class="contact-form" id="contact-form">
                        <input type="hidden" name="recaptcha_token" id="recaptcha_token">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="name">Your Name *</label>
                                <input type="text" id="name" name="name" placeholder="John Doe" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email Address *</label>
                                <input type="email" id="email" name="email" placeholder="john@example.com" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="subject">Subject *</label>
                            <select id="subject" name="subject" required>
                                <option value="" disabled selected>What can we help you with?</option>
                                <option value="general">General Inquiry</option>
                                <option value="quote">Request a Quote</option>
                                <option value="support">Technical Support</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="message">Message *</label>
                            <textarea id="message" name="message" rows="6"
                                placeholder="Tell us more about your project or question..." required></textarea>
                        </div>

  <div class="form-group">
                           <!--  <div class="g-recaptcha" data-sitekey="6Lex-eQtAAAAAM6AnGfBEnw8b09i9imqiL83Tz_L"></div> -->
                        </div>

                        <button type="submit" class="submit-btn">Send Message</button>
                    </form>
                </div>
            </div>

        </div>

        <!-- FAQ Section -->
        <section class="faq-section-bottom">
            <h2>Frequently Asked Questions</h2>
            <p class="faq-subtitle">Find answers to common questions about our services</p>

            <div class="faq-list">
                <details>
                    <summary>
                        <span class="faq-icon">?</span>
                        What file formats do you accept?
                    </summary>
                    <p>We accept STL, OBJ, STEP, IGES, and most standard CAD file formats.</p>
                </details>

                <details>
                    <summary>
                        <span class="faq-icon">?</span>
                        How long does printing take?
                    </summary>
                    <p>Standard turnaround is 3-5 business days depending on the size and complexity of the part.</p>
                </details>

                <details>
                    <summary>
                        <span class="faq-icon">?</span>
                        What's your minimum order value?
                    </summary>
                    <p>There is no minimum order value. We are happy to print one-off parts and prototypes.</p>
                </details>

                <details>
                    <summary>
                        <span class="faq-icon">?</span>
                        Do you offer design services?
                    </summary>
                    <p>Yes, we offer complete CAD design and reverse engineering services.</p>
                </details>

                <details>
                    <summary>
                        <span class="faq-icon">?</span>
                        What materials are available?
                    </summary>
                    <p>We offer a wide range of engineering-grade plastics including PLA, ABS, PETG, TPU, and Nylon.</p>
                </details>
            </div>
        </section>

    </main>

    <!-- ================= FOOTER ================= -->
    <?php require_once __DIR__ . '/include/footer.php'; ?>


        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('contact-form');
            if (!form) return;

            form.addEventListener('submit', function (e) {
            e.preventDefault();

            grecaptcha.ready(function () {
                grecaptcha.execute('<?= htmlspecialchars(RECAPTCHA_SITE_KEY) ?>', { action: 'contact_form' })
                    .then(function (token) {
                        document.getElementById('recaptcha_token').value = token;
                        form.submit();
                    });
            });
            });
        });
    </script>
</body>

</html>