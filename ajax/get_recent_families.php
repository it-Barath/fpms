<?php
require_once '../config.php';
require_once '../classes/FormManager.php';

header('Content-Type: application/json');

// Check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = intval($_GET['user_id'] ?? 0);
$limit = intval($_GET['limit'] ?? 5);

$formManager = new FormManager();

try {
    // Get recent submissions
    $recentSubmissions = $formManager->getRecentSubmissions($userId, 10);
    
    // Extract unique families
    $families = [];
    $familyIds = [];
    
    foreach ($recentSubmissions as $submission) {
        if ($submission['entity_type'] === 'family' && !in_array($submission['family_id'], $familyIds)) {
            $families[] = [
                'family_id' => $submission['family_id'],
                'form_name' => $submission['form_name'],
                'submission_date' => $submission['submission_date'],
                'total_members' => 1 // You might want to fetch actual member count
            ];
            $familyIds[] = $submission['family_id'];
            
            if (count($families) >= $limit) {
                break;
            }
        }
    }
    
    echo json_encode(['success' => true, 'families' => $families]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>