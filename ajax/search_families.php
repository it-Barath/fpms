<?php
require_once '../config.php';
require_once '../classes/FamilyManager.php';

header('Content-Type: application/json');

// Check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userType = $_SESSION['user_type'];
$officeCode = $_SESSION['office_code'];
$searchTerm = $_GET['q'] ?? '';
$familyId = $_GET['family_id'] ?? '';
$headNic = $_GET['head_nic'] ?? '';

$familyManager = new FamilyManager();

try {
    $families = [];
    
    if ($familyId) {
        // Search by family ID
        $family = $familyManager->getFamilyById($familyId);
        if ($family) {
            // Check access permissions
            if ($userType === 'gn' && $family['gn_id'] !== $officeCode) {
                echo json_encode(['success' => true, 'families' => []]);
                exit;
            }
            $families = [$family];
        }
    } elseif ($headNic) {
        // Search by head NIC
        $families = $familyManager->searchFamiliesByHeadNIC($headNic, $officeCode, $userType);
    } elseif ($searchTerm) {
        // General search
        $families = $familyManager->searchFamilies($searchTerm, $officeCode, $userType);
    } else {
        // Get families for current user
        $families = $familyManager->getFamiliesByOffice($officeCode, $userType, 10);
    }
    
    echo json_encode(['success' => true, 'families' => $families]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>