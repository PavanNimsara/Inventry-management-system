<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Secure access
if (!$auth->isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;

if ($item_id > 0) {
    try {
        // Query to get all employees who received this item
        $query = "SELECT ii.quantity, ii.issue_date, e.emp_no, e.calling_name, e.designation, b.name as branch_name, c.name as company_name 
                  FROM issued_items ii
                  INNER JOIN employees e ON ii.employee_id = e.id
                  INNER JOIN branches b ON e.branch_id = b.id
                  INNER JOIN companies c ON b.company_id = c.id
                  WHERE ii.item_id = :item_id
                  ORDER BY ii.issue_date DESC, e.calling_name ASC";
        $stmt = $db->prepare($query);
        $stmt->execute([':item_id' => $item_id]);
        $issuances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($issuances);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    echo json_encode([]);
}
?>
