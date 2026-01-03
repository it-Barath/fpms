<?php
// users/gn/citizens/remove_profile_picture.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering
ob_start();

require_once '../../../config.php';
require_once '../../../classes/Auth.php';

// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize Auth
$auth = new Auth();

// Check if user is logged in and has GN level access
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'gn') {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Try regular POST if JSON fails
    $input = $_POST;
}

$citizen_id = $input['citizen_id'] ?? 0;
$action = $input['action'] ?? '';

if ($action !== 'remove_profile_picture' || !$citizen_id) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit();
}

try {
    // Check if directory exists
    $upload_dir = '../../../assets/uploads/members/' . $citizen_id . '/';
    
    if (file_exists($upload_dir) && is_dir($upload_dir)) {
        // Remove all files in directory
        $files = glob($upload_dir . '*');
        $deleted = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        // Remove directory
        if (rmdir($upload_dir)) {
            $message = "Profile picture removed successfully. Deleted $deleted file(s).";
        } else {
            $message = "Files removed but directory could not be deleted.";
        }
    } else {
        $message = "No profile picture found to remove.";
    }
    
    ob_end_clean();
    
    // Return success
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error removing profile picture: ' . $e->getMessage()
    ]);
}

exit();
?>