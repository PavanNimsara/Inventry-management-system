<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Automatically run database migrations if columns are missing
try {
    $checkName = $db->query("SHOW COLUMNS FROM users LIKE 'name'");
    if ($checkName->rowCount() == 0) {
        $db->exec("ALTER TABLE users ADD COLUMN name VARCHAR(100) NULL AFTER username");
    }
    
    $checkPic = $db->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($checkPic->rowCount() == 0) {
        $db->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL AFTER password");
    }

    $uploadDir = 'uploads';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
} catch (Exception $e) {
    // Fail silently or log error
}

// Secure page access
$auth->restrictToLoggedIn();

$userId = $_SESSION['user_id'];
$user = $auth->getUserDetails($userId);

$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate password confirmation if typed
    if (!empty($password) && $password !== $confirm_password) {
        $message = "New password and confirmation do not match.";
        $messageType = "error";
    } else {
        $profilePicture = null;
        
        // Handle file upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['profile_picture']['tmp_name'];
            $fileName = $_FILES['profile_picture']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // Allowed extensions
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($fileExtension, $allowedExtensions)) {
                // Generate a unique filename
                $newFileName = 'profile_' . $userId . '_' . time() . '.' . $fileExtension;
                $uploadFileDir = './uploads/';
                
                if (!file_exists($uploadFileDir)) {
                    mkdir($uploadFileDir, 0777, true);
                }
                
                $destPath = $uploadFileDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $profilePicture = 'uploads/' . $newFileName;
                    // Delete old picture if exists and not default
                    if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                        @unlink($user['profile_picture']);
                    }
                } else {
                    $message = "There was an error moving the uploaded file.";
                    $messageType = "error";
                }
            } else {
                $message = "Invalid file type. Only JPG, PNG, and WEBP are allowed.";
                $messageType = "error";
            }
        }
        
        // Update profile if no error yet
        if ($messageType !== "error") {
            $result = $auth->updateProfile($userId, $name, $password, $profilePicture);
            if ($result === true) {
                $message = "Profile updated successfully!";
                $messageType = "success";
                // Refresh local user data
                $user = $auth->getUserDetails($userId);
            } else {
                $message = $result;
                $messageType = "error";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Profile - Inventory Management System</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Theme Manager -->
    <script src="js/theme.js"></script>
    <style>
        .profile-card-wrapper {
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--panel-border);
            padding: 2.5rem;
            border-radius: 24px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            margin-top: 1rem;
        }
        .avatar-preview-container {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent-color);
            box-shadow: 0 0 15px var(--accent-glow);
            background: rgba(255,255,255,0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: var(--text-secondary);
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <img src="css/Monik.jpeg" alt="Monik Logo" class="brand-logo">
                <span>Inventory Pro</span>
                <button id="sidebar-toggle" class="sidebar-toggle-btn"><i class="fa-solid fa-bars"></i></button>
            </div>

            <!-- Profile Info in Sidebar -->
            <div class="sidebar-profile">
                <?php if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" class="sidebar-avatar" alt="Avatar">
                <?php else: ?>
                    <div class="sidebar-avatar">
                        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="sidebar-name"><?php echo htmlspecialchars(!empty($user['name']) ? $user['name'] : $user['username']); ?></div>
                <div class="sidebar-role"><?php echo htmlspecialchars($user['role'] ?? 'Administrator'); ?></div>
            </div>

            <!-- Sidebar Navigation Links -->
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php" class="sidebar-item-link">
                        <i class="fa-solid fa-chart-line"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="inventory.php" class="sidebar-item-link">
                        <i class="fa-solid fa-box"></i> <span>Inventory Items</span>
                    </a>
                </li>
                <li>
                    <a href="categories.php" class="sidebar-item-link">
                        <i class="fa-solid fa-tags"></i> <span>Categories</span>
                    </a>
                </li>
                <li>
                    <a href="branches.php" class="sidebar-item-link">
                        <i class="fa-solid fa-store"></i> <span>Branches</span>
                    </a>
                </li>
                <li>
                    <a href="employees.php" class="sidebar-item-link">
                        <i class="fa-solid fa-users"></i> <span>Employees</span>
                    </a>
                </li>
                <li>
                    <a href="profile.php" class="sidebar-item-link active">
                        <i class="fa-solid fa-user-gear"></i> <span>Profile Settings</span>
                    </a>
                </li>
                <li>
                    <a href="issues_history.php" class="sidebar-item-link">
                        <i class="fa-solid fa-clock-rotate-left"></i> <span>Issues History</span>
                    </a>
                </li>
                <li class="sidebar-submenu-container">
                    <a href="#" class="sidebar-item-link" id="doc-mgmt-toggle">
                        <i class="fa-solid fa-file-invoice"></i> <span>Document Management</span> <i class="fa-solid fa-chevron-down submenu-chevron" style="margin-left:auto; font-size: 0.8rem;"></i>
                    </a>
                    <ul class="sidebar-submenu" id="doc-mgmt-submenu">
                        <li>
                            <a href="loan_applications.php" class="sidebar-item-link" id="loan-app-link">
                                <i class="fa-solid fa-hand-holding-dollar"></i> <span>Loan Application</span>
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>

            <div class="sidebar-footer">
                <div class="sidebar-theme-panel" style="padding-top: 1.25rem; border-top: 1px solid var(--panel-border); margin-bottom: 0.5rem; width: 100%;">
                    <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.75px; color: var(--text-secondary); margin-bottom: 0.75rem; display: block; padding-left: 0.25rem;">Theme Mode</span>
                    <div class="theme-switch-container">
                        <button class="theme-switch-btn" data-theme="light">
                            <span style="font-size: 0.95rem;">☀️</span> <span>Light</span>
                        </button>
                        <button class="theme-switch-btn" data-theme="dark">
                            <span style="font-size: 0.95rem;">🌙</span> <span>Dark</span>
                        </button>
                    </div>
                </div>
                <a href="logout.php" class="sidebar-item-link" style="color: var(--error-color);">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> <span>Logout</span>
                </a>
                <span class="sidebar-footer-copyright">All rights reserved.<br>Developed by Pawan</span>
            </div>
        </aside>

        <!-- Main Workspace Area -->
        <main class="main-content">
            <div class="main-content-header">
                <div>
                    <h2 style="margin-bottom: 0.25rem;">Profile Settings</h2>
                    <p class="subtitle" style="margin-bottom: 0;">Update your personal details, profile image, and passwords</p>
                </div>
            </div>

            <?php if(!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>" style="max-width: 600px;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="profile-card-wrapper">
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="avatar-preview-container">
                        <?php if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" class="avatar-large" alt="Avatar">
                        <?php else: ?>
                            <div class="avatar-large">
                                <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h4 style="margin-bottom: 0.25rem; font-size: 1.1rem;">Profile Picture</h4>
                            <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem;">Upload PNG, JPG, or WEBP images.</p>
                            <input type="file" id="profile_picture" name="profile_picture" class="form-control" accept="image/*" style="padding: 0.5rem;">
                        </div>
                    </div>

                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-secondary); font-weight: 600;">Display Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" placeholder="Enter display name" required>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-secondary);">Username (Read-only)</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled style="opacity: 0.6; cursor: not-allowed;">
                    </div>

                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-secondary);">Email Address (Read-only)</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled style="opacity: 0.6; cursor: not-allowed;">
                    </div>

                    <div style="border-top: 1px solid var(--panel-border); margin: 2rem 0; padding-top: 1.5rem;">
                        <h3 style="font-size: 1.15rem; margin-bottom: 1rem; color: var(--text-primary);">Security & Password</h3>
                        
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-secondary);">New Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password">
                        </div>
                        
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-secondary);">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Re-type new password">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" style="margin-top: 1rem;">Save Changes</button>
                </form>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const toggleBtn = document.getElementById("sidebar-toggle");
            const sidebar = document.querySelector(".sidebar");
            
            // Immediately apply state on load to prevent flicker
            if (localStorage.getItem("sidebar-collapsed") === "true") {
                sidebar.classList.add("collapsed");
            }
            
            if (toggleBtn) {
                toggleBtn.addEventListener("click", function() {
                    sidebar.classList.toggle("collapsed");
                    localStorage.setItem("sidebar-collapsed", sidebar.classList.contains("collapsed"));
                });
            }

            // Submenu toggle logic
            const docMgmtToggle = document.getElementById("doc-mgmt-toggle");
            const docMgmtSubmenu = document.getElementById("doc-mgmt-submenu");
            const submenuChevron = document.querySelector(".submenu-chevron");

            // Load submenu state
            if (localStorage.getItem("doc-mgmt-open") === "true") {
                docMgmtSubmenu.classList.add("show");
                if (submenuChevron) submenuChevron.classList.add("rotate");
            }

            if (docMgmtToggle) {
                docMgmtToggle.addEventListener("click", function(e) {
                    e.preventDefault();
                    docMgmtSubmenu.classList.toggle("show");
                    if (submenuChevron) submenuChevron.classList.toggle("rotate");
                    localStorage.setItem("doc-mgmt-open", docMgmtSubmenu.classList.contains("show"));
                });
            }
        });
    </script>
</body>
</html>
