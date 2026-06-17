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

// Database Migration & Seeding for Companies and Branches
try {
    // Create companies table
    $db->exec("CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Create branches table
    $db->exec("CREATE TABLE IF NOT EXISTS branches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        address VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Seed default companies if table is empty
    $checkCompaniesCount = $db->query("SELECT COUNT(*) FROM companies");
    if ($checkCompaniesCount->fetchColumn() == 0) {
        $companiesToSeed = [
            "Commercial Micro Credit",
            "Monik International Pvt Ltd",
            "Ceylon Monik Building Society Limited",
            "Monik Homes Pvt Ltd",
            "Monik Water Pvt Ltd",
            "Monik Trading Pvt LTD",
            "Monik Agro Pvt Ltd",
            "Monik Land"
        ];
        $stmtSeed = $db->prepare("INSERT INTO companies (name) VALUES (:name)");
        foreach ($companiesToSeed as $companyName) {
            $stmtSeed->execute([':name' => $companyName]);
        }
    }
} catch (Exception $e) {
    $migrationError = "Setup error: " . $e->getMessage();
}

$message = "";
$messageType = "";

// Handle Adding a New Branch
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'add_branch') {
    $company_id = intval($_POST['company_id']);
    $branch_name = trim($_POST['branch_name']);
    $branch_address = trim($_POST['branch_address']);
    
    if ($company_id > 0 && !empty($branch_name) && !empty($branch_address)) {
        try {
            $insertQuery = "INSERT INTO branches (company_id, name, address) VALUES (:company_id, :name, :address)";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute([
                ':company_id' => $company_id,
                ':name' => $branch_name,
                ':address' => $branch_address
            ]);
            $message = "Branch added successfully!";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Failed to add branch: " . $e->getMessage();
            $messageType = "error";
        }
    } else {
        $message = "Please fill in all the fields.";
        $messageType = "error";
    }
}

// Fetch all companies for the dropdown
$companiesStmt = $db->query("SELECT id, name FROM companies ORDER BY name ASC");
$companies = $companiesStmt->fetchAll();

// Fetch all branches with their company name
$branchesStmt = $db->query("SELECT b.id, b.name as branch_name, b.address, c.name as company_name 
                            FROM branches b 
                            INNER JOIN companies c ON b.company_id = c.id 
                            ORDER BY c.name ASC, b.name ASC");
$branches = $branchesStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branches - Inventory Management System</title>
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
        /* Custom Table Styling */
        .table-responsive {
            overflow-x: auto;
            margin-top: 1rem;
        }
        .branch-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.95rem;
        }
        .branch-table th {
            padding: 1rem;
            border-bottom: 2px solid var(--panel-border);
            color: var(--accent-color);
            font-weight: 600;
        }
        .branch-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
        }
        .branch-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        .company-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            color: #a5b4fc;
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
                    <a href="branches.php" class="sidebar-item-link active">
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
                    <h2 style="margin-bottom: 0.25rem;">Branch Management</h2>
                    <p class="subtitle" style="margin-bottom: 0;">Add and organize branches across Monik companies</p>
                </div>
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

            <div class="split-container">
                <!-- Add Branch Form Panel -->
                <div class="form-panel">
                    <h3 style="font-size: 1.25rem; margin-bottom: 1.5rem; color: var(--text-primary);">Create Sub-Branch</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_branch">
                        
                        <div class="form-group">
                            <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Select Company</label>
                            <select name="company_id" class="form-control custom-select" required>
                                <option value="" disabled selected>Choose a company...</option>
                                <?php foreach($companies as $company): ?>
                                    <option value="<?php echo $company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Branch Name</label>
                            <input type="text" name="branch_name" class="form-control" placeholder="e.g. Colombo Head Office" required>
                        </div>

                        <div class="form-group">
                            <label style="display:block; margin-bottom:0.5rem; font-size:0.9rem; color:var(--text-secondary); font-weight:600;">Branch Address</label>
                            <input type="text" name="branch_address" class="form-control" placeholder="e.g. No 12, Galle Road, Colombo" required>
                        </div>

                        <button type="submit" class="btn-primary" style="margin-top: 1rem;">Add Branch</button>
                    </form>
                </div>

                <!-- Branch Directory Panel -->
                <div class="list-panel">
                    <h3 style="font-size: 1.25rem; margin-bottom: 0.5rem; color: var(--text-primary);">Branch Directory</h3>
                    <p style="font-size: 0.85rem; color: var(--text-secondary);">Currently registered branches inside the system</p>
                    
                    <div class="table-responsive">
                        <?php if(count($branches) > 0): ?>
                            <table class="branch-table">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th>Branch Name</th>
                                        <th>Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($branches as $branch): ?>
                                        <tr>
                                            <td>
                                                <span class="company-badge"><?php echo htmlspecialchars($branch['company_name']); ?></span>
                                            </td>
                                            <td style="font-weight:600;"><?php echo htmlspecialchars($branch['branch_name']); ?></td>
                                            <td style="font-size:0.9rem; color:var(--text-secondary);"><?php echo htmlspecialchars($branch['address']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align:center; padding: 3rem 0; color:var(--text-secondary);">
                                <i class="fa-solid fa-store-slash" style="font-size: 2.5rem; display:block; margin-bottom:1rem; color: rgba(255,255,255,0.1);"></i>
                                No branches registered yet.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

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
