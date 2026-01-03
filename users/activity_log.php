<?php
// users/activity_log.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Activity Log";
$pageIcon = "bi bi-clock-history";
$pageDescription = "View your system activity and audit trail";
$bodyClass = "bg-light";

try {
    require_once '../config.php';
    require_once '../classes/Auth.php';
    
    // Check if session is already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in
    if (!$auth->isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }

    // Get database connection
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $username = $_SESSION['username'] ?? '';
    $user_type = $_SESSION['user_type'] ?? '';
    
    // Initialize variables
    $error = '';
    $activity_logs = [];
    $total_logs = 0;
    $filtered_count = 0;
    $summary_stats = [];
    
    // Define action types and their icons/colors
    $action_types = [
        'login' => ['icon' => 'bi-box-arrow-in-right', 'color' => 'success', 'label' => 'Login'],
        'logout' => ['icon' => 'bi-box-arrow-left', 'color' => 'secondary', 'label' => 'Logout'],
        'create' => ['icon' => 'bi-plus-circle', 'color' => 'primary', 'label' => 'Create'],
        'update' => ['icon' => 'bi-pencil', 'color' => 'warning', 'label' => 'Update'],
        'delete' => ['icon' => 'bi-trash', 'color' => 'danger', 'label' => 'Delete'],
        'view' => ['icon' => 'bi-eye', 'color' => 'info', 'label' => 'View'],
        'search' => ['icon' => 'bi-search', 'color' => 'dark', 'label' => 'Search'],
        'export' => ['icon' => 'bi-download', 'color' => 'success', 'label' => 'Export'],
        'import' => ['icon' => 'bi-upload', 'color' => 'primary', 'label' => 'Import'],
        'password_changed' => ['icon' => 'bi-key', 'color' => 'warning', 'label' => 'Password Changed'],
        'failed_login' => ['icon' => 'bi-exclamation-triangle', 'color' => 'danger', 'label' => 'Failed Login'],
        'failed_password_change' => ['icon' => 'bi-key-fill', 'color' => 'danger', 'label' => 'Failed Password Change'],
        'update_profile' => ['icon' => 'bi-person', 'color' => 'info', 'label' => 'Profile Update'],
        'add_member' => ['icon' => 'bi-person-plus', 'color' => 'success', 'label' => 'Add Family Member'],
        'transfer_request' => ['icon' => 'bi-arrow-left-right', 'color' => 'primary', 'label' => 'Transfer Request'],
    ];
    
    // Define tables and their display names
    $table_names = [
        'users' => 'Users',
        'families' => 'Families',
        'citizens' => 'Citizens',
        'audit_logs' => 'Audit Logs',
        'transfer_requests' => 'Transfer Requests',
        'transfer_history' => 'Transfer History',
        'education' => 'Education Records',
        'employment' => 'Employment Records',
        'health_conditions' => 'Health Conditions',
        'land_details' => 'Land Details',
    ];
    
    // Get filter parameters
    $action_type_filter = $_GET['action_type'] ?? '';
    $table_name_filter = $_GET['table_name'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $search_query = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = 50;
    $offset = ($page - 1) * $per_page;
    
    // Build query with filters
    $where_conditions = ["user_id = ?"];
    $params = [$user_id];
    $types = "i";
    
    if (!empty($action_type_filter)) {
        $where_conditions[] = "action_type = ?";
        $params[] = $action_type_filter;
        $types .= "s";
    }
    
    if (!empty($table_name_filter)) {
        $where_conditions[] = "table_name = ?";
        $params[] = $table_name_filter;
        $types .= "s";
    }
    
    if (!empty($date_from)) {
        $where_conditions[] = "DATE(created_at) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "DATE(created_at) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }
    
    if (!empty($search_query)) {
        $where_conditions[] = "(record_id LIKE ? OR old_values LIKE ? OR new_values LIKE ? OR ip_address LIKE ?)";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ssss";
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM audit_logs $where_clause";
    $count_stmt = $db->prepare($count_query);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_row = $count_result->fetch_assoc();
    $total_logs = $total_row['total'];
    $filtered_count = $total_logs;
    
    // Calculate pagination
    $total_pages = ceil($total_logs / $per_page);
    
    // Get activity logs with pagination
    $logs_query = "SELECT * FROM audit_logs 
                   $where_clause 
                   ORDER BY created_at DESC 
                   LIMIT ? OFFSET ?";
    
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    
    $logs_stmt = $db->prepare($logs_query);
    $logs_stmt->bind_param($types, ...$params);
    $logs_stmt->execute();
    $logs_result = $logs_stmt->get_result();
    $activity_logs = $logs_result->fetch_all(MYSQLI_ASSOC);
    
    // Get summary statistics
    $stats_query = "SELECT 
                    action_type,
                    COUNT(*) as count,
                    DATE(created_at) as log_date
                    FROM audit_logs 
                    WHERE user_id = ? 
                    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY action_type, DATE(created_at)
                    ORDER BY log_date DESC, action_type";
    
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bind_param("i", $user_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    
    $daily_stats = [];
    while ($row = $stats_result->fetch_assoc()) {
        $daily_stats[$row['log_date']][$row['action_type']] = $row['count'];
    }
    
    // Get most active tables
    $tables_query = "SELECT 
                     table_name,
                     COUNT(*) as count
                     FROM audit_logs 
                     WHERE user_id = ? 
                     AND table_name IS NOT NULL
                     GROUP BY table_name
                     ORDER BY count DESC
                     LIMIT 10";
    
    $tables_stmt = $db->prepare($tables_query);
    $tables_stmt->bind_param("i", $user_id);
    $tables_stmt->execute();
    $tables_result = $tables_stmt->get_result();
    $table_stats = $tables_result->fetch_all(MYSQLI_ASSOC);
    
    // Get recent IP addresses
    $ips_query = "SELECT 
                  ip_address,
                  COUNT(*) as count,
                  MAX(created_at) as last_used
                  FROM audit_logs 
                  WHERE user_id = ? 
                  AND ip_address IS NOT NULL
                  GROUP BY ip_address
                  ORDER BY last_used DESC
                  LIMIT 5";
    
    $ips_stmt = $db->prepare($ips_query);
    $ips_stmt->bind_param("i", $user_id);
    $ips_stmt->execute();
    $ips_result = $ips_stmt->get_result();
    $ip_stats = $ips_result->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Activity Log System Error: " . $e->getMessage());
}

// Function to format action type with icon and badge
function formatActionType($type, $action_types) {
    if (isset($action_types[$type])) {
        $config = $action_types[$type];
        return '<span class="badge bg-' . $config['color'] . '">
                <i class="bi ' . $config['icon'] . ' me-1"></i>
                ' . $config['label'] . '
                </span>';
    }
    return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
}

// Function to format table name
function formatTableName($table_name, $table_names) {
    if (isset($table_names[$table_name])) {
        return $table_names[$table_name];
    }
    return ucfirst(str_replace('_', ' ', $table_name));
}

// Function to format JSON data for display
function formatJsonData($json_string) {
    if (empty($json_string) || $json_string === 'null') {
        return '<span class="text-muted fst-italic">No data</span>';
    }
    
    try {
        $data = json_decode($json_string, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            $output = '<div class="json-data">';
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $output .= '<div><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT)) . '</div>';
                } else {
                    $output .= '<div><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value) . '</div>';
                }
            }
            $output .= '</div>';
            return $output;
        }
    } catch (Exception $e) {
        // If not valid JSON, return as plain text
    }
    
    return '<div class="text-truncate" style="max-width: 300px;" title="' . htmlspecialchars($json_string) . '">' . htmlspecialchars($json_string) . '</div>';
}

// Function to format timestamp
function formatTimestamp($timestamp) {
    if (empty($timestamp)) return '';
    
    $date = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($date);
    
    if ($diff->days == 0) {
        if ($diff->h == 0) {
            if ($diff->i < 1) {
                return 'Just now';
            }
            return $diff->i . ' min ago';
        }
        return $diff->h . ' hours ago';
    } elseif ($diff->days == 1) {
        return 'Yesterday at ' . $date->format('H:i');
    } elseif ($diff->days < 7) {
        return $date->format('D, H:i');
    } else {
        return $date->format('Y-m-d H:i');
    }
}

// Function to get browser info from user agent
function getBrowserInfo($user_agent) {
    if (empty($user_agent)) return 'Unknown';
    
    $browser = 'Unknown';
    $os = 'Unknown';
    
    // Browser detection
    if (strpos($user_agent, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (strpos($user_agent, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (strpos($user_agent, 'Safari') !== false) {
        $browser = 'Safari';
    } elseif (strpos($user_agent, 'Edge') !== false) {
        $browser = 'Edge';
    } elseif (strpos($user_agent, 'MSIE') !== false || strpos($user_agent, 'Trident') !== false) {
        $browser = 'Internet Explorer';
    }
    
    // OS detection
    if (strpos($user_agent, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (strpos($user_agent, 'Mac') !== false) {
        $os = 'Mac';
    } elseif (strpos($user_agent, 'Linux') !== false) {
        $os = 'Linux';
    } elseif (strpos($user_agent, 'Android') !== false) {
        $os = 'Android';
    } elseif (strpos($user_agent, 'iPhone') !== false || strpos($user_agent, 'iPad') !== false) {
        $os = 'iOS';
    }
    
    return $browser . ' on ' . $os;
}
?>

<?php require_once '../includes/header.php'; ?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../includes/sidebar.php'; ?>
        </div>
        
        <!-- Main Content -->
        <main class="">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-clock-history me-2"></i>
                    Activity Log
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-outline-secondary me-2" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a href="my_profile.php" class="btn btn-outline-primary">
                        <i class="bi bi-person-circle"></i> Back to Profile
                    </a>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <h2 class="display-6 text-primary"><?php echo number_format($total_logs); ?></h2>
                            <p class="card-text">Total Activities</p>
                            <small class="text-muted">All time</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h2 class="display-6 text-success"><?php echo number_format($filtered_count); ?></h2>
                            <p class="card-text">Filtered Results</p>
                            <small class="text-muted">With current filters</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <h2 class="display-6 text-info"><?php echo isset($daily_stats[date('Y-m-d')]) ? array_sum($daily_stats[date('Y-m-d')]) : 0; ?></h2>
                            <p class="card-text">Today's Activities</p>
                            <small class="text-muted"><?php echo date('M d, Y'); ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card border-warning">
                        <div class="card-body text-center">
                            <h2 class="display-6 text-warning"><?php echo count($ip_stats); ?></h2>
                            <p class="card-text">Unique IPs Used</p>
                            <small class="text-muted">Security check</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters Card -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0">
                        <i class="bi bi-funnel me-2"></i>
                        Filter Activities
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" id="filterForm" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Action Type</label>
                            <select class="form-select" name="action_type">
                                <option value="">All Actions</option>
                                <?php foreach ($action_types as $type => $config): ?>
                                    <option value="<?php echo $type; ?>" <?php echo $action_type_filter === $type ? 'selected' : ''; ?>>
                                        <?php echo $config['label']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Table/Module</label>
                            <select class="form-select" name="table_name">
                                <option value="">All Tables</option>
                                <?php foreach ($table_names as $table => $name): ?>
                                    <option value="<?php echo $table; ?>" <?php echo $table_name_filter === $table ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>"
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>"
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" 
                                   value="<?php echo htmlspecialchars($search_query); ?>"
                                   placeholder="Search in data...">
                        </div>
                        
                        <div class="col-12">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-filter me-1"></i> Apply Filters
                                    </button>
                                    <a href="activity_log.php" class="btn btn-outline-secondary ms-2">
                                        <i class="bi bi-x-circle me-1"></i> Clear All
                                    </a>
                                </div>
                                <div>
                                    <span class="badge bg-light text-dark">
                                        <?php echo number_format($filtered_count); ?> results found
                                    </span>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Activity Logs Table -->
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list-check me-2"></i>
                        Activity History
                    </h5>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light dropdown-toggle" type="button" 
                                data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-download me-1"></i> Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="exportToCSV()"><i class="bi bi-file-earmark-spreadsheet me-2"></i> CSV</a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportToPDF()"><i class="bi bi-file-pdf me-2"></i> PDF</a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportToJSON()"><i class="bi bi-code me-2"></i> JSON</a></li>
                        </ul>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($activity_logs)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-1 text-muted mb-3"></i>
                            <h4>No Activities Found</h4>
                            <p class="text-muted">No activity logs match your filter criteria.</p>
                            <a href="activity_log.php" class="btn btn-primary">
                                <i class="bi bi-arrow-clockwise me-1"></i> Clear Filters
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm" id="activityTable">
                                <thead>
                                    <tr>
                                        <th width="150">Date & Time</th>
                                        <th width="120">Action</th>
                                        <th width="120">Module</th>
                                        <th width="100">Record ID</th>
                                        <th>Details</th>
                                        <th width="150">IP & Browser</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activity_logs as $log): ?>
                                        <tr>
                                            <td>
                                                <div class="small text-muted">
                                                    <?php echo date('Y-m-d', strtotime($log['created_at'])); ?>
                                                </div>
                                                <div class="small">
                                                    <?php echo date('H:i:s', strtotime($log['created_at'])); ?>
                                                </div>
                                                <div class="very-small text-muted">
                                                    <?php echo formatTimestamp($log['created_at']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo formatActionType($log['action_type'], $action_types); ?>
                                            </td>
                                            <td>
                                                <?php if ($log['table_name']): ?>
                                                    <span class="badge bg-light text-dark">
                                                        <?php echo formatTableName($log['table_name'], $table_names); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($log['record_id']): ?>
                                                    <code class="small"><?php echo htmlspecialchars(substr($log['record_id'], 0, 15)); ?></code>
                                                    <?php if (strlen($log['record_id']) > 15): ?>...<?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="activity-details">
                                                    <?php if ($log['old_values'] && $log['new_values']): ?>
                                                        <!-- Change Record -->
                                                        <div class="mb-1">
                                                            <span class="badge bg-light text-dark small">Changed:</span>
                                                            <button type="button" class="btn btn-sm btn-link p-0 ms-1" 
                                                                    data-bs-toggle="collapse" 
                                                                    data-bs-target="#details-<?php echo $log['log_id']; ?>">
                                                                <i class="bi bi-chevron-down"></i> View Changes
                                                            </button>
                                                        </div>
                                                        <div class="collapse" id="details-<?php echo $log['log_id']; ?>">
                                                            <div class="card card-body p-2 small">
                                                                <div class="row">
                                                                    <div class="col-md-6">
                                                                        <strong class="text-danger">Old Values:</strong>
                                                                        <?php echo formatJsonData($log['old_values']); ?>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <strong class="text-success">New Values:</strong>
                                                                        <?php echo formatJsonData($log['new_values']); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php elseif ($log['new_values']): ?>
                                                        <!-- New Record -->
                                                        <div class="mb-1">
                                                            <span class="badge bg-light text-dark small">Created:</span>
                                                            <?php echo formatJsonData($log['new_values']); ?>
                                                        </div>
                                                    <?php elseif ($log['old_values']): ?>
                                                        <!-- Deleted Record -->
                                                        <div class="mb-1">
                                                            <span class="badge bg-light text-dark small">Deleted:</span>
                                                            <?php echo formatJsonData($log['old_values']); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <!-- Simple Action -->
                                                        <span class="text-muted">No additional details</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <code><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></code>
                                                </div>
                                                <div class="very-small text-muted text-truncate" style="max-width: 150px;">
                                                    <?php echo htmlspecialchars(getBrowserInfo($log['user_agent'] ?? '')); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Activity log pagination">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" 
                                           href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                            <i class="bi bi-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                    
                                    <?php 
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                                        </li>
                                        <?php if ($start_page > 2): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">...</span>
                                            </li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" 
                                               href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">...</span>
                                            </li>
                                        <?php endif; ?>
                                        <li class="page-item">
                                            <a class="page-link" 
                                               href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                                <?php echo $total_pages; ?>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" 
                                           href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                            Next <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                                <div class="text-center text-muted small">
                                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                                    | Showing <?php echo min($per_page, count($activity_logs)); ?> of <?php echo number_format($filtered_count); ?> records
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Statistics Row -->
            <div class="row mt-4">
                <!-- Most Active Tables -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-bar-chart me-2"></i>
                                Most Active Modules
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($table_stats)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Module/Table</th>
                                                <th>Activity Count</th>
                                                <th>Percentage</th>
                                                <th>Trend</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($table_stats as $stat): ?>
                                                <tr>
                                                    <td><?php echo formatTableName($stat['table_name'], $table_names); ?></td>
                                                    <td>
                                                        <span class="badge bg-primary"><?php echo $stat['count']; ?></span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $percentage = $total_logs > 0 ? ($stat['count'] / $total_logs * 100) : 0;
                                                        ?>
                                                        <div class="progress" style="height: 10px;">
                                                            <div class="progress-bar bg-info" 
                                                                 style="width: <?php echo $percentage; ?>%"
                                                                 role="progressbar">
                                                            </div>
                                                        </div>
                                                        <small class="text-muted"><?php echo round($percentage, 1); ?>%</small>
                                                    </td>
                                                    <td>
                                                        <?php if ($stat['count'] > 100): ?>
                                                            <i class="bi bi-arrow-up-circle-fill text-success"></i>
                                                        <?php elseif ($stat['count'] > 50): ?>
                                                            <i class="bi bi-dash-circle-fill text-warning"></i>
                                                        <?php else: ?>
                                                            <i class="bi bi-arrow-down-circle-fill text-danger"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center py-3">No module statistics available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Recent IP Addresses -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-warning text-dark">
                            <h6 class="mb-0">
                                <i class="bi bi-pc-display-horizontal me-2"></i>
                                Recent IP Addresses
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($ip_stats)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>IP Address</th>
                                                <th>Usage Count</th>
                                                <th>Last Used</th>
                                                <th>Location*</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ip_stats as $ip): ?>
                                                <tr>
                                                    <td>
                                                        <code><?php echo htmlspecialchars($ip['ip_address']); ?></code>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-warning"><?php echo $ip['count']; ?></span>
                                                    </td>
                                                    <td class="small">
                                                        <?php echo formatTimestamp($ip['last_used']); ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark small">
                                                            <i class="bi bi-geo-alt"></i> Unknown
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="alert alert-info mt-3 small">
                                    <i class="bi bi-info-circle me-1"></i>
                                    <strong>Note:</strong> IP geolocation is not implemented. This would require a geolocation API service.
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center py-3">No IP address data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<style>
    .very-small {
        font-size: 0.75rem;
    }
    .activity-details .card {
        max-height: 200px;
        overflow-y: auto;
    }
    .json-data {
        font-family: 'Courier New', monospace;
        font-size: 0.85rem;
        background-color: #f8f9fa;
        padding: 5px;
        border-radius: 3px;
        margin-top: 3px;
    }
    .json-data div {
        margin-bottom: 2px;
    }
    .progress {
        background-color: #e9ecef;
    }
    .badge {
        font-weight: normal;
    }
    .table-sm th, .table-sm td {
        padding: 0.5rem;
    }
    .table-hover tbody tr:hover {
        background-color: rgba(0, 123, 255, 0.05);
    }
    .page-item.active .page-link {
        background-color: #0d6efd;
        border-color: #0d6efd;
    }
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
    @media print {
        .sidebar-column, .btn-toolbar, .card-header .dropdown, 
        #filterForm, .row.mb-4:first-child, .row.mt-4 {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
        }
        .table {
            font-size: 10pt;
        }
    }
</style>

<script src="../assets/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.3.0/papaparse.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Auto-collapse old details after 5 seconds
        setTimeout(function() {
            const details = document.querySelectorAll('.activity-details .collapse.show');
            details.forEach(function(collapse) {
                const bsCollapse = new bootstrap.Collapse(collapse, {
                    toggle: false
                });
                bsCollapse.hide();
            });
        }, 5000);
        
        // Date range validation
        const dateFrom = document.querySelector('input[name="date_from"]');
        const dateTo = document.querySelector('input[name="date_to"]');
        
        dateFrom.addEventListener('change', function() {
            if (dateTo.value && this.value > dateTo.value) {
                dateTo.value = this.value;
            }
        });
        
        dateTo.addEventListener('change', function() {
            if (dateFrom.value && this.value < dateFrom.value) {
                dateFrom.value = this.value;
            }
        });
        
        // Export functions
        window.exportToCSV = function() {
            const table = document.getElementById('activityTable');
            const rows = table.querySelectorAll('tr');
            const csv = [];
            
            rows.forEach(function(row) {
                const cols = row.querySelectorAll('td, th');
                const rowData = [];
                
                cols.forEach(function(col) {
                    // Remove HTML and get text content
                    let text = col.textContent.trim();
                    text = text.replace(/\n/g, ' ');
                    text = text.replace(/\s+/g, ' ');
                    rowData.push('"' + text + '"');
                });
                
                csv.push(rowData.join(','));
            });
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'activity_log_' + new Date().toISOString().slice(0, 10) + '.csv');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        };
        
        window.exportToJSON = function() {
            const table = document.getElementById('activityTable');
            const rows = table.querySelectorAll('tr');
            const headers = [];
            const data = [];
            
            // Get headers
            const headerRow = rows[0];
            headerRow.querySelectorAll('th').forEach(function(th) {
                headers.push(th.textContent.trim());
            });
            
            // Get data rows
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const cols = row.querySelectorAll('td');
                const rowData = {};
                
                cols.forEach(function(col, index) {
                    if (headers[index]) {
                        rowData[headers[index]] = col.textContent.trim();
                    }
                });
                
                data.push(rowData);
            }
            
            const jsonContent = JSON.stringify(data, null, 2);
            const blob = new Blob([jsonContent], { type: 'application/json;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'activity_log_' + new Date().toISOString().slice(0, 10) + '.json');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        };
        
        window.exportToPDF = function() {
            alert('PDF export would require additional libraries. For now, please use the print function (Ctrl+P) and save as PDF.');
            // In production, you could use jsPDF with html2canvas
            // const { jsPDF } = window.jspdf;
            // const doc = new jsPDF();
            // doc.text('Activity Log', 20, 20);
            // doc.save('activity_log.pdf');
        };
        
        // Auto-refresh logs every 30 seconds if on first page
        const urlParams = new URLSearchParams(window.location.search);
        const currentPage = parseInt(urlParams.get('page')) || 1;
        
        if (currentPage === 1 && !urlParams.has('search') && !urlParams.has('action_type') && 
            !urlParams.has('table_name') && !urlParams.has('date_from') && !urlParams.has('date_to')) {
            
            let refreshTimer = setTimeout(function refreshLogs() {
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const newDoc = parser.parseFromString(html, 'text/html');
                        const newTable = newDoc.querySelector('#activityTable tbody');
                        const currentTable = document.querySelector('#activityTable tbody');
                        
                        if (newTable && currentTable && newTable.innerHTML !== currentTable.innerHTML) {
                            currentTable.innerHTML = newTable.innerHTML;
                            
                            // Reinitialize collapse functionality
                            const collapseElements = currentTable.querySelectorAll('[data-bs-toggle="collapse"]');
                            collapseElements.forEach(function(btn) {
                                btn.addEventListener('click', function() {
                                    const target = document.querySelector(this.getAttribute('data-bs-target'));
                                    const bsCollapse = new bootstrap.Collapse(target, {
                                        toggle: true
                                    });
                                });
                            });
                            
                            // Show notification
                            showNotification('Activity log has been updated', 'info');
                        }
                        
                        refreshTimer = setTimeout(refreshLogs, 30000);
                    })
                    .catch(error => {
                        console.error('Failed to refresh logs:', error);
                        refreshTimer = setTimeout(refreshLogs, 30000);
                    });
            }, 30000);
            
            // Stop auto-refresh when page is hidden
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    clearTimeout(refreshTimer);
                } else {
                    refreshTimer = setTimeout(refreshLogs, 30000);
                }
            });
        }
        
        function showNotification(message, type) {
            // Remove existing notification
            const existing = document.querySelector('.auto-refresh-notification');
            if (existing) existing.remove();
            
            // Create new notification
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} auto-refresh-notification alert-dismissible fade show`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                max-width: 300px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            `;
            notification.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="bi bi-info-circle me-2"></i>
                    <div class="flex-grow-1">${message}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Auto-dismiss after 3 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    const bsAlert = new bootstrap.Alert(notification);
                    bsAlert.close();
                }
            }, 3000);
        }
    });
</script>

<?php 
// Include footer if exists
$footer_path = '../includes/footer.php';
if (file_exists($footer_path)) {
    include $footer_path;
} else {
    echo '</div></div></body></html>';
}
?>