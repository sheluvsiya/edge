<?php declare(strict_types=1);

session_start();

$conn = new mysqli("localhost", "root", "Siyab0ng@", "3d_edge");

if ($conn->connect_error) {
    die("Connection error: " . $conn->connect_error);
}


/*
|--------------------------------------------------------------------------
| Check Existing Customer Session
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['auth_user']['customer_id'])) {

    $customerId = (int) $_SESSION['auth_user']['customer_id'];

    $stmt = $conn->prepare(
        "SELECT customer_session_duration
         FROM customer_session
         WHERE customer_id = ?
         ORDER BY customer_session_duration DESC
         LIMIT 1"
    );

    $stmt->bind_param("i", $customerId);
    $stmt->execute();

    $result = $stmt->get_result();
    $sessionData = $result->fetch_assoc();

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | No database session found
    |--------------------------------------------------------------------------
    */

    if (!$sessionData) {

        $_SESSION = [];
        session_destroy();

        header("Location: login.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Session Expired
    |--------------------------------------------------------------------------
    */

    if (strtotime($sessionData['customer_session_duration']) <= time()) {

        $_SESSION = [];
        session_destroy();

        header("Location: login.php");
        exit;
    }
}

?>

