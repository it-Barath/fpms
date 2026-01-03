<?php
/**
 * setup_admin.php
 * Script to create super admin account
 * This file will self-destruct after successful setup
 */

require_once 'config.php';

// ============================================================================
// SECURITY SETTINGS - CONFIGURE THESE
// ============================================================================

// Set a one-time access token (change this to a strong random string)
// Example: generate with: echo bin2hex(random_bytes(16));

$ACCESS_TOKEN = 'FPMS_SETUP_15127869'; // CHANGE THIS!    

// Maximum attempts before blocking
$MAX_ATTEMPTS = 3;
$attempts_file = 'setup_attempts.txt';

// ============================================================================
// SECURITY CHECKS
// ============================================================================

// Check if token is provided
$token = $_GET['token'] ?? '';
if ($token !== $ACCESS_TOKEN) {
    http_response_code(403);
    showErrorPage('Invalid or missing access token', 
                 'This setup page requires a valid access token.');
    exit();
}

// Check if already set up
$conn = getMainConnection();
$check = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'moha'");
$admin_exists = $check->fetch_assoc()['count'] > 0;

if ($admin_exists) {
    showAlreadyExistsPage();
    selfDestruct();
    exit();
}

// Check setup attempts
if (file_exists($attempts_file)) {
    $attempts = (int)file_get_contents($attempts_file);
    if ($attempts >= $MAX_ATTEMPTS) {
        showErrorPage('Setup Blocked', 
                     'Maximum setup attempts exceeded. Contact system administrator.');
        exit();
    }
} else {
    $attempts = 0;
}

// ============================================================================
// MAIN SETUP LOGIC
// ============================================================================

$success = false;
$error = '';
$username = 'moha_admin';
$default_password = 'Admin@123456';  

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Increment attempt counter
    $attempts++;
    file_put_contents($attempts_file, $attempts);
    
    // Get form data
    $username = trim($_POST['username'] ?? 'moha_admin');
    $password = $_POST['password'] ?? $default_password;
    $confirm = $_POST['confirm_password'] ?? '';
    $email = trim($_POST['email'] ?? 'admin@fpms.lk');
    
    // Validation
    if (empty($username) || empty($password)) {
        $error = 'Username and password are required';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match';
    } else {
        // Validate password policy
        list($valid, $message) = validatePasswordPolicy($password);
        if (!$valid) {
            $error = $message;
        } else {
            // Check username availability
            $stmt = $conn->prepare("SELECT 1 FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Username already exists';
            } else {
                // Create admin account
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("
                    INSERT INTO users (
                        username, password_hash, user_type, office_code, 
                        office_name, email, phone, is_active, created_at
                    ) VALUES (?, ?, 'moha', 'MOHA', 'Ministry of Home Affairs', ?, ?, 1, NOW())
                ");
                
                $phone = '+94 11 2 123 456';
                $stmt->bind_param("ssss", $username, $hashed_password, $email, $phone);
                
                if ($stmt->execute()) {
                    $admin_id = $conn->insert_id;
                    
                    // Create audit log
                    $ip = getUserIP();
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $log_sql = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, ip_address, user_agent) 
                                VALUES (?, 'system', 'users', ?, ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    if ($log_stmt) {
                        $log_stmt->bind_param("isss", $admin_id, $username, $ip, $user_agent);
                        $log_stmt->execute();
                        $log_stmt->close();
                    }
                    
                    $success = true;
                    
                    // Delete attempts file
                    if (file_exists($attempts_file)) {
                        unlink($attempts_file);
                    }
                    
                    // Show success page and self-destruct
                    showSuccessPage($username, $password, $admin_id);
                    selfDestruct();
                    exit();
                } else {
                    $error = 'Database error: ' . $conn->error;
                }
            }
        }
    }
}

// Show setup form
showSetupForm($username, $default_password, $error, $attempts, $MAX_ATTEMPTS);
$conn->close();

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function showErrorPage($title, $message) {
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Setup Error - FPMS</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
        <style>
            body { background: #f8f9fa; padding: 50px 20px; }
            .error-container { max-width: 600px; margin: 0 auto; }
        </style>
    </head>
    <body>
        <div class='container error-container'>
            <div class='card shadow'>
                <div class='card-header bg-danger text-white'>
                    <h4 class='mb-0'><i class='fas fa-exclamation-circle'></i> $title</h4>
                </div>
                <div class='card-body'>
                    <div class='alert alert-danger'>
                        <h5><i class='fas fa-ban'></i> Setup Failed</h5>
                        <p>$message</p>
                    </div>
                    <div class='text-center mt-4'>
                        <a href='login.php' class='btn btn-primary'>
                            <i class='fas fa-sign-in-alt'></i> Go to Login
                        </a>
                        <a href='index.php' class='btn btn-outline-secondary'>
                            <i class='fas fa-home'></i> Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";
}

function showAlreadyExistsPage() {
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Setup Completed - FPMS</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head>
    <body style='padding: 50px; background: #f8f9fa;'>
        <div class='container' style='max-width: 600px;'>
            <div class='card shadow'>
                <div class='card-header bg-success text-white'>
                    <h4 class='mb-0'><i class='fas fa-check-circle'></i> Setup Already Completed</h4>
                </div>
                <div class='card-body'>
                    <div class='alert alert-success'>
                        <h5><i class='fas fa-info-circle'></i> System Ready</h5>
                        <p>The super admin account has already been created.</p>
                        <p>This setup page will now self-destruct for security.</p>
                    </div>
                    
                    <div class='text-center mt-4'>
                        <a href='login.php' class='btn btn-success btn-lg'>
                            <i class='fas fa-sign-in-alt'></i> Go to Login
                        </a>
                    </div>
                </div>
                <div class='card-footer text-center text-muted'>
                    <small>This page will be removed automatically</small>
                </div>
            </div>
        </div>
        <script>
            setTimeout(function() {
                window.location.href = 'login.php';
            }, 5000);
        </script>
    </body>
    </html>";
}

function showSuccessPage($username, $password, $admin_id) {
    $login_url = SITE_URL . 'login.php';
    
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Setup Successful - FPMS</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'>
        <style>
            body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
            .success-container { max-width: 800px; margin: 0 auto; }
            .credentials-box { background: #fff3cd; border: 2px dashed #ffc107; border-radius: 10px; padding: 20px; }
            .auto-destroy { background: #f8d7da; border: 2px solid #dc3545; border-radius: 10px; padding: 15px; }
            .countdown { font-size: 1.5rem; font-weight: bold; color: #dc3545; }
        </style>
    </head>
    <body>
        <div class='container success-container'>
            <div class='card shadow-lg'>
                <div class='card-header text-white text-center' style='background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%);'>
                    <h3><i class='fas fa-user-shield'></i> SETUP COMPLETED SUCCESSFULLY</h3>
                    <p>Family Profile Management System</p>
                </div>
                
                <div class='card-body'>
                    <div class='alert alert-success'>
                        <h4><i class='fas fa-check-circle'></i> Super Admin Account Created</h4>
                        <p class='mb-0'>The system is now ready for use.</p>
                    </div>
                    
                    <div class='row mb-4'>
                        <div class='col-md-6'>
                            <div class='card h-100'>
                                <div class='card-header bg-primary text-white'>
                                    <h6 class='mb-0'><i class='fas fa-key'></i> Login Credentials</h6>
                                </div>
                                <div class='card-body'>
                                    <div class='credentials-box'>
                                        <table class='table table-borderless'>
                                            <tr>
                                                <th width='120'>Username:</th>
                                                <td><strong><code>$username</code></strong></td>
                                            </tr>
                                            <tr>
                                                <th>Password:</th>
                                                <td><strong><code>$password</code></strong></td>
                                            </tr>
                                            <tr>
                                                <th>Account ID:</th>
                                                <td><span class='badge bg-secondary'>$admin_id</span></td>
                                            </tr>
                                            <tr>
                                                <th>Role:</th>
                                                <td><span class='badge bg-danger'>SUPER ADMIN</span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class='col-md-6'>
                            <div class='card h-100'>
                                <div class='card-header bg-warning'>
                                    <h6 class='mb-0'><i class='fas fa-exclamation-triangle'></i> Important Notes</h6>
                                </div>
                                <div class='card-body'>
                                    <ol>
                                        <li><strong>Save these credentials</strong> in a secure location</li>
                                        <li><strong>Change password</strong> immediately after first login</li>
                                        <li>Create backup admin accounts</li>
                                        <li>Enable HTTPS in production</li>
                                        <li>Regularly audit system logs</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class='auto-destroy text-center mb-4'>
                        <h5><i class='fas fa-bomb'></i> SECURITY ALERT</h5>
                        <p class='mb-2'>This setup page will self-destruct in <span class='countdown' id='countdown'>10</span> seconds</p>
                        <p class='mb-0'><small>You will be automatically redirected to login page</small></p>
                    </div>
                    
                    <div class='text-center'>
                        <a href='$login_url' class='btn btn-success btn-lg'>
                            <i class='fas fa-sign-in-alt'></i> Login to System Now
                        </a>
                        <button onclick='copyCredentials()' class='btn btn-info btn-lg'>
                            <i class='fas fa-copy'></i> Copy Credentials
                        </button>
                    </div>
                </div>
                
                <div class='card-footer text-center text-muted'>
                    <small>
                        <i class='fas fa-shield-alt'></i> Secure Setup | 
                        Time: " . date('Y-m-d H:i:s') . " | 
                        IP: " . htmlspecialchars($_SERVER['REMOTE_ADDR']) . "
                    </small>
                </div>
            </div>
        </div>
        
        <script>
            // Countdown timer
            let seconds = 10;
            const countdownElement = document.getElementById('countdown');
            const countdownInterval = setInterval(function() {
                seconds--;
                countdownElement.textContent = seconds;
                
                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    window.location.href = '$login_url';
                }
            }, 1000);
            
            // Copy credentials to clipboard
            function copyCredentials() {
                const text = `Username: $username\\nPassword: $password\\nLogin URL: $login_url`;
                navigator.clipboard.writeText(text).then(function() {
                    alert('Credentials copied to clipboard!');
                });
            }
            
            // Auto-redirect after 10 seconds
            setTimeout(function() {
                window.location.href = '$login_url';
            }, 10000);
        </script>
    </body>
    </html>";
}

function showSetupForm($username, $default_password, $error, $attempts, $max_attempts) {
    $attempts_left = $max_attempts - $attempts;
    
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Super Admin Setup - FPMS</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'>
        <style>
            body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
            .setup-container { max-width: 600px; margin: 0 auto; }
            .password-strength { height: 5px; margin-top: 5px; border-radius: 3px; }
            .strength-0 { background: #dc3545; width: 25%; }
            .strength-1 { background: #dc3545; width: 25%; }
            .strength-2 { background: #ffc107; width: 50%; }
            .strength-3 { background: #28a745; width: 75%; }
            .strength-4 { background: #28a745; width: 100%; }
            .attempts-warning { background: #fff3cd; border-left: 4px solid #ffc107; }
        </style>
    </head>
    <body>
        <div class='container setup-container'>
            <div class='card shadow-lg'>
                <div class='card-header text-white text-center' style='background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%);'>
                    <h3><i class='fas fa-user-shield'></i> Super Admin Setup</h3>
                    <p>Family Profile Management System - Initial Configuration</p>
                </div>
                
                <div class='card-body'>";
                
                if ($attempts > 0) {
                    echo "<div class='attempts-warning p-3 mb-3'>
                        <i class='fas fa-exclamation-triangle'></i>
                        <strong>Attempt $attempts of $max_attempts</strong> - $attempts_left attempts remaining
                    </div>";
                }
                
                if ($error) {
                    echo "<div class='alert alert-danger'>
                        <i class='fas fa-exclamation-circle'></i> " . htmlspecialchars($error) . "
                    </div>";
                }
                
                echo "<form method='POST' id='setupForm'>
                    <div class='mb-3'>
                        <label for='username' class='form-label'>Username</label>
                        <div class='input-group'>
                            <span class='input-group-text'><i class='fas fa-user'></i></span>
                            <input type='text' class='form-control' id='username' name='username' 
                                   value='" . htmlspecialchars($username) . "'
                                   required pattern='[a-zA-Z0-9_]{3,50}'
                                   placeholder='Enter admin username'>
                        </div>
                        <small class='form-text text-muted'>3-50 characters, letters, numbers and underscores only</small>
                    </div>
                    
                    <div class='mb-3'>
                        <label for='email' class='form-label'>Email Address</label>
                        <div class='input-group'>
                            <span class='input-group-text'><i class='fas fa-envelope'></i></span>
                            <input type='email' class='form-control' id='email' name='email' 
                                   value='admin@fpms.lk'
                                   placeholder='admin@fpms.lk'>
                        </div>
                    </div>
                    
                    <div class='mb-3'>
                        <label for='password' class='form-label'>Password</label>
                        <div class='input-group'>
                            <span class='input-group-text'><i class='fas fa-lock'></i></span>
                            <input type='password' class='form-control' id='password' name='password' 
                                   value='" . htmlspecialchars($default_password) . "'
                                   required minlength='8'>
                            <button type='button' class='btn btn-outline-secondary' id='togglePassword'>
                                <i class='fas fa-eye'></i>
                            </button>
                        </div>
                        <div class='password-strength' id='passwordStrength'></div>
                        <small class='form-text text-muted'>Minimum 8 characters with mixed case, numbers, and symbols</small>
                    </div>
                    
                    <div class='mb-4'>
                        <label for='confirm_password' class='form-label'>Confirm Password</label>
                        <div class='input-group'>
                            <span class='input-group-text'><i class='fas fa-lock'></i></span>
                            <input type='password' class='form-control' id='confirm_password' name='confirm_password' 
                                   value='" . htmlspecialchars($default_password) . "'
                                   required>
                        </div>
                        <div class='invalid-feedback' id='passwordError'>Passwords do not match</div>
                    </div>
                    
                    <div class='alert alert-info'>
                        <h6><i class='fas fa-info-circle'></i> About This Setup</h6>
                        <ul class='mb-0'>
                            <li>This will create the <strong>SUPER ADMIN</strong> account</li>
                            <li>You will have <strong>full system access</strong></li>
                            <li>This page will <strong>self-destruct</strong> after setup</li>
                            <li>Store credentials in a <strong>secure location</strong></li>
                        </ul>
                    </div>
                    
                    <div class='d-grid gap-2'>
                        <button type='submit' class='btn btn-primary btn-lg'>
                            <i class='fas fa-user-plus'></i> Create Super Admin Account
                        </button>
                        <a href='login.php' class='btn btn-outline-secondary'>
                            <i class='fas fa-times'></i> Cancel
                        </a>
                    </div>
                </form>
                </div>
                
                <div class='card-footer text-center text-muted'>
                    <small>
                        <i class='fas fa-shield-alt'></i> Secure Setup | 
                        IP: " . htmlspecialchars($_SERVER['REMOTE_ADDR']) . " | 
                        Time: " . date('Y-m-d H:i:s') . "
                    </small>
                </div>
            </div>
        </div>
        
        <script src='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js'></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Toggle password visibility
                const toggleBtn = document.getElementById('togglePassword');
                const passwordInput = document.getElementById('password');
                const confirmInput = document.getElementById('confirm_password');
                const strengthBar = document.getElementById('passwordStrength');
                
                toggleBtn.addEventListener('click', function() {
                    const type = passwordInput.type === 'password' ? 'text' : 'password';
                    passwordInput.type = type;
                    confirmInput.type = type;
                    this.innerHTML = type === 'password' ? '<i class=\"fas fa-eye\"></i>' : '<i class=\"fas fa-eye-slash\"></i>';
                });
                
                // Password strength indicator
                passwordInput.addEventListener('input', function() {
                    const pass = this.value;
                    let strength = 0;
                    
                    if (pass.length >= 8) strength++;
                    if (/[A-Z]/.test(pass)) strength++;
                    if (/[a-z]/.test(pass)) strength++;
                    if (/[0-9]/.test(pass)) strength++;
                    if (/[^A-Za-z0-9]/.test(pass)) strength++;
                    
                    strengthBar.className = 'password-strength strength-' + Math.min(strength, 4);
                });
                
                // Password confirmation
                confirmInput.addEventListener('input', function() {
                    if (passwordInput.value !== this.value) {
                        this.classList.add('is-invalid');
                        document.getElementById('passwordError').style.display = 'block';
                    } else {
                        this.classList.remove('is-invalid');
                        document.getElementById('passwordError').style.display = 'none';
                    }
                });
                
                // Form validation
                document.getElementById('setupForm').addEventListener('submit', function(e) {
                    const password = document.getElementById('password').value;
                    const confirm = document.getElementById('confirm_password').value;
                    
                    if (password !== confirm) {
                        e.preventDefault();
                        alert('Passwords do not match!');
                        return false;
                    }
                    
                    if (password.length < 8) {
                        e.preventDefault();
                        alert('Password must be at least 8 characters long!');
                        return false;
                    }
                    
                    // Show loading
                    const submitBtn = this.querySelector('button[type=\"submit\"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> Creating Account...';
                        submitBtn.disabled = true;
                    }
                    
                    return true;
                });
            });
        </script>
    </body>
    </html>";
}

function selfDestruct() {
    // Delete this file after successful setup
    $current_file = __FILE__;
    
    // Schedule deletion after a short delay
    if (function_exists('register_shutdown_function')) {
        register_shutdown_function(function() use ($current_file) {
            if (file_exists($current_file)) {
                // Try to delete the file
                @unlink($current_file);
                
                // Also delete any attempt counter file
                $attempts_file = dirname($current_file) . '/setup_attempts.txt';
                if (file_exists($attempts_file)) {
                    @unlink($attempts_file);
                }
            }
        });
    }
}
?>