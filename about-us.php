<?php
require_once "Start_session.php";


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
  <title>About-us- 3D Edge</title>
</head>
<body>

   <?php require_once __DIR__ . '\include\navbar.php'; ?>

    <section id = "about-us">

    <div id="about-story">

      <div id="story-text">
        <p class="eyebrow">Our story</p>
        <h2 class="about-title">The edge between “what if” and “here it is.”</h2>
        <p id="story-lead">3D Edge exists to make complex physical problems easier to understand, develop and produce.</p>
        <p class="story-body">3D Edge was founded to solve one of the biggest challenges faced by vehicle owners, workshops, and manufacturers—finding replacement parts that are discontinued or impossible to source.</p>
        <p class="story-body">Using advanced CAD software and professional 3D printing technologies, we manufacture custom components that meet demanding applications while also helping inventors and businesses develop new products from concept to production.</p>

        <div id="story-principle">
          <p id="principle-number">01</p>
          <p>Listen closely. Choose the right route. Make the outcome useful.</p>
        </div>
      </div>

      <div id="story-image">
        <img src="3d_edge_image/3d Edge About-us.jpg" alt="3D Image">
        <div id="story-caption">
          <p id="caption-title">A practical mindset</p>
          <p>Every decision tied to the intended use.</p>
        </div>
      </div>

    </div>


    <div id="about-values">

      <div id="values-text">
        <p class="eyebrow_about">What guides us</p>
        <h2 class="about-title2">Care, made visible in the details.</h2>
        <p id="values-intro">The best outcome is not simply accurate. It makes sense for the person, part and problem in front of us.</p>
      </div>

      <div id="values-list">

        <div class="value">
          <div class="value-icon"><img src="icons/crosshair.svg" alt=""></div>
          <h3>Precision</h3>
          <p>We pay attention to the geometry, tolerances and details that shape a dependable result.</p>
        </div>

        <div class="value">
          <div class="value-icon"><img src="icons/wrench.svg" alt=""></div>
          <h3>Practical problem-solving</h3>
          <p>We start with what the part needs to do—not with a predetermined process.</p>
        </div>

        <div class="value">
          <div class="value-icon"><img src="icons/messages-square.svg" alt=""></div>
          <h3>Collaboration</h3>
          <p>Clear conversations and shared context keep decisions useful from first brief to handover.</p>
        </div>

        <div class="value">
          <div class="value-icon"><img src="icons/badge-check.svg" alt=""></div>
          <h3>Quality</h3>
          <p>We care about fit, finish and whether the final outcome is right for its intended use.</p>
        </div>

      </div>

    </div>

  </section>


<br>
<br>
<br>
<br>
<br>
<br>

  
<?php require_once __DIR__ . '\include\footer.php';?>
  
</body>
</html>