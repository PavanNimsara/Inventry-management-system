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

$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

if ($employee_id > 0) {
    try {
        // Query to get all items issued to this employee
        $query = "SELECT ii.id as issue_id, ii.quantity, ii.issue_date, item.name as item_name, cat.name as category_name 
                  FROM issued_items ii
                  INNER JOIN inventory_items item ON ii.item_id = item.id
                  INNER JOIN inventory_categories cat ON item.category_id = cat.id
                  WHERE ii.employee_id = :employee_id
                  ORDER BY ii.issue_date DESC";
        $stmt = $db->prepare($query);
        $stmt->execute([':employee_id' => $employee_id]);
        $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($issues);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    echo json_encode([]);
}
?>
