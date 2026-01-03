<?php
// users/gn/citizens/ajax_handler.php

// Disable all error output that could corrupt JSON
error_reporting(0);
ini_set('display_errors', 0);

// Set JSON header FIRST
header('Content-Type: application/json');

try {
    // Include config with absolute path
    $config_path = dirname(dirname(dirname(__DIR__))) . '/config.php';
    if (!file_exists($config_path)) {
        throw new Exception("Config file not found: " . $config_path);
    }
    require_once $config_path;
    
    // Initialize session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check if user is logged in (optional for public endpoints)
    // If you need auth, uncomment:
    /*
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'gn') {
        throw new Exception("Unauthorized access");
    }
    */
    
    // Get database connection
    $ref_db = getRefConnection();
    if (!$ref_db) {
        throw new Exception("Database connection failed");
    }
    
    // Get action parameter
    $action = $_GET['action'] ?? '';
    
    // Handle different actions
    switch ($action) {
        case 'get_districts':
            if (!isset($_GET['province'])) {
                throw new Exception("Province parameter required");
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
            
            echo json_encode([
                'success' => true, 
                'districts' => $districts,
                'count' => count($districts)
            ]);
            break;
            
        case 'get_divisions':
            if (!isset($_GET['district'])) {
                throw new Exception("District parameter required");
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
            
            echo json_encode([
                'success' => true, 
                'divisions' => $divisions,
                'count' => count($divisions)
            ]);
            break;
            
        case 'get_gn_divisions':
            if (!isset($_GET['division'])) {
                throw new Exception("Division parameter required");
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
            
            echo json_encode([
                'success' => true, 
                'gn_divisions' => $gn_divisions,
                'count' => count($gn_divisions)
            ]);
            break;
            
        case 'test':
            // Simple test endpoint
            echo json_encode([
                'success' => true,
                'message' => 'AJAX endpoint is working!',
                'timestamp' => date('Y-m-d H:i:s'),
                'server' => $_SERVER['SERVER_NAME']
            ]);
            break;
            
        default:
            throw new Exception("Invalid action. Valid actions: get_districts, get_divisions, get_gn_divisions, test");
    }
    
} catch (Exception $e) {
    // Return error as JSON
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

// Ensure no extra output
exit();