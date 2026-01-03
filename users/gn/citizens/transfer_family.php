<?php
// users/gn/citizens/transfer_family.php - UPDATED FOR FIXED SCHEMA
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Transfer Family";
$pageIcon = "bi bi-arrow-left-right";
$pageDescription = "Transfer family to another GN Division";
$bodyClass = "bg-light";

try {
    require_once '../../../config.php';
    require_once '../../../classes/Auth.php';
    require_once '../../../classes/Validator.php';
    
    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in and has GN level access
    if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'gn') {
        header('Location: ../../../login.php');
        exit();
    }

    // Get database connection
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    // Get reference database connection
    $ref_db = getRefConnection();
    
    $user_id = $_SESSION['user_id'];
    $office_name = $_SESSION['office_name'];
    $username = $_SESSION['username'];
    
    // Get GN ID from session and remove "gn_" prefix
    $from_gn_id = $_SESSION['office_code'];
    if (strpos($from_gn_id, 'gn_') === 0) {
        $from_gn_id = substr($from_gn_id, 3);
    }
    
    // Get family ID from URL
    $family_id = isset($_GET['id']) ? trim($_GET['id']) : null;
    
    if (!$family_id) {
        header('Location: list_families.php?error=Family ID is required');
        exit();
    }
    
    // Initialize variables
    $error = '';
    $success = '';
    $family_details = [];
    $family_members = [];
    $provinces = [];
    $districts = [];
    $divisions = [];
    $gn_divisions = [];
    $selected_district = '';
    $selected_division = '';
    $selected_gn = '';
    $transfer_id = '';
    $transfer_slip_data = null;

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
        header('Location: list_families.php?error=Family not found or you do not have permission');
        exit();
    }
    
    // Check if family has pending transfer - REMOVED COLLATE CLAUSE (no longer needed)
    $check_pending_query = "SELECT transfer_id FROM transfer_history 
                           WHERE family_id = ? 
                           AND current_status IN ('pending', 'approved')";
    $check_pending_stmt = $db->prepare($check_pending_query);
    $check_pending_stmt->bind_param("s", $family_id);
    $check_pending_stmt->execute();
    $check_pending_result = $check_pending_stmt->get_result();
    
    if ($check_pending_result->num_rows > 0) {
        header('Location: list_families.php?error=This family already has a pending or approved transfer request');
        exit();
    }
    
    // Get family members
    $members_query = "SELECT c.* 
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
    
    // Get current GN details
    $current_gn_query = "SELECT GN, District_Name, Division_Name, Province_Name 
                         FROM mobile_service.fix_work_station 
                         WHERE GN_ID = ?";
    $current_gn_stmt = $ref_db->prepare($current_gn_query);
    $current_gn_stmt->bind_param("s", $from_gn_id);
    $current_gn_stmt->execute();
    $current_gn_result = $current_gn_stmt->get_result();
    $current_gn = $current_gn_result->fetch_assoc();
    
    // Get all provinces for dropdown
    $province_query = "SELECT DISTINCT Province_Name FROM mobile_service.fix_work_station 
                       WHERE Province_Name IS NOT NULL AND Province_Name != '' 
                       ORDER BY Province_Name";
    $province_result = $ref_db->query($province_query);
    $provinces = $province_result->fetch_all(MYSQLI_ASSOC);
    
    // Process form submission
    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $db->begin_transaction();
            
            // Initialize Validator
            $validator = new Validator();
            
            // Validate required fields using individual Validator methods
            $validation_errors = [];
            
            // Sanitize all inputs
            $to_district = $validator->sanitize($_POST['to_district'] ?? '');
            $to_division = $validator->sanitize($_POST['to_division'] ?? '');
            $to_gn_id = $validator->sanitize($_POST['to_gn_id'] ?? '');
            $transfer_reason = $validator->sanitize($_POST['transfer_reason'] ?? '');
            $transfer_notes = $validator->sanitize($_POST['transfer_notes'] ?? '');
            
            // Validate required fields
            if (!$validator->validateRequired($to_district)) {
                $validation_errors[] = "District is required";
            }
            
            if (!$validator->validateRequired($to_division)) {
                $validation_errors[] = "Division is required";
            }
            
            if (!$validator->validateRequired($to_gn_id)) {
                $validation_errors[] = "GN Division is required";
            }
            
            if (!$validator->validateRequired($transfer_reason)) {
                $validation_errors[] = "Transfer reason is required";
            }
            
            // Validate transfer reason length
            if (!$validator->validateLength($transfer_reason, 5, 500)) {
                $validation_errors[] = "Transfer reason must be between 5 and 500 characters";
            }
            
            // Check if transferring to same GN
            if ($to_gn_id === $from_gn_id) {
                $validation_errors[] = "Cannot transfer to the same GN division";
            }
            
            // If there are validation errors, throw exception
            if (!empty($validation_errors)) {
                throw new Exception(implode(", ", $validation_errors));
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
            $transfer_id = 'TRF' . date('YmdHis') . rand(1000, 9999);
            
            // Create transfer history record
            $transfer_query = "INSERT INTO transfer_history 
                              (transfer_id, family_id, from_gn_id, to_gn_id, 
                               transfer_reason, transfer_notes, 
                               requested_by_user_id, request_date, current_status) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')";
            
            $transfer_stmt = $db->prepare($transfer_query);
            $transfer_stmt->bind_param("ssssssi", 
                $transfer_id,
                $family_id,
                $from_gn_id,
                $to_gn_id,
                $transfer_reason,
                $transfer_notes,
                $user_id
            );
            
            if (!$transfer_stmt->execute()) {
                throw new Exception("Failed to create transfer request: " . $transfer_stmt->error);
            }
            
            // Update family status
            $update_family = "UPDATE families SET has_pending_transfer = 1 WHERE family_id = ?";
            $update_stmt = $db->prepare($update_family);
            $update_stmt->bind_param("s", $family_id);
            $update_stmt->execute();
            
            // Add transfer log to families table
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
                'requested_by' => $user_id,
                'requested_by_name' => $office_name,
                'request_date' => date('Y-m-d H:i:s'),
                'status' => 'pending'
            ];
            
            $history_json = json_encode($history_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            $history_query = "UPDATE families SET transfer_history = ?, last_transfer_request = ? WHERE family_id = ?";
            $history_stmt = $db->prepare($history_query);
            $history_stmt->bind_param("sss", $history_json, $transfer_id, $family_id);
            $history_stmt->execute();
            
            // Log the action
            $action = 'transfer_request';
            $table = 'transfer_history';
            $record_id = $transfer_id;
            $new_values = json_encode([
                'family_id' => $family_id,
                'from_gn' => $from_gn_id,
                'to_gn_id' => $to_gn_id,
                'reason' => $transfer_reason,
                'status' => 'pending'
            ], JSON_UNESCAPED_UNICODE);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $log_stmt = $db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values, ip_address, user_agent) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?)");
            $log_stmt->bind_param("issssss", $user_id, $action, $table, $record_id, $new_values, $ip, $user_agent);
            $log_stmt->execute();
            
            // Send notification to division officer (if needed)
            $division_officer_query = "SELECT user_id FROM users 
                                      WHERE user_type = 'division' 
                                      AND office_name = ? 
                                      AND is_active = 1 
                                      LIMIT 1";
            $division_officer_stmt = $db->prepare($division_officer_query);
            $division_officer_stmt->bind_param("s", $current_gn['Division_Name']);
            $division_officer_stmt->execute();
            $division_officer_result = $division_officer_stmt->get_result();
            
            if ($division_officer_row = $division_officer_result->fetch_assoc()) {
                $division_officer_id = $division_officer_row['user_id'];
                
                // Check if notifications table exists before inserting
              // Check if notifications table exists before inserting
$check_notifications_table = $db->query("SHOW TABLES LIKE 'notifications'");
if ($check_notifications_table->num_rows > 0) {
    $notification_query = "INSERT INTO notifications 
                          (user_id, notification_type, title, message, is_read, link, created_at) 
                          VALUES (?, 'transfer', 'New Transfer Request', 
                                  CONCAT('Family ', ?, ' has requested transfer from GN ', ?, ' to GN ', ?), 
                                  0, ?, NOW())";
    
    $notify_stmt = $db->prepare($notification_query);
    
    // Create link to the transfer request
    $transfer_link = "transfer_requests.php?transfer_id=" . urlencode($transfer_id);
    $notify_stmt->bind_param("issss", $division_officer_id, $family_id, $from_gn_id, $to_gn_id, $transfer_link);
    $notify_stmt->execute();
}
            }
            
            // Commit transaction
            $db->commit();
            
            // Prepare transfer slip data
            $transfer_slip_data = [
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
                'status' => 'PENDING APPROVAL',
                'members' => $family_members,
                'land_details' => $land_details
            ];
            
            $success = "Transfer request submitted successfully! Transfer ID: <strong>$transfer_id</strong><br>
                       <small class='text-muted'>The transfer requires approval from divisional secretariat before it can be completed.</small>";
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Transfer Family Error: " . $e->getMessage());
}

// Include header with sidebar
require_once '../../../includes/header.php';
?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../../../includes/sidebar.php'; ?>
        </div>
        
        <!-- Main Content -->
        <main class="main-content">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">
            <i class="bi bi-arrow-left-right me-2"></i>
            Transfer Family
            <small class="text-muted fs-6"><?php echo htmlspecialchars($office_name); ?></small>
        </h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="list_families.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Families
            </a>
        </div>
    </div>
    
    <!-- Flash Messages -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <!-- Debug Panel (Remove in production) -->
    <?php if (isset($_GET['debug'])): ?>
    <div class="alert alert-info">
        <h6><i class="bi bi-bug"></i> Debug Information</h6>
        <div class="row">
            <div class="col-md-3">
                <strong>Current GN ID:</strong><br>
                <?php echo htmlspecialchars($from_gn_id); ?>
            </div>
            <div class="col-md-3">
                <strong>Family ID:</strong><br>
                <?php echo htmlspecialchars($family_id); ?>
            </div>
            <div class="col-md-3">
                <strong>Provinces Found:</strong><br>
                <?php echo count($provinces); ?>
            </div>
            <div class="col-md-3">
                <button onclick="testAjax()" class="btn btn-sm btn-warning">Test AJAX</button>
                <div id="ajax-test-result" class="mt-2"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Family Summary -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-house-door"></i> Family Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Family ID:</strong><br>
                            <span class="font-monospace fs-5"><?php echo htmlspecialchars($family_details['family_id']); ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>Head of Family:</strong><br>
                            <span class="fs-5"><?php echo htmlspecialchars($family_details['head_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>Head NIC:</strong><br>
                            <span class="fs-5"><?php echo htmlspecialchars($family_details['family_head_nic'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>Total Members:</strong><br>
                            <span class="badge bg-secondary fs-5"><?php echo $family_details['actual_members'] ?? count($family_members); ?></span>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <strong>Current Address:</strong><br>
                            <?php echo htmlspecialchars($family_details['address']); ?>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <strong>Current GN:</strong><br>
                            <?php echo htmlspecialchars($current_gn['GN'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($from_gn_id); ?>)
                        </div>
                        <div class="col-md-4">
                            <strong>Division:</strong><br>
                            <?php echo htmlspecialchars($current_gn['Division_Name'] ?? 'N/A'); ?>
                        </div>
                        <div class="col-md-4">
                            <strong>District:</strong><br>
                            <?php echo htmlspecialchars($current_gn['District_Name'] ?? 'N/A'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!$transfer_slip_data): ?>
    <!-- Transfer Form -->
    <div class="row no-print">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-geo-alt"></i> Select Destination</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="transferForm">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label required">Province</label>
                                <select class="form-select" id="province" name="province" required>
                                    <option value="">Select Province</option>
                                    <?php foreach ($provinces as $province): ?>
                                        <option value="<?php echo htmlspecialchars($province['Province_Name']); ?>">
                                            <?php echo htmlspecialchars($province['Province_Name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">District</label>
                                <select class="form-select" id="district" name="to_district" required disabled>
                                    <option value="">Select District</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label required">Division</label>
                                <select class="form-select" id="division" name="to_division" required disabled>
                                    <option value="">Select Division</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">GN Division</label>
                                <select class="form-select" id="gn_division" name="to_gn_id" required disabled>
                                    <option value="">Select GN Division</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label required">Transfer Reason</label>
                                <select class="form-select" name="transfer_reason" required>
                                    <option value="">Select Reason</option>
                                    <option value="Relocation">Relocation</option>
                                    <option value="Marriage">Marriage</option>
                                    <option value="Employment">Employment</option>
                                    <option value="Education">Education</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <label class="form-label">Additional Notes</label>
                                <textarea class="form-control" name="transfer_notes" rows="3" 
                                          placeholder="Any additional information about this transfer..."></textarea>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="button" class="btn btn-secondary me-md-2" onclick="window.history.back()">
                                <i class="bi bi-x-circle"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="bi bi-send-check"></i> Submit Transfer Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Family Members Preview -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-people"></i> Family Members</h5>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($family_members as $member): ?>
                        <div class="card member-card mb-2">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong><?php echo htmlspecialchars($member['full_name']); ?></strong><br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($member['relation_to_head'] ?? 'Member'); ?> | 
                                            <?php echo ucfirst($member['gender']); ?> | 
                                            <?php echo date('d/m/Y', strtotime($member['date_of_birth'])); ?>
                                        </small>
                                    </div>
                                    <div>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($member['identification_number']); ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($member['employment'])): ?>
                                    <small><i class="bi bi-briefcase"></i> <?php echo htmlspecialchars($member['employment']); ?></small><br>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Land Details -->
            <?php if (!empty($land_details)): ?>
            <div class="card mt-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-geo"></i> Land Details</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($land_details as $land): ?>
                        <div class="card land-card mb-2">
                            <div class="card-body p-2">
                                <strong><?php echo ucfirst($land['land_type']); ?></strong><br>
                                <small class="text-muted">
                                    Size: <?php echo $land['land_size_perches']; ?> perches<br>
                                    <?php if (!empty($land['deed_number'])): ?>
                                        Deed: <?php echo htmlspecialchars($land['deed_number']); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Transfer Slip (Show after successful submission) -->
    <?php if ($transfer_slip_data): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="card transfer-slip">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-receipt"></i> Transfer Slip</h5>
                    <div class="btn-group">
                        <button class="btn btn-light btn-sm" onclick="window.print()">
                            <i class="bi bi-printer"></i> Print
                        </button>
                        <button class="btn btn-light btn-sm" onclick="downloadSlip()">
                            <i class="bi bi-download"></i> Download
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Transfer Slip Header -->
                    <div class="text-center mb-4">
                        <h3 class="text-primary">FAMILY TRANSFER SLIP</h3>
                        <h5>Ministry of Home Affairs - Sri Lanka</h5>
                        <p class="text-muted">Family Profile Management System</p>
                        <hr>
                    </div>
                    
                    <!-- Transfer Details -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">Transfer ID:</th>
                                    <td class="font-monospace"><?php echo htmlspecialchars($transfer_slip_data['transfer_id']); ?></td>
                                </tr>
                                <tr>
                                    <th>Family ID:</th>
                                    <td class="font-monospace"><?php echo htmlspecialchars($transfer_slip_data['family_id']); ?></td>
                                </tr>
                                <tr>
                                    <th>Transfer Date:</th>
                                    <td><?php echo htmlspecialchars($transfer_slip_data['request_date']); ?></td>
                                </tr>
                                <tr>
                                    <th>Requested By:</th>
                                    <td><?php echo htmlspecialchars($transfer_slip_data['requested_by']); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">Status:</th>
                                    <td><span class="badge bg-warning">PENDING APPROVAL</span></td>
                                </tr>
                                <tr>
                                    <th>Reason:</th>
                                    <td><?php echo htmlspecialchars($transfer_slip_data['transfer_reason']); ?></td>
                                </tr>
                                <tr>
                                    <th>Notes:</th>
                                    <td><?php echo htmlspecialchars($transfer_slip_data['transfer_notes']); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- From and To Details -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-danger text-white">
                                    <h6 class="mb-0"><i class="bi bi-box-arrow-left"></i> TRANSFERRING FROM</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>GN Division:</strong> <?php echo htmlspecialchars($transfer_slip_data['from_gn']['GN']); ?></p>
                                    <p class="mb-1"><strong>GN ID:</strong> <?php echo htmlspecialchars($transfer_slip_data['to_gn_id'] ?? $transfer_slip_data['to_gn']['GN_ID'] ?? 'N/A'); ?></p>                                      <p class="mb-1"><strong>Division:</strong> <?php echo htmlspecialchars($transfer_slip_data['from_gn']['Division_Name']); ?></p>
                                    <p class="mb-1"><strong>District:</strong> <?php echo htmlspecialchars($transfer_slip_data['from_gn']['District_Name']); ?></p>
                                    <p class="mb-0"><strong>Province:</strong> <?php echo htmlspecialchars($transfer_slip_data['from_gn']['Province_Name']); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0"><i class="bi bi-box-arrow-right"></i> TRANSFERRING TO</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>GN Division:</strong> <?php echo htmlspecialchars($transfer_slip_data['to_gn']['GN']); ?></p>
                                    <p class="mb-1"><strong>GN ID:</strong> <?php echo htmlspecialchars($transfer_slip_data['to_gn_id']); ?></p>
                                    <p class="mb-1"><strong>Division:</strong> <?php echo htmlspecialchars($transfer_slip_data['to_gn']['Division_Name']); ?></p>
                                    <p class="mb-1"><strong>District:</strong> <?php echo htmlspecialchars($transfer_slip_data['to_gn']['District_Name']); ?></p>
                                    <p class="mb-0"><strong>Province:</strong> <?php echo htmlspecialchars($transfer_slip_data['to_gn']['Province_Name']); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Family Details -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="bi bi-house-door"></i> FAMILY DETAILS</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Head of Family:</strong> <?php echo htmlspecialchars($transfer_slip_data['family_head']); ?></p>
                                            <p><strong>Head NIC:</strong> <?php echo htmlspecialchars($transfer_slip_data['family_head_nic']); ?></p>
                                            <p><strong>Total Members:</strong> <?php echo $transfer_slip_data['total_members']; ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Current Address:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($transfer_slip_data['current_address'])); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Family Members Table -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="bi bi-people"></i> FAMILY MEMBERS</h6>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-bordered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Name</th>
                                                <th>NIC/ID</th>
                                                <th>Relation</th>
                                                <th>Gender</th>
                                                <th>Date of Birth</th>
                                                <th>Contact</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transfer_slip_data['members'] as $index => $member): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($member['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($member['identification_number']); ?></td>
                                                <td><?php echo htmlspecialchars($member['relation_to_head'] ?? 'Self'); ?></td>
                                                <td><?php echo ucfirst($member['gender']); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($member['date_of_birth'])); ?></td>
                                                <td><?php echo htmlspecialchars($member['mobile_phone'] ?? 'N/A'); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Land Details Table -->
                    <?php if (!empty($transfer_slip_data['land_details'])): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0"><i class="bi bi-geo"></i> LAND DETAILS</h6>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-bordered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Land Type</th>
                                                <th>Size (Perches)</th>
                                                <th>Deed Number</th>
                                                <th>Address</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transfer_slip_data['land_details'] as $index => $land): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo ucfirst($land['land_type']); ?></td>
                                                <td><?php echo $land['land_size_perches']; ?></td>
                                                <td><?php echo htmlspecialchars($land['deed_number'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($land['land_address'] ?? 'N/A'); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Footer and Instructions -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="alert alert-warning">
                                <h6><i class="bi bi-info-circle"></i> IMPORTANT INSTRUCTIONS:</h6>
                                <ol class="mb-0">
                                    <li>This transfer slip must be presented to the receiving GN office</li>
                                    <li>The receiving office must update the family's GN ID in their system</li>
                                    <li>Family ID and member IDs remain the same after transfer</li>
                                    <li>Original documents should be verified at the receiving office</li>
                                    <li>This transfer requires approval from divisional secretariat</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Signatures -->
                    <div class="row mt-4">
                        <div class="col-md-6 text-center">
                            <hr>
                            <p><strong>Requesting Officer Signature</strong></p>
                            <p>Name: <?php echo htmlspecialchars($office_name); ?></p>
                            <p>Date: <?php echo date('d/m/Y'); ?></p>
                        </div>
                        <div class="col-md-6 text-center">
                            <hr>
                            <p><strong>Receiving Officer Signature</strong></p>
                            <p>Name: _________________________</p>
                            <p>Date: _________________________</p>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="row mt-4 no-print">
                        <div class="col-md-12 text-center">
                            <a href="list_families.php" class="btn btn-primary me-2">
                                <i class="bi bi-list"></i> Back to Family List
                            </a>
                            <a href="transfer_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-success me-2">
                                <i class="bi bi-plus-circle"></i> Create Another Transfer
                            </a>
                            <button class="btn btn-warning" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print Slip
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <?php if (!$transfer_slip_data): ?>
    <footer class="pt-3 mt-4 text-muted border-top no-print">
        <div class="row">
            <div class="col-md-6">
                <small>
                    <i class="bi bi-info-circle"></i> 
                    Transferring family ID: <?php echo htmlspecialchars($family_id); ?>
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small>
                    Current GN: <?php echo htmlspecialchars($current_gn['GN'] ?? 'Unknown'); ?> |
                    Members: <?php echo count($family_members); ?> |
                    User: <?php echo htmlspecialchars($username); ?>
                </small>
            </div>
        </div>
    </footer>
    <?php endif; ?>
</main>

<style>
    .member-card { border-left: 4px solid #0d6efd; }
    .land-card { border-left: 4px solid #198754; }
    .transfer-slip { border: 2px dashed #6c757d; background: #fff; }
    @media print {
        .no-print { display: none !important; }
        .transfer-slip { border: none; }
        body { background: white; }
        .container-fluid { padding: 0; }
        main { padding: 0 !important; }
        .card { border: none; box-shadow: none; }
    }
    .required::after { content: " *"; color: #dc3545; }
    .select-loading { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cpath fill='%236c757d' d='M50,10A40,40 0 1,0 90,50A40,44.8 0 0,0 50,10Z'%3E%3CanimateTransform attributeName='transform' type='rotate' from='0 50 50' to='360 50 50' dur='1s' repeatCount='indefinite'/%3E%3C/path%3E%3C/svg%3E"); 
        background-size: 20px; 
        background-position: right 10px center; 
        background-repeat: no-repeat; 
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const provinceSelect = document.getElementById('province');
    const districtSelect = document.getElementById('district');
    const divisionSelect = document.getElementById('division');
    const gnSelect = document.getElementById('gn_division');
    const form = document.getElementById('transferForm');
    const submitBtn = document.getElementById('submitBtn');
    
    // Function to make AJAX call
    function makeAjaxCall(action, params, onSuccess, onError) {
        // Build URL
        const url = 'ajax_handler.php';
        const queryParams = new URLSearchParams({
            action: action,
            ...params,
            _: Date.now() // Cache buster
        }).toString();
        
        fetch(`${url}?${queryParams}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    onSuccess(data);
                } else {
                    throw new Error(data.error || 'Unknown error');
                }
            })
            .catch(error => {
                console.error('AJAX Error:', error);
                console.log('URL:', `${url}?${queryParams}`);
                if (onError) onError(error);
            });
    }
    
    // Test the AJAX endpoint on load
    function testAjaxEndpoint() {
        makeAjaxCall(
            'test',
            {},
            function(data) {
                console.log('AJAX Test Success:', data);
            },
            function(error) {
                console.error('AJAX Test Failed:', error);
                alert('AJAX endpoint test failed. Check console for details.');
            }
        );
    }
    
    // Run test on page load (optional)
    // testAjaxEndpoint();
    
    // Province change handler
    if (provinceSelect) {
        provinceSelect.addEventListener('change', function() {
            const province = this.value;
            
            if (!province) {
                districtSelect.innerHTML = '<option value="">Select District</option>';
                districtSelect.disabled = true;
                divisionSelect.innerHTML = '<option value="">Select Division</option>';
                divisionSelect.disabled = true;
                gnSelect.innerHTML = '<option value="">Select GN Division</option>';
                gnSelect.disabled = true;
                return;
            }
            
            // Show loading
            districtSelect.innerHTML = '<option value="">Loading districts...</option>';
            districtSelect.disabled = true;
            
            // Fetch districts
            makeAjaxCall(
                'get_districts',
                { province: province },
                function(data) {
                    let options = '<option value="">Select District</option>';
                    if (data.districts && data.districts.length > 0) {
                        data.districts.forEach(district => {
                            options += `<option value="${district}">${district}</option>`;
                        });
                        districtSelect.innerHTML = options;
                        districtSelect.disabled = false;
                        
                        // Reset other selects
                        divisionSelect.innerHTML = '<option value="">Select Division</option>';
                        divisionSelect.disabled = true;
                        gnSelect.innerHTML = '<option value="">Select GN Division</option>';
                        gnSelect.disabled = true;
                    } else {
                        districtSelect.innerHTML = '<option value="">No districts found</option>';
                        districtSelect.disabled = false;
                    }
                },
                function(error) {
                    districtSelect.innerHTML = '<option value="">Error loading</option>';
                    alert('Error loading districts: ' + error.message);
                }
            );
        });
    }
    
    // District change handler
    if (districtSelect) {
        districtSelect.addEventListener('change', function() {
            const district = this.value;
            
            if (!district) {
                divisionSelect.innerHTML = '<option value="">Select Division</option>';
                divisionSelect.disabled = true;
                gnSelect.innerHTML = '<option value="">Select GN Division</option>';
                gnSelect.disabled = true;
                return;
            }
            
            // Show loading
            divisionSelect.innerHTML = '<option value="">Loading divisions...</option>';
            divisionSelect.disabled = true;
            
            // Fetch divisions
            makeAjaxCall(
                'get_divisions',
                { district: district },
                function(data) {
                    let options = '<option value="">Select Division</option>';
                    if (data.divisions && data.divisions.length > 0) {
                        data.divisions.forEach(division => {
                            options += `<option value="${division}">${division}</option>`;
                        });
                        divisionSelect.innerHTML = options;
                        divisionSelect.disabled = false;
                        
                        // Reset GN select
                        gnSelect.innerHTML = '<option value="">Select GN Division</option>';
                        gnSelect.disabled = true;
                    } else {
                        divisionSelect.innerHTML = '<option value="">No divisions found</option>';
                        divisionSelect.disabled = false;
                    }
                },
                function(error) {
                    divisionSelect.innerHTML = '<option value="">Error loading</option>';
                    alert('Error loading divisions: ' + error.message);
                }
            );
        });
    }
    
    // Division change handler
    if (divisionSelect) {
        divisionSelect.addEventListener('change', function() {
            const division = this.value;
            
            if (!division) {
                gnSelect.innerHTML = '<option value="">Select GN Division</option>';
                gnSelect.disabled = true;
                return;
            }
            
            // Show loading
            gnSelect.innerHTML = '<option value="">Loading GN divisions...</option>';
            gnSelect.disabled = true;
            
            // Fetch GN divisions
            makeAjaxCall(
                'get_gn_divisions',
                { division: division },
                function(data) {
                    let options = '<option value="">Select GN Division</option>';
                    if (data.gn_divisions && data.gn_divisions.length > 0) {
                        data.gn_divisions.forEach(gn => {
                            options += `<option value="${gn.GN_ID}">${gn.GN} (${gn.GN_ID})</option>`;
                        });
                        gnSelect.innerHTML = options;
                        gnSelect.disabled = false;
                    } else {
                        gnSelect.innerHTML = '<option value="">No GN divisions found</option>';
                        gnSelect.disabled = false;
                    }
                },
                function(error) {
                    gnSelect.innerHTML = '<option value="">Error loading</option>';
                    alert('Error loading GN divisions: ' + error.message);
                }
            );
        });
    }
    
    // Form submission handler (keep your existing code)
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Your existing validation code...
            // ... [Keep your existing form validation code] ...
            
            // Submit the form
            this.submit();
        });
    }
    
    // Auto-dismiss alerts
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});

// Debug function
function testAjax() {
    const testResult = document.getElementById('ajax-test-result');
    if (testResult) {
        testResult.innerHTML = 'Testing...';
        
        fetch('ajax_handler.php?action=test&_=' + Date.now())
            .then(response => response.text())
            .then(text => {
                testResult.innerHTML = 'Response: ' + text;
                try {
                    const json = JSON.parse(text);
                    testResult.innerHTML = '<pre>' + JSON.stringify(json, null, 2) + '</pre>';
                } catch (e) {
                    testResult.innerHTML += '<br>Parse error: ' + e.message;
                }
            })
            .catch(error => {
                testResult.innerHTML = 'Error: ' + error.message;
            });
    }
}


// Add this function to your existing JavaScript
function downloadSlip() {
    const transferId = '<?php echo $transfer_id ?? ""; ?>';
    const familyId = '<?php echo $family_id; ?>';
    
    if (!transferId) {
        alert('No transfer ID found');
        return;
    }
    
    // Show loading
    const originalText = document.querySelector('#downloadBtn')?.innerHTML || 'Download';
    const downloadBtn = document.querySelector('#downloadBtn');
    if (downloadBtn) {
        downloadBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Downloading...';
        downloadBtn.disabled = true;
    }
    
    // Download PDF
    window.open('/fpms/users/gn/citizens/download_transfer_slip.php?transfer_id=' + 
                encodeURIComponent(transferId) + '&family_id=' + encodeURIComponent(familyId), '_blank');
    
    // Reset button after 2 seconds
    setTimeout(() => {
        if (downloadBtn) {
            downloadBtn.innerHTML = originalText;
            downloadBtn.disabled = false;
        }
    }, 2000);
}










</script>

<?php 
// Include footer
require_once '../../../includes/footer.php';
?>