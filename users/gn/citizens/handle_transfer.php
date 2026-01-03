<?php
// division/handle_transfer.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../../config.php';
require_once '../../../classes/Auth.php';

session_start();
$auth = new Auth();

// Check if user is logged in and has division level access
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'division') {
    header('Location: ../login.php');
    exit();
}

$db = getMainConnection();
$user_id = $_SESSION['user_id'];

// Get action parameters
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$transfer_id = $_POST['transfer_id'] ?? $_GET['id'] ?? '';

if (!$action || !$transfer_id) {
    header('Location: transfer_requests.php?error=Invalid request');
    exit();
}

try {
    $db->begin_transaction();
    
    // Get transfer details
    $transfer_query = "SELECT th.*, f.gn_id as current_gn_id 
                      FROM transfer_history th
                      JOIN families f ON th.family_id = f.family_id
                      WHERE th.transfer_id = ?";
    $transfer_stmt = $db->prepare($transfer_query);
    $transfer_stmt->bind_param("s", $transfer_id);
    $transfer_stmt->execute();
    $transfer = $transfer_stmt->get_result()->fetch_assoc();
    
    if (!$transfer) {
        throw new Exception("Transfer request not found");
    }
    
    if ($action === 'approve') {
        // Check if already processed
        if ($transfer['current_status'] !== 'pending') {
            throw new Exception("Transfer request is already processed");
        }
        
        // Update transfer status
        $update_query = "UPDATE transfer_history 
                        SET current_status = 'approved', 
                            approved_by_user_id = ?, 
                            approval_date = NOW()
                        WHERE transfer_id = ?";
        
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bind_param("is", $user_id, $transfer_id);
        $update_stmt->execute();
        
        // Update family transfer history
        $history_data = [
            'status' => 'approved',
            'approved_by' => $user_id,
            'approved_by_name' => $_SESSION['username'],
            'approval_date' => date('Y-m-d H:i:s')
        ];
        
        $history_json = json_encode($history_data, JSON_UNESCAPED_UNICODE);
        $update_family = "UPDATE families SET transfer_history = ?, has_pending_transfer = 1 WHERE family_id = ?";
        $update_family_stmt = $db->prepare($update_family);
        $update_family_stmt->bind_param("ss", $history_json, $transfer['family_id']);
        $update_family_stmt->execute();
        
        $success_message = "Transfer request approved successfully!";
        
    } elseif ($action === 'reject') {
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        
        if (empty($rejection_reason)) {
            throw new Exception("Rejection reason is required");
        }
        
        // Check if already processed
        if ($transfer['current_status'] !== 'pending') {
            throw new Exception("Transfer request is already processed");
        }
        
        // Update transfer status
        $update_query = "UPDATE transfer_history 
                        SET current_status = 'rejected', 
                            rejected_by_user_id = ?, 
                            rejection_date = NOW(),
                            rejection_reason = ?
                        WHERE transfer_id = ?";
        
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bind_param("iss", $user_id, $rejection_reason, $transfer_id);
        $update_stmt->execute();
        
        // Update family status
        $update_family = "UPDATE families SET has_pending_transfer = 0 WHERE family_id = ?";
        $update_family_stmt = $db->prepare($update_family);
        $update_family_stmt->bind_param("s", $transfer['family_id']);
        $update_family_stmt->execute();
        
        $success_message = "Transfer request rejected successfully!";
        
    } elseif ($action === 'complete') {
        // This would be handled by the receiving GN officer
        throw new Exception("Completion must be done by receiving GN officer");
    }
    
    // Log the action
    $log_action = $action . '_transfer';
    $table_name = 'transfer_history';
    $record_id = $transfer_id;
    $new_values = json_encode([
        'status' => $action . 'd',
        'transfer_id' => $transfer_id,
        'action_by' => $user_id
    ], JSON_UNESCAPED_UNICODE);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $log_stmt = $db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values, ip_address, user_agent) 
                             VALUES (?, ?, ?, ?, ?, ?, ?)");
    $log_stmt->bind_param("issssss", $user_id, $log_action, $table_name, $record_id, $new_values, $ip, $user_agent);
    $log_stmt->execute();
    
    $db->commit();
    
    header("Location: transfer_requests.php?success=" . urlencode($success_message));
    exit();
    
} catch (Exception $e) {
    $db->rollback();
    header("Location: transfer_requests.php?error=" . urlencode($e->getMessage()));
    exit();
}