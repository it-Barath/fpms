<?php
require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit();
}

// Initialize variables
$error = '';
$username = '';
$remember = false;

// Check for login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Get form data
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) ? true : false;
        $captcha_response = $_POST['captcha_response'] ?? '';
        
        // Basic validation
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password';
        } else {
            try {
                // Create Auth instance and attempt login
                $auth = new Auth();
                
                if ($auth->login($username, $password, $remember)) {
                    // Check for redirect URL
                    $redirect_url = $_SESSION['redirect_url'] ?? 'index.php';
                    unset($_SESSION['redirect_url']);
                    
                    // Log successful login
                    logActivity('login_success', 'User logged in successfully: ' . $username, $_SESSION['user_id'] ?? null);
                    
                    // Redirect to dashboard or intended page
                    header('Location: ' . $redirect_url);
                    exit();
                } else {
                    $error = 'Invalid username or password';
                    
                    // Get login attempts info
                    $attempts = $auth->getLoginAttempts($username);
                    if (!empty($attempts) && $attempts['count'] >= (MAX_LOGIN_ATTEMPTS - 2)) {
                        $remaining = MAX_LOGIN_ATTEMPTS - $attempts['count'];
                        if ($remaining > 0) {
                            $error .= " ($remaining attempts remaining before lockout)";
                        } else {
                            $error = 'Account locked. Please try again in ' . ceil(LOGIN_LOCKOUT_TIME / 60) . ' minutes.';
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'System error. Please try again later.';
                if (DEBUG_MODE) {
                    $error .= ' Debug: ' . $e->getMessage();
                }
                logActivity('login_error', 'Login system error: ' . $e->getMessage());
            }
        }
    }
}

// Generate CSRF token for form
$csrf_token = generateCsrfToken();

// Set page title
$pageTitle = "Login - " . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px;
        }
        
        .login-container {
            max-width: 420px;
            width: 100%;
            margin: 0 auto;
        }
        
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        
        .login-header .logo {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .login-header h1 {
            font-size: 1.5rem;
            margin: 0;
            font-weight: 600;
        }
        
        .login-header .subtitle {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-top: 5px;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .form-control {
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px 0 0 8px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }
        
        .login-footer {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #eee;
            font-size: 0.85rem;
            color: #666;
        }
        
        .login-footer a {
            color: #667eea;
            text-decoration: none;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
        }
        
        .password-toggle {
            cursor: pointer;
            background: transparent;
            border: none;
            color: #666;
        }
        
        .system-info {
            background: #f0f7ff;
            border-left: 4px solid #667eea;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        .system-info h6 {
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        @media (max-width: 576px) {
            .login-container {
                max-width: 100%;
            }
            
            .login-body {
                padding: 20px;
            }
            
            .remember-forgot {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Header -->
            <div class="login-header">
                <div class="logo">
                    <i class="fas fa-users"></i>
                </div>
                <h1><?php echo htmlspecialchars(SITE_NAME); ?></h1>
                <div class="subtitle">Family Profile Management System</div>
            </div>
            
            <!-- Body -->
            <div class="login-body">
                <!-- System Information -->
                <div class="system-info">
                    <h6><i class="fas fa-info-circle"></i> System Information</h6>
                    <div class="row">
                        <div class="col-6">
                            <small><i class="fas fa-building"></i> <?php echo ORGANIZATION_NAME; ?></small>
                        </div>
                        <div class="col-6">
                            <small><i class="fas fa-map-marker-alt"></i> Sri Lanka</small>
                        </div>
                    </div>
                </div>
                
                <!-- Error Message -->
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Login Form -->
                <form method="POST" action="" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    
                    <!-- Username Field -->
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="fas fa-user"></i> Username
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-user-tag"></i>
                            </span>
                            <input type="text" 
                                   class="form-control" 
                                   id="username" 
                                   name="username" 
                                   value="<?php echo htmlspecialchars($username); ?>"
                                   placeholder="Enter your username"
                                   required
                                   autofocus>
                        </div>
                        <small class="form-text text-muted">
                            Format: district_colombo, division_kolonnawa, gn_12345
                        </small>
                    </div>
                    
                    <!-- Password Field -->
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock"></i> Password
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-key"></i>
                            </span>
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Enter your password"
                                   required>
                            <button type="button" class="btn password-toggle" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="form-text text-muted">
                            Minimum <?php echo PASSWORD_MIN_LENGTH; ?> characters
                        </small>
                    </div>
                    
                    <!-- Remember Me & Forgot Password -->
                    <div class="remember-forgot">
                        <div class="form-check">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   id="remember" 
                                   name="remember"
                                   <?php echo $remember ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="remember">
                                Remember me for 30 days
                            </label>
                        </div>
                        <a href="forgot_password.php" class="text-decoration-none">
                            <i class="fas fa-question-circle"></i> Forgot Password?
                        </a>
                    </div>
                    
                    <!-- Login Button -->
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-login">
                            <i class="fas fa-sign-in-alt"></i> Login to System
                        </button>
                    </div>
                    
                    <!-- User Guide -->
                    <div class="text-center mb-3">
                        <small class="text-muted">
                            <i class="fas fa-book"></i> 
                            <a href="#" data-bs-toggle="modal" data-bs-target="#userGuideModal">
                                User Guide & Instructions
                            </a>
                        </small>
                    </div>
                    
                    <!-- Support Information -->
                    <div class="text-center">
                        <small class="text-muted d-block">
                            <i class="fas fa-headset"></i> Need help? 
                            Contact: <?php echo htmlspecialchars(SUPPORT_EMAIL); ?>
                        </small>
                        <small class="text-muted d-block mt-1">
                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars(SUPPORT_PHONE); ?>
                        </small>
                    </div>
                </form>
            </div>
            
            <!-- Footer -->
            <div class="login-footer">
                <div>
                    <small>
                        Version <?php echo SITE_VERSION; ?> | 
                        &copy; <?php echo date('Y'); ?> <?php echo ORGANIZATION_NAME; ?>
                    </small>
                </div>
                <div class="mt-1">
                    <small>
                        <i class="fas fa-shield-alt"></i> Secure Login | 
                        <i class="fas fa-lock"></i> Encrypted Connection
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- User Guide Modal -->
    <div class="modal fade" id="userGuideModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-book"></i> User Login Guide
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Login Credentials:</h6>
                    <ul>
                        <li><strong>MOHA Users:</strong> moha_username</li>
                        <li><strong>District Secretariat:</strong> district_districtname</li>
                        <li><strong>Divisional Secretariat:</strong> division_divisionname</li>
                        <li><strong>GN Division:</strong> gn_gnid</li>
                    </ul>
                    
                    <h6>Password Rules:</h6>
                    <ul>
                        <li>Minimum <?php echo PASSWORD_MIN_LENGTH; ?> characters</li>
                        <li>Must contain uppercase and lowercase letters</li>
                        <li>Must contain at least one number</li>
                        <li>Must contain at least one special character</li>
                    </ul>
                    
                    <h6>Troubleshooting:</h6>
                    <ul>
                        <li>Ensure Caps Lock is off</li>
                        <li>Check username format</li>
                        <li>Contact system administrator if locked out</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle password visibility
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            
            if (togglePassword && passwordInput) {
                togglePassword.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
                });
            }
            
            // Auto-focus username field if empty
            const usernameInput = document.getElementById('username');
            if (usernameInput && !usernameInput.value) {
                usernameInput.focus();
            }
            
            // Form validation
            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                loginForm.addEventListener('submit', function(e) {
                    const username = document.getElementById('username').value.trim();
                    const password = document.getElementById('password').value;
                    
                    if (!username || !password) {
                        e.preventDefault();
                        alert('Please fill in both username and password fields.');
                        return false;
                    }
                    
                    // Show loading state
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
                        submitBtn.disabled = true;
                    }
                    
                    return true;
                });
            }
            
            // Check for URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('expired')) {
                alert('Your session has expired. Please login again.');
            }
            if (urlParams.has('loggedout')) {
                alert('You have been successfully logged out.');
            }
            if (urlParams.has('reset')) {
                alert('Your password has been reset. Please login with your new password.');
            }
            
            // Display last username from cookie if available
            const lastUsername = getCookie('last_username');
            if (lastUsername && usernameInput && !usernameInput.value) {
                usernameInput.value = lastUsername;
                document.getElementById('password').focus();
            }
        });
        
        // Cookie helper function
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }
        
        // Set cookie on form submission
        document.getElementById('loginForm')?.addEventListener('submit', function() {
            const username = document.getElementById('username').value;
            if (username) {
                document.cookie = `last_username=${encodeURIComponent(username)}; path=/; max-age=2592000`; // 30 days
            }
        });
        
        // Auto-refresh captcha if exists
        function refreshCaptcha() {
            const captchaImg = document.getElementById('captchaImage');
            if (captchaImg) {
                captchaImg.src = 'captcha.php?t=' + new Date().getTime();
            }
        }
    </script>
    
    <!-- Visitor counter (optional) -->
    <script>
        // Log page visit
        if (navigator.sendBeacon) {
            navigator.sendBeacon('log_visit.php', 'login_page');   
        }
    </script>
</body>
</html>