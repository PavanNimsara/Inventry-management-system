<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Secure the page
$auth->restrictToLoggedIn();

$userId = $_SESSION['user_id'];
$user = $auth->getUserDetails($userId);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Inventory Management System</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <a href="dashboard.php" class="sidebar-item-link active">
                        <i class="fa-solid fa-chart-line"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="sidebar-item-link">
                        <i class="fa-solid fa-box"></i> <span>Inventory Items</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="sidebar-item-link">
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
                    <a href="profile.php" class="sidebar-item-link">
                        <i class="fa-solid fa-user-gear"></i> <span>Profile Settings</span>
                    </a>
                </li>
            </ul>

            <div class="sidebar-footer">
                <a href="logout.php" class="sidebar-item-link" style="color: var(--error-color);">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Workspace Area -->
        <main class="main-content">
            <div class="main-content-header">
                <div>
                    <h2 style="margin-bottom: 0.25rem;">Inventory Dashboard</h2>
                    <p class="subtitle" style="margin-bottom: 0;">Overview and real-time statistics of your warehouse</p>
                </div>
            </div>

            <section>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                            <h3 style="color: var(--accent-color); font-size: 1.1rem; font-weight: 600;">Total Items</h3>
                            <i class="fa-solid fa-box-open" style="color: var(--accent-color); font-size: 1.25rem;"></i>
                        </div>
                        <p style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.5rem;">1,248</p>
                        <span style="font-size: 0.85rem; color: var(--success-color);">
                            <i class="fa-solid fa-arrow-trend-up"></i> ↑ 12% this week
                        </span>
                    </div>
                    
                    <div class="stat-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                            <h3 style="color: #a855f7; font-size: 1.1rem; font-weight: 600;">Low Stock Alerts</h3>
                            <i class="fa-solid fa-triangle-exclamation" style="color: var(--error-color); font-size: 1.25rem;"></i>
                        </div>
                        <p style="font-size: 2.25rem; font-weight: 700; color: var(--error-color); margin-bottom: 0.5rem;">5</p>
                        <span style="font-size: 0.85rem; color: var(--text-secondary);">Items needing attention</span>
                    </div>
                    
                    <div class="stat-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                            <h3 style="color: #06b6d4; font-size: 1.1rem; font-weight: 600;">Recent Transactions</h3>
                            <i class="fa-solid fa-arrow-right-arrow-left" style="color: #06b6d4; font-size: 1.25rem;"></i>
                        </div>
                        <p style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.5rem;">34</p>
                        <span style="font-size: 0.85rem; color: var(--success-color);">
                            <i class="fa-solid fa-circle-check"></i> Transactions today
                        </span>
                    </div>
                </div>
            </section>
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
        });
    </script>
</body>
</html>