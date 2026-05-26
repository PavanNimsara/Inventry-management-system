<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Secure page access
if (!$auth->isLoggedIn()) {
    die("Unauthorized access.");
}

// Retrieve filters (matching the filtration parameters on employees.php)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_company = isset($_GET['filter_company']) ? intval($_GET['filter_company']) : 0;
$filter_branch = isset($_GET['filter_branch']) ? intval($_GET['filter_branch']) : 0;

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

// Query ALL matching records (ignoring pagination so the user exports the entire filtered set)
$dataQuery = "SELECT e.*, b.name as branch_name, c.name as company_name 
              FROM employees e 
              INNER JOIN branches b ON e.branch_id = b.id 
              INNER JOIN companies c ON b.company_id = c.id 
              WHERE $whereSQL 
              ORDER BY e.emp_no ASC";

$dataStmt = $db->prepare($dataQuery);
foreach ($queryParams as $param => $val) {
    $dataStmt->bindValue($param, $val);
}
$dataStmt->execute();
$employees = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Set download headers for CSV/Excel compatibility
$filename = "employees_export_" . date('Y-m-d_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Open php output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM to fix Excel encoding issues (displays special symbols/non-English letters correctly)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Header Row
fputcsv($output, [
    'EMP NO',
    'Calling Name',
    'Full Name',
    'Designation',
    'Company',
    'Branch',
    'NIC Number',
    'Mobile Number',
    'Mail Address',
    'Joining Date'
]);

// Write employee rows
foreach ($employees as $emp) {
    fputcsv($output, [
        '="' . $emp['emp_no'] . '"',
        $emp['calling_name'],
        $emp['full_name'],
        $emp['designation'],
        $emp['company_name'],
        $emp['branch_name'],
        '="' . $emp['nic_number'] . '"',
        '="' . $emp['mobile_number'] . '"',
        $emp['mail_address'],
        $emp['joining_date']
    ]);
}

fclose($output);
exit();
?>
