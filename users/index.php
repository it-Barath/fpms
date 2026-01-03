<?php
/**
 * users/index.php
 * User Management Dashboard
 */

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/UserManager.php';

$auth = new Auth();
$auth->requireLogin();

$userManager = new UserManager();
$currentUser = $auth->getCurrentUser();

// Check permissions - only MOHA, District, and Division can access
if (!in_array($currentUser['user_type'], ['moha', 'district', 'division'])) {
    header('Location: unauthorized.php');
    exit();
}

// Get user statistics
$userStats = $userManager->getUserStatistics($currentUser['user_id']);
$recentActivities = $userManager->getRecentActivities(10);
$manageableUsers = $userManager->getManageableUsers($currentUser['user_id']);

// Set page variables
$pageTitle = "User Management";
$pageDescription = "Manage user accounts and permissions";
$pageIcon = "fas fa-users-cog";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index.php'],
    ['title' => 'User Management']
];

// Include header
include '../includes/header.php';
?>

<div class="container-fluid">
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
                <a href="manage_passwords.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-key me-2"></i> Manage Passwords
                </a>
                <a href="my_profile.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-user me-2"></i> My Profile
                </a>
            </div>
        </div>
    </div>
    
    <!-- Dashboard Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-1">Total Users</h6>
                            <h2 class="card-text mb-0"><?php echo $userStats['total_users'] ?? 0; ?></h2>
                        </div>
                        <i class="fas fa-users fa-2x opacity-75"></i>
                    </div>
                    <div class="mt-2">
                        <small class="opacity-75">Under your jurisdiction</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-1">Active Users</h6>
                            <h2 class="card-text mb-0"><?php echo $userStats['active_users'] ?? 0; ?></h2>
                        </div>
                        <i class="fas fa-user-check fa-2x opacity-75"></i>
                    </div>
                    <div class="mt-2">
                        <small class="opacity-75">Currently active</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-1">Today's Logins</h6>
                            <h2 class="card-text mb-0"><?php echo $userStats['today_logins'] ?? 0; ?></h2>
                        </div>
                        <i class="fas fa-sign-in-alt fa-2x opacity-75"></i>
                    </div>
                    <div class="mt-2">
                        <small class="opacity-75">Logged in today</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-1">Never Logged In</h6>
                            <h2 class="card-text mb-0"><?php echo $userStats['never_logged_in'] ?? 0; ?></h2>
                        </div>
                        <i class="fas fa-user-clock fa-2x opacity-75"></i>
                    </div>
                    <div class="mt-2">
                        <small class="opacity-75">Accounts pending activation</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt me-2"></i> Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <a href="manage_passwords.php" class="btn btn-primary w-100">
                                <i class="fas fa-key me-2"></i> Reset Passwords
                            </a>
                        </div>
                        
                        <div class="col-md-2">
                            <a href="user_activity.php?id=<?php echo $currentUser['user_id']; ?>" class="btn btn-info w-100">
                                <i class="fas fa-history me-2"></i> My Activity
                            </a>
                        </div>
                        
                        <div class="col-md-2">
                            <a href="change_password.php" class="btn btn-warning w-100">
                                <i class="fas fa-lock me-2"></i> Change My Password
                            </a>
                        </div>
                        
                        <div class="col-md-2">
                            <a href="activity_log.php" class="btn btn-secondary w-100">
                                <i class="fas fa-clipboard-list me-2"></i> All Activities
                            </a>
                        </div>
                        
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" onclick="exportUserReport()">
                                <i class="fas fa-file-export me-2"></i> Export Report
                            </button>
                        </div>
                        
                        <div class="col-md-2">
                            <button class="btn btn-dark w-100" data-bs-toggle="modal" data-bs-target="#helpModal">
                                <i class="fas fa-question-circle me-2"></i> Help
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content Row -->
    <div class="row">
        <!-- Left Column: User List -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Manageable Users
                        <span class="badge bg-secondary ms-2"><?php echo count($manageableUsers); ?></span>
                    </h5>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="refreshUserList()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($manageableUsers)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-users-slash fa-3x text-muted mb-3"></i>
                            <h5>No Users Found</h5>
                            <p class="text-muted">There are no users under your jurisdiction to manage.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="usersTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Username</th>
                                        <th>User Type</th>
                                        <th>Office</th>
                                        <th>Last Login</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($manageableUsers as $user): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm bg-primary rounded-circle d-flex align-items-center justify-content-center me-2">
                                                    <i class="fas fa-user text-white"></i>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                                    <?php if ($user['email']): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($user['email']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
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
                                        </td>
                                        <td>
                                            <div class="small"><?php echo htmlspecialchars($user['office_name']); ?></div>
                                            <div class="text-muted smaller"><?php echo htmlspecialchars($user['office_code']); ?></div>
                                        </td>
                                        <td>
                                            <?php if ($user['last_login']): ?>
                                            <div class="small" title="<?php echo $user['last_login']; ?>">
                                                <?php echo date('M d, H:i', strtotime($user['last_login'])); ?>
                                            </div>
                                            <div class="text-muted smaller">
                                                <?php echo timeAgo($user['last_login']); ?>
                                            </div>
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
                                                <a href="user_activity.php?id=<?php echo $user['user_id']; ?>" 
                                                   class="btn btn-outline-info" title="View Activity">
                                                    <i class="fas fa-history"></i>
                                                </a>
                                                <a href="manage_passwords.php#user-<?php echo $user['user_id']; ?>" 
                                                   class="btn btn-outline-warning" title="Reset Password">
                                                    <i class="fas fa-key"></i>
                                                </a>
                                                <?php if ($currentUser['user_type'] === 'moha'): ?>
                                                <button class="btn btn-outline-danger" 
                                                        onclick="deactivateUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')"
                                                        title="Deactivate User">
                                                    <i class="fas fa-user-slash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            Showing <?php echo count($manageableUsers); ?> users under your jurisdiction
                        </small>
                        <a href="list_users.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-list-ul me-1"></i> View Full List
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Recent Activity & Info -->
        <div class="col-md-4">
            <!-- Recent Activity -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i> Recent Activity
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (empty($recentActivities)): ?>
                            <div class="text-center py-3">
                                <i class="fas fa-clipboard-list fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No recent activity</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentActivities as $activity): ?>
                            <a href="#" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <small class="text-primary"><?php echo htmlspecialchars($activity['action_type'] ?? 'Activity'); ?></small>
                                    <small class="text-muted"><?php echo timeAgo($activity['created_at'] ?? ''); ?></small>
                                </div>
                                <small class="text-muted d-block">
                                    <?php 
                                    $details = $activity['record_id'] ?? $activity['details'] ?? '';
                                    echo htmlspecialchars(substr($details, 0, 50)) . (strlen($details) > 50 ? '...' : '');
                                    ?>
                                </small>
                                <?php if ($activity['username']): ?>
                                <small class="text-muted d-block">
                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($activity['username']); ?>
                                </small>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer text-center">
                    <a href="activity_log.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-external-link-alt me-1"></i> View All Activity
                    </a>
                </div>
            </div>
            
            <!-- User Management Info -->
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i> User Management Guide
                    </h5>
                </div>
                <div class="card-body">
                    <h6>Your Permissions:</h6>
                    <ul class="small">
                        <?php if ($currentUser['user_type'] === 'moha'): ?>
                            <li>Can manage all District Secretariats</li>
                            <li>Can reset passwords for all users</li>
                            <li>Can activate/deactivate any account</li>
                            <li>Can view all activity logs</li>
                        <?php elseif ($currentUser['user_type'] === 'district'): ?>
                            <li>Can manage Divisional Secretariats under your district</li>
                            <li>Can reset passwords for divisions and GN</li>
                            <li>Can view activity logs for your jurisdiction</li>
                        <?php elseif ($currentUser['user_type'] === 'division'): ?>
                            <li>Can manage GN Divisions under your division</li>
                            <li>Can reset passwords for GN divisions</li>
                            <li>Can view activity logs for your jurisdiction</li>
                        <?php endif; ?>
                    </ul>
                    
                    <h6 class="mt-3">Quick Tips:</h6>
                    <ul class="small">
                        <li>Always verify user identity before password reset</li>
                        <li>Inform users when resetting their passwords</li>
                        <li>Regularly review activity logs for security</li>
                        <li>Deactivate unused accounts for security</li>
                    </ul>
                </div>
            </div>
            
            <!-- System Status -->
            <div class="card mt-3">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">System Status</h6>
                            <small class="text-muted">User Management Module</small>
                        </div>
                        <div class="text-end">
                            <div class="text-success">
                                <i class="fas fa-circle"></i> Online
                            </div>
                            <small class="text-muted">v1.0.0</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-question-circle me-2"></i>User Management Help
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-key text-warning me-2"></i>Password Management</h6>
                        <p class="small">When resetting passwords:</p>
                        <ol class="small">
                            <li>A temporary password is generated</li>
                            <li>User must change it on first login</li>
                            <li>Email notification is sent (if configured)</li>
                            <li>Activity is logged for audit purposes</li>
                        </ol>
                        
                        <h6 class="mt-3"><i class="fas fa-shield-alt text-success me-2"></i>Security</h6>
                        <ul class="small">
                            <li>All actions are logged in audit trail</li>
                            <li>CSRF protection prevents unauthorized actions</li>
                            <li>Session timeout after <?php echo floor(SESSION_TIMEOUT / 60); ?> minutes</li>
                            <li>IP tracking for all activities</li>
                        </ul>
                    </div>
                    
                    <div class="col-md-6">
                        <h6><i class="fas fa-user-cog text-info me-2"></i>User Roles</h6>
                        <ul class="small">
                            <li><strong>MOHA:</strong> Full system access, manages districts</li>
                            <li><strong>District:</strong> Manages divisions under district</li>
                            <li><strong>Division:</strong> Manages GN divisions under division</li>
                            <li><strong>GN:</strong> Manages citizen data only</li>
                        </ul>
                        
                        <h6 class="mt-3"><i class="fas fa-headset text-danger me-2"></i>Support</h6>
                        <p class="small mb-1">
                            <strong>Email:</strong> <?php echo SUPPORT_EMAIL; ?>
                        </p>
                        <p class="small mb-0">
                            <strong>Phone:</strong> <?php echo SUPPORT_PHONE; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="contact_support.php" class="btn btn-primary">Contact Support</a>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
// Export user report
function exportUserReport() {
    // Create CSV content
    let csv = 'Username,User Type,Office,Last Login,Status\n';
    
    const rows = document.querySelectorAll('#usersTable tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const rowData = [];
        
        // Username (skip avatar and email)
        rowData.push(cells[0].querySelector('strong').textContent.trim());
        
        // User Type
        rowData.push(cells[1].textContent.trim());
        
        // Office
        rowData.push(cells[2].querySelector('.small').textContent.trim());
        
        // Last Login
        const loginText = cells[3].querySelector('.small')?.textContent.trim() || 'Never';
        rowData.push(loginText);
        
        // Status
        rowData.push(cells[4].textContent.trim());
        
        // Escape quotes and wrap in quotes if contains comma
        const escapedData = rowData.map(cell => {
            if (cell.includes(',') || cell.includes('"')) {
                return '"' + cell.replace(/"/g, '""') + '"';
            }
            return cell;
        });
        
        csv += escapedData.join(',') + '\n';
    });
    
    // Create download link
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'user_report_<?php echo strtolower($currentUser['user_type']); ?>_<?php echo date('Y-m-d'); ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Refresh user list
function refreshUserList() {
    const refreshBtn = document.querySelector('button[onclick="refreshUserList()"]');
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    refreshBtn.disabled = true;
    
    setTimeout(() => {
        window.location.reload();
    }, 1000);
}

// Deactivate user (MOHA only)
function deactivateUser(userId, username) {
    if (!confirm(`Are you sure you want to deactivate user "${username}"?\n\nThis will prevent them from logging in.`)) {
        return;
    }
    
    fetch('ajax/deactivate_user.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN
        },
        body: JSON.stringify({
            user_id: userId,
            action: 'deactivate'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('User deactivated successfully.');
            refreshUserList();
        } else {
            alert('Error: ' + (data.message || 'Failed to deactivate user'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    });
}

// Initialize table sorting
document.addEventListener('DOMContentLoaded', function() {
    const usersTable = document.getElementById('usersTable');
    if (usersTable) {
        // Add sorting to table headers
        const headers = usersTable.querySelectorAll('thead th');
        headers.forEach((header, index) => {
            if (index !== 5) { // Skip actions column
                header.style.cursor = 'pointer';
                header.addEventListener('click', function() {
                    sortUsersTable(index);
                });
            }
        });
    }
});

// Sort users table
function sortUsersTable(columnIndex) {
    const table = document.getElementById('usersTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    const isAscending = table.getAttribute('data-sort-dir') !== 'asc';
    
    rows.sort((a, b) => {
        const aCell = a.children[columnIndex];
        const bCell = b.children[columnIndex];
        
        let aText, bText;
        
        // Extract text based on column type
        switch(columnIndex) {
            case 0: // Username
                aText = aCell.querySelector('strong').textContent.trim();
                bText = bCell.querySelector('strong').textContent.trim();
                break;
            case 1: // User Type
                aText = aCell.textContent.trim();
                bText = bCell.textContent.trim();
                break;
            case 2: // Office
                aText = aCell.querySelector('.small').textContent.trim();
                bText = bCell.querySelector('.small').textContent.trim();
                break;
            case 3: // Last Login
                aText = aCell.querySelector('.small')?.textContent.trim() || '';
                bText = bCell.querySelector('.small')?.textContent.trim() || '';
                break;
            case 4: // Status
                aText = aCell.textContent.trim();
                bText = bCell.textContent.trim();
                break;
            default:
                aText = aCell.textContent.trim();
                bText = bCell.textContent.trim();
        }
        
        // Special handling for dates
        if (columnIndex === 3) {
            if (aText === 'Never' && bText === 'Never') return 0;
            if (aText === 'Never') return isAscending ? 1 : -1;
            if (bText === 'Never') return isAscending ? -1 : 1;
            
            const aDate = new Date(aText);
            const bDate = new Date(bText);
            return isAscending ? aDate - bDate : bDate - aDate;
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
</script>

<!-- CSS for this page -->
<style>
.avatar-sm {
    width: 36px;
    height: 36px;
}

#usersTable th {
    cursor: pointer;
    user-select: none;
}

#usersTable th:hover {
    background-color: #f1f3f4;
}

.smaller {
    font-size: 0.8rem;
}

.card .card-body.p-0 .table {
    margin-bottom: 0;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.list-group-item {
    border-left: none;
    border-right: none;
    border-radius: 0;
}

.list-group-item:first-child {
    border-top: none;
}

.list-group-item:last-child {
    border-bottom: none;
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
    if (empty($datetime)) return 'Never';
    
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
?>