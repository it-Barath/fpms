<?php
/**
 * check_form_code.php
 * Check if form code is available
 */

require_once '../config.php';
require_once '../classes/FormManager.php';

header('Content-Type: application/json');

// Get the form code from query parameter
$formCode = isset($_GET['code']) ? trim($_GET['code']) : '';

if (empty($formCode)) {
    echo json_encode(['available' => false, 'message' => 'No code provided']);
    exit();
}

// Validate format
if (!preg_match('/^[a-z0-9_]+$/', $formCode)) {
    echo json_encode(['available' => false, 'message' => 'Invalid format']);
    exit();
}

try {
    $formManager = new FormManager();
    $conn = getMainConnection();
    
    // Check if code exists
    $sql = "SELECT COUNT(*) as count FROM forms WHERE form_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $formCode);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $available = $row['count'] == 0;
    
    echo json_encode([
        'available' => $available,
        'message' => $available ? 'Code is available' : 'Code already exists'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'available' => false,
        'message' => 'Error checking code: ' . $e->getMessage()
    ]);
}