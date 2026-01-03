<?php
// users/gn/citizens/create_family_from_member.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Create New Family from Member";
$pageIcon = "bi bi-house-add";
$pageDescription = "Create a new family from an existing member";
$bodyClass = "bg-light";

try {
    require_once '../../../config.php';
    require_once '../../../classes/Auth.php';
    
    // Start session if not already started
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

    // Get database connection from config
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $user_id = $_SESSION['user_id'];
    $gn_id = $_SESSION['office_code'];
    $username = $_SESSION['username'];

    // Check if citizen_id and source_family_id are provided
    if (!isset($_GET['citizen_id']) || !isset($_GET['source_family_id'])) {
        header('Location: list_families.php?error=missing_parameters');
        exit();
    }
    
    $citizen_id = intval($_GET['citizen_id']);
    $source_family_id = $_GET['source_family_id'];
    
    // Get member details
    $member_query = "SELECT * FROM citizens WHERE citizen_id = ?";
    $member_stmt = $db->prepare($member_query);
    $member_stmt->bind_param("i", $citizen_id);
    $member_stmt->execute();
    $member_result = $member_stmt->get_result();
    
    if ($member_result->num_rows === 0) {
        header('Location: view_family.php?id=' . urlencode($source_family_id) . '&error=member_not_found');
        exit();
    }
    
    $member = $member_result->fetch_assoc();
    
    // Check if member is already a family head
    if ($member['relation_to_head'] === 'Self') {
        header('Location: view_family.php?id=' . urlencode($source_family_id) . '&error=member_is_head');
        exit();
    }
    
    // Get source family details
    $family_query = "SELECT * FROM families WHERE family_id = ?";
    $family_stmt = $db->prepare($family_query);
    $family_stmt->bind_param("s", $source_family_id);
    $family_stmt->execute();
    $family_result = $family_stmt->get_result();
    $source_family = $family_result->fetch_assoc();
    
    // Get GN details
    $ref_db = getRefConnection();
    $gn_details = [];
    if ($ref_db) {
        $gn_query = "SELECT GN, Division_Name, District_Name, Province_Name 
                     FROM mobile_service.fix_work_station 
                     WHERE GN_ID = ?";
        $gn_stmt = $ref_db->prepare($gn_query);
        $gn_stmt->bind_param("s", $gn_id);
        $gn_stmt->execute();
        $gn_result = $gn_stmt->get_result();
        $gn_details = $gn_result->fetch_assoc() ?? [];
    }
    
    // Generate new family ID
    function generateFamilyId($gn_id) {
        // Extract only numbers from GN_ID
        $gn_numbers = preg_replace('/[^0-9]/', '', $gn_id);
        
        // If GN_ID has less than 5 numbers, pad with zeros
        if (strlen($gn_numbers) < 5) {
            $gn_numbers = str_pad($gn_numbers, 5, '0', STR_PAD_LEFT);
        }
        
        // Get timestamp (YYYYMMDDHHMMSS format)
        $timestamp = date('YmdHis');
        
        // Take first 5 digits from GN numbers and 9 digits from timestamp
        $gn_part = substr($gn_numbers, 0, 5);
        $time_part = substr($timestamp, 5, 9);
        
        // Combine to make 14 digits
        $family_id = $gn_part . $time_part;
        
        // Ensure exactly 14 digits
        if (strlen($family_id) > 14) {
            $family_id = substr($family_id, 0, 14);
        } elseif (strlen($family_id) < 14) {
            $family_id = str_pad($family_id, 14, '0', STR_PAD_RIGHT);
        }
        
        return $family_id;
    }
    
    $new_family_id = generateFamilyId($gn_id);
    $error = '';
    $success = '';
    $form_data = [];
    
    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form_data = $_POST;
        
        try {
            // Start transaction
            $db->begin_transaction();
            
            // Validate required fields
            $required_fields = [
                'new_family_address' => 'New family address'
            ];
            
            $validation_errors = [];
            
            foreach ($required_fields as $field => $label) {
                $value = trim($_POST[$field] ?? '');
                if (empty($value)) {
                    $validation_errors[$field] = "$label is required";
                }
            }
            
            if (!empty($validation_errors)) {
                throw new Exception("Please correct the errors in the form.");
            }
            
            $new_family_address = trim($_POST['new_family_address']);
            $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
            
            // Insert new family
            $family_query = "INSERT INTO families 
                            (family_id, gn_id, original_gn_id, address, family_head_nic, total_members, created_by) 
                            VALUES (?, ?, ?, ?, ?, 1, ?)";
            
            $family_stmt = $db->prepare($family_query);
            $family_head_nic = ($member['identification_type'] === 'nic') ? 
                strtoupper($member['identification_number']) : null;
            
            $family_stmt->bind_param("sssssi", 
                $new_family_id, 
                $gn_id, 
                $gn_id, 
                $new_family_address,
                $family_head_nic,
                $user_id
            );
            
            if (!$family_stmt->execute()) {
                throw new Exception("Failed to create family record: " . $family_stmt->error);
            }
            
            // Update the member to be the head of new family
            $update_member_query = "UPDATE citizens 
                                   SET family_id = ?, 
                                       relation_to_head = 'Self',
                                       address = COALESCE(?, address),
                                       updated_at = CURRENT_TIMESTAMP
                                   WHERE citizen_id = ?";
            
            $update_stmt = $db->prepare($update_member_query);
            $member_new_address = !empty($_POST['member_new_address']) ? trim($_POST['member_new_address']) : $member['address'];
            
            $update_stmt->bind_param("ssi", 
                $new_family_id,
                $member_new_address,
                $citizen_id
            );
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update member: " . $update_stmt->error);
            }
            
            // Decrement total members in source family
            $decrement_query = "UPDATE families 
                               SET total_members = total_members - 1,
                                   updated_at = CURRENT_TIMESTAMP
                               WHERE family_id = ?";
            
            $decrement_stmt = $db->prepare($decrement_query);
            $decrement_stmt->bind_param("s", $source_family_id);
            
            if (!$decrement_stmt->execute()) {
                throw new Exception("Failed to update source family: " . $decrement_stmt->error);
            }
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs 
                           (user_id, action_type, table_name, record_id, ip_address, user_agent, new_values)
                           VALUES (?, 'create_family', 'families', ?, ?, ?, ?)";
            
            $audit_stmt = $db->prepare($audit_query);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $audit_data = json_encode([
                'new_family_id' => $new_family_id,
                'from_family_id' => $source_family_id,
                'member_id' => $citizen_id,
                'member_name' => $member['full_name'],
                'reason' => $notes
            ]);
            
            $audit_stmt->bind_param("issss", $user_id, $new_family_id, $ip, $user_agent, $audit_data);
            $audit_stmt->execute();
            
            // Commit transaction
            $db->commit();
            
            $success = "New family created successfully!";
            $success .= "<br><strong>New Family ID:</strong> " . htmlspecialchars($new_family_id);
            $success .= "<br><strong>Family Head:</strong> " . htmlspecialchars($member['full_name']);
            
        } catch (Exception $e) {
            $db->rollback();
            $error = $e->getMessage();
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
}

require_once '../../../includes/header.php';
?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../../../includes/sidebar.php'; ?>
        </div>
        
        <main class="" id="main-content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-house-add me-2"></i>
                    Create New Family from Member
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="view_family.php?id=<?php echo urlencode($source_family_id); ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Family
                    </a>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <h5><i class="bi bi-exclamation-triangle"></i> Error</h5>
                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($error)); ?></p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <h5><i class="bi bi-check-circle"></i> Success!</h5>
                    <p><?php echo $success; ?></p>
                    <div class="mt-3">
                        <a href="view_family.php?id=<?php echo urlencode($new_family_id); ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-eye"></i> View New Family
                        </a>
                        <a href="view_family.php?id=<?php echo urlencode($source_family_id); ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-left"></i> View Original Family
                        </a>
                        <a href="add_family.php" class="btn btn-sm btn-success">
                            <i class="bi bi-plus-circle"></i> Add Another Family
                        </a>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Information Cards -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0"><i class="bi bi-person"></i> Selected Member</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="avatar-lg me-3">
                                    <div class="avatar-title bg-primary text-white rounded-circle">
                                        <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                    </div>
                                </div>
                                <div>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($member['full_name']); ?></h5>
                                    <p class="mb-1 text-muted"><?php echo htmlspecialchars($member['name_with_initials']); ?></p>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($member['identification_number']); ?></span>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted d-block">Gender</small>
                                    <strong><?php echo ucfirst($member['gender']); ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Date of Birth</small>
                                    <strong><?php echo date('d M Y', strtotime($member['date_of_birth'])); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-warning text-dark">
                            <h6 class="mb-0"><i class="bi bi-house"></i> Current Family</h6>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title">Family ID: <?php echo htmlspecialchars($source_family_id); ?></h5>
                            <p class="card-text">
                                <small class="text-muted">Current Address:</small><br>
                                <?php echo nl2br(htmlspecialchars($source_family['address'])); ?>
                            </p>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                This member will be removed from the current family and become the head of a new family.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- New Family ID Preview -->
            <div class="card mb-4">
                <div class="card-body text-center bg-light">
                    <h5 class="card-title"><i class="bi bi-card-heading"></i> New Family ID</h5>
                    <p class="card-text display-4 text-primary font-monospace"><?php echo htmlspecialchars($new_family_id); ?></p>
                    <small class="text-muted">This 14-digit ID will be assigned to the new family</small>
                </div>
            </div>
            
            <?php if (!$success): ?>
            <!-- Create New Family Form -->
            <form method="POST" action="" id="createFamilyForm" class="needs-validation" novalidate>
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-house-add"></i> New Family Details</h5>
                    </div>
                    <div class="card-body">
                        <!-- GN Information -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label">GN Division</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($gn_details['GN'] ?? 'Not Available'); ?>" readonly>
                                <small class="text-muted">
                                    GN ID: <?php echo htmlspecialchars($gn_id); ?>
                                </small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Division</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($gn_details['Division_Name'] ?? 'N/A'); ?>" readonly>
                            </div>
                        </div>
                        
                        <!-- New Family Address -->
                        <div class="mb-4">
                            <label class="form-label required">New Family Address</label>
                            <textarea class="form-control" name="new_family_address" rows="3" required 
                                      minlength="10" maxlength="255"><?php echo isset($form_data['new_family_address']) ? htmlspecialchars($form_data['new_family_address']) : ''; ?></textarea>
                            <div class="invalid-feedback">
                                Please provide a new family address (at least 10 characters).
                            </div>
                            <small class="text-muted">Address where the new family will reside</small>
                        </div>
                        
                        <!-- Member's New Address -->
                        <div class="mb-4">
                            <label class="form-label">Member's Personal Address (Optional)</label>
                            <textarea class="form-control" name="member_new_address" rows="2"
                                      maxlength="255"><?php echo isset($form_data['member_new_address']) ? htmlspecialchars($form_data['member_new_address']) : ''; ?></textarea>
                            <small class="text-muted">If different from the new family address</small>
                        </div>
                        
                        <!-- Notes -->
                        <div class="mb-3">
                            <label class="form-label">Notes / Reason</label>
                            <textarea class="form-control" name="notes" rows="2"
                                      maxlength="500"><?php echo isset($form_data['notes']) ? htmlspecialchars($form_data['notes']) : ''; ?></textarea>
                            <small class="text-muted">Optional: Reason for creating new family</small>
                        </div>
                        
                        <!-- Confirmation -->
                        <div class="alert alert-danger">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="confirm_action" value="yes" 
                                       id="confirm_action" required>
                                <label class="form-check-label" for="confirm_action">
                                    <strong>I confirm the following:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>This member will be removed from Family <?php echo htmlspecialchars($source_family_id); ?></li>
                                        <li>A new family will be created with this member as the head</li>
                                        <li>The member's relation will be changed to 'Self'</li>
                                        <li>The source family's total members count will be reduced by 1</li>
                                        <li>This action cannot be undone</li>
                                    </ul>
                                </label>
                                <div class="invalid-feedback">You must confirm before proceeding</div>
                            </div>
                        </div>
                        
                        <!-- Form Buttons -->
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                            <a href="view_family.php?id=<?php echo urlencode($source_family_id); ?>" 
                               class="btn btn-secondary me-md-2">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-success" id="submitBtn">
                                <i class="bi bi-check-circle"></i> Create New Family
                            </button>
                        </div>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </main>
    </div>
</div>

<style>
    .required::after {
        content: " *";
        color: #dc3545;
    }
    .avatar-lg {
        width: 60px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .avatar-title {
        font-weight: 600;
        font-size: 24px;
    }
    .display-4 {
        font-size: 2.5rem;
        font-weight: 600;
    }
    .alert ul {
        padding-left: 20px;
    }
    .alert ul li {
        margin-bottom: 5px;
    }
</style>

<script src="../../../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('createFamilyForm');
        const confirmCheckbox = document.getElementById('confirm_action');
        
        // Bootstrap validation
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
        
        // Real-time validation
        form.querySelectorAll('input, textarea').forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-valid', 'is-invalid');
                if (this.checkValidity()) {
                    this.classList.add('is-valid');
                } else if (this.value) {
                    this.classList.add('is-invalid');
                }
            });
        });
        
        // Confirmation checkbox validation
        confirmCheckbox.addEventListener('change', function() {
            this.classList.remove('is-valid', 'is-invalid');
            if (this.checked) {
                this.classList.add('is-valid');
            }
        });
        
        // Add confirmation dialog on submit
        form.addEventListener('submit', function(e) {
            if (form.checkValidity()) {
                if (!confirm('Are you sure you want to create a new family with this member as the head?')) {
                    e.preventDefault();
                }
            }
        });
    });
</script>

<?php 
// Include footer
$footer_path = '../../../includes/footer.php';  
if (file_exists($footer_path)) {
    include $footer_path;
} else {
    echo '</div></div></body></html>';
}
?>