<?php
// forms/submit.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';

try {
    // Check if session is already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in
    if (!$auth->isLoggedIn()) {
        throw new Exception('Access denied');
    }
    
    // Get parameters
    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'draft';
    $target = isset($_POST['target']) ? trim($_POST['target']) : 'member';
    $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
    
    // Validate required parameters
    if (!$form_id) {
        throw new Exception('Form ID is required');
    }
    
    // Get database connection
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $gn_id = $_SESSION['office_code'] ?? '';
    
    // Initialize FormManager
    $formManager = new FormManager();
    
    // Get form details
    $form = $formManager->getForm($form_id);
    if (!$form) {
        throw new Exception('Form not found');
    }
    
    // Prepare data based on target
    if ($target === 'member') {
        $citizen_id = isset($_POST['citizen_id']) ? intval($_POST['citizen_id']) : 0;
        if (!$citizen_id) {
            throw new Exception('Citizen ID is required for member forms');
        }
        
        // Verify citizen exists and belongs to user's GN
        $citizen_query = "SELECT c.*, f.gn_id 
                         FROM citizens c 
                         JOIN families f ON c.family_id = f.family_id 
                         WHERE c.citizen_id = ? AND f.gn_id = ?";
        $stmt = $db->prepare($citizen_query);
        $stmt->bind_param("is", $citizen_id, $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Citizen not found or not in your GN division');
        }
        
        $citizen = $result->fetch_assoc();
        $family_id = $citizen['family_id'];
        
        // Handle submission
        if ($submission_id > 0) {
            // Update existing submission
            $result = $formManager->updateMemberSubmission(
                $submission_id,
                $form_id,
                $citizen_id,
                $family_id,
                $gn_id,
                $user_id,
                $_POST,
                $status
            );
        } else {
            // Create new submission
            $result = $formManager->createMemberSubmission(
                $form_id,
                $citizen_id,
                $family_id,
                $gn_id,
                $user_id,
                $_POST,
                $status
            );
        }
    } else {
        $family_id = isset($_POST['family_id']) ? trim($_POST['family_id']) : '';
        if (!$family_id) {
            throw new Exception('Family ID is required for family forms');
        }
        
        // Verify family exists and belongs to user's GN
        $family_query = "SELECT * FROM families WHERE family_id = ? AND gn_id = ?";
        $stmt = $db->prepare($family_query);
        $stmt->bind_param("ss", $family_id, $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Family not found or not in your GN division');
        }
        
        // Handle submission
        if ($submission_id > 0) {
            // Update existing submission
            $result = $formManager->updateFamilySubmission(
                $submission_id,
                $form_id,
                $family_id,
                $gn_id,
                $user_id,
                $_POST,
                $status
            );
        } else {
            // Create new submission
            $result = $formManager->createFamilySubmission(
                $form_id,
                $family_id,
                $gn_id,
                $user_id,
                $_POST,
                $status
            );
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => $status === 'draft' ? 'Form saved as draft' : 'Form submitted successfully',
        'submission_id' => $result['submission_id'] ?? 0
    ]);
    
} catch (Exception $e) {
    error_log("Form Submission Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}