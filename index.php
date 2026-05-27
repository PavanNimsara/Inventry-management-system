<?php
require_once 'config/database.php';
require_once 'classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Check if the user is already logged in
if ($auth->isLoggedIn()) {
    // If logged in, check their role and send them to the correct dashboard
    if ($auth->getRole() === 'admin') {
        header("Location: public/admin_dashboard.php");
    } else {
        header("Location: public/dashboard.php");
    }
} else {
    // If not logged in, seamlessly redirect them to the login screen
    header("Location: public/login.php");
}
exit();
?>