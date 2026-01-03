<?php
// division/transfer_requests.php - FIXED LAYOUT VERSION
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Transfer Requests";
$pageIcon = "fas fa-exchange-alt";
$pageDescription = "View and manage family transfer requests";
$bodyClass = "bg-light";

try {
    require_once '../../../config.php';
    require_once '../../../classes/Auth.php';
    require_once '../../../classes/Validator.php';
    
    // Don't start session if already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $auth = new Auth();
    
    // Check if user is logged in and has division OR GN level access
    if (!$auth->isLoggedIn() || !in_array($_SESSION['user_type'], ['division', 'gn'])) {
        header('Location: ../../../login.php');
        exit();
    }

    // Get database connections
    $db = getMainConnection();
    $ref_db = getRefConnection();
    
    // Validate and sanitize session data
    $user_id = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    $user_type = htmlspecialchars($_SESSION['user_type'] ?? '', ENT_QUOTES, 'UTF-8');
    $office_name = htmlspecialchars($_SESSION['office_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $office_code = htmlspecialchars($_SESSION['office_code'] ?? '', ENT_QUOTES, 'UTF-8');
    $username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
    
    if (!$user_id || empty($user_type) || empty($office_name)) {
        throw new Exception("Invalid session data");
    }
    
    // Remove prefixes based on user type
    $gn_id = '';
    $division_code = '';
    
    if ($user_type === 'gn' && strpos($office_code, 'gn_') === 0) {
        $gn_id = substr($office_code, 3); // Remove "gn_" prefix
        $gn_id = filter_var($gn_id, FILTER_SANITIZE_STRING);
    } elseif ($user_type === 'division' && strpos($office_code, 'division_') === 0) {
        $division_code = substr($office_code, 9); // Remove "division_" prefix
        $division_code = filter_var($division_code, FILTER_SANITIZE_STRING);
    } else {
        $gn_id = $office_code;
        $division_code = $office_code;
    }
    
    // Initialize variables
    $error = '';
    $success = '';
    $transfers = [];
    $stats = [
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'total' => 0
    ];
    
    // Get current division name for division officers
    $current_division = $office_name;
    
    if ($user_type === 'division') {
        try {
            // Add COLLATE to reference database query
            $division_query = "SELECT DISTINCT Division_Name 
                              FROM mobile_service.fix_work_station 
                              WHERE Division_Name = ? COLLATE utf8mb4_unicode_ci
                              LIMIT 1";
            
            $division_stmt = $ref_db->prepare($division_query);
            $division_stmt->bind_param("s", $office_name);
            $division_stmt->execute();
            $division_result = $division_stmt->get_result();
            
            if ($division_row = $division_result->fetch_assoc()) {
                $current_division = htmlspecialchars($division_row['Division_Name']);
            }
            $division_stmt->close();
        } catch (Exception $e) {
            error_log("Division query error: " . $e->getMessage());
            $current_division = $office_name;
        }
    }
    
    // Get filter parameters with validation
    $filter_status = isset($_GET['status']) && in_array($_GET['status'], ['all', 'pending', 'approved', 'rejected', 'completed', 'cancelled']) 
                     ? $_GET['status'] : 'all';
    
    $filter_date_from = isset($_GET['date_from']) ? filter_var($_GET['date_from'], FILTER_SANITIZE_STRING) : '';
    $filter_date_to = isset($_GET['date_to']) ? filter_var($_GET['date_to'], FILTER_SANITIZE_STRING) : '';
    $search = isset($_GET['search']) ? trim(filter_var($_GET['search'], FILTER_SANITIZE_STRING)) : '';
    
    // Validate dates if provided
    if (!empty($filter_date_from) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
        $filter_date_from = '';
    }
    
    if (!empty($filter_date_to) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
        $filter_date_to = '';
    }
    
    // Build query with COLLATE only for reference database joins
    $query = "SELECT 
                th.transfer_id,
                th.family_id,
                th.from_gn_id,
                th.to_gn_id,
                th.transfer_reason,
                th.transfer_notes,
                th.request_date,
                th.approval_date,
                th.rejection_date,
                th.completed_date,
                th.current_status,
                th.rejection_reason,
                th.completion_notes,
                th.requested_by_user_id,
                th.approved_by_user_id,
                th.rejected_by_user_id,
                th.completed_by_user_id,
                f.address,
                f.family_head_nic,
                f.total_members,
                f.gn_id as current_family_gn,
                (SELECT full_name FROM citizens 
                 WHERE identification_number = f.family_head_nic
                 AND identification_type = 'nic' 
                 LIMIT 1) as head_name,
                u1.username as requested_by_name,
                u1.office_name as requested_by_office,
                u2.username as approved_by_name,
                u3.username as completed_by_name,
                from_gn.GN as from_gn_name,
                from_gn.Division_Name as from_division,
                from_gn.District_Name as from_district,
                to_gn.GN as to_gn_name,
                to_gn.Division_Name as to_division,
                to_gn.District_Name as to_district
             FROM transfer_history th
             JOIN families f ON th.family_id = f.family_id
             JOIN users u1 ON th.requested_by_user_id = u1.user_id
             LEFT JOIN users u2 ON th.approved_by_user_id = u2.user_id
             LEFT JOIN users u3 ON th.completed_by_user_id = u3.user_id
             LEFT JOIN mobile_service.fix_work_station from_gn ON th.from_gn_id = from_gn.GN_ID COLLATE utf8mb4_unicode_ci
             LEFT JOIN mobile_service.fix_work_station to_gn ON th.to_gn_id = to_gn.GN_ID COLLATE utf8mb4_unicode_ci
             WHERE 1=1";
    
    $params = [];
    $types = '';
    
    // Apply filters based on user type - ADD COLLATE for reference database comparisons
    if ($user_type === 'division') {
        $query .= " AND (from_gn.Division_Name = ? COLLATE utf8mb4_unicode_ci OR to_gn.Division_Name = ? COLLATE utf8mb4_unicode_ci)";
        $params[] = $current_division;
        $params[] = $current_division;
        $types .= "ss";
    } elseif ($user_type === 'gn') {
        $query .= " AND (th.from_gn_id = ? OR th.to_gn_id = ?)";
        $params[] = $gn_id;
        $params[] = $gn_id;
        $types .= "ss";
    }
    
    // Apply status filter
    if ($filter_status !== 'all') {
        $query .= " AND th.current_status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
    
    // Apply date filter
    if (!empty($filter_date_from)) {
        $query .= " AND DATE(th.request_date) >= ?";
        $params[] = $filter_date_from;
        $types .= "s";
    }
    
    if (!empty($filter_date_to)) {
        $query .= " AND DATE(th.request_date) <= ?";
        $params[] = $filter_date_to;
        $types .= "s";
    }
    
    // Apply search filter
    if (!empty($search)) {
        $search_term = "%$search%";
        $query .= " AND (th.transfer_id LIKE ? OR 
                        th.family_id LIKE ? OR 
                        f.family_head_nic LIKE ? OR 
                        f.address LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ssss";
    }
    
    $query .= " ORDER BY th.request_date DESC";
    
    try {
        $stmt = $db->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Failed to prepare query: " . $db->error);
        }
        
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute query: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Sanitize all output data, ensure all keys exist
            $row = array_map(function($value) {
                return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
            }, $row);
            
            // Ensure required fields have default values if missing
            $row['requested_by_user_id'] = $row['requested_by_user_id'] ?? 0;
            $row['from_gn_id'] = $row['from_gn_id'] ?? '';
            $row['to_gn_id'] = $row['to_gn_id'] ?? '';
            $row['current_status'] = $row['current_status'] ?? '';
            
            $transfers[] = $row;
            
            $status = $row['current_status'] ?? '';
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
            $stats['total']++;
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $error = "Query Error: " . $e->getMessage();
        error_log("Transfer query error: " . $e->getMessage() . "\nQuery: " . $query);
    }
    
    // Generate CSRF token for forms
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];
    
    // Handle actions (approve/reject/complete) with CSRF protection
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Simple CSRF check
        if (!isset($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || 
            $_SESSION['csrf_token'] !== $_POST['csrf_token']) {
            $error = "Security token validation failed. Please try again.";
        } else {
            $action = filter_var($_POST['action'] ?? '', FILTER_SANITIZE_STRING);
            $transfer_id = filter_var($_POST['transfer_id'] ?? '', FILTER_SANITIZE_STRING);
            $note = filter_var($_POST['note'] ?? '', FILTER_SANITIZE_STRING);
            
            if ($action && $transfer_id) {
                try {
                    $db->begin_transaction();
                    
                    // Get transfer details with prepared statement
                    $transfer_query = "SELECT th.*, f.gn_id as current_gn_id, f.family_id
                                      FROM transfer_history th
                                      JOIN families f ON th.family_id = f.family_id
                                      WHERE th.transfer_id = ?";
                    $transfer_stmt = $db->prepare($transfer_query);
                    $transfer_stmt->bind_param("s", $transfer_id);
                    $transfer_stmt->execute();
                    $transfer_result = $transfer_stmt->get_result();
                    $transfer = $transfer_result->fetch_assoc();
                    $transfer_stmt->close();
                    
                    if (!$transfer) {
                        throw new Exception("Transfer request not found");
                    }
                    
                    // Validate user permissions and action
                    if ($action === 'approve') {
                        // Only division officers can approve
                        if ($user_type !== 'division') {
                            throw new Exception("Only division officers can approve transfers");
                        }
                        
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
                        
                        if (!$update_stmt->execute()) {
                            throw new Exception("Failed to approve transfer: " . $update_stmt->error);
                        }
                        $update_stmt->close();
                        
                        // Update family transfer history
                        $history_data = [
                            'status' => 'approved',
                            'approved_by' => $user_id,
                            'approved_by_name' => $username,
                            'approval_date' => date('Y-m-d H:i:s'),
                            'note' => $note
                        ];
                        
                        $history_json = json_encode($history_data, JSON_UNESCAPED_UNICODE);
                        $update_family = "UPDATE families SET transfer_history = ? WHERE family_id = ?";
                        $update_family_stmt = $db->prepare($update_family);
                        $update_family_stmt->bind_param("ss", $history_json, $transfer['family_id']);
                        $update_family_stmt->execute();
                        $update_family_stmt->close();
                        
                        $success = "Transfer request approved successfully!";
                        
                    } elseif ($action === 'reject') {
                        // Division officers can reject pending transfers
                        // GN officers can only reject transfers they requested
                        if ($user_type === 'division') {
                            // Division officer rejecting
                            if (empty($note)) {
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
                            $update_stmt->bind_param("iss", $user_id, $note, $transfer_id);
                            
                            if (!$update_stmt->execute()) {
                                throw new Exception("Failed to reject transfer: " . $update_stmt->error);
                            }
                            $update_stmt->close();
                            
                            // Update family status
                            $update_family = "UPDATE families SET has_pending_transfer = 0 WHERE family_id = ?";
                            $update_family_stmt = $db->prepare($update_family);
                            $update_family_stmt->bind_param("s", $transfer['family_id']);
                            $update_family_stmt->execute();
                            $update_family_stmt->close();
                            
                            // Update family transfer history
                            $history_data = [
                                'status' => 'rejected',
                                'rejected_by' => $user_id,
                                'rejected_by_name' => $username,
                                'rejection_date' => date('Y-m-d H:i:s'),
                                'reason' => $note
                            ];
                            
                            $history_json = json_encode($history_data, JSON_UNESCAPED_UNICODE);
                            $update_family_history = "UPDATE families SET transfer_history = ? WHERE family_id = ?";
                            $update_family_history_stmt = $db->prepare($update_family_history);
                            $update_family_history_stmt->bind_param("ss", $history_json, $transfer['family_id']);
                            $update_family_history_stmt->execute();
                            $update_family_history_stmt->close();
                            
                            $success = "Transfer request rejected successfully!";
                            
                        } elseif ($user_type === 'gn') {
                            // GN officer can cancel their own pending transfer
                            if (!isset($transfer['requested_by_user_id']) || $transfer['requested_by_user_id'] != $user_id) {
                                throw new Exception("You can only cancel transfers you requested");
                            }
                            
                            if ($transfer['current_status'] !== 'pending') {
                                throw new Exception("Only pending transfers can be cancelled");
                            }
                            
                            $cancel_reason = !empty($note) ? $note : 'Cancelled by requesting GN officer';
                            
                            // Update transfer status to cancelled
                            $update_query = "UPDATE transfer_history 
                                            SET current_status = 'cancelled', 
                                                rejected_by_user_id = ?, 
                                                rejection_date = NOW(),
                                                rejection_reason = ?
                                            WHERE transfer_id = ? AND requested_by_user_id = ?";
                            
                            $update_stmt = $db->prepare($update_query);
                            $update_stmt->bind_param("issi", $user_id, $cancel_reason, $transfer_id, $user_id);
                            
                            if (!$update_stmt->execute()) {
                                throw new Exception("Failed to cancel transfer: " . $update_stmt->error);
                            }
                            $update_stmt->close();
                            
                            // Update family status
                            $update_family = "UPDATE families SET has_pending_transfer = 0 WHERE family_id = ?";
                            $update_family_stmt = $db->prepare($update_family);
                            $update_family_stmt->bind_param("s", $transfer['family_id']);
                            $update_family_stmt->execute();
                            $update_family_stmt->close();
                            
                            // Update family transfer history
                            $history_data = [
                                'status' => 'cancelled',
                                'cancelled_by' => $user_id,
                                'cancelled_by_name' => $username,
                                'cancellation_date' => date('Y-m-d H:i:s'),
                                'reason' => $cancel_reason
                            ];
                            
                            $history_json = json_encode($history_data, JSON_UNESCAPED_UNICODE);
                            $update_family_history = "UPDATE families SET transfer_history = ? WHERE family_id = ?";
                            $update_family_history_stmt = $db->prepare($update_family_history);
                            $update_family_history_stmt->bind_param("ss", $history_json, $transfer['family_id']);
                            $update_family_history_stmt->execute();
                            $update_family_history_stmt->close();
                            
                            $success = "Transfer request cancelled successfully!";
                        }
                        
                    } elseif ($action === 'complete') {
                        // Only GN officers can complete transfers to their GN
                        if ($user_type !== 'gn') {
                            throw new Exception("Only GN officers can complete transfers");
                        }
                        
                        // Check if transfer is approved
                        if ($transfer['current_status'] !== 'approved') {
                            throw new Exception("Only approved transfers can be completed");
                        }
                        
                        // Check if this GN is the receiving GN
                        if ($transfer['to_gn_id'] !== $gn_id) {
                            throw new Exception("Only the receiving GN officer can complete the transfer");
                        }
                        
                        // Update transfer status
                        $completion_note = !empty($note) ? $note : 'Transfer completed';
                        $update_query = "UPDATE transfer_history 
                                        SET current_status = 'completed', 
                                            completed_by_user_id = ?, 
                                            completed_date = NOW(),
                                            completion_notes = ?
                                        WHERE transfer_id = ?";
                        
                        $update_stmt = $db->prepare($update_query);
                        $update_stmt->bind_param("iss", $user_id, $completion_note, $transfer_id);
                        
                        if (!$update_stmt->execute()) {
                            throw new Exception("Failed to complete transfer: " . $update_stmt->error);
                        }
                        $update_stmt->close();
                        
                        // Update family GN ID to new GN
                        $update_family_gn = "UPDATE families SET gn_id = ?, has_pending_transfer = 0, is_transferred = 1 WHERE family_id = ?";
                        $update_family_gn_stmt = $db->prepare($update_family_gn);
                        $update_family_gn_stmt->bind_param("ss", $gn_id, $transfer['family_id']);
                        $update_family_gn_stmt->execute();
                        $update_family_gn_stmt->close();
                        
                        // Update family transfer history
                        $history_data = [
                            'status' => 'completed',
                            'completed_by' => $user_id,
                            'completed_by_name' => $username,
                            'completion_date' => date('Y-m-d H:i:s'),
                            'new_gn_id' => $gn_id,
                            'note' => $completion_note
                        ];
                        
                        $history_json = json_encode($history_data, JSON_UNESCAPED_UNICODE);
                        $update_family = "UPDATE families SET transfer_history = ? WHERE family_id = ?";
                        $update_family_stmt = $db->prepare($update_family);
                        $update_family_stmt->bind_param("ss", $history_json, $transfer['family_id']);
                        $update_family_stmt->execute();
                        $update_family_stmt->close();
                        
                        $success = "Transfer completed successfully! Family now belongs to your GN division.";
                    }
                    
                    // Log the action
                    $log_action = $action . '_transfer';
                    $table_name = 'transfer_history';
                    $record_id = $transfer_id;
                    $new_values = json_encode([
                        'status' => $action . ($action === 'complete' ? 'd' : ($action === 'approve' ? 'd' : 'ed')),
                        'transfer_id' => $transfer_id,
                        'action_by' => $user_id,
                        'note' => $note
                    ], JSON_UNESCAPED_UNICODE);
                    $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? 'unknown', FILTER_VALIDATE_IP) ?: 'unknown';
                    $user_agent = filter_var($_SERVER['HTTP_USER_AGENT'] ?? '', FILTER_SANITIZE_STRING);
                    
                    $log_stmt = $db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values, ip_address, user_agent) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $log_stmt->bind_param("issssss", $user_id, $log_action, $table_name, $record_id, $new_values, $ip, $user_agent);
                    $log_stmt->execute();
                    $log_stmt->close();
                    
                    $db->commit();
                    
                    // Refresh page to show updated data
                    header("Location: transfer_requests.php?success=" . urlencode($success));
                    exit();
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $error = "Error: " . $e->getMessage();
                }
            }
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Transfer Requests Error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
}




function createTransferNotification($db, $user_id, $family_id, $from_gn, $to_gn, $transfer_id) {
    $message = "Family $family_id has requested transfer from GN $from_gn to GN $to_gn";
    $link = "transfer_requests.php?transfer_id=" . urlencode($transfer_id);
    
    $query = "INSERT INTO notifications (user_id, notification_type, title, message, is_read, link) 
              VALUES (?, 'transfer', 'New Transfer Request', ?, 0, ?)";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param("iss", $user_id, $message, $link);
    return $stmt->execute();
}













// Include header
require_once '../../../includes/header.php';
?>

<style>
/* Main content adjustment for sidebar */
.main-content {
    margin-left: 280px;
    width: calc(100% - 280px);
    padding: 20px;
    min-height: calc(100vh - 60px);
    transition: all 0.3s ease;
}

@media (max-width: 991.98px) {
    .main-content {
        margin-left: 0;
        width: 100%;
        padding: 15px;
    }
}

/* Statistics cards */
.stat-card {
    transition: transform 0.2s;
    border-radius: 10px;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}

/* Table styling */
.table th {
    background-color: #f8f9fa;
    font-weight: 600;
    vertical-align: middle;
}

.table td {
    vertical-align: middle;
}

/* Modal styling */
.modal-backdrop {
    z-index: 1040;
}

.modal {
    z-index: 1050;
}

/* Badge styling */
.badge {
    font-weight: 500;
    padding: 5px 10px;
}

/* Button group styling */
.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Action buttons container */
.action-buttons {
    white-space: nowrap;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table-responsive {
        border: none;
    }
    
    .btn-group {
        flex-wrap: wrap;
    }
    
    .btn-group .btn {
        margin-bottom: 5px;
    }
}
</style>



<?php 
require_once '../../../includes/header.php';
?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../../../includes/sidebar.php'; ?>
        </div>


<div>
    <!-- Page Header -->
    <div class="">
        <h1 class="h2">
            
                <?php 
                echo htmlspecialchars($office_name); 
                if ($user_type === 'gn') {
                    echo ' (GN Officer)';
                } else {
                    echo ' (Division Officer)';
                }
                ?>
            
        </h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button class="btn btn-outline-secondary me-2" onclick="window.print()">
                <i class="fas fa-print"></i> Print Report
            </button>
            <button class="btn btn-outline-success" onclick="exportToExcel()">
                <i class="fas fa-file-excel"></i> Export
            </button>
        </div>
    </div>
    
    <!-- Flash Messages -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_GET['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-primary text-white stat-card">
                <div class="card-body text-center py-3">
                    <h6 class="card-title mb-1">Total</h6>
                    <h2 class="mb-0"><?php echo $stats['total']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-warning text-white stat-card">
                <div class="card-body text-center py-3">
                    <h6 class="card-title mb-1">Pending</h6>
                    <h2 class="mb-0"><?php echo $stats['pending']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-info text-white stat-card">
                <div class="card-body text-center py-3">
                    <h6 class="card-title mb-1">Approved</h6>
                    <h2 class="mb-0"><?php echo $stats['approved']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-success text-white stat-card">
                <div class="card-body text-center py-3">
                    <h6 class="card-title mb-1">Completed</h6>
                    <h2 class="mb-0"><?php echo $stats['completed']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-danger text-white stat-card">
                <div class="card-body text-center py-3">
                    <h6 class="card-title mb-1">Rejected</h6>
                    <h2 class="mb-0"><?php echo $stats['rejected']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-secondary text-white stat-card">
                <div class="card-body text-center py-3">
                    <h6 class="card-title mb-1">This Month</h6>
                    <h2 class="mb-0"><?php 
                        $month_count = 0;
                        $current_month = date('Y-m');
                        foreach ($transfers as $t) {
                            if (date('Y-m', strtotime($t['request_date'])) === $current_month) {
                                $month_count++;
                            }
                        }
                        echo $month_count;
                    ?></h2>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Search and Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i> Search & Filter</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">From Date</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">To Date</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Search</label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" 
                               placeholder="Transfer ID, Family ID, NIC..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                        <?php if (!empty($search) || !empty($filter_date_from) || !empty($filter_date_to) || $filter_status !== 'all'): ?>
                            <a href="transfer_requests.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12">
                    <div class="d-grid d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- User Role Info -->
    <div class="alert alert-info mb-4">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Your Role: <?php echo strtoupper($user_type); ?> Officer</strong> - 
        <?php if ($user_type === 'division'): ?>
            You can approve or reject pending transfer requests in your division.
        <?php else: ?>
            You can view transfers involving your GN division. You can:
            <ul class="mb-0">
                <li>Complete approved transfers to your GN</li>
                <li>Cancel pending transfers you requested</li>
                <li>View all transfer history</li>
            </ul>
        <?php endif; ?>
    </div>
    
    <!-- Transfer Requests Table -->
    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i> Transfer Requests</h5>
            <span class="badge bg-primary fs-6 p-2">Total: <?php echo count($transfers); ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($transfers)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-exchange-alt fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No Transfer Requests Found</h4>
                    <p class="text-muted">No transfer requests match your current filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Transfer ID</th>
                                <th>Family Details</th>
                                <th>From → To</th>
                                <th>Reason</th>
                                <th>Requested</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transfers as $transfer): 
                                // Determine user's relationship to this transfer
                                $is_requesting_gn = ($transfer['from_gn_id'] == $gn_id);
                                $is_receiving_gn = ($transfer['to_gn_id'] == $gn_id);
                                $is_requested_by_me = ($transfer['requested_by_user_id'] == $user_id);
                                
                                // FIXED: Clear logic for button display
                                $show_approve_reject = ($user_type === 'division' && $transfer['current_status'] === 'pending');
                                $show_complete = ($user_type === 'gn' && $transfer['current_status'] === 'approved' && $is_receiving_gn);
                                $show_cancel = ($user_type === 'gn' && $transfer['current_status'] === 'pending' && $is_requested_by_me);
                            ?>
                                <tr>
                                    <td>
                                        <strong class="font-monospace"><?php echo $transfer['transfer_id']; ?></strong><br>
                                        <small class="text-muted">Family: <?php echo $transfer['family_id']; ?></small>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo $transfer['head_name'] ?? 'N/A'; ?></strong><br>
                                            <small class="text-muted">
                                                NIC: <?php echo $transfer['family_head_nic']; ?><br>
                                                Members: <?php echo $transfer['total_members']; ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <small>
                                                <strong>From:</strong> <?php echo $transfer['from_gn_name'] ?? $transfer['from_gn_id']; ?><br>
                                                <span class="text-muted"><?php echo $transfer['from_district'] ?? ''; ?></span>
                                            </small>
                                            <small class="mt-1">
                                                <strong>To:</strong> <?php echo $transfer['to_gn_name'] ?? $transfer['to_gn_id']; ?><br>
                                                <span class="text-muted"><?php echo $transfer['to_district'] ?? ''; ?></span>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <small><?php echo $transfer['transfer_reason']; ?></small>
                                        <?php if (!empty($transfer['transfer_notes'])): ?>
                                            <br><small class="text-muted"><?php echo substr($transfer['transfer_notes'], 0, 50); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <?php echo date('d/m/Y', strtotime($transfer['request_date'])); ?><br>
                                            <?php echo date('H:i', strtotime($transfer['request_date'])); ?>
                                        </small><br>
                                        <small class="text-muted">By: <?php echo $transfer['requested_by_name']; ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $status_badge = [
                                            'pending' => 'warning',
                                            'approved' => 'info',
                                            'rejected' => 'danger',
                                            'completed' => 'success',
                                            'cancelled' => 'secondary'
                                        ];
                                        $status_text = [
                                            'pending' => 'Pending',
                                            'approved' => 'Approved',
                                            'rejected' => 'Rejected',
                                            'completed' => 'Completed',
                                            'cancelled' => 'Cancelled'
                                        ];
                                        $current_status = $transfer['current_status'] ?? '';
                                        ?>
                                        <span class="badge bg-<?php echo $status_badge[$current_status] ?? 'secondary'; ?>">
                                            <?php echo $status_text[$current_status] ?? ucfirst($current_status); ?>
                                        </span>
                                        <?php if ($current_status === 'approved' && !empty($transfer['approval_date'])): ?>
                                            <br><small class="text-muted">
                                                <?php echo date('d/m/Y', strtotime($transfer['approval_date'])); ?>
                                            </small>
                                        <?php elseif ($current_status === 'rejected' && !empty($transfer['rejection_date'])): ?>
                                            <br><small class="text-muted">
                                                Rejected: <?php echo date('d/m/Y', strtotime($transfer['rejection_date'])); ?>
                                            </small>
                                        <?php elseif ($current_status === 'completed' && !empty($transfer['completed_date'])): ?>
                                            <br><small class="text-muted">
                                                Completed: <?php echo date('d/m/Y', strtotime($transfer['completed_date'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="action-buttons">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <!-- View Details Button -->
                                            <button type="button" class="btn btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#viewModal<?php echo htmlspecialchars(str_replace(['-', ' '], '', $transfer['transfer_id'])); ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <!-- Action Buttons (Functional) -->
                                            <?php if ($show_approve_reject): ?>
                                                <!-- Approve Button (Division only) -->
                                                <button type="button" class="btn btn-success" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#approveModal<?php echo htmlspecialchars(str_replace(['-', ' '], '', $transfer['transfer_id'])); ?>">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                
                                                <!-- Reject Button (Division only) --> 
                                                <button type="button" class="btn btn-danger" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#rejectModal<?php echo htmlspecialchars(str_replace(['-', ' '], '', $transfer['transfer_id'])); ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                                
                                            <?php elseif ($show_complete): ?>
                                                <!-- Complete Button (Receiving GN only) -->
                                                <button type="button" class="btn btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#completeModal<?php echo htmlspecialchars(str_replace(['-', ' '], '', $transfer['transfer_id'])); ?>">
                                                    <i class="fas fa-check-double"></i>
                                                </button>
                                                
                                            <?php elseif ($show_cancel): ?>
                                                <!-- Cancel Button (Requesting GN only) -->
                                                <button type="button" class="btn btn-warning" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#cancelModal<?php echo htmlspecialchars(str_replace(['-', ' '], '', $transfer['transfer_id'])); ?>">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Pagination (if needed) -->
            <?php if (count($transfers) > 20): ?>
                <div class="card-footer">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item disabled">
                                <a class="page-link" href="#" tabindex="-1">Previous</a>
                            </li>
                            <li class="page-item active">
                                <a class="page-link" href="#">1</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">2</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">3</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modals for each transfer -->
<?php foreach ($transfers as $transfer): 
    $transfer_id_clean = htmlspecialchars(str_replace(['-', ' '], '', $transfer['transfer_id']));
    $is_requesting_gn = ($transfer['from_gn_id'] == $gn_id);
    $is_receiving_gn = ($transfer['to_gn_id'] == $gn_id);
    $is_requested_by_me = ($transfer['requested_by_user_id'] == $user_id);
    
    $show_approve_reject = ($user_type === 'division' && $transfer['current_status'] === 'pending');
    $show_complete = ($user_type === 'gn' && $transfer['current_status'] === 'approved' && $is_receiving_gn);
    $show_cancel = ($user_type === 'gn' && $transfer['current_status'] === 'pending' && $is_requested_by_me);
?>

<!-- View Modal -->
<div class="modal fade" id="viewModal<?php echo $transfer_id_clean; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-eye me-2"></i> Transfer Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Transfer Information</h6>
                        <p><strong>Transfer ID:</strong> <?php echo $transfer['transfer_id']; ?></p>
                        <p><strong>Status:</strong> 
                            <span class="badge bg-<?php echo $status_badge[$transfer['current_status']] ?? 'secondary'; ?>">
                                <?php echo ucfirst($transfer['current_status']); ?>
                            </span>
                        </p>
                        <p><strong>Reason:</strong> <?php echo $transfer['transfer_reason']; ?></p>
                        <?php if (!empty($transfer['transfer_notes'])): ?>
                            <p><strong>Notes:</strong> <?php echo $transfer['transfer_notes']; ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <h6>Family Information</h6>
                        <p><strong>Family ID:</strong> <?php echo $transfer['family_id']; ?></p>
                        <p><strong>Head of Family:</strong> <?php echo $transfer['head_name'] ?? 'N/A'; ?></p>
                        <p><strong>Head NIC:</strong> <?php echo $transfer['family_head_nic']; ?></p>
                        <p><strong>Total Members:</strong> <?php echo $transfer['total_members']; ?></p>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <h6>Transferring From</h6>
                        <p><strong>GN Division:</strong> <?php echo $transfer['from_gn_name'] ?? $transfer['from_gn_id']; ?></p>
                        <p><strong>Division:</strong> <?php echo $transfer['from_division'] ?? 'N/A'; ?></p>
                        <p><strong>District:</strong> <?php echo $transfer['from_district'] ?? 'N/A'; ?></p>
                    </div>
                    <div class="col-md-6">
                        <h6>Transferring To</h6>
                        <p><strong>GN Division:</strong> <?php echo $transfer['to_gn_name'] ?? $transfer['to_gn_id']; ?></p>
                        <p><strong>Division:</strong> <?php echo $transfer['to_division'] ?? 'N/A'; ?></p>
                        <p><strong>District:</strong> <?php echo $transfer['to_district'] ?? 'N/A'; ?></p>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <h6>Timeline</h6>
                        <p><strong>Requested:</strong> <?php echo date('d/m/Y H:i', strtotime($transfer['request_date'])); ?></p>
                        <p><strong>By:</strong> <?php echo $transfer['requested_by_name']; ?></p>
                        
                        <?php if (!empty($transfer['approval_date'])): ?>
                            <p><strong>Approved:</strong> <?php echo date('d/m/Y H:i', strtotime($transfer['approval_date'])); ?></p>
                            <?php if (!empty($transfer['approved_by_name'])): ?>
                                <p><strong>Approved By:</strong> <?php echo $transfer['approved_by_name']; ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if (!empty($transfer['completed_date'])): ?>
                            <p><strong>Completed:</strong> <?php echo date('d/m/Y H:i', strtotime($transfer['completed_date'])); ?></p>
                            <?php if (!empty($transfer['completed_by_name'])): ?>
                                <p><strong>Completed By:</strong> <?php echo $transfer['completed_by_name']; ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <?php if (!empty($transfer['rejection_date'])): ?>
                            <h6>Rejection Details</h6>
                            <p><strong>Rejected:</strong> <?php echo date('d/m/Y H:i', strtotime($transfer['rejection_date'])); ?></p>
                            <?php if (!empty($transfer['rejection_reason'])): ?>
                                <p><strong>Reason:</strong> <?php echo $transfer['rejection_reason']; ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if (!empty($transfer['completion_notes'])): ?>
                            <h6>Completion Notes</h6>
                            <p><?php echo $transfer['completion_notes']; ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Details
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal (Division only) -->
<?php if ($show_approve_reject): ?>
<div class="modal fade" id="approveModal<?php echo $transfer_id_clean; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="transfer_id" value="<?php echo $transfer['transfer_id']; ?>">
                
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-check"></i> Approve Transfer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve this transfer?</p>
                    <div class="alert alert-success">
                        <strong>Transfer ID:</strong> <?php echo $transfer['transfer_id']; ?><br>
                        <strong>Family:</strong> <?php echo $transfer['head_name'] ?? 'N/A'; ?><br>
                        <strong>From:</strong> <?php echo $transfer['from_gn_name'] ?? $transfer['from_gn_id']; ?><br>
                        <strong>To:</strong> <?php echo $transfer['to_gn_name'] ?? $transfer['to_gn_id']; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Approval Note (Optional)</label>
                        <textarea class="form-control" name="note" rows="3" placeholder="Add a note..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reject Modal (Division only) -->
<?php if ($show_approve_reject): ?>
<div class="modal fade" id="rejectModal<?php echo $transfer_id_clean; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="transfer_id" value="<?php echo $transfer['transfer_id']; ?>">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-times"></i> Reject Transfer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject this transfer?</p>
                    <div class="alert alert-danger">
                        <strong>Transfer ID:</strong> <?php echo $transfer['transfer_id']; ?><br>
                        <strong>Family:</strong> <?php echo $transfer['head_name'] ?? 'N/A'; ?><br>
                        <strong>From:</strong> <?php echo $transfer['from_gn_name'] ?? $transfer['from_gn_id']; ?><br>
                        <strong>To:</strong> <?php echo $transfer['to_gn_name'] ?? $transfer['to_gn_id']; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rejection Reason *</label>
                        <textarea class="form-control" name="note" rows="3" placeholder="Reason for rejection..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Complete Modal (GN only) -->
<?php if ($show_complete): ?>
<div class="modal fade" id="completeModal<?php echo $transfer_id_clean; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="transfer_id" value="<?php echo $transfer['transfer_id']; ?>">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-check-double"></i> Complete Transfer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to complete this transfer?</p>
                    <div class="alert alert-success">
                        <strong>Transfer ID:</strong> <?php echo $transfer['transfer_id']; ?><br>
                        <strong>Family:</strong> <?php echo $transfer['head_name'] ?? 'N/A'; ?><br>
                        <strong>From:</strong> <?php echo $transfer['from_gn_name'] ?? $transfer['from_gn_id']; ?><br>
                        <strong>To:</strong> <strong><?php echo $transfer['to_gn_name'] ?? $transfer['to_gn_id']; ?> (Your GN)</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Completion Notes (Optional)</label>
                        <textarea class="form-control" name="note" rows="3" placeholder="Add completion notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Complete Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Cancel Modal (GN only) -->
<?php if ($show_cancel): ?>
<div class="modal fade" id="cancelModal<?php echo $transfer_id_clean; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="transfer_id" value="<?php echo $transfer['transfer_id']; ?>">
                
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-ban"></i> Cancel Transfer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to cancel this transfer request?</p>
                    <div class="alert alert-warning">
                        <strong>Transfer ID:</strong> <?php echo $transfer['transfer_id']; ?><br>
                        <strong>Family:</strong> <?php echo $transfer['head_name'] ?? 'N/A'; ?><br>
                        <strong>To:</strong> <?php echo $transfer['to_gn_name'] ?? $transfer['to_gn_id']; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cancellation Reason (Optional)</label>
                        <textarea class="form-control" name="note" rows="3" placeholder="Reason for cancellation..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Request</button>
                    <button type="submit" class="btn btn-warning">Cancel Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Initialize Bootstrap modals properly
    var modalElements = document.querySelectorAll('.modal');
    modalElements.forEach(function(modalElement) {
        var modal = new bootstrap.Modal(modalElement, {
            backdrop: true,
            keyboard: true,
            focus: true
        });
        
        modalElement.addEventListener('hidden.bs.modal', function () {
            var backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(function(backdrop) {
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        });
    });
});

// Export to Excel function (placeholder)
function exportToExcel() {
    alert('Excel export feature will be implemented. For now, please use the print function.');
}
</script>

<?php
// Include footer
require_once '../../../includes/footer.php';
?>