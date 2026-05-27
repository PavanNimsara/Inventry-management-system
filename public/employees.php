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

// Database Migration for Employees and Issued Items
try {
    // Create employees table
    $db->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        emp_no VARCHAR(50) NOT NULL UNIQUE,
        full_name VARCHAR(255) NOT NULL,
        calling_name VARCHAR(100) NOT NULL,
        designation VARCHAR(100) NOT NULL,
        joining_date DATE NOT NULL,
        nic_number VARCHAR(50) NOT NULL UNIQUE,
        mobile_number VARCHAR(20) NOT NULL,
        mail_address VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Create issued_items table
    $db->exec("CREATE TABLE IF NOT EXISTS issued_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        item_id INT NOT NULL,
        quantity INT NOT NULL,
        issue_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    $migrationError = "Setup error: " . $e->getMessage();
}

$message = "";
$messageType = "";

// Handle Adding a New Employee
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'add_employee') {
    $branch_id = intval($_POST['branch_id']);
    $emp_no = trim($_POST['emp_no']);
    $full_name = trim($_POST['full_name']);
    $calling_name = trim($_POST['calling_name']);
    $designation = trim($_POST['designation']);
    $joining_date = $_POST['joining_date'];
    $nic_number = trim($_POST['nic_number']);
    $mobile_number = trim($_POST['mobile_number']);
    $mail_address = trim($_POST['mail_address']);
    
    // Prefix configurations
    $companyPrefixes = [
        "Commercial Micro Credit" => "CMC",
        "Monik International Pvt Ltd" => "MNK",
        "Ceylon Monik Building Society Limited" => "CMB",
        "Monik Homes Pvt Ltd" => "MNH",
        "Monik Water Pvt Ltd" => "MNW",
        "Monik Trading Pvt LTD" => "MNT",
        "Monik Agro Pvt Ltd" => "MNA",
        "Monik Land" => "MNL"
    ];
    
    if (
        $branch_id > 0 && !empty($emp_no) && !empty($full_name) && !empty($calling_name) && 
        !empty($designation) && !empty($joining_date) && !empty($nic_number) && 
        !empty($mobile_number) && !empty($mail_address)
    ) {
        // Fetch company name to validate prefix
        $compQuery = "SELECT c.name FROM branches b INNER JOIN companies c ON b.company_id = c.id WHERE b.id = :branch_id LIMIT 1";
        $compStmt = $db->prepare($compQuery);
        $compStmt->execute([':branch_id' => $branch_id]);
        $companyName = $compStmt->fetchColumn();
        
        $expectedPrefix = isset($companyPrefixes[$companyName]) ? $companyPrefixes[$companyName] : '';
        
        if ($expectedPrefix && strpos($emp_no, $expectedPrefix) !== 0) {
            $message = "Invalid EMP NO. For $companyName, it must start with '$expectedPrefix'.";
            $messageType = "error";
        } else {
            try {
                $insertQuery = "INSERT INTO employees (branch_id, emp_no, full_name, calling_name, designation, joining_date, nic_number, mobile_number, mail_address) 
                                VALUES (:branch_id, :emp_no, :full_name, :calling_name, :designation, :joining_date, :nic_number, :mobile_number, :mail_address)";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->execute([
                    ':branch_id' => $branch_id,
                    ':emp_no' => $emp_no,
                    ':full_name' => $full_name,
                    ':calling_name' => $calling_name,
                    ':designation' => $designation,
                    ':joining_date' => $joining_date,
                    ':nic_number' => $nic_number,
                    ':mobile_number' => $mobile_number,
                    ':mail_address' => $mail_address
                ]);
                $message = "Employee added successfully!";
                $messageType = "success";
            } catch (PDOException $e) {
                if ($e->errorInfo[1] == 1062) { // Duplicate key
                    if (strpos($e->getMessage(), 'emp_no') !== false) {
                        $message = "Failed to add employee: EMP NO already exists.";
                    } elseif (strpos($e->getMessage(), 'nic_number') !== false) {
                        $message = "Failed to add employee: NIC Number already exists.";
                    } else {
                        $message = "Failed to add employee: Duplicate key error.";
                    }
                } else {
                    $message = "Failed to add employee: " . $e->getMessage();
                }
                $messageType = "error";
            }
        }
    } else {
        $message = "Please fill in all the fields.";
        $messageType = "error";
    }
}

// Handle Issuing Inventory Items to Employee
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'issue_item') {
    $employee_id = intval($_POST['issue_employee_id']);
    $item_id = intval($_POST['issue_item_id']);
    $quantity = intval($_POST['issue_quantity']);
    $issue_date = $_POST['issue_date'];
    
    if ($employee_id > 0 && $item_id > 0 && $quantity > 0 && !empty($issue_date)) {
        try {
            // Check available stock in warehouse
            $stockStmt = $db->prepare("SELECT name, quantity FROM inventory_items WHERE id = :id");
            $stockStmt->execute([':id' => $item_id]);
            $item = $stockStmt->fetch();
            
            if (!$item) {
                $message = "Item does not exist in inventory.";
                $messageType = "error";
            } elseif ($item['quantity'] < $quantity) {
                $message = "Insufficient stock for '{$item['name']}'. Available quantity: {$item['quantity']}.";
                $messageType = "error";
            } else {
                $db->beginTransaction();
                
                // Deduct stock from inventory
                $deductStmt = $db->prepare("UPDATE inventory_items SET quantity = quantity - :qty WHERE id = :id");
                $deductStmt->execute([':qty' => $quantity, ':id' => $item_id]);
                
                // Log issuance record
                $issueStmt = $db->prepare("INSERT INTO issued_items (employee_id, item_id, quantity, issue_date) VALUES (:employee_id, :item_id, :quantity, :issue_date)");
                $issueStmt->execute([
                    ':employee_id' => $employee_id,
                    ':item_id' => $item_id,
                    ':quantity' => $quantity,
                    ':issue_date' => $issue_date
                ]);
                
                $db->commit();
                $message = "Inventory item successfully issued to employee!";
                $messageType = "success";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $message = "Failed to issue item: " . $e->getMessage();
            $messageType = "error";
        }
    } else {
        $message = "Please fill in all details correctly.";
        $messageType = "error";
    }
}

// Fetch all companies for filters and dropdowns
$companiesStmt = $db->query("SELECT id, name FROM companies ORDER BY name ASC");
$companies = $companiesStmt->fetchAll();

// Fetch active inventory categories for selection dropdown
$categoriesStmt = $db->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
$inventory_categories = $categoriesStmt->fetchAll();


// --- Filtration and Search logic ---
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_company = isset($_GET['filter_company']) ? intval($_GET['filter_company']) : 0;
$filter_branch = isset($_GET['filter_branch']) ? intval($_GET['filter_branch']) : 0;

// Fetch branches for filter options if company is selected
$filterBranches = [];
if ($filter_company > 0) {
    $fbStmt = $db->prepare("SELECT id, name FROM branches WHERE company_id = :company_id ORDER BY name ASC");
    $fbStmt->execute([':company_id' => $filter_company]);
    $filterBranches = $fbStmt->fetchAll();
}

// Pagination setup
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// SQL builders
$whereClauses = ["1=1"];
$queryParams = [];

if (!empty($search)) {
    $whereClauses[] = "(e.emp_no LIKE :search OR e.full_name LIKE :search OR e.calling_name LIKE :search OR e.designation LIKE :search OR e.nic_number LIKE :search)";
    $queryParams[':search'] = "%$search%";
}
if ($filter_company > 0) {
    $whereClauses[] = "b.company_id = :filter_company";
    $queryParams[':filter_company'] = $filter_company;
}
if ($filter_branch > 0) {
    $whereClauses[] = "e.branch_id = :filter_branch";
    $queryParams[':filter_branch'] = $filter_branch;
}

$whereSQL = implode(" AND ", $whereClauses);

// Count total matching employees
$countQuery = "SELECT COUNT(*) FROM employees e 
               INNER JOIN branches b ON e.branch_id = b.id 
               INNER JOIN companies c ON b.company_id = c.id 
               WHERE $whereSQL";
$countStmt = $db->prepare($countQuery);
$countStmt->execute($queryParams);
$total_rows = $countStmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch paginated employees
$dataQuery = "SELECT e.*, b.name as branch_name, c.name as company_name 
              FROM employees e 
              INNER JOIN branches b ON e.branch_id = b.id 
              INNER JOIN companies c ON b.company_id = c.id 
              WHERE $whereSQL 
              ORDER BY e.emp_no ASC 
              LIMIT :limit OFFSET :offset";

$dataStmt = $db->prepare($dataQuery);
foreach ($queryParams as $param => $val) {
    $dataStmt->bindValue($param, $val);
}
$dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$employees = $dataStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees - Inventory Management System</title>
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
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            max-height: 70vh;
            overflow-y: auto;
            padding-right: 0.5rem;
        }
        .form-grid-full {
            grid-column: span 2;
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
            background: #151726;
            border: 1px solid var(--panel-border);
            padding: 2.5rem;
            border-radius: 24px;
            max-width: 650px;
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
        .employee-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        .employee-table th {
            padding: 1rem;
            border-bottom: 2px solid var(--panel-border);
            color: var(--accent-color);
            font-weight: 600;
        }
        .employee-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            white-space: nowrap;
        }
        .employee-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .company-badge {
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #a5b4fc;
        }
        .branch-badge {
            background: rgba(168, 85, 247, 0.15);
            border: 1px solid rgba(168, 85, 247, 0.3);
            color: #d8b4fe;
        }
        .clickable-name {
            cursor: pointer;
            text-decoration: underline;
            color: #818cf8;
            transition: color 0.2s ease;
        }
        .clickable-name:hover {
            color: #a5b4fc;
        }
        .btn-issue {
            padding: 0.4rem 0.75rem;
            background: var(--accent-gradient);
            color: #ffffff;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            cursor: pointer;
            border: none;
            transition: transform 0.2s ease;
        }
        .btn-issue:hover {
            transform: translateY(-1px);
            filter: brightness(1.1);
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
                    <a href="employees.php" class="sidebar-item-link active">
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
            <div class="page-header-row">
                <div>
                    <h2 style="margin-bottom: 0.25rem;">Employee Registry</h2>
                    <p class="subtitle" style="margin-bottom: 0;">Organize and filter staff members across Monik companies</p>
                </div>
                <button id="open-modal-btn" class="btn-primary" style="width: auto; padding: 0.85rem 1.75rem; display: inline-flex; align-items: center; gap: 0.5rem; margin-top:0;">
                    <i class="fa-solid fa-user-plus"></i> Add Employee
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
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Search Keyword</label>
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by EMP NO, name, designation, NIC...">
                    </div>
                    <div style="flex: 1 1 150px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Company Filter</label>
                        <select id="filter-company-select" name="filter_company" class="form-control custom-select">
                            <option value="0">All Companies</option>
                            <?php foreach($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>" <?php echo $filter_company == $company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($company['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1 1 150px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Branch Filter</label>
                        <select id="filter-branch-select" name="filter_branch" class="form-control custom-select" <?php echo $filter_company == 0 ? 'disabled' : ''; ?>>
                            <option value="0">All Branches</option>
                            <?php if($filter_company > 0): ?>
                                <?php foreach($filterBranches as $fb): ?>
                                    <option value="<?php echo $fb['id']; ?>" <?php echo $filter_branch == $fb['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($fb['name']); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div style="display:flex; gap: 0.5rem;">
                        <button type="submit" class="btn-primary" style="margin-top:0; padding: 0.85rem 1.5rem; font-size:0.95rem; width:auto; display:inline-flex; align-items:center; gap:0.5rem;"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                        
                        <?php 
                        $exportUrl = "export_employees.php?search=" . urlencode($search) . "&filter_company=" . $filter_company . "&filter_branch=" . $filter_branch;
                        ?>
                        <a href="<?php echo $exportUrl; ?>" class="btn-profile" style="padding:0.85rem 1.5rem; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; background: linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
                        
                        <a href="employees.php" class="btn-logout" style="padding:0.85rem 1.5rem; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none;"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                    </div>
                </form>
            </div>

            <!-- Employee Directory Table Panel -->
            <div class="list-panel-full">
                <h3 style="font-size: 1.25rem; margin-bottom: 0.5rem; color: var(--text-primary);">Employee Directory</h3>
                <p style="font-size: 0.85rem; color: var(--text-secondary);">Click on an employee's name to view their issued inventory details</p>
                
                <div class="table-responsive">
                    <?php if(count($employees) > 0): ?>
                        <table class="employee-table">
                            <thead>
                                <tr>
                                    <th>EMP NO</th>
                                    <th>Calling Name</th>
                                    <th>Full Name</th>
                                    <th>Designation</th>
                                    <th>Company / Branch</th>
                                    <th>Mobile</th>
                                    <th>Email</th>
                                    <th>Join Date</th>
                                    <th style="text-align: center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($employees as $emp): ?>
                                    <tr>
                                        <td style="font-weight:700; color: var(--accent-color);"><?php echo htmlspecialchars($emp['emp_no']); ?></td>
                                        <td class="clickable-name" data-id="<?php echo $emp['id']; ?>" data-name="<?php echo htmlspecialchars($emp['calling_name']); ?>" data-fullname="<?php echo htmlspecialchars($emp['full_name']); ?>" data-empno="<?php echo htmlspecialchars($emp['emp_no']); ?>" data-desig="<?php echo htmlspecialchars($emp['designation']); ?>" data-comp="<?php echo htmlspecialchars($emp['company_name']); ?>" data-branch="<?php echo htmlspecialchars($emp['branch_name']); ?>" data-email="<?php echo htmlspecialchars($emp['mail_address']); ?>" data-mobile="<?php echo htmlspecialchars($emp['mobile_number']); ?>" data-nic="<?php echo htmlspecialchars($emp['nic_number']); ?>" data-date="<?php echo htmlspecialchars($emp['joining_date']); ?>">
                                            <strong><?php echo htmlspecialchars($emp['calling_name']); ?></strong>
                                        </td>
                                        <td style="color:var(--text-secondary);"><?php echo htmlspecialchars($emp['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($emp['designation']); ?></td>
                                        <td>
                                            <span class="badge company-badge" style="margin-bottom:0.25rem; display:block; text-align:center;">
                                                <?php echo htmlspecialchars($emp['company_name']); ?>
                                            </span>
                                            <span class="badge branch-badge" style="display:block; text-align:center;">
                                                <?php echo htmlspecialchars($emp['branch_name']); ?>
                                            </span>
                                        </td>
                                        <td style="font-size:0.85rem; color: var(--text-secondary);"><?php echo htmlspecialchars($emp['mobile_number']); ?></td>
                                        <td style="font-size:0.85rem; color: var(--text-secondary);"><?php echo htmlspecialchars($emp['mail_address']); ?></td>
                                        <td style="font-size:0.85rem; color: var(--text-secondary);"><?php echo htmlspecialchars($emp['joining_date']); ?></td>
                                        <td style="text-align: center;">
                                            <button class="btn-issue open-issue-btn" data-id="<?php echo $emp['id']; ?>" data-name="<?php echo htmlspecialchars($emp['calling_name']); ?>">
                                                <i class="fa-solid fa-hand-holding"></i> Issue Item
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
                                    Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> employees
                                </div>
                                <div class="pagination-buttons" style="display:flex; gap:0.35rem;">
                                    <?php if($page > 1): ?>
                                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&filter_company=<?php echo $filter_company; ?>&filter_branch=<?php echo $filter_branch; ?>" class="btn-logout" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none;"><i class="fa-solid fa-angle-left"></i> Prev</a>
                                    <?php endif; ?>
                                    
                                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&filter_company=<?php echo $filter_company; ?>&filter_branch=<?php echo $filter_branch; ?>" class="<?php echo $i == $page ? 'btn-profile' : 'btn-logout'; ?>" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none; display:inline-flex; align-items:center;"><?php echo $i; ?></a>
                                    <?php endfor; ?>
                                    
                                    <?php if($page < $total_pages): ?>
                                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&filter_company=<?php echo $filter_company; ?>&filter_branch=<?php echo $filter_branch; ?>" class="btn-logout" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none;">Next <i class="fa-solid fa-angle-right"></i></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="text-align:center; padding: 5rem 0; color:var(--text-secondary);">
                            <i class="fa-solid fa-users-slash" style="font-size: 3rem; display:block; margin-bottom:1rem; color: rgba(255,255,255,0.1);"></i>
                            No employees found matching the criteria.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Employee Form Modal -->
    <div id="add-employee-modal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="close-add-modal-btn">&times;</span>
            <h3 style="font-size: 1.35rem; margin-bottom: 1.5rem; color: var(--text-primary); border-bottom: 1px solid var(--panel-border); padding-bottom: 0.75rem;">Add New Employee</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_employee">
                
                <div class="form-grid">
                    <div class="form-group form-grid-full">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Company</label>
                        <select id="company-select" class="form-control custom-select" required>
                            <option value="" disabled selected>Select Company...</option>
                            <?php foreach($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group form-grid-full">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Branch</label>
                        <select id="branch-select" name="branch_id" class="form-control custom-select" required disabled>
                            <option value="" disabled selected>Select Branch (choose company first)...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">EMP NO</label>
                        <input type="text" name="emp_no" class="form-control" placeholder="e.g. EMP001" required style="padding: 0.8rem 1rem;">
                    </div>

                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Calling Name</label>
                        <input type="text" name="calling_name" class="form-control" placeholder="e.g. John" required style="padding: 0.8rem 1rem;">
                    </div>

                    <div class="form-group form-grid-full">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Full Name</label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. John Doe Smith" required style="padding: 0.8rem 1rem;">
                    </div>

                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Designation</label>
                        <input type="text" name="designation" class="form-control" placeholder="e.g. Branch Manager" required style="padding: 0.8rem 1rem;">
                    </div>

                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Joining Date</label>
                        <input type="date" name="joining_date" class="form-control" required style="padding: 0.7rem 1rem;">
                    </div>

                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">NIC Number</label>
                        <input type="text" name="nic_number" class="form-control" placeholder="e.g. 199012345678" required style="padding: 0.8rem 1rem;">
                    </div>

                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Mobile Number</label>
                        <input type="text" name="mobile_number" class="form-control" placeholder="e.g. 0771234567" required style="padding: 0.8rem 1rem;">
                    </div>

                    <div class="form-group form-grid-full">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Mail Address</label>
                        <input type="email" name="mail_address" class="form-control" placeholder="e.g. john@monik.com" required style="padding: 0.8rem 1rem;">
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 1.5rem; padding: 0.8rem;">Add Employee</button>
            </form>
        </div>
    </div>

    <!-- Issue Inventory Item Modal -->
    <div id="issue-item-modal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span class="close-btn" id="close-issue-modal-btn">&times;</span>
            <h3 style="font-size: 1.35rem; margin-bottom: 1.5rem; color: var(--text-primary); border-bottom: 1px solid var(--panel-border); padding-bottom: 0.75rem;">Issue Stock to <span id="issue-employee-name" style="color: var(--accent-color);"></span></h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="issue_item">
                <input type="hidden" id="issue-employee-id" name="issue_employee_id">
                
                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Select Category</label>
                    <select id="issue-category-select" class="form-control custom-select" required>
                        <option value="" disabled selected>Select Category...</option>
                        <?php foreach($inventory_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Select Inventory Item</label>
                    <select id="issue-item-select" name="issue_item_id" class="form-control custom-select" required disabled>
                        <option value="" disabled selected>Select Category First...</option>
                    </select>
                </div>

                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Quantity to Issue</label>
                    <input type="number" name="issue_quantity" class="form-control" min="1" placeholder="e.g. 5" required>
                </div>

                <div class="form-group">
                    <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Issue Date</label>
                    <input type="date" name="issue_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 1.5rem; padding: 0.8rem;">Confirm Issue</button>
            </form>
        </div>
    </div>

    <!-- View Employee Details & Issued Items Modal -->
    <div id="view-employee-modal" class="modal">
        <div class="modal-content" style="max-width: 750px;">
            <span class="close-btn" id="close-view-modal-btn">&times;</span>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--panel-border); padding-bottom: 0.75rem; flex-wrap: wrap; gap: 0.75rem;">
                <h3 style="font-size: 1.35rem; color: var(--text-primary); margin: 0;">Employee Profile & Issued Items</h3>
                <a id="export-pdf-btn" href="#" target="_blank" class="btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; text-decoration: none; width: auto; margin-top: 0; box-shadow: none; border-radius: 8px; margin-right: 2.2rem;">
                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                </a>
            </div>
            
            <!-- Employee Info Block -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; background: rgba(255,255,255,0.02); padding: 1.25rem; border-radius: 12px; border: 1px solid var(--panel-border);">
                <div>
                    <span style="display:block; font-size:0.8rem; color: var(--text-secondary);">Full Name</span>
                    <strong id="view-emp-fullname" style="color:#ffffff;"></strong>
                </div>
                <div>
                    <span style="display:block; font-size:0.8rem; color: var(--text-secondary);">EMP NO</span>
                    <strong id="view-emp-no" style="color:var(--accent-color);"></strong>
                </div>
                <div>
                    <span style="display:block; font-size:0.8rem; color: var(--text-secondary);">Designation</span>
                    <strong id="view-emp-desig" style="color:#ffffff;"></strong>
                </div>
                <div>
                    <span style="display:block; font-size:0.8rem; color: var(--text-secondary);">Company / Branch</span>
                    <strong id="view-emp-company" style="color:#ffffff; font-size: 0.9rem;"></strong>
                </div>
                <div>
                    <span style="display:block; font-size:0.8rem; color: var(--text-secondary);">NIC Number</span>
                    <span id="view-emp-nic" style="color:#ffffff;"></span>
                </div>
                <div>
                    <span style="display:block; font-size:0.8rem; color: var(--text-secondary);">Contact Details</span>
                    <span id="view-emp-contact" style="color:#ffffff; font-size:0.85rem; display:block;"></span>
                </div>
            </div>

            <!-- Issued Items Table -->
            <h4 style="font-size:1.1rem; color: var(--text-primary); margin-bottom: 0.75rem;">Issued Inventory Items</h4>
            <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                <table class="employee-table" id="view-issued-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Item Name</th>
                            <th>Issued Qty</th>
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

    <!-- JavaScript to handle dynamic filters, modals, and AJAX branch/issues loading -->
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

            // Modal elements
            const addModal = document.getElementById("add-employee-modal");
            const issueModal = document.getElementById("issue-item-modal");
            const viewModal = document.getElementById("view-employee-modal");
            
            const openAddBtn = document.getElementById("open-modal-btn");
            const closeAddBtn = document.getElementById("close-add-modal-btn");
            const closeIssueBtn = document.getElementById("close-issue-modal-btn");
            const closeViewBtn = document.getElementById("close-view-modal-btn");

            // Open Add Modal
            if (openAddBtn) {
                openAddBtn.addEventListener("click", () => addModal.classList.add("show"));
            }
            if (closeAddBtn) {
                closeAddBtn.addEventListener("click", () => addModal.classList.remove("show"));
            }

            // Close Modals
            if (closeIssueBtn) {
                closeIssueBtn.addEventListener("click", () => issueModal.classList.remove("show"));
            }
            if (closeViewBtn) {
                closeViewBtn.addEventListener("click", () => viewModal.classList.remove("show"));
            }

            // Close modal when clicking outside contents
            window.addEventListener("click", (event) => {
                if (event.target === addModal) addModal.classList.remove("show");
                if (event.target === issueModal) issueModal.classList.remove("show");
                if (event.target === viewModal) viewModal.classList.remove("show");
            });

            // Elements for category filter in Issue Stock modal
            const categorySelect = document.getElementById("issue-category-select");
            const itemSelect = document.getElementById("issue-item-select");

            // Handle "Issue Item" button click
            const issueBtns = document.querySelectorAll(".open-issue-btn");
            issueBtns.forEach(btn => {
                btn.addEventListener("click", function(e) {
                    e.stopPropagation();
                    const empId = this.dataset.id;
                    const empName = this.dataset.name;
                    
                    document.getElementById("issue-employee-id").value = empId;
                    document.getElementById("issue-employee-name").textContent = empName;
                    
                    // Reset fields
                    if (categorySelect) categorySelect.value = "";
                    if (itemSelect) {
                        itemSelect.innerHTML = '<option value="" disabled selected>Select Category First...</option>';
                        itemSelect.disabled = true;
                    }
                    
                    issueModal.classList.add("show");
                });
            });

            // Handle Category selection change to load dynamic items
            if (categorySelect && itemSelect) {
                categorySelect.addEventListener("change", function() {
                    const categoryId = this.value;
                    if (!categoryId) {
                        itemSelect.innerHTML = '<option value="" disabled selected>Select Category First...</option>';
                        itemSelect.disabled = true;
                        return;
                    }
                    
                    itemSelect.innerHTML = '<option value="" disabled selected>Loading items...</option>';
                    itemSelect.disabled = true;
                    
                    fetch('get_category_items.php?category_id=' + categoryId)
                        .then(response => response.json())
                        .then(data => {
                            itemSelect.innerHTML = '<option value="" disabled selected>Select Item (In stock)...</option>';
                            if (data.error) {
                                console.error(data.error);
                                itemSelect.innerHTML = '<option value="" disabled selected>Error loading items</option>';
                                return;
                            }
                            if (Array.isArray(data) && data.length > 0) {
                                data.forEach(item => {
                                    const option = document.createElement("option");
                                    option.value = item.id;
                                    option.textContent = `${item.name} (Available: ${item.quantity})`;
                                    itemSelect.appendChild(option);
                                });
                                itemSelect.disabled = false;
                            } else {
                                itemSelect.innerHTML = '<option value="" disabled selected>No items in stock for this category</option>';
                            }
                        })
                        .catch(err => {
                            console.error('Error fetching items:', err);
                            itemSelect.innerHTML = '<option value="" disabled selected>Failed to load items</option>';
                        });
                });
            }

            // Handle Click Employee Name to View Issued Items Detail Modal
            const clickableNames = document.querySelectorAll(".clickable-name");
            clickableNames.forEach(cell => {
                cell.addEventListener("click", function() {
                    const empId = this.dataset.id;
                    
                    // Set PDF export link
                    document.getElementById("export-pdf-btn").href = 'export_employee_pdf.php?employee_id=' + empId;
                    
                    // Set Profile Details in Modal
                    document.getElementById("view-emp-fullname").textContent = this.dataset.fullname;
                    document.getElementById("view-emp-no").textContent = this.dataset.empno;
                    document.getElementById("view-emp-desig").textContent = this.dataset.desig;
                    document.getElementById("view-emp-company").textContent = this.dataset.comp + " (" + this.dataset.branch + ")";
                    document.getElementById("view-emp-nic").textContent = this.dataset.nic;
                    document.getElementById("view-emp-contact").innerHTML = '<i class="fa-solid fa-phone"></i> ' + this.dataset.mobile + '<br><i class="fa-solid fa-envelope"></i> ' + this.dataset.email;
                    
                    // Clear and load dynamic issued items via AJAX
                    const tbody = document.getElementById("view-issued-tbody");
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 1.5rem 0; color:var(--text-secondary);">Loading issued items...</td></tr>';
                    
                    viewModal.classList.add("show");
                    
                    fetch('get_employee_issues.php?employee_id=' + empId)
                        .then(response => response.json())
                        .then(data => {
                            tbody.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(issue => {
                                    const row = document.createElement('tr');
                                    row.innerHTML = `
                                        <td><span class="badge category-badge">${escapeHtml(issue.category_name)}</span></td>
                                        <td style="font-weight:600;">${escapeHtml(issue.item_name)}</td>
                                        <td><span class="badge qty-badge">${issue.quantity}</span></td>
                                        <td style="font-size:0.9rem; color:var(--text-secondary);">${issue.issue_date}</td>
                                    `;
                                    tbody.appendChild(row);
                                });
                            } else {
                                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 2rem 0; color:var(--text-secondary);"><i class="fa-solid fa-box-open" style="display:block; font-size:2rem; margin-bottom:0.5rem; opacity:0.2;"></i> No items issued to this employee yet.</td></tr>';
                            }
                        })
                        .catch(err => {
                            console.error('Error fetching issued items:', err);
                            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 1.5rem 0; color:var(--error-color);">Failed to load issued items.</td></tr>';
                        });
                });
            });

            // Helper to escape HTML safely in JS
            function escapeHtml(text) {
                return text
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }

            // AJAX loader for Add Employee Modal Dropdown
            const companySelect = document.getElementById("company-select");
            const branchSelect = document.getElementById("branch-select");
            
            if (companySelect) {
                companySelect.addEventListener("change", function() {
                    const companyId = this.value;
                    const selectedCompanyName = companySelect.options[companySelect.selectedIndex].text.trim();
                    
                    // Prefix mapping configuration
                    const companyPrefixes = {
                        "Commercial Micro Credit": "CMC",
                        "Monik International Pvt Ltd": "MNK",
                        "Ceylon Monik Building Society Limited": "CMB",
                        "Monik Homes Pvt Ltd": "MNH",
                        "Monik Water Pvt Ltd": "MNW",
                        "Monik Trading Pvt LTD": "MNT",
                        "Monik Agro Pvt Ltd": "MNA",
                        "Monik Land": "MNL"
                    };
                    
                    const prefix = companyPrefixes[selectedCompanyName] || "";
                    const empNoInput = document.querySelector('#add-employee-modal input[name="emp_no"]');
                    if (empNoInput) {
                        empNoInput.value = prefix;
                        empNoInput.focus();
                    }

                    branchSelect.innerHTML = '<option value="" disabled selected>Loading branches...</option>';
                    branchSelect.disabled = true;
                    
                    if (companyId) {
                        fetch('get_branches.php?company_id=' + companyId)
                            .then(response => response.json())
                            .then(data => {
                                branchSelect.innerHTML = '<option value="" disabled selected>Select Branch...</option>';
                                if (data.length > 0) {
                                    data.forEach(branch => {
                                        const option = document.createElement('option');
                                        option.value = branch.id;
                                        option.textContent = branch.name;
                                        branchSelect.appendChild(option);
                                    });
                                    branchSelect.disabled = false;
                                } else {
                                    branchSelect.innerHTML = '<option value="" disabled selected>No branches found</option>';
                                }
                            });
                    }
                });
            }

            // AJAX loader for Filtration Panel Dropdowns
            const filterCompanySelect = document.getElementById("filter-company-select");
            const filterBranchSelect = document.getElementById("filter-branch-select");
            
            if (filterCompanySelect) {
                filterCompanySelect.addEventListener("change", function() {
                    const companyId = this.value;
                    filterBranchSelect.innerHTML = '<option value="0">All Branches</option>';
                    
                    if (companyId > 0) {
                        filterBranchSelect.innerHTML = '<option value="0">Loading branches...</option>';
                        filterBranchSelect.disabled = true;
                        
                        fetch('get_branches.php?company_id=' + companyId)
                            .then(response => response.json())
                            .then(data => {
                                filterBranchSelect.innerHTML = '<option value="0">All Branches</option>';
                                if (data.length > 0) {
                                    data.forEach(branch => {
                                        const option = document.createElement('option');
                                        option.value = branch.id;
                                        option.textContent = branch.name;
                                        filterBranchSelect.appendChild(option);
                                    });
                                    filterBranchSelect.disabled = false;
                                } else {
                                    filterBranchSelect.disabled = true;
                                }
                            });
                    } else {
                        filterBranchSelect.disabled = true;
                    }
                });
            }
        });
    </script>
</body>
</html>
