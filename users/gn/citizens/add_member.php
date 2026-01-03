<?php
// users/gn/citizens/add_member.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Add Family Member";
$pageIcon = "bi bi-person-plus-fill";
$pageDescription = "Add new member to existing family";
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

    $error = '';
    $success = '';
    $family_id = '';
    $family_data = [];
    
    // Check if family ID is provided - FIXED THIS
    if (!isset($_GET['family_id']) || empty(trim($_GET['family_id']))) {
        // Redirect to family list with error
        header('Location: list_families.php?error=missing_family_id');
        exit();
    }
    
    $family_id = trim($_GET['family_id']);
    
    // Verify the family belongs to this GN office
    $family_query = "SELECT f.*, c.full_name as head_name 
                     FROM families f
                     LEFT JOIN citizens c ON f.family_id = c.family_id AND c.relation_to_head = 'Self'
                     WHERE f.family_id = ? AND f.gn_id = ?";
    
    $family_stmt = $db->prepare($family_query);
    $family_stmt->bind_param("ss", $family_id, $gn_id);
    $family_stmt->execute();
    $family_result = $family_stmt->get_result();
    
    if ($family_result->num_rows === 0) {
        header('Location: list_families.php?error=family_not_found');
        exit();
    }
    
    $family_data = $family_result->fetch_assoc();

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
            $is_alive = 1;

            // Start transaction
            $db->begin_transaction();
            
            // Add the family member
            $citizen_query = "INSERT INTO citizens 
                            (family_id, identification_type, identification_number, full_name, 
                             name_with_initials, gender, date_of_birth, ethnicity, religion, 
                             mobile_phone, home_phone, email, address, relation_to_head, 
                             marital_status, is_alive) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $citizen_stmt = $db->prepare($citizen_query);
            if (!$citizen_stmt) {
                throw new Exception("Failed to prepare citizen query: " . $db->error);
            }
            
            $citizen_stmt->bind_param("sssssssssssssssi", 
                $family_id,
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
                $is_alive
            );
            
            if (!$citizen_stmt->execute()) {
                // Check for duplicate identification number
                if ($db->errno == 1062) {
                    throw new Exception("This identification number already exists in the system.");
                }
                throw new Exception("Failed to add family member: " . $citizen_stmt->error);
            }
            
            // Update family member count
            $update_query = "UPDATE families SET total_members = total_members + 1 WHERE family_id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bind_param("s", $family_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update family member count: " . $update_stmt->error);
            }
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values) 
                           VALUES (?, 'add_member', 'citizens', ?, ?)";
            $audit_stmt = $db->prepare($audit_query);
            $new_values = json_encode([
                'family_id' => $family_id,
                'member_name' => $full_name,
                'relation' => $relation_to_head
            ]);
            $audit_stmt->bind_param("iss", $user_id, $family_id, $new_values);
            $audit_stmt->execute();
            
            // Commit transaction
            $db->commit();
            
            $success = "Family member added successfully!";
            
            // Don't reset POST so form keeps values if there's an error
            
        } catch (Exception $e) {
            // Rollback on error
            if (isset($db) && $db) {
                $db->rollback();
            }
            $error = "Error: " . $e->getMessage();
            error_log("Add Member Error: " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Add Member System Error: " . $e->getMessage());
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
            <i class="bi bi-person-plus-fill me-2"></i>
            Add Family Member
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
                <a href="add_member.php?family_id=<?php echo urlencode($family_id); ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle"></i> Add Another Member
                </a>
                <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye"></i> View Family
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
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Family ID</label>
                    <p class="font-monospace text-primary"><?php echo htmlspecialchars($family_data['family_id'] ?? ''); ?></p>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Family Head</label>
                    <p><?php echo htmlspecialchars($family_data['head_name'] ?? 'N/A'); ?></p>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Current Members</label>
                    <p><span class="badge bg-primary"><?php echo $family_data['total_members'] ?? 0; ?> members</span></p>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Family Address</label>
                    <p><?php echo nl2br(htmlspecialchars($family_data['address'] ?? '')); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Member Form -->
    <form method="POST" action="" id="memberForm" class="needs-validation" novalidate>
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-person-plus"></i> New Member Details</h5>
            </div>
            <div class="card-body">
                <!-- Name Section -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Full Name</label>
                        <input type="text" class="form-control" name="full_name" 
                               value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                        <div class="invalid-feedback">Please enter full name</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label required">Name with Initials</label>
                        <input type="text" class="form-control" name="name_with_initials" 
                               value="<?php echo htmlspecialchars($_POST['name_with_initials'] ?? ''); ?>" 
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
                            <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                        <div class="invalid-feedback">Please select gender</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label required">Date of Birth</label>
                        <input type="date" class="form-control" name="date_of_birth" 
                               value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>"
                               max="<?php echo date('Y-m-d'); ?>" required>
                        <div class="invalid-feedback">Please enter date of birth</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label required">Relation to Family Head</label>
                        <select class="form-select" name="relation_to_head" required>
                            <option value="">Select Relation</option>
                            <option value="Husband" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Husband') ? 'selected' : ''; ?>>Husband</option>
                            <option value="Wife" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Wife') ? 'selected' : ''; ?>>Wife</option>
                            <option value="Son" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Son') ? 'selected' : ''; ?>>Son</option>
                            <option value="Daughter" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Daughter') ? 'selected' : ''; ?>>Daughter</option>
                            <option value="Father" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Father') ? 'selected' : ''; ?>>Father</option>
                            <option value="Mother" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Mother') ? 'selected' : ''; ?>>Mother</option>
                            <option value="Brother" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Brother') ? 'selected' : ''; ?>>Brother</option>
                            <option value="Sister" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Sister') ? 'selected' : ''; ?>>Sister</option>
                            <option value="Grandfather" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Grandfather') ? 'selected' : ''; ?>>Grandfather</option>
                            <option value="Grandmother" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Grandmother') ? 'selected' : ''; ?>>Grandmother</option>
                            <option value="Other" <?php echo (isset($_POST['relation_to_head']) && $_POST['relation_to_head'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                        <div class="invalid-feedback">Please select relation to family head</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Marital Status</label>
                        <select class="form-select" name="marital_status">
                            <option value="">Select</option>
                            <option value="single" <?php echo (isset($_POST['marital_status']) && $_POST['marital_status'] === 'single') ? 'selected' : ''; ?>>Single</option>
                            <option value="married" <?php echo (isset($_POST['marital_status']) && $_POST['marital_status'] === 'married') ? 'selected' : ''; ?>>Married</option>
                            <option value="divorced" <?php echo (isset($_POST['marital_status']) && $_POST['marital_status'] === 'divorced') ? 'selected' : ''; ?>>Divorced</option>
                            <option value="widowed" <?php echo (isset($_POST['marital_status']) && $_POST['marital_status'] === 'widowed') ? 'selected' : ''; ?>>Widowed</option>
                        </select>
                    </div>
                </div>
                
                <!-- Identification Section -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Identification Type</label>
                        <select class="form-select" name="identification_type">
                            <option value="other">Other</option>
                            <option value="nic" <?php echo (isset($_POST['identification_type']) && $_POST['identification_type'] === 'nic') ? 'selected' : ''; ?>>NIC</option>
                            <option value="passport" <?php echo (isset($_POST['identification_type']) && $_POST['identification_type'] === 'passport') ? 'selected' : ''; ?>>Passport</option>
                            <option value="postal" <?php echo (isset($_POST['identification_type']) && $_POST['identification_type'] === 'postal') ? 'selected' : ''; ?>>Postal ID</option>
                        </select>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Identification Number</label>
                        <input type="text" class="form-control" name="identification_number" 
                               value="<?php echo htmlspecialchars($_POST['identification_number'] ?? ''); ?>">
                    </div>
                </div>
                
                <!-- Ethnicity and Religion -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Ethnicity</label>
                        <select class="form-select" name="ethnicity">
                            <option value="">Select</option>
                            <option value="Sinhala" <?php echo (isset($_POST['ethnicity']) && $_POST['ethnicity'] === 'Sinhala') ? 'selected' : ''; ?>>Sinhala</option>
                            <option value="Tamil" <?php echo (isset($_POST['ethnicity']) && $_POST['ethnicity'] === 'Tamil') ? 'selected' : ''; ?>>Tamil</option>
                            <option value="Muslim" <?php echo (isset($_POST['ethnicity']) && $_POST['ethnicity'] === 'Muslim') ? 'selected' : ''; ?>>Muslim</option>
                            <option value="Burgher" <?php echo (isset($_POST['ethnicity']) && $_POST['ethnicity'] === 'Burgher') ? 'selected' : ''; ?>>Burgher</option>
                            <option value="Other" <?php echo (isset($_POST['ethnicity']) && $_POST['ethnicity'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Religion</label>
                        <select class="form-select" name="religion">
                            <option value="">Select</option>
                            <option value="Buddhist" <?php echo (isset($_POST['religion']) && $_POST['religion'] === 'Buddhist') ? 'selected' : ''; ?>>Buddhist</option>
                            <option value="Hindu" <?php echo (isset($_POST['religion']) && $_POST['religion'] === 'Hindu') ? 'selected' : ''; ?>>Hindu</option>
                            <option value="Islam" <?php echo (isset($_POST['religion']) && $_POST['religion'] === 'Islam') ? 'selected' : ''; ?>>Islam</option>
                            <option value="Christian" <?php echo (isset($_POST['religion']) && $_POST['religion'] === 'Christian') ? 'selected' : ''; ?>>Christian</option>
                            <option value="Other" <?php echo (isset($_POST['religion']) && $_POST['religion'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
                
                <!-- Contact Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Mobile Phone</label>
                        <input type="tel" class="form-control" name="mobile_phone"
                               value="<?php echo htmlspecialchars($_POST['mobile_phone'] ?? ''); ?>"
                               placeholder="e.g., 0712345678" maxlength="10">
                        <small class="text-muted">10 digits only</small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Home Phone</label>
                        <input type="tel" class="form-control" name="home_phone"
                               value="<?php echo htmlspecialchars($_POST['home_phone'] ?? ''); ?>"
                               placeholder="e.g., 0112345678" maxlength="10">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>
                
                <!-- Personal Address -->
                <div class="mb-3">
                    <label class="form-label">Personal Address</label>
                    <textarea class="form-control" name="member_address" rows="2"><?php echo htmlspecialchars($_POST['member_address'] ?? ''); ?></textarea>
                    <small class="text-muted">If different from family address</small>
                </div>
                
                <!-- Form Buttons -->
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <button type="reset" class="btn btn-secondary me-md-2">
                        <i class="bi bi-x-circle"></i> Clear
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus"></i> Add Member
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
</style>

<script src="../../../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('memberForm');
        
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