<?php
// users/my_profile.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "My Profile";
$pageIcon = "bi bi-person-circle";
$pageDescription = "View and update your profile information";
$bodyClass = "bg-light";

try {
    require_once '../config.php';
    require_once '../classes/Auth.php';
    
    // Check if session is already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in
    if (!$auth->isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }

    // Get database connection
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $user_type = $_SESSION['user_type'] ?? '';
    $gn_id = $_SESSION['office_code'] ?? '';
    $username = $_SESSION['username'] ?? '';

    $error = '';
    $success = '';
    $user_data = [];
    
    // Get user data
    $user_query = "SELECT * FROM users WHERE user_id = ?";
    $user_stmt = $db->prepare($user_query);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user_data = $user_result->fetch_assoc();
    
    if (!$user_data) {
        throw new Exception("User profile not found");
    }
    
    // Get GN details for GN users
    $gn_details = [];
    if ($user_type === 'gn') {
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
            }
        } catch (Exception $e) {
            // Silent fail - GN details are optional
        }
    }
    
    // Get login history
    $login_history = [];
    $login_query = "SELECT * FROM audit_logs 
                   WHERE user_id = ? AND action_type = 'login' 
                   ORDER BY created_at DESC 
                   LIMIT 10";
    $login_stmt = $db->prepare($login_query);
    $login_stmt->bind_param("i", $user_id);
    $login_stmt->execute();
    $login_result = $login_stmt->get_result();
    $login_history = $login_result->fetch_all(MYSQLI_ASSOC);
    
    // Process profile update form
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        try {
            $email = !empty($_POST['email']) ? trim($_POST['email']) : null;
            $phone = !empty($_POST['phone']) ? preg_replace('/[^0-9]/', '', $_POST['phone']) : null;
            
            // Validate email if provided
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Please enter a valid email address");
            }
            
            // Validate phone if provided
            if ($phone && strlen($phone) !== 10) {
                throw new Exception("Phone number must be 10 digits");
            }
            
            // Update user profile
            $update_query = "UPDATE users SET 
                            email = ?, 
                            phone = ?, 
                            updated_at = CURRENT_TIMESTAMP 
                            WHERE user_id = ?";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bind_param("ssi", $email, $phone, $user_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update profile: " . $update_stmt->error);
            }
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id) 
                           VALUES (?, 'update_profile', 'users', ?)";
            $audit_stmt = $db->prepare($audit_query);
            $audit_stmt->bind_param("ii", $user_id, $user_id);
            $audit_stmt->execute();
            
            // Refresh user data
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            
            $success = "Profile updated successfully!";
            
        } catch (Exception $e) {
            $error = "Update Error: " . $e->getMessage();
            error_log("Profile Update Error: " . $e->getMessage());
        }
    }
    
    // Process password change form
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        try {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Validate current password
            if (!password_verify($current_password, $user_data['password_hash'])) {
                throw new Exception("Current password is incorrect");
            }
            
            // Validate new password
            if (strlen($new_password) < 8) {
                throw new Exception("New password must be at least 8 characters long");
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match");
            }
            
            // Check if new password is same as old password
            if (password_verify($new_password, $user_data['password_hash'])) {
                throw new Exception("New password cannot be the same as current password");
            }
            
            // Hash new password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $password_query = "UPDATE users SET 
                              password_hash = ?, 
                              updated_at = CURRENT_TIMESTAMP 
                              WHERE user_id = ?";
            
            $password_stmt = $db->prepare($password_query);
            $password_stmt->bind_param("si", $new_password_hash, $user_id);
            
            if (!$password_stmt->execute()) {
                throw new Exception("Failed to update password: " . $password_stmt->error);
            }
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id) 
                           VALUES (?, 'change_password', 'users', ?)";
            $audit_stmt = $db->prepare($audit_query);
            $audit_stmt->bind_param("ii", $user_id, $user_id);
            $audit_stmt->execute();
            
            $success = "Password changed successfully!";
            
        } catch (Exception $e) {
            $error = "Password Change Error: " . $e->getMessage();
            error_log("Password Change Error: " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Profile System Error: " . $e->getMessage());
}

// Function to get user type display name
function getUserTypeDisplay($type) {
    $types = [
        'moha' => 'MOHA Administrator',
        'district' => 'District Officer',
        'division' => 'Divisional Officer',
        'gn' => 'GN Officer'
    ];
    return $types[$type] ?? ucfirst($type);
}

// Function to get status badge
function getStatusBadge($is_active) {
    return $is_active 
        ? '<span class="badge bg-success">Active</span>' 
        : '<span class="badge bg-danger">Inactive</span>';
}
?>

<?php require_once '../includes/header.php'; ?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../includes/sidebar.php'; ?>
        </div>
        
        <!-- Main Content -->
        <main class="">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-person-circle me-2"></i>
                    My Profile
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Alert Messages -->
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
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Left Column: Profile Info -->
                <div class="col-lg-8">
                    <!-- Personal Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-person-badge me-2"></i>
                                Personal Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Username</label>
                                    <p class="font-monospace text-primary"><?php echo htmlspecialchars($user_data['username']); ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">User Type</label>
                                    <p><?php echo getUserTypeDisplay($user_data['user_type']); ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Office Code</label>
                                    <p><?php echo htmlspecialchars($user_data['office_code']); ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Office Name</label>
                                    <p><?php echo htmlspecialchars($user_data['office_name']); ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Account Status</label>
                                    <p><?php echo getStatusBadge($user_data['is_active']); ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Last Login</label>
                                    <p><?php echo $user_data['last_login'] ? date('Y-m-d H:i:s', strtotime($user_data['last_login'])) : 'Never'; ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Account Created</label>
                                    <p><?php echo date('Y-m-d', strtotime($user_data['created_at'])); ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Last Updated</label>
                                    <p><?php echo date('Y-m-d', strtotime($user_data['updated_at'])); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- GN Details Card (for GN users) -->
                    <?php if ($user_type === 'gn' && !empty($gn_details)): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-geo-alt me-2"></i>
                                    GN Division Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">GN Division</label>
                                        <p><?php echo htmlspecialchars($gn_details['GN'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">GN ID</label>
                                        <p><?php echo htmlspecialchars($gn_details['GN_ID'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Division</label>
                                        <p><?php echo htmlspecialchars($gn_details['Division_Name'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">District</label>
                                        <p><?php echo htmlspecialchars($gn_details['District_Name'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Province</label>
                                        <p><?php echo htmlspecialchars($gn_details['Province_Name'] ?? 'N/A'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Recent Login History -->
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-clock-history me-2"></i>
                                Recent Login History
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($login_history)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>IP Address</th>
                                                <th>User Agent</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($login_history as $log): ?>
                                                <tr>
                                                    <td class="small"><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                                    <td class="small font-monospace"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                                    <td class="small text-truncate" style="max-width: 200px;">
                                                        <?php echo htmlspecialchars($log['user_agent']); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center py-3">No login history available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Update Forms -->
                <div class="col-lg-4">
                    <!-- Update Contact Information -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-envelope me-2"></i>
                                Contact Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" id="profileForm">
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>"
                                           placeholder="your.email@example.com">
                                    <small class="text-muted">For notifications and password recovery</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" name="phone" 
                                           value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>"
                                           placeholder="0712345678"
                                           pattern="[0-9]{10}" title="10 digits only">
                                    <small class="text-muted">10 digits only (without +94)</small>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" name="update_profile" class="btn btn-success">
                                        <i class="bi bi-check-circle me-1"></i> Update Contact Info
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Change Password -->
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0">
                                <i class="bi bi-shield-lock me-2"></i>
                                Change Password
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" id="passwordForm">
                                <div class="mb-3">
                                    <label class="form-label required">Current Password</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label required">New Password</label>
                                    <input type="password" class="form-control" name="new_password" required
                                           minlength="8"
                                           pattern="^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*#?&]{8,}$"
                                           title="At least 8 characters with letters and numbers">
                                    <small class="text-muted">Minimum 8 characters with letters and numbers</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label required">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" name="change_password" class="btn btn-warning">
                                        <i class="bi bi-key me-1"></i> Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="card mt-4">
                        <div class="card-header bg-dark text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-bar-chart me-2"></i>
                                Account Summary
                            </h6>
                        </div>
                        <div class="card-body p-3">
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2">
                                    <i class="bi bi-calendar-check text-primary me-2"></i>
                                    <strong>Account Age:</strong> 
                                    <?php 
                                        $created = new DateTime($user_data['created_at']);
                                        $now = new DateTime();
                                        $interval = $now->diff($created);
                                        echo $interval->format('%a days');
                                    ?>
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-box-arrow-in-right text-success me-2"></i>
                                    <strong>Last Login:</strong> 
                                    <?php echo $user_data['last_login'] ? 
                                        (new DateTime($user_data['last_login']))->format('Y-m-d H:i') : 'Never'; ?>
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-pencil-square text-info me-2"></i>
                                    <strong>Last Updated:</strong> 
                                    <?php echo (new DateTime($user_data['updated_at']))->format('Y-m-d'); ?>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- System Information (Bottom) -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card border-dark">
                        <div class="card-header bg-dark text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-info-circle me-2"></i>
                                System Information
                            </h6>
                        </div>
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-md-3 mb-2">
                                    <small class="text-muted">Session ID:</small><br>
                                    <code class="small"><?php echo session_id(); ?></code>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <small class="text-muted">Browser:</small><br>
                                    <span class="small"><?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'); ?></span>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <small class="text-muted">IP Address:</small><br>
                                    <code class="small"><?php echo $_SERVER['REMOTE_ADDR'] ?? 'Unknown'; ?></code>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <small class="text-muted">Server Time:</small><br>
                                    <span class="small"><?php echo date('Y-m-d H:i:s'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<style>
    .required::after {
        content: " *";
        color: #dc3545;
    }
    .font-monospace {
        font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, monospace;
    }
    .badge {
        font-size: 0.8em;
        padding: 0.4em 0.8em;
    }
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
    .card-header {
        padding: 0.75rem 1.25rem;
    }
    .list-unstyled li {
        padding: 0.25rem 0;
    }
    code {
        color: #d63384;
        background-color: #f8f9fa;
        padding: 0.2rem 0.4rem;
        border-radius: 0.25rem;
    }
    input[type="password"] {
        font-family: monospace;
    }
</style>

<script src="../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Profile form validation
        const profileForm = document.getElementById('profileForm');
        const emailInput = profileForm.querySelector('input[name="email"]');
        const phoneInput = profileForm.querySelector('input[name="phone"]');
        
        profileForm.addEventListener('submit', function(e) {
            let isValid = true;
            
            // Validate email if provided
            if (emailInput.value.trim()) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(emailInput.value)) {
                    emailInput.classList.add('is-invalid');
                    isValid = false;
                } else {
                    emailInput.classList.remove('is-invalid');
                }
            }
            
            // Validate phone if provided
            if (phoneInput.value.trim()) {
                const phoneRegex = /^[0-9]{10}$/;
                if (!phoneRegex.test(phoneInput.value)) {
                    phoneInput.classList.add('is-invalid');
                    isValid = false;
                } else {
                    phoneInput.classList.remove('is-invalid');
                }
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });
        
        // Real-time phone formatting
        phoneInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 10) {
                this.value = this.value.substring(0, 10);
            }
        });
        
        // Password form validation
        const passwordForm = document.getElementById('passwordForm');
        const newPassword = passwordForm.querySelector('input[name="new_password"]');
        const confirmPassword = passwordForm.querySelector('input[name="confirm_password"]');
        
        passwordForm.addEventListener('submit', function(e) {
            // Check if passwords match
            if (newPassword.value !== confirmPassword.value) {
                e.preventDefault();
                confirmPassword.classList.add('is-invalid');
                
                // Show error message
                let errorDiv = confirmPassword.nextElementSibling;
                if (!errorDiv || !errorDiv.classList.contains('invalid-feedback')) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'invalid-feedback';
                    errorDiv.textContent = 'Passwords do not match';
                    confirmPassword.parentNode.appendChild(errorDiv);
                }
            } else {
                confirmPassword.classList.remove('is-invalid');
            }
            
            // Check password strength
            const passwordStrength = checkPasswordStrength(newPassword.value);
            if (passwordStrength < 2) {
                e.preventDefault();
                newPassword.classList.add('is-invalid');
                
                let errorDiv = newPassword.nextElementSibling;
                if (!errorDiv || !errorDiv.classList.contains('invalid-feedback')) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'invalid-feedback';
                    errorDiv.textContent = 'Password too weak. Use at least 8 characters with letters and numbers';
                    newPassword.parentNode.appendChild(errorDiv);
                }
            }
        });
        
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            
            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            return strength;
        }
        
        // Show/hide password functionality
        const passwordFields = document.querySelectorAll('input[type="password"]');
        passwordFields.forEach(function(field) {
            const parent = field.parentNode;
            const toggleButton = document.createElement('button');
            toggleButton.type = 'button';
            toggleButton.className = 'btn btn-sm btn-outline-secondary position-absolute end-0 top-50 translate-middle-y me-2';
            toggleButton.innerHTML = '<i class="bi bi-eye"></i>';
            toggleButton.style.zIndex = '5';
            
            parent.style.position = 'relative';
            field.style.paddingRight = '45px';
            
            toggleButton.addEventListener('click', function() {
                const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
                field.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
            
            parent.appendChild(toggleButton);
        });
    });
</script>

<?php 
// Include footer if exists
$footer_path = '../includes/footer.php';
if (file_exists($footer_path)) {
    include $footer_path;
} else {
    echo '</div></div></body></html>';
}
?>