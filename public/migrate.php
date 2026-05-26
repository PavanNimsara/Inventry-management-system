<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Add 'name' column if not exists
    $checkName = $db->query("SHOW COLUMNS FROM users LIKE 'name'");
    if ($checkName->rowCount() == 0) {
        $db->exec("ALTER TABLE users ADD COLUMN name VARCHAR(100) NULL AFTER username");
        echo "✓ Column 'name' added successfully.<br>";
    } else {
        echo "✓ Column 'name' already exists.<br>";
    }
    
    // Add 'profile_picture' column if not exists
    $checkPic = $db->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($checkPic->rowCount() == 0) {
        $db->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL AFTER password");
        echo "✓ Column 'profile_picture' added successfully.<br>";
    } else {
        echo "✓ Column 'profile_picture' already exists.<br>";
    }
    
    // Set default name for existing users if empty
    $db->exec("UPDATE users SET name = 'Admin User' WHERE username = 'admin_user' AND (name IS NULL OR name = '')");
    $db->exec("UPDATE users SET name = 'Regular User' WHERE username = 'regular_user' AND (name IS NULL OR name = '')");
    echo "✓ Default names updated for existing users.<br>";
    
    // Create uploads directory if not exists
    $uploadDir = 'uploads';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
        echo "✓ Uploads directory created.<br>";
    } else {
        echo "✓ Uploads directory already exists.<br>";
    }
    
    echo "<h3>Migration completed successfully!</h3>";
    echo "<p><a href='dashboard.php'>Go to Dashboard</a></p>";
} catch (Exception $e) {
    die("Error running migration: " . $e->getMessage());
}
?>
