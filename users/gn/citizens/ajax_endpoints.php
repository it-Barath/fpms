<?php
// users/gn/citizens/ajax_endpoints.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../../../config.php';
    require_once '../../../classes/Auth.php';
    
    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in and has GN level access
    if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'gn') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }
    
    // Get database connection
    $ref_db = getRefConnection();
    if (!$ref_db) {
        throw new Exception("Database connection failed");
    }
    
    // Get action parameter
    $action = $_GET['action'] ?? '';
    
    // Set JSON header
    header('Content-Type: application/json');
    
    // Handle different actions
    switch ($action) {
        case 'get_districts':
            if (!isset($_GET['province'])) {
                echo json_encode(['success' => false, 'error' => 'Province parameter required']);
                exit();
            }
            
            $province = trim($_GET['province']);
            $district_query = "SELECT DISTINCT District_Name 
                               FROM mobile_service.fix_work_station 
                               WHERE Province_Name = ? AND District_Name IS NOT NULL 
                               ORDER BY District_Name";
            $district_stmt = $ref_db->prepare($district_query);
            $district_stmt->bind_param("s", $province);
            $district_stmt->execute();
            $district_result = $district_stmt->get_result();
            
            $districts = [];
            while ($row = $district_result->fetch_assoc()) {
                $districts[] = $row['District_Name'];
            }
            
            echo json_encode(['success' => true, 'districts' => $districts]);
            break;
            
        case 'get_divisions':
            if (!isset($_GET['district'])) {
                echo json_encode(['success' => false, 'error' => 'District parameter required']);
                exit();
            }
            
            $district = trim($_GET['district']);
            $division_query = "SELECT DISTINCT Division_Name 
                               FROM mobile_service.fix_work_station 
                               WHERE District_Name = ? AND Division_Name IS NOT NULL 
                               ORDER BY Division_Name";
            $division_stmt = $ref_db->prepare($division_query);
            $division_stmt->bind_param("s", $district);
            $division_stmt->execute();
            $division_result = $division_stmt->get_result();
            
            $divisions = [];
            while ($row = $division_result->fetch_assoc()) {
                $divisions[] = $row['Division_Name'];
            }
            
            echo json_encode(['success' => true, 'divisions' => $divisions]);
            break;
            
        case 'get_gn_divisions':
            if (!isset($_GET['division'])) {
                echo json_encode(['success' => false, 'error' => 'Division parameter required']);
                exit();
            }
            
            $division = trim($_GET['division']);
            $gn_query = "SELECT GN_ID, GN 
                         FROM mobile_service.fix_work_station 
                         WHERE Division_Name = ? AND GN_ID IS NOT NULL 
                         ORDER BY GN";
            $gn_stmt = $ref_db->prepare($gn_query);
            $gn_stmt->bind_param("s", $division);
            $gn_stmt->execute();
            $gn_result = $gn_stmt->get_result();
            
            $gn_divisions = [];
            while ($row = $gn_result->fetch_assoc()) {
                $gn_divisions[] = $row;
            }
            
            echo json_encode(['success' => true, 'gn_divisions' => $gn_divisions]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}