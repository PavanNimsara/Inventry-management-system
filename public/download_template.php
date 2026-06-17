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

$filename = "employee_import_template.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Open php output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM to fix Excel encoding issues
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

// Sample Row (using badulla which is a known valid branch of Commercial Micro Credit)
fputcsv($output, [
    'CMC002',
    'John',
    'John Doe Smith',
    'Assistant Manager',
    'Commercial Micro Credit',
    'badulla',
    '199512345678',
    '0771234567',
    'john@gmail.com',
    '2026-05-28'
]);

fclose($output);
exit();
?>
