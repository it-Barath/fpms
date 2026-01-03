<?php
// system_settings.php
// System configuration and administration settings

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/UserManager.php';
require_once '../classes/Validator.php';

// Initialize classes
$auth = new Auth();
$userManager = new UserManager();
$validator = new Validator();

// Check authentication and authorization
$auth->requireLogin();
$auth->requireRole('moha');

// Get current user
$currentUser = $auth->getCurrentUser();

// Set page title
$pageTitle = "System Settings";
$pageDescription = "Configure system preferences and administration settings";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $successMessage = '';
    $errorMessage = '';
    
    switch ($action) {
        case 'update_general_settings':
            // Update general system settings
            $siteName = $validator->sanitize($_POST['site_name'] ?? '');
            $adminEmail = $validator->sanitize($_POST['admin_email'] ?? '');
            $supportEmail = $validator->sanitize($_POST['support_email'] ?? '');
            $supportPhone = $validator->sanitize($_POST['support_phone'] ?? '');
            $itemsPerPage = (int)($_POST['items_per_page'] ?? 20);
            
            // Validate inputs
            $errors = [];
            
            if (empty($siteName)) {
                $errors[] = "Site name is required";
            }
            
            if (!empty($adminEmail) && !$validator->validateEmail($adminEmail)) {
                $errors[] = "Invalid admin email address";
            }
            
            if (!empty($supportEmail) && !$validator->validateEmail($supportEmail)) {
                $errors[] = "Invalid support email address";
            }
            
            if (!empty($supportPhone) && !$validator->validatePhone($supportPhone)) {
                $errors[] = "Invalid support phone number";
            }
            
            if ($itemsPerPage < 5 || $itemsPerPage > 100) {
                $errors[] = "Items per page must be between 5 and 100";
            }
            
            if (empty($errors)) {
                // In a real system, you would save to database
                // For now, we'll simulate success
                $successMessage = "General settings updated successfully";
                logActivity('settings_updated', 'Updated general system settings', $currentUser['user_id']);
            } else {
                $errorMessage = implode('<br>', $errors);
            }
            break;
            
        case 'update_security_settings':
            // Update security settings
            $sessionTimeout = (int)($_POST['session_timeout'] ?? 3600);
            $maxLoginAttempts = (int)($_POST['max_login_attempts'] ?? 5);
            $loginLockoutTime = (int)($_POST['login_lockout_time'] ?? 900);
            $passwordMinLength = (int)($_POST['password_min_length'] ?? 8);
            $enable2FA = isset($_POST['enable_2fa']) ? 1 : 0;
            $enableIPWhitelist = isset($_POST['enable_ip_whitelist']) ? 1 : 0;
            $ipWhitelist = $validator->sanitize($_POST['ip_whitelist'] ?? '');
            
            // Validate inputs
            $errors = [];
            
            if ($sessionTimeout < 300 || $sessionTimeout > 86400) {
                $errors[] = "Session timeout must be between 5 minutes and 24 hours";
            }
            
            if ($maxLoginAttempts < 3 || $maxLoginAttempts > 10) {
                $errors[] = "Max login attempts must be between 3 and 10";
            }
            
            if ($loginLockoutTime < 300 || $loginLockoutTime > 3600) {
                $errors[] = "Login lockout time must be between 5 and 60 minutes";
            }
            
            if ($passwordMinLength < 6 || $passwordMinLength > 32) {
                $errors[] = "Password minimum length must be between 6 and 32 characters";
            }
            
            if (empty($errors)) {
                $successMessage = "Security settings updated successfully";
                logActivity('settings_updated', 'Updated security settings', $currentUser['user_id']);
            } else {
                $errorMessage = implode('<br>', $errors);
            }
            break;
            
        case 'update_email_settings':
            // Update email settings
            $smtpHost = $validator->sanitize($_POST['smtp_host'] ?? '');
            $smtpPort = (int)($_POST['smtp_port'] ?? 587);
            $smtpUsername = $validator->sanitize($_POST['smtp_username'] ?? '');
            $smtpPassword = $_POST['smtp_password'] ?? '';
            $smtpSecure = $validator->sanitize($_POST['smtp_secure'] ?? 'tls');
            $fromName = $validator->sanitize($_POST['from_name'] ?? '');
            $fromEmail = $validator->sanitize($_POST['from_email'] ?? '');
            
            // Validate inputs
            $errors = [];
            
            if (empty($smtpHost)) {
                $errors[] = "SMTP host is required";
            }
            
            if ($smtpPort < 1 || $smtpPort > 65535) {
                $errors[] = "Invalid SMTP port";
            }
            
            if (!empty($fromEmail) && !$validator->validateEmail($fromEmail)) {
                $errors[] = "Invalid from email address";
            }
            
            if (empty($errors)) {
                // Test email configuration (optional)
                if (isset($_POST['test_email'])) {
                    // Send test email
                    $testResult = sendTestEmail($fromEmail, $smtpHost, $smtpPort, $smtpUsername, $smtpPassword);
                    if ($testResult['success']) {
                        $successMessage = "Email settings updated and test email sent successfully";
                    } else {
                        $errorMessage = "Email settings saved but test failed: " . $testResult['message'];
                    }
                } else {
                    $successMessage = "Email settings updated successfully";
                }
                logActivity('settings_updated', 'Updated email settings', $currentUser['user_id']);
            } else {
                $errorMessage = implode('<br>', $errors);
            }
            break;
            
        case 'update_backup_settings':
            // Update backup settings
            $autoBackup = isset($_POST['auto_backup']) ? 1 : 0;
            $backupTime = $validator->sanitize($_POST['backup_time'] ?? '02:00');
            $backupRetention = (int)($_POST['backup_retention'] ?? 30);
            $backupLocation = $validator->sanitize($_POST['backup_location'] ?? '');
            $enableCloudBackup = isset($_POST['enable_cloud_backup']) ? 1 : 0;
            
            // Validate inputs
            $errors = [];
            
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $backupTime)) {
                $errors[] = "Invalid backup time format (HH:MM)";
            }
            
            if ($backupRetention < 1 || $backupRetention > 365) {
                $errors[] = "Backup retention must be between 1 and 365 days";
            }
            
            if (empty($errors)) {
                $successMessage = "Backup settings updated successfully";
                logActivity('settings_updated', 'Updated backup settings', $currentUser['user_id']);
            } else {
                $errorMessage = implode('<br>', $errors);
            }
            break;
            
        case 'clear_cache':
            // Clear system cache
            if (clearSystemCache()) {
                $successMessage = "System cache cleared successfully";
                logActivity('cache_cleared', 'Cleared system cache', $currentUser['user_id']);
            } else {
                $errorMessage = "Failed to clear cache";
            }
            break;
            
        case 'rebuild_indexes':
            // Rebuild database indexes
            if (rebuildDatabaseIndexes()) {
                $successMessage = "Database indexes rebuilt successfully";
                logActivity('indexes_rebuilt', 'Rebuilt database indexes', $currentUser['user_id']);
            } else {
                $errorMessage = "Failed to rebuild indexes";
            }
            break;
            
        case 'create_backup':
            // Create manual backup
            $backupResult = createManualBackup();
            if ($backupResult['success']) {
                $successMessage = "Backup created successfully: " . $backupResult['filename'];
                logActivity('backup_created', 'Created manual backup: ' . $backupResult['filename'], $currentUser['user_id']);
            } else {
                $errorMessage = "Backup failed: " . $backupResult['message'];
            }
            break;
            
        case 'toggle_maintenance':
            // Toggle maintenance mode
            $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
            $maintenanceMessage = $validator->sanitize($_POST['maintenance_message'] ?? '');
            
            if (updateMaintenanceMode($maintenanceMode, $maintenanceMessage)) {
                $status = $maintenanceMode ? 'enabled' : 'disabled';
                $successMessage = "Maintenance mode {$status} successfully";
                logActivity('maintenance_toggle', "{$status} maintenance mode", $currentUser['user_id']);
            } else {
                $errorMessage = "Failed to update maintenance mode";
            }
            break;
    }
    
    // Store messages in session
    if ($successMessage) {
        $_SESSION['success_message'] = $successMessage;
    }
    if ($errorMessage) {
        $_SESSION['error_message'] = $errorMessage;
    }
    
    // Redirect to avoid form resubmission
    header("Location: system_settings.php");
    exit();
}

// Get current system statistics
$systemStats = getSystemStatistics();

// Get recent backups
$recentBackups = getRecentBackups(5);

// Get system logs
$systemLogs = getSystemLogs(10);

// Get database size
$dbSize = getDatabaseSize();

// Include header
include '../includes/header.php';

// Helper functions (these would typically be in a separate file)
function sendTestEmail($fromEmail, $smtpHost, $smtpPort, $smtpUsername, $smtpPassword) {
    // Simulate email sending
    return ['success' => true, 'message' => 'Test email sent successfully'];
}

function clearSystemCache() {
    // Simulate cache clearing
    return true;
}

function rebuildDatabaseIndexes() {
    // Simulate index rebuilding
    return true;
}

function createManualBackup() {
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    return ['success' => true, 'filename' => $filename];
}

function updateMaintenanceMode($enabled, $message) {
    // Simulate maintenance mode update
    return true;
}

function getSystemStatistics() {
    return [
        'total_users' => 150,
        'active_users' => 120,
        'total_families' => 50000,
        'total_population' => 200000,
        'disk_usage' => '75%',
        'memory_usage' => '60%',
        'cpu_usage' => '45%',
        'uptime' => '15 days'
    ];
}

function getRecentBackups($limit) {
    return [
        ['filename' => 'backup_2024-01-15_02-00-01.sql', 'size' => '250 MB', 'date' => '2024-01-15 02:00:01'],
        ['filename' => 'backup_2024-01-14_02-00-01.sql', 'size' => '245 MB', 'date' => '2024-01-14 02:00:01'],
        ['filename' => 'backup_2024-01-13_02-00-01.sql', 'size' => '240 MB', 'date' => '2024-01-13 02:00:01'],
    ];
}

function getSystemLogs($limit) {
    return [
        ['type' => 'INFO', 'message' => 'System backup completed successfully', 'timestamp' => '2024-01-15 02:05:12'],
        ['type' => 'WARNING', 'message' => 'High memory usage detected', 'timestamp' => '2024-01-15 01:30:45'],
        ['type' => 'ERROR', 'message' => 'Failed to send email notification', 'timestamp' => '2024-01-15 00:15:22'],
    ];
}

function getDatabaseSize() {
    return '850 MB';
}
?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-lg-2 sidebar-column">
            <?php include '../includes/sidebar.php'; ?>
        </div>
        
        <!-- Main Content -->
        <main class="">
            <!-- Page Header -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1 class="h2">
                        <i class="fas fa-cogs me-2"></i><?php echo $pageTitle; ?>
                    </h1>
                    <p class="lead mb-0"><?php echo $pageDescription; ?></p>
                </div>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="createBackupNow()">
                            <i class="fas fa-save me-1"></i> Backup Now
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="clearCache()">
                            <i class="fas fa-broom me-1"></i> Clear Cache
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#maintenanceModal">
                            <i class="fas fa-tools me-1"></i> Maintenance
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Flash Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- System Status Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        System Status
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <span class="badge bg-success">Operational</span>
                                    </div>
                                    <div class="mt-2 text-muted small">
                                        <i class="fas fa-clock me-1"></i> Uptime: <?php echo $systemStats['uptime']; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-server fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Resource Usage
                                    </div>
                                    <div class="row no-gutters align-items-center">
                                        <div class="col-auto">
                                            <div class="h5 mb-0 mr-3 font-weight-bold text-gray-800">
                                                <?php echo $systemStats['disk_usage']; ?>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="progress progress-sm mr-2">
                                                <div class="progress-bar bg-success" style="width: <?php echo str_replace('%', '', $systemStats['disk_usage']); ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <span class="badge bg-info me-1">CPU: <?php echo $systemStats['cpu_usage']; ?></span>
                                        <span class="badge bg-warning">RAM: <?php echo $systemStats['memory_usage']; ?></span>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-chart-pie fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                        Database
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo $dbSize; ?>
                                    </div>
                                    <div class="mt-2 text-muted small">
                                        <i class="fas fa-database me-1"></i> Total size
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-database fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                        Recent Backups
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo count($recentBackups); ?>
                                    </div>
                                    <div class="mt-2 text-muted small">
                                        <i class="fas fa-history me-1"></i> Last 5 backups
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-save fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Settings Tabs -->
            <div class="row">
                <div class="col-12">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <ul class="nav nav-tabs card-header-tabs" id="settingsTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="general-tab" data-bs-toggle="tab" 
                                            data-bs-target="#general" type="button" role="tab">
                                        <i class="fas fa-sliders-h me-2"></i>General
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="security-tab" data-bs-toggle="tab" 
                                            data-bs-target="#security" type="button" role="tab">
                                        <i class="fas fa-shield-alt me-2"></i>Security
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="email-tab" data-bs-toggle="tab" 
                                            data-bs-target="#email" type="button" role="tab">
                                        <i class="fas fa-envelope me-2"></i>Email
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="backup-tab" data-bs-toggle="tab" 
                                            data-bs-target="#backup" type="button" role="tab">
                                        <i class="fas fa-save me-2"></i>Backup
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tools-tab" data-bs-toggle="tab" 
                                            data-bs-target="#tools" type="button" role="tab">
                                        <i class="fas fa-tools me-2"></i>Tools
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="settingsTabContent">
                                
                                <!-- General Settings Tab -->
                                <div class="tab-pane fade show active" id="general" role="tabpanel">
                                    <form method="POST" id="generalSettingsForm">
                                        <input type="hidden" name="action" value="update_general_settings">
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="site_name" class="form-label">Site Name *</label>
                                                <input type="text" class="form-control" id="site_name" name="site_name" 
                                                       value="<?php echo SITE_NAME; ?>" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="items_per_page" class="form-label">Items Per Page</label>
                                                <input type="number" class="form-control" id="items_per_page" name="items_per_page" 
                                                       value="<?php echo ITEMS_PER_PAGE; ?>" min="5" max="100">
                                                <div class="form-text">Number of items to display per page in tables</div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="admin_email" class="form-label">Admin Email</label>
                                                <input type="email" class="form-control" id="admin_email" name="admin_email" 
                                                       value="<?php echo ADMIN_EMAIL; ?>">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="support_email" class="form-label">Support Email</label>
                                                <input type="email" class="form-control" id="support_email" name="support_email" 
                                                       value="<?php echo SUPPORT_EMAIL; ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="support_phone" class="form-label">Support Phone</label>
                                                <input type="tel" class="form-control" id="support_phone" name="support_phone" 
                                                       value="<?php echo SUPPORT_PHONE; ?>">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="timezone" class="form-label">Timezone</label>
                                                <select class="form-select" id="timezone" name="timezone">
                                                    <option value="Asia/Colombo" selected>Asia/Colombo (Sri Lanka)</option>
                                                    <option value="UTC">UTC</option>
                                                    <option value="America/New_York">America/New_York</option>
                                                    <option value="Europe/London">Europe/London</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="site_description" class="form-label">Site Description</label>
                                            <textarea class="form-control" id="site_description" name="site_description" rows="3"></textarea>
                                        </div>
                                        
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Note:</strong> Some settings may require a system restart to take effect.
                                        </div>
                                        
                                        <div class="d-flex justify-content-end">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i> Save General Settings
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Security Settings Tab -->
                                <div class="tab-pane fade" id="security" role="tabpanel">
                                    <form method="POST" id="securitySettingsForm">
                                        <input type="hidden" name="action" value="update_security_settings">
                                        
                                        <h6 class="mb-3">Session Settings</h6>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="session_timeout" class="form-label">Session Timeout (seconds)</label>
                                                <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                                                       value="3600" min="300" max="86400">
                                                <div class="form-text">Time before automatic logout (5 min to 24 hours)</div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="session_regenerate" class="form-label">Session Regeneration (seconds)</label>
                                                <input type="number" class="form-control" id="session_regenerate" name="session_regenerate" 
                                                       value="1800" min="300" max="3600">
                                                <div class="form-text">Time between session ID regeneration</div>
                                            </div>
                                        </div>
                                        
                                        <h6 class="mb-3 mt-4">Login Security</h6>
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <label for="max_login_attempts" class="form-label">Max Login Attempts</label>
                                                <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts" 
                                                       value="5" min="3" max="10">
                                                <div class="form-text">Failed attempts before lockout</div>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label for="login_lockout_time" class="form-label">Lockout Time (seconds)</label>
                                                <input type="number" class="form-control" id="login_lockout_time" name="login_lockout_time" 
                                                       value="900" min="300" max="3600">
                                                <div class="form-text">Account lockout duration</div>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label for="password_min_length" class="form-label">Minimum Password Length</label>
                                                <input type="number" class="form-control" id="password_min_length" name="password_min_length" 
                                                       value="8" min="6" max="32">
                                            </div>
                                        </div>
                                        
                                        <h6 class="mb-3 mt-4">Advanced Security</h6>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="enable_2fa" name="enable_2fa">
                                                    <label class="form-check-label" for="enable_2fa">
                                                        Enable Two-Factor Authentication
                                                    </label>
                                                    <div class="form-text">Require 2FA for admin users</div>
                                                </div>
                                                
                                                <div class="form-check form-switch mt-2">
                                                    <input class="form-check-input" type="checkbox" id="enable_ip_whitelist" name="enable_ip_whitelist">
                                                    <label class="form-check-label" for="enable_ip_whitelist">
                                                        Enable IP Whitelist
                                                    </label>
                                                    <div class="form-text">Restrict access to specific IP addresses</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="ip_whitelist" class="form-label">IP Whitelist</label>
                                                <textarea class="form-control" id="ip_whitelist" name="ip_whitelist" rows="3" 
                                                          placeholder="Enter one IP address per line&#10;192.168.1.1&#10;10.0.0.1"></textarea>
                                                <div class="form-text">Allowed IP addresses (one per line)</div>
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <strong>Warning:</strong> Changing security settings may affect user access. Test changes in a staging environment first.
                                        </div>
                                        
                                        <div class="d-flex justify-content-end">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i> Save Security Settings
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Email Settings Tab -->
                                <div class="tab-pane fade" id="email" role="tabpanel">
                                    <form method="POST" id="emailSettingsForm">
                                        <input type="hidden" name="action" value="update_email_settings">
                                        
                                        <h6 class="mb-3">SMTP Configuration</h6>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_host" class="form-label">SMTP Host *</label>
                                                <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                                       value="smtp.gmail.com" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_port" class="form-label">SMTP Port</label>
                                                <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                                       value="587" min="1" max="65535">
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_username" class="form-label">SMTP Username</label>
                                                <input type="text" class="form-control" id="smtp_username" name="smtp_username" 
                                                       value="noreply@fpms.lk">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_password" class="form-label">SMTP Password</label>
                                                <input type="password" class="form-control" id="smtp_password" name="smtp_password">
                                                <div class="form-text">Leave blank to keep current password</div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_secure" class="form-label">SMTP Security</label>
                                                <select class="form-select" id="smtp_secure" name="smtp_secure">
                                                    <option value="tls" selected>TLS</option>
                                                    <option value="ssl">SSL</option>
                                                    <option value="">None</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_timeout" class="form-label">SMTP Timeout (seconds)</label>
                                                <input type="number" class="form-control" id="smtp_timeout" name="smtp_timeout" 
                                                       value="30" min="5" max="300">
                                            </div>
                                        </div>
                                        
                                        <h6 class="mb-3 mt-4">Email Content</h6>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="from_name" class="form-label">From Name</label>
                                                <input type="text" class="form-control" id="from_name" name="from_name" 
                                                       value="<?php echo SITE_NAME; ?>">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="from_email" class="form-label">From Email</label>
                                                <input type="email" class="form-control" id="from_email" name="from_email" 
                                                       value="noreply@fpms.lk">
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="test_email" name="test_email">
                                                <label class="form-check-label" for="test_email">
                                                    Send test email after saving
                                                </label>
                                                <div class="form-text">Test email will be sent to: <?php echo ADMIN_EMAIL; ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between">
                                            <button type="button" class="btn btn-outline-info" onclick="testEmailConfig()">
                                                <i class="fas fa-paper-plane me-1"></i> Test Configuration
                                            </button>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i> Save Email Settings
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Backup Settings Tab -->
                                <div class="tab-pane fade" id="backup" role="tabpanel">
                                    <form method="POST" id="backupSettingsForm">
                                        <input type="hidden" name="action" value="update_backup_settings">
                                        
                                        <h6 class="mb-3">Automatic Backups</h6>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="auto_backup" name="auto_backup" checked>
                                                    <label class="form-check-label" for="auto_backup">
                                                        Enable Automatic Backups
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="backup_time" class="form-label">Backup Time (24-hour)</label>
                                                <input type="time" class="form-control" id="backup_time" name="backup_time" value="02:00">
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="backup_retention" class="form-label">Backup Retention (days)</label>
                                                <input type="number" class="form-control" id="backup_retention" name="backup_retention" 
                                                       value="30" min="1" max="365">
                                                <div class="form-text">Number of days to keep backups</div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="backup_location" class="form-label">Backup Location</label>
                                                <input type="text" class="form-control" id="backup_location" name="backup_location" 
                                                       value="/var/backups/fpms/">
                                                <div class="form-text">Directory to store backup files</div>
                                            </div>
                                        </div>
                                        
                                        <h6 class="mb-3 mt-4">Backup Options</h6>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="backup_database" name="backup_database" checked>
                                                    <label class="form-check-label" for="backup_database">
                                                        Backup Database
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="backup_files" name="backup_files">
                                                    <label class="form-check-label" for="backup_files">
                                                        Backup Uploaded Files
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="backup_logs" name="backup_logs">
                                                    <label class="form-check-label" for="backup_logs">
                                                        Backup System Logs
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="enable_cloud_backup" name="enable_cloud_backup">
                                                    <label class="form-check-label" for="enable_cloud_backup">
                                                        Enable Cloud Backup
                                                    </label>
                                                </div>
                                                <div class="mt-2">
                                                    <label for="cloud_provider" class="form-label">Cloud Provider</label>
                                                    <select class="form-select" id="cloud_provider" name="cloud_provider" disabled>
                                                        <option value="">Select provider</option>
                                                        <option value="aws">Amazon S3</option>
                                                        <option value="google">Google Cloud Storage</option>
                                                        <option value="azure">Microsoft Azure</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <h6 class="mb-3 mt-4">Recent Backups</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Filename</th>
                                                        <th>Size</th>
                                                        <th>Date</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recentBackups as $backup): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                                                        <td><?php echo htmlspecialchars($backup['size']); ?></td>
                                                        <td><?php echo htmlspecialchars($backup['date']); ?></td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                                    onclick="downloadBackup('<?php echo $backup['filename']; ?>')">
                                                                <i class="fas fa-download"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                    onclick="deleteBackup('<?php echo $backup['filename']; ?>')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <div class="d-flex justify-content-end mt-3">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i> Save Backup Settings
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Tools Tab -->
                                <div class="tab-pane fade" id="tools" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6 mb-4">
                                            <div class="card">
                                                <div class="card-header bg-info text-white">
                                                    <h6 class="card-title mb-0">
                                                        <i class="fas fa-broom me-2"></i>System Maintenance
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <p class="card-text">Perform system maintenance tasks to optimize performance.</p>
                                                    
                                                    <form method="POST" class="mb-3">
                                                        <input type="hidden" name="action" value="clear_cache">
                                                        <button type="submit" class="btn btn-warning w-100">
                                                            <i class="fas fa-broom me-1"></i> Clear System Cache
                                                        </button>
                                                    </form>
                                                    
                                                    <form method="POST" class="mb-3">
                                                        <input type="hidden" name="action" value="rebuild_indexes">
                                                        <button type="submit" class="btn btn-info w-100">
                                                            <i class="fas fa-database me-1"></i> Rebuild Database Indexes
                                                        </button>
                                                    </form>
                                                    
                                                    <form method="POST">
                                                        <input type="hidden" name="action" value="create_backup">
                                                        <button type="submit" class="btn btn-success w-100">
                                                            <i class="fas fa-save me-1"></i> Create Manual Backup
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6 mb-4">
                                            <div class="card">
                                                <div class="card-header bg-warning text-dark">
                                                    <h6 class="card-title mb-0">
                                                        <i class="fas fa-exclamation-triangle me-2"></i>System Logs
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="table-responsive">
                                                        <table class="table table-sm">
                                                            <thead>
                                                                <tr>
                                                                    <th>Type</th>
                                                                    <th>Message</th>
                                                                    <th>Time</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($systemLogs as $log): ?>
                                                                <tr>
                                                                    <td>
                                                                        <span class="badge bg-<?php 
                                                                            echo $log['type'] == 'ERROR' ? 'danger' : 
                                                                                 ($log['type'] == 'WARNING' ? 'warning' : 'info'); 
                                                                        ?>">
                                                                            <?php echo htmlspecialchars($log['type']); ?>
                                                                        </span>
                                                                    </td>
                                                                    <td class="small"><?php echo htmlspecialchars($log['message']); ?></td>
                                                                    <td class="small"><?php echo htmlspecialchars($log['timestamp']); ?></td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <a href="system_logs.php" class="btn btn-outline-primary btn-sm w-100 mt-2">
                                                        <i class="fas fa-external-link-alt me-1"></i> View All Logs
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card">
                                        <div class="card-header bg-danger text-white">
                                            <h6 class="card-title mb-0">
                                                <i class="fas fa-skull-crossbones me-2"></i>Danger Zone
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <p class="card-text text-danger">
                                                <i class="fas fa-exclamation-circle me-1"></i>
                                                <strong>Warning:</strong> These actions are irreversible and may cause system downtime.
                                            </p>
                                            
                                            <div class="row">
                                                <div class="col-md-4 mb-3">
                                                    <button type="button" class="btn btn-outline-danger w-100" 
                                                            onclick="resetSystem()">
                                                        <i class="fas fa-redo me-1"></i> Reset System
                                                    </button>
                                                    <div class="form-text small">Reset all system settings to default</div>
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <button type="button" class="btn btn-outline-danger w-100" 
                                                            onclick="purgeOldData()">
                                                        <i class="fas fa-trash-alt me-1"></i> Purge Old Data
                                                    </button>
                                                    <div class="form-text small">Delete data older than 2 years</div>
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <button type="button" class="btn btn-outline-danger w-100" 
                                                            onclick="exportAllData()">
                                                        <i class="fas fa-file-export me-1"></i> Export All Data
                                                    </button>
                                                    <div class="form-text small">Export complete database</div>
                                                </div>
                                            </div>
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

<!-- Maintenance Mode Modal -->
<div class="modal fade" id="maintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="maintenanceForm">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">
                        <i class="fas fa-tools me-2"></i>Maintenance Mode
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="toggle_maintenance">
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="maintenance_mode" name="maintenance_mode">
                            <label class="form-check-label" for="maintenance_mode">
                                <strong>Enable Maintenance Mode</strong>
                            </label>
                        </div>
                        <div class="form-text">
                            When enabled, only administrators can access the system.
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="maintenance_message" class="form-label">Maintenance Message</label>
                        <textarea class="form-control" id="maintenance_message" name="maintenance_message" rows="3"
                                  placeholder="System is currently undergoing maintenance. Please check back later."></textarea>
                        <div class="form-text">This message will be displayed to users.</div>
                    </div>
                    
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important:</strong> Notify users before enabling maintenance mode.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Include Footer -->
<?php include '../includes/footer.php'; ?>

<!-- JavaScript -->
<script>
$(document).ready(function() {
    // Initialize tab functionality
    const triggerTabList = document.querySelectorAll('#settingsTabs button');
    triggerTabList.forEach(triggerEl => {
        const tabTrigger = new bootstrap.Tab(triggerEl);
        triggerEl.addEventListener('click', event => {
            event.preventDefault();
            tabTrigger.show();
        });
    });
    
    // Enable/disable cloud provider select
    $('#enable_cloud_backup').change(function() {
        $('#cloud_provider').prop('disabled', !$(this).is(':checked'));
    });
});

// Functions for various actions
function createBackupNow() {
    if (confirm('Create a manual backup now? This may take several minutes.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="create_backup">';
        document.body.appendChild(form);
        form.submit();
    }
}

function clearCache() {
    if (confirm('Clear all system cache? This may temporarily affect performance.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="clear_cache">';
        document.body.appendChild(form);
        form.submit();
    }
}

function testEmailConfig() {
    alert('This would test the email configuration in a real system.');
    // In a real system: fetch('test_email.php').then(...)
}

function downloadBackup(filename) {
    if (confirm('Download backup file: ' + filename + '?')) {
        window.location.href = 'download_backup.php?file=' + encodeURIComponent(filename);
    }
}

function deleteBackup(filename) {
    if (confirm('Delete backup file: ' + filename + '?\nThis action cannot be undone.')) {
        fetch('delete_backup.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ filename: filename })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Backup deleted successfully');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}

function resetSystem() {
    if (confirm('WARNING: This will reset ALL system settings to defaults.\n\nAre you absolutely sure?')) {
        if (prompt('Type "RESET" to confirm:') === 'RESET') {
            alert('System reset initiated. This would reset the system in a real implementation.');
            // In real system: window.location.href = 'reset_system.php';
        }
    }
}

function purgeOldData() {
    if (confirm('Delete all data older than 2 years?\n\nThis action cannot be undone.')) {
        if (prompt('Type "PURGE" to confirm:') === 'PURGE') {
            alert('Data purge initiated. This would delete old data in a real implementation.');
            // In real system: window.location.href = 'purge_data.php';
        }
    }
}

function exportAllData() {
    if (confirm('Export complete database? This may take several minutes.')) {
        window.location.href = 'export_all_data.php';
    }
}

// Form validation
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Basic validation
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    });
});

// Auto-save feature for settings
let autoSaveTimer;
function scheduleAutoSave(formId) {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(() => {
        document.getElementById(formId).submit();
    }, 30000); // Auto-save after 30 seconds of inactivity
}

// Add change listeners to forms
['generalSettingsForm', 'securitySettingsForm', 'emailSettingsForm', 'backupSettingsForm'].forEach(formId => {
    const form = document.getElementById(formId);
    if (form) {
        form.addEventListener('change', () => scheduleAutoSave(formId));
    }
});
</script>

<!-- CSS Styles -->
<style>
.nav-tabs .nav-link {
    color: #495057;
    border: none;
    padding: 0.75rem 1rem;
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom: 3px solid #0d6efd;
    background-color: rgba(13, 110, 253, 0.05);
}

.card-header-tabs {
    margin-bottom: -1rem;
    border-bottom: 0;
}

.form-switch .form-check-input {
    width: 3em;
    height: 1.5em;
}

.form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

.progress {
    height: 0.5rem;
}

.badge {
    font-size: 0.8em;
    padding: 0.4em 0.8em;
}

.table-sm th, .table-sm td {
    padding: 0.5rem;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
}

.modal-header.bg-warning {
    background-color: #ffc107 !important;
}

.alert pre {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    border-left: 3px solid #dee2e6;
    font-size: 0.9em;
    margin-top: 10px;
}

.form-text {
    font-size: 0.85em;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .nav-tabs .nav-link {
        padding: 0.5rem;
        font-size: 0.9rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .btn {
        padding: 0.375rem 0.75rem;
        font-size: 0.9rem;
    }
}
</style>
</body>
</html>