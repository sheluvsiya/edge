<?php
declare(strict_types=1);
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';
require_login();
$user = auth_user();
?><!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Account | 3D Edge</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/auth.css"></head><body class="auth-body"><div class="container py-5"><div class="mx-auto" style="max-width:760px"><div class="auth-card"><img src="assets/images/3d-edge-logo.png" style="width:180px;margin-bottom:30px" alt="3D Edge"><h1 class="auth-title">Welcome, <?= e($user['username']) ?></h1><p class="auth-subtitle">Your account is signed in successfully.</p><div class="row g-3"><div class="col-md-6"><div class="p-3 rounded-3" style="background:#f4f7f5"><strong>Email</strong><br><?= e($user['email']) ?></div></div><div class="col-md-6"><div class="p-3 rounded-3" style="background:#f4f7f5"><strong>Role</strong><br><?= e($user['role']) ?></div></div></div><a class="btn btn-edge mt-4 d-flex align-items-center justify-content-center" href="logout.php">Sign out</a></div></div></div></body></html>
