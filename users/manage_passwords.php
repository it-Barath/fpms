<?php
/**
 * manage_passwords.php
 * Hierarchical password management for FPMS
 * MOHA → Districts, District → Divisions, Division → GN
 */

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/UserManager.php';

$auth = new Auth();
$auth->requireLogin();

$userManager = new UserManager();
$currentUser = $auth->getCurrentUser();

// Get user's jurisdiction information
$jurisdiction = $auth->getUserJurisdiction();

// Set page title based on user type
$pageTitle = "";
$managedUsers = [];
$successMessage = '';
$errorMessage = '';

switch ($currentUser['user_type']) {
    case 'moha':
        $pageTitle = "Manage District Secretariats";
        $pageDescription = "Reset passwords for District Secretariats";
        break;
        
    case 'district':
        $pageTitle = "Manage Divisional Secretariats";
        $pageDescription = "Reset passwords for Divisional Secretariats under your district";
        break;
        
    case 'division':
        $pageTitle = "Manage GN Divisions";
        $pageDescription = "Reset passwords for GN Divisions under your division";
        break;
        
    default:
        // GN users cannot manage other users
        header('Location: unauthorized.php');
        exit();
}

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $targetUsername = $_POST['username'] ?? '';
    
    if ($targetUserId > 0) {
        // Check if user has permission to reset this password
        $targetUser = $userManager->getUserById($targetUserId);
        
        if ($targetUser && $auth->canManage($targetUser['user_type'], $targetUser['office_code'])) {
            // Reset the password
            list($success, $message, $newPassword) = $auth->resetPassword(
                $targetUserId, 
                $currentUser['user_id']
            );
            
            if ($success) {
                $successMessage = "Password reset successfully for <strong>{$targetUser['username']}</strong>.<br>";
                $successMessage .= "New password: <code class='bg-light p-2 rounded'>{$newPassword}</code>";
                
                // Log activity
                logActivity('password_reset', 
                    "Reset password for {$targetUser['username']} ({$targetUser['user_type']})", 
                    $currentUser['user_id']);
            } else {
                $errorMessage = $message;
            }
        } else {
            $errorMessage = "You are not authorized to reset this user's password.";
        }
    }
}

// Get users that current user can manage
$managedUsers = $userManager->getManageableUsers($currentUser['user_id']);

// Set page variables for header
$pageIcon = "fas fa-key";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index.php'],
    ['title' => 'User Management', 'url' => '#'],
    ['title' => $pageTitle]
];

// Include header
include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../includes/sidebar.php'; ?>
        </div>
        


<div class="container-fluid px-0">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2">
                <i class="<?php echo $pageIcon; ?> me-2"></i>
                <?php echo $pageTitle; ?>
            </h1>
            <p class="lead mb-0"><?php echo $pageDescription; ?></p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> Export
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printPage()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>
    
    <!-- Flash Messages -->
    <?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo $successMessage; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $errorMessage; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- User Jurisdiction Info -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-map-marked-alt me-2"></i>
                Your Jurisdiction
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless table-sm">
                        <tr>
                            <th width="150">Your Role:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    switch($currentUser['user_type']) {
                                        case 'moha': echo 'danger'; break;
                                        case 'district': echo 'warning'; break;
                                        case 'division': echo 'info'; break;
                                    }
                                ?>">
                                    <?php echo strtoupper($currentUser['user_type']); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Office:</th>
                            <td><?php echo htmlspecialchars($currentUser['office_name']); ?></td>
                        </tr>
                        <tr>
                            <th>Office Code:</th>
                            <td><code><?php echo htmlspecialchars($currentUser['office_code']); ?></code></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info mb-0">
                        <h6><i class="fas fa-info-circle me-2"></i> Permission Information</h6>
                        <p class="mb-0 small">
                            You can reset passwords for 
                            <strong><?php echo strtolower($jurisdiction['managed_offices'][0]['type'] ?? 'users'); ?></strong> 
                            under your jurisdiction.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- User Management Table -->
    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-users-cog me-2"></i>
                Manageable Users
                <span class="badge bg-secondary ms-2"><?php echo count($managedUsers); ?></span>
            </h5>
            <div class="btn-group">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="refreshList()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($managedUsers)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-users-slash fa-3x text-muted mb-3"></i>
                    <h5>No Users Found</h5>
                    <p class="text-muted">There are no users under your jurisdiction to manage.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="usersTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Username</th>
                                <th>Office Name</th>
                                <th>Last Login</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($managedUsers as $index => $user): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                     <span class="badge bg-<?php 
                                        switch($user['user_type']) {
                                            case 'moha': echo 'danger'; break;
                                            case 'district': echo 'warning'; break;
                                            case 'division': echo 'info'; break;
                                            case 'gn': echo 'success'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>">
                                        <?php echo strtoupper($user['user_type']); ?>
                                    </span>
                                    <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                </td>
                              
                                <td><?php echo htmlspecialchars($user['office_name']); ?><br>

                                  <?php if ($user['email']): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>" class="small">
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">Not set</span>
                                    <?php endif; ?>
                            
                            
                            
                            </td>
                            
                             
                                <td>
                                    <code><?php echo htmlspecialchars($user['office_code']); ?></code><br>
                                    <?php if ($user['last_login']): ?>
                                    <span class="small" title="<?php echo $user['last_login']; ?>">
                                        <?php echo timeAgo($user['last_login']); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted small">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <!-- Reset Password Button -->
                                        <button type="button" class="btn btn-warning" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#resetModal"
                                                data-userid="<?php echo $user['user_id']; ?>"
                                                data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                data-usertype="<?php echo $user['user_type']; ?>"
                                                onclick="setResetUser(this)">
                                            <i class="fas fa-key"></i> Reset Password
                                        </button>
                                        
                                        <!-- View Activity Button -->
                                        <a href="user_activity.php?id=<?php echo $user['user_id']; ?>" 
                                           class="btn btn-info">
                                            <i class="fas fa-history"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Statistics -->
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="mb-0">Total Users</h6>
                                        <h3 class="mb-0"><?php echo count($managedUsers); ?></h3>
                                    </div>
                                    <i class="fas fa-users fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="mb-0">Active</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                            $activeCount = array_filter($managedUsers, function($u) {
                                                return $u['is_active'];
                                            });
                                            echo count($activeCount);
                                            ?>
                                        </h3>
                                    </div>
                                    <i class="fas fa-user-check fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="mb-0">Logged In Today</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                            $todayCount = array_filter($managedUsers, function($u) {
                                                if (!$u['last_login']) return false;
                                                return date('Y-m-d', strtotime($u['last_login'])) == date('Y-m-d');
                                            });
                                            echo count($todayCount);
                                            ?>
                                        </h3>
                                    </div>
                                    <i class="fas fa-sign-in-alt fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="mb-0">Never Logged In</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                            $neverCount = array_filter($managedUsers, function($u) {
                                                return !$u['last_login'];
                                            });
                                            echo count($neverCount);
                                            ?>
                                        </h3>
                                    </div>
                                    <i class="fas fa-user-clock fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Instructions Card -->
    <div class="card mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="fas fa-info-circle me-2"></i>
                Password Reset Instructions
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6><i class="fas fa-exclamation-triangle text-warning me-2"></i>Important Notes:</h6>
                    <ul>
                        <li>When you reset a password, a new random password will be generated</li>
                        <li>The user will need to login with the new password and change it immediately</li>
                        <li>Email notification will be sent if email is configured</li>
                        <li>All password resets are logged in the audit system</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6><i class="fas fa-shield-alt text-success me-2"></i>Security Guidelines:</h6>
                    <ul>
                        <li>Only reset passwords for users under your jurisdiction</li>
                        <li>Verify user identity before resetting password</li>
                        <li>Inform the user about the password reset</li>
                        <li>Keep audit logs for accountability</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-key me-2"></i>Confirm Password Reset
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Are you sure you want to reset the password for this user?
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Username:</label>
                        <input type="text" class="form-control" id="resetUsername" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">User Type:</label>
                        <input type="text" class="form-control" id="resetUserType" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reset By:</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($currentUser['username']); ?>" readonly>
                    </div>
                    
                    <input type="hidden" name="user_id" id="resetUserId">
                    <input type="hidden" name="username" id="resetUsernameHidden">
                    <input type="hidden" name="reset_password" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-2"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
// Set user for reset modal
function setResetUser(button) {
    const userId = button.getAttribute('data-userid');
    const username = button.getAttribute('data-username');
    const userType = button.getAttribute('data-usertype');
    
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUsername').value = username;
    document.getElementById('resetUsernameHidden').value = username;
    document.getElementById('resetUserType').value = userType.toUpperCase();
}

// Export to Excel
function exportToExcel() {
    // Create CSV content
    let csv = 'Username,User Type,Office Name,Office Code,Email,Last Login,Status\n';
    
    const rows = document.querySelectorAll('#usersTable tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const rowData = [];
        
        cells.forEach((cell, index) => {
            if (index !== 8) { // Skip actions column
                let text = cell.textContent.trim();
                
                // Clean up badge text
                text = text.replace(/ACTIVE|INACTIVE|DISTRICT|DIVISION|GN|MOHA/g, '').trim();
                
                // Remove multiple spaces
                text = text.replace(/\s+/g, ' ');
                
                // Escape quotes and wrap in quotes if contains comma
                if (text.includes(',')) {
                    text = '"' + text + '"';
                }
                
                rowData.push(text);
            }
        });
        
        csv += rowData.join(',') + '\n';
    });
    
    // Create download link
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'users_<?php echo strtolower($currentUser['user_type']); ?>_<?php echo date('Y-m-d'); ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Print page
function printPage() {
    window.print();
}

// Refresh list
function refreshList() {
    const refreshBtn = document.querySelector('button[onclick="refreshList()"]');
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    refreshBtn.disabled = true;
    
    setTimeout(() => {
        window.location.reload();
    }, 1000);
}

// Initialize DataTable
document.addEventListener('DOMContentLoaded', function() {
    const usersTable = document.getElementById('usersTable');
    if (usersTable) {
        // Simple sorting functionality
        const headers = usersTable.querySelectorAll('thead th');
        headers.forEach((header, index) => {
            if (index !== 8) { // Skip actions column
                header.style.cursor = 'pointer';
                header.addEventListener('click', function() {
                    sortTable(index);
                });
            }
        });
    }
});

// Simple table sorting
function sortTable(columnIndex) {
    const table = document.getElementById('usersTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    const isAscending = table.getAttribute('data-sort-dir') !== 'asc';
    
    rows.sort((a, b) => {
        const aText = a.children[columnIndex].textContent.trim();
        const bText = b.children[columnIndex].textContent.trim();
        
        // Try to compare as numbers if possible
        const aNum = parseFloat(aText.replace(/[^0-9.-]+/g, ''));
        const bNum = parseFloat(bText.replace(/[^0-9.-]+/g, ''));
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return isAscending ? aNum - bNum : bNum - aNum;
        }
        
        // Otherwise compare as strings
        return isAscending 
            ? aText.localeCompare(bText)
            : bText.localeCompare(aText);
    });
    
    // Clear and re-append rows
    rows.forEach(row => tbody.appendChild(row));
    
    // Update sort direction
    table.setAttribute('data-sort-dir', isAscending ? 'asc' : 'desc');
}

// Sidebar toggle functionality
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.modern-sidebar');
    const sidebarOverlay = document.querySelector('.sidebar-overlay');
    
    if (sidebarToggle && sidebar && sidebarOverlay) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            sidebarOverlay.classList.toggle('show');
            document.body.classList.toggle('sidebar-open');
        });
        
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.classList.remove('sidebar-open');
        });
        
        // Close sidebar when clicking on links (mobile only)
        const sidebarLinks = sidebar.querySelectorAll('a');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth < 768) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                }
            });
        });
    }
});
</script>

<!-- CSS for this page -->
<style>
#usersTable th {
    cursor: pointer;
    user-select: none;
}

#usersTable th:hover {
    background-color: #f1f3f4;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.card {
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.card-header {
    border-bottom: 1px solid rgba(0,0,0,0.1);
}

.alert pre {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    border: 1px solid #dee2e6;
    font-family: 'Courier New', monospace;
    margin: 10px 0;
}

/* Ensure content doesn't hide behind sidebar on mobile */
@media (max-width: 767.98px) {
    .container-fluid {
        padding-left: 15px !important;
        padding-right: 15px !important;
    }
    
    .main-content-wrapper {
        width: 100% !important;
    }
}

/* Print styles */
@media print {
    .btn, .modal, .card-header .btn-group {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
}
</style>

<?php
// Include footer
include '../includes/footer.php';

// Helper function to show time ago
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('d M Y', $time);
    }
}