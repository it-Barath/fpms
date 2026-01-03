<?php
// audit_logs.php
// View system audit logs and activities

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
$pageTitle = "Audit Logs";
$pageDescription = "System activity logs and audit trail";

// Handle filters
$filters = [];
$filterParams = [];
$filterTypes = "";

// Date range filter
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

if (!empty($startDate)) {
    $filters[] = "DATE(al.created_at) >= ?";
    $filterParams[] = $startDate;
    $filterTypes .= 's';
}

if (!empty($endDate)) {
    $filters[] = "DATE(al.created_at) <= ?";
    $filterParams[] = $endDate;
    $filterTypes .= 's';
}

// User filter
$userId = $_GET['user_id'] ?? '';
if (!empty($userId)) {
    $filters[] = "al.user_id = ?";
    $filterParams[] = $userId;
    $filterTypes .= 'i';
}

// Action type filter
$actionType = $_GET['action_type'] ?? '';
if (!empty($actionType)) {
    $filters[] = "al.action_type = ?";
    $filterParams[] = $actionType;
    $filterTypes .= 's';
}

// IP address filter
$ipAddress = $_GET['ip_address'] ?? '';
if (!empty($ipAddress)) {
    $filters[] = "al.ip_address LIKE ?";
    $filterParams[] = "%$ipAddress%";
    $filterTypes .= 's';
}

// Search term filter
$searchTerm = $_GET['search'] ?? '';
if (!empty($searchTerm)) {
    $filters[] = "(al.action_type LIKE ? OR al.table_name LIKE ? OR al.record_id LIKE ? OR u.username LIKE ? OR u.office_name LIKE ?)";
    $searchPattern = "%$searchTerm%";
    for ($i = 0; $i < 5; $i++) {
        $filterParams[] = $searchPattern;
        $filterTypes .= 's';
    }
}

// Get pagination parameters
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) && in_array($_GET['limit'], [10, 25, 50, 100]) ? (int)$_GET['limit'] : 25;
$offset = ($page - 1) * $limit;

// Get audit logs with filters
$auditLogs = [];
$totalLogs = 0;
$totalPages = 0;

try {
    // Build query with filters
    $whereClause = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";
    
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total 
                 FROM audit_logs al
                 LEFT JOIN users u ON al.user_id = u.user_id
                 $whereClause";
    
    $countStmt = $userManager->getConnection()->prepare($countSql);
    if (!empty($filterParams)) {
        $countStmt->bind_param($filterTypes, ...$filterParams);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRow = $countResult->fetch_assoc();
    $totalLogs = $totalRow['total'] ?? 0;
    $totalPages = ceil($totalLogs / $limit);
    
    // Get logs for current page
    $sql = "SELECT al.*, 
                   u.username, 
                   u.user_type, 
                   u.office_name,
                   u.office_code
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            $whereClause
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $userManager->getConnection()->prepare($sql);
    
    // Add pagination parameters to filter params
    $filterParams[] = $limit;
    $filterParams[] = $offset;
    $filterTypes .= 'ii';
    
    if (!empty($filterParams)) {
        $stmt->bind_param($filterTypes, ...$filterParams);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $auditLogs[] = $row;
    }
    
} catch (Exception $e) {
    error_log("Error getting audit logs: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading audit logs: " . $e->getMessage();
}

// Get distinct action types for filter dropdown
$actionTypes = [];
try {
    $typeSql = "SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type";
    $typeResult = $userManager->getConnection()->query($typeSql);
    while ($row = $typeResult->fetch_assoc()) {
        $actionTypes[] = $row['action_type'];
    }
} catch (Exception $e) {
    error_log("Error getting action types: " . $e->getMessage());
}

// Get recent users for filter dropdown
$recentUsers = [];
try {
    $userSql = "SELECT DISTINCT u.user_id, u.username, u.office_name 
                FROM audit_logs al
                JOIN users u ON al.user_id = u.user_id
                WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY u.username
                LIMIT 50";
    $userResult = $userManager->getConnection()->query($userSql);
    while ($row = $userResult->fetch_assoc()) {
        $recentUsers[] = $row;
    }
} catch (Exception $e) {
    error_log("Error getting recent users: " . $e->getMessage());
}

// Get statistics
$stats = [
    'today' => 0,
    'yesterday' => 0,
    'this_week' => 0,
    'this_month' => 0,
    'by_action_type' => []
];

try {
    // Today's logs
    $todaySql = "SELECT COUNT(*) as count FROM audit_logs WHERE DATE(created_at) = CURDATE()";
    $todayResult = $userManager->getConnection()->query($todaySql);
    $stats['today'] = $todayResult ? $todayResult->fetch_assoc()['count'] : 0;
    
    // Yesterday's logs
    $yesterdaySql = "SELECT COUNT(*) as count FROM audit_logs WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    $yesterdayResult = $userManager->getConnection()->query($yesterdaySql);
    $stats['yesterday'] = $yesterdayResult ? $yesterdayResult->fetch_assoc()['count'] : 0;
    
    // This week's logs
    $weekSql = "SELECT COUNT(*) as count FROM audit_logs WHERE YEARWEEK(created_at) = YEARWEEK(CURDATE())";
    $weekResult = $userManager->getConnection()->query($weekSql);
    $stats['this_week'] = $weekResult ? $weekResult->fetch_assoc()['count'] : 0;
    
    // This month's logs
    $monthSql = "SELECT COUNT(*) as count FROM audit_logs WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
    $monthResult = $userManager->getConnection()->query($monthSql);
    $stats['this_month'] = $monthResult ? $monthResult->fetch_assoc()['count'] : 0;
    
    // Count by action type
    $typeCountSql = "SELECT action_type, COUNT(*) as count 
                     FROM audit_logs 
                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY action_type 
                     ORDER BY count DESC 
                     LIMIT 10";
    $typeCountResult = $userManager->getConnection()->query($typeCountSql);
    while ($row = $typeCountResult->fetch_assoc()) {
        $stats['by_action_type'][$row['action_type']] = $row['count'];
    }
    
} catch (Exception $e) {
    error_log("Error getting audit statistics: " . $e->getMessage());
}

// Include header
include '../includes/header.php';
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
                        <i class="fas fa-clipboard-list me-2"></i><?php echo $pageTitle; ?>
                    </h1>
                    <p class="lead mb-0"><?php echo $pageDescription; ?></p>
                </div>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="exportLogs()">
                            <i class="fas fa-file-excel me-1"></i> Export
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearOldLogs()">
                            <i class="fas fa-trash me-1"></i> Clear Old
                        </button>
                    </div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
                        <i class="fas fa-filter me-1"></i> Filters
                    </button>
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
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Today's Activities
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['today']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-calendar-day fa-2x text-gray-300"></i>
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
                                        This Week
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['this_week']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-calendar-week fa-2x text-gray-300"></i>
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
                                        This Month
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['this_month']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
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
                                        Total Logs
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($totalLogs); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-database fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Summary -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0">Current Filters:</h6>
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        <?php if (!empty($startDate)): ?>
                                            <span class="badge bg-info">
                                                From: <?php echo htmlspecialchars($startDate); ?>
                                                <a href="<?php echo removeQueryParam('start_date'); ?>" class="text-white ms-1">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($endDate)): ?>
                                            <span class="badge bg-info">
                                                To: <?php echo htmlspecialchars($endDate); ?>
                                                <a href="<?php echo removeQueryParam('end_date'); ?>" class="text-white ms-1">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($actionType)): ?>
                                            <span class="badge bg-success">
                                                Action: <?php echo htmlspecialchars($actionType); ?>
                                                <a href="<?php echo removeQueryParam('action_type'); ?>" class="text-white ms-1">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($ipAddress)): ?>
                                            <span class="badge bg-warning">
                                                IP: <?php echo htmlspecialchars($ipAddress); ?>
                                                <a href="<?php echo removeQueryParam('ip_address'); ?>" class="text-white ms-1">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($searchTerm)): ?>
                                            <span class="badge bg-primary">
                                                Search: <?php echo htmlspecialchars($searchTerm); ?>
                                                <a href="<?php echo removeQueryParam('search'); ?>" class="text-white ms-1">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (empty($startDate) && empty($endDate) && empty($actionType) && empty($ipAddress) && empty($searchTerm)): ?>
                                            <span class="text-muted">No filters applied</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <a href="audit_logs.php" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-times me-1"></i> Clear All
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Audit Logs Table -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-table me-2"></i>Audit Logs
                        <span class="badge bg-primary ms-2"><?php echo number_format($totalLogs); ?> total</span>
                    </h6>
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <select class="form-select form-select-sm" onchange="changeLimit(this.value)">
                                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 per page</option>
                                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="refreshTable()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($auditLogs)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                            <h5>No Audit Logs Found</h5>
                            <p class="text-muted">No activity logs match your current filters.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
                                <i class="fas fa-filter me-1"></i> Adjust Filters
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="auditLogsTable" width="100%" cellspacing="0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Timestamp</th>
                                        <th>Action</th>
                                        <th>User</th>
                                        <th>Details</th>
                                        <th>IP Address</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($auditLogs as $index => $log): 
                                        $logNumber = $offset + $index + 1;
                                        $icon = getActionIcon($log['action_type']);
                                        $color = getActionColor($log['action_type']);
                                    ?>
                                    <tr>
                                        <td><?php echo $logNumber; ?></td>
                                        <td>
                                            <div class="small">
                                                <div><?php echo date('d M Y', strtotime($log['created_at'])); ?></div>
                                                <div><?php echo date('h:i:s A', strtotime($log['created_at'])); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $color; ?>">
                                                <i class="fas fa-<?php echo $icon; ?> me-1"></i>
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $log['action_type']))); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($log['username']): ?>
                                                <div class="small">
                                                    <strong><?php echo htmlspecialchars($log['username']); ?></strong><br>
                                                    <span class="text-muted">
                                                        <?php echo htmlspecialchars($log['office_name'] ?? 'N/A'); ?>
                                                        (<?php echo htmlspecialchars($log['user_type'] ?? 'system'); ?>)
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">System / Automated</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <?php if ($log['table_name']): ?>
                                                    <div><strong>Table:</strong> <?php echo htmlspecialchars($log['table_name']); ?></div>
                                                <?php endif; ?>
                                                
                                                <?php if ($log['record_id']): ?>
                                                    <div><strong>Record ID:</strong> <?php echo htmlspecialchars($log['record_id']); ?></div>
                                                <?php endif; ?>
                                                
                                                <?php if ($log['old_values']): ?>
                                                    <div class="mt-1">
                                                        <button class="btn btn-sm btn-outline-info" type="button" 
                                                                data-bs-toggle="collapse" 
                                                                data-bs-target="#oldValues<?php echo $log['log_id']; ?>">
                                                            View Old Values
                                                        </button>
                                                        <div class="collapse mt-1" id="oldValues<?php echo $log['log_id']; ?>">
                                                            <pre class="small p-2 bg-light"><?php echo htmlspecialchars($log['old_values']); ?></pre>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($log['new_values']): ?>
                                                    <div class="mt-1">
                                                        <button class="btn btn-sm btn-outline-success" type="button" 
                                                                data-bs-toggle="collapse" 
                                                                data-bs-target="#newValues<?php echo $log['log_id']; ?>">
                                                            View New Values
                                                        </button>
                                                        <div class="collapse mt-1" id="newValues<?php echo $log['log_id']; ?>">
                                                            <pre class="small p-2 bg-light"><?php echo htmlspecialchars($log['new_values']); ?></pre>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($log['ip_address']): ?>
                                                <span class="small">
                                                    <?php echo htmlspecialchars($log['ip_address']); ?><br>
                                                    <?php if ($log['user_agent']): ?>
                                                        <button class="btn btn-sm btn-outline-secondary mt-1" type="button" 
                                                                data-bs-toggle="tooltip" 
                                                                title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                                            <i class="fas fa-info-circle"></i> User Agent
                                                        </button>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-info" 
                                                        onclick="viewLogDetails(<?php echo $log['log_id']; ?>)"
                                                        title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger"
                                                        onclick="deleteLog(<?php echo $log['log_id']; ?>)"
                                                        title="Delete Log">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <nav aria-label="Audit logs pagination">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo getPageLink($page - 1); ?>">Previous</a>
                                </li>
                                
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo getPageLink($i); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo getPageLink($page + 1); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        
                        <div class="text-center text-muted small mt-3">
                            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalLogs); ?> of <?php echo number_format($totalLogs); ?> logs
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Statistics Chart -->
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-chart-pie me-2"></i>Activity by Action Type (Last 30 days)
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($stats['by_action_type'])): ?>
                                <p class="text-muted text-center py-3">No activity data available</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Action Type</th>
                                                <th>Count</th>
                                                <th width="50%">Percentage</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $totalActions = array_sum($stats['by_action_type']);
                                            foreach ($stats['by_action_type'] as $type => $count): 
                                                $percentage = $totalActions > 0 ? round(($count / $totalActions) * 100, 1) : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-<?php echo getActionColor($type); ?>">
                                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $type))); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo number_format($count); ?></td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar bg-<?php echo getActionColor($type); ?>" 
                                                             style="width: <?php echo $percentage; ?>%">
                                                            <?php if ($percentage > 15): ?>
                                                                <?php echo $percentage; ?>%
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <?php if ($percentage <= 15): ?>
                                                        <small class="text-muted"><?php echo $percentage; ?>%</small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-chart-line me-2"></i>Recent Activity Trend
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="text-center py-4">
                                <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                <h6>Activity Trend Chart</h6>
                                <p class="text-muted small">Would show daily activity counts here</p>
                                <button class="btn btn-sm btn-outline-primary" onclick="generateActivityReport()">
                                    <i class="fas fa-download me-1"></i> Generate Report
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Filter Modal -->
<div class="modal fade" id="filterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="GET" action="audit_logs.php">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-filter me-2"></i>Filter Audit Logs
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                   value="<?php echo htmlspecialchars($startDate); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                   value="<?php echo htmlspecialchars($endDate); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="action_type" class="form-label">Action Type</label>
                            <select class="form-select" id="action_type" name="action_type">
                                <option value="">All Actions</option>
                                <?php foreach ($actionTypes as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" 
                                        <?php echo $actionType == $type ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $type))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="user_id" class="form-label">User</label>
                            <select class="form-select" id="user_id" name="user_id">
                                <option value="">All Users</option>
                                <?php foreach ($recentUsers as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>" 
                                        <?php echo $userId == $user['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['username'] . ' - ' . $user['office_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="ip_address" class="form-label">IP Address</label>
                            <input type="text" class="form-control" id="ip_address" name="ip_address" 
                                   value="<?php echo htmlspecialchars($ipAddress); ?>"
                                   placeholder="e.g., 192.168.1.1">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="search" class="form-label">Search Term</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($searchTerm); ?>"
                                   placeholder="Search in action, table, record, etc.">
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Tip:</strong> Leave fields empty to show all logs. Dates are inclusive.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Log Details Modal -->
<div class="modal fade" id="logDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2"></i>Audit Log Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="logDetailsContent">
                <!-- Details will be loaded here via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-danger" onclick="deleteCurrentLog()">
                    <i class="fas fa-trash me-1"></i> Delete Log
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Include Footer -->
<?php include '../includes/footer.php'; ?>

<!-- JavaScript -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#auditLogsTable').DataTable({
        "order": [[1, "desc"]],
        "pageLength": <?php echo $limit; ?>,
        "searching": false,
        "lengthChange": false,
        "info": false,
        "paging": false,
        "language": {
            "zeroRecords": "No matching logs found"
        }
    });
    
    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
});

// Helper functions
function getPageLink(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    return url.toString();
}

function removeQueryParam(param) {
    const url = new URL(window.location.href);
    url.searchParams.delete(param);
    url.searchParams.set('page', '1'); // Reset to first page
    return url.toString();
}

function changeLimit(limit) {
    const url = new URL(window.location.href);
    url.searchParams.set('limit', limit);
    url.searchParams.set('page', '1'); // Reset to first page
    window.location.href = url.toString();
}

function refreshTable() {
    window.location.reload();
}

function exportLogs() {
    // Get current filter parameters
    const params = new URLSearchParams(window.location.search);
    
    // Redirect to export script
    window.location.href = 'export_audit_logs.php?' + params.toString();
}

function clearOldLogs() {
    if (confirm('Are you sure you want to clear audit logs older than 90 days?\nThis action cannot be undone.')) {
        window.location.href = 'clear_audit_logs.php?days=90';
    }
}

function viewLogDetails(logId) {
    // Load log details via AJAX
    fetch(`get_log_details.php?id=${logId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('logDetailsContent').innerHTML = html;
            const modal = new bootstrap.Modal(document.getElementById('logDetailsModal'));
            modal.show();
        })
        .catch(error => {
            alert('Error loading log details: ' + error);
        });
}

function deleteLog(logId) {
    if (confirm('Are you sure you want to delete this audit log?\nThis action cannot be undone.')) {
        fetch(`delete_log.php?id=${logId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Log deleted successfully');
                window.location.reload();
            } else {
                alert('Error deleting log: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}

function deleteCurrentLog() {
    const logId = document.getElementById('logDetailsModal').getAttribute('data-log-id');
    if (logId) {
        deleteLog(logId);
    }
}

function generateActivityReport() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = 'generate_activity_report.php?' + params.toString();
}
</script>

<!-- PHP Helper Functions -->
<?php
function getActionIcon($actionType) {
    $icons = [
        'login' => 'sign-in-alt',
        'logout' => 'sign-out-alt',
        'password_reset' => 'key',
        'user_created' => 'user-plus',
        'user_updated' => 'user-edit',
        'user_deleted' => 'user-times',
        'district_created' => 'building',
        'district_updated' => 'edit',
        'form_submission' => 'file-upload',
        'form_approval' => 'check-circle',
        'form_rejection' => 'times-circle',
        'transfer_request' => 'exchange-alt',
        'transfer_approval' => 'check-double',
        'system_error' => 'exclamation-triangle',
        'backup_created' => 'save',
        'settings_updated' => 'cog',
        'report_generated' => 'chart-bar'
    ];
    
    return $icons[$actionType] ?? 'circle';
}

function getActionColor($actionType) {
    $colors = [
        'login' => 'success',
        'logout' => 'secondary',
        'password_reset' => 'warning',
        'user_created' => 'primary',
        'user_updated' => 'info',
        'user_deleted' => 'danger',
        'district_created' => 'primary',
        'district_updated' => 'info',
        'form_submission' => 'primary',
        'form_approval' => 'success',
        'form_rejection' => 'danger',
        'transfer_request' => 'warning',
        'transfer_approval' => 'success',
        'system_error' => 'danger',
        'backup_created' => 'success',
        'settings_updated' => 'info',
        'report_generated' => 'primary'
    ];
    
    return $colors[$actionType] ?? 'secondary';
}

function getPageLink($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'audit_logs.php?' . http_build_query($params);
}

function removeQueryParam($param) {
    $params = $_GET;
    unset($params[$param]);
    $params['page'] = 1; // Reset to first page
    return 'audit_logs.php?' . http_build_query($params);
}
?>
</body>
</html>