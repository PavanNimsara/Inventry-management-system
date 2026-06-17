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

// Database Migration for Loan Documents
try {
    $checkCol = $db->query("SHOW COLUMNS FROM loan_document_inventory LIKE 'company_id'");
    if ($checkCol && $checkCol->rowCount() > 0) {
        $db->exec("DROP TABLE IF EXISTS loan_document_additions;");
        $db->exec("DROP TABLE IF EXISTS loan_document_inventory;");
    }
} catch (Exception $e) {
    // Suppress errors if tables do not exist yet
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS loan_document_inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        doc_type VARCHAR(50) NOT NULL,
        language VARCHAR(50) NOT NULL,
        quantity INT DEFAULT 0,
        last_updated DATE NOT NULL,
        UNIQUE KEY (category, doc_type, language)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $db->exec("CREATE TABLE IF NOT EXISTS loan_document_additions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        doc_type VARCHAR(50) NOT NULL,
        language VARCHAR(50) NOT NULL,
        quantity INT NOT NULL,
        addition_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $db->exec("CREATE TABLE IF NOT EXISTS loan_document_issuances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NOT NULL,
        category VARCHAR(50) NOT NULL,
        doc_type VARCHAR(50) NOT NULL,
        language VARCHAR(50) NOT NULL,
        quantity INT NOT NULL,
        issue_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Exception $e) {
    $migrationError = "Setup error: " . $e->getMessage();
}

$message = "";
$messageType = "";

// Allowed loan companies
$allowedLoanCompanies = [
    "Ceylon Monik Building Society Limited",
    "Monik International Pvt Ltd",
    "Commercial Micro Credit"
];

// Document Types & Languages configuration
$docTypes = [
    'loan_application' => 'Loan Application',
    'loan_agreement' => 'Loan Agreement',
    'promissory' => 'Promissory',
    'husband_promissory' => 'Husband Promissory'
];

$languages = [
    'sinhala' => 'Sinhala',
    'tamil' => 'Tamil'
];

// Fetch allowed companies with IDs
$allowedCompaniesPlaceholder = implode(',', array_fill(0, count($allowedLoanCompanies), '?'));
$compStmt = $db->prepare("SELECT id, name FROM companies WHERE name IN ($allowedCompaniesPlaceholder) ORDER BY name ASC");
$compStmt->execute($allowedLoanCompanies);
$companies = $compStmt->fetchAll(PDO::FETCH_ASSOC);

// Map company names to IDs
$companyIdMap = [];
foreach ($companies as $comp) {
    $companyIdMap[$comp['name']] = $comp['id'];
}

// Handle Adding Inventory
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'add_inventory') {
    $category = $_POST['category'];
    $addition_date = $_POST['addition_date'];
    
    if (!empty($category) && !empty($addition_date)) {
        try {
            $db->beginTransaction();
            
            $addedSomething = false;
            foreach ($docTypes as $docKey => $docLabel) {
                foreach ($languages as $langKey => $langLabel) {
                    $postKey = "qty_{$docKey}_{$langKey}";
                    $qty = isset($_POST[$postKey]) ? intval($_POST[$postKey]) : 0;
                    
                    if ($qty > 0) {
                        $addedSomething = true;
                        
                        // 1. Log in additions table
                        $addLogStmt = $db->prepare("INSERT INTO loan_document_additions (category, doc_type, language, quantity, addition_date) 
                                                    VALUES (:category, :doc_type, :language, :quantity, :addition_date)");
                        $addLogStmt->execute([
                            ':category' => $category,
                            ':doc_type' => $docKey,
                            ':language' => $langKey,
                            ':quantity' => $qty,
                            ':addition_date' => $addition_date
                        ]);
                        
                        // 2. Insert or update inventory table
                        $upsertStmt = $db->prepare("INSERT INTO loan_document_inventory (category, doc_type, language, quantity, last_updated) 
                                                    VALUES (:category, :doc_type, :language, :quantity, :last_updated) 
                                                    ON DUPLICATE KEY UPDATE quantity = quantity + :quantity, last_updated = :last_updated");
                        $upsertStmt->execute([
                            ':category' => $category,
                            ':doc_type' => $docKey,
                            ':language' => $langKey,
                            ':quantity' => $qty,
                            ':last_updated' => $addition_date
                        ]);
                    }
                }
            }
            
            if ($addedSomething) {
                $db->commit();
                $message = "Loan document inventory updated successfully!";
                $messageType = "success";
            } else {
                $db->rollBack();
                $message = "Please enter quantity greater than 0 for at least one document.";
                $messageType = "error";
            }
        } catch (Exception $e) {
            $db->rollBack();
            $message = "Failed to add inventory: " . $e->getMessage();
            $messageType = "error";
        }
    } else {
        $message = "Please fill in all general details.";
        $messageType = "error";
    }
}

// Handle Issuing Inventory
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'issue_inventory') {
    $branch_id = intval($_POST['branch_id']);
    $category = $_POST['category'];
    $issue_date = $_POST['issue_date'];
    
    if ($branch_id > 0 && !empty($category) && !empty($issue_date)) {
        try {
            $db->beginTransaction();
            
            $issuedSomething = false;
            $errors = [];
            
            // First pass: Validate stock levels
            foreach ($docTypes as $docKey => $docLabel) {
                foreach ($languages as $langKey => $langLabel) {
                    $postKey = "issue_qty_{$docKey}_{$langKey}";
                    $qty = isset($_POST[$postKey]) ? intval($_POST[$postKey]) : 0;
                    
                    if ($qty > 0) {
                        $issuedSomething = true;
                        
                        // Check available stock globally
                        $stockStmt = $db->prepare("SELECT quantity FROM loan_document_inventory 
                                                   WHERE category = :category 
                                                   AND doc_type = :doc_type AND language = :language LIMIT 1");
                        $stockStmt->execute([
                            ':category' => $category,
                            ':doc_type' => $docKey,
                            ':language' => $langKey
                        ]);
                        $available = intval($stockStmt->fetchColumn());
                        
                        if ($available < $qty) {
                            $errors[] = "Insufficient stock for {$docLabel} ({$langLabel}). Available: {$available}, Requested: {$qty}.";
                        }
                    }
                }
            }
            
            if (!empty($errors)) {
                $db->rollBack();
                $message = implode("<br>", $errors);
                $messageType = "error";
            } elseif (!$issuedSomething) {
                $db->rollBack();
                $message = "Please enter quantity greater than 0 for at least one document to issue.";
                $messageType = "error";
            } else {
                // Second pass: Deduct stock and log issuances
                foreach ($docTypes as $docKey => $docLabel) {
                    foreach ($languages as $langKey => $langLabel) {
                        $postKey = "issue_qty_{$docKey}_{$langKey}";
                        $qty = isset($_POST[$postKey]) ? intval($_POST[$postKey]) : 0;
                        
                        if ($qty > 0) {
                            // Deduct stock globally
                            $deductStmt = $db->prepare("UPDATE loan_document_inventory 
                                                        SET quantity = quantity - :quantity, last_updated = :last_updated 
                                                        WHERE category = :category 
                                                        AND doc_type = :doc_type AND language = :language");
                            $deductStmt->execute([
                                ':quantity' => $qty,
                                ':last_updated' => $issue_date,
                                ':category' => $category,
                                ':doc_type' => $docKey,
                                ':language' => $langKey
                            ]);
                            
                            // Insert log
                            $issueLogStmt = $db->prepare("INSERT INTO loan_document_issuances (branch_id, category, doc_type, language, quantity, issue_date) 
                                                          VALUES (:branch_id, :category, :doc_type, :language, :quantity, :issue_date)");
                            $issueLogStmt->execute([
                                ':branch_id' => $branch_id,
                                ':category' => $category,
                                ':doc_type' => $docKey,
                                ':language' => $langKey,
                                ':quantity' => $qty,
                                ':issue_date' => $issue_date
                            ]);
                        }
                    }
                }
                $db->commit();
                $message = "Loan documents successfully issued to branch and inventory updated!";
                $messageType = "success";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $message = "Failed to issue documents: " . $e->getMessage();
            $messageType = "error";
        }
    } else {
        $message = "Please fill in all general details.";
        $messageType = "error";
    }
}

// --- Fetch filters and data ---
$filter_category = isset($_GET['filter_category']) ? $_GET['filter_category'] : '';
$filter_company = isset($_GET['filter_company']) ? intval($_GET['filter_company']) : 0;
$filter_branch = isset($_GET['filter_branch']) ? intval($_GET['filter_branch']) : 0;

// 1. Fetch current inventory levels
$inventoryWhere = ["1=1"];
$inventoryParams = [];
if (!empty($filter_category)) {
    $inventoryWhere[] = "category = :category";
    $inventoryParams[':category'] = $filter_category;
}
$inventoryWhereSQL = implode(" AND ", $inventoryWhere);

$inventoryQuery = "SELECT * FROM loan_document_inventory WHERE $inventoryWhereSQL ORDER BY category ASC, doc_type ASC";
$inventoryStmt = $db->prepare($inventoryQuery);
$inventoryStmt->execute($inventoryParams);
$inventoryData = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch branches for issuance dropdown & filter (restricted to the 3 allowed companies)
$branchesQuery = "SELECT b.id, b.name as branch_name, b.company_id, c.name as company_name 
                  FROM branches b 
                  INNER JOIN companies c ON b.company_id = c.id 
                  WHERE c.name IN ($allowedCompaniesPlaceholder) 
                  ORDER BY c.name ASC, b.name ASC";
$branchesStmt = $db->prepare($branchesQuery);
$branchesStmt->execute($allowedLoanCompanies);
$allowedBranches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Issuance History Log (aggregated or detailed)
$view_branch_id = isset($_GET['view_branch_id']) ? intval($_GET['view_branch_id']) : 0;
$selectedBranchInfo = null;

if ($view_branch_id > 0) {
    // Fetch info of selected branch
    $selectedBranchStmt = $db->prepare("SELECT b.name as branch_name, c.name as company_name 
                                         FROM branches b 
                                         INNER JOIN companies c ON b.company_id = c.id 
                                         WHERE b.id = ?");
    $selectedBranchStmt->execute([$view_branch_id]);
    $selectedBranchInfo = $selectedBranchStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch detailed paginated history for this branch
    $logWhere = ["l.branch_id = :view_branch_id"];
    $logParams = [':view_branch_id' => $view_branch_id];

    if (!empty($filter_category)) {
        $logWhere[] = "l.category = :category";
        $logParams[':category'] = $filter_category;
    }

    $logWhereSQL = implode(" AND ", $logWhere);

    $logPage = isset($_GET['page']) ? intval($_GET['page']) : 1;
    if ($logPage < 1) $logPage = 1;
    $logLimit = 15;
    $logOffset = ($logPage - 1) * $logLimit;

    $logQuery = "SELECT l.*, b.name as branch_name, c.name as company_name 
                 FROM loan_document_issuances l
                 INNER JOIN branches b ON l.branch_id = b.id
                 INNER JOIN companies c ON b.company_id = c.id
                 WHERE $logWhereSQL
                 ORDER BY l.issue_date DESC, l.created_at DESC 
                 LIMIT :limit OFFSET :offset";
    $logStmt = $db->prepare($logQuery);
    foreach ($logParams as $paramKey => $paramVal) {
        $logStmt->bindValue($paramKey, $paramVal);
    }
    $logStmt->bindValue(':limit', $logLimit, PDO::PARAM_INT);
    $logStmt->bindValue(':offset', $logOffset, PDO::PARAM_INT);
    $logStmt->execute();
    $issuancesLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalLogsQuery = "SELECT COUNT(*) FROM loan_document_issuances l
                       INNER JOIN branches b ON l.branch_id = b.id
                       WHERE $logWhereSQL";
    $totalLogsStmt = $db->prepare($totalLogsQuery);
    $totalLogsStmt->execute($logParams);
    $totalLogsCount = intval($totalLogsStmt->fetchColumn());
    $totalLogPages = ceil($totalLogsCount / $logLimit);
} else {
    // Fetch aggregated list of Companies & Branches
    $branchWhere = ["c.name IN ($allowedCompaniesPlaceholder)"];
    $branchParams = $allowedLoanCompanies;

    if (!empty($filter_category)) {
        $branchWhere[] = "l.category = ?";
        $branchParams[] = $filter_category;
    }
    if ($filter_company > 0) {
        $branchWhere[] = "b.company_id = ?";
        $branchParams[] = $filter_company;
    }
    if ($filter_branch > 0) {
        $branchWhere[] = "l.branch_id = ?";
        $branchParams[] = $filter_branch;
    }

    $branchWhereSQL = implode(" AND ", $branchWhere);
    $branchListQuery = "SELECT b.id, b.name as branch_name, c.name as company_name, 
                               SUM(l.quantity) as total_issued, 
                               MAX(l.issue_date) as last_issue_date
                        FROM loan_document_issuances l
                        INNER JOIN branches b ON l.branch_id = b.id
                        INNER JOIN companies c ON b.company_id = c.id
                        WHERE $branchWhereSQL
                        GROUP BY b.id, b.name, c.name
                        ORDER BY c.name ASC, b.name ASC";
    $branchListStmt = $db->prepare($branchListQuery);
    $branchListStmt->execute($branchParams);
    $issuedBranches = $branchListStmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Applications - Document Management</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="js/theme.js"></script>
    <style>
        .split-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.9rem;
        }
        .matrix-table th {
            padding: 0.75rem;
            border-bottom: 2px solid var(--panel-border);
            color: var(--accent-color);
            font-weight: 600;
        }
        .matrix-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--panel-border);
            color: var(--text-primary);
            vertical-align: middle;
        }
        .matrix-qty-input {
            width: 100%;
            padding: 0.5rem;
            background: var(--input);
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            color: var(--text-primary);
            text-align: center;
        }
        .matrix-qty-input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .document-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }
        .doc-stock-card {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--panel-border);
            padding: 1.25rem;
            border-radius: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .doc-stock-qty {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--accent-color);
        }
        .lang-badge {
            font-size: 0.75rem;
            padding: 0.2rem 0.45rem;
            border-radius: 6px;
            margin-left: 0.35rem;
            text-transform: uppercase;
        }
        .lang-sinhala {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }
        .lang-tamil {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }
        .cat-badge {
            background: rgba(168, 85, 247, 0.15);
            border: 1px solid rgba(168, 85, 247, 0.3);
            color: #c084fc;
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
            box-shadow: 0 15px 30px rgba(0,0,0,0.2);
            width: 100%;
        }
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
            max-width: 650px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0,0,0,0.4);
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
        }
        .custom-select {
            appearance: none;
            -webkit-appearance: none;
            background: rgba(255, 255, 255, 0.04) url("data:image/svg+xml;utf8,<svg fill='white' height='24' viewBox='0 0 24 24' width='24' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/><path d='M0 0h24v24H0z' fill='none'/></svg>") no-repeat right 12px center;
            background-size: 20px;
            padding-right: 40px;
        }
        .custom-select option {
            background-color: var(--card);
            color: var(--text-primary);
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
                    <a href="profile.php" class="sidebar-item-link">
                        <i class="fa-solid fa-user-gear"></i> <span>Profile Settings</span>
                    </a>
                </li>
                <li class="sidebar-submenu-container">
                    <a href="#" class="sidebar-item-link" id="doc-mgmt-toggle">
                        <i class="fa-solid fa-file-invoice"></i> <span>Document Management</span> <i class="fa-solid fa-chevron-down submenu-chevron rotate" style="margin-left:auto; font-size: 0.8rem;"></i>
                    </a>
                    <ul class="sidebar-submenu show" id="doc-mgmt-submenu">
                        <li>
                            <a href="loan_applications.php" class="sidebar-item-link active" id="loan-app-link">
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

        <!-- Main Content Area -->
        <main class="main-content">
            <div class="main-content-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <div>
                    <h2 style="margin-bottom: 0.25rem;">Loan Document Management</h2>
                    <p class="subtitle" style="margin-bottom: 0;">Manage inventories and branch issuances of loan document packages</p>
                </div>
                <div style="display:flex; gap:0.75rem;">
                    <button id="open-add-modal-btn" class="btn-profile" style="padding:0.85rem 1.5rem; border-radius:12px; display:inline-flex; align-items:center; gap:0.5rem; font-weight:600; cursor:pointer; border:none; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color:#fff;">
                        <i class="fa-solid fa-file-circle-plus"></i> Add Inventory
                    </button>
                    <button id="open-issue-modal-btn" class="btn-primary" style="margin-top:0; padding:0.85rem 1.5rem; width:auto; display:inline-flex; align-items:center; gap:0.5rem; font-weight:600;">
                        <i class="fa-solid fa-hand-holding-dollar"></i> Issue to Branch
                    </button>
                </div>
            </div>

            <?php if(!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>" style="width:100%;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Filtration / Search Row -->
            <div class="filter-panel" style="margin-top:1.5rem;">
                <form method="GET" action="" class="filter-form">
                    <div style="flex: 0 1 250px; min-width: 180px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Company Filter</label>
                        <select name="filter_company" id="filter_company" class="form-control custom-select">
                            <option value="0">All Companies</option>
                            <?php foreach($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>" <?php echo $filter_company == $company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($company['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 0 1 250px; min-width: 180px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Branch Filter</label>
                        <select name="filter_branch" id="filter_branch" class="form-control custom-select">
                            <option value="0" data-company="0">All Branches</option>
                            <?php foreach($allowedBranches as $br): ?>
                                <option value="<?php echo $br['id']; ?>" data-company="<?php echo $br['company_id']; ?>" <?php echo $filter_branch == $br['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($br['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 0 1 200px; min-width: 150px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Category Filter</label>
                        <select name="filter_category" class="form-control custom-select">
                            <option value="">All Categories</option>
                            <option value="weekly" <?php echo $filter_category == 'weekly' ? 'selected' : ''; ?>>Weekly Loan</option>
                            <option value="monthly" <?php echo $filter_category == 'monthly' ? 'selected' : ''; ?>>Monthly Loan</option>
                        </select>
                    </div>
                    <div style="display:flex; gap: 0.5rem; margin-bottom: 0;">
                        <button type="submit" class="btn-primary" style="margin-top:0; padding: 0.85rem 1.5rem; font-size:0.95rem; width:auto; display:inline-flex; align-items:center; gap:0.5rem;"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                        <a href="loan_applications.php" class="btn-logout" style="padding:0.85rem 1.5rem; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none;"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                    </div>
                </form>
            </div>

            <!-- Inventory Grid -->
            <div class="list-panel-full" style="margin-bottom:2rem;">
                <h3 style="font-size: 1.25rem; margin-bottom: 0.5rem; color: var(--text-primary);">Document Stock Balance</h3>
                <p style="font-size: 0.85rem; color: var(--text-secondary);">Currently available document stock in the main inventory</p>
                
                <div class="document-grid">
                    <?php if (count($inventoryData) > 0): ?>
                        <?php foreach($inventoryData as $inv): ?>
                            <div class="doc-stock-card">
                                <div>
                                    <h4 style="font-size: 0.95rem; font-weight: 600; color:var(--text-primary); margin-bottom:0.25rem;">
                                        <?php echo htmlspecialchars($docTypes[$inv['doc_type']] ?? $inv['doc_type']); ?>
                                        <span class="lang-badge lang-<?php echo $inv['language']; ?>"><?php echo htmlspecialchars($inv['language']); ?></span>
                                    </h4>
                                    <span class="badge cat-badge" style="text-transform: capitalize; font-size: 0.7rem;"><?php echo htmlspecialchars($inv['category']); ?> Loan</span>
                                </div>
                                <div class="doc-stock-qty">
                                    <?php echo number_format($inv['quantity']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1; text-align:center; padding: 4rem 0; color:var(--text-secondary);">
                            <i class="fa-solid fa-file-circle-xmark" style="font-size: 3rem; display:block; margin-bottom:1rem; opacity:0.1;"></i>
                            No loan document inventory records found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Issuance Logs Section -->
            <div class="list-panel-full" style="margin-top:2rem;">
                <?php if ($view_branch_id > 0 && !empty($selectedBranchInfo)): ?>
                    <!-- Branch Issuance Details View -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
                        <div>
                            <h3 style="font-size: 1.25rem; margin-bottom: 0.25rem; color: var(--text-primary);">
                                Issuance History: <?php echo htmlspecialchars($selectedBranchInfo['company_name']); ?> - <?php echo htmlspecialchars($selectedBranchInfo['branch_name']); ?>
                            </h3>
                            <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0;">Detailed distribution records for this branch</p>
                        </div>
                        <a href="loan_applications.php" class="btn-logout" style="padding:0.6rem 1.2rem; border-radius:10px; display:inline-flex; align-items:center; gap:0.5rem; text-decoration:none; font-size:0.9rem;">
                            <i class="fa-solid fa-arrow-left"></i> Back to Branches List
                        </a>
                    </div>

                    <div class="table-responsive">
                        <?php if (count($issuancesLogs) > 0): ?>
                            <table class="matrix-table" style="font-size:0.95rem;">
                                <thead>
                                    <tr>
                                        <th>Issue Date</th>
                                        <th>Category</th>
                                        <th>Document Details</th>
                                        <th style="text-align: center;">Issued Qty</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($issuancesLogs as $log): ?>
                                        <tr>
                                            <td style="color:var(--text-secondary); font-size:0.9rem;"><?php echo htmlspecialchars($log['issue_date']); ?></td>
                                            <td style="text-transform: capitalize;"><span class="badge cat-badge"><?php echo htmlspecialchars($log['category']); ?></span></td>
                                            <td>
                                                <?php echo htmlspecialchars($docTypes[$log['doc_type']] ?? $log['doc_type']); ?>
                                                <span class="lang-badge lang-<?php echo $log['language']; ?>"><?php echo htmlspecialchars($log['language']); ?></span>
                                            </td>
                                            <td style="text-align: center; font-weight:700; color: var(--accent-color);"><?php echo number_format($log['quantity']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php if($totalLogPages > 1): ?>
                                <div class="pagination-container" style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; border-top: 1px solid var(--panel-border); padding-top:1.25rem;">
                                    <div style="font-size:0.85rem; color:var(--text-secondary);">
                                        Showing <?php echo $logOffset + 1; ?> to <?php echo min($logOffset + $logLimit, $totalLogsCount); ?> of <?php echo $totalLogsCount; ?> issues
                                    </div>
                                    <div class="pagination-buttons" style="display:flex; gap:0.35rem;">
                                        <?php if($logPage > 1): ?>
                                            <a href="?page=<?php echo $logPage-1; ?>&view_branch_id=<?php echo $view_branch_id; ?>&filter_category=<?php echo $filter_category; ?>" class="btn-logout" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none;"><i class="fa-solid fa-angle-left"></i> Prev</a>
                                        <?php endif; ?>
                                        <?php for($i = 1; $i <= $totalLogPages; $i++): ?>
                                            <a href="?page=<?php echo $i; ?>&view_branch_id=<?php echo $view_branch_id; ?>&filter_category=<?php echo $filter_category; ?>" class="<?php echo $i == $logPage ? 'btn-profile' : 'btn-logout'; ?>" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none; display:inline-flex; align-items:center;"><?php echo $i; ?></a>
                                        <?php endfor; ?>
                                        <?php if($logPage < $totalLogPages): ?>
                                            <a href="?page=<?php echo $logPage+1; ?>&view_branch_id=<?php echo $view_branch_id; ?>&filter_category=<?php echo $filter_category; ?>" class="btn-logout" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none;">Next <i class="fa-solid fa-angle-right"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <p style="text-align:center; padding: 4rem 0; color:var(--text-secondary);">
                                No document issuances logged for this branch yet.
                            </p>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- Aggregated Branches View -->
                    <h3 style="font-size: 1.25rem; margin-bottom: 0.5rem; color: var(--text-primary);">Branch Issuance History Log</h3>
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">Select a branch below to view its detailed document issuance log</p>

                    <div class="table-responsive">
                        <?php if (count($issuedBranches) > 0): ?>
                            <table class="matrix-table" style="font-size:0.95rem;">
                                <thead>
                                    <tr>
                                        <th>Company Name</th>
                                        <th>Branch Name</th>
                                        <th style="text-align: center;">Total Issued Qty</th>
                                        <th style="text-align: center;">Last Issue Date</th>
                                        <th style="text-align: center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($issuedBranches as $br): ?>
                                        <tr style="cursor: pointer;" onclick="window.location.href='?view_branch_id=<?php echo $br['id']; ?>'">
                                            <td><strong style="color:var(--text-primary);"><?php echo htmlspecialchars($br['company_name']); ?></strong></td>
                                            <td><strong style="color:var(--accent-color);"><?php echo htmlspecialchars($br['branch_name']); ?></strong></td>
                                            <td style="text-align: center; font-weight:700;"><?php echo number_format($br['total_issued']); ?></td>
                                            <td style="text-align: center; color:var(--text-secondary);"><?php echo htmlspecialchars($br['last_issue_date']); ?></td>
                                            <td style="text-align: center;">
                                                <a href="?view_branch_id=<?php echo $br['id']; ?>" class="btn-profile" style="padding:0.4rem 0.85rem; font-size:0.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:0.35rem; border-radius:8px;">
                                                    <i class="fa-solid fa-eye"></i> View History
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align:center; padding: 4rem 0; color:var(--text-secondary);">
                                No branches have received document issuances yet.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
    <!-- Add Inventory Modal -->
    <div id="add-inventory-modal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="close-add-modal-btn">&times;</span>
            <h3 style="font-size: 1.35rem; margin-bottom: 1rem; color: var(--text-primary); border-bottom: 1px solid var(--panel-border); padding-bottom: 0.75rem;">Add Document Inventory</h3>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_inventory">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem;">
                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Loan Category</label>
                        <select name="category" class="form-control custom-select" required>
                            <option value="weekly">Weekly Loan</option>
                            <option value="monthly">Monthly Loan</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Date</label>
                        <input type="date" name="addition_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <h4 style="font-size: 1rem; color: var(--text-primary); margin-bottom: 0.5rem; border-bottom: 1px solid var(--panel-border); padding-bottom: 0.5rem;">Document Quantities Matrix</h4>
                
                <table class="matrix-table">
                    <thead>
                        <tr>
                            <th>Document Type</th>
                            <th style="text-align: center; width: 120px;">Sinhala Qty</th>
                            <th style="text-align: center; width: 120px;">Tamil Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($docTypes as $docKey => $docLabel): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo $docLabel; ?></td>
                                <td>
                                    <input type="number" name="qty_<?php echo $docKey; ?>_sinhala" class="matrix-qty-input" min="0" value="0">
                                </td>
                                <td>
                                    <input type="number" name="qty_<?php echo $docKey; ?>_tamil" class="matrix-qty-input" min="0" value="0">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <button type="submit" class="btn-primary" style="margin-top: 1.5rem; padding: 0.85rem;">Add Stock to Inventory</button>
            </form>
        </div>
    </div>

    <!-- Issue Inventory Modal -->
    <div id="issue-inventory-modal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="close-issue-modal-btn">&times;</span>
            <h3 style="font-size: 1.35rem; margin-bottom: 1rem; color: var(--text-primary); border-bottom: 1px solid var(--panel-border); padding-bottom: 0.75rem;">Issue Documents to Branch</h3>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="issue_inventory">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Select Company</label>
                        <select id="issue_company_id" class="form-control custom-select" required>
                            <option value="" disabled selected>Choose company...</option>
                            <?php foreach($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Select Target Branch</label>
                        <select name="branch_id" id="issue_branch_id" class="form-control custom-select" required>
                            <option value="" disabled selected>Choose branch...</option>
                            <?php foreach($allowedBranches as $br): ?>
                                <option value="<?php echo $br['id']; ?>" data-company="<?php echo $br['company_id']; ?>"><?php echo htmlspecialchars($br['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Loan Category</label>
                        <select name="category" class="form-control custom-select" required>
                            <option value="weekly">Weekly Loan</option>
                            <option value="monthly">Monthly Loan</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Issue Date</label>
                        <input type="date" name="issue_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <h4 style="font-size: 1rem; color: var(--text-primary); margin-bottom: 0.5rem; border-bottom: 1px solid var(--panel-border); padding-bottom: 0.5rem;">Document Quantities Matrix</h4>
                
                <table class="matrix-table">
                    <thead>
                        <tr>
                            <th>Document Type</th>
                            <th style="text-align: center; width: 120px;">Sinhala Qty</th>
                            <th style="text-align: center; width: 120px;">Tamil Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($docTypes as $docKey => $docLabel): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo $docLabel; ?></td>
                                <td>
                                    <input type="number" name="issue_qty_<?php echo $docKey; ?>_sinhala" class="matrix-qty-input" min="0" value="0">
                                </td>
                                <td>
                                    <input type="number" name="issue_qty_<?php echo $docKey; ?>_tamil" class="matrix-qty-input" min="0" value="0">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <button type="submit" class="btn-primary" style="margin-top: 1.5rem; padding: 0.85rem;">Issue Stock to Branch</button>
            </form>
        </div>
    </div>

    <!-- Collapsible script controller -->
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

            // Submenu toggle logic
            const docMgmtToggle = document.getElementById("doc-mgmt-toggle");
            const docMgmtSubmenu = document.getElementById("doc-mgmt-submenu");
            const submenuChevron = document.querySelector(".submenu-chevron");

            if (docMgmtToggle) {
                docMgmtToggle.addEventListener("click", function(e) {
                    e.preventDefault();
                    docMgmtSubmenu.classList.toggle("show");
                    if (submenuChevron) submenuChevron.classList.toggle("rotate");
                    localStorage.setItem("doc-mgmt-open", docMgmtSubmenu.classList.contains("show"));
                });
            }

             // Company -> Branch Filter Dropdown Dynamic behavior
            const filterCompanySelect = document.getElementById("filter_company");
            const filterBranchSelect = document.getElementById("filter_branch");

            if (filterCompanySelect && filterBranchSelect) {
                const branchOptions = Array.from(filterBranchSelect.options);
                
                function updateBranchFilter() {
                    const selectedCompanyId = filterCompanySelect.value;
                    const previouslySelectedBranch = filterBranchSelect.value;
                    
                    // Clear existing items in branch dropdown
                    filterBranchSelect.innerHTML = "";
                    
                    // Filter matching options
                    branchOptions.forEach(opt => {
                        const optCompany = opt.getAttribute("data-company");
                        if (selectedCompanyId === "0" || optCompany === "0" || optCompany === selectedCompanyId) {
                            filterBranchSelect.appendChild(opt);
                        }
                    });

                    // Restore previous selection if it is still valid
                    if (Array.from(filterBranchSelect.options).some(opt => opt.value === previouslySelectedBranch)) {
                        filterBranchSelect.value = previouslySelectedBranch;
                    } else {
                        filterBranchSelect.value = "0";
                    }
                }
                
                filterCompanySelect.addEventListener("change", updateBranchFilter);
                
                // Initialize on load
                updateBranchFilter();
                // Retain selected branch index if possible
                const queryParams = new URLSearchParams(window.location.search);
                const activeBranch = queryParams.get('filter_branch');
                if (activeBranch) {
                    filterBranchSelect.value = activeBranch;
                }
            }

            // Company -> Branch Modal Dropdown Dynamic behavior
            const issueCompanySelect = document.getElementById("issue_company_id");
            const issueBranchSelect = document.getElementById("issue_branch_id");

            if (issueCompanySelect && issueBranchSelect) {
                const modalBranchOptions = Array.from(issueBranchSelect.options).filter(opt => opt.value !== "");
                
                function updateModalBranchOptions() {
                    const selectedCompanyId = issueCompanySelect.value;
                    
                    // Clear existing items in branch dropdown
                    issueBranchSelect.innerHTML = "";
                    
                    // Add "Choose branch..." default option
                    const defaultOpt = document.createElement("option");
                    defaultOpt.value = "";
                    defaultOpt.disabled = true;
                    defaultOpt.selected = true;
                    defaultOpt.textContent = "Choose branch...";
                    issueBranchSelect.appendChild(defaultOpt);
                    
                    // Filter matching options
                    modalBranchOptions.forEach(opt => {
                        const optCompany = opt.getAttribute("data-company");
                        if (optCompany === selectedCompanyId) {
                            issueBranchSelect.appendChild(opt);
                        }
                    });
                }
                
                issueCompanySelect.addEventListener("change", updateModalBranchOptions);
                // Call initial update to empty the branches list until a company is chosen
                updateModalBranchOptions();
            }

            // Modals controllers
            const addModal = document.getElementById("add-inventory-modal");
            const openAddBtn = document.getElementById("open-add-modal-btn");
            const closeAddBtn = document.getElementById("close-add-modal-btn");

            const issueModal = document.getElementById("issue-inventory-modal");
            const openIssueBtn = document.getElementById("open-issue-modal-btn");
            const closeIssueBtn = document.getElementById("close-issue-modal-btn");

            if (openAddBtn) {
                openAddBtn.addEventListener("click", () => addModal.classList.add("show"));
            }
            if (closeAddBtn) {
                closeAddBtn.addEventListener("click", () => addModal.classList.remove("show"));
            }

            if (openIssueBtn) {
                openIssueBtn.addEventListener("click", () => issueModal.classList.add("show"));
            }
            if (closeIssueBtn) {
                closeIssueBtn.addEventListener("click", () => issueModal.classList.remove("show"));
            }

            // Close modal when clicking outside contents
            window.addEventListener("click", (event) => {
                if (event.target === addModal) addModal.classList.remove("show");
                if (event.target === issueModal) issueModal.classList.remove("show");
            });
        });
    </script>
</body>
</html>
