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

// Database Migration & Seeding for Inventory
try {
    // Create categories table
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    
    // Create inventory items table
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        date DATE NOT NULL,
        quantity INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES inventory_categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    
    // Seed default categories if table is empty
    $checkCats = $db->query("SELECT COUNT(*) FROM inventory_categories");
    if ($checkCats->fetchColumn() == 0) {
        $categoriesToSeed = ["T-shirt", "Bag", "jacket-bottom", "Diary", "SIM"];
        $stmtSeed = $db->prepare("INSERT INTO inventory_categories (name) VALUES (:name)");
        foreach ($categoriesToSeed as $catName) {
            $stmtSeed->execute([':name' => $catName]);
        }
    }
} catch (Exception $e) {
    $migrationError = "Setup error: " . $e->getMessage();
}

$message = "";
$messageType = "";

// Handle Adding a New Inventory Item
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'add_item') {
    $category_id = intval($_POST['category_id']);
    $item_name = trim($_POST['item_name']);
    $item_date = $_POST['item_date'];
    $item_quantity = intval($_POST['item_quantity']);
    
    if ($category_id > 0 && !empty($item_name) && !empty($item_date) && $item_quantity >= 0) {
        try {
            $insertQuery = "INSERT INTO inventory_items (category_id, name, date, quantity) VALUES (:category_id, :name, :date, :quantity)";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute([
                ':category_id' => $category_id,
                ':name' => $item_name,
                ':date' => $item_date,
                ':quantity' => $item_quantity
            ]);
            $message = "Inventory item added successfully!";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Failed to add inventory item: " . $e->getMessage();
            $messageType = "error";
        }
    } else {
        $message = "Please fill in all the fields correctly.";
        $messageType = "error";
    }
}

// Handle Restocking an Existing Inventory Item
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'restock_item') {
    $item_id = intval($_POST['restock_item_id']);
    $restock_quantity = intval($_POST['restock_quantity']);
    $restock_date = $_POST['restock_date'];
    
    if ($item_id > 0 && $restock_quantity > 0 && !empty($restock_date)) {
        try {
            $updateQuery = "UPDATE inventory_items SET quantity = quantity + :quantity, date = :date WHERE id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([
                ':quantity' => $restock_quantity,
                ':date' => $restock_date,
                ':id' => $item_id
            ]);
            $message = "Inventory item restocked successfully!";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Failed to restock inventory item: " . $e->getMessage();
            $messageType = "error";
        }
    } else {
        $message = "Please fill in all the fields correctly.";
        $messageType = "error";
    }
}

// Fetch all categories for form & filter dropdowns
$categoriesStmt = $db->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
$categories = $categoriesStmt->fetchAll();

// --- Filtration and Search logic ---
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_category = isset($_GET['filter_category']) ? intval($_GET['filter_category']) : 0;

// Pagination setup
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// SQL builders
$whereClauses = ["1=1"];
$queryParams = [];

if (!empty($search)) {
    $whereClauses[] = "i.name LIKE :search";
    $queryParams[':search'] = "%$search%";
}
if ($filter_category > 0) {
    $whereClauses[] = "i.category_id = :filter_category";
    $queryParams[':filter_category'] = $filter_category;
}

$whereSQL = implode(" AND ", $whereClauses);

// Count total matching items
$countQuery = "SELECT COUNT(*) FROM inventory_items i 
               INNER JOIN inventory_categories c ON i.category_id = c.id 
               WHERE $whereSQL";
$countStmt = $db->prepare($countQuery);
$countStmt->execute($queryParams);
$total_rows = $countStmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch paginated inventory items
$dataQuery = "SELECT i.*, c.name as category_name 
              FROM inventory_items i 
              INNER JOIN inventory_categories c ON i.category_id = c.id 
              WHERE $whereSQL 
              ORDER BY i.date DESC, i.name ASC 
              LIMIT :limit OFFSET :offset";

$dataStmt = $db->prepare($dataQuery);
foreach ($queryParams as $param => $val) {
    $dataStmt->bindValue($param, $val);
}
$dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$items = $dataStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Items - Inventory Management System</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Theme Manager -->
    <script src="js/theme.js"></script>
    <style>
        .page-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .filter-panel {
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--panel-border);
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        .filter-form {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .list-panel-full {
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--panel-border);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
            width: 100%;
        }
        .custom-select {
            appearance: none;
            -webkit-appearance: none;
            background: rgba(255, 255, 255, 0.04) url("data:image/svg+xml;utf8,<svg fill='white' height='24' viewBox='0 0 24 24' width='24' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/><path d='M0 0h24v24H0z' fill='none'/></svg>") no-repeat right 12px center;
            background-size: 20px;
            padding-right: 40px;
        }
        .custom-select option {
            background-color: #121421;
            color: #ffffff;
        }
        
        /* Modal Popup Styling */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(9, 10, 16, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
        }
        .modal.show {
            display: flex;
        }
        .modal-content {
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            padding: 2.5rem;
            border-radius: 24px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
            position: relative;
            animation: scaleIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        .close-btn {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            color: var(--text-secondary);
            font-size: 1.75rem;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .close-btn:hover {
            color: #ffffff;
        }
        @keyframes scaleIn {
            from {
                transform: scale(0.9);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        /* Custom Table Styling */
        .table-responsive {
            overflow-x: auto;
            margin-top: 1rem;
        }
        .item-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.95rem;
        }
        .item-table th {
            padding: 1rem;
            border-bottom: 2px solid var(--panel-border);
            color: var(--accent-color);
            font-weight: 600;
        }
        .item-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
        }
        .clickable-item-name {
            cursor: pointer;
            color: var(--accent-color);
            transition: color 0.2s ease;
        }
        .clickable-item-name:hover {
            color: #ffffff;
            text-decoration: underline;
        }
        .item-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .category-badge {
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #a5b4fc;
        }
        .qty-badge {
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
                    <a href="inventory.php" class="sidebar-item-link active">
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
                <span class="sidebar-footer-copyright">All rights reserved.<br>Developed by Pawan</span>
            </div>
        </aside>

        <!-- Main Workspace Area -->
        <main class="main-content">
            <div class="page-header-row">
                <div>
                    <h2 style="margin-bottom: 0.25rem;">Inventory Items</h2>
                    <p class="subtitle" style="margin-bottom: 0;">Add and manage stock items in the warehouse</p>
                </div>
                <button id="open-modal-btn" class="btn-primary" style="width: auto; padding: 0.85rem 1.75rem; display: inline-flex; align-items: center; gap: 0.5rem; margin-top:0;">
                    <i class="fa-solid fa-plus"></i> Add Inventory Item
                </button>
            </div>

            <?php if(isset($migrationError)): ?>
                <div class="alert alert-error" style="width:100%;">
                    <?php echo htmlspecialchars($migrationError); ?>
                </div>
            <?php endif; ?>

            <?php if(!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>" style="width:100%;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Filtration / Search Row -->
            <div class="filter-panel">
                <form method="GET" action="" class="filter-form">
                    <div style="flex: 2 1 200px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Search Item Name</label>
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by item name...">
                    </div>
                    <div style="flex: 1 1 150px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Category Filter</label>
                        <select name="filter_category" class="form-control custom-select">
                            <option value="0">All Categories</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex; gap: 0.5rem;">
                        <a href="inventory.php" class="btn-logout" style="padding:0.85rem 1.5rem; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none;"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                    </div>
                </form>
            </div>

            <!-- Inventory Directory Panel -->
            <div class="list-panel-full">
                <h3 style="font-size: 1.25rem; margin-bottom: 0.5rem; color: var(--text-primary);">Inventory Directory</h3>
                <p style="font-size: 0.85rem; color: var(--text-secondary);">List of items registered in the warehouse</p>
                
                <div class="table-responsive">
                    <?php if(count($items) > 0): ?>
                        <table class="item-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Last Updated</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($items as $item): ?>
                                    <tr>
                                        <td style="font-weight:700; color:var(--text-secondary);">#<?php echo $item['id']; ?></td>
                                        <td class="clickable-item-name" data-id="<?php echo $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['name']); ?>" data-category="<?php echo htmlspecialchars($item['category_name']); ?>" data-qty="<?php echo $item['quantity']; ?>"><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td>
                                            <span class="badge category-badge"><?php echo htmlspecialchars($item['category_name']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge qty-badge"><?php echo htmlspecialchars($item['quantity']); ?></span>
                                        </td>
                                        <td style="font-size:0.9rem; color:var(--text-secondary);"><?php echo htmlspecialchars($item['date']); ?></td>
                                        <td style="text-align: right;">
                                            <button class="open-restock-btn btn-primary" data-id="<?php echo $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['name']); ?>" style="width: auto; padding: 0.45rem 1rem; font-size: 0.8rem; margin-top: 0; background: var(--accent-gradient); border-radius: 8px; box-shadow: none;">
                                                <i class="fa-solid fa-plus-circle"></i> Restock
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Pagination Navigation -->
                        <?php if($total_pages > 1): ?>
                            <div class="pagination-container" style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; border-top: 1px solid var(--panel-border); padding-top:1.25rem;">
                                <div style="font-size:0.85rem; color:var(--text-secondary);">
                                    Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> items
                                </div>
                                <div class="pagination-buttons" style="display:flex; gap:0.35rem;">
                                    <?php if($page > 1): ?>
                                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&filter_category=<?php echo $filter_category; ?>" class="btn-logout" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none;"><i class="fa-solid fa-angle-left"></i> Prev</a>
                                    <?php endif; ?>
                                    
                                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&filter_category=<?php echo $filter_category; ?>" class="<?php echo $i == $page ? 'btn-profile' : 'btn-logout'; ?>" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none; display:inline-flex; align-items:center;"><?php echo $i; ?></a>
                                    <?php endfor; ?>
                                    
                                    <?php if($page < $total_pages): ?>
                                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&filter_category=<?php echo $filter_category; ?>" class="btn-logout" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none;">Next <i class="fa-solid fa-angle-right"></i></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="text-align:center; padding: 5rem 0; color:var(--text-secondary);">
                            <i class="fa-solid fa-box-open" style="font-size: 3rem; display:block; margin-bottom:1rem; color: rgba(255,255,255,0.1);"></i>
                            No inventory items found.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Item Modal -->
    <div id="add-item-modal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="close-modal-btn">&times;</span>
            <h3 style="font-size: 1.35rem; margin-bottom: 1.5rem; color: var(--text-primary); border-bottom: 1px solid var(--panel-border); padding-bottom: 0.75rem;">Add Inventory Item</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_item">
                
                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Category</label>
                    <select name="category_id" class="form-control custom-select" required>
                        <option value="" disabled selected>Select Category...</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Item Name</label>
                    <input type="text" name="item_name" class="form-control" placeholder="e.g. Monik Branded Blue Tee" required>
                </div>

                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Date</label>
                    <input type="date" name="item_date" class="form-control" required>
                </div>

                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Quantity</label>
                    <input type="number" name="item_quantity" class="form-control" min="0" placeholder="e.g. 50" required>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 1.5rem; padding: 0.8rem;">Add Item</button>
            </form>
        </div>
    </div>

    <!-- Restock Item Modal -->
    <div id="restock-item-modal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="close-restock-modal-btn">&times;</span>
            <h3 style="font-size: 1.35rem; margin-bottom: 1.5rem; color: var(--text-primary); border-bottom: 1px solid var(--panel-border); padding-bottom: 0.75rem;">Restock <span id="restock-item-name" style="color: var(--accent-color);"></span></h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="restock_item">
                <input type="hidden" id="restock-item-id" name="restock_item_id">
                
                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Quantity to Add</label>
                    <input type="number" name="restock_quantity" class="form-control" min="1" placeholder="e.g. 50" required>
                </div>

                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Restock Date</label>
                    <input type="date" name="restock_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 1.5rem; padding: 0.8rem;">Confirm Restock</button>
            </form>
        </div>
    </div>

    <!-- View Item Details & Issuances Modal -->
    <div id="view-item-modal" class="modal">
        <div class="modal-content" style="max-width: 750px;">
            <span class="close-btn" id="close-view-modal-btn">&times;</span>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--panel-border); padding-bottom: 0.75rem; flex-wrap: wrap; gap: 0.75rem;">
                <h3 style="font-size: 1.35rem; color: var(--text-primary); margin: 0;">Item Details & Issuances</h3>
                <a id="export-item-pdf-btn" href="#" target="_blank" class="btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; text-decoration: none; width: auto; margin-top: 0; box-shadow: none; border-radius: 8px; margin-right: 2.2rem;">
                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                </a>
            </div>

            <!-- Item Info Block -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; background: rgba(255,255,255,0.02); padding: 1.25rem; border-radius: 12px; border: 1px solid var(--panel-border);">
                <div>
                    <span style="display:block; font-size:0.8rem; color: var(--text-secondary);">Item Name</span>
                    <strong id="view-item-name" style="color:#ffffff;"></strong>
                </div>
                <div>
                    <span style="display:block; font-size:0.8rem; color: var(--text-secondary);">Category</span>
                    <strong id="view-item-category" style="color:var(--accent-color);"></strong>
                </div>
                <div>
                    <span style="display:block; font-size:0.8rem; color: var(--text-secondary);">In-Stock Quantity</span>
                    <strong id="view-item-qty" style="color:#ffffff;"></strong>
                </div>
            </div>

            <!-- Issued Employees Table -->
            <h4 style="font-size:1.1rem; color: var(--text-primary); margin-bottom: 0.75rem;">Issued Employees</h4>
            <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                <table class="item-table" id="view-issued-table">
                    <thead>
                        <tr>
                            <th>EMP NO</th>
                            <th>Calling Name</th>
                            <th>Designation</th>
                            <th>Company & Branch</th>
                            <th>Qty Issued</th>
                            <th>Issue Date</th>
                        </tr>
                    </thead>
                    <tbody id="view-issued-tbody">
                        <!-- AJAX populated -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- JavaScript to handle modal toggles and sidebar collapse -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Sidebar layout toggle
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

            // Modal elements and logic
            const addModal = document.getElementById("add-item-modal");
            const openAddModalBtn = document.getElementById("open-modal-btn");
            const closeAddModalBtn = document.getElementById("close-modal-btn");
            
            const restockModal = document.getElementById("restock-item-modal");
            const closeRestockModalBtn = document.getElementById("close-restock-modal-btn");

            if (openAddModalBtn) {
                openAddModalBtn.addEventListener("click", () => {
                    addModal.classList.add("show");
                });
            }
            if (closeAddModalBtn) {
                closeAddModalBtn.addEventListener("click", () => {
                    addModal.classList.remove("show");
                });
            }

            // Restock Modal action triggers
            const restockBtns = document.querySelectorAll(".open-restock-btn");
            restockBtns.forEach(btn => {
                btn.addEventListener("click", function(e) {
                    e.stopPropagation();
                    const itemId = this.dataset.id;
                    const itemName = this.dataset.name;
                    
                    document.getElementById("restock-item-id").value = itemId;
                    document.getElementById("restock-item-name").textContent = itemName;
                    
                    restockModal.classList.add("show");
                });
            });

            if (closeRestockModalBtn) {
                closeRestockModalBtn.addEventListener("click", () => {
                    restockModal.classList.remove("show");
                });
            }

            const viewModal = document.getElementById("view-item-modal");
            const closeViewModalBtn = document.getElementById("close-view-modal-btn");

            // Handle Click Item Name to View Issuance details
            const clickableItems = document.querySelectorAll(".clickable-item-name");
            clickableItems.forEach(cell => {
                cell.addEventListener("click", function() {
                    const itemId = this.dataset.id;
                    const itemName = this.dataset.name;
                    const itemCat = this.dataset.category;
                    const itemQty = this.dataset.qty;

                    // Set details in Modal
                    document.getElementById("view-item-name").textContent = itemName;
                    document.getElementById("view-item-category").textContent = itemCat;
                    document.getElementById("view-item-qty").textContent = itemQty;

                    // Set PDF export link
                    document.getElementById("export-item-pdf-btn").href = 'export_item_pdf.php?item_id=' + itemId;

                    // Clear and load dynamic item issuances via AJAX
                    const tbody = document.getElementById("view-issued-tbody");
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding: 1.5rem 0; color:var(--text-secondary);">Loading issuances...</td></tr>';

                    viewModal.classList.add("show");

                    fetch('get_item_issuances.php?item_id=' + itemId)
                        .then(response => response.json())
                        .then(data => {
                            tbody.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(issue => {
                                    const row = document.createElement('tr');
                                    row.innerHTML = `
                                        <td style="font-weight:700; color:var(--text-secondary);">${escapeHtml(issue.emp_no)}</td>
                                        <td style="font-weight:600;">${escapeHtml(issue.calling_name)}</td>
                                        <td>${escapeHtml(issue.designation)}</td>
                                        <td style="font-size:0.9rem;">${escapeHtml(issue.company_name)} (${escapeHtml(issue.branch_name)})</td>
                                        <td><span class="badge qty-badge">${issue.quantity}</span></td>
                                        <td style="font-size:0.9rem; color:var(--text-secondary);">${issue.issue_date}</td>
                                    `;
                                    tbody.appendChild(row);
                                });
                            } else {
                                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding: 2rem 0; color:var(--text-secondary);"><i class="fa-solid fa-box-open" style="display:block; font-size:2rem; margin-bottom:0.5rem; opacity:0.2;"></i> No employee has been issued this item yet.</td></tr>';
                            }
                        })
                        .catch(err => {
                            console.error('Error fetching item issuances:', err);
                            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding: 1.5rem 0; color:var(--error-color);">Failed to load issuances.</td></tr>';
                        });
                });
            });

            if (closeViewModalBtn) {
                closeViewModalBtn.addEventListener("click", () => {
                    viewModal.classList.remove("show");
                });
            }

            // Helper to escape HTML safely in JS
            function escapeHtml(text) {
                return text
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }

            // Close modals when clicking outside contents
            window.addEventListener("click", (event) => {
                if (event.target === addModal) {
                    addModal.classList.remove("show");
                }
                if (event.target === restockModal) {
                    restockModal.classList.remove("show");
                }
                if (event.target === viewModal) {
                    viewModal.classList.remove("show");
                }
            });

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

            // Live filtering for Category select option
            const filterCategorySelect = document.querySelector(".filter-panel select[name='filter_category']");
            if (filterCategorySelect) {
                filterCategorySelect.addEventListener("change", function() {
                    const form = this.closest("form");
                    if (form) {
                        form.submit();
                    }
                });
            }
        });
    </script>
</body>
</html>
