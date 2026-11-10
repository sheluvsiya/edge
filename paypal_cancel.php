<?php
require_once __DIR__ . '/Start_session.php';
require_once __DIR__ . '/include/functions.php';

// The customer cancelled on PayPal. Nothing was charged, and their cart is still saved.
flash('error', 'Payment was cancelled. Nothing was charged, and your cart is still saved.');
redirect('cart.php');
