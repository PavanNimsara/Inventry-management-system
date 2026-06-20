<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Secure page access
$auth->restrictToLoggedIn();

$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

if ($employee_id <= 0) {
    die("Invalid Employee ID.");
}

// Fetch employee details
try {
    $empStmt = $db->prepare("
        SELECT e.*, b.name as branch_name, c.name as company_name 
        FROM employees e
        INNER JOIN branches b ON e.branch_id = b.id
        INNER JOIN companies c ON b.company_id = c.id
        WHERE e.id = :employee_id
        LIMIT 1
    ");
    $empStmt->execute([':employee_id' => $employee_id]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        die("Employee not found.");
    }

    // Fetch issued items
    $issueStmt = $db->prepare("
        SELECT ii.quantity, ii.issue_date, item.name as item_name, cat.name as category_name 
        FROM issued_items ii
        INNER JOIN inventory_items item ON ii.item_id = item.id
        INNER JOIN inventory_categories cat ON item.category_id = cat.id
        WHERE ii.employee_id = :employee_id
        ORDER BY ii.issue_date DESC
    ");
    $issueStmt->execute([':employee_id' => $employee_id]);
    $issued_items = $issueStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Profile - <?php echo htmlspecialchars($employee['emp_no']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Include html2pdf.js library -->
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
            max-width: 800px;
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
            grid-template-columns: repeat(2, 1fr);
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
            font-size: 1rem;
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
            font-size: 0.9rem;
        }
        .table td {
            font-size: 0.95rem;
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
            max-width: 800px;
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

    <!-- Printable Content Wrapper -->
    <div id="printable-content" class="container">
        <div class="header">
            <div class="header-title">
                <h1>Employee Inventory Record</h1>
                <p>Generated on <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
            <div class="company-logo">
                <div class="company-name">MONIK GROUP</div>
                <div class="company-sub">Inventory Management System</div>
            </div>
        </div>

        <div class="section-title">Employee Details</div>
        <div class="profile-grid">
            <div class="profile-item">
                <span>Full Name</span>
                <strong><?php echo htmlspecialchars($employee['full_name']); ?></strong>
            </div>
            <div class="profile-item">
                <span>EMP NO</span>
                <strong style="color: #4f46e5;"><?php echo htmlspecialchars($employee['emp_no']); ?></strong>
            </div>
            <div class="profile-item">
                <span>Calling Name</span>
                <strong><?php echo htmlspecialchars($employee['calling_name']); ?></strong>
            </div>
            <div class="profile-item">
                <span>Designation</span>
                <strong><?php echo htmlspecialchars($employee['designation']); ?></strong>
            </div>
            <div class="profile-item">
                <span>Company</span>
                <strong><?php echo htmlspecialchars($employee['company_name']); ?></strong>
            </div>
            <div class="profile-item">
                <span>Branch</span>
                <strong><?php echo htmlspecialchars($employee['branch_name']); ?></strong>
            </div>
            <div class="profile-item">
                <span>NIC Number</span>
                <strong><?php echo htmlspecialchars($employee['nic_number']); ?></strong>
            </div>
            <div class="profile-item">
                <span>Joining Date</span>
                <strong><?php echo htmlspecialchars($employee['joining_date']); ?></strong>
            </div>
            <div class="profile-item">
                <span>Mobile Number</span>
                <strong><?php echo htmlspecialchars($employee['mobile_number']); ?></strong>
            </div>
            <div class="profile-item">
                <span>Mail Address</span>
                <strong><?php echo htmlspecialchars($employee['mail_address']); ?></strong>
            </div>
        </div>

        <div class="section-title">Issued Inventory Items</div>
        <table class="table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Item Name</th>
                    <th>Quantity</th>
                    <th>Issue Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($issued_items) > 0): ?>
                    <?php foreach ($issued_items as $item): ?>
                        <tr>
                            <td><span class="badge badge-category"><?php echo htmlspecialchars($item['category_name']); ?></span></td>
                            <td style="font-weight: 600; color: #111827;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td><span class="badge badge-qty"><?php echo $item['quantity']; ?></span></td>
                            <td><?php echo htmlspecialchars($item['issue_date']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: #9ca3af; padding: 2rem 0;">No items issued to this employee yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="footer">
            All rights reserved. Developed by Pawan
        </div>
    </div>

    <script>
        function downloadPDF() {
            const statusText = document.getElementById('status-text');
            statusText.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating PDF download...';
            
            const element = document.getElementById('printable-content');
            
            // PDF Generation options
            const opt = {
                margin:       0.3,
                filename:     'Employee_Record_<?php echo htmlspecialchars($employee['emp_no']); ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, letterRendering: true },
                jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
            };

            // Generate and save the PDF
            html2pdf().set(opt).from(element).save().then(() => {
                statusText.innerHTML = '<i class="fa-solid fa-circle-check" style="color: #10b981;"></i> PDF Downloaded Successfully!';
            }).catch(err => {
                console.error(err);
                statusText.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="color: #ef4444;"></i> Failed to generate PDF.';
            });
        }

        // Auto download on page load
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(downloadPDF, 500);
        });
    </script>
</body>
</html>
