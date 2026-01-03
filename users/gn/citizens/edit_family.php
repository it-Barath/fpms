<?php
// users/gn/citizens/edit_family.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Edit Family";
$pageIcon = "bi bi-pencil-square";
$pageDescription = "Edit family details and members";
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
    
    $user_id = $_SESSION['user_id'];
    $office_name = $_SESSION['office_name'];
    $username = $_SESSION['username'];
    
    // Get GN ID from session and remove "gn_" prefix
    $gn_id = $_SESSION['office_code'];
    if (strpos($gn_id, 'gn_') === 0) {
        $gn_id = substr($gn_id, 3);
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
    $land_details = [];
    $other_family_heads = [];

    // Get family details
    $family_query = "SELECT f.*, 
                            (SELECT full_name FROM citizens 
                             WHERE identification_number = f.family_head_nic 
                             AND identification_type = 'nic' 
                             LIMIT 1) as head_name
                     FROM families f 
                     WHERE f.family_id = ? AND f.gn_id = ?";
    
    $stmt = $db->prepare($family_query);
    $stmt->bind_param("ss", $family_id, $gn_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $family_details = $result->fetch_assoc();
    
    if (!$family_details) {
        header('Location: list_families.php?error=Family not found or you do not have permission');
        exit();
    }
    
    // Check if family is transferred
    if ($family_details['is_transferred'] == 1) {
        header('Location: list_families.php?error=Cannot edit a transferred family');
        exit();
    }
    
    // Get family members
    $members_query = "SELECT c.* 
                      FROM citizens c 
                      WHERE c.family_id = ? 
                      ORDER BY CASE 
                          WHEN relation_to_head = 'Self' THEN 1
                          WHEN relation_to_head = 'Spouse' THEN 2
                          WHEN relation_to_head = 'Child' THEN 3
                          WHEN relation_to_head = 'Parent' THEN 4
                          ELSE 5
                      END, date_of_birth";
    
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
    
    // Get other potential family heads (adults in the family)
    $heads_query = "SELECT citizen_id, full_name, identification_number, date_of_birth,
                           TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) as age
                    FROM citizens 
                    WHERE family_id = ? 
                    AND relation_to_head IN ('Self', 'Spouse')
                    AND TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 18
                    ORDER BY full_name";
    
    $heads_stmt = $db->prepare($heads_query);
    $heads_stmt->bind_param("s", $family_id);
    $heads_stmt->execute();
    $heads_result = $heads_stmt->get_result();
    
    while ($head = $heads_result->fetch_assoc()) {
        $other_family_heads[] = $head;
    }
    
    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $db->begin_transaction();
            
            $action = $_POST['action'] ?? '';
            
            if ($action === 'update_family') {
                // Update family details
                $address = trim($_POST['address']);
                $family_head_nic = trim($_POST['family_head_nic']);
                $total_members = (int)$_POST['total_members'];
                $living_conditions = trim($_POST['living_conditions'] ?? '');
                $income_category = trim($_POST['income_category'] ?? '');
                $special_notes = trim($_POST['special_notes'] ?? '');
                
                // Validate required fields
                if (empty($address)) {
                    throw new Exception("Address is required");
                }
                
                if (empty($family_head_nic)) {
                    throw new Exception("Family head NIC is required");
                }
                
                // Verify new family head exists in the family
                $verify_head = "SELECT citizen_id FROM citizens 
                               WHERE family_id = ? AND identification_number = ? 
                               AND identification_type = 'nic'";
                $verify_stmt = $db->prepare($verify_head);
                $verify_stmt->bind_param("ss", $family_id, $family_head_nic);
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();
                
                if ($verify_result->num_rows === 0) {
                    throw new Exception("Selected family head must be a member of this family");
                }
                
                // Update family
                $update_query = "UPDATE families SET 
                                address = ?, 
                                family_head_nic = ?, 
                                total_members = ?, 
                                living_conditions = ?, 
                                income_category = ?, 
                                special_notes = ?, 
                                updated_at = NOW()
                                WHERE family_id = ?";
                
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bind_param(
                    "ssissss",
                    $address,
                    $family_head_nic,
                    $total_members,
                    $living_conditions,
                    $income_category,
                    $special_notes,
                    $family_id
                );
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update family: " . $update_stmt->error);
                }
                
                // Log the action
                $log_action = 'update_family';
                $table_name = 'families';
                $record_id = $family_id;
                $old_values = json_encode($family_details);
                $new_values = json_encode([
                    'address' => $address,
                    'family_head_nic' => $family_head_nic,
                    'total_members' => $total_members,
                    'living_conditions' => $living_conditions,
                    'income_category' => $income_category
                ]);
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                
                $log_stmt = $db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $log_stmt->bind_param("isssssss", $user_id, $log_action, $table_name, $record_id, $old_values, $new_values, $ip, $user_agent);
                $log_stmt->execute();
                
                $success = "Family details updated successfully!";
                
            } elseif ($action === 'update_member') {
                // Update family member
                $member_id = (int)$_POST['member_id'];
                $full_name = trim($_POST['full_name']);
                $identification_number = trim($_POST['identification_number']);
                $identification_type = trim($_POST['identification_type']);
                $date_of_birth = trim($_POST['date_of_birth']);
                $gender = trim($_POST['gender']);
                $marital_status = trim($_POST['marital_status']);
                $relation_to_head = trim($_POST['relation_to_head']);
                $mobile_phone = trim($_POST['mobile_phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                
                // Validate required fields
                $required = [
                    'full_name' => 'Full Name',
                    'identification_number' => 'ID Number',
                    'date_of_birth' => 'Date of Birth',
                    'gender' => 'Gender',
                    'relation_to_head' => 'Relation to Head'
                ];
                
                foreach ($required as $field => $label) {
                    if (empty($$field)) {
                        throw new Exception("$label is required");
                    }
                }
                
                // Check if member belongs to this family
                $check_member = "SELECT citizen_id FROM citizens 
                                WHERE citizen_id = ? AND family_id = ?";
                $check_stmt = $db->prepare($check_member);
                $check_stmt->bind_param("is", $member_id, $family_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception("Member not found in this family");
                }
                
                // Get old values for logging
                $old_query = "SELECT * FROM citizens WHERE citizen_id = ?";
                $old_stmt = $db->prepare($old_query);
                $old_stmt->bind_param("i", $member_id);
                $old_stmt->execute();
                $old_result = $old_stmt->get_result();
                $old_member = $old_result->fetch_assoc();
                
                // Update member
                $update_member_query = "UPDATE citizens SET 
                                       full_name = ?, 
                                       identification_number = ?, 
                                       identification_type = ?, 
                                       date_of_birth = ?, 
                                       gender = ?, 
                                       marital_status = ?, 
                                       relation_to_head = ?, 
                                       mobile_phone = ?, 
                                       email = ?, 
                                       updated_at = NOW()
                                       WHERE citizen_id = ?";
                
                $update_member_stmt = $db->prepare($update_member_query);
                $update_member_stmt->bind_param(
                    "sssssssssi",
                    $full_name,
                    $identification_number,
                    $identification_type,
                    $date_of_birth,
                    $gender,
                    $marital_status,
                    $relation_to_head,
                    $mobile_phone,
                    $email,
                    $member_id
                );
                
                if (!$update_member_stmt->execute()) {
                    throw new Exception("Failed to update member: " . $update_member_stmt->error);
                }
                
                // Log the action
                $log_action = 'update_citizen';
                $table_name = 'citizens';
                $record_id = $member_id;
                $new_member_values = [
                    'full_name' => $full_name,
                    'identification_number' => $identification_number,
                    'date_of_birth' => $date_of_birth,
                    'gender' => $gender,
                    'relation_to_head' => $relation_to_head
                ];
                
                $log_stmt = $db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $log_stmt->bind_param(
                    "isssssss",
                    $user_id,
                    $log_action,
                    $table_name,
                    $record_id,
                    json_encode($old_member),
                    json_encode($new_member_values),
                    $ip,
                    $user_agent
                );
                $log_stmt->execute();
                
                $success = "Member updated successfully!";
                
            } elseif ($action === 'add_land') {
                // Add new land record
                $land_type = trim($_POST['land_type']);
                $land_size_perches = (float)$_POST['land_size_perches'];
                $deed_number = trim($_POST['deed_number'] ?? '');
                $land_address = trim($_POST['land_address'] ?? '');
                $ownership_type = trim($_POST['ownership_type'] ?? '');
                $notes = trim($_POST['land_notes'] ?? '');
                
                if (empty($land_type)) {
                    throw new Exception("Land type is required");
                }
                
                if ($land_size_perches <= 0) {
                    throw new Exception("Land size must be greater than 0");
                }
                
                $insert_land_query = "INSERT INTO land_details 
                                     (family_id, land_type, land_size_perches, deed_number, 
                                      land_address, ownership_type, notes, created_at) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $insert_land_stmt = $db->prepare($insert_land_query);
                $insert_land_stmt->bind_param(
                    "ssdssss",
                    $family_id,
                    $land_type,
                    $land_size_perches,
                    $deed_number,
                    $land_address,
                    $ownership_type,
                    $notes
                );
                
                if (!$insert_land_stmt->execute()) {
                    throw new Exception("Failed to add land record: " . $insert_land_stmt->error);
                }
                
                // Log the action
                $log_action = 'add_land';
                $table_name = 'land_details';
                $record_id = $insert_land_stmt->insert_id;
                $new_values = json_encode([
                    'family_id' => $family_id,
                    'land_type' => $land_type,
                    'land_size_perches' => $land_size_perches
                ]);
                
                $log_stmt = $db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values, ip_address, user_agent) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?)");
                $log_stmt->bind_param("issssss", $user_id, $log_action, $table_name, $record_id, $new_values, $ip, $user_agent);
                $log_stmt->execute();
                
                $success = "Land record added successfully!";
                
            } elseif ($action === 'delete_land') {
                // Delete land record
                $land_id = (int)$_POST['land_id'];
                
                // Get land details for logging
                $land_query = "SELECT * FROM land_details WHERE land_id = ? AND family_id = ?";
                $land_stmt = $db->prepare($land_query);
                $land_stmt->bind_param("is", $land_id, $family_id);
                $land_stmt->execute();
                $land_result = $land_stmt->get_result();
                $land_record = $land_result->fetch_assoc();
                
                if (!$land_record) {
                    throw new Exception("Land record not found");
                }
                
                // Delete land record
                $delete_land_query = "DELETE FROM land_details WHERE land_id = ?";
                $delete_land_stmt = $db->prepare($delete_land_query);
                $delete_land_stmt->bind_param("i", $land_id);
                
                if (!$delete_land_stmt->execute()) {
                    throw new Exception("Failed to delete land record: " . $delete_land_stmt->error);
                }
                
                // Log the action
                $log_action = 'delete_land';
                $table_name = 'land_details';
                $record_id = $land_id;
                $old_values = json_encode($land_record);
                
                $log_stmt = $db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, old_values, ip_address, user_agent) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?)");
                $log_stmt->bind_param("issssss", $user_id, $log_action, $table_name, $record_id, $old_values, $ip, $user_agent);
                $log_stmt->execute();
                
                $success = "Land record deleted successfully!";
            }
            
            // Refresh data after update
            $family_query = "SELECT f.*, 
                                    (SELECT full_name FROM citizens 
                                     WHERE identification_number = f.family_head_nic 
                                     AND identification_type = 'nic' 
                                     LIMIT 1) as head_name
                             FROM families f 
                             WHERE f.family_id = ?";
            
            $stmt = $db->prepare($family_query);
            $stmt->bind_param("s", $family_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $family_details = $result->fetch_assoc();
            
            // Refresh members
            $members_stmt = $db->prepare($members_query);
            $members_stmt->bind_param("s", $family_id);
            $members_stmt->execute();
            $members_result = $members_stmt->get_result();
            $family_members = [];
            while ($member = $members_result->fetch_assoc()) {
                $family_members[] = $member;
            }
            
            // Refresh land details
            $land_stmt = $db->prepare($land_query);
            $land_stmt->bind_param("s", $family_id);
            $land_stmt->execute();
            $land_result = $land_stmt->get_result();
            $land_details = $land_result->fetch_all(MYSQLI_ASSOC);
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Edit Family Error: " . $e->getMessage());
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
        

<main class="" id="main-content">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">
            <i class="bi bi-pencil-square me-2"></i>
            Edit Family
            <small class="text-muted fs-6"><?php echo htmlspecialchars($office_name); ?></small>
        </h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-info me-2">
                <i class="bi bi-eye"></i> View Family
            </a>
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
    
    <!-- Family Information Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-house-door"></i> Family Information</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_family">
                <input type="hidden" name="family_id" value="<?php echo htmlspecialchars($family_id); ?>">
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Family ID</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($family_details['family_id']); ?>" readonly>
                        <small class="text-muted">Family ID cannot be changed</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Registration Date</label>
                        <input type="text" class="form-control" value="<?php echo date('d/m/Y', strtotime($family_details['created_at'])); ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Updated</label>
                        <input type="text" class="form-control" value="<?php echo date('d/m/Y H:i', strtotime($family_details['updated_at'])); ?>" readonly>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label required">Address</label>
                        <textarea class="form-control" name="address" rows="3" required><?php echo htmlspecialchars($family_details['address']); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Family Head</label>
                        <select class="form-select" name="family_head_nic" required>
                            <option value="">Select Family Head</option>
                            <?php foreach ($other_family_heads as $head): ?>
                                <option value="<?php echo htmlspecialchars($head['identification_number']); ?>" 
                                    <?php echo $head['identification_number'] == $family_details['family_head_nic'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($head['full_name']); ?> 
                                    (<?php echo htmlspecialchars($head['identification_number']); ?>) - 
                                    <?php echo $head['age']; ?> years
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Current: <?php echo htmlspecialchars($family_details['head_name'] ?? 'N/A'); ?></small>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label required">Total Members</label>
                        <input type="number" class="form-control" name="total_members" 
                               value="<?php echo $family_details['total_members']; ?>" min="1" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Living Conditions</label>
                        <select class="form-select" name="living_conditions">
                            <option value="">Select Condition</option>
                            <option value="good" <?php echo $family_details['living_conditions'] == 'good' ? 'selected' : ''; ?>>Good</option>
                            <option value="average" <?php echo $family_details['living_conditions'] == 'average' ? 'selected' : ''; ?>>Average</option>
                            <option value="poor" <?php echo $family_details['living_conditions'] == 'poor' ? 'selected' : ''; ?>>Poor</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Income Category</label>
                        <select class="form-select" name="income_category">
                            <option value="">Select Category</option>
                            <option value="low" <?php echo $family_details['income_category'] == 'low' ? 'selected' : ''; ?>>Low Income</option>
                            <option value="middle" <?php echo $family_details['income_category'] == 'middle' ? 'selected' : ''; ?>>Middle Income</option>
                            <option value="high" <?php echo $family_details['income_category'] == 'high' ? 'selected' : ''; ?>>High Income</option>
                        </select>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Special Notes</label>
                        <textarea class="form-control" name="special_notes" rows="2"><?php echo htmlspecialchars($family_details['special_notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Update Family Details
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Family Members Card -->
    <div class="card mb-4">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-people"></i> Family Members (<?php echo count($family_members); ?>)</h5>
            <a href="add_member.php?family_id=<?php echo urlencode($family_id); ?>" class="btn btn-light btn-sm">
                <i class="bi bi-person-plus"></i> Add New Member
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($family_members)): ?>
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle"></i> No family members found.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>NIC/ID</th>
                                <th>Relation</th>
                                <th>Gender</th>
                                <th>Date of Birth</th>
                                <th>Contact</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($family_members as $index => $member): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                        <?php if ($member['identification_number'] == $family_details['family_head_nic']): ?>
                                            <span class="badge bg-primary ms-1">Head</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($member['identification_number']); ?></td>
                                    <td><?php echo htmlspecialchars($member['relation_to_head']); ?></td>
                                    <td><?php echo ucfirst($member['gender']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($member['date_of_birth'])); ?></td>
                                    <td>
                                        <?php if (!empty($member['mobile_phone'])): ?>
                                            <i class="bi bi-phone"></i> <?php echo htmlspecialchars($member['mobile_phone']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editMemberModal<?php echo $member['citizen_id']; ?>">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <a href="edit_member.php?id=<?php echo $member['citizen_id']; ?>" 
                                               class="btn btn-outline-info" title="Edit Details">
                                                <i class="bi bi-gear"></i>
                                            </a>
                                        </div>
                                        
                                        <!-- Edit Member Modal -->
                                        <div class="modal fade" id="editMemberModal<?php echo $member['citizen_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="POST" action="">
                                                        <input type="hidden" name="action" value="update_member">
                                                        <input type="hidden" name="member_id" value="<?php echo $member['citizen_id']; ?>">
                                                        
                                                        <div class="modal-header bg-primary text-white">
                                                            <h5 class="modal-title">
                                                                <i class="bi bi-person"></i> Edit Member: <?php echo htmlspecialchars($member['full_name']); ?>
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="row">
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label required">Full Name</label>
                                                                    <input type="text" class="form-control" name="full_name" 
                                                                           value="<?php echo htmlspecialchars($member['full_name']); ?>" required>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label required">ID Type</label>
                                                                    <select class="form-select" name="identification_type" required>
                                                                        <option value="nic" <?php echo $member['identification_type'] == 'nic' ? 'selected' : ''; ?>>NIC</option>
                                                                        <option value="passport" <?php echo $member['identification_type'] == 'passport' ? 'selected' : ''; ?>>Passport</option>
                                                                        <option value="birth_certificate" <?php echo $member['identification_type'] == 'birth_certificate' ? 'selected' : ''; ?>>Birth Certificate</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row">
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label required">ID Number</label>
                                                                    <input type="text" class="form-control" name="identification_number" 
                                                                           value="<?php echo htmlspecialchars($member['identification_number']); ?>" required>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label required">Date of Birth</label>
                                                                    <input type="date" class="form-control" name="date_of_birth" 
                                                                           value="<?php echo $member['date_of_birth']; ?>" required>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row">
                                                                <div class="col-md-4 mb-3">
                                                                    <label class="form-label required">Gender</label>
                                                                    <select class="form-select" name="gender" required>
                                                                        <option value="male" <?php echo $member['gender'] == 'male' ? 'selected' : ''; ?>>Male</option>
                                                                        <option value="female" <?php echo $member['gender'] == 'female' ? 'selected' : ''; ?>>Female</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-4 mb-3">
                                                                    <label class="form-label">Marital Status</label>
                                                                    <select class="form-select" name="marital_status">
                                                                        <option value="">Select</option>
                                                                        <option value="single" <?php echo $member['marital_status'] == 'single' ? 'selected' : ''; ?>>Single</option>
                                                                        <option value="married" <?php echo $member['marital_status'] == 'married' ? 'selected' : ''; ?>>Married</option>
                                                                        <option value="divorced" <?php echo $member['marital_status'] == 'divorced' ? 'selected' : ''; ?>>Divorced</option>
                                                                        <option value="widowed" <?php echo $member['marital_status'] == 'widowed' ? 'selected' : ''; ?>>Widowed</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-4 mb-3">
                                                                    <label class="form-label required">Relation to Head</label>
                                                                    <select class="form-select" name="relation_to_head" required>
                                                                        <option value="Self" <?php echo $member['relation_to_head'] == 'Self' ? 'selected' : ''; ?>>Self (Head)</option>
                                                                        <option value="Spouse" <?php echo $member['relation_to_head'] == 'Spouse' ? 'selected' : ''; ?>>Spouse</option>
                                                                        <option value="Child" <?php echo $member['relation_to_head'] == 'Child' ? 'selected' : ''; ?>>Child</option>
                                                                        <option value="Parent" <?php echo $member['relation_to_head'] == 'Parent' ? 'selected' : ''; ?>>Parent</option>
                                                                        <option value="Sibling" <?php echo $member['relation_to_head'] == 'Sibling' ? 'selected' : ''; ?>>Sibling</option>
                                                                        <option value="Other" <?php echo $member['relation_to_head'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row">
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label">Mobile Phone</label>
                                                                    <input type="tel" class="form-control" name="mobile_phone" 
                                                                           value="<?php echo htmlspecialchars($member['mobile_phone'] ?? ''); ?>">
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label">Email Address</label>
                                                                    <input type="email" class="form-control" name="email" 
                                                                           value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">
                                                                <i class="bi bi-save"></i> Update Member
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Land Details Card -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-geo-alt"></i> Land Details (<?php echo count($land_details); ?>)</h5>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addLandModal">
                <i class="bi bi-plus-circle"></i> Add Land
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($land_details)): ?>
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle"></i> No land records found.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Land Type</th>
                                <th>Size (Perches)</th>
                                <th>Deed Number</th>
                                <th>Address</th>
                                <th>Ownership</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($land_details as $index => $land): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo ucfirst($land['land_type']); ?></td>
                                    <td><?php echo $land['land_size_perches']; ?></td>
                                    <td><?php echo htmlspecialchars($land['deed_number'] ?? 'N/A'); ?></td>
                                    <td>
                                        <small><?php echo htmlspecialchars($land['land_address'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo ucfirst($land['ownership_type'] ?? 'N/A'); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-danger" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteLandModal<?php echo $land['land_id']; ?>">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                        </div>
                                        
                                        <!-- Delete Land Modal -->
                                        <div class="modal fade" id="deleteLandModal<?php echo $land['land_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST" action="">
                                                        <input type="hidden" name="action" value="delete_land">
                                                        <input type="hidden" name="land_id" value="<?php echo $land['land_id']; ?>">
                                                        
                                                        <div class="modal-header bg-danger text-white">
                                                            <h5 class="modal-title">
                                                                <i class="bi bi-exclamation-triangle"></i> Confirm Deletion
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to delete this land record?</p>
                                                            <div class="alert alert-warning">
                                                                <strong>Land Type:</strong> <?php echo ucfirst($land['land_type']); ?><br>
                                                                <strong>Size:</strong> <?php echo $land['land_size_perches']; ?> perches<br>
                                                                <strong>Deed Number:</strong> <?php echo htmlspecialchars($land['deed_number'] ?? 'N/A'); ?>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-danger">
                                                                <i class="bi bi-trash"></i> Delete Land Record
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Quick Links -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-link"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-info w-100">
                                <i class="bi bi-eye"></i> View Family
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="transfer_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-warning w-100">
                                <i class="bi bi-arrow-left-right"></i> Transfer Family
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="add_member.php?family_id=<?php echo urlencode($family_id); ?>" class="btn btn-success w-100">
                                <i class="bi bi-person-plus"></i> Add Member
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="list_families.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-left"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Add Land Modal -->
<div class="modal fade" id="addLandModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_land">
                
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-geo-alt"></i> Add New Land Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Land Type</label>
                            <select class="form-select" name="land_type" required>
                                <option value="">Select Land Type</option>
                                <option value="residential">Residential</option>
                                <option value="agricultural">Agricultural</option>
                                <option value="commercial">Commercial</option>
                                <option value="vacant">Vacant</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Land Size (Perches)</label>
                            <input type="number" class="form-control" name="land_size_perches" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Deed Number</label>
                            <input type="text" class="form-control" name="deed_number">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ownership Type</label>
                            <select class="form-select" name="ownership_type">
                                <option value="">Select Ownership</option>
                                <option value="owned">Owned</option>
                                <option value="leased">Leased</option>
                                <option value="rented">Rented</option>
                                <option value="inherited">Inherited</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Land Address</label>
                            <textarea class="form-control" name="land_address" rows="2"></textarea>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="land_notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save"></i> Add Land Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .required::after { content: " *"; color: #dc3545; }
    .member-card { border-left: 4px solid #0d6efd; }
    .land-card { border-left: 4px solid #198754; }
    .table th { background-color: #f8f9fa; }
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
    
    // Calculate age from date of birth
    const dobInputs = document.querySelectorAll('input[name="date_of_birth"]');
    dobInputs.forEach(input => {
        input.addEventListener('change', function() {
            const dob = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const monthDiff = today.getMonth() - dob.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                age--;
            }
            
            // Find the age display element
            const row = this.closest('.row');
            const ageInput = row.querySelector('input[name="age"]');
            if (ageInput) {
                ageInput.value = age;
            }
        });
    });
    
    // Form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill all required fields.');
            }
        });
    });
});
</script>

<?php 
// Include footer
require_once '../../../includes/footer.php';
?>