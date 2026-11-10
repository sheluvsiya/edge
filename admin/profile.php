<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

$uid = (int)($adminUser['user_id'] ?? 0);
$details = db_fetch_one('SELECT user_id, username, email, phone_num, role, account_status, created_at, last_login FROM `user` WHERE user_id = ? LIMIT 1', [$uid]);
$category = admin_category();
$categoryLabel = ADMIN_TYPE_LABELS[$category] ?? 'Super';
$initials = strtoupper(substr((string)($details['username'] ?? 'A'), 0, 1));

$tab = (string)($_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview', 'details', 'access'], true)) { $tab = 'overview'; }

// What this category can do, page by page (for the Access tab).
$pages = [
    'dashboard' => 'Dashboard', 'products' => 'Products', 'materials' => 'Materials', 'orders' => 'Orders',
    'quotes' => 'Quote requests', 'reviews' => 'Reviews', 'news' => 'News', 'gallery' => 'Gallery',
    'users' => 'Users & admins',
];

admin_header('My profile');
?>
<div class="profile-shell">
  <aside class="profile-nav">
    <div class="profile-nav-head">Account</div>
    <nav>
      <a href="profile.php?tab=overview" class="<?= $tab === 'overview' ? 'active' : '' ?>"><i class="mdi mdi-account-circle-outline"></i> Account overview</a>
      <a href="profile.php?tab=details" class="<?= $tab === 'details' ? 'active' : '' ?>"><i class="mdi mdi-card-account-details-outline"></i> Personal information</a>
      <a href="profile.php?tab=access" class="<?= $tab === 'access' ? 'active' : '' ?>"><i class="mdi mdi-shield-key-outline"></i> Access &amp; category</a>
    </nav>
    <div class="profile-help">
      <h4>Need a hand?</h4>
      <p>Ask a Super administrator to change your category.</p>
    </div>
    <a class="profile-logout" href="../logout.php"><i class="mdi mdi-logout"></i> Log out</a>
  </aside>

  <section class="profile-main">
    <?php if ($tab === 'overview'): ?>
      <p class="profile-eyebrow">Your admin space</p>
      <h2 class="profile-hello">Welcome back, <?= e((string)($details['username'] ?? 'admin')) ?>.</h2>
      <p class="profile-lede">Your details, category and access for the 3D EDGE administration area.</p>

      <div class="profile-card-user">
        <div class="profile-avatar"><?= e($initials) ?></div>
        <div class="profile-user-text">
          <div class="profile-fullname"><?= e((string)($details['username'] ?? 'Administrator')) ?></div>
          <div class="profile-email"><?= e((string)($details['email'] ?? '')) ?></div>
          <div class="profile-tags">
            <span class="profile-tag">Administrator</span>
            <span class="profile-tag profile-tag-lime"><?= e($categoryLabel) ?></span>
          </div>
        </div>
      </div>

      <div class="profile-stats">
        <div><span>Last login</span><strong><?= e((string)($details['last_login'] ?? 'Not recorded')) ?></strong></div>
        <div><span>Account status</span><strong><?= e(admin_label((string)($details['account_status'] ?? 'unknown'))) ?></strong></div>
        <div><span>Member since</span><strong><?= e(!empty($details['created_at']) ? date('d M Y', strtotime((string)$details['created_at'])) : '—') ?></strong></div>
      </div>

    <?php elseif ($tab === 'details'): ?>
      <p class="profile-eyebrow">Your details</p>
      <h2 class="profile-hello">Personal information</h2>
      <p class="profile-lede">These details come from your account. Ask a Super administrator if anything needs changing.</p>
      <?php if (!$details): ?>
        <div class="alert alert-warning">Could not load your account details.</div>
      <?php else: ?>
      <dl class="profile-dl">
        <dt>User ID</dt><dd><?= (int)$details['user_id'] ?></dd>
        <dt>Username</dt><dd><?= e((string)$details['username']) ?></dd>
        <dt>Email</dt><dd><?= e((string)$details['email']) ?></dd>
        <dt>Phone</dt><dd><?= e((string)($details['phone_num'] ?? 'Not provided')) ?></dd>
        <dt>Role</dt><dd><?= e(ucfirst((string)$details['role'])) ?></dd>
        <dt>Category</dt><dd><?= e($categoryLabel) ?></dd>
        <dt>Account created</dt><dd><?= e((string)($details['created_at'] ?? '—')) ?></dd>
      </dl>
      <?php endif; ?>

    <?php else: ?>
      <p class="profile-eyebrow">Your access</p>
      <h2 class="profile-hello">Access &amp; category</h2>
      <p class="profile-lede">You can see every page. Your category decides which pages you can change.</p>
      <div class="access-list">
        <?php foreach ($pages as $section => $label):
          $can = $category === 'user' ? true : admin_can_write($section);
          if ($section === 'dashboard') { $can = false; }
        ?>
          <div class="access-row">
            <span><?= e($label) ?></span>
            <?php if ($can): ?>
              <span class="access-pill can">Can edit</span>
            <?php else: ?>
              <span class="access-pill">View only</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php admin_footer(); ?>
