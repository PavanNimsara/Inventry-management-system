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

// Fetch total inventory items quantity
$totalItemsStmt = $db->query("SELECT SUM(quantity) FROM inventory_items");
$totalItems = intval($totalItemsStmt->fetchColumn());

// Fetch low stock items details (quantity below 5)
$lowStockStmt = $db->query("SELECT i.name, i.quantity, c.name as category_name FROM inventory_items i INNER JOIN inventory_categories c ON i.category_id = c.id WHERE i.quantity < 5 ORDER BY i.quantity ASC, i.name ASC");
$lowStockItems = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);
$lowStockCount = count($lowStockItems);

// Fetch total issues count and issues today
$totalIssuesStmt = $db->query("SELECT COUNT(*) FROM issued_items");
$totalIssues = intval($totalIssuesStmt->fetchColumn());

$issuesTodayStmt = $db->query("SELECT SUM(quantity) FROM issued_items WHERE DATE(issue_date) = CURDATE()");
$issuesToday = intval($issuesTodayStmt->fetchColumn());
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
    <!-- Theme Manager -->
    <script src="js/theme.js"></script>
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
                    <a href="profile.php" class="sidebar-item-link">
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
                        <p style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.5rem;"><?php echo number_format($totalItems); ?></p>
                        <span style="font-size: 0.85rem; color: var(--text-secondary);">
                            Total stock in warehouse
                        </span>
                    </div>
                    
                    <div class="stat-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                            <h3 style="color: #a855f7; font-size: 1.1rem; font-weight: 600;">Low Stock Alerts</h3>
                            <i class="fa-solid fa-triangle-exclamation" style="color: var(--error-color); font-size: 1.25rem;"></i>
                        </div>
                        <p style="font-size: 2.25rem; font-weight: 700; color: var(--error-color); margin-bottom: 0.5rem;"><?php echo $lowStockCount; ?></p>
                        <span style="font-size: 0.85rem; color: var(--text-secondary); display: block; margin-bottom: 0.5rem;">Quantity below 5 units</span>
                        
                        <?php if ($lowStockCount > 0): ?>
                            <div style="margin-top: 1rem; border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 0.75rem;">
                                <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem;">
                                    <?php foreach ($lowStockItems as $item): ?>
                                        <li style="font-size: 0.85rem; color: var(--text-primary); display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                                            <span style="display: inline-flex; align-items: center; gap: 0.45rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <i class="fa-solid fa-circle" style="color: var(--error-color); font-size: 0.45rem; flex-shrink: 0;"></i>
                                                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($item['name']); ?></span>
                                                <span style="font-size: 0.75rem; color: var(--text-secondary); flex-shrink: 0;">(<?php echo htmlspecialchars($item['category_name']); ?>)</span>
                                            </span>
                                            <strong style="color: var(--error-color); flex-shrink: 0;"><?php echo $item['quantity']; ?> left</strong>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                            <h3 style="color: #06b6d4; font-size: 1.1rem; font-weight: 600;">Recent Issues</h3>
                            <i class="fa-solid fa-arrow-right-to-bracket" style="color: #06b6d4; font-size: 1.25rem;"></i>
                        </div>
                        <p style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.5rem;"><?php echo number_format($totalIssues); ?></p>
                        <span style="font-size: 0.85rem; color: var(--success-color);">
                            <i class="fa-solid fa-circle-check"></i> <?php echo $issuesToday; ?> issues today
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