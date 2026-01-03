<?php
/**
 * AJAX endpoint to get users by office
 */

require_once '../config.php';
require_once '../classes/UserManager.php';

// Only allow AJAX requests
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Direct access not allowed']);
    exit;
}

// Check if office code is provided
if (!isset($_GET['office_code']) || empty($_GET['office_code'])) {
    echo json_encode(['success' => false, 'message' => 'Office code is required']);
    exit;
}

$officeCode = $_GET['office_code'];

try {
    $userManager = new UserManager();
    
    // Get users by office
    $users = $userManager->getAllUsers([
        'office_code' => $officeCode,
        'is_active' => 1
    ]);
    
    echo json_encode([
        'success' => true,
        'users' => $users
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_users_by_office.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching users'
    ]);
}