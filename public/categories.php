<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Secure page access
$auth->restrictToLoggedIn();

$userId = $_SESSION['user_id'];
$user = $auth->getUserDetails($userId);

$message = "";
$messageType = "";

// Handle Adding a New Category
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'add_category') {
    $category_name = trim($_POST['category_name']);
    
    if (!empty($category_name)) {
        try {
            $insertQuery = "INSERT INTO inventory_categories (name) VALUES (:name)";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute([':name' => $category_name]);
            $message = "Category added successfully!";
            $messageType = "success";
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) { // Unique constraint violation
                $message = "Failed to add category: Category name already exists.";
            } else {
                $message = "Failed to add category: " . $e->getMessage();
            }
            $messageType = "error";
        }
    } else {
        $message = "Please enter a category name.";
        $messageType = "error";
    }
}

// Fetch all categories with statistics (total item types and total stock)
$categoriesQuery = "
    SELECT c.id, c.name, COUNT(i.id) as total_items, IFNULL(SUM(i.quantity), 0) as total_quantity
    FROM inventory_categories c
    LEFT JOIN inventory_items i ON c.id = i.category_id
    GROUP BY c.id, c.name
    ORDER BY c.name ASC
";
$categoriesStmt = $db->query($categoriesQuery);
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - Inventory Management System</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Theme Manager -->
    <script src="js/theme.js"></script>
    <style>
        .split-container {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .form-panel {
            flex: 1 1 350px;
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--panel-border);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
            height: fit-content;
        }
        .list-panel {
            flex: 2 2 550px;
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--panel-border);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
        }
        /* Custom Table Styling */
        .table-responsive {
            overflow-x: auto;
            margin-top: 1rem;
        }
        .category-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.95rem;
        }
        .category-table th {
            padding: 1rem;
            border-bottom: 2px solid var(--panel-border);
            color: var(--accent-color);
            font-weight: 600;
        }
        .category-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
        }
        .category-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .item-count-badge {
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #a5b4fc;
        }
        .stock-count-badge {
            background: rgba(168, 85, 247, 0.15);
            border: 1px solid rgba(168, 85, 247, 0.3);
            color: #d8b4fe;
            font-weight: 600;
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
                    <a href="categories.php" class="sidebar-item-link active">
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
            <div class="main-content-header" style="margin-bottom: 2rem;">
                <div>
                    <h2 style="margin-bottom: 0.25rem;">Inventory Categories</h2>
                    <p class="subtitle" style="margin-bottom: 0;">Add new classes and view statistics of warehouse items</p>
                </div>
            </div>

            <?php if(!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>" style="width:100%;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="split-container">
                <!-- Add Category Form Panel -->
                <div class="form-panel">
                    <h3 style="font-size: 1.2rem; margin-bottom: 1.25rem; color: var(--text-primary);"><i class="fa-solid fa-folder-plus" style="color:var(--accent-color); margin-right:0.5rem;"></i> Add New Category</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_category">
                        
                        <div class="form-group">
                            <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Category Name</label>
                            <input type="text" name="category_name" class="form-control" placeholder="e.g. SIM, Diary, Jacket" required style="padding: 0.8rem 1rem;">
                        </div>

                        <button type="submit" class="btn-primary" style="margin-top: 1rem; padding: 0.8rem;">Add Category</button>
                    </form>
                </div>

                <!-- Categories Directory Panel -->
                <div class="list-panel">
                    <h3 style="font-size: 1.2rem; margin-bottom: 0.5rem; color: var(--text-primary);">Categories Directory</h3>
                    <p style="font-size: 0.85rem; color: var(--text-secondary);">Currently registered inventory item categories</p>
                    
                    <div class="table-responsive">
                        <?php if (count($categories) > 0): ?>
                            <table class="category-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Category Name</th>
                                        <th>Registered Item Types</th>
                                        <th>Total Stock Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                        <tr>
                                            <td style="font-weight:700; color:var(--text-secondary);">#<?php echo $cat['id']; ?></td>
                                            <td style="font-weight:600; color:var(--text-primary);"><?php echo htmlspecialchars($cat['name']); ?></td>
                                            <td>
                                                <span class="badge item-count-badge"><?php echo $cat['total_items']; ?> Items</span>
                                            </td>
                                            <td>
                                                <span class="badge stock-count-badge"><?php echo $cat['total_quantity']; ?> Units</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align:center; padding: 3rem 0; color:var(--text-secondary);">
                                <i class="fa-solid fa-tags" style="font-size: 2.5rem; display:block; margin-bottom:0.75rem; color: rgba(255,255,255,0.1);"></i>
                                No categories found.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript to handle sidebar collapse -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const toggleBtn = document.getElementById("sidebar-toggle");
            const sidebar = document.querySelector(".sidebar");
            
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
