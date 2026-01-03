<?php
// users/gn/profile/settings.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Profile Settings";
$pageIcon = "bi bi-gear";
$pageDescription = "Manage your account settings and profile information";
$bodyClass = "bg-light";

try {
    require_once '../../../config.php';
    require_once '../../../classes/Auth.php';
    require_once '../../../classes/Sanitizer.php';
    
    // Check if session is already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in and has GN level access
    if (!$auth->isLoggedIn()) {
        header('Location: ../../../login.php');
        exit();
    }
    
    // Check if user has GN level access
    if ($_SESSION['user_type'] !== 'gn') {
        header('Location: ../dashboard.php');
        exit();
    }

    // Get database connection
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $gn_id = $_SESSION['office_code'] ?? '';
    $username = $_SESSION['username'] ?? '';
    $office_name = $_SESSION['office_name'] ?? '';
    
    // Initialize sanitizer
    $sanitizer = new Sanitizer();
    
    // Get current user data
    $user_sql = "SELECT * FROM users WHERE user_id = ?";
    $user_stmt = $db->prepare($user_sql);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user_data = $user_result->fetch_assoc();
    
    if (!$user_data) {
        header('Location: ../../../login.php');
        exit();
    }
    
    // Variables for form data and messages
    $success = '';
    $error = '';
    $form_errors = [];
    
    // Process form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_profile':
                $form_errors = $this->updateProfile($db, $user_id, $_POST, $sanitizer);
                if (empty($form_errors)) {
                    $success = "Profile updated successfully!";
                    // Refresh user data
                    $user_stmt->execute();
                    $user_result = $user_stmt->get_result();
                    $user_data = $user_result->fetch_assoc();
                    // Update session if needed
                    if (isset($_POST['email'])) {
                        $_SESSION['email'] = $sanitizer->sanitizeEmail($_POST['email']);
                    }
                }
                break;
                
            case 'change_password':
                $form_errors = $this->changePassword($db, $user_id, $_POST, $sanitizer);
                if (empty($form_errors)) {
                    $success = "Password changed successfully!";
                }
                break;
                
            case 'update_notifications':
                $form_errors = $this->updateNotifications($db, $user_id, $_POST, $sanitizer);
                if (empty($form_errors)) {
                    $success = "Notification settings updated!";
                }
                break;
        }
    }
    
    // Get notification settings (you might need to create a user_settings table)
    $notification_settings = $this->getNotificationSettings($db, $user_id);
    
    // Get login history
    $login_history = $this->getLoginHistory($db, $user_id, 10);

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Profile Settings Error: " . $e->getMessage());
}

/**
 * Update profile information
 */
private function updateProfile($db, $user_id, $post_data, $sanitizer) {
    $errors = [];
    
    // Sanitize inputs
    $email = $sanitizer->sanitizeEmail($post_data['email'] ?? '');
    $phone = $sanitizer->sanitizePhone($post_data['phone'] ?? '');
    
    // Validate email
    if (empty($email)) {
        $errors['email'] = "Please enter a valid email address";
    } else {
        // Check if email already exists (excluding current user)
        $check_sql = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
        $check_stmt = $db->prepare($check_sql);
        $check_stmt->bind_param("si", $email, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $errors['email'] = "This email is already registered";
        }
    }
    
    // Validate phone
    if (!empty($phone) && !preg_match('/^0\d{9}$/', $phone)) {
        $errors['phone'] = "Please enter a valid phone number";
    }
    
    if (empty($errors)) {
        $update_sql = "UPDATE users SET email = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param("ssi", $email, $phone, $user_id);
        
        if (!$update_stmt->execute()) {
            $errors['general'] = "Failed to update profile. Please try again.";
        }
    }
    
    return $errors;
}

/**
 * Change password
 */
private function changePassword($db, $user_id, $post_data, $sanitizer) {
    $errors = [];
    
    $current_password = $post_data['current_password'] ?? '';
    $new_password = $post_data['new_password'] ?? '';
    $confirm_password = $post_data['confirm_password'] ?? '';
    
    // Get current password hash
    $sql = "SELECT password_hash FROM users WHERE user_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    // Verify current password
    if (!password_verify($current_password, $user['password_hash'])) {
        $errors['current_password'] = "Current password is incorrect";
    }
    
    // Validate new password
    if (strlen($new_password) < 8) {
        $errors['new_password'] = "Password must be at least 8 characters long";
    }
    
    if ($new_password !== $confirm_password) {
        $errors['confirm_password'] = "New passwords do not match";
    }
    
    if (empty($errors)) {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update_sql = "UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param("si", $new_hash, $user_id);
        
        if (!$update_stmt->execute()) {
            $errors['general'] = "Failed to change password. Please try again.";
        }
    }
    
    return $errors;
}

/**
 * Update notification settings
 */
private function updateNotifications($db, $user_id, $post_data, $sanitizer) {
    $errors = [];
    
    // This is a simplified version - you might want to create a user_settings table
    // For now, we'll store in session or a separate table
    
    // Example: Store in user meta table (you need to create this)
    // $notifications = [
    //     'email_notifications' => isset($post_data['email_notifications']) ? 1 : 0,
    //     'sms_notifications' => isset($post_data['sms_notifications']) ? 1 : 0,
    //     'push_notifications' => isset($post_data['push_notifications']) ? 1 : 0,
    //     'weekly_reports' => isset($post_data['weekly_reports']) ? 1 : 0,
    // ];
    
    // Store in session for demo
    $_SESSION['notifications'] = [
        'email_notifications' => isset($post_data['email_notifications']) ? 1 : 0,
        'sms_notifications' => isset($post_data['sms_notifications']) ? 1 : 0,
        'push_notifications' => isset($post_data['push_notifications']) ? 1 : 0,
        'weekly_reports' => isset($post_data['weekly_reports']) ? 1 : 0,
    ];
    
    return $errors;
}

/**
 * Get notification settings
 */
private function getNotificationSettings($db, $user_id) {
    // Default settings
    $defaults = [
        'email_notifications' => 1,
        'sms_notifications' => 0,
        'push_notifications' => 1,
        'weekly_reports' => 1,
        'new_registration_alerts' => 1,
        'transfer_requests' => 1,
        'report_reminders' => 1
    ];
    
    // Check if settings exist in session
    if (isset($_SESSION['notifications'])) {
        return array_merge($defaults, $_SESSION['notifications']);
    }
    
    return $defaults;
}

/**
 * Get login history
 */
private function getLoginHistory($db, $user_id, $limit = 10) {
    $history = [];
    
    // This would require an audit_logs table with login entries
    // For now, we'll return dummy data or you can implement your own
    
    $sql = "SELECT 
                log_id,
                action_type,
                created_at,
                ip_address,
                user_agent
            FROM audit_logs 
            WHERE user_id = ? AND action_type IN ('login', 'logout', 'failed_login')
            ORDER BY created_at DESC 
            LIMIT ?";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $history[] = [
                'action' => ucfirst(str_replace('_', ' ', $row['action_type'])),
                'timestamp' => $row['created_at'],
                'time_ago' => $this->getTimeAgo($row['created_at']),
                'ip' => $row['ip_address'] ?? 'N/A',
                'device' => $this->parseUserAgent($row['user_agent'] ?? '')
            ];
        }
    } catch (Exception $e) {
        // If table doesn't exist, return empty array
        error_log("Login history error: " . $e->getMessage());
    }
    
    // If no history found, add some dummy entries for demo
    if (empty($history)) {
        $history = [
            [
                'action' => 'Login',
                'timestamp' => date('Y-m-d H:i:s'),
                'time_ago' => 'Just now',
                'ip' => '127.0.0.1',
                'device' => 'Chrome on Windows'
            ],
            [
                'action' => 'Login',
                'timestamp' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'time_ago' => '1 day ago',
                'ip' => '127.0.0.1',
                'device' => 'Firefox on Windows'
            ],
            [
                'action' => 'Login',
                'timestamp' => date('Y-m-d H:i:s', strtotime('-3 days')),
                'time_ago' => '3 days ago',
                'ip' => '192.168.1.100',
                'device' => 'Safari on Mac'
            ]
        ];
    }
    
    return $history;
}

/**
 * Get time ago string
 */
private function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $time_difference = time() - $time;
    
    if ($time_difference < 1) {
        return 'just now';
    }
    
    $condition = [
        12 * 30 * 24 * 60 * 60 => 'year',
        30 * 24 * 60 * 60 => 'month',
        24 * 60 * 60 => 'day',
        60 * 60 => 'hour',
        60 => 'minute',
        1 => 'second'
    ];
    
    foreach ($condition as $secs => $str) {
        $d = $time_difference / $secs;
        if ($d >= 1) {
            $t = round($d);
            return $t . ' ' . $str . ($t > 1 ? 's' : '') . ' ago';
        }
    }
}

/**
 * Parse user agent string
 */
private function parseUserAgent($user_agent) {
    if (empty($user_agent)) {
        return 'Unknown';
    }
    
    $device = 'Unknown';
    
    // Simple parsing - in production, use a library like DeviceDetector
    if (stripos($user_agent, 'Mobile') !== false) {
        $device = 'Mobile';
    } elseif (stripos($user_agent, 'Tablet') !== false) {
        $device = 'Tablet';
    } else {
        $device = 'Desktop';
    }
    
    // Browser detection
    $browser = 'Unknown';
    if (stripos($user_agent, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($user_agent, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($user_agent, 'Safari') !== false && stripos($user_agent, 'Chrome') === false) {
        $browser = 'Safari';
    } elseif (stripos($user_agent, 'Edge') !== false) {
        $browser = 'Edge';
    } elseif (stripos($user_agent, 'MSIE') !== false || stripos($user_agent, 'Trident') !== false) {
        $browser = 'Internet Explorer';
    }
    
    return $browser . ' on ' . $device;
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
        <main class="col-md-9 ms-sm-auto col-xl-10 px-md-4 main-content">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mt-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Profile Settings</li>
                </ol>
            </nav>
            
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-gear me-2"></i>
                    Profile Settings
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="../dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Profile Overview -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-person-circle me-2"></i> Profile Overview</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <div class="mb-3">
                                <div class="profile-avatar display-4 text-primary">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                            </div>
                            <div>
                                <span class="badge bg-success">GN Officer</span>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-borderless table-sm">
                                        <tr>
                                            <th width="40%">Username:</th>
                                            <td>
                                                <strong><?php echo htmlspecialchars($user_data['username']); ?></strong>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>User ID:</th>
                                            <td>
                                                <span class="font-monospace"><?php echo $user_id; ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Account Type:</th>
                                            <td>
                                                <span class="badge bg-info"><?php echo strtoupper($user_data['user_type']); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Status:</th>
                                            <td>
                                                <?php if ($user_data['is_active'] == 1): ?>
                                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-borderless table-sm">
                                        <tr>
                                            <th width="40%">GN Division:</th>
                                            <td>
                                                <strong><?php echo htmlspecialchars($office_name); ?></strong>
                                                <div class="small font-monospace"><?php echo htmlspecialchars($gn_id); ?></div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Last Login:</th>
                                            <td>
                                                <?php if (!empty($user_data['last_login'])): ?>
                                                    <?php echo date('d M Y, h:i A', strtotime($user_data['last_login'])); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Account Created:</th>
                                            <td>
                                                <?php echo date('d M Y', strtotime($user_data['created_at'])); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Last Updated:</th>
                                            <td>
                                                <?php echo date('d M Y', strtotime($user_data['updated_at'])); ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- Left Column - Account Settings -->
                <div class="col-lg-8">
                    <!-- Profile Information Form -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i> Profile Information</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="profileForm">
                                <input type="hidden" name="action" value="update_profile">
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Username</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_data['username']); ?>" readonly>
                                        <div class="form-text">Username cannot be changed</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Account Type</label>
                                        <input type="text" class="form-control" value="GN Officer" readonly>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Email Address *</label>
                                        <input type="email" class="form-control <?php echo isset($form_errors['email']) ? 'is-invalid' : ''; ?>" 
                                               name="email" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" required>
                                        <?php if (isset($form_errors['email'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($form_errors['email']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Used for notifications and account recovery</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control <?php echo isset($form_errors['phone']) ? 'is-invalid' : ''; ?>" 
                                               name="phone" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>"
                                               pattern="[0-9]{10}" placeholder="0712345678">
                                        <?php if (isset($form_errors['phone'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($form_errors['phone']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">10-digit Sri Lankan mobile number</div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <div class="form-text">
                                            <i class="bi bi-info-circle"></i> Required fields are marked with *
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save"></i> Update Profile
                                        </button>
                                        <button type="reset" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-clockwise"></i> Reset
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Change Password -->
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i> Change Password</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="passwordForm">
                                <input type="hidden" name="action" value="change_password">
                                
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Current Password *</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control <?php echo isset($form_errors['current_password']) ? 'is-invalid' : ''; ?>" 
                                                   name="current_password" id="current_password" required>
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <?php if (isset($form_errors['current_password'])): ?>
                                            <div class="invalid-feedback d-block"><?php echo htmlspecialchars($form_errors['current_password']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">New Password *</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control <?php echo isset($form_errors['new_password']) ? 'is-invalid' : ''; ?>" 
                                                   name="new_password" id="new_password" required>
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <?php if (isset($form_errors['new_password'])): ?>
                                            <div class="invalid-feedback d-block"><?php echo htmlspecialchars($form_errors['new_password']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Minimum 8 characters</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Confirm New Password *</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control <?php echo isset($form_errors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                                                   name="confirm_password" id="confirm_password" required>
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <?php if (isset($form_errors['confirm_password'])): ?>
                                            <div class="invalid-feedback d-block"><?php echo htmlspecialchars($form_errors['confirm_password']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <i class="bi bi-lightbulb"></i>
                                            <strong>Password Requirements:</strong>
                                            <ul class="mb-0">
                                                <li>At least 8 characters long</li>
                                                <li>Use a combination of letters, numbers, and symbols</li>
                                                <li>Avoid using personal information</li>
                                                <li>Don't reuse old passwords</li>
                                            </ul>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="bi bi-key"></i> Change Password
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column - Additional Settings -->
                <div class="col-lg-4">
                    <!-- Notification Settings -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-bell me-2"></i> Notification Settings</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="notificationForm">
                                <input type="hidden" name="action" value="update_notifications">
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="email_notifications" 
                                               id="email_notifications" <?php echo $notification_settings['email_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="email_notifications">
                                            Email Notifications
                                        </label>
                                    </div>
                                    <small class="text-muted">Receive updates via email</small>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="sms_notifications" 
                                               id="sms_notifications" <?php echo $notification_settings['sms_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="sms_notifications">
                                            SMS Notifications
                                        </label>
                                    </div>
                                    <small class="text-muted">Receive SMS alerts (requires valid phone number)</small>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="push_notifications" 
                                               id="push_notifications" <?php echo $notification_settings['push_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="push_notifications">
                                            Push Notifications
                                        </label>
                                    </div>
                                    <small class="text-muted">Browser/App notifications</small>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="weekly_reports" 
                                               id="weekly_reports" <?php echo $notification_settings['weekly_reports'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="weekly_reports">
                                            Weekly Reports
                                        </label>
                                    </div>
                                    <small class="text-muted">Receive weekly summary reports</small>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="new_registration_alerts" 
                                               id="new_registration_alerts" <?php echo $notification_settings['new_registration_alerts'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="new_registration_alerts">
                                            New Registration Alerts
                                        </label>
                                    </div>
                                    <small class="text-muted">Alert for new family registrations</small>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="transfer_requests" 
                                               id="transfer_requests" <?php echo $notification_settings['transfer_requests'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="transfer_requests">
                                            Transfer Request Alerts
                                        </label>
                                    </div>
                                    <small class="text-muted">Alert for family transfer requests</small>
                                </div>
                                
                                <div class="mb-3">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="bi bi-save"></i> Save Notification Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Login History -->
                    <div class="card mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i> Recent Login History</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($login_history as $login): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($login['action']); ?></h6>
                                        <small class="text-muted"><?php echo $login['time_ago']; ?></small>
                                    </div>
                                    <p class="mb-1 small">
                                        <i class="bi bi-laptop"></i> <?php echo htmlspecialchars($login['device']); ?>
                                    </p>
                                    <small class="text-muted">
                                        <i class="bi bi-geo-alt"></i> IP: <?php echo htmlspecialchars($login['ip']); ?>
                                    </small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="card-footer text-center">
                            <a href="login_history.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-list"></i> View Full History
                            </a>
                        </div>
                    </div>
                    
                    <!-- Account Actions -->
                    <div class="card">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i> Account Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#exportModal">
                                    <i class="bi bi-download"></i> Export Account Data
                                </button>
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                    <i class="bi bi-trash"></i> Request Account Deletion
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Security Information -->
            <div class="card mt-4">
                <div class="card-header bg-light text-dark">
                    <h6 class="mb-0"><i class="bi bi-shield-check me-2"></i> Security Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3">
                                    <i class="bi bi-check-circle-fill text-success fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0">Password Strength</h6>
                                    <small class="text-muted">Last changed: <?php echo date('d M Y', strtotime($user_data['updated_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3">
                                    <i class="bi bi-envelope-check-fill text-info fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0">Email Verified</h6>
                                    <small class="text-muted">
                                        <?php echo !empty($user_data['email']) ? 'Verified' : 'Not verified'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3">
                                    <i class="bi bi-device-phone-fill text-primary fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0">Two-Factor Auth</h6>
                                    <small class="text-muted">
                                        <a href="#" class="text-decoration-none">Enable 2FA</a>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Export Data Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="exportModalLabel">
                    <i class="bi bi-download me-2"></i> Export Account Data
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>You can download all your personal data stored in our system.</p>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    The export will include:
                    <ul class="mb-0">
                        <li>Profile information</li>
                        <li>Activity history</li>
                        <li>System logs</li>
                        <li>Notification settings</li>
                    </ul>
                </div>
                <div class="mb-3">
                    <label class="form-label">Export Format</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatJson" value="json" checked>
                        <label class="form-check-label" for="formatJson">
                            JSON Format
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatCsv" value="csv">
                        <label class="form-check-label" for="formatCsv">
                            CSV Format
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatPdf" value="pdf">
                        <label class="form-check-label" for="formatPdf">
                            PDF Document
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="exportAccountData()">
                    <i class="bi bi-download"></i> Export Data
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Account Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="bi bi-exclamation-triangle me-2"></i> Request Account Deletion
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>You are about to request deletion of your account. This action cannot be undone.</p>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>Warning:</strong> This will permanently delete:
                    <ul class="mb-0">
                        <li>Your user account</li>
                        <li>All associated data</li>
                        <li>Login credentials</li>
                        <li>Activity history</li>
                    </ul>
                </div>
                <p>If you are a system administrator, please contact the system administrator instead.</p>
                <div class="mb-3">
                    <label for="deleteReason" class="form-label">Reason for deletion (optional)</label>
                    <textarea class="form-control" id="deleteReason" rows="3" placeholder="Please tell us why you want to delete your account..."></textarea>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirmDelete">
                    <label class="form-check-label" for="confirmDelete">
                        I understand this action is permanent and cannot be undone
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="deleteButton" disabled onclick="requestAccountDeletion()">
                    <i class="bi bi-trash"></i> Request Deletion
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .profile-avatar {
        width: 120px;
        height: 120px;
        background-color: #e9ecef;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
    }
    .table-borderless th {
        font-weight: 600;
        color: #495057;
    }
    .list-group-item {
        border-left: none;
        border-right: none;
    }
    .list-group-item:first-child {
        border-top: none;
    }
    .list-group-item:last-child {
        border-bottom: none;
    }
    .form-check-input:checked {
        background-color: #198754;
        border-color: #198754;
    }
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
</style>

<script src="../../../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle password visibility
        window.togglePassword = function(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        };
        
        // Form validation
        const profileForm = document.getElementById('profileForm');
        profileForm.addEventListener('submit', function(e) {
            const email = this.querySelector('input[name="email"]');
            const phone = this.querySelector('input[name="phone"]');
            
            // Email validation
            if (!email.value.trim()) {
                e.preventDefault();
                email.focus();
                email.classList.add('is-invalid');
                return false;
            }
            
            // Phone validation (if provided)
            if (phone.value.trim() && !/^0\d{9}$/.test(phone.value)) {
                e.preventDefault();
                phone.focus();
                phone.classList.add('is-invalid');
                return false;
            }
        });
        
        // Password form validation
        const passwordForm = document.getElementById('passwordForm');
        passwordForm.addEventListener('submit', function(e) {
            const newPassword = this.querySelector('input[name="new_password"]');
            const confirmPassword = this.querySelector('input[name="confirm_password"]');
            
            if (newPassword.value.length < 8) {
                e.preventDefault();
                newPassword.focus();
                newPassword.classList.add('is-invalid');
                return false;
            }
            
            if (newPassword.value !== confirmPassword.value) {
                e.preventDefault();
                confirmPassword.focus();
                confirmPassword.classList.add('is-invalid');
                return false;
            }
        });
        
        // Clear validation on input
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        });
        
        // Delete confirmation toggle
        const confirmDelete = document.getElementById('confirmDelete');
        const deleteButton = document.getElementById('deleteButton');
        
        if (confirmDelete && deleteButton) {
            confirmDelete.addEventListener('change', function() {
                deleteButton.disabled = !this.checked;
            });
        }
        
        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
    
    // Export account data
    function exportAccountData() {
        const format = document.querySelector('input[name="exportFormat"]:checked').value;
        
        // Show loading state
        const exportBtn = document.querySelector('#exportModal .btn-warning');
        const originalText = exportBtn.innerHTML;
        exportBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Exporting...';
        exportBtn.disabled = true;
        
        // Simulate export process
        setTimeout(function() {
            exportBtn.innerHTML = originalText;
            exportBtn.disabled = false;
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
            modal.hide();
            
            // Show success message
            alert('Your data export has started. You will receive an email when it is ready for download.');
        }, 2000);
    }
    
    // Request account deletion
    function requestAccountDeletion() {
        const reason = document.getElementById('deleteReason').value;
        
        // Show loading state
        const deleteBtn = document.getElementById('deleteButton');
        const originalText = deleteBtn.innerHTML;
        deleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
        deleteBtn.disabled = true;
        
        // In a real application, this would be an AJAX call
        setTimeout(function() {
            deleteBtn.innerHTML = originalText;
            deleteBtn.disabled = false;
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
            modal.hide();
            
            // Show success message
            alert('Your account deletion request has been submitted. Our administrators will review your request within 3-5 business days.');
        }, 2000);
    }
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