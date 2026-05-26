<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

echo "<h2>Database Diagnostics</h2>";
echo "<p><a href='?fix_passwords=1' style='display:inline-block; padding: 8px 16px; background-color: #6366f1; color: white; border-radius: 6px; text-decoration: none; font-weight: bold;'>Click here to automatically fix and set passwords to 'password123'</a></p><br>";

try {
    $database = new Database();
    $db = $database->getConnection();
    echo "<p style='color:green;'>✓ Connection to database server successful.</p>";
    
    // Quick fix mechanism
    if (isset($_GET['fix_passwords'])) {
        $new_hash = password_hash('password123', PASSWORD_DEFAULT);
        $updateStmt = $db->prepare("UPDATE users SET password = :hash WHERE username IN ('admin_user', 'regular_user')");
        $updateStmt->execute([':hash' => $new_hash]);
        echo "<p style='color:blue; font-weight:bold;'>🔄 Passwords for admin_user and regular_user updated to 'password123' using the fresh hash: $new_hash</p>";
    }
    
    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green;'>✓ Table 'users' exists.</p>";
        
        // Count users
        $stmtUsers = $db->query("SELECT id, username, email, password FROM users");
        $users = $stmtUsers->fetchAll();
        echo "<p>Total users in table: " . count($users) . "</p>";
        
        if (count($users) > 0) {
            echo "<h3>User Verification List:</h3>";
            echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
            echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Password Hash</th><th>Test 'password123'</th></tr>";
            foreach ($users as $user) {
                $verify = password_verify('password123', $user['password']) ? "<span style='color:green;'>Correct ('password123')</span>" : "<span style='color:red;'>Incorrect</span>";
                echo "<tr>";
                echo "<td>{$user['id']}</td>";
                echo "<td>" . htmlspecialchars($user['username']) . "</td>";
                echo "<td>" . htmlspecialchars($user['email']) . "</td>";
                echo "<td><code>" . htmlspecialchars($user['password']) . "</code></td>";
                echo "<td>{$verify}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Generate a fresh hash for password123
            $fresh_hash = password_hash('password123', PASSWORD_DEFAULT);
            echo "<h3>Generate Fresh Hash for 'password123':</h3>";
            echo "<p>Copy this hash: <code>" . htmlspecialchars($fresh_hash) . "</code></p>";
        } else {
            echo "<p style='color:orange;'>⚠ No users found in the table. Try registering a new user first or running the database.sql insert statement.</p>";
        }
    } else {
        echo "<p style='color:red;'>✗ Table 'users' does not exist in database 'inventry_management_system'. Make sure you imported database.sql.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Error during diagnostics: " . $e->getMessage() . "</p>";
}
?>
