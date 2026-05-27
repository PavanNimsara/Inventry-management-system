<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Secure page access
$auth->restrictToLoggedIn();

$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;

if ($item_id <= 0) {
    die("Invalid Item ID.");
}

// Fetch item details
try {
    $itemStmt = $db->prepare("
        SELECT i.*, c.name as category_name 
        FROM inventory_items i
        INNER JOIN inventory_categories c ON i.category_id = c.id
        WHERE i.id = :item_id
        LIMIT 1
    ");
    $itemStmt->execute([':item_id' => $item_id]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        die("Inventory item not found.");
    }

    // Fetch employee issuances
    $issueStmt = $db->prepare("
        SELECT ii.quantity, ii.issue_date, e.emp_no, e.calling_name, e.designation, b.name as branch_name, c.name as company_name 
        FROM issued_items ii
        INNER JOIN employees e ON ii.employee_id = e.id
        INNER JOIN branches b ON e.branch_id = b.id
        INNER JOIN companies c ON b.company_id = c.id
        WHERE ii.item_id = :item_id
        ORDER BY ii.issue_date DESC, e.calling_name ASC
    ");
    $issueStmt->execute([':item_id' => $item_id]);
    $issuances = $issueStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Issuances - <?php echo htmlspecialchars($item['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- html2pdf.js library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f3f4f6;
            color: #1f2937;
            margin: 0;
            padding: 2rem;
        }
        .container {
            max-width: 850px;
            background: #ffffff;
            margin: 0 auto;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            position: relative;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
        }
        .header-title h1 {
            margin: 0;
            font-size: 1.8rem;
            color: #111827;
            font-weight: 700;
        }
        .header-title p {
            margin: 0.25rem 0 0 0;
            color: #6b7280;
            font-size: 0.95rem;
        }
        .company-logo {
            text-align: right;
        }
        .company-name {
            font-weight: 800;
            font-size: 1.35rem;
            color: #6366f1;
            letter-spacing: -0.5px;
        }
        .company-sub {
            font-size: 0.8rem;
            color: #9ca3af;
            margin-top: 0.1rem;
        }
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2.5rem;
            background: #f9fafb;
            padding: 1.5rem;
            border-radius: 8px;
            border: 1px solid #f3f4f6;
        }
        .profile-item {
            display: flex;
            flex-direction: column;
        }
        .profile-item span {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        .profile-item strong {
            font-size: 1.05rem;
            color: #1f2937;
        }
        .section-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 1rem;
            border-left: 4px solid #6366f1;
            padding-left: 0.5rem;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }
        .table th, .table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .table th {
            background-color: #f3f4f6;
            color: #374151;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .table td {
            font-size: 0.9rem;
            color: #4b5563;
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 6px;
            text-transform: uppercase;
        }
        .badge-category {
            background-color: #e0e7ff;
            color: #4338ca;
        }
        .badge-qty {
            background-color: #f3f4f6;
            color: #1f2937;
        }
        .no-print-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 850px;
            margin: 0 auto 1.5rem auto;
            background: #ffffff;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .btn-print {
            background-color: #6366f1;
            color: #ffffff;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .btn-print:hover {
            background-color: #4f46e5;
        }
        .footer {
            margin-top: 3rem;
            text-align: center;
            font-size: 0.8rem;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 1rem;
        }
    </style>
</head>
<body>

    <div class="no-print-bar">
        <span style="font-size: 0.9rem; color: #4b5563;" id="status-text"><i class="fa-solid fa-spinner fa-spin"></i> Generating PDF download...</span>
        <button onclick="downloadPDF()" class="btn-print">
            <i class="fa-solid fa-download"></i> Download PDF Again
        </button>
    </div>

    <!-- Printable Content -->
    <div id="printable-content" class="container">
        <div class="header">
            <div class="header-title">
                <h1>Inventory Item Distribution</h1>
                <p>Generated on <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
            <div class="company-logo">
                <div class="company-name">MONIK GROUP</div>
                <div class="company-sub">Inventory Management System</div>
            </div>
        </div>

        <div class="section-title">Item Details</div>
        <div class="profile-grid">
            <div class="profile-item">
                <span>Item Name</span>
                <strong><?php echo htmlspecialchars($item['name']); ?></strong>
            </div>
            <div class="profile-item">
                <span>Category</span>
                <strong style="color: #4f46e5;"><?php echo htmlspecialchars($item['category_name']); ?></strong>
            </div>
            <div class="profile-item">
                <span>Available Stock (Warehouse)</span>
                <strong><?php echo htmlspecialchars($item['quantity']); ?></strong>
            </div>
        </div>

        <div class="section-title">Employees Issued Details</div>
        <table class="table">
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
            <tbody>
                <?php if (count($issuances) > 0): ?>
                    <?php foreach ($issuances as $iss): ?>
                        <tr>
                            <td style="font-weight: 700; color: #4b5563;">#<?php echo htmlspecialchars($iss['emp_no']); ?></td>
                            <td style="font-weight: 600; color: #111827;"><?php echo htmlspecialchars($iss['calling_name']); ?></td>
                            <td><?php echo htmlspecialchars($iss['designation']); ?></td>
                            <td><?php echo htmlspecialchars($iss['company_name'] . ' (' . $iss['branch_name'] . ')'); ?></td>
                            <td><span class="badge badge-qty"><?php echo $iss['quantity']; ?></span></td>
                            <td><?php echo htmlspecialchars($iss['issue_date']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: #9ca3af; padding: 2rem 0;">No employees have been issued this item yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="footer">
            Monik Group &copy; <?php echo date('Y'); ?>. All rights reserved.
        </div>
    </div>

    <script>
        function downloadPDF() {
            const statusText = document.getElementById('status-text');
            statusText.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating PDF download...';
            
            const element = document.getElementById('printable-content');
            
            const opt = {
                margin:       0.3,
                filename:     'Item_Distribution_<?php echo htmlspecialchars($item['name']); ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, letterRendering: true },
                jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
            };

            html2pdf().set(opt).from(element).save().then(() => {
                statusText.innerHTML = '<i class="fa-solid fa-circle-check" style="color: #10b981;"></i> PDF Downloaded Successfully!';
            }).catch(err => {
                console.error(err);
                statusText.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="color: #ef4444;"></i> Failed to generate PDF.';
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(downloadPDF, 500);
        });
    </script>
</body>
</html>
