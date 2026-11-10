<?php declare(strict_types=1);




require_once __DIR__ . '/Start_session.php';


/*
|--------------------------------------------------------------------------
| Destroy PHP Session
|--------------------------------------------------------------------------
| The customer_session database record is intentionally NOT deleted.
|--------------------------------------------------------------------------
*/

$_SESSION = [];

if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        '',
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;

