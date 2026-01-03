<?php
// users/change_password.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Change Password";
$pageIcon = "bi bi-shield-lock";
$pageDescription = "Change your account password";
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
    $username = $_SESSION['username'] ?? '';
    $user_type = $_SESSION['user_type'] ?? '';
    
    // Initialize variables
    $error = '';
    $success = '';
    $user_data = [];
    
    // Get user data
    $user_query = "SELECT user_id, username, password_hash, email, last_login FROM users WHERE user_id = ?";
    $user_stmt = $db->prepare($user_query);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user_data = $user_result->fetch_assoc();
    
    if (!$user_data) {
        throw new Exception("User not found");
    }
    
    // Check if password change is required (e.g., first login or password expired)
    $password_change_required = false;
    $password_expiry_days = 90; // Password expires after 90 days
    $last_password_change = isset($_SESSION['password_changed_at']) ? 
        $_SESSION['password_changed_at'] : $user_data['last_login'];
    
    if ($last_password_change) {
        $last_change = new DateTime($last_password_change);
        $now = new DateTime();
        $days_since_change = $now->diff($last_change)->days;
        
        if ($days_since_change > $password_expiry_days) {
            $password_change_required = true;
            $error = "Your password has expired. Please change your password to continue.";
        }
    }
    
    // Process password change form
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Validate current password
            if (empty($current_password)) {
                throw new Exception("Current password is required");
            }
            
            // Verify current password
            if (!password_verify($current_password, $user_data['password_hash'])) {
                // Log failed attempt
                $audit_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, ip_address, user_agent) 
                               VALUES (?, 'failed_password_change', 'users', ?, ?, ?)";
                $audit_stmt = $db->prepare($audit_query);
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $audit_stmt->bind_param("iisss", $user_id, $user_id, $ip, $user_agent);
                $audit_stmt->execute();
                
                throw new Exception("Current password is incorrect");
            }
            
            // Validate new password
            if (empty($new_password)) {
                throw new Exception("New password is required");
            }
            
            // Check password strength
            $strength = checkPasswordStrength($new_password);
            if ($strength < 3) {
                throw new Exception("Password is too weak. Use at least 8 characters with uppercase, lowercase, and numbers");
            }
            
            // Check password history (prevent reuse of last 3 passwords)
            $history_check = checkPasswordHistory($db, $user_id, $new_password);
            if ($history_check) {
                throw new Exception("Cannot use a previously used password. Please choose a new password.");
            }
            
            // Confirm password match
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match");
            }
            
            // Check if new password is same as current
            if (password_verify($new_password, $user_data['password_hash'])) {
                throw new Exception("New password cannot be the same as current password");
            }
            
            // Hash new password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Start transaction
            $db->begin_transaction();
            
            // Update password in users table
            $update_query = "UPDATE users SET 
                            password_hash = ?, 
                            updated_at = CURRENT_TIMESTAMP,
                            last_password_change = CURRENT_TIMESTAMP
                            WHERE user_id = ?";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bind_param("si", $new_password_hash, $user_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update password: " . $update_stmt->error);
            }
            
            // Save password to history
            $history_query = "INSERT INTO password_history (user_id, password_hash, changed_at) 
                             VALUES (?, ?, CURRENT_TIMESTAMP)";
            $history_stmt = $db->prepare($history_query);
            $history_stmt->bind_param("is", $user_id, $new_password_hash);
            $history_stmt->execute();
            
            // Keep only last 3 passwords in history
            $cleanup_query = "DELETE FROM password_history 
                             WHERE user_id = ? 
                             AND history_id NOT IN (
                                 SELECT history_id FROM (
                                     SELECT history_id 
                                     FROM password_history 
                                     WHERE user_id = ? 
                                     ORDER BY changed_at DESC 
                                     LIMIT 3
                                 ) AS recent
                             )";
            $cleanup_stmt = $db->prepare($cleanup_query);
            $cleanup_stmt->bind_param("ii", $user_id, $user_id);
            $cleanup_stmt->execute();
            
            // Log successful password change
            $audit_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, ip_address, user_agent) 
                           VALUES (?, 'password_changed', 'users', ?, ?, ?)";
            $audit_stmt = $db->prepare($audit_query);
            $audit_stmt->bind_param("iisss", $user_id, $user_id, $ip, $user_agent);
            $audit_stmt->execute();
            
            // Commit transaction
            $db->commit();
            
            // Update session variable
            $_SESSION['password_changed_at'] = date('Y-m-d H:i:s');
            
            // Send email notification if email exists
            if (!empty($user_data['email'])) {
                sendPasswordChangeEmail($user_data['email'], $username);
            }
            
            $success = "Password changed successfully!";
            
            // Redirect to profile page after 3 seconds
            header("refresh:3;url=my_profile.php");
            
        } catch (Exception $e) {
            // Rollback on error
            if (isset($db) && $db) {
                $db->rollback();
            }
            $error = "Error: " . $e->getMessage();
            error_log("Password Change Error: " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Password Change System Error: " . $e->getMessage());
}

// Function to check password strength
function checkPasswordStrength($password) {
    $strength = 0;
    
    // Length check
    if (strlen($password) >= 8) $strength++;
    
    // Contains lowercase
    if (preg_match('/[a-z]/', $password)) $strength++;
    
    // Contains uppercase
    if (preg_match('/[A-Z]/', $password)) $strength++;
    
    // Contains numbers
    if (preg_match('/[0-9]/', $password)) $strength++;
    
    // Contains special characters
    if (preg_match('/[^A-Za-z0-9]/', $password)) $strength++;
    
    return $strength;
}

// Function to check password history
function checkPasswordHistory($db, $user_id, $new_password) {
    // Check if password_history table exists
    $table_check = $db->query("SHOW TABLES LIKE 'password_history'");
    if ($table_check->num_rows == 0) {
        // Create password_history table if it doesn't exist
        $create_table = "CREATE TABLE password_history (
            history_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        )";
        $db->query($create_table);
        return false;
    }
    
    // Get last 3 passwords from history
    $history_query = "SELECT password_hash FROM password_history 
                     WHERE user_id = ? 
                     ORDER BY changed_at DESC 
                     LIMIT 3";
    $history_stmt = $db->prepare($history_query);
    $history_stmt->bind_param("i", $user_id);
    $history_stmt->execute();
    $result = $history_stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if (password_verify($new_password, $row['password_hash'])) {
            return true;
        }
    }
    
    return false;
}

// Function to send email notification
function sendPasswordChangeEmail($email, $username) {
    try {
        $to = $email;
        $subject = "Password Changed Successfully - FPMS";
        $message = "
        <html>
        <head>
            <title>Password Changed</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f8f9fa; }
                .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #6c757d; }
                .alert { background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; border-radius: 4px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Family Profile Management System</h2>
                </div>
                <div class='content'>
                    <h3>Password Changed Successfully</h3>
                    <p>Hello <strong>$username</strong>,</p>
                    
                    <div class='alert'>
                        <p>Your password was successfully changed on " . date('F j, Y \a\t g:i A') . ".</p>
                    </div>
                    
                    <p>If you did not make this change, please contact your system administrator immediately.</p>
                    
                    <p><strong>Security Tips:</strong></p>
                    <ul>
                        <li>Never share your password with anyone</li>
                        <li>Use a unique password for this system</li>
                        <li>Change your password regularly</li>
                        <li>Log out after each session</li>
                    </ul>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>© " . date('Y') . " Family Profile Management System</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: FPMS System <noreply@fpms.gov.lk>" . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        // For production, you would use a proper email library
        // For now, we'll just log it
        error_log("Password change email would be sent to: $email");
        
        // Uncomment to actually send email (configure your server first)
        // mail($to, $subject, $message, $headers);
        
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
    }
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
                    <i class="bi bi-shield-lock me-2"></i>
                    Change Password
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="my_profile.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Profile
                    </a>
                </div>
            </div>
            
            <!-- Password Change Required Warning -->
            <?php if ($password_change_required): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <h5><i class="bi bi-exclamation-triangle-fill me-2"></i> Password Expired</h5>
                    <p class="mb-0">Your password has expired. You must change your password to continue using the system.</p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Alert Messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <h5><i class="bi bi-exclamation-triangle me-2"></i> Error</h5>
                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <h5><i class="bi bi-check-circle me-2"></i> Success!</h5>
                    <p><?php echo $success; ?></p>
                    <p class="mb-0">You will be redirected to your profile page in a few seconds...</p>
                    <div class="mt-3">
                        <a href="my_profile.php" class="btn btn-sm btn-outline-primary">
                            Go to Profile Now
                        </a>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <!-- Password Change Card -->
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-shield-check me-2"></i>
                                Change Your Password
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" id="passwordForm" class="needs-validation" novalidate>
                                
                                <!-- User Info -->
                                <div class="mb-4 p-3 bg-light rounded">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <i class="bi bi-person-circle fs-1 text-primary"></i>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($username); ?></h6>
                                            <small class="text-muted">User ID: <?php echo $user_id; ?> | Type: <?php echo ucfirst($user_type); ?></small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Current Password -->
                                <div class="mb-4">
                                    <label class="form-label required">Current Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" 
                                               name="current_password" id="current_password" 
                                               required minlength="1">
                                        <button class="btn btn-outline-secondary" type="button" 
                                                id="toggleCurrentPassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback">
                                        Please enter your current password
                                    </div>
                                </div>
                                
                                <!-- New Password -->
                                <div class="mb-4">
                                    <label class="form-label required">New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" 
                                               name="new_password" id="new_password" 
                                               required minlength="8"
                                               pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d@$!%*?&]{8,}$"
                                               title="At least 8 characters with uppercase, lowercase and numbers">
                                        <button class="btn btn-outline-secondary" type="button" 
                                                id="toggleNewPassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback">
                                        Password must be at least 8 characters with uppercase, lowercase and numbers
                                    </div>
                                    
                                    <!-- Password Strength Meter -->
                                    <div class="mt-2">
                                        <div class="progress" style="height: 5px;">
                                            <div class="progress-bar" id="passwordStrengthBar" 
                                                 role="progressbar" style="width: 0%"></div>
                                        </div>
                                        <small id="passwordStrengthText" class="text-muted"></small>
                                    </div>
                                    
                                    <!-- Password Requirements -->
                                    <div class="mt-2 small">
                                        <strong>Password Requirements:</strong>
                                        <ul class="mb-0 ps-3">
                                            <li id="reqLength" class="text-muted">At least 8 characters</li>
                                            <li id="reqLower" class="text-muted">One lowercase letter</li>
                                            <li id="reqUpper" class="text-muted">One uppercase letter</li>
                                            <li id="reqNumber" class="text-muted">One number</li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <!-- Confirm New Password -->
                                <div class="mb-4">
                                    <label class="form-label required">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" 
                                               name="confirm_password" id="confirm_password" 
                                               required>
                                        <button class="btn btn-outline-secondary" type="button" 
                                                id="toggleConfirmPassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback" id="confirmPasswordFeedback">
                                        Passwords do not match
                                    </div>
                                    <div class="valid-feedback" id="passwordMatchFeedback" style="display: none;">
                                        <i class="bi bi-check-circle"></i> Passwords match
                                    </div>
                                </div>
                                
                                <!-- Security Information -->
                                <div class="alert alert-info">
                                    <h6><i class="bi bi-info-circle me-2"></i> Security Information</h6>
                                    <ul class="mb-0 small">
                                        <li>Your new password will be valid for 90 days</li>
                                        <li>You cannot reuse your last 3 passwords</li>
                                        <li>An email notification will be sent to your registered email</li>
                                        <li>All password changes are logged for security</li>
                                    </ul>
                                </div>
                                
                                <!-- Form Buttons -->
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                        <i class="bi bi-shield-check me-2"></i>
                                        Change Password
                                    </button>
                                    <a href="my_profile.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle me-2"></i>
                                        Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                        <div class="card-footer text-muted small">
                            <div class="row">
                                <div class="col-md-6">
                                    <i class="bi bi-clock-history me-1"></i>
                                    Last login: <?php echo $user_data['last_login'] ? date('Y-m-d H:i', strtotime($user_data['last_login'])) : 'Never'; ?>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <i class="bi bi-shield-fill-check me-1"></i>
                                    Session secured
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Password Tips -->
                    <div class="card mt-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">
                                <i class="bi bi-lightbulb me-2"></i>
                                Password Security Tips
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex">
                                        <div class="flex-shrink-0">
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        </div>
                                        <div class="flex-grow-1 ms-2">
                                            <small><strong>Do use a passphrase</strong><br>
                                            Combine multiple words for stronger security</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex">
                                        <div class="flex-shrink-0">
                                            <i class="bi bi-x-circle-fill text-danger"></i>
                                        </div>
                                        <div class="flex-grow-1 ms-2">
                                            <small><strong>Don't use personal info</strong><br>
                                            Avoid names, birthdays, or common words</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex">
                                        <div class="flex-shrink-0">
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        </div>
                                        <div class="flex-grow-1 ms-2">
                                            <small><strong>Do use unique passwords</strong><br>
                                            Different password for each account</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex">
                                        <div class="flex-shrink-0">
                                            <i class="bi bi-x-circle-fill text-danger"></i>
                                        </div>
                                        <div class="flex-grow-1 ms-2">
                                            <small><strong>Don't share your password</strong><br>
                                            Keep it confidential at all times</small>
                                        </div>
                                    </div>
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
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
    .progress {
        background-color: #e9ecef;
    }
    .progress-bar {
        transition: width 0.3s ease;
    }
    .valid-feedback {
        display: block !important;
    }
    .input-group .btn {
        border-color: #ced4da;
    }
    .btn-lg {
        padding: 0.75rem 1.5rem;
        font-size: 1.1rem;
    }
    .bi-check-circle-fill.text-success {
        font-size: 1.1em;
    }
    .bi-x-circle-fill.text-danger {
        font-size: 1.1em;
    }
</style>

<script src="../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('passwordForm');
        const currentPassword = document.getElementById('current_password');
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submitBtn');
        
        // Password strength elements
        const strengthBar = document.getElementById('passwordStrengthBar');
        const strengthText = document.getElementById('passwordStrengthText');
        const reqLength = document.getElementById('reqLength');
        const reqLower = document.getElementById('reqLower');
        const reqUpper = document.getElementById('reqUpper');
        const reqNumber = document.getElementById('reqNumber');
        
        // Toggle password visibility
        document.getElementById('toggleCurrentPassword').addEventListener('click', function() {
            togglePasswordVisibility(currentPassword, this);
        });
        
        document.getElementById('toggleNewPassword').addEventListener('click', function() {
            togglePasswordVisibility(newPassword, this);
        });
        
        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            togglePasswordVisibility(confirmPassword, this);
        });
        
        function togglePasswordVisibility(input, button) {
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            button.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        }
        
        // Check password strength in real-time
        newPassword.addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
        });
        
        // Check password match in real-time
        confirmPassword.addEventListener('input', checkPasswordMatch);
        
        function checkPasswordStrength(password) {
            let strength = 0;
            const requirements = {
                length: false,
                lower: false,
                upper: false,
                number: false
            };
            
            // Length check
            if (password.length >= 8) {
                strength += 25;
                requirements.length = true;
                reqLength.classList.remove('text-muted');
                reqLength.classList.add('text-success');
            } else {
                reqLength.classList.remove('text-success');
                reqLength.classList.add('text-muted');
            }
            
            // Lowercase check
            if (/[a-z]/.test(password)) {
                strength += 25;
                requirements.lower = true;
                reqLower.classList.remove('text-muted');
                reqLower.classList.add('text-success');
            } else {
                reqLower.classList.remove('text-success');
                reqLower.classList.add('text-muted');
            }
            
            // Uppercase check
            if (/[A-Z]/.test(password)) {
                strength += 25;
                requirements.upper = true;
                reqUpper.classList.remove('text-muted');
                reqUpper.classList.add('text-success');
            } else {
                reqUpper.classList.remove('text-success');
                reqUpper.classList.add('text-muted');
            }
            
            // Number check
            if (/[0-9]/.test(password)) {
                strength += 25;
                requirements.number = true;
                reqNumber.classList.remove('text-muted');
                reqNumber.classList.add('text-success');
            } else {
                reqNumber.classList.remove('text-success');
                reqNumber.classList.add('text-muted');
            }
            
            // Update progress bar
            strengthBar.style.width = strength + '%';
            
            // Update color and text based on strength
            if (strength < 50) {
                strengthBar.className = 'progress-bar bg-danger';
                strengthText.textContent = 'Weak password';
                strengthText.className = 'text-danger';
            } else if (strength < 75) {
                strengthBar.className = 'progress-bar bg-warning';
                strengthText.textContent = 'Moderate password';
                strengthText.className = 'text-warning';
            } else {
                strengthBar.className = 'progress-bar bg-success';
                strengthText.textContent = 'Strong password';
                strengthText.className = 'text-success';
            }
            
            return strength;
        }
        
        function checkPasswordMatch() {
            const matchFeedback = document.getElementById('passwordMatchFeedback');
            const mismatchFeedback = document.getElementById('confirmPasswordFeedback');
            
            if (newPassword.value && confirmPassword.value) {
                if (newPassword.value === confirmPassword.value) {
                    confirmPassword.classList.remove('is-invalid');
                    confirmPassword.classList.add('is-valid');
                    matchFeedback.style.display = 'block';
                    mismatchFeedback.style.display = 'none';
                    return true;
                } else {
                    confirmPassword.classList.remove('is-valid');
                    confirmPassword.classList.add('is-invalid');
                    matchFeedback.style.display = 'none';
                    mismatchFeedback.style.display = 'block';
                    return false;
                }
            }
            return false;
        }
        
        // Form validation
        form.addEventListener('submit', function(e) {
            let isValid = true;
            
            // Check current password
            if (!currentPassword.value.trim()) {
                currentPassword.classList.add('is-invalid');
                isValid = false;
            } else {
                currentPassword.classList.remove('is-invalid');
            }
            
            // Check new password strength
            const strength = checkPasswordStrength(newPassword.value);
            if (strength < 75) {
                newPassword.classList.add('is-invalid');
                isValid = false;
            } else {
                newPassword.classList.remove('is-invalid');
            }
            
            // Check password match
            if (!checkPasswordMatch()) {
                isValid = false;
            }
            
            // Check if new password is different from current
            if (currentPassword.value && newPassword.value && 
                currentPassword.value === newPassword.value) {
                newPassword.classList.add('is-invalid');
                newPassword.setCustomValidity('New password cannot be same as current password');
                isValid = false;
            } else {
                newPassword.setCustomValidity('');
            }
            
            if (!isValid) {
                e.preventDefault();
                e.stopPropagation();
                
                // Show all validation messages
                form.classList.add('was-validated');
                
                // Scroll to first error
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstInvalid.focus();
                }
            } else {
                // Disable submit button to prevent double submission
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-arrow-repeat me-2"></i> Changing Password...';
            }
        });
        
        // Real-time validation
        [currentPassword, newPassword, confirmPassword].forEach(input => {
            input.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.classList.remove('is-invalid');
                }
            });
        });
        
        // Password generator suggestion (optional)
        const generatePasswordBtn = document.createElement('button');
        generatePasswordBtn.type = 'button';
        generatePasswordBtn.className = 'btn btn-sm btn-outline-info mt-2';
        generatePasswordBtn.innerHTML = '<i class="bi bi-magic me-1"></i> Suggest Strong Password';
        generatePasswordBtn.onclick = function() {
            const suggested = generateStrongPassword();
            newPassword.value = suggested;
            confirmPassword.value = suggested;
            checkPasswordStrength(suggested);
            checkPasswordMatch();
        };
        
        // Insert after new password input
        newPassword.parentNode.parentNode.appendChild(generatePasswordBtn);
        
        function generateStrongPassword() {
            const chars = {
                lower: 'abcdefghijklmnopqrstuvwxyz',
                upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
                numbers: '0123456789',
                special: '!@#$%^&*'
            };
            
            let password = '';
            
            // Ensure at least one of each required type
            password += chars.lower.charAt(Math.floor(Math.random() * chars.lower.length));
            password += chars.upper.charAt(Math.floor(Math.random() * chars.upper.length));
            password += chars.numbers.charAt(Math.floor(Math.random() * chars.numbers.length));
            
            // Fill remaining to make 12 characters
            const allChars = chars.lower + chars.upper + chars.numbers + chars.special;
            for (let i = password.length; i < 12; i++) {
                password += allChars.charAt(Math.floor(Math.random() * allChars.length));
            }
            
            // Shuffle the password
            return password.split('').sort(() => 0.5 - Math.random()).join('');
        }
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