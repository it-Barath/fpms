<?php
/**Config.php
 * FPMS - Family Profile Management System
 * Main Configuration File
 */

// ============================================================================
// APPLICATION SETTINGS
// ============================================================================

// Application Information
define('SITE_NAME', 'Family Profile Management System');
define('SITE_SHORT_NAME', 'FPMS');
define('SITE_VERSION', '1.0.0');
define('SITE_URL', 'https://dsd.samurdhi.gov.lk/fpms/');
define('BASE_PATH', __DIR__ . '/');

// Developer Mode
define('DEBUG_MODE', true);
define('MAINTENANCE_MODE', false);

// Contact Information
define('ADMIN_EMAIL', 'admin@fpms.lk');
define('SUPPORT_EMAIL', 'support@fpms.lk');
define('SUPPORT_PHONE', '+94 11 2 123 456');

// Organization Details
define('ORGANIZATION_NAME', 'Ministry of Home Affairs');
define('ORGANIZATION_ADDRESS', 'Republic Square, Colombo 01, Sri Lanka');
define('SYSTEM_ADMIN', 'MOHA - Information Technology Division');

// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================

// Main Application Database (fpms)
define('MAIN_DB_HOST', 'localhost');
define('MAIN_DB_PORT', '3306');
define('MAIN_DB_NAME', 'fpms');
define('MAIN_DB_USER', 'it-barath-db');
define('MAIN_DB_PASS', '1512@Balagi');
define('MAIN_DB_CHARSET', 'utf8mb4');
define('MAIN_DB_COLLATION', 'utf8mb4_unicode_ci');

// Reference Database (mobile_service - existing workstation data)
define('REF_DB_HOST', 'localhost');
define('REF_DB_PORT', '3306');
define('REF_DB_NAME', 'mobile_service');
define('REF_DB_USER', 'it-barath-db');
define('REF_DB_PASS', '1512@Balagi');
define('REF_DB_CHARSET', 'utf8mb4');
define('REF_DB_COLLATION', 'utf8mb4_unicode_ci');

// Backup Database Configuration
define('BACKUP_DIR', BASE_PATH . 'backups/');
define('BACKUP_RETENTION_DAYS', 30);
define('AUTO_BACKUP', true);
define('BACKUP_TIME', '02:00'); // Daily backup at 2 AM

// ============================================================================
// SECURITY SETTINGS
// ============================================================================

// Session Security
define('SESSION_NAME', 'FPMS_SESSION');
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('SESSION_REGENERATE', 1800); // Regenerate ID every 30 minutes
define('SESSION_HTTP_ONLY', true);
define('SESSION_SECURE', false); // Set to true for HTTPS
define('SESSION_SAME_SITE', 'Lax');

// Password Policy
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_MAX_LENGTH', 32);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SYMBOL', true);
define('PASSWORD_RESET_LENGTH', 12); // For auto-generated passwords
define('PASSWORD_HASH_ALGO', PASSWORD_DEFAULT);
define('PASSWORD_COST', 12);

// Login Security
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds
define('REMEMBER_ME_DURATION', 2592000); // 30 days

// CSRF Protection
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_TOKEN_EXPIRE', 3600); // 1 hour

// File Upload Security
define('MAX_FILE_SIZE', 2097152); // 2MB in bytes
define('ALLOWED_FILE_TYPES', ['image/jpeg', 'image/png', 'application/pdf']);
define('UPLOAD_DIR', BASE_PATH . 'assets/uploads/');
define('MAX_FILES_PER_UPLOAD', 5);

// ============================================================================
// APPLICATION BEHAVIOR
// ============================================================================

// Time and Date Settings
date_default_timezone_set('Asia/Colombo');
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'd/m/Y');
define('DISPLAY_DATETIME_FORMAT', 'd/m/Y H:i:s');

// Pagination Settings
define('ITEMS_PER_PAGE', 20);
define('MAX_PAGINATION_LINKS', 5);

// Cache Settings
define('CACHE_ENABLED', true);
define('CACHE_DIR', BASE_PATH . 'cache/');
define('CACHE_TTL', 300); // 5 minutes

// Email Settings
define('EMAIL_ENABLED', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'noreply@fpms.lk');
define('SMTP_PASSWORD', '');
define('SMTP_SECURE', 'tls');
define('SMTP_FROM_NAME', SITE_NAME);
define('SMTP_FROM_EMAIL', 'noreply@fpms.lk');

// SMS Settings (if applicable)
define('SMS_ENABLED', false);
define('SMS_API_KEY', '');
define('SMS_API_SECRET', '');
define('SMS_SENDER_ID', 'FPMS');

// Notification Settings
define('NOTIFY_NEW_FAMILY', true);
define('NOTIFY_TRANSFER_REQUEST', true);
define('NOTIFY_PASSWORD_RESET', true);

// ============================================================================
// SYSTEM PATHS
// ============================================================================

define('CLASSES_DIR', BASE_PATH . 'classes/');
define('INCLUDES_DIR', BASE_PATH . 'includes/');
define('ASSETS_DIR', BASE_PATH . 'assets/');
define('CSS_DIR', ASSETS_DIR . 'css/');
define('JS_DIR', ASSETS_DIR . 'js/');
define('IMAGES_DIR', ASSETS_DIR . 'images/');
define('UPLOADS_DIR', ASSETS_DIR . 'uploads/');
define('TEMP_DIR', BASE_PATH . 'temp/');
define('LOGS_DIR', BASE_PATH . 'logs/');

// API Configuration
define('API_ENABLED', true);
define('API_VERSION', 'v1');
define('API_KEY_EXPIRY', 86400); // 24 hours

// ============================================================================
// ERROR HANDLING
// ============================================================================

// Error Reporting
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', LOGS_DIR . 'php_errors.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', LOGS_DIR . 'php_errors.log');
}

// Custom Error Handler
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    $errorTypes = [
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated'
    ];
    
    $errorType = isset($errorTypes[$errno]) ? $errorTypes[$errno] : 'Unknown Error';
    
    $errorMessage = date(DATETIME_FORMAT) . " - [$errorType] $errstr in $errfile on line $errline" . PHP_EOL;
    
    // Log to file
    error_log($errorMessage, 3, LOGS_DIR . 'application_errors.log');
    
    // Show error in debug mode
    if (DEBUG_MODE) {
        echo "<div class='alert alert-danger'>";
        echo "<strong>$errorType:</strong> $errstr<br>";
        echo "<small>File: $errfile (Line: $errline)</small>";
        echo "</div>";
    }
    
    return true;
}

set_error_handler("customErrorHandler");

// Exception Handler
function customExceptionHandler($exception) {
    $errorMessage = date(DATETIME_FORMAT) . " - [Exception] " . $exception->getMessage() . 
                   " in " . $exception->getFile() . " on line " . $exception->getLine() . PHP_EOL;
    
    error_log($errorMessage, 3, LOGS_DIR . 'exceptions.log');
    
    if (DEBUG_MODE) {
        echo "<div class='alert alert-danger'>";
        echo "<strong>Exception:</strong> " . $exception->getMessage() . "<br>";
        echo "<small>File: " . $exception->getFile() . " (Line: " . $exception->getLine() . ")</small>";
        echo "<pre>" . $exception->getTraceAsString() . "</pre>";
        echo "</div>";
    } else {
        // Redirect to error page in production
        header('Location: error.php?code=500');
        exit();
    }
}

set_exception_handler("customExceptionHandler");

// Shutdown Function for Fatal Errors
function shutdownHandler() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $errorMessage = date(DATETIME_FORMAT) . " - [Fatal Error] " . $error['message'] . 
                       " in " . $error['file'] . " on line " . $error['line'] . PHP_EOL;
        
        error_log($errorMessage, 3, LOGS_DIR . 'fatal_errors.log');
        
        if (!DEBUG_MODE) {
            header('Location: error.php?code=500');
            exit();
        }
    }
}

register_shutdown_function("shutdownHandler");

// ============================================================================
// DATABASE CONNECTION FUNCTIONS
// ============================================================================

/**
 * Get main database connection
 * @return mysqli
 */
function getMainConnection() {
    static $mainConn = null;
    
    if ($mainConn === null) {
        $mainConn = new mysqli(
            MAIN_DB_HOST, 
            MAIN_DB_USER, 
            MAIN_DB_PASS, 
            MAIN_DB_NAME,
            MAIN_DB_PORT
        );
        
        if ($mainConn->connect_error) {
            $errorMsg = "Main Database Connection Failed: " . $mainConn->connect_error;
            error_log($errorMsg, 3, LOGS_DIR . 'database_errors.log');
            
            if (DEBUG_MODE) {
                die("<div class='alert alert-danger'><strong>Database Error:</strong> " . 
                    htmlspecialchars($mainConn->connect_error) . "</div>");
            } else {
                header('Location: error.php?code=503');
                exit();
            }
        }
        
        $mainConn->set_charset(MAIN_DB_CHARSET);
        
        // Set timezone for database
        $mainConn->query("SET time_zone = '+05:30'");
        
        // Set SQL mode
        $mainConn->query("SET SQL_MODE = ''");
    }
    
    return $mainConn;
}

/**
 * Get reference database connection
 * @return mysqli
 */
function getRefConnection() {
    static $refConn = null;
    
    if ($refConn === null) {
        $refConn = new mysqli(
            REF_DB_HOST, 
            REF_DB_USER, 
            REF_DB_PASS, 
            REF_DB_NAME,
            REF_DB_PORT
        );
        
        if ($refConn->connect_error) {
            $errorMsg = "Reference Database Connection Failed: " . $refConn->connect_error;
            error_log($errorMsg, 3, LOGS_DIR . 'database_errors.log');
            
            if (DEBUG_MODE) {
                die("<div class='alert alert-danger'><strong>Database Error:</strong> " . 
                    htmlspecialchars($refConn->connect_error) . "</div>");
            } else {
                header('Location: error.php?code=503');
                exit();
            }
        }
        
        $refConn->set_charset(REF_DB_CHARSET);
    }
    
    return $refConn;
}

/**
 * Close all database connections
 */
function closeAllConnections() {
    $mainConn = getMainConnection();
    $refConn = getRefConnection();
    
    if ($mainConn) $mainConn->close();
    if ($refConn) $refConn->close();
}

// ============================================================================
// SESSION MANAGEMENT
// ============================================================================

// Configure session settings
ini_set('session.name', SESSION_NAME);
ini_set('session.cookie_lifetime', SESSION_TIMEOUT);
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
ini_set('session.cookie_httponly', SESSION_HTTP_ONLY);
ini_set('session.cookie_secure', SESSION_SECURE);
ini_set('session.cookie_samesite', SESSION_SAME_SITE);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['CREATED'])) {
        $_SESSION['CREATED'] = time();
    } elseif (time() - $_SESSION['CREATED'] > SESSION_REGENERATE) {
        session_regenerate_id(true);
        $_SESSION['CREATED'] = time();
    }
}

// ============================================================================
// AUTOLOAD CLASSES
// ============================================================================

spl_autoload_register(function ($className) {
    $classFile = CLASSES_DIR . $className . '.php';
    
    if (file_exists($classFile)) {
        require_once $classFile;
    } elseif (DEBUG_MODE) {
        error_log("Class file not found: $classFile", 3, LOGS_DIR . 'autoload_errors.log');
    }
});

// ============================================================================
// COMMON FUNCTIONS
// ============================================================================

/**
 * Generate CSRF token
 * @return string
 */
function generateCsrfToken() {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        $_SESSION[CSRF_TOKEN_NAME . '_time'] = time();
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validate CSRF token
 * @param string $token
 * @return bool
 */
function validateCsrfToken($token) {
    if (empty($_SESSION[CSRF_TOKEN_NAME]) || empty($token)) {
        return false;
    }
    
    if ($_SESSION[CSRF_TOKEN_NAME] !== $token) {
        return false;
    }
    
    // Check token expiry
    if (time() - $_SESSION[CSRF_TOKEN_NAME . '_time'] > CSRF_TOKEN_EXPIRE) {
        unset($_SESSION[CSRF_TOKEN_NAME], $_SESSION[CSRF_TOKEN_NAME . '_time']);
        return false;
    }
    
    return true;
}

/**
 * Sanitize input data
 * @param mixed $data
 * @return mixed
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInput($value);
        }
    } else {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    return $data;
}

/**
 * Validate email address
 * @param string $email
 * @return bool
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate Sri Lankan phone number
 * @param string $phone
 * @return bool
 */
function isValidSriLankanPhone($phone) {
    $pattern = '/^(?:\+94|0)(?:11|21|23|24|25|26|27|31|32|33|34|35|36|37|38|41|45|47|51|52|54|55|57|63|65|66|67|81|91)[0-9]{7}$/';
    return preg_match($pattern, $phone);
}

/**
 * Validate Sri Lankan NIC
 * @param string $nic
 * @return bool
 */
function isValidSriLankanNIC($nic) {
    // Remove spaces and convert to uppercase
    $nic = strtoupper(trim($nic));
    
    // Check for old format (9 digits with optional V or X)
    if (preg_match('/^[0-9]{9}[VX]$/', $nic)) {
        return true;
    }
    
    // Check for new format (12 digits)
    if (preg_match('/^[0-9]{12}$/', $nic)) {
        return true;
    }
    
    return false;
}

/**
 * Generate random password
 * @param int $length
 * @return string
 */
function generateRandomPassword($length = PASSWORD_RESET_LENGTH) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    $charsLength = strlen($chars);
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $charsLength - 1)];
    }
    
    return $password;
}

/**
 * Check if password meets policy requirements
 * @param string $password
 * @return array [bool $valid, string $message]
 */
function validatePasswordPolicy($password) {
    $length = strlen($password);
    
    if ($length < PASSWORD_MIN_LENGTH) {
        return [false, "Password must be at least " . PASSWORD_MIN_LENGTH . " characters long"];
    }
    
    if ($length > PASSWORD_MAX_LENGTH) {
        return [false, "Password cannot exceed " . PASSWORD_MAX_LENGTH . " characters"];
    }
    
    if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
        return [false, "Password must contain at least one uppercase letter"];
    }
    
    if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
        return [false, "Password must contain at least one lowercase letter"];
    }
    
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        return [false, "Password must contain at least one number"];
    }
    
    if (PASSWORD_REQUIRE_SYMBOL && !preg_match('/[!@#$%^&*]/', $password)) {
        return [false, "Password must contain at least one special character (!@#$%^&*)"];
    }
    
    return [true, "Password meets all requirements"];
}

/**
 * Get user IP address
 * @return string
 */
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

/**
 * Log user activity
 * @param string $action
 * @param string $details
 * @param int $userId
 */
/**
 * Log user activity
 * @param string $action
 * @param string $details
 * @param int $userId
 */
function logActivity($action, $details = '', $userId = null) {
    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
    }
    
    $conn = getMainConnection();
    $ip = getUserIP();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Use correct column names from your audit_logs table schema
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, ip_address, user_agent) 
                           VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $userId, $action, $details, $details, $ip, $userAgent);
    $stmt->execute();
}

/**
 * Redirect with message
 * @param string $url
 * @param string $type success|error|warning|info
 * @param string $message
 */
function redirectWithMessage($url, $type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
    header("Location: $url");
    exit();
}

/**
 * Display flash message
 */
/**
 * Display flash message
 */
function displayFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        
        $alertClass = '';
        switch ($message['type']) {
            case 'success': $alertClass = 'alert-success'; break;
            case 'error': $alertClass = 'alert-danger'; break;
            case 'warning': $alertClass = 'alert-warning'; break;
            case 'info': $alertClass = 'alert-info'; break;
            default: $alertClass = 'alert-primary';
        }
        
        echo "<div class='alert $alertClass alert-dismissible fade show' role='alert'>";
        echo htmlspecialchars($message['message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo "</div>";
    }
}

// ============================================================================
// MAINTENANCE MODE CHECK
// ============================================================================

if (MAINTENANCE_MODE && !isset($_SESSION['user_id'])) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Maintenance - <?php echo SITE_NAME; ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
            .maintenance-container { max-width: 600px; margin-top: 15vh; }
        </style>
    </head>
    <body>
        <div class="container maintenance-container">
            <div class="card shadow-lg">
                <div class="card-body text-center p-5">
                    <div class="mb-4">
                        <i class="bi bi-tools" style="font-size: 4rem; color: #6c757d;"></i>
                    </div>
                    <h1 class="h2 mb-3">System Maintenance</h1>
                    <p class="text-muted mb-4">
                        <?php echo SITE_NAME; ?> is currently undergoing scheduled maintenance.
                        We apologize for any inconvenience and appreciate your patience.
                    </p>
                    <div class="alert alert-info">
                        <i class="bi bi-clock"></i> Expected completion: <?php echo date('h:i A', strtotime('+2 hours')); ?>
                    </div>
                    <hr>
                    <small class="text-muted">
                        For urgent inquiries, please contact:<br>
                        <i class="bi bi-telephone"></i> <?php echo SUPPORT_PHONE; ?> | 
                        <i class="bi bi-envelope"></i> <?php echo SUPPORT_EMAIL; ?>
                    </small>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ============================================================================
// INITIALIZATION
// ============================================================================

// Create necessary directories if they don't exist
$requiredDirs = [LOGS_DIR, CACHE_DIR, UPLOADS_DIR, TEMP_DIR, BACKUP_DIR];
foreach ($requiredDirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Set headers for security
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Add CSP header in production
if (!DEBUG_MODE) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:;");
}

// Initialize CSRF token
generateCsrfToken();

// ============================================================================
// END OF CONFIGURATION
// ============================================================================
?>