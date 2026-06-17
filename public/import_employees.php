<?php
session_start();
require_once '../config/database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Secure page access
if (!$auth->isLoggedIn()) {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['import_file'])) {
    header("Location: employees.php");
    exit();
}

$file = $_FILES['import_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['import_errors'] = ["Upload failed with error code: " . $file['error']];
    header("Location: employees.php");
    exit();
}

$fileName = $file['name'];
$tmpPath = $file['tmp_name'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

$rows = [];

// Helper function to clean values (handles formulas, ="" wrapper)
function cleanImportValue($val) {
    $val = trim($val);
    if (strpos($val, '=') === 0) {
        $val = substr($val, 1);
        $val = trim($val, '"\'');
    }
    return $val;
}

// Convert Excel Serial Date to Y-m-d
function excelDateToPHP($serial) {
    if (is_numeric($serial)) {
        $utc_days = $serial - 25569;
        $utc_value = $utc_days * 86400;
        return date('Y-m-d', $utc_value);
    }
    if (!empty($serial)) {
        $serial = trim($serial);
        
        // Extract date part only (handling spaces or ISO-8601 'T' separator)
        $parts = preg_split('/[\sT]+/', $serial);
        $dateStr = trim($parts[0]);
        
        $formats = [
            'Y-m-d', 'Y/m/d', 'Y.m.d',
            'm-d-Y', 'm/d/Y', 'm.d.Y',
            'n-j-Y', 'n/j/Y', 'n.j.Y',
            'd-m-Y', 'd/m/Y', 'd.m.Y',
            'j-n-Y', 'j/n/Y', 'j.n.Y',
            'Y-M-d', 'd-M-Y'
        ];
        
        foreach ($formats as $format) {
            $d = DateTime::createFromFormat($format, $dateStr);
            if ($d && $d->format($format) === $dateStr) {
                return $d->format('Y-m-d');
            }
        }
        
        $timestamp = strtotime(str_replace(['/', '.'], '-', $dateStr));
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
    }
    return $serial;
}

// Custom XLSX parser using ZipArchive + SimpleXML
function parseXLSX($filePath) {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        return false;
    }

    // 1. Parse Shared Strings
    $sharedStrings = [];
    $sharedStringsData = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsData) {
        $xml = simplexml_load_string($sharedStringsData);
        if ($xml) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $text = '';
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            if (isset($r->t)) {
                                $text .= (string)$r->t;
                            }
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    // 2. Parse Sheet1
    $sheetData = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetData) {
        $zip->close();
        return false;
    }

    $xml = simplexml_load_string($sheetData);
    if (!$xml) {
        $zip->close();
        return false;
    }

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $cellRef = (string)$cell['r'];
            preg_match('/^[A-Z]+/', $cellRef, $matches);
            $colLetter = $matches[0];
            $colIndex = 0;
            $len = strlen($colLetter);
            for ($i = 0; $i < $len; $i++) {
                $colIndex = $colIndex * 26 + (ord($colLetter[$i]) - 64);
            }
            $colIndex -= 1;

            $val = '';
            if (isset($cell->v)) {
                $val = (string)$cell->v;
                if ((string)$cell['t'] === 's') {
                    $val = isset($sharedStrings[$val]) ? $sharedStrings[$val] : '';
                }
            }
            $rowData[$colIndex] = $val;
        }

        $maxCol = count($rowData) > 0 ? max(array_keys($rowData)) : -1;
        for ($i = 0; $i <= $maxCol; $i++) {
            if (!isset($rowData[$i])) {
                $rowData[$i] = '';
            }
        }
        ksort($rowData);
        $rows[] = $rowData;
    }

    $zip->close();
    return $rows;
}

if ($ext === 'csv') {
    if (($handle = fopen($tmpPath, "r")) !== FALSE) {
        // Detect UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $rows[] = array_map('cleanImportValue', $data);
        }
        fclose($handle);
    }
} elseif ($ext === 'xlsx') {
    $xlsxRows = parseXLSX($tmpPath);
    if ($xlsxRows === false) {
        $_SESSION['import_errors'] = ["Failed to parse the Excel (.xlsx) file. Please make sure it is a valid Excel workbook."];
        header("Location: employees.php");
        exit();
    }
    foreach ($xlsxRows as $row) {
        $rows[] = array_map('cleanImportValue', $row);
    }
} else {
    $_SESSION['import_errors'] = ["Unsupported file type. Please upload a Microsoft Excel (.xlsx) or CSV (.csv) file."];
    header("Location: employees.php");
    exit();
}

if (count($rows) <= 1) {
    $_SESSION['import_errors'] = ["The file appears to be empty or contains no data rows."];
    header("Location: employees.php");
    exit();
}

// Match headers
$headers = array_map('strtolower', $rows[0]);
$expectedHeaders = [
    'emp no' => 'emp_no',
    'calling name' => 'calling_name',
    'full name' => 'full_name',
    'designation' => 'designation',
    'company' => 'company',
    'branch' => 'branch',
    'nic number' => 'nic_number',
    'mobile number' => 'mobile_number',
    'mail address' => 'mail_address',
    'joining date' => 'joining_date'
];

$headerIndices = [];
foreach ($expectedHeaders as $key => $colName) {
    $idx = array_search($key, $headers);
    if ($idx === false) {
        $_SESSION['import_errors'] = ["Missing required header: '" . ucwords($key) . "'. Please download and use the official template."];
        header("Location: employees.php");
        exit();
    }
    $headerIndices[$colName] = $idx;
}

$successCount = 0;
$errors = [];
$companyPrefixes = [
    "Commercial Micro Credit" => "CMC",
    "Monik International Pvt Ltd" => "MNK",
    "Ceylon Monik Building Society Limited" => "CMB",
    "Monik Homes Pvt Ltd" => "MNH",
    "Monik Water Pvt Ltd" => "MNW",
    "Monik Trading Pvt LTD" => "MNT",
    "Monik Agro Pvt Ltd" => "MNA",
    "Monik Land" => "MNL"
];

// Start processing from row index 1 (skip header)
for ($rowIdx = 1; $rowIdx < count($rows); $rowIdx++) {
    $rowData = $rows[$rowIdx];
    
    // Skip completely empty rows
    $nonEmpty = array_filter($rowData);
    if (empty($nonEmpty)) {
        continue;
    }

    $displayRowNumber = $rowIdx + 1; // 1-indexed Excel row

    // Extract values based on mapped header index
    $emp_no = isset($rowData[$headerIndices['emp_no']]) ? trim($rowData[$headerIndices['emp_no']]) : '';
    $calling_name = isset($rowData[$headerIndices['calling_name']]) ? trim($rowData[$headerIndices['calling_name']]) : '';
    $full_name = isset($rowData[$headerIndices['full_name']]) ? trim($rowData[$headerIndices['full_name']]) : '';
    $designation = isset($rowData[$headerIndices['designation']]) ? trim($rowData[$headerIndices['designation']]) : '';
    $companyName = isset($rowData[$headerIndices['company']]) ? trim($rowData[$headerIndices['company']]) : '';
    $branchName = isset($rowData[$headerIndices['branch']]) ? trim($rowData[$headerIndices['branch']]) : '';
    $nic_number = isset($rowData[$headerIndices['nic_number']]) ? trim($rowData[$headerIndices['nic_number']]) : '';
    $mobile_number = isset($rowData[$headerIndices['mobile_number']]) ? trim($rowData[$headerIndices['mobile_number']]) : '';
    $mail_address = isset($rowData[$headerIndices['mail_address']]) ? trim($rowData[$headerIndices['mail_address']]) : '';
    $joining_date = isset($rowData[$headerIndices['joining_date']]) ? trim($rowData[$headerIndices['joining_date']]) : '';

    // Convert date for all imports (CSV and XLSX)
    $joining_date = excelDateToPHP($joining_date);

    // Basic required field validations
    if (empty($emp_no) || empty($calling_name) || empty($full_name) || empty($designation) || empty($companyName) || empty($branchName) || empty($nic_number) || empty($mobile_number) || empty($mail_address) || empty($joining_date)) {
        $errors[] = "Row {$displayRowNumber}: All columns are required. Please fill in all fields.";
        continue;
    }

    // Lookup company
    $compStmt = $db->prepare("SELECT id, name FROM companies WHERE LOWER(name) = LOWER(:name) LIMIT 1");
    $compStmt->execute([':name' => $companyName]);
    $company = $compStmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        $errors[] = "Row {$displayRowNumber}: Company '{$companyName}' does not exist in the system.";
        continue;
    }

    // Lookup branch
    $branchStmt = $db->prepare("SELECT id, name FROM branches WHERE LOWER(name) = LOWER(:name) AND company_id = :company_id LIMIT 1");
    $branchStmt->execute([':name' => $branchName, ':company_id' => $company['id']]);
    $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$branch) {
        $errors[] = "Row {$displayRowNumber}: Branch '{$branchName}' does not exist under company '{$companyName}'.";
        continue;
    }

    $branch_id = $branch['id'];

    // Verify company prefix
    $expectedPrefix = isset($companyPrefixes[$company['name']]) ? $companyPrefixes[$company['name']] : '';
    if ($expectedPrefix && strpos($emp_no, $expectedPrefix) !== 0) {
        $errors[] = "Row {$displayRowNumber}: Invalid EMP NO '{$emp_no}'. For '{$company['name']}', it must start with '{$expectedPrefix}'.";
        continue;
    }

    // Try to insert
    try {
        $insertQuery = "INSERT INTO employees (branch_id, emp_no, full_name, calling_name, designation, joining_date, nic_number, mobile_number, mail_address) 
                        VALUES (:branch_id, :emp_no, :full_name, :calling_name, :designation, :joining_date, :nic_number, :mobile_number, :mail_address)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->execute([
            ':branch_id' => $branch_id,
            ':emp_no' => $emp_no,
            ':full_name' => $full_name,
            ':calling_name' => $calling_name,
            ':designation' => $designation,
            ':joining_date' => $joining_date,
            ':nic_number' => $nic_number,
            ':mobile_number' => $mobile_number,
            ':mail_address' => $mail_address
        ]);
        $successCount++;
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) { // Duplicate key
            if (strpos($e->getMessage(), 'emp_no') !== false) {
                $errors[] = "Row {$displayRowNumber}: EMP NO '{$emp_no}' already exists in the system.";
            } elseif (strpos($e->getMessage(), 'nic_number') !== false) {
                $errors[] = "Row {$displayRowNumber}: NIC number '{$nic_number}' already exists in the system.";
            } else {
                $errors[] = "Row {$displayRowNumber}: Duplicate key error.";
            }
        } else {
            $errors[] = "Row {$displayRowNumber}: Failed to save employee: " . $e->getMessage();
        }
    }
}

$_SESSION['import_success'] = $successCount;
if (!empty($errors)) {
    $_SESSION['import_errors'] = $errors;
}

header("Location: employees.php");
exit();
?>
