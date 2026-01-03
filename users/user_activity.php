<?php
/**
 * user_activity.php
 * View user activity logs for a specific user
 */

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/UserManager.php';

$auth = new Auth();
$auth->requireLogin();

$userManager = new UserManager();
$currentUser = $auth->getCurrentUser();

// Get user ID from query string
$targetUserId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$targetUser = null;

if ($targetUserId > 0) {
    $targetUser = $userManager->getUserById($targetUserId);
}

// Check permissions
if (!$targetUser || !$auth->canManage($targetUser['user_type'], $targetUser['office_code'])) {
    header('Location: unauthorized.php');
    exit();
}

// Get filter parameters
$filterAction = $_GET['action'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterTable = $_GET['table'] ?? '';
$searchQuery = $_GET['search'] ?? '';

// Build query for activity logs
$query = "SELECT al.*, u.username as action_user 
          FROM audit_logs al 
          LEFT JOIN users u ON al.user_id = u.user_id 
          WHERE al.user_id = ?";
          
$params = [$targetUserId];
$types = "i";

// Add filters
if (!empty($filterAction)) {
    $query .= " AND al.action_type = ?";
    $params[] = $filterAction;
    $types .= "s";
}

if (!empty($filterDateFrom)) {
    $query .= " AND DATE(al.created_at) >= ?";
    $params[] = $filterDateFrom;
    $types .= "s";
}

if (!empty($filterDateTo)) {
    $query .= " AND DATE(al.created_at) <= ?";
    $params[] = $filterDateTo;
    $types .= "s";
}

if (!empty($filterTable)) {
    $query .= " AND al.table_name = ?";
    $params[] = $filterTable;
    $types .= "s";
}

if (!empty($searchQuery)) {
    $query .= " AND (al.record_id LIKE ? OR al.ip_address LIKE ? OR al.user_agent LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $types .= "sss";
}

// Order and limit
$query .= " ORDER BY al.created_at DESC";
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$query .= " LIMIT ?";
$params[] = $limit;
$types .= "i";

// Execute query
$conn = getMainConnection();
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get distinct action types for filter dropdown
$actionTypesQuery = "SELECT DISTINCT action_type FROM audit_logs WHERE user_id = ? ORDER BY action_type";
$actionStmt = $conn->prepare($actionTypesQuery);
$actionStmt->bind_param("i", $targetUserId);
$actionStmt->execute();
$actionTypes = $actionStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get distinct tables for filter dropdown
$tablesQuery = "SELECT DISTINCT table_name FROM audit_logs WHERE user_id = ? AND table_name IS NOT NULL ORDER BY table_name";
$tableStmt = $conn->prepare($tablesQuery);
$tableStmt->bind_param("i", $targetUserId);
$tableStmt->execute();
$tables = $tableStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Set page variables
$pageTitle = "User Activity Log";
$pageDescription = "Activity history for " . htmlspecialchars($targetUser['username']);
$pageIcon = "fas fa-history";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index.php'],
    ['title' => 'User Management', 'url' => 'manage_passwords.php'],
    ['title' => 'User Activity']
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
                <a href="manage_passwords.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Users
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportActivityLog()">
                    <i class="fas fa-file-export"></i> Export
                </button>
            </div>
        </div>
    </div>
    
    <!-- User Info Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-user me-2"></i>
                User Information
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless table-sm">
                        <tr>
                            <th width="150">Username:</th>
                            <td><strong><?php echo htmlspecialchars($targetUser['username']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>User Type:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    switch($targetUser['user_type']) {
                                        case 'moha': echo 'danger'; break;
                                        case 'district': echo 'warning'; break;
                                        case 'division': echo 'info'; break;
                                        case 'gn': echo 'success'; break;
                                    }
                                ?>">
                                    <?php echo strtoupper($targetUser['user_type']); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Office:</th>
                            <td><?php echo htmlspecialchars($targetUser['office_name']); ?></td>
                        </tr>
                        <tr>
                            <th>User ID:</th>
                            <td><code><?php echo $targetUser['user_id']; ?></code></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless table-sm">
                        <tr>
                            <th width="150">Email:</th>
                            <td>
                                <?php if ($targetUser['email']): ?>
                                <a href="mailto:<?php echo htmlspecialchars($targetUser['email']); ?>">
                                    <?php echo htmlspecialchars($targetUser['email']); ?>
                                </a>
                                <?php else: ?>
                                <span class="text-muted">Not set</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Last Login:</th>
                            <td>
                                <?php if ($targetUser['last_login']): ?>
                                <?php echo date('Y-m-d H:i:s', strtotime($targetUser['last_login'])); ?>
                                <small class="text-muted">(<?php echo timeAgo($targetUser['last_login']); ?>)</small>
                                <?php else: ?>
                                <span class="text-muted">Never logged in</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Account Created:</th>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($targetUser['created_at'])); ?></td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td>
                                <?php if ($targetUser['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="fas fa-filter me-2"></i>
                Filter Activities
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="id" value="<?php echo $targetUserId; ?>">
                
                <div class="col-md-3">
                    <label for="action" class="form-label">Action Type</label>
                    <select class="form-select" id="action" name="action">
                        <option value="">All Actions</option>
                        <?php foreach ($actionTypes as $action): ?>
                        <option value="<?php echo htmlspecialchars($action['action_type']); ?>" 
                                <?php echo ($filterAction === $action['action_type']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($action['action_type']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="table" class="form-label">Table</label>
                    <select class="form-select" id="table" name="table">
                        <option value="">All Tables</option>
                        <?php foreach ($tables as $table): ?>
                        <option value="<?php echo htmlspecialchars($table['table_name']); ?>" 
                                <?php echo ($filterTable === $table['table_name']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($table['table_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="date_from" class="form-label">Date From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                </div>
                
                <div class="col-md-2">
                    <label for="date_to" class="form-label">Date To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?php echo htmlspecialchars($filterDateTo); ?>">
                </div>
                
                <div class="col-md-2">
                    <label for="limit" class="form-label">Records</label>
                    <select class="form-select" id="limit" name="limit">
                        <option value="20" <?php echo ($limit == 20) ? 'selected' : ''; ?>>20</option>
                        <option value="50" <?php echo ($limit == 50) ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo ($limit == 100) ? 'selected' : ''; ?>>100</option>
                        <option value="200" <?php echo ($limit == 200) ? 'selected' : ''; ?>>200</option>
                        <option value="500" <?php echo ($limit == 500) ? 'selected' : ''; ?>>500</option>
                    </select>
                </div>
                
                <div class="col-md-6">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           placeholder="Search in record ID, IP, user agent..." 
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                
                <div class="col-md-6 d-flex align-items-end">
                    <div class="btn-group w-100">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i> Apply Filters
                        </button>
                        <a href="user_activity.php?id=<?php echo $targetUserId; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Activity Logs Card -->
    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list-alt me-2"></i>
                Activity Logs
                <span class="badge bg-secondary ms-2"><?php echo count($activities); ?></span>
            </h5>
            <div class="btn-group">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="refreshLogs()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($activities)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h5>No Activity Found</h5>
                    <p class="text-muted">No activity logs found for this user with the current filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="activityTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Time</th>
                                <th>Action</th>
                                <th>Table</th>
                                <th>Record ID</th>
                                <th>IP Address</th>
                                <th>User Agent</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activities as $index => $activity): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="small">
                                        <?php echo date('Y-m-d', strtotime($activity['created_at'])); ?>
                                    </div>
                                    <div class="text-muted smaller">
                                        <?php echo date('H:i:s', strtotime($activity['created_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        switch($activity['action_type']) {
                                            case 'login': echo 'success'; break;
                                            case 'logout': echo 'secondary'; break;
                                            case 'create': echo 'primary'; break;
                                            case 'update': echo 'warning'; break;
                                            case 'delete': echo 'danger'; break;
                                            case 'password_reset': echo 'info'; break;
                                            default: echo 'dark';
                                        }
                                    ?>">
                                        <?php echo htmlspecialchars($activity['action_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($activity['table_name']): ?>
                                    <code class="small"><?php echo htmlspecialchars($activity['table_name']); ?></code>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($activity['record_id']): ?>
                                    <span class="small"><?php echo htmlspecialchars($activity['record_id']); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="small"><?php echo htmlspecialchars($activity['ip_address']); ?></code>
                                    <?php if ($activity['ip_address']): ?>
                                    <br>
                                    <button type="button" class="btn btn-xs btn-outline-info btn-sm mt-1" 
                                            onclick="lookupIP('<?php echo htmlspecialchars($activity['ip_address']); ?>')"
                                            title="Lookup IP">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($activity['user_agent']): ?>
                                    <div class="small text-truncate" style="max-width: 200px;" 
                                         title="<?php echo htmlspecialchars($activity['user_agent']); ?>">
                                        <?php echo htmlspecialchars($activity['user_agent']); ?>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($activity['old_values'] || $activity['new_values']): ?>
                                    <button type="button" class="btn btn-xs btn-outline-primary btn-sm" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#detailsModal"
                                            data-old='<?php echo htmlspecialchars($activity['old_values'] ?? ''); ?>'
                                            data-new='<?php echo htmlspecialchars($activity['new_values'] ?? ''); ?>'
                                            onclick="showDetails(this)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Activity Statistics -->
                <div class="row g-3 mt-4 px-3">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="mb-0">Total Activities</h6>
                                        <h3 class="mb-0"><?php echo count($activities); ?></h3>
                                    </div>
                                    <i class="fas fa-chart-line fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="mb-0">Logins</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                            $loginCount = array_filter($activities, function($a) {
                                                return $a['action_type'] === 'login';
                                            });
                                            echo count($loginCount);
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
                                        <h6 class="mb-0">Updates</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                            $updateCount = array_filter($activities, function($a) {
                                                return $a['action_type'] === 'update';
                                            });
                                            echo count($updateCount);
                                            ?>
                                        </h3>
                                    </div>
                                    <i class="fas fa-edit fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="mb-0">Deletes</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                            $deleteCount = array_filter($activities, function($a) {
                                                return $a['action_type'] === 'delete';
                                            });
                                            echo count($deleteCount);
                                            ?>
                                        </h3>
                                    </div>
                                    <i class="fas fa-trash-alt fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer text-muted small">
            Showing last <?php echo count($activities); ?> activities. 
            <a href="user_activity_full.php?id=<?php echo $targetUserId; ?>" class="text-primary">
                View full history
            </a>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-code me-2"></i>Activity Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-arrow-left text-danger me-2"></i>Old Values</h6>
                        <pre id="oldValues" class="bg-light p-3 rounded" style="max-height: 300px; overflow-y: auto;"></pre>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-arrow-right text-success me-2"></i>New Values</h6>
                        <pre id="newValues" class="bg-light p-3 rounded" style="max-height: 300px; overflow-y: auto;"></pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="copyDetails()">
                    <i class="fas fa-copy me-2"></i>Copy Details
                </button>
            </div>
        </div>
    </div>
</div>

<!-- IP Lookup Modal -->
<div class="modal fade" id="ipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-map-marker-alt me-2"></i>IP Lookup
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">IP Address:</label>
                    <input type="text" class="form-control" id="ipAddress" readonly>
                </div>
                <div id="ipResult" class="alert alert-info">
                    <i class="fas fa-spinner fa-spin me-2"></i> Looking up IP information...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="ipLookupLink" target="_blank" class="btn btn-primary">
                    <i class="fas fa-external-link-alt me-2"></i>More Info
                </a>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
// Show activity details
function showDetails(button) {
    const oldValues = button.getAttribute('data-old');
    const newValues = button.getAttribute('data-new');
    
    // Format JSON if it's valid JSON
    try {
        const oldObj = oldValues ? JSON.parse(oldValues) : {};
        const newObj = newValues ? JSON.parse(newValues) : {};
        
        document.getElementById('oldValues').textContent = JSON.stringify(oldObj, null, 2);
        document.getElementById('newValues').textContent = JSON.stringify(newObj, null, 2);
    } catch (e) {
        // If not JSON, display as plain text
        document.getElementById('oldValues').textContent = oldValues || '(empty)';
        document.getElementById('newValues').textContent = newValues || '(empty)';
    }
}

// Copy details to clipboard
function copyDetails() {
    const oldValues = document.getElementById('oldValues').textContent;
    const newValues = document.getElementById('newValues').textContent;
    const text = `OLD VALUES:\n${oldValues}\n\nNEW VALUES:\n${newValues}`;
    
    navigator.clipboard.writeText(text).then(function() {
        alert('Details copied to clipboard!');
    }).catch(function(err) {
        console.error('Failed to copy: ', err);
    });
}

// Lookup IP address
function lookupIP(ip) {
    document.getElementById('ipAddress').value = ip;
    document.getElementById('ipResult').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Looking up IP information...';
    document.getElementById('ipLookupLink').href = `https://ipinfo.io/${ip}`;
    
    // Fetch IP information
    fetch(`https://ipapi.co/${ip}/json/`)
        .then(response => response.json())
        .then(data => {
            let html = '';
            if (data.error) {
                html = `<div class="text-danger"><i class="fas fa-exclamation-circle me-2"></i>${data.reason || 'Unable to lookup IP'}</div>`;
            } else {
                html = `
                    <div><strong>Country:</strong> ${data.country_name || 'Unknown'}</div>
                    <div><strong>Region:</strong> ${data.region || 'Unknown'}</div>
                    <div><strong>City:</strong> ${data.city || 'Unknown'}</div>
                    <div><strong>ISP:</strong> ${data.org || 'Unknown'}</div>
                    <div><strong>Timezone:</strong> ${data.timezone || 'Unknown'}</div>
                `;
            }
            document.getElementById('ipResult').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('ipResult').innerHTML = 
                `<div class="text-danger"><i class="fas fa-exclamation-circle me-2"></i>Failed to lookup IP: ${error.message}</div>`;
        });
    
    // Show modal
    const ipModal = new bootstrap.Modal(document.getElementById('ipModal'));
    ipModal.show();
}

// Export activity log
function exportActivityLog() {
    // Get table data
    const rows = document.querySelectorAll('#activityTable tbody tr');
    let csv = 'Time,Action,Table,Record ID,IP Address,User Agent\n';
    
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const rowData = [
            cells[1].textContent.trim().replace(/\n/g, ' '),
            cells[2].textContent.trim(),
            cells[3].textContent.trim(),
            cells[4].textContent.trim(),
            cells[5].querySelector('code')?.textContent.trim() || '',
            cells[6].textContent.trim()
        ];
        
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
    a.download = `activity_${<?php echo $targetUserId; ?>}_<?php echo date('Y-m-d'); ?>.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Refresh logs
function refreshLogs() {
    const refreshBtn = document.querySelector('button[onclick="refreshLogs()"]');
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    refreshBtn.disabled = true;
    
    setTimeout(() => {
        window.location.reload();
    }, 1000);
}

// Initialize table sorting
document.addEventListener('DOMContentLoaded', function() {
    // Add sorting to table headers
    const headers = document.querySelectorAll('#activityTable thead th');
    headers.forEach((header, index) => {
        if (index !== 7) { // Skip details column
            header.style.cursor = 'pointer';
            header.addEventListener('click', function() {
                sortActivityTable(index);
            });
        }
    });
});

// Sort activity table
function sortActivityTable(columnIndex) {
    const table = document.getElementById('activityTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    const isAscending = table.getAttribute('data-sort-dir') !== 'asc';
    
    rows.sort((a, b) => {
        const aText = a.children[columnIndex].textContent.trim();
        const bText = b.children[columnIndex].textContent.trim();
        
        // For time column (index 1), sort by datetime
        if (columnIndex === 1) {
            const aTime = new Date(a.children[1].querySelector('div.small')?.textContent.trim() || '');
            const bTime = new Date(b.children[1].querySelector('div.small')?.textContent.trim() || '');
            return isAscending ? aTime - bTime : bTime - aTime;
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
#activityTable th {
    cursor: pointer;
    user-select: none;
}

#activityTable th:hover {
    background-color: #f1f3f4;
}

.smaller {
    font-size: 0.8rem;
}

pre {
    white-space: pre-wrap;
    word-wrap: break-word;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
}

.text-truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.card .card-body.p-0 .table {
    margin-bottom: 0;
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
    
    pre {
        background: #f8f9fa !important;
        border: 1px solid #dee2e6 !important;
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
?>