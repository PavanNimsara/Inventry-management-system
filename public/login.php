<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);


$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username_or_email = trim($_POST['username_or_email']);
    $password = $_POST['password'];

    if ($auth->login($username_or_email, $password)) {
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid username/email or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Inventory Management System</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="auth-container" style="text-align: center;">
        <img src="css/Monik.jpeg" alt="Monik Logo" class="login-logo" style="width: 80px; height: 80px; border-radius: 16px; margin-bottom: 1.5rem; object-fit: cover; border: 1px solid var(--panel-border); box-shadow: 0 0 15px var(--accent-glow);">
        <h2>Welcome Back</h2>
        <p class="subtitle">Please sign in to access your inventory dashboard</p>
        
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <input type="text" name="username_or_email" class="form-control" placeholder="Username or Email" required>
            </div>
            <div class="form-group">
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>
            <button type="submit" class="btn-primary">Sign In</button>
        </form>
        

    </div>
</body>
</html>