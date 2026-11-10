<?php
require_once __DIR__ . '/include/reCaptcha_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// 1. Sanitize and validate input
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if (empty($name) || empty($email) || empty($message) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: help.php?status=error');
    exit;
}

// ==========================================
// 2. Verify Google reCAPTCHA (v3)
// ==========================================
$recaptcha_token = $_POST['recaptcha_token'] ?? '';

if (empty($recaptcha_token)) {
    header('Location: help.php?status=error#contact-form');
    exit;
}

$verify_url = 'https://www.google.com/recaptcha/api/siteverify';

$response = file_get_contents($verify_url . '?' . http_build_query([
    'secret'   => RECAPTCHA_SECRET_KEY,
    'response' => $recaptcha_token,
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
]));

$result = json_decode($response, true);

if (
    empty($result['success']) ||
    ($result['action'] ?? '') !== 'contact_form' ||
    ($result['score'] ?? 0) < 0.5
) {
    error_log('reCAPTCHA failed: ' . json_encode($result));
    header('Location: help.php?status=error#contact-form');
    exit;
}

// Escape user content to prevent HTML injection
$safeName    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail   = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
$safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

// 3. Configure PHPMailer
$mail = new PHPMailer(true);

try {
    // --- SMTP Server Settings ---
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';      // Your SMTP server
    $mail->SMTPAuth   = true;
    $mail->Username   = 'yomelelatiyani@gmail.com'; // Your SMTP username
    $mail->Password   = 'vbqrbflpycvjxzyu';    // Your SMTP App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // --- Email Content ---
    $mail->setFrom('yomelelatiyani@gmail.com', '3D Edge Website');
    $mail->addAddress('yomelelatiyani@gmail.com', '3D Edge Inbox');
    $mail->addReplyTo($email, $name);

    $mail->isHTML(true);
    $mail->Subject = "New Contact Form: {$safeSubject}";
    $mail->Body    = "
        <h3>New Message from 3D Edge Contact Form</h3>
        <p><strong>Name:</strong> {$safeName}</p>
        <p><strong>Email:</strong> {$safeEmail}</p>
        <p><strong>Subject:</strong> {$safeSubject}</p>
        <hr>
        <p><strong>Message:</strong></p>
        <p>{$safeMessage}</p>
    ";
    $mail->AltBody = "Name: {$name}\nEmail: {$email}\nSubject: {$subject}\n\nMessage:\n{$message}";

    // 4. Send the email
    $mail->send();

    // 5. Redirect back with a success message
    header('Location: help.php?status=success#contact-form');
    exit;

} catch (Exception $e) {
    error_log("PHPMailer Error: {$mail->ErrorInfo}");
    header('Location: help.php?status=error#contact-form');
    exit;
}