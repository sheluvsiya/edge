<?php
// Load the database connection (use the name of YOUR connection file,
require_once "Start_session.php";

//   review   -> the rating and the comment
//   customer -> the person's first name   (found using customer_id)
//   product  -> what they reviewed        (found using product_id)

$sql = "SELECT review.rating, review.comment, customer.first_name, product.product_name
        FROM review
        JOIN customer ON review.customer_id = customer.customer_id
        JOIN product ON review.product_id = product.product_id
        ORDER BY review.date_posted DESC
        LIMIT 3";


// Send the question to the database.
// $result now holds all the rows that came back.

$result = $conn->query($sql);


// Get the latest news item
$news_sql = "SELECT news_id, admin_id, content, image
             FROM news
             ORDER BY news_id DESC
             LIMIT 1";

$news_result = $conn->query($news_sql);

$latest_news = null;

if ($news_result && $news_result->num_rows > 0) {
    $latest_news = $news_result->fetch_assoc();
}

?>



<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/home_css.css">
  <link rel="stylesheet" href="css/navbar_css.css">
   <link rel="stylesheet" href="css/footer_css.css">
  <title>Home - 3D Edge</title>
</head>
<body>

<?php require_once __DIR__ . '\include\navbar.php'; ?>


<!-- Latest News Section -->

<section id="latest-news">

    <div class="latest-news-content">

        <div class="latest-news-heading">
            <p class="latest-news-eyebrow">Latest News</p>
            <h1>What's happening at 3D Edge</h1>
        </div>

        <?php if ($latest_news) { ?>

            <div class="latest-news-card">

                <div class="latest-news-image">

                    <?php if (!empty($latest_news['image'])) { ?>

                        <img
    src="<?php echo htmlspecialchars($latest_news['image']); ?>"
    alt="3D Edge news"
>

                    <?php } ?>

                </div>

                <div class="latest-news-info">

                    <div class="latest-news-label">
                        <span></span>
                        Latest Update
                    </div>

                    <div class="latest-news-text">
                        <p>
                            <?php echo nl2br(htmlspecialchars($latest_news['content'])); ?>
                        </p>
                    </div>

                </div>

            </div>

        <?php } else { ?>

            <div class="latest-news-card">

                <div class="latest-news-text">
                    <p>No news has been posted yet.</p>
                </div>

            </div>

        <?php } ?>

    </div>
    <div class="scroll-down">
    <a href="#what-we-do" aria-label="Scroll to What We Do">
        <span>Scroll to explore</span>
        <img src="icons/arrow-down.png" alt="">
    </a>
</div>

</section>  

<!-- What we do -->
<section class = "what-we-do" id="what-we-do">

  <div class = "what-we-do-content">

    <div class = "what-we-do-text">
      <p id = "what-we-do-eyebrow">What we do</p>
      <p id = "what-we-do-description">
        We provide complete design-to-production services including CAD design,
        reverse engineering, 3D scanning, rapid prototyping, and manufacturing
        using engineering-grade materials.
      </p>
    </div>

    <div class = "what-we-do-box">

      <a href = "request_quote.php" class = "what-we-do-cards">
        <img src = "icons/pen-tool.svg" alt = "">
        <h3>3D Design</h3>
        <p>CAD design, product development, mechanical design and design improvements.</p>
      </a>

      <a href = "request_quote.php" class = "what-we-do-cards">
        <img src = "icons/drafting-compass.svg" alt = "">
        <h3>Reverse Engineering</h3>
        <p>Recreate broken or discontinued parts, modify existing components and improve original designs.</p>
      </a>

      <a href = "products.php" class = "what-we-do-cards">
        <img src = "icons/printer.svg" alt = "">
        <h3>3D Printing</h3>
        <p>Engineering-grade materials for automotive parts, industrial components, functional prototypes and custom products.</p>
      </a>

    </div>

    <div class = "request-a-qoute">

      <div class = "request-a-qoute-heading">
        <h2>Not sure what your parts need?</h2>
        <p>Send photos, dimensions and the intended use. We'll recommend a sensible next step.</p>
      </div>

      <a href = "request_quote.php" class = "request-a-qoute-btn">Request a Quote <img src = "icons/arrow-up-right-dark.svg" alt = ""></a>

    </div>

  </div>

</section>

<!-- Material Display Section -->


<section class = "material-display">

  <div class = "material-content">

    <div class = "materials-text">
    <p id = "materials-eyebrow">Material Guide</p>
    <h2>The right material changes the result</h2>
    <p id = "materials-description">We match material and print approach to the part’s purpose, geometry and finish. Tell us where it will be used — we’ll help narrow the options. </p>
    
    <div class = "materials-note">
      <img src = "icons/info.svg">
     <p>Final suitability depends on the specific application and design.</p>
    </div>

    <div class = "materials-button">
    <a href = "materials.php" id = "materials-btn">More Materials<img src = "icons/arrow-up-right-dark.svg"></a>
    </div>

  </div>

    

    <div class = "materials-listing">

      <ul class = "material-li">
       
       <li>
        <div class="material-name">
          <p class="material-number">01</p>
          <h3>PLA</h3>
        </div>
        <div class="material-use">
          <p>Concept models & display pieces</p>
          <span class="material-dot"></span>
        </div>
      </li>

      <li>
        <div class="material-name">
          <p class="material-number">02</p>
          <h3>PETG</h3>
        </div>
        <div class="material-use">
          <p>Practical everyday components</p>
          <span class="material-dot"></span>
        </div>
      </li>

      <li>
        <div class="material-name">
          <p class="material-number">03</p>
          <h3>ABS / ASA</h3>
        </div>
        <div class="material-use">
          <p>Parts for demanding environments</p>
          <span class="material-dot"></span>
        </div>
      </li>

      <li>
        <div class="material-name">
          <p class="material-number">04</p>
          <h3>TPU</h3>
        </div>
        <div class="material-use">
          <p>Flexible covers, grips & seals</p>
          <span class="material-dot"></span>
        </div>
      </li>

      <li>
        <div class="material-name">
          <p class="material-number">05</p>
          <h3>NYLON</h3>
        </div>
        <div class="material-use">
          <p>Functional mechanical applications</p>
          <span class="material-dot"></span>
        </div>
      </li>

      <li class="material-last">
        <div class="material-name">
          <p class="material-number">06</p>
          <h3>CARBON FIBRE</h3>
        </div>
        <div class="material-use">
          <p>PETG-CF, ABS-CF & PPA-CF for stiff, strong parts</p>
          <span class="material-dot"></span>
        </div>
      </li>


      </ul>
  
    </div>



  </div>

</section>

<!-- Customer Reviews -->


  <section class="reviews">

    <div class="reviews-heading">

      <div class="reviews-title">
        <p id="reviews-eyebrow">Customer feedback</p>
        <h2>Built with care. Explained clearly.</h2>
      </div>

      

    </div>

    <div class="review-cards">

    <!-- If the database sent back 0 rows, show a message instead of cards -->
  <?php if ($result->num_rows == 0) { ?>
    <p>No reviews yet.</p>
  <?php } ?>

  <?php while ($row = $result->fetch_assoc()) { ?>

    <div class="review-card">
      <p class="quote-mark">“</p>

      <!-- Print the review text.
       htmlspecialchars makes anything a user typed safe to display -->
      <p class="quote"><?php echo htmlspecialchars($row['comment']); ?></p>
      <div>

      <!-- The customer's first name (from the customer table) -->
        <p class="customer"><?php echo htmlspecialchars($row['first_name']); ?></p>

        <!-- What they reviewed (from the product table) -->
        <p class="service"><?php echo htmlspecialchars($row['product_name']); ?></p>
      </div>

       <!-- Count from 0 up to the rating, printing one star each time.
                  A rating of 5 prints 5 stars, a rating of 4 prints 4. -->
      <div class="stars">
        <?php for ($i = 0; $i < $row['rating']; $i++) { ?>
          <img src="icons/star.svg" alt="">
        <?php } ?>
      </div>
    </div>

  <?php } ?>   <!---end of the loop: go back up and do the next review -->

</div>
    </div>

  </section>


<!-- Qoute and Contact(Home) -->

<section class="intake">

  <div class="intake-wrapper">

    <div class="intake-content">

      <div class="intake-left">

        <div class="intake-message">
          <p class="intake-eyebrow">Send the geometry</p>
          <h1>From file to finished part.</h1>
          <p class="intake-copy">Share your model, sketch or project folder. We’ll review what you have and help shape the clearest route to a physical result.</p>
        </div>

        <div class="intake-contact">

          <a href="https://wa.me/27662166924" class="contact-route">
            <div class="contact-icon"><img src="icons/message-circle.svg" alt=""></div>
            <div class="contact-text">
              <p class="contact-label">WhatsApp</p>
              <p class="contact-value">066 216 6924</p>
            </div>
            <img src="icons/arrow-up-right-grey.svg" alt="" class="contact-arrow">
          </a>

          <a href="mailto:info@3dedge.co.za" class="contact-route">
            <div class="contact-icon"><img src="icons/mail.svg" alt=""></div>
            <div class="contact-text">
              <p class="contact-label">Email</p>
              <p class="contact-value">info@3dedge.co.za</p>
            </div>
            <img src="icons/arrow-up-right-grey.svg" alt="" class="contact-arrow">
          </a>

          <p class="contact-help"><img src="icons/help-circle.svg" alt=""> Not sure what to upload? Send what you have — our team can help.</p>

        </div>

      </div>



    </div>

  </div>

</section>

<?php require_once __DIR__ . '\include\footer.php';?>


</body>
</html>