<?php
/**
 * sidebar.php - Sidebar navigation for FPMS
 * Updated version with all links visible (no dropdowns)
 */

// Determine the base path based on where this file is included from
$currentDir = dirname(__FILE__);
$rootDir = dirname($currentDir);

// Define the correct paths
$configPath = $rootDir . '/config.php';
$authClassPath = $rootDir . '/classes/Auth.php';
$formManagerPath = $rootDir . '/classes/FormManager.php';

// Check and load files with better error handling
if (!file_exists($configPath)) {
    $configPath = dirname($rootDir) . '/config.php';
    if (!file_exists($configPath)) {
        $configPath = '../../config.php';
        if (!file_exists($configPath)) {
            die("Configuration file not found. Please check the file paths.");
        }
    }
}

// Load config file
require_once $configPath;

// Initialize Auth if not already initialized
if (!isset($auth)) {
    if (!class_exists('Auth')) {
        if (file_exists($authClassPath)) {
            require_once $authClassPath;
        } else {
            $authClassPath = dirname($rootDir) . '/classes/Auth.php';
            if (file_exists($authClassPath)) {
                require_once $authClassPath;
            } else {
                die("Auth class not found. Please check the installation.");
            }
        }
    }
    $auth = new Auth();
}

// Get current user
$currentUser = $auth->getCurrentUser();

// If no user is logged in, show basic sidebar
if (!$currentUser) {
    ?>
    <style>
    .modern-sidebar {
        width: var(--sidebar-width);
        background: #f8fafc;
        color: #1e293b;
        height: calc(100vh - var(--header-height));
        overflow-y: auto;
        border-right: 1px solid #e2e8f0;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        box-shadow: 4px 0 20px rgba(0,0,0,0.04);
        position: fixed;
        top: var(--header-height);
        left: 0;
        z-index: 1020;
        transition: transform 0.3s ease;
        padding: 20px;
    }
    
    .login-message {
        text-align: center;
        padding: 40px 20px;
    }
    
    .login-message i {
        font-size: 48px;
        color: #6366f1;
        margin-bottom: 20px;
    }
    
    .login-message h5 {
        color: #1e293b;
        margin-bottom: 10px;
    }
    
    .login-message p {
        color: #64748b;
        font-size: 14px;
    }
    </style>
    
    <div class="modern-sidebar">
        <div class="login-message">
            <i class="fas fa-sign-in-alt"></i>
            <h5>Please Login</h5>
            <p>You need to login to access the system features.</p>
        </div>
    </div>
    <?php
    return;
}

// Get current URL for active link highlighting
$currentUrl = $_SERVER['PHP_SELF']; 

// Initialize FormManager for form statistics (only if needed)
$formStats = null;
if ($currentUser['user_type'] === 'gn') {
    if (!class_exists('FormManager')) {
        if (file_exists($formManagerPath)) {
            require_once $formManagerPath;
        } else {
            $formManagerPath = dirname($rootDir) . '/classes/FormManager.php';
            if (file_exists($formManagerPath)) {
                require_once $formManagerPath;
            }
        }
    }
    
    if (class_exists('FormManager')) {
        try {
            $formManager = new FormManager();
            $formStats = $formManager->getUserFormStats($currentUser['user_id']);
        } catch (Exception $e) {
            error_log('Error loading form stats: ' . $e->getMessage());
            $formStats = null;
        }
    }
}

// Get GN statistics for GN users
$gnStats = null;
if ($currentUser['user_type'] === 'gn') {
    try {
        $conn = getMainConnection();
        if ($conn) {
            $gn_id = $currentUser['office_code'];
            
            // Family and citizen stats
            $families = $conn->query("SELECT COUNT(*) as count FROM families WHERE gn_id = '$gn_id'")->fetch_assoc();
            $citizens = $conn->query("SELECT COUNT(*) as count FROM citizens c JOIN families f ON c.family_id = f.family_id WHERE f.gn_id = '$gn_id'")->fetch_assoc();
            $month_families = $conn->query("SELECT COUNT(*) as count FROM families WHERE gn_id = '$gn_id' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetch_assoc();
            
            $gnStats = [
                'families' => $families['count'] ?? 0,
                'citizens' => $citizens['count'] ?? 0,
                'month_families' => $month_families['count'] ?? 0
            ];
        }
    } catch (Exception $e) {
        error_log('Error loading GN stats: ' . $e->getMessage());
        $gnStats = null;
    }
}
?>

<style>
/* Light Professional Sidebar */
.modern-sidebar {
    width: var(--sidebar-width);
    background: #f8fafc;
    color: #1e293b;
    height: calc(100vh - var(--header-height));
    overflow-y: auto;
    border-right: 1px solid #e2e8f0;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    box-shadow: 4px 0 20px rgba(0,0,0,0.04);
    position: fixed;
    top: var(--header-height);
    left: 0;
    z-index: 1020;
    transition: transform 0.3s ease;
}

/* For mobile devices */
@media (max-width: 767.98px) {
    .modern-sidebar {
        transform: translateX(-100%);
        height: 100vh;
        top: 0;
        z-index: 1040;
    }
    
    .modern-sidebar.show {
        transform: translateX(0);
    }
}

/* Desktop */
@media (min-width: 768px) {
    .modern-sidebar {
        transform: translateX(0) !important;
        position: fixed;
        top: var(--header-height);
        left: 0;
        height: calc(100vh - var(--header-height));
    }
}

/* Profile Card */
.user-profile-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    margin: 24px 20px 20px;
    padding: 28px 20px;
    text-align: center;
    box-shadow: 0 10px 30px rgba(0,0,0,0.06);
}

.user-avatar {
    width: 84px;
    height: 84px;
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    box-shadow: 0 12px 30px rgba(79, 70, 229, 0.3);
    transition: all 0.3s ease;
}

.user-avatar:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 40px rgba(79, 70, 229, 0.4);
}

.user-avatar i {
    font-size: 34px;
}

.user-name {
    color: #1e293b;
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 4px;
}

.user-office {
    color: #64748b;
    font-size: 13.5px;
    margin-bottom: 12px;
}

.user-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 18px;
    background: #ecfdf5;
    border: 1px solid #86efac;
    color: #166534;
    border-radius: 50px;
    font-size: 11.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Form Badge */
.form-badge {
    display: inline-block;
    background: #7c3aed;
    color: white;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 10px;
    margin-left: 8px;
    font-weight: 600;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

/* Section Titles */
.nav-section-title {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    padding: 28px 28px 10px;
}

/* Main Navigation Links */
.modern-nav-link {
    color: #475569;
    padding: 14px 28px;
    margin: 4px 18px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    text-decoration: none;
    font-weight: 500;
    font-size: 14.5px;
    transition: all 0.3s ease;
    border: none;
    background: transparent;
}

.modern-nav-link:hover {
    background: #f1f5f9;
    color: #1e293b;
    transform: translateX(6px);
    text-decoration: none;
}

/* Active state - Prominent highlight */
.modern-nav-link.active {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white !important;
    font-weight: 600;
    box-shadow: 0 8px 25px rgba(99,102,241,0.35);
    transform: translateX(0);
}

.modern-nav-link.active i {
    color: white !important;
}

.modern-nav-link.active .form-badge {
    background: rgba(255,255,255,0.3);
    color: white;
}

.modern-nav-link i {
    width: 22px;
    margin-right: 14px;
    font-size: 17px;
    color: #64748b;
    transition: color 0.3s;
}

.modern-nav-link:hover i {
    color: #6366f1;
}

/* Submenu Links - Now always visible */
.submenu-link {
    color: #64748b;
    padding: 11px 28px 11px 64px;
    margin: 3px 18px;
    border-radius: 12px;
    font-size: 13.8px;
    display: flex;
    align-items: center;
    transition: all 0.3s;
    position: relative;
    text-decoration: none;
}

.submenu-link::before {
    content: '';
    position: absolute;
    left: 42px;
    top: 50%;
    width: 7px;
    height: 7px;
    background: #6366f1;
    border-radius: 50%;
    transform: translateY(-50%);
}

.submenu-link:hover {
    background: #eef2ff;
    color: #4f46e5;
    padding-left: 68px;
    text-decoration: none;
}

/* Active submenu state */
.submenu-link.active {
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    color: #4f46e5 !important;
    font-weight: 600;
    border-left: 3px solid #6366f1;
}

.submenu-link.active::before {
    background: #4f46e5;
    width: 9px;
    height: 9px;
}

/* Dividers & Logout */
.nav-divider {
    height: 1px;
    background: #e2e8f0;
    margin: 20px 28px;
}

.logout-link {
    color: #ef4444 !important;
}

.logout-link:hover {
    background: #fee2e2 !important;
    color: #991b1b !important;
}

.logout-link:hover i {
    color: #991b1b !important;
}

/* Stats Card & System Info */
.stats-card,
.system-info {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    margin: 20px 20px 20px;
    padding: 22px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.05);
}

.stats-card h6 {
    color: #1e293b;
    font-size: 14.5px;
    font-weight: 600;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.stat-item {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px dashed #e2e8f0;
}

.stat-item:last-child {
    border: none;
}

.stat-label {
    color: #64748b;
    font-size: 13.5px;
}

.stat-value {
    color: #1e293b;
    font-weight: 700;
    font-size: 16px;
}

.system-info {
    text-align: center;
    font-size: 12px;
    color: #64748b;
}

.system-info-item {
    padding: 7px 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.system-info-item i {
    color: #6366f1;
}

/* Scrollbar styling */
.modern-sidebar::-webkit-scrollbar {
    width: 6px;
}

.modern-sidebar::-webkit-scrollbar-track {
    background: transparent;
}

.modern-sidebar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.modern-sidebar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Remove all text decorations */
.modern-sidebar a,
.modern-sidebar button {
    text-decoration: none !important;
}

/* Main content adjustment for mobile */
@media (max-width: 767.98px) {
    body.sidebar-open {
        overflow: hidden;
    }
    
    .main-content {
        margin-left: 0 !important;
    }
}
</style>

<div class="modern-sidebar">
    <!-- Profile Card -->
    <div class="user-profile-card">
        <div class="user-avatar">
            <i class="fas fa-user-tie"></i>
        </div>
        <div class="user-name"><?php echo htmlspecialchars($currentUser['username']); ?></div>
        <div class="user-office"><?php echo htmlspecialchars($currentUser['office_name']); ?></div>
        <div class="user-badge">
            <i class="fas fa-shield-alt"></i>
            <?php echo strtoupper($currentUser['user_type']); ?>
        </div>
    </div>

    <!-- Navigation -->
    <nav>
        <?php if ($currentUser['user_type'] === 'moha'): ?>
            <div class="nav-section-title">Central Administration</div>
            <a class="modern-nav-link <?php echo (basename($currentUrl) == 'index.php') ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>index.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'districts.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>admin/districts.php">
                <i class="fas fa-building"></i> Manage Districts
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'audit_logs.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>admin/audit_logs.php">
                <i class="fas fa-clipboard-list"></i> Audit Logs
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'system_settings.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>admin/system_settings.php">
                <i class="fas fa-cogs"></i> System Settings
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'report') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>reports/index.php">
                <i class="fas fa-chart-bar"></i> National Reports
            </a>

            <!-- MOHA Form Management -->
            <div class="nav-divider"></div>
            <div class="nav-section-title">Form Administration</div>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'forms/manage.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>forms/manage.php">
                <i class="fas fa-list-check"></i> All Forms
                <span class="form-badge">Admin</span>
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'forms/create.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>forms/create.php">
                <i class="fas fa-plus-circle"></i> Create Form
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'forms/assignments.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>forms/assignments.php">
                <i class="fas fa-user-plus"></i> Assign Forms
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'forms/submissions.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>forms/submissions.php">
                <i class="fas fa-inbox"></i> All Submissions
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'forms/reports.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>forms/reports.php">
                <i class="fas fa-chart-line"></i> Form Analytics
            </a>

        <?php elseif ($currentUser['user_type'] === 'district'): ?>
            <div class="nav-section-title">District Management</div>
            <a class="modern-nav-link <?php echo (basename($currentUrl) == 'index.php') ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>index.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'divisions.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>district/divisions.php">
                <i class="fas fa-sitemap"></i> Manage Divisions
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'view_families.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>district/view_families.php">
                <i class="fas fa-users"></i> View Families
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'reports_district.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>district/reports_district.php">
                <i class="fas fa-file-alt"></i> District Reports
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'statistics.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>district/statistics.php">
                <i class="fas fa-chart-pie"></i> Statistics
            </a>

        <?php elseif ($currentUser['user_type'] === 'division'): ?>
            <div class="nav-section-title">Divisional Secretariat</div>
            <a class="modern-nav-link <?php echo (basename($currentUrl) == 'index.php') ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>index.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'gn_divisions.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>division/gn_divisions.php">
                <i class="fas fa-map-marker-alt"></i> Manage GN Divisions
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'transfer_requests.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>users/gn/citizens/transfer_requests.php">
                <i class="fas fa-exchange-alt"></i> Manage Transfers
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'reports_division.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>division/reports_division.php">
                <i class="fas fa-chart-bar"></i> Divisional Reports
            </a>
            <a class="modern-nav-link <?php echo (strpos($currentUrl, 'statistics.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>division/statistics.php">
                <i class="fas fa-chart-pie"></i> Statistics
            </a>

        <?php elseif ($currentUser['user_type'] === 'gn'): ?>
            <div class="nav-section-title">Grama Niladhari Division</div>
            <a class="modern-nav-link <?php echo (basename($currentUrl) == 'index.php') ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>index.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>

            <!-- Citizen & Family Management - All Links Visible -->
            <div class="nav-section-title">Citizen Management</div>
            <a class="submenu-link <?php echo (strpos($currentUrl, 'add_family.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>users/gn/citizens/add_family.php">
                Add New Family
            </a>
            <a class="submenu-link <?php echo (strpos($currentUrl, 'list_families.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>users/gn/citizens/list_families.php">
                Manage Families
            </a>
            <a class="submenu-link <?php echo (strpos($currentUrl, 'transfer_requests.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>users/gn/citizens/transfer_requests.php">
                Transfer Requests
            </a>
            <a class="submenu-link <?php echo (strpos($currentUrl, 'search_family.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>users/search_family.php">
                Search Family
            </a>
            <a class="submenu-link <?php echo (strpos($currentUrl, 'search_members.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>users/search_members.php">
                Search Member
            </a>

          
            
            <!-- Fill Forms Submenu - All Links Visible -->
            
        <?php endif; ?>

        <!-- Common Account Links -->
        <div class="nav-divider"></div>
        <div class="nav-section-title">My Account</div>
        <a class="modern-nav-link <?php echo (strpos($currentUrl, 'my_profile.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>users/my_profile.php">
            <i class="fas fa-id-card"></i> My Profile
        </a>
        <a class="modern-nav-link <?php echo (strpos($currentUrl, 'change_password.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>users/change_password.php">
            <i class="fas fa-key"></i> Change Password
        </a>
        <a class="modern-nav-link <?php echo (strpos($currentUrl, 'activity_log.php') !== false) ? 'active' : ''; ?>" href="<?php echo SITE_URL; ?>users/activity_log.php">
            <i class="fas fa-history"></i> Activity Log
        </a>

        <div class="nav-divider"></div>
        <a class="modern-nav-link logout-link" href="<?php echo SITE_URL; ?>logout.php">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>

    <!-- Quick Stats (GN only) - Updated with Form Stats -->
    <?php if ($currentUser['user_type'] === 'gn'): ?>
        <div class="stats-card">
            <h6>Division Statistics</h6>
            <?php if ($gnStats): ?>
            <div class="stat-item">
                <span class="stat-label">Total Families</span>
                <span class="stat-value"><?php echo number_format($gnStats['families']); ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Total Citizens</span>
                <span class="stat-value"><?php echo number_format($gnStats['citizens']); ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">New Families (Month)</span>
                <span class="stat-value">+<?php echo $gnStats['month_families']; ?></span>
            </div>
            <?php if ($formStats): ?>
            <div class="stat-item" style="border-top: 2px solid #6366f1; padding-top: 15px;">
                <span class="stat-label">Form Submissions</span>
                <span class="stat-value" style="color: #6366f1;"><?php echo $formStats['completed_submissions'] ?? 0; ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Forms in Draft</span>
                <span class="stat-value"><?php echo $formStats['draft_submissions'] ?? 0; ?></span>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="text-center py-3">
                <small class="text-muted">Statistics unavailable</small>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- System Info -->
    <div class="system-info">
        <div class="system-info-item"><i class="fas fa-server"></i> <?php echo defined('MAIN_DB_NAME') ? MAIN_DB_NAME : 'FPMS'; ?></div>
        <div class="system-info-item"><i class="fas fa-code-branch"></i> <strong>v<?php echo defined('SITE_VERSION') ? SITE_VERSION : '1.0.0'; ?></strong></div>
        <div class="system-info-item"><i class="fas fa-clock"></i> <span id="current-time"><?php echo date('h:i A'); ?></span></div>
    </div>
</div>

<!-- Simple time update script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update time every minute
    function updateTime() {
        const now = new Date();
        const timeElement = document.getElementById('current-time');
        if (timeElement) {
            timeElement.textContent = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                hour12: true 
            });
        }
    }
    
    setInterval(updateTime, 60000);
});
</script>