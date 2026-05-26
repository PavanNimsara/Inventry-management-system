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

$company_id = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;

if ($company_id > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM branches WHERE company_id = :company_id ORDER BY name ASC");
        $stmt->execute([':company_id' => $company_id]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($branches);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    echo json_encode([]);
}
?>
