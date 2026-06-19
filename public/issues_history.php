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

// Fetch all companies for filters
$companiesStmt = $db->query("SELECT id, name FROM companies ORDER BY name ASC");
$companies = $companiesStmt->fetchAll();

// --- Filtering parameters ---
$tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'items';
if ($tab !== 'items' && $tab !== 'documents') {
    $tab = 'items';
}

$filter_company = isset($_GET['filter_company']) ? intval($_GET['filter_company']) : 0;
$filter_branch = isset($_GET['filter_branch']) ? intval($_GET['filter_branch']) : 0;
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

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

if ($filter_company > 0) {
    $whereClauses[] = "b.company_id = :filter_company";
    $queryParams[':filter_company'] = $filter_company;
}
if ($filter_branch > 0) {
    if ($tab === 'items') {
        $whereClauses[] = "e.branch_id = :filter_branch";
    } else {
        $whereClauses[] = "l.branch_id = :filter_branch";
    }
    $queryParams[':filter_branch'] = $filter_branch;
}
if (!empty($start_date)) {
    if ($tab === 'items') {
        $whereClauses[] = "ii.issue_date >= :start_date";
    } else {
        $whereClauses[] = "l.issue_date >= :start_date";
    }
    $queryParams[':start_date'] = $start_date;
}
if (!empty($end_date)) {
    if ($tab === 'items') {
        $whereClauses[] = "ii.issue_date <= :end_date";
    } else {
        $whereClauses[] = "l.issue_date <= :end_date";
    }
    $queryParams[':end_date'] = $end_date;
}

$whereSQL = implode(" AND ", $whereClauses);

$records = [];
$total_rows = 0;
$total_pages = 0;

if ($tab === 'items') {
    // Count total matching items issues
    $countQuery = "SELECT COUNT(*) FROM issued_items ii 
                   INNER JOIN employees e ON ii.employee_id = e.id 
                   INNER JOIN branches b ON e.branch_id = b.id 
                   INNER JOIN companies c ON b.company_id = c.id 
                   INNER JOIN inventory_items item ON ii.item_id = item.id
                   WHERE $whereSQL";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($queryParams);
    $total_rows = $countStmt->fetchColumn();
    $total_pages = ceil($total_rows / $limit);

    // Fetch paginated items issues
    $dataQuery = "SELECT ii.*, item.name as item_name, cat.name as category_name, e.calling_name, e.emp_no, b.name as branch_name, c.name as company_name 
                  FROM issued_items ii 
                  INNER JOIN employees e ON ii.employee_id = e.id 
                  INNER JOIN branches b ON e.branch_id = b.id 
                  INNER JOIN companies c ON b.company_id = c.id 
                  INNER JOIN inventory_items item ON ii.item_id = item.id
                  INNER JOIN inventory_categories cat ON item.category_id = cat.id
                  WHERE $whereSQL 
                  ORDER BY ii.issue_date DESC, ii.id DESC 
                  LIMIT :limit OFFSET :offset";
    $dataStmt = $db->prepare($dataQuery);
    foreach ($queryParams as $param => $val) {
        $dataStmt->bindValue($param, $val);
    }
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $records = $dataStmt->fetchAll();
} else {
    // Count total matching document issues
    $countQuery = "SELECT COUNT(*) FROM loan_document_issuances l
                   INNER JOIN branches b ON l.branch_id = b.id 
                   INNER JOIN companies c ON b.company_id = c.id 
                   WHERE $whereSQL";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($queryParams);
    $total_rows = $countStmt->fetchColumn();
    $total_pages = ceil($total_rows / $limit);

    // Fetch paginated document issues
    $dataQuery = "SELECT l.*, b.name as branch_name, c.name as company_name 
                  FROM loan_document_issuances l 
                  INNER JOIN branches b ON l.branch_id = b.id 
                  INNER JOIN companies c ON b.company_id = c.id 
                  WHERE $whereSQL 
                  ORDER BY l.issue_date DESC, l.id DESC 
                  LIMIT :limit OFFSET :offset";
    $dataStmt = $db->prepare($dataQuery);
    foreach ($queryParams as $param => $val) {
        $dataStmt->bindValue($param, $val);
    }
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $records = $dataStmt->fetchAll();
}

// Mappings for nice display of documents
$docTypes = [
    "loan_application" => "Loan Application",
    "loan_agreement" => "Loan Agreement",
    "promissory" => "Promissory Note",
    "husband_promissory" => "Husband Promissory"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issues History - Inventory Management System</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            position: relative;
            z-index: 100;
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
        .history-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--panel-border);
            padding-bottom: 0.5rem;
        }
        .tab-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1rem;
            font-weight: 600;
            padding: 0.5rem 1rem;
            cursor: pointer;
            position: relative;
            transition: color 0.3s ease;
            text-decoration: none;
        }
        .tab-btn:hover {
            color: var(--text-primary);
        }
        .tab-btn.active {
            color: var(--primary);
        }
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -0.6rem;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
            border-radius: 2px;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        .history-table th {
            padding: 1rem;
            border-bottom: 2px solid var(--panel-border);
            color: var(--accent-color);
            font-weight: 600;
        }
        .history-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            white-space: nowrap;
        }
        .history-table tr:hover td {
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
        .doc-badge {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }
        .lang-badge {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
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

            <!-- Profile Widget -->
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
                    <a href="profile.php" class="sidebar-item-link">
                        <i class="fa-solid fa-user-gear"></i> <span>Profile Settings</span>
                    </a>
                </li>
                <li>
                    <a href="issues_history.php" class="sidebar-item-link active">
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

        <!-- Main Panel Content -->
        <main class="main-content">
            <div class="page-header-row">
                <div>
                    <h2 class="page-title">Issues History Log</h2>
                    <p style="font-size:0.9rem; color:var(--text-secondary); margin-top:0.25rem;">Track historical distributions of inventory items and loan documents</p>
                </div>
            </div>

            <!-- Tabs System -->
            <div class="history-tabs">
                <a href="?tab=items&filter_company=<?php echo $filter_company; ?>&filter_branch=<?php echo $filter_branch; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="tab-btn <?php echo $tab === 'items' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-boxes-stacked"></i> Items Issuance History
                </a>
                <a href="?tab=documents&filter_company=<?php echo $filter_company; ?>&filter_branch=<?php echo $filter_branch; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="tab-btn <?php echo $tab === 'documents' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-shield"></i> Document Issuance History
                </a>
            </div>

            <!-- Filtration Panel -->
            <div class="filter-panel">
                <form method="GET" action="" class="filter-form">
                    <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                    
                    <div style="flex: 1 1 200px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Company Filter</label>
                        <select id="filter-company-select" name="filter_company" class="form-control custom-select">
                            <option value="0">All Companies</option>
                            <?php foreach($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>" <?php echo $filter_company == $company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($company['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex: 1 1 200px;">
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

                    <div style="flex: 1 1 180px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>

                    <div style="flex: 1 1 180px;">
                        <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-secondary); font-weight:600;">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>

                    <div style="display:flex; gap: 0.5rem;">
                        <a href="?tab=<?php echo $tab; ?>" class="btn-logout" style="padding:0.85rem 1.5rem; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none;"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                    </div>
                </form>
            </div>

            <!-- List Panel -->
            <div class="list-panel-full">
                <div class="table-responsive">
                    <?php if($tab === 'items'): ?>
                        <!-- Items Table -->
                        <?php if(count($records) > 0): ?>
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>EMP NO</th>
                                        <th>Employee Name</th>
                                        <th>Item Name</th>
                                        <th>Category</th>
                                        <th>Issued Qty</th>
                                        <th>Company / Branch</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($records as $rec): ?>
                                        <tr>
                                            <td style="font-weight: 600;"><?php echo htmlspecialchars($rec['issue_date']); ?></td>
                                            <td style="font-weight: 700; color: var(--accent-color);"><?php echo htmlspecialchars($rec['emp_no']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($rec['calling_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($rec['item_name']); ?></td>
                                            <td><span class="badge company-badge"><?php echo htmlspecialchars($rec['category_name']); ?></span></td>
                                            <td><span class="badge branch-badge"><?php echo htmlspecialchars($rec['quantity']); ?></span></td>
                                            <td>
                                                <span class="badge company-badge" style="margin-bottom:0.25rem; display:block; text-align:center;">
                                                    <?php echo htmlspecialchars($rec['company_name']); ?>
                                                </span>
                                                <span class="badge branch-badge" style="display:block; text-align:center;">
                                                    <?php echo htmlspecialchars($rec['branch_name']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align:center; padding: 3rem 0; color:var(--text-secondary);">
                                <i class="fa-solid fa-boxes-packing" style="font-size:3rem; margin-bottom:1rem; opacity:0.2;"></i>
                                <p>No item issuance records found matching filters.</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Documents Table -->
                        <?php if(count($records) > 0): ?>
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Document Type</th>
                                        <th>Category</th>
                                        <th>Language</th>
                                        <th>Quantity</th>
                                        <th>Company / Branch</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($records as $rec): ?>
                                        <tr>
                                            <td style="font-weight: 600;"><?php echo htmlspecialchars($rec['issue_date']); ?></td>
                                            <td>
                                                <strong>
                                                    <?php echo isset($docTypes[$rec['doc_type']]) ? $docTypes[$rec['doc_type']] : htmlspecialchars($rec['doc_type']); ?>
                                                </strong>
                                            </td>
                                            <td><span class="badge cat-badge"><?php echo htmlspecialchars($rec['category']); ?></span></td>
                                            <td>
                                                <span class="badge lang-badge">
                                                    <?php echo htmlspecialchars($rec['language']); ?>
                                                </span>
                                            </td>
                                            <td><span class="badge branch-badge"><?php echo htmlspecialchars($rec['quantity']); ?></span></td>
                                            <td>
                                                <span class="badge company-badge" style="margin-bottom:0.25rem; display:block; text-align:center;">
                                                    <?php echo htmlspecialchars($rec['company_name']); ?>
                                                </span>
                                                <span class="badge branch-badge" style="display:block; text-align:center;">
                                                    <?php echo htmlspecialchars($rec['branch_name']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align:center; padding: 3rem 0; color:var(--text-secondary);">
                                <i class="fa-solid fa-file-circle-xmark" style="font-size:3rem; margin-bottom:1rem; opacity:0.2;"></i>
                                <p>No document issuance records found matching filters.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Pagination Navigation -->
                    <?php if($total_pages > 1): ?>
                        <div class="pagination-container" style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; border-top: 1px solid var(--panel-border); padding-top:1.25rem;">
                            <div style="font-size:0.85rem; color:var(--text-secondary);">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> records
                            </div>
                            <div class="pagination-buttons" style="display:flex; gap:0.35rem;">
                                <?php if($page > 1): ?>
                                    <a href="?page=<?php echo $page-1; ?>&tab=<?php echo $tab; ?>&filter_company=<?php echo $filter_company; ?>&filter_branch=<?php echo $filter_branch; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="btn-logout" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none;"><i class="fa-solid fa-angle-left"></i> Prev</a>
                                <?php endif; ?>
                                
                                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&tab=<?php echo $tab; ?>&filter_company=<?php echo $filter_company; ?>&filter_branch=<?php echo $filter_branch; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="btn-logout <?php echo $page == $i ? 'active' : ''; ?>" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none; <?php echo $page == $i ? 'background:var(--primary); color:#fff;' : ''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                
                                <?php if($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page+1; ?>&tab=<?php echo $tab; ?>&filter_company=<?php echo $filter_company; ?>&filter_branch=<?php echo $filter_branch; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="btn-logout" style="padding:0.5rem 1rem; font-size:0.85rem; text-decoration:none;">Next <i class="fa-solid fa-angle-right"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript to handle dynamic filters and layout toggles -->
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
                                // Submit form after updating branch dropdown
                                const form = filterCompanySelect.closest("form");
                                if (form) form.submit();
                            });
                    } else {
                        filterBranchSelect.disabled = true;
                        const form = filterCompanySelect.closest("form");
                        if (form) form.submit();
                    }
                });
            }

            if (filterBranchSelect) {
                filterBranchSelect.addEventListener("change", function() {
                    const form = this.closest("form");
                    if (form) {
                        form.submit();
                    }
                });
            }

            // Live filtering for start and end dates
            const startDateInput = document.querySelector(".filter-panel input[name='start_date']");
            const endDateInput = document.querySelector(".filter-panel input[name='end_date']");
            
            [startDateInput, endDateInput].forEach(input => {
                if (input) {
                    input.addEventListener("change", function() {
                        const form = this.closest("form");
                        if (form) {
                            form.submit();
                        }
                    });
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
        });
    </script>
</body>
</html>
