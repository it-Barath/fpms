<?php
// users/gn/citizens/add_family.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Add New Family";
$pageIcon = "bi bi-people-fill";
$pageDescription = "Register a new family with single member";
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

    $error = '';
    $success = '';
    $family_id = '';
    $gn_details = [];
    $form_data = []; // Store form data for repopulation

    // Generate 14-digit numeric Family ID
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

    // Helper function to log actions
    function logAction($user_id, $action, $table, $record_id, $old_values = null, $new_values = null) {
        try {
            $db = getMainConnection();
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, ip_address, user_agent) 
                                 VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $user_id, $action, $table, $record_id, $ip, $user_agent);
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
            return false;
        }
    }

    // Get GN details from reference database
    try {
        $ref_db = getRefConnection();
        $gn_query = "SELECT GN, GN_ID, Division_Name, District_Name, Province_Name 
                     FROM mobile_service.fix_work_station 
                     WHERE GN_ID = ?";
        
        if ($stmt = $ref_db->prepare($gn_query)) {
            $stmt->bind_param("s", $gn_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $gn_details = $result->fetch_assoc();
            
            if (!$gn_details) {
                throw new Exception("Your GN office details were not found. Please contact administrator.");
            }
        }
    } catch (Exception $e) {
        throw new Exception("Error fetching GN details: " . $e->getMessage());
    }

    // Process form submission
 // Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store form data for repopulation
    $form_data = $_POST;
    
    // Debug: Log form data
    error_log("Form data received: " . print_r($_POST, true));
    
    // Track transaction status
    $transaction_started = false;
    $family_id = generateFamilyId($gn_id);
    
    try {
        // Start transaction
        $db->begin_transaction();
        $transaction_started = true;
        
        // Debug each field
        error_log("family_address: " . ($_POST['family_address'] ?? 'empty'));
        error_log("identification_type: " . ($_POST['identification_type'] ?? 'empty'));
        error_log("identification_number: " . ($_POST['identification_number'] ?? 'empty'));
        error_log("full_name: " . ($_POST['full_name'] ?? 'empty'));
        error_log("name_with_initials: " . ($_POST['name_with_initials'] ?? 'empty'));
        error_log("gender: " . ($_POST['gender'] ?? 'empty'));
        error_log("date_of_birth: " . ($_POST['date_of_birth'] ?? 'empty'));
        
        // SIMPLIFIED VALIDATION - Just check if fields are not empty
        $required_fields = [
            'family_address' => 'Family address',
            'identification_type' => 'Identification type',
            'identification_number' => 'Identification number',
            'full_name' => 'Full name',
            'name_with_initials' => 'Name with initials',
            'gender' => 'Gender',
            'date_of_birth' => 'Date of birth'
        ];
        
        $validation_errors = [];
        
        foreach ($required_fields as $field => $label) {
            $value = $_POST[$field] ?? '';
            
            // Trim text/textarea fields
            if ($field === 'family_address' || $field === 'full_name' || $field === 'name_with_initials') {
                $value = trim($value);
            }
            
            // Check if empty
            if (empty($value)) {
                $validation_errors[$field] = "$label is required";
                error_log("Validation error: $field is empty");
            }
        }
        
        // Validate date format if not empty
        if (!empty($_POST['date_of_birth']) && !isset($validation_errors['date_of_birth'])) {
            $dob = $_POST['date_of_birth'];
            $date_parts = explode('-', $dob);
            
            if (count($date_parts) !== 3 || !checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
                $validation_errors['date_of_birth'] = "Invalid date format";
            } else if (strtotime($dob) > time()) {
                $validation_errors['date_of_birth'] = "Date of birth cannot be in the future";
            }
        }
        
        // Validate NIC format if NIC is selected
        if (isset($_POST['identification_type']) && $_POST['identification_type'] === 'nic') {
            $nic = strtoupper(trim($_POST['identification_number'] ?? ''));
            if (!preg_match('/^[0-9]{9}[VX]$/', $nic) && !preg_match('/^[0-9]{12}$/', $nic)) {
                $validation_errors['identification_number'] = "Invalid NIC format. Use 9 digits with V/X or 12 digits";
            }
        }
        
        // Calculate age for minor confirmation
        if (!isset($validation_errors['date_of_birth']) && !empty($_POST['date_of_birth'])) {
            $dob = $_POST['date_of_birth'];
            try {
                $birthDate = new DateTime($dob);
                $today = new DateTime();
                $age = $today->diff($birthDate)->y;
                
                if ($age < 18) {
                    if (!isset($_POST['confirm_minor']) || $_POST['confirm_minor'] !== 'yes') {
                        $validation_errors['confirm_minor'] = "Family head is under 18. Please confirm to proceed.";
                    }
                }
            } catch (Exception $e) {
                $validation_errors['date_of_birth'] = "Invalid date of birth";
            }
        }
        
        // Validate phone numbers (optional fields)
        if (!empty($_POST['mobile_phone'])) {
            $phone = preg_replace('/[^0-9]/', '', $_POST['mobile_phone']);
            if (strlen($phone) !== 10) {
                $validation_errors['mobile_phone'] = "Mobile phone must be 10 digits";
            }
        }
        
        if (!empty($_POST['home_phone'])) {
            $phone = preg_replace('/[^0-9]/', '', $_POST['home_phone']);
            if (strlen($phone) !== 10) {
                $validation_errors['home_phone'] = "Home phone must be 10 digits";
            }
        }
        
        // Validate email (optional field)
        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $validation_errors['email'] = "Please enter a valid email address";
        }
        
        // If there are validation errors, throw exception
        if (!empty($validation_errors)) {
            $error_messages = implode("\n", array_map(
                function($field, $message) use ($required_fields) {
                    $label = $required_fields[$field] ?? ucfirst(str_replace('_', ' ', $field));
                    return "• {$label}: {$message}";
                },
                array_keys($validation_errors),
                $validation_errors
            ));
            error_log("Validation errors: " . print_r($validation_errors, true));
            throw new Exception("Please correct the following errors:\n\n" . $error_messages);
        }
        
        // Check for duplicate identification number
        $check_dup_query = "SELECT COUNT(*) as count FROM citizens WHERE identification_type = ? AND identification_number = ?";
        $check_stmt = $db->prepare($check_dup_query);
        $id_type = $_POST['identification_type'];
        $id_number = $_POST['identification_number'];
        
        if ($id_type === 'nic') {
            $id_number = strtoupper($id_number);
        }
        
        error_log("Checking duplicate ID: $id_type, $id_number");
        
        $check_stmt->bind_param("ss", $id_type, $id_number);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['count'] > 0) {
            throw new Exception("This identification number already exists in the system.");
        }
        
        // Prepare family data
        $family_address = trim($_POST['family_address']);
        $family_head_nic = ($_POST['identification_type'] === 'nic') ? strtoupper($_POST['identification_number']) : null;
        
        // Insert Family Record
        $family_query = "INSERT INTO families 
                        (family_id, gn_id, original_gn_id, address, family_head_nic, total_members, created_by) 
                        VALUES (?, ?, ?, ?, ?, 1, ?)";
        
        $family_stmt = $db->prepare($family_query);
        if (!$family_stmt) {
            throw new Exception("Failed to prepare family query: " . $db->error);
        }
        
        error_log("Inserting family with ID: $family_id");
        
        $family_stmt->bind_param("sssssi", 
            $family_id, 
            $gn_id, 
            $gn_id, 
            $family_address,
            $family_head_nic,
            $user_id
        );
        
        if (!$family_stmt->execute()) {
            throw new Exception("Failed to create family record: " . $family_stmt->error);
        }

        // Prepare citizen data
        $identification_type = $_POST['identification_type'];
        $identification_number = ($identification_type === 'nic') ? strtoupper($_POST['identification_number']) : $_POST['identification_number'];
        $full_name = trim($_POST['full_name']);
        $name_with_initials = trim($_POST['name_with_initials']);
        $gender = $_POST['gender'];
        $date_of_birth = $_POST['date_of_birth'];
        $ethnicity = !empty($_POST['ethnicity']) ? $_POST['ethnicity'] : null;
        $religion = !empty($_POST['religion']) ? $_POST['religion'] : null;
        
        // Clean phone numbers
        $mobile_phone = !empty($_POST['mobile_phone']) ? preg_replace('/[^0-9]/', '', $_POST['mobile_phone']) : null;
        $home_phone = !empty($_POST['home_phone']) ? preg_replace('/[^0-9]/', '', $_POST['home_phone']) : null;
        
        $email = !empty($_POST['email']) ? trim($_POST['email']) : null;
        $member_address = !empty($_POST['member_address']) ? trim($_POST['member_address']) : null;
        $marital_status = !empty($_POST['marital_status']) ? $_POST['marital_status'] : null;
        $is_alive = 1;
        $relation_to_head = 'Self';

        error_log("Inserting citizen: $full_name, ID: $identification_number");

        // Insert citizen record
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
            throw new Exception("Failed to add family member: " . $citizen_stmt->error);
        }
        
        // Log the action
        logAction($user_id, 'create', 'families', $family_id, null, json_encode([
            'family_id' => $family_id,
            'head_name' => $full_name,
            'identification' => $identification_number
        ]));
        
        // Commit transaction
        $db->commit();  
        
        error_log("Family registered successfully: $family_id");
        
        $success = "Family registered successfully! Family ID: <strong>" . htmlspecialchars($family_id) . "</strong>";
        
        // Clear form data for new entry
        $form_data = [];
        
    } catch (Exception $e) {
        // Rollback on error if transaction was started
        if ($transaction_started) {
            $db->rollback();
        }
        $error = $e->getMessage();
        error_log("Add Family Error: " . $e->getMessage());
    }
}
    
    // Generate preview family ID for display (always fresh)
    $preview_family_id = generateFamilyId($gn_id);

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Add Family System Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}

// Function to safely output form data
function getFormValue($field, $default = '') {
    global $form_data;
    return isset($form_data[$field]) ? htmlspecialchars($form_data[$field]) : $default;
}

// Function to check if option is selected
function isSelected($field, $value) {
    global $form_data;
    return (isset($form_data[$field]) && $form_data[$field] == $value) ? 'selected' : '';
}

// Function to check if checkbox is checked
function isChecked($field, $value) {
    global $form_data;
    return (isset($form_data[$field]) && $form_data[$field] == $value) ? 'checked' : '';
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
                    <i class="bi bi-people-fill me-2"></i>
                    Add New Family
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="list_families.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Families
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
                    <p class="mb-0">The family has been registered successfully.</p>
                    <div class="mt-3">
                        <a href="add_family.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle"></i> Add Another Family
                        </a>
                        <a href="list_families.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-list"></i> View Family List
                        </a>
                        <?php if ($family_id): ?>
                        <a href="view_family.php?id=<?php echo $family_id; ?>" class="btn btn-sm btn-info">
                            <i class="bi bi-eye"></i> View Family Details
                        </a>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Family ID Preview -->
            <div class="card mb-4">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-card-heading"></i> Family ID Preview</h5>
                    <p class="card-text display-4 text-primary font-monospace"><?php echo htmlspecialchars($preview_family_id); ?></p>
                    <small class="text-muted">This 14-digit ID will be assigned upon successful registration</small>
                </div>
            </div>
            
            <!-- Main Form -->
            <form method="POST" action="" id="familyForm" class="needs-validation" novalidate>
                
                <!-- Family Details Card -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-house-door"></i> Family Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">GN Division</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($gn_details['GN'] ?? 'Not Available'); ?>" readonly>
                                <small class="text-muted">
                                    GN ID: <?php echo htmlspecialchars($gn_id); ?> | 
                                    Division: <?php echo htmlspecialchars($gn_details['Division_Name'] ?? 'N/A'); ?>
                                </small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">District</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($gn_details['District_Name'] ?? 'N/A'); ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label required">Family Address</label>
                            <textarea class="form-control" name="family_address" rows="3" required 
                                      minlength="10" maxlength="255"><?php echo getFormValue('family_address'); ?></textarea>
                            <div class="invalid-feedback">
                                Please provide a family address (at least 10 characters).
                            </div>
                            <small class="text-muted">Full address where the family resides</small>
                        </div>
                    </div>
                </div>
                
                <!-- Family Member Card -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-person"></i> Family Member (Family Head)</h5>
                    </div>
                    <div class="card-body">
                        <!-- Identification Section -->
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <label class="form-label required">Identification Type</label>
                                <select class="form-select" name="identification_type" required>
                                    <option value="">Select Type</option>
                                    <option value="nic" <?php echo isSelected('identification_type', 'nic'); ?>>NIC</option>
                                    <option value="passport" <?php echo isSelected('identification_type', 'passport'); ?>>Passport</option>
                                    <option value="postal" <?php echo isSelected('identification_type', 'postal'); ?>>Postal ID</option>
                                    <option value="other" <?php echo isSelected('identification_type', 'other'); ?>>Other</option>
                                </select>
                                <div class="invalid-feedback">Please select identification type</div>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label required">Identification Number</label>
                                <input type="text" class="form-control" name="identification_number" 
                                       value="<?php echo getFormValue('identification_number'); ?>" required
                                       pattern=".{5,20}" title="5-20 characters required">
                                <div class="invalid-feedback">Please enter identification number (5-20 characters)</div>
                                <small class="text-muted" id="nic_hint" style="display: none;">Format: 9 digits with V/X or 12 digits</small>
                            </div>
                        </div>
                        
                        <!-- Name Section -->
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Full Name</label>
                                <input type="text" class="form-control" name="full_name" 
                                       value="<?php echo getFormValue('full_name'); ?>" required
                                       minlength="3" maxlength="100">
                                <div class="invalid-feedback">Please enter full name (3-100 characters)</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Name with Initials</label>
                                <input type="text" class="form-control" name="name_with_initials" 
                                       value="<?php echo getFormValue('name_with_initials'); ?>" 
                                       placeholder="e.g., A.B. Perera" required
                                       minlength="2" maxlength="50">
                                <div class="invalid-feedback">Please enter name with initials (2-50 characters)</div>
                            </div>
                        </div>
                        
                        <!-- Personal Details -->
                        <div class="row mb-4">
                            <div class="col-md-3 mb-3">
                                <label class="form-label required">Gender</label>
                                <select class="form-select" name="gender" required>
                                    <option value="">Select</option>
                                    <option value="male" <?php echo isSelected('gender', 'male'); ?>>Male</option>
                                    <option value="female" <?php echo isSelected('gender', 'female'); ?>>Female</option>
                                    <option value="other" <?php echo isSelected('gender', 'other'); ?>>Other</option>
                                </select>
                                <div class="invalid-feedback">Please select gender</div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label required">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" 
                                       value="<?php echo getFormValue('date_of_birth'); ?>"
                                       max="<?php echo date('Y-m-d'); ?>" required>
                                <div class="invalid-feedback">Please enter date of birth</div>
                                <small class="text-muted">Age: <span id="age_display">-</span> years</small>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Ethnicity</label>
                                <select class="form-select" name="ethnicity">
                                    <option value="">Select</option>
                                    <option value="Sinhala" <?php echo isSelected('ethnicity', 'Sinhala'); ?>>Sinhala</option>
                                    <option value="Tamil" <?php echo isSelected('ethnicity', 'Tamil'); ?>>Tamil</option>
                                    <option value="Muslim" <?php echo isSelected('ethnicity', 'Muslim'); ?>>Muslim</option>
                                    <option value="Burgher" <?php echo isSelected('ethnicity', 'Burgher'); ?>>Burgher</option>
                                    <option value="Other" <?php echo isSelected('ethnicity', 'Other'); ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Religion</label>
                                <select class="form-select" name="religion">
                                    <option value="">Select</option>
                                    <option value="Buddhist" <?php echo isSelected('religion', 'Buddhist'); ?>>Buddhist</option>
                                    <option value="Hindu" <?php echo isSelected('religion', 'Hindu'); ?>>Hindu</option>
                                    <option value="Islam" <?php echo isSelected('religion', 'Islam'); ?>>Islam</option>
                                    <option value="Christian" <?php echo isSelected('religion', 'Christian'); ?>>Christian</option>
                                    <option value="Other" <?php echo isSelected('religion', 'Other'); ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Contact Information -->
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mobile Phone</label>
                                <input type="tel" class="form-control" name="mobile_phone"
                                       value="<?php echo getFormValue('mobile_phone'); ?>"
                                       placeholder="e.g., 0712345678" maxlength="10"
                                       pattern="[0-9]{10}" title="10 digits only">
                                <div class="invalid-feedback">Please enter 10-digit mobile number</div>
                                <small class="text-muted">10 digits only (without +94)</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Home Phone</label>
                                <input type="tel" class="form-control" name="home_phone"
                                       value="<?php echo getFormValue('home_phone'); ?>"
                                       placeholder="e.g., 0112345678" maxlength="10"
                                       pattern="[0-9]{10}" title="10 digits only">
                                <div class="invalid-feedback">Please enter 10-digit home number</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email"
                                       value="<?php echo getFormValue('email'); ?>"
                                       maxlength="100">
                                <div class="invalid-feedback">Please enter valid email address</div>
                            </div>
                        </div>
                        
                        <!-- Additional Information -->
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Marital Status</label>
                                <select class="form-select" name="marital_status">
                                    <option value="">Select</option>
                                    <option value="single" <?php echo isSelected('marital_status', 'single'); ?>>Single</option>
                                    <option value="married" <?php echo isSelected('marital_status', 'married'); ?>>Married</option>
                                    <option value="divorced" <?php echo isSelected('marital_status', 'divorced'); ?>>Divorced</option>
                                    <option value="widowed" <?php echo isSelected('marital_status', 'widowed'); ?>>Widowed</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Personal Address -->
                        <div class="mb-3">
                            <label class="form-label">Personal Address</label>
                            <textarea class="form-control" name="member_address" rows="2"
                                      maxlength="255"><?php echo getFormValue('member_address'); ?></textarea>
                            <small class="text-muted">If different from family address</small>
                        </div>
                        
                        <!-- Age Confirmation -->
                        <div class="alert alert-warning mt-3" id="minor_warning" style="display: none;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="confirm_minor" value="yes" 
                                       id="confirm_minor" <?php echo isChecked('confirm_minor', 'yes'); ?>>
                                <label class="form-check-label" for="confirm_minor">
                                    <strong>Confirm:</strong> The family head is under 18 years old. I understand and wish to proceed.
                                </label>
                                <div class="invalid-feedback">Please confirm to proceed with minor family head</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Buttons -->
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4 mb-5">
                    <button type="reset" class="btn btn-secondary me-md-2" id="resetBtn">
                        <i class="bi bi-x-circle"></i> Clear Form
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="bi bi-save"></i> Register Family
                    </button>
                </div>
            </form>
        </main>
    </div>
</div>

<style>
    .required::after {
        content: " *";
        color: #dc3545;
    }
    .form-control:read-only {
        background-color: #e9ecef;
        opacity: 1;
    }
    #minor_warning {
        border-left: 4px solid #ffc107;
    }
    .is-valid {
        border-color: #198754 !important;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e") !important;
    }
    .is-invalid {
        border-color: #dc3545 !important;
    }
    @media (max-width: 768px) {
        .display-4 {
            font-size: 2rem;
        }
        .card-body {
            padding: 1rem;
        }
    }
</style>

<script src="../../../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('familyForm');
        const submitBtn = document.getElementById('submitBtn');
        const resetBtn = document.getElementById('resetBtn');
        const minorWarning = document.getElementById('minor_warning');
        const confirmMinorCheckbox = document.getElementById('confirm_minor');
        const ageDisplay = document.getElementById('age_display');
        const nicHint = document.getElementById('nic_hint');
        const idTypeSelect = document.querySelector('select[name="identification_type"]');
        const idNumberInput = document.querySelector('input[name="identification_number"]');
        
        // Calculate and display age
        const dobInput = document.querySelector('input[name="date_of_birth"]');
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
                
                ageDisplay.textContent = age;
                
                // Show/hide minor warning
                if (age < 18) {
                    minorWarning.style.display = 'block';
                } else {
                    minorWarning.style.display = 'none';
                    confirmMinorCheckbox.checked = false;
                }
            }
        }
        
        // Show NIC hint when NIC is selected
        idTypeSelect.addEventListener('change', function() {
            if (this.value === 'nic') {
                nicHint.style.display = 'block';
                // Update pattern for NIC
                idNumberInput.pattern = '^[0-9]{9}[VX]$|^[0-9]{12}$';
                idNumberInput.title = '9 digits with V/X or 12 digits';
            } else {
                nicHint.style.display = 'none';
                // Reset pattern for other ID types
                idNumberInput.pattern = '.{5,20}';
                idNumberInput.title = '5-20 characters required';
            }
        });
        
        // Real-time validation for identification number
        idNumberInput.addEventListener('input', function() {
            validateField(this);
        });
        
        // Phone number validation
        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.addEventListener('input', function() {
                // Remove non-digits
                this.value = this.value.replace(/[^0-9]/g, '');
                validateField(this);
            });
        });
        
        // Email validation
        const emailInput = document.querySelector('input[name="email"]');
        emailInput.addEventListener('input', function() {
            validateField(this);
        });
        
        // Helper function to validate a field
        function validateField(field) {
            field.classList.remove('is-valid', 'is-invalid');
            
            if (field.checkValidity()) {
                field.classList.add('is-valid');
            } else if (field.value) {
                field.classList.add('is-invalid');
            }
        }
        
        // Validate all fields on blur
        form.querySelectorAll('input, select, textarea').forEach(element => {
            element.addEventListener('blur', function() {
                validateField(this);
            });
        });
        
        // Form submission handler
form.addEventListener('submit', function(e) {
        
        // Form reset handler
        resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (confirm('Are you sure you want to clear all form data?')) {
                form.reset();
                form.classList.remove('was-validated');
                form.querySelectorAll('.is-valid, .is-invalid').forEach(el => {
                    el.classList.remove('is-valid', 'is-invalid');
                });
                ageDisplay.textContent = '-';
                minorWarning.style.display = 'none';
                nicHint.style.display = 'none';
                confirmMinorCheckbox.checked = false;
                
                // Reset select elements to first option
                form.querySelectorAll('select').forEach(select => {
                    select.selectedIndex = 0;
                });
            }
        });
        
        // Initialize age calculation if date is already filled
        if (dobInput.value) {
            calculateAge();
        }
        
        // Initialize NIC hint if NIC is selected
        if (idTypeSelect.value === 'nic') {
            nicHint.style.display = 'block';
            idNumberInput.pattern = '^[0-9]{9}[VX]$|^[0-9]{12}$';
            idNumberInput.title = '9 digits with V/X or 12 digits';
        }
        
        // Add Bootstrap validation styles
        form.addEventListener('input', function(e) {
            const target = e.target;
            if (target.checkValidity()) {
                target.classList.remove('is-invalid');
                target.classList.add('is-valid');
            } else {
                target.classList.remove('is-valid');
                target.classList.add('is-invalid');
            }
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