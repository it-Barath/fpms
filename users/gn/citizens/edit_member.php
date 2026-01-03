<?php
// users/gn/citizens/edit_member.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Edit Family Member";
$pageIcon = "bi bi-pencil-fill";
$pageDescription = "Edit family member details";
$bodyClass = "bg-light";

try {
    require_once '../../../config.php';
    require_once '../../../classes/Auth.php';
    
    // Check if session is already started
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
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $gn_id = $_SESSION['office_code'] ?? '';
    
    // Check if citizen ID is provided
    if (!isset($_GET['citizen_id']) || empty(trim($_GET['citizen_id']))) {
        header('Location: list_families.php?error=missing_citizen_id');
        exit();
    }
    
    $citizen_id = (int)trim($_GET['citizen_id']);
    
    // Get citizen details with family verification
    $citizen_query = "SELECT c.*, f.gn_id, f.family_id, f.address as family_address
                     FROM citizens c
                     JOIN families f ON c.family_id = f.family_id
                     WHERE c.citizen_id = ? AND f.gn_id = ?";
    
    $citizen_stmt = $db->prepare($citizen_query);
    $citizen_stmt->bind_param("is", $citizen_id, $gn_id);
    $citizen_stmt->execute();
    $citizen_result = $citizen_stmt->get_result();
    
    if ($citizen_result->num_rows === 0) {
        header('Location: list_families.php?error=member_not_found');
        exit();
    }
    
    $member = $citizen_result->fetch_assoc();
    $family_id = $member['family_id'];
    
    // Get family head name for reference
    $head_query = "SELECT full_name FROM citizens 
                   WHERE family_id = ? AND relation_to_head = 'Self' 
                   LIMIT 1";
    $head_stmt = $db->prepare($head_query);
    $head_stmt->bind_param("s", $family_id);
    $head_stmt->execute();
    $head_result = $head_stmt->get_result();
    $family_head = $head_result->fetch_assoc() ?? ['full_name' => 'Unknown'];

    $error = '';
    $success = '';
    
    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            // Basic validation
            if (empty(trim($_POST['full_name'] ?? ''))) {
                throw new Exception("Full name is required");
            }
            
            if (empty(trim($_POST['name_with_initials'] ?? ''))) {
                throw new Exception("Name with initials is required");
            }
            
            if (empty($_POST['gender'] ?? '')) {
                throw new Exception("Gender is required");
            }
            
            if (empty($_POST['date_of_birth'] ?? '')) {
                throw new Exception("Date of birth is required");
            }
            
            if (empty($_POST['relation_to_head'] ?? '')) {
                throw new Exception("Relation to family head is required");
            }
            
            // Validate date of birth
            $dob = $_POST['date_of_birth'];
            if (strtotime($dob) > time()) {
                throw new Exception("Date of birth cannot be in the future");
            }
            
            // Check if changing relation from 'Self' (family head)
            if ($member['relation_to_head'] === 'Self' && $_POST['relation_to_head'] !== 'Self') {
                // Check if there's at least one other member who could be head
                $check_head_query = "SELECT COUNT(*) as other_members 
                                     FROM citizens 
                                     WHERE family_id = ? 
                                     AND citizen_id != ? 
                                     AND relation_to_head != 'Self'";
                $check_head_stmt = $db->prepare($check_head_query);
                $check_head_stmt->bind_param("si", $family_id, $citizen_id);
                $check_head_stmt->execute();
                $check_result = $check_head_stmt->get_result();
                $row = $check_result->fetch_assoc();
                
                if ($row['other_members'] == 0) {
                    throw new Exception("Cannot change family head relation. There must be at least one other family member to assign as head.");
                }
            }
            
            // Check if setting new family head
            if ($_POST['relation_to_head'] === 'Self' && $member['relation_to_head'] !== 'Self') {
                // Update old head to 'Other'
                $update_old_head = "UPDATE citizens SET relation_to_head = 'Other' 
                                    WHERE family_id = ? AND relation_to_head = 'Self'";
                $update_stmt = $db->prepare($update_old_head);
                $update_stmt->bind_param("s", $family_id);
                $update_stmt->execute();
                
                // Update family head NIC if this member has NIC
                if ($_POST['identification_type'] === 'nic' && !empty($_POST['identification_number'])) {
                    $update_family = "UPDATE families SET family_head_nic = ? WHERE family_id = ?";
                    $family_update_stmt = $db->prepare($update_family);
                    $family_update_stmt->bind_param("ss", $_POST['identification_number'], $family_id);
                    $family_update_stmt->execute();
                }
            }
            
            // Prepare data
            $identification_type = $_POST['identification_type'] ?? 'other';
            $identification_number = $_POST['identification_number'] ?? '';
            $full_name = trim($_POST['full_name']);
            $name_with_initials = trim($_POST['name_with_initials']);
            $gender = $_POST['gender'];
            $date_of_birth = $_POST['date_of_birth'];
            $ethnicity = !empty($_POST['ethnicity']) ? $_POST['ethnicity'] : null;
            $religion = !empty($_POST['religion']) ? $_POST['religion'] : null;
            $mobile_phone = !empty($_POST['mobile_phone']) ? preg_replace('/[^0-9]/', '', $_POST['mobile_phone']) : null;
            $home_phone = !empty($_POST['home_phone']) ? preg_replace('/[^0-9]/', '', $_POST['home_phone']) : null;
            $email = !empty($_POST['email']) ? $_POST['email'] : null;
            $member_address = !empty($_POST['member_address']) ? trim($_POST['member_address']) : null;
            $relation_to_head = $_POST['relation_to_head'];
            $marital_status = !empty($_POST['marital_status']) ? $_POST['marital_status'] : null;
            $is_alive = isset($_POST['is_alive']) ? 1 : 0;

            // Update citizen
            $update_query = "UPDATE citizens SET
                            identification_type = ?,
                            identification_number = ?,
                            full_name = ?,
                            name_with_initials = ?,
                            gender = ?,
                            date_of_birth = ?,
                            ethnicity = ?,
                            religion = ?,
                            mobile_phone = ?,
                            home_phone = ?,
                            email = ?,
                            address = ?,
                            relation_to_head = ?,
                            marital_status = ?,
                            is_alive = ?,
                            updated_at = CURRENT_TIMESTAMP
                            WHERE citizen_id = ?";
            
            $update_stmt = $db->prepare($update_query);
            if (!$update_stmt) {
                throw new Exception("Failed to prepare update query: " . $db->error);
            }
            
            $update_stmt->bind_param("ssssssssssssssii", 
                $identification_type,
                $identification_number,
                $full_name,
                $name_with_initials,
                $gender,
                $date_of_birth,
                $ethnicity,
                $religion,
                $mobile_phone,
                $home_phone,
                $email,
                $member_address,
                $relation_to_head,
                $marital_status,
                $is_alive,
                $citizen_id
            );
            
            if (!$update_stmt->execute()) {
                // Check for duplicate identification number
                if ($db->errno == 1062) {
                    throw new Exception("This identification number already exists for another member.");
                }
                throw new Exception("Failed to update member: " . $update_stmt->error);
            }
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values) 
                           VALUES (?, 'update_member', 'citizens', ?, ?)";
            $audit_stmt = $db->prepare($audit_query);
            $new_values = json_encode([
                'citizen_id' => $citizen_id,
                'member_name' => $full_name,
                'family_id' => $family_id,
                'changes' => $_POST
            ]);
            $audit_stmt->bind_param("iis", $user_id, $citizen_id, $new_values);
            $audit_stmt->execute();
            
            $success = "Member updated successfully!";
            
            // Refresh member data
            $member = array_merge($member, [
                'identification_type' => $identification_type,
                'identification_number' => $identification_number,
                'full_name' => $full_name,
                'name_with_initials' => $name_with_initials,
                'gender' => $gender,
                'date_of_birth' => $date_of_birth,
                'ethnicity' => $ethnicity,
                'religion' => $religion,
                'mobile_phone' => $mobile_phone,
                'home_phone' => $home_phone,
                'email' => $email,
                'address' => $member_address,
                'relation_to_head' => $relation_to_head,
                'marital_status' => $marital_status,
                'is_alive' => $is_alive
            ]);
            
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
            error_log("Edit Member Error: " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Edit Member System Error: " . $e->getMessage());
}
?>

<?php require_once '../../../includes/header.php'; ?>
<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../../../includes/sidebar.php'; ?>
        </div>
        
        <!-- Main Content -->
        <main class="main-content">


    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">
            <i class="bi bi-pencil-fill me-2"></i>
            Edit Family Member
        </h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back to Family
            </a>
            <a href="list_families.php" class="btn btn-outline-secondary">
                <i class="bi bi-list"></i> Family List
            </a>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <h5><i class="bi bi-exclamation-triangle"></i> Error</h5>
            <p><?php echo htmlspecialchars($error); ?></p>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <h5><i class="bi bi-check-circle"></i> Success!</h5>
            <p><?php echo $success; ?></p>
            <div class="mt-2">
                <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-eye"></i> View Family
                </a>
                <a href="edit_member.php?citizen_id=<?php echo $citizen_id; ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil"></i> Continue Editing
                </a>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <!-- Family Info Card -->
    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-house-door"></i> Family Information</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Family ID</label>
                    <p class="font-monospace text-primary"><?php echo htmlspecialchars($family_id); ?></p>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Family Head</label>
                    <p><?php echo htmlspecialchars($family_head['full_name']); ?></p>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Member ID</label>
                    <p class="font-monospace">#<?php echo $citizen_id; ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Member Form -->
    <form method="POST" action="" id="editMemberForm" class="needs-validation" novalidate>
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-person-gear"></i> Member Details</h5>
            </div>
            <div class="card-body">
                <!-- Name Section -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Full Name</label>
                        <input type="text" class="form-control" name="full_name" 
                               value="<?php echo htmlspecialchars($member['full_name']); ?>" required>
                        <div class="invalid-feedback">Please enter full name</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Name with Initials</label>
                        <input type="text" class="form-control" name="name_with_initials" 
                               value="<?php echo htmlspecialchars($member['name_with_initials']); ?>" 
                               placeholder="e.g., A.B. Perera" required>
                        <div class="invalid-feedback">Please enter name with initials</div>
                    </div>
                </div>
                
                <!-- Personal Details -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <label class="form-label required">Gender</label>
                        <select class="form-select" name="gender" required>
                            <option value="">Select</option>
                            <option value="male" <?php echo $member['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo $member['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo $member['gender'] === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                        <div class="invalid-feedback">Please select gender</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label required">Date of Birth</label>
                        <input type="date" class="form-control" name="date_of_birth" 
                               value="<?php echo htmlspecialchars($member['date_of_birth']); ?>"
                               max="<?php echo date('Y-m-d'); ?>" required>
                        <div class="invalid-feedback">Please enter date of birth</div>
                        <?php if (!empty($member['date_of_birth'])): ?>
                            <?php
                            $birthDate = new DateTime($member['date_of_birth']);
                            $today = new DateTime();
                            $age = $today->diff($birthDate)->y;
                            ?>
                            <small class="text-muted">Age: <?php echo $age; ?> years</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label required">Relation to Family Head</label>
                        <select class="form-select" name="relation_to_head" required>
                            <option value="">Select Relation</option>
                            <option value="Self" <?php echo $member['relation_to_head'] === 'Self' ? 'selected' : ''; ?>>Self (Family Head)</option>
                            <option value="Husband" <?php echo $member['relation_to_head'] === 'Husband' ? 'selected' : ''; ?>>Husband</option>
                            <option value="Wife" <?php echo $member['relation_to_head'] === 'Wife' ? 'selected' : ''; ?>>Wife</option>
                            <option value="Son" <?php echo $member['relation_to_head'] === 'Son' ? 'selected' : ''; ?>>Son</option>
                            <option value="Daughter" <?php echo $member['relation_to_head'] === 'Daughter' ? 'selected' : ''; ?>>Daughter</option>
                            <option value="Father" <?php echo $member['relation_to_head'] === 'Father' ? 'selected' : ''; ?>>Father</option>
                            <option value="Mother" <?php echo $member['relation_to_head'] === 'Mother' ? 'selected' : ''; ?>>Mother</option>
                            <option value="Brother" <?php echo $member['relation_to_head'] === 'Brother' ? 'selected' : ''; ?>>Brother</option>
                            <option value="Sister" <?php echo $member['relation_to_head'] === 'Sister' ? 'selected' : ''; ?>>Sister</option>
                            <option value="Grandfather" <?php echo $member['relation_to_head'] === 'Grandfather' ? 'selected' : ''; ?>>Grandfather</option>
                            <option value="Grandmother" <?php echo $member['relation_to_head'] === 'Grandmother' ? 'selected' : ''; ?>>Grandmother</option>
                            <option value="Uncle" <?php echo $member['relation_to_head'] === 'Uncle' ? 'selected' : ''; ?>>Uncle</option>
                            <option value="Aunt" <?php echo $member['relation_to_head'] === 'Aunt' ? 'selected' : ''; ?>>Aunt</option>
                            <option value="Cousin" <?php echo $member['relation_to_head'] === 'Cousin' ? 'selected' : ''; ?>>Cousin</option>
                            <option value="Guardian" <?php echo $member['relation_to_head'] === 'Guardian' ? 'selected' : ''; ?>>Guardian</option>
                            <option value="Other" <?php echo $member['relation_to_head'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                        <div class="invalid-feedback">Please select relation to family head</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Marital Status</label>
                        <select class="form-select" name="marital_status">
                            <option value="">Select</option>
                            <option value="single" <?php echo $member['marital_status'] === 'single' ? 'selected' : ''; ?>>Single</option>
                            <option value="married" <?php echo $member['marital_status'] === 'married' ? 'selected' : ''; ?>>Married</option>
                            <option value="divorced" <?php echo $member['marital_status'] === 'divorced' ? 'selected' : ''; ?>>Divorced</option>
                            <option value="widowed" <?php echo $member['marital_status'] === 'widowed' ? 'selected' : ''; ?>>Widowed</option>
                        </select>
                    </div>
                </div>
                
                <!-- Identification Section -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Identification Type</label>
                        <select class="form-select" name="identification_type">
                            <option value="other">Other</option>
                            <option value="nic" <?php echo $member['identification_type'] === 'nic' ? 'selected' : ''; ?>>NIC</option>
                            <option value="passport" <?php echo $member['identification_type'] === 'passport' ? 'selected' : ''; ?>>Passport</option>
                            <option value="postal" <?php echo $member['identification_type'] === 'postal' ? 'selected' : ''; ?>>Postal ID</option>
                            <option value="driving" <?php echo $member['identification_type'] === 'driving' ? 'selected' : ''; ?>>Driving License</option>
                        </select>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Identification Number</label>
                        <input type="text" class="form-control" name="identification_number" 
                               value="<?php echo htmlspecialchars($member['identification_number']); ?>">
                        <?php if ($member['identification_type'] === 'nic'): ?>
                            <small class="text-muted">Format: 9 digits with V/X or 12 digits</small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Ethnicity and Religion -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Ethnicity</label>
                        <select class="form-select" name="ethnicity">
                            <option value="">Select</option>
                            <option value="Sinhala" <?php echo $member['ethnicity'] === 'Sinhala' ? 'selected' : ''; ?>>Sinhala</option>
                            <option value="Tamil" <?php echo $member['ethnicity'] === 'Tamil' ? 'selected' : ''; ?>>Tamil</option>
                            <option value="Muslim" <?php echo $member['ethnicity'] === 'Muslim' ? 'selected' : ''; ?>>Muslim</option>
                            <option value="Burgher" <?php echo $member['ethnicity'] === 'Burgher' ? 'selected' : ''; ?>>Burgher</option>
                            <option value="Malay" <?php echo $member['ethnicity'] === 'Malay' ? 'selected' : ''; ?>>Malay</option>
                            <option value="Other" <?php echo $member['ethnicity'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Religion</label>
                        <select class="form-select" name="religion">
                            <option value="">Select</option>
                            <option value="Buddhist" <?php echo $member['religion'] === 'Buddhist' ? 'selected' : ''; ?>>Buddhist</option>
                            <option value="Hindu" <?php echo $member['religion'] === 'Hindu' ? 'selected' : ''; ?>>Hindu</option>
                            <option value="Islam" <?php echo $member['religion'] === 'Islam' ? 'selected' : ''; ?>>Islam</option>
                            <option value="Christian" <?php echo $member['religion'] === 'Christian' ? 'selected' : ''; ?>>Christian</option>
                            <option value="Other" <?php echo $member['religion'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
                
                <!-- Contact Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Mobile Phone</label>
                        <input type="tel" class="form-control" name="mobile_phone"
                               value="<?php echo htmlspecialchars($member['mobile_phone'] ?? ''); ?>"
                               placeholder="e.g., 0712345678" maxlength="10">
                        <small class="text-muted">10 digits only</small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Home Phone</label>
                        <input type="tel" class="form-control" name="home_phone"
                               value="<?php echo htmlspecialchars($member['home_phone'] ?? ''); ?>"
                               placeholder="e.g., 0112345678" maxlength="10">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email"
                               value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>">
                    </div>
                </div>
                
                <!-- Personal Address -->
                <div class="mb-4">
                    <label class="form-label">Personal Address</label>
                    <textarea class="form-control" name="member_address" rows="2"><?php echo htmlspecialchars($member['address'] ?? ''); ?></textarea>
                    <small class="text-muted">If different from family address</small>
                </div>
                
                <!-- Status -->
                <div class="mb-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_alive" 
                               id="is_alive" value="1" <?php echo $member['is_alive'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_alive">
                            <span class="badge bg-<?php echo $member['is_alive'] ? 'success' : 'danger'; ?>">
                                <?php echo $member['is_alive'] ? 'Alive' : 'Deceased'; ?>
                            </span>
                            - Mark as <?php echo $member['is_alive'] ? 'deceased' : 'alive'; ?>
                        </label>
                    </div>
                    <small class="text-muted">Toggle to change living status</small>
                </div>
                
                <!-- System Info (Readonly) -->
                <div class="card border-light mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> System Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <small class="text-muted">Created:</small>
                                <p class="small"><?php echo date('d M Y, h:i A', strtotime($member['created_at'])); ?></p>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted">Last Updated:</small>
                                <p class="small"><?php echo date('d M Y, h:i A', strtotime($member['updated_at'])); ?></p>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted">Member ID:</small>
                                <p class="small font-monospace">#<?php echo $citizen_id; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Buttons -->
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-secondary me-md-2">
                        <i class="bi bi-x-circle"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Update Member
                    </button>
                </div>
            </div>
        </div>
    </form>
</main>

<style>
    .required::after {
        content: " *";
        color: #dc3545;
    }
    .form-check-input:checked {
        background-color: #198754;
        border-color: #198754;
    }
    .badge {
        font-weight: 500;
    }
    .card.border-light {
        border: 1px solid #e9ecef;
    }
</style>

<script src="../../../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('editMemberForm');
        
        // Form validation
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
        
        // Phone number formatting
        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 10) {
                    this.value = this.value.substring(0, 10);
                }
            });
        });
        
        // NIC validation when NIC is selected
        const idTypeSelect = document.querySelector('select[name="identification_type"]');
        const idNumberInput = document.querySelector('input[name="identification_number"]');
        
        idTypeSelect.addEventListener('change', function() {
            if (this.value === 'nic' && idNumberInput.value) {
                validateNIC(idNumberInput.value);
            }
        });
        
        idNumberInput.addEventListener('blur', function() {
            if (idTypeSelect.value === 'nic' && this.value) {
                validateNIC(this.value);
            }
        });
        
        function validateNIC(nicValue) {
            const nic = nicValue.toUpperCase();
            const nicRegex = /^[0-9]{9}[VX]$|^[0-9]{12}$/;
            
            if (!nicRegex.test(nic)) {
                idNumberInput.setCustomValidity('Please enter valid NIC (9 digits with V/X or 12 digits)');
                idNumberInput.classList.add('is-invalid');
            } else {
                idNumberInput.setCustomValidity('');
                idNumberInput.classList.remove('is-invalid');
            }
        }
        
        // Toggle switch label update
        const isAliveSwitch = document.getElementById('is_alive');
        const switchLabel = isAliveSwitch.closest('.form-check').querySelector('.badge');
        
        isAliveSwitch.addEventListener('change', function() {
            if (this.checked) {
                switchLabel.textContent = 'Alive';
                switchLabel.className = 'badge bg-success';
                switchLabel.nextSibling.textContent = ' - Mark as deceased';
            } else {
                switchLabel.textContent = 'Deceased';
                switchLabel.className = 'badge bg-danger';
                switchLabel.nextSibling.textContent = ' - Mark as alive';
            }
        });
        
        // Warning when changing family head relation
        const relationSelect = document.querySelector('select[name="relation_to_head"]');
        const originalRelation = "<?php echo $member['relation_to_head']; ?>";
        
        relationSelect.addEventListener('change', function() {
            if (originalRelation === 'Self' && this.value !== 'Self') {
                if (!confirm('Warning: You are changing the family head relation. This member will no longer be the family head. Continue?')) {
                    this.value = originalRelation;
                }
            } else if (originalRelation !== 'Self' && this.value === 'Self') {
                if (!confirm('Warning: You are setting this member as family head. The current head will be changed to "Other". Continue?')) {
                    this.value = originalRelation;
                }
            }
        });
        
        // Calculate age on date change
        const dobInput = document.querySelector('input[name="date_of_birth"]');
        const ageDisplay = document.createElement('small');
        ageDisplay.className = 'text-muted d-block mt-1';
        
        if (dobInput.nextSibling && dobInput.nextSibling.nodeType === 3) {
            dobInput.parentNode.insertBefore(ageDisplay, dobInput.nextSibling);
        } else {
            dobInput.parentNode.appendChild(ageDisplay);
        }
        
        dobInput.addEventListener('change', calculateAge);
        
        function calculateAge() {
            if (dobInput.value) {
                const birthDate = new Date(dobInput.value);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const monthDiff = today.getMonth() - birthDate.getMonth();
                
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
                
                ageDisplay.textContent = 'Age: ' + age + ' years';
            } else {
                ageDisplay.textContent = '';
            }
        }
        
        // Initialize age display
        calculateAge();
    });
</script>

<?php 
// Include footer if exists
$footer_path = '../../../includes/footer.php';
if (file_exists($footer_path)) {
    include $footer_path;
} else {
    echo '</div></div></body></html>';
}
?>