<?php
// division/approve_transfer.php - Approve Transfer Action
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../../../config.php';
    require_once '../../../classes/Auth.php';
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $auth = new Auth();
    
    // Check if user is logged in as division officer
    if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'division') {
        header('Location: ../../../login.php');
        exit();
    }
    
    // Get database connection
    $db = getMainConnection();
    $ref_db = getRefConnection();
    
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'];
    $office_name = $_SESSION['office_name'];
    
    // Get transfer ID from URL
    $transfer_id = isset($_GET['id']) ? trim($_GET['id']) : null;
    
    if (!$transfer_id) {
        header('Location: transfer_requests.php?error=Transfer ID is required');
        exit();
    }
    
    // Get transfer details
    $transfer_query = "SELECT th.*, f.gn_id as current_gn_id, f.family_id,
                              (SELECT Division_Name FROM mobile_service.fix_work_station 
                               WHERE GN_ID = th.from_gn_id LIMIT 1) as from_division
                      FROM transfer_history th
                      JOIN families f ON th.family_id = f.family_id
                      WHERE th.transfer_id = ?";
    
    $stmt = $db->prepare($transfer_query);
    $stmt->bind_param("s", $transfer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $transfer = $result->fetch_assoc();
    $stmt->close();
    
    if (!$transfer) {
        header('Location: transfer_requests.php?error=Transfer request not found');
        exit();
    }
    
    // Check if user can approve this transfer (must be division officer for that division)
    $user_division_query = "SELECT Division_Name FROM mobile_service.fix_work_station 
                           WHERE Division_Name = ? LIMIT 1";
    $user_division_stmt = $ref_db->prepare($user_division_query);
    $user_division_stmt->bind_param("s", $office_name);
    $user_division_stmt->execute();
    $user_division_result = $user_division_stmt->get_result();
    $user_division = $user_division_result->fetch_assoc();
    $user_division_stmt->close();
    
    // Verify division match
    if (!$user_division || $transfer['from_division'] !== $user_division['Division_Name']) {
        header('Location: transfer_requests.php?error=You do not have permission to approve this transfer');
        exit();
    }
    
    // Check if already processed
    if ($transfer['current_status'] !== 'pending') {
        header('Location: transfer_requests.php?error=Transfer request is already processed');
        exit();
    }
    
    // Handle form submission
    $error = '';
    $success = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $db->begin_transaction();
            
            // Get approval note
            $note = isset($_POST['note']) ? trim($_POST['note']) : '';
            
            // Update transfer status
            $update_query = "UPDATE transfer_history 
                            SET current_status = 'approved', 
                                approved_by_user_id = ?, 
                                approval_date = NOW(),
                                completion_notes = ?
                            WHERE transfer_id = ?";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bind_param("iss", $user_id, $note, $transfer_id);
            
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
            
            // Log the action
            $action = 'approve_transfer';
            $table = 'transfer_history';
            $record_id = $transfer_id;
            $new_values = json_encode([
                'status' => 'approved',
                'approved_by' => $user_id,
                'note' => $note
            ], JSON_UNESCAPED_UNICODE);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $log_stmt = $db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values, ip_address, user_agent) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?)");
            $log_stmt->bind_param("issssss", $user_id, $action, $table, $record_id, $new_values, $ip, $user_agent);
            $log_stmt->execute();
            $log_stmt->close();
            
            $db->commit();
            
            // Redirect with success message
            header("Location: transfer_requests.php?success=Transfer approved successfully!");
            exit();
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
    
    // Get family details for display
    $family_query = "SELECT f.*, 
                            (SELECT full_name FROM citizens 
                             WHERE identification_number = f.family_head_nic 
                             AND identification_type = 'nic' 
                             LIMIT 1) as head_name
                     FROM families f 
                     WHERE f.family_id = ?";
    $family_stmt = $db->prepare($family_query);
    $family_stmt->bind_param("s", $transfer['family_id']);
    $family_stmt->execute();
    $family_result = $family_stmt->get_result();
    $family = $family_result->fetch_assoc();
    $family_stmt->close();
    
    // Get GN details
    $from_gn_query = "SELECT GN, Division_Name, District_Name FROM mobile_service.fix_work_station WHERE GN_ID = ?";
    $from_gn_stmt = $ref_db->prepare($from_gn_query);
    $from_gn_stmt->bind_param("s", $transfer['from_gn_id']);
    $from_gn_stmt->execute();
    $from_gn_result = $from_gn_stmt->get_result();
    $from_gn = $from_gn_result->fetch_assoc();
    $from_gn_stmt->close();
    
    $to_gn_query = "SELECT GN, Division_Name, District_Name FROM mobile_service.fix_work_station WHERE GN_ID = ?";
    $to_gn_stmt = $ref_db->prepare($to_gn_query);
    $to_gn_stmt->bind_param("s", $transfer['to_gn_id']);
    $to_gn_stmt->execute();
    $to_gn_result = $to_gn_stmt->get_result();
    $to_gn = $to_gn_result->fetch_assoc();
    $to_gn_stmt->close();
    
} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Approve Transfer Error: " . $e->getMessage());
}

// Include header
require_once '../../../includes/header.php';
?>

<style>
.main-content {
    margin-left: 280px;
    width: calc(100% - 280px);
    padding: 20px;
    min-height: calc(100vh - 60px);
}

@media (max-width: 991.98px) {
    .main-content {
        margin-left: 0;
        width: 100%;
        padding: 15px;
    }
}

.card {
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
</style>

<div class="main-content">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">
            <i class="fas fa-check-circle text-success me-2"></i>
            Approve Transfer Request
        </h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="transfer_requests.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Transfers
            </a>
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
    
    <div class="row">
        <!-- Transfer Details -->
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i> Transfer Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Transfer ID:</strong><br>
                            <span class="font-monospace"><?php echo htmlspecialchars($transfer['transfer_id']); ?></span></p>
                            
                            <p><strong>Family ID:</strong><br>
                            <span class="font-monospace"><?php echo htmlspecialchars($transfer['family_id']); ?></span></p>
                            
                            <p><strong>Head of Family:</strong><br>
                            <?php echo htmlspecialchars($family['head_name'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Transfer Reason:</strong><br>
                            <?php echo htmlspecialchars($transfer['transfer_reason']); ?></p>
                            
                            <?php if (!empty($transfer['transfer_notes'])): ?>
                            <p><strong>Transfer Notes:</strong><br>
                            <?php echo htmlspecialchars($transfer['transfer_notes']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-danger"><i class="fas fa-sign-out-alt"></i> Transferring From</h6>
                                    <p><strong>GN Division:</strong><br>
                                    <?php echo htmlspecialchars($from_gn['GN'] ?? $transfer['from_gn_id']); ?></p>
                                    
                                    <p><strong>Division:</strong><br>
                                    <?php echo htmlspecialchars($from_gn['Division_Name'] ?? 'N/A'); ?></p>
                                    
                                    <p><strong>District:</strong><br>
                                    <?php echo htmlspecialchars($from_gn['District_Name'] ?? 'N/A'); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-success"><i class="fas fa-sign-in-alt"></i> Transferring To</h6>
                                    <p><strong>GN Division:</strong><br>
                                    <?php echo htmlspecialchars($to_gn['GN'] ?? $transfer['to_gn_id']); ?></p>
                                    
                                    <p><strong>Division:</strong><br>
                                    <?php echo htmlspecialchars($to_gn['Division_Name'] ?? 'N/A'); ?></p>
                                    
                                    <p><strong>District:</strong><br>
                                    <?php echo htmlspecialchars($to_gn['District_Name'] ?? 'N/A'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Approval Form -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-check me-2"></i> Approval Form</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Approval Notes (Optional)</label>
                            <textarea class="form-control" name="note" rows="4" 
                                      placeholder="Add any notes or comments about this approval..."></textarea>
                            <div class="form-text">These notes will be recorded in the transfer history.</div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> Once approved, the receiving GN officer will be able to complete the transfer. 
                            The family will remain in the current GN until the transfer is completed.
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="transfer_requests.php" class="btn btn-secondary me-md-2">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check"></i> Approve Transfer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Family Information -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-house-user me-2"></i> Family Information</h5>
                </div>
                <div class="card-body">
                    <p><strong>Head NIC:</strong><br>
                    <?php echo htmlspecialchars($family['family_head_nic']); ?></p>
                    
                    <p><strong>Total Members:</strong><br>
                    <?php echo $family['total_members']; ?> members</p>
                    
                    <p><strong>Current Address:</strong><br>
                    <?php echo htmlspecialchars($family['address']); ?></p>
                    
                    <p><strong>Current GN:</strong><br>
                    <?php echo htmlspecialchars($family['gn_id']); ?></p>
                </div>
            </div>
            
            <!-- Timeline -->
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="fas fa-history me-2"></i> Timeline</h5>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-badge bg-primary"><i class="fas fa-paper-plane"></i></div>
                            <div class="timeline-content">
                                <h6>Request Submitted</h6>
                                <small><?php echo date('d/m/Y H:i', strtotime($transfer['request_date'])); ?></small>
                            </div>
                        </div>
                        
                        <div class="timeline-item current">
                            <div class="timeline-badge bg-warning"><i class="fas fa-clock"></i></div>
                            <div class="timeline-content">
                                <h6>Pending Approval</h6>
                                <small>Waiting for your action</small>
                            </div>
                        </div>
                        
                        <div class="timeline-item">
                            <div class="timeline-badge bg-info"><i class="fas fa-check"></i></div>
                            <div class="timeline-content">
                                <h6>Approval</h6>
                                <small>Will be recorded upon approval</small>
                            </div>
                        </div>
                        
                        <div class="timeline-item">
                            <div class="timeline-badge bg-success"><i class="fas fa-check-double"></i></div>
                            <div class="timeline-content">
                                <h6>Completion</h6>
                                <small>By receiving GN officer</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-badge {
    position: absolute;
    left: -30px;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    z-index: 1;
}

.timeline-content {
    padding-left: 10px;
}

.timeline-item.current .timeline-badge {
    box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.3);
}
</style>

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
    
    // Form validation
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to approve this transfer?')) {
                e.preventDefault();
                return false;
            }
            return true;
        });
    }
});
</script>

<?php
require_once '../../../includes/footer.php';
?>