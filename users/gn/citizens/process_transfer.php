<?php
// users/gn/citizens/process_transfer.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

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
        throw new Exception("Unauthorized access");
    }

    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    // Get database connections
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $ref_db = getRefConnection();
    
    $user_id = $_SESSION['user_id'];
    $office_name = $_SESSION['office_name'];
    
    // Get GN ID from session and remove "gn_" prefix
    $from_gn_id = $_SESSION['office_code'];
    if (strpos($from_gn_id, 'gn_') === 0) {
        $from_gn_id = substr($from_gn_id, 3);
    }
    
    // Get POST data
    $family_id = trim($_POST['family_id'] ?? '');
    $to_district = trim($_POST['to_district'] ?? '');
    $to_division = trim($_POST['to_division'] ?? '');
    $to_gn_id = trim($_POST['to_gn_id'] ?? '');
    $transfer_reason = trim($_POST['transfer_reason'] ?? '');
    $transfer_notes = trim($_POST['transfer_notes'] ?? '');
    
    // Validate required fields
    $required_fields = [
        'family_id' => $family_id,
        'to_district' => $to_district,
        'to_division' => $to_division,
        'to_gn_id' => $to_gn_id,
        'transfer_reason' => $transfer_reason
    ];
    
    foreach ($required_fields as $field => $value) {
        if (empty($value)) {
            throw new Exception(ucwords(str_replace('_', ' ', $field)) . " is required");
        }
    }
    
    // Start transaction
    $db->begin_transaction();
    
    // Get family details
    $family_query = "SELECT f.*, 
                            (SELECT full_name FROM citizens 
                             WHERE identification_number = f.family_head_nic 
                             AND identification_type = 'nic' 
                             LIMIT 1) as head_name,
                            (SELECT COUNT(*) FROM citizens WHERE family_id = f.family_id) as actual_members
                     FROM families f 
                     WHERE f.family_id = ? AND f.gn_id = ?";
    
    $stmt = $db->prepare($family_query);
    $stmt->bind_param("ss", $family_id, $from_gn_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $family_details = $result->fetch_assoc();
    
    if (!$family_details) {
        throw new Exception("Family not found or you do not have permission");
    }
    
    // Check if family is already transferred
    if ($family_details['is_transferred']) {
        throw new Exception("This family has already been transferred");
    }
    
    // Check if transferring to same GN
    if ($to_gn_id === $from_gn_id) {
        throw new Exception("Cannot transfer to the same GN division");
    }
    
    // Get current GN details
    $current_gn_query = "SELECT GN, District_Name, Division_Name, Province_Name 
                         FROM mobile_service.fix_work_station 
                         WHERE GN_ID = ?";
    $current_gn_stmt = $ref_db->prepare($current_gn_query);
    $current_gn_stmt->bind_param("s", $from_gn_id);
    $current_gn_stmt->execute();
    $current_gn_result = $current_gn_stmt->get_result();
    $current_gn = $current_gn_result->fetch_assoc();
    
    if (!$current_gn) {
        throw new Exception("Current GN division not found");
    }
    
    // Get destination GN details
    $dest_gn_query = "SELECT GN, District_Name, Division_Name, Province_Name 
                      FROM mobile_service.fix_work_station 
                      WHERE GN_ID = ?";
    $dest_gn_stmt = $ref_db->prepare($dest_gn_query);
    $dest_gn_stmt->bind_param("s", $to_gn_id);
    $dest_gn_stmt->execute();
    $dest_gn_result = $dest_gn_stmt->get_result();
    $dest_gn = $dest_gn_result->fetch_assoc();
    
    if (!$dest_gn) {
        throw new Exception("Selected GN division not found");
    }
    
    // Generate transfer ID
    $transfer_id = 'TRF' . date('YmdHis') . substr($family_id, -6);
    
    // Get all family members for the transfer
    $members_query = "SELECT citizen_id FROM citizens WHERE family_id = ?";
    $members_stmt = $db->prepare($members_query);
    $members_stmt->bind_param("s", $family_id);
    $members_stmt->execute();
    $members_result = $members_stmt->get_result();
    
    $citizen_ids = [];
    while ($member = $members_result->fetch_assoc()) {
        $citizen_ids[] = $member['citizen_id'];
    }
    
    if (empty($citizen_ids)) {
        throw new Exception("No family members found");
    }
    
    // Create transfer request for each family member
    $transfer_query = "INSERT INTO transfer_requests 
                      (citizen_id, family_id, from_gn_id, to_gn_id, 
                       division_code, to_division_code, status, 
                       reason, notes, requested_by_user_id, request_date) 
                      VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())";
    
    $transfer_stmt = $db->prepare($transfer_query);
    
    foreach ($citizen_ids as $citizen_id) {
        $transfer_stmt->bind_param("isssssssi", 
            $citizen_id,
            $family_id,
            $from_gn_id,
            $to_gn_id,
            $current_gn['Division_Name'] ?? '',
            $to_division,
            $transfer_reason,
            $transfer_notes,
            $user_id
        );
        
        if (!$transfer_stmt->execute()) {
            throw new Exception("Failed to create transfer request: " . $transfer_stmt->error);
        }
    }
    
    // Update family status to transferred
    $update_family = "UPDATE families SET is_transferred = 1 WHERE family_id = ?";
    $update_stmt = $db->prepare($update_family);
    $update_stmt->bind_param("s", $family_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update family status: " . $update_stmt->error);
    }
    
    // Create transfer history JSON
    $history_data = [
        'transfer_id' => $transfer_id,
        'from_gn' => [
            'id' => $from_gn_id,
            'name' => $current_gn['GN'] ?? '',
            'district' => $current_gn['District_Name'] ?? '',
            'division' => $current_gn['Division_Name'] ?? '',
            'province' => $current_gn['Province_Name'] ?? ''
        ],
        'to_gn' => [
            'id' => $to_gn_id,
            'name' => $dest_gn['GN'] ?? '',
            'district' => $dest_gn['District_Name'] ?? '',
            'division' => $dest_gn['Division_Name'] ?? '',
            'province' => $dest_gn['Province_Name'] ?? ''
        ],
        'reason' => $transfer_reason,
        'notes' => $transfer_notes,
        'requested_by' => [
            'user_id' => $user_id,
            'office_name' => $office_name
        ],
        'request_date' => date('Y-m-d H:i:s'),
        'status' => 'pending',
        'total_members' => count($citizen_ids)
    ];
    
    $history_json = json_encode($history_data, JSON_PRETTY_PRINT);
    
    // Update family transfer history
    $history_query = "UPDATE families SET transfer_history = ? WHERE family_id = ?";
    $history_stmt = $db->prepare($history_query);
    $history_stmt->bind_param("ss", $history_json, $family_id);
    
    if (!$history_stmt->execute()) {
        throw new Exception("Failed to update transfer history: " . $history_stmt->error);
    }
    
    // Log the action
    $action = 'transfer_request';
    $table = 'transfer_requests';
    $record_id = $transfer_id;
    $new_values = json_encode([
        'family_id' => $family_id,
        'from_gn' => $from_gn_id,
        'to_gn' => $to_gn_id,
        'reason' => $transfer_reason,
        'members_count' => count($citizen_ids)
    ]);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $log_stmt = $db->prepare("INSERT INTO audit_logs 
                              (user_id, action_type, table_name, record_id, new_values, ip_address, user_agent) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
    $log_stmt->bind_param("issssss", $user_id, $action, $table, $record_id, $new_values, $ip, $user_agent);
    
    if (!$log_stmt->execute()) {
        error_log("Failed to create audit log: " . $log_stmt->error);
        // Don't throw exception here, as this is not critical
    }
    
    // Get family members details for response
    $members_query = "SELECT c.*, 
                             (SELECT GROUP_CONCAT(education_level) FROM education 
                              WHERE citizen_id = c.citizen_id) as education_levels,
                             (SELECT GROUP_CONCAT(CONCAT(designation, ' (', employment_type, ')')) FROM employment 
                              WHERE citizen_id = c.citizen_id AND is_current_job = 1) as employment,
                             (SELECT GROUP_CONCAT(condition_name) FROM health_conditions 
                              WHERE citizen_id = c.citizen_id) as health_conditions
                      FROM citizens c 
                      WHERE c.family_id = ? 
                      ORDER BY CASE 
                          WHEN relation_to_head = 'Self' THEN 1
                          WHEN relation_to_head = 'Spouse' THEN 2
                          ELSE 3
                      END";
    
    $members_stmt = $db->prepare($members_query);
    $members_stmt->bind_param("s", $family_id);
    $members_stmt->execute();
    $members_result = $members_stmt->get_result();
    
    $family_members = [];
    while ($member = $members_result->fetch_assoc()) {
        $family_members[] = $member;
    }
    
    // Get land details
    $land_query = "SELECT * FROM land_details WHERE family_id = ?";
    $land_stmt = $db->prepare($land_query);
    $land_stmt->bind_param("s", $family_id);
    $land_stmt->execute();
    $land_result = $land_stmt->get_result();
    $land_details = $land_result->fetch_all(MYSQLI_ASSOC);
    
    // Commit transaction
    $db->commit();
    
    // Prepare success response with transfer slip data
    $response = [
        'success' => true,
        'message' => 'Transfer request submitted successfully',
        'transfer_id' => $transfer_id,
        'transfer_slip' => [
            'transfer_id' => $transfer_id,
            'family_id' => $family_id,
            'family_head' => $family_details['head_name'] ?? 'N/A',
            'family_head_nic' => $family_details['family_head_nic'] ?? 'N/A',
            'total_members' => $family_details['actual_members'] ?? count($family_members),
            'current_address' => $family_details['address'] ?? '',
            'from_gn' => $current_gn,
            'to_gn' => $dest_gn,
            'transfer_reason' => $transfer_reason,
            'transfer_notes' => $transfer_notes,
            'requested_by' => $office_name,
            'request_date' => date('d/m/Y H:i:s'),
            'members' => $family_members,
            'land_details' => $land_details
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    if (isset($db) && $db->connect_errno === 0) {
        $db->rollback();
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    
    error_log("Transfer Process Error: " . $e->getMessage());
}
?>