<?php
// division/gn_divisions.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Manage GN Divisions";
$pageIcon = "bi bi-diagram-3-fill";
$pageDescription = "View and manage GN divisions in your division";
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
    
    // Check if user is logged in and has division level access
    if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'division') {
        header('Location: ../login.php');
        exit();
    }

    // Get database connections
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Main database connection failed");
    }
    
    $ref_db = getRefConnection();
    if (!$ref_db) {
        throw new Exception("Reference database connection failed");
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $division_code = $_SESSION['office_code'] ?? '';
    $username = $_SESSION['username'] ?? '';
    
    // Initialize variables
    $error = '';
    $success = '';
    $gn_divisions = [];
    $division_stats = [];
    $search_query = '';
    $filter_status = 'all';
    
    // Get division information
    $division_query = "SELECT Division_Name, District_Name, Province_Name 
                      FROM mobile_service.fix_work_station 
                      WHERE Division_Code = ? 
                      LIMIT 1";
    
    $division_stmt = $ref_db->prepare($division_query);
    $division_stmt->bind_param("s", $division_code);
    $division_stmt->execute();
    $division_result = $division_stmt->get_result();
    $division_info = $division_result->fetch_assoc();
    
    if (!$division_info) {
        throw new Exception("Division information not found");
    }
    
    // Get filter parameters
    $search_query = $_GET['search'] ?? '';
    $filter_status = $_GET['status'] ?? 'all';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = 25;
    $offset = ($page - 1) * $per_page;
    
    // Build base query
    $base_query = "SELECT 
                  fws.GN,
                  fws.GN_ID,
                  fws.Division_Name,
                  fws.District_Name,
                  fws.Province_Name,
                  fws.Division_Code,
                  u.user_id,
                  u.username,
                  u.is_active as user_active,
                  u.last_login,
                  (SELECT COUNT(*) FROM families WHERE gn_id = fws.GN_ID) as total_families,
                  (SELECT COUNT(*) FROM citizens c 
                   JOIN families f ON c.family_id = f.family_id 
                   WHERE f.gn_id = fws.GN_ID) as total_citizens,
                  (SELECT COUNT(*) FROM families 
                   WHERE gn_id = fws.GN_ID AND has_pending_transfer = 1) as pending_transfers
                  FROM mobile_service.fix_work_station fws
                  LEFT JOIN users u ON fws.GN_ID = u.office_code AND u.user_type = 'gn'
                  WHERE fws.Division_Code = ?";
    
    $params = [$division_code];
    $types = "s";
    
    // Add search conditions
    if (!empty($search_query)) {
        $base_query .= " AND (fws.GN LIKE ? OR fws.GN_ID LIKE ? OR u.username LIKE ?)";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }
    
    // Add status filter
    if ($filter_status === 'active') {
        $base_query .= " AND u.is_active = 1";
    } elseif ($filter_status === 'inactive') {
        $base_query .= " AND u.is_active = 0";
    } elseif ($filter_status === 'unassigned') {
        $base_query .= " AND u.user_id IS NULL";
    }
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM ($base_query) as subquery";
    $count_stmt = $ref_db->prepare($count_query);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_row = $count_result->fetch_assoc();
    $total_divisions = $total_row['total'];
    
    // Calculate pagination
    $total_pages = ceil($total_divisions / $per_page);
    
    // Get GN divisions with pagination
    $gn_query = $base_query . " ORDER BY fws.GN ASC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    
    $gn_stmt = $ref_db->prepare($gn_query);
    $gn_stmt->bind_param($types, ...$params);
    $gn_stmt->execute();
    $gn_result = $gn_stmt->get_result();
    $gn_divisions = $gn_result->fetch_all(MYSQLI_ASSOC);
    
    // Get division statistics
    $stats_query = "SELECT 
                    COUNT(DISTINCT fws.GN_ID) as total_gns,
                    COUNT(DISTINCT u.user_id) as assigned_users,
                    SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) as active_users,
                    (SELECT COUNT(*) FROM families f 
                     JOIN mobile_service.fix_work_station fws2 ON f.gn_id = fws2.GN_ID 
                     WHERE fws2.Division_Code = ?) as total_families,
                    (SELECT COUNT(*) FROM citizens c 
                     JOIN families f ON c.family_id = f.family_id 
                     JOIN mobile_service.fix_work_station fws2 ON f.gn_id = fws2.GN_ID 
                     WHERE fws2.Division_Code = ?) as total_citizens
                    FROM mobile_service.fix_work_station fws
                    LEFT JOIN users u ON fws.GN_ID = u.office_code AND u.user_type = 'gn'
                    WHERE fws.Division_Code = ?";
    
    $stats_stmt = $ref_db->prepare($stats_query);
    $stats_stmt->bind_param("sss", $division_code, $division_code, $division_code);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $division_stats = $stats_result->fetch_assoc();
    
    // Process actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (isset($_POST['assign_user'])) {
                // Assign user to GN division
                $gn_id = $_POST['gn_id'] ?? '';
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                
                // Validate inputs
                if (empty($gn_id) || empty($username) || empty($password)) {
                    throw new Exception("GN ID, username, and password are required");
                }
                
                // Check if GN exists
                $check_gn = "SELECT GN FROM mobile_service.fix_work_station WHERE GN_ID = ? AND Division_Code = ?";
                $check_stmt = $ref_db->prepare($check_gn);
                $check_stmt->bind_param("ss", $gn_id, $division_code);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception("GN division not found in your division");
                }
                
                // Check if user already exists
                $check_user = "SELECT user_id FROM users WHERE username = ?";
                $user_stmt = $db->prepare($check_user);
                $user_stmt->bind_param("s", $username);
                $user_stmt->execute();
                
                if ($user_stmt->get_result()->num_rows > 0) {
                    throw new Exception("Username already exists");
                }
                
                // Hash password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Get GN name
                $gn_row = $check_result->fetch_assoc();
                $gn_name = $gn_row['GN'];
                
                // Create user
                $insert_query = "INSERT INTO users 
                                (username, password_hash, user_type, office_code, office_name, 
                                 email, phone, is_active, created_at) 
                                VALUES (?, ?, 'gn', ?, ?, ?, ?, 1, NOW())";
                
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->bind_param("ssssss", $username, $password_hash, $gn_id, 
                                        $gn_name, $email, $phone);
                
                if (!$insert_stmt->execute()) {
                    throw new Exception("Failed to create user: " . $insert_stmt->error);
                }
                
                // Log the action
                $audit_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values) 
                               VALUES (?, 'assign_gn_user', 'users', ?, ?)";
                $audit_stmt = $db->prepare($audit_query);
                $new_values = json_encode([
                    'gn_id' => $gn_id,
                    'gn_name' => $gn_name,
                    'username' => $username
                ]);
                $audit_stmt->bind_param("iis", $user_id, $gn_id, $new_values);
                $audit_stmt->execute();
                
                $success = "User assigned to GN division successfully!";
                
            } elseif (isset($_POST['update_user'])) {
                // Update GN user
                $user_id_update = $_POST['user_id'] ?? 0;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                
                // Validate user belongs to this division
                $check_user = "SELECT u.* FROM users u
                              JOIN mobile_service.fix_work_station fws ON u.office_code = fws.GN_ID
                              WHERE u.user_id = ? AND fws.Division_Code = ? AND u.user_type = 'gn'";
                $check_stmt = $db->prepare($check_user);
                $check_stmt->bind_param("is", $user_id_update, $division_code);
                $check_stmt->execute();
                $user_result = $check_stmt->get_result();
                
                if ($user_result->num_rows === 0) {
                    throw new Exception("User not found or not authorized");
                }
                
                // Update user
                $update_query = "UPDATE users SET 
                                is_active = ?, 
                                email = ?, 
                                phone = ?, 
                                updated_at = NOW() 
                                WHERE user_id = ?";
                
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bind_param("issi", $is_active, $email, $phone, $user_id_update);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update user: " . $update_stmt->error);
                }
                
                // Log the action
                $audit_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values) 
                               VALUES (?, 'update_gn_user', 'users', ?, ?)";
                $audit_stmt = $db->prepare($audit_query);
                $new_values = json_encode([
                    'user_id' => $user_id_update,
                    'is_active' => $is_active,
                    'email' => $email,
                    'phone' => $phone
                ]);
                $audit_stmt->bind_param("iis", $user_id, $user_id_update, $new_values);
                $audit_stmt->execute();
                
                $success = "User updated successfully!";
                
            } elseif (isset($_POST['reset_password'])) {
                // Reset GN user password
                $user_id_reset = $_POST['user_id'] ?? 0;
                $new_password = bin2hex(random_bytes(8)); // Generate random password
                
                // Validate user belongs to this division
                $check_user = "SELECT u.* FROM users u
                              JOIN mobile_service.fix_work_station fws ON u.office_code = fws.GN_ID
                              WHERE u.user_id = ? AND fws.Division_Code = ? AND u.user_type = 'gn'";
                $check_stmt = $db->prepare($check_user);
                $check_stmt->bind_param("is", $user_id_reset, $division_code);
                $check_stmt->execute();
                $user_result = $check_stmt->get_result();
                
                if ($user_result->num_rows === 0) {
                    throw new Exception("User not found or not authorized");
                }
                
                // Hash new password
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update password
                $update_query = "UPDATE users SET 
                                password_hash = ?, 
                                updated_at = NOW() 
                                WHERE user_id = ?";
                
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bind_param("si", $password_hash, $user_id_reset);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to reset password: " . $update_stmt->error);
                }
                
                // Log the action
                $audit_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values) 
                               VALUES (?, 'reset_gn_password', 'users', ?, ?)";
                $audit_stmt = $db->prepare($audit_query);
                $new_values = json_encode([
                    'user_id' => $user_id_reset,
                    'password_reset' => true
                ]);
                $audit_stmt->bind_param("iis", $user_id, $user_id_reset, $new_values);
                $audit_stmt->execute();
                
                $success = "Password reset successfully! New password: <strong>$new_password</strong>";
                
            } elseif (isset($_POST['export_data'])) {
                // Export GN divisions data
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="gn_divisions_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                
                // CSV headers
                fputcsv($output, [
                    'GN Division',
                    'GN ID',
                    'Division',
                    'District',
                    'Province',
                    'Assigned User',
                    'User Status',
                    'Last Login',
                    'Total Families',
                    'Total Citizens',
                    'Pending Transfers'
                ]);
                
                // Get all data for export
                $export_query = str_replace("LIMIT ? OFFSET ?", "", $base_query);
                $export_stmt = $ref_db->prepare($export_query);
                
                // Remove limit/offset params
                $export_params = array_slice($params, 0, count($params) - 2);
                $export_types = substr($types, 0, -2);
                
                if (!empty($export_params)) {
                    $export_stmt->bind_param($export_types, ...$export_params);
                }
                $export_stmt->execute();
                $export_result = $export_stmt->get_result();
                
                while ($row = $export_result->fetch_assoc()) {
                    fputcsv($output, [
                        $row['GN'],
                        $row['GN_ID'],
                        $row['Division_Name'],
                        $row['District_Name'],
                        $row['Province_Name'],
                        $row['username'] ?: 'Not Assigned',
                        $row['user_active'] ? 'Active' : ($row['username'] ? 'Inactive' : 'Unassigned'),
                        $row['last_login'] ? date('Y-m-d H:i', strtotime($row['last_login'])) : 'Never',
                        $row['total_families'],
                        $row['total_citizens'],
                        $row['pending_transfers']
                    ]);
                }
                
                fclose($output);
                exit();
            }
            
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
            error_log("GN Divisions Action Error: " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("GN Divisions System Error: " . $e->getMessage());
}

// Helper function to get status badge
function getStatusBadge($username, $is_active) {
    if (!$username) {
        return '<span class="badge bg-secondary">Unassigned</span>';
    }
    return $is_active 
        ? '<span class="badge bg-success">Active</span>' 
        : '<span class="badge bg-danger">Inactive</span>';
}

// Helper function to format last login
function formatLastLogin($timestamp) {
    if (!$timestamp) return '<span class="text-muted">Never</span>';
    
    $date = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($date);
    
    if ($diff->days == 0) {
        return 'Today ' . $date->format('H:i');
    } elseif ($diff->days == 1) {
        return 'Yesterday ' . $date->format('H:i');
    } elseif ($diff->days < 7) {
        return $date->format('D, H:i');
    } else {
        return $date->format('Y-m-d H:i');
    }
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
        <main class="col-md-9 ms-sm-auto col-xl-10 px-md-4 main-content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-diagram-3-fill me-2"></i>
                    Manage GN Divisions
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-outline-primary me-2" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-speedometer2"></i> Dashboard
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
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Division Overview -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-building me-2"></i>
                                Division Overview: <?php echo htmlspecialchars($division_info['Division_Name']); ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <div class="card border-primary text-center h-100">
                                        <div class="card-body">
                                            <h2 class="display-6 text-primary">
                                                <?php echo number_format($division_stats['total_gns'] ?? 0); ?>
                                            </h2>
                                            <p class="card-text">GN Divisions</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card border-success text-center h-100">
                                        <div class="card-body">
                                            <h2 class="display-6 text-success">
                                                <?php echo number_format($division_stats['assigned_users'] ?? 0); ?>
                                            </h2>
                                            <p class="card-text">Assigned Users</p>
                                            <small class="text-muted">
                                                <?php echo $division_stats['active_users'] ?? 0; ?> active
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card border-info text-center h-100">
                                        <div class="card-body">
                                            <h2 class="display-6 text-info">
                                                <?php echo number_format($division_stats['total_families'] ?? 0); ?>
                                            </h2>
                                            <p class="card-text">Total Families</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card border-warning text-center h-100">
                                        <div class="card-body">
                                            <h2 class="display-6 text-warning">
                                                <?php echo number_format($division_stats['total_citizens'] ?? 0); ?>
                                            </h2>
                                            <p class="card-text">Total Citizens</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <strong>District:</strong> <?php echo htmlspecialchars($division_info['District_Name']); ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Province:</strong> <?php echo htmlspecialchars($division_info['Province_Name']); ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Division Code:</strong> <?php echo htmlspecialchars($division_code); ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Your Role:</strong> Division Officer
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filters -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0">
                        <i class="bi bi-funnel me-2"></i>
                        Search & Filter GN Divisions
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" id="filterForm" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Search GN Divisions</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="search" 
                                       value="<?php echo htmlspecialchars($search_query); ?>"
                                       placeholder="Search by GN name, ID, or username">
                                <button class="btn btn-primary" type="submit">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Status Filter</label>
                            <select class="form-select" name="status">
                                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Divisions</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active Users</option>
                                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive Users</option>
                                <option value="unassigned" <?php echo $filter_status === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <a href="gn_divisions.php" class="btn btn-outline-secondary w-100">
                                <i class="bi bi-arrow-clockwise"></i> Reset
                            </a>
                        </div>
                        
                        <div class="col-12">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <span class="badge bg-light text-dark">
                                        <?php echo number_format($total_divisions); ?> GN divisions found
                                    </span>
                                </div>
                                <div>
                                    <button type="submit" form="exportForm" name="export_data" 
                                            class="btn btn-success btn-sm">
                                        <i class="bi bi-download me-1"></i> Export CSV
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Export form (separate for CSV download) -->
                    <form method="POST" action="" id="exportForm" style="display: none;">
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                    </form>
                </div>
            </div>
            
            <!-- GN Divisions Table -->
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list-check me-2"></i>
                        GN Divisions List
                    </h5>
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#assignUserModal">
                        <i class="bi bi-person-plus me-1"></i> Assign User
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($gn_divisions)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-1 text-muted mb-3"></i>
                            <h4>No GN Divisions Found</h4>
                            <p class="text-muted">
                                <?php if (!empty($search_query)): ?>
                                    No GN divisions found matching "<?php echo htmlspecialchars($search_query); ?>"
                                <?php else: ?>
                                    No GN divisions found in your division.
                                <?php endif; ?>
                            </p>
                            <a href="gn_divisions.php" class="btn btn-primary">
                                <i class="bi bi-arrow-clockwise me-1"></i> Clear Filters
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm" id="gnDivisionsTable">
                                <thead>
                                    <tr>
                                        <th width="180">GN Division</th>
                                        <th width="120">GN ID</th>
                                        <th width="120">Assigned User</th>
                                        <th width="100">Status</th>
                                        <th width="120">Last Login</th>
                                        <th width="100">Families</th>
                                        <th width="100">Citizens</th>
                                        <th width="80">Transfers</th>
                                        <th width="150">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($gn_divisions as $gn): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($gn['GN']); ?></strong><br>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($gn['Division_Name']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <code class="small"><?php echo htmlspecialchars($gn['GN_ID']); ?></code>
                                            </td>
                                            <td>
                                                <?php if ($gn['username']): ?>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($gn['username']); ?></strong>
                                                        <?php if ($gn['email']): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($gn['email']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo getStatusBadge($gn['username'], $gn['user_active']); ?>
                                            </td>
                                            <td class="small">
                                                <?php echo formatLastLogin($gn['last_login']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo number_format($gn['total_families']); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-success"><?php echo number_format($gn['total_citizens']); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($gn['pending_transfers'] > 0): ?>
                                                    <span class="badge bg-warning"><?php echo $gn['pending_transfers']; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <?php if ($gn['user_id']): ?>
                                                        <!-- User actions for assigned GN -->
                                                        <button type="button" class="btn btn-outline-primary" 
                                                                data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                                onclick="loadUserData(<?php echo htmlspecialchars(json_encode($gn)); ?>)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-warning"
                                                                data-bs-toggle="modal" data-bs-target="#resetPasswordModal"
                                                                onclick="setResetUser(<?php echo $gn['user_id']; ?>, '<?php echo htmlspecialchars($gn['username']); ?>')">
                                                            <i class="bi bi-key"></i>
                                                        </button>
                                                        <a href="gn_reports.php?gn_id=<?php echo urlencode($gn['GN_ID']); ?>" 
                                                           class="btn btn-outline-info" title="View Reports">
                                                            <i class="bi bi-graph-up"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <!-- Assign user button for unassigned GN -->
                                                        <button type="button" class="btn btn-outline-success"
                                                                data-bs-toggle="modal" data-bs-target="#assignUserModal"
                                                                onclick="setAssignGN('<?php echo htmlspecialchars($gn['GN_ID']); ?>', '<?php echo htmlspecialchars($gn['GN']); ?>')">
                                                            <i class="bi bi-person-plus"></i> Assign
                                                        </button>
                                                        <a href="gn_reports.php?gn_id=<?php echo urlencode($gn['GN_ID']); ?>" 
                                                           class="btn btn-outline-info" title="View Reports">
                                                            <i class="bi bi-graph-up"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="GN divisions pagination">
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
                                    | Showing <?php echo min($per_page, count($gn_divisions)); ?> of <?php echo number_format($total_divisions); ?> GN divisions
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Statistics and Charts -->
            <div class="row mt-4">
                <!-- User Assignment Stats -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-pie-chart me-2"></i>
                                User Assignment Status
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <canvas id="assignmentChart" width="200" height="200"></canvas>
                                </div>
                                <div class="col-md-6">
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-2">
                                            <span class="badge bg-success me-2">●</span>
                                            <strong>Active Users:</strong> 
                                            <?php echo $division_stats['active_users'] ?? 0; ?>
                                        </li>
                                        <li class="mb-2">
                                            <span class="badge bg-danger me-2">●</span>
                                            <strong>Inactive Users:</strong> 
                                            <?php echo ($division_stats['assigned_users'] ?? 0) - ($division_stats['active_users'] ?? 0); ?>
                                        </li>
                                        <li class="mb-2">
                                            <span class="badge bg-secondary me-2">●</span>
                                            <strong>Unassigned:</strong> 
                                            <?php echo ($division_stats['total_gns'] ?? 0) - ($division_stats['assigned_users'] ?? 0); ?>
                                        </li>
                                        <li class="mb-2">
                                            <span class="badge bg-primary me-2">●</span>
                                            <strong>Total GN Divisions:</strong> 
                                            <?php echo $division_stats['total_gns'] ?? 0; ?>
                                        </li>
                                    </ul>
                                    <div class="mt-3">
                                        <div class="progress" style="height: 10px;">
                                            <?php 
                                            $total_gns = $division_stats['total_gns'] ?? 1;
                                            $assigned_percent = ($division_stats['assigned_users'] ?? 0) / $total_gns * 100;
                                            ?>
                                            <div class="progress-bar bg-success" 
                                                 style="width: <?php echo $assigned_percent; ?>%"
                                                 role="progressbar">
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo round($assigned_percent, 1); ?>% of GN divisions have assigned users
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-warning text-dark">
                            <h6 class="mb-0">
                                <i class="bi bi-lightning me-2"></i>
                                Quick Actions
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <a href="transfer_requests.php" class="btn btn-outline-primary w-100 mb-2">
                                        <i class="bi bi-arrow-left-right me-1"></i> Transfer Requests
                                    </a>
                                    <a href="reports.php" class="btn btn-outline-success w-100 mb-2">
                                        <i class="bi bi-graph-up me-1"></i> Division Reports
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="audit_logs.php" class="btn btn-outline-info w-100 mb-2">
                                        <i class="bi bi-clock-history me-1"></i> Activity Logs
                                    </a>
                                    <a href="users_management.php" class="btn btn-outline-dark w-100 mb-2">
                                        <i class="bi bi-people me-1"></i> Manage Users
                                    </a>
                                </div>
                            </div>
                            <hr>
                            <div class="mt-3">
                                <h6>System Information</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><i class="bi bi-calendar-check text-primary me-1"></i> 
                                        Last updated: <?php echo date('Y-m-d H:i'); ?>
                                    </li>
                                    <li><i class="bi bi-database text-success me-1"></i> 
                                        Total records: <?php echo number_format($division_stats['total_citizens'] ?? 0); ?> citizens
                                    </li>
                                    <li><i class="bi bi-clock-history text-info me-1"></i> 
                                        Session: <?php echo session_id(); ?>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Assign User Modal -->
<div class="modal fade" id="assignUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-person-plus me-2"></i>
                    Assign User to GN Division
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" id="assignUserForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">GN Division</label>
                        <input type="text" class="form-control" id="assign_gn_name" readonly>
                        <input type="hidden" name="gn_id" id="assign_gn_id">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Username</label>
                        <input type="text" class="form-control" name="username" required
                               minlength="3" maxlength="50"
                               pattern="[a-zA-Z0-9_]+" title="Letters, numbers and underscores only">
                        <small class="text-muted">3-50 characters, letters, numbers and underscores only</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Password</label>
                        <input type="password" class="form-control" name="password" required
                               minlength="8"
                               pattern="^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*#?&]{8,}$"
                               title="At least 8 characters with letters and numbers">
                        <small class="text-muted">Minimum 8 characters with letters and numbers</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="confirm_password" required>
                        <div class="invalid-feedback" id="passwordMatchError">Passwords do not match</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email">
                        <small class="text-muted">For notifications and password recovery</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" name="phone"
                               pattern="[0-9]{10}" title="10 digits only">
                        <small class="text-muted">10 digits only (without +94)</small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        The user will be created with GN officer privileges for this specific GN division.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_user" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i> Assign User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>
                    Edit GN User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" id="editUserForm">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">GN Division</label>
                        <input type="text" class="form-control" id="edit_gn_name" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" id="edit_username" readonly>
                    </div>
                    
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" 
                               name="is_active" id="edit_is_active" value="1">
                        <label class="form-check-label" for="edit_is_active">
                            Account Active
                        </label>
                        <small class="d-block text-muted">Deactivate to prevent login</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email" id="edit_email">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" name="phone" id="edit_phone"
                               pattern="[0-9]{10}" title="10 digits only">
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Changes will take effect immediately. User will be notified of account status changes.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_user" class="btn btn-warning">
                        <i class="bi bi-save me-1"></i> Update User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-key me-2"></i>
                    Reset User Password
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" id="resetPasswordForm">
                <input type="hidden" name="user_id" id="reset_user_id">
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-octagon-fill me-2"></i>
                        <strong>Warning:</strong> This will reset the user's password. The user will need to use the new password to login.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">User</label>
                        <input type="text" class="form-control" id="reset_username" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">GN Division</label>
                        <input type="text" class="form-control" id="reset_gn_name" readonly>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        A new random password will be generated and displayed after reset.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-danger">
                        <i class="bi bi-key-fill me-1"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .table-hover tbody tr:hover {
        background-color: rgba(0, 123, 255, 0.05);
    }
    .badge {
        font-weight: normal;
    }
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
    .btn-group-sm .btn {
        padding: 0.25rem 0.5rem;
    }
    .modal-header {
        padding: 0.75rem 1.5rem;
    }
    .modal-body {
        padding: 1.5rem;
    }
    .required::after {
        content: " *";
        color: #dc3545;
    }
    @media print {
        .sidebar-column, .btn-toolbar, .card-header button, 
        .card:first-child, .card:nth-child(2), .row.mt-4 {
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Password confirmation
        const passwordInput = document.querySelector('input[name="password"]');
        const confirmPassword = document.getElementById('confirm_password');
        
        confirmPassword.addEventListener('input', function() {
            if (passwordInput.value !== this.value) {
                this.classList.add('is-invalid');
                document.getElementById('passwordMatchError').style.display = 'block';
            } else {
                this.classList.remove('is-invalid');
                document.getElementById('passwordMatchError').style.display = 'none';
            }
        });
        
        // Assign User Form validation
        const assignForm = document.getElementById('assignUserForm');
        assignForm.addEventListener('submit', function(e) {
            if (passwordInput.value !== confirmPassword.value) {
                e.preventDefault();
                confirmPassword.focus();
                confirmPassword.classList.add('is-invalid');
                document.getElementById('passwordMatchError').style.display = 'block';
            }
        });
        
        // Phone number formatting
        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 10) {
                    this.value = this.value.substring(0, 10);
                }
            });
        });
        
        // Generate random password
        const generatePasswordBtn = document.createElement('button');
        generatePasswordBtn.type = 'button';
        generatePasswordBtn.className = 'btn btn-sm btn-outline-secondary mt-1';
        generatePasswordBtn.innerHTML = '<i class="bi bi-shuffle me-1"></i> Generate Password';
        generatePasswordBtn.onclick = function() {
            const password = generatePassword();
            passwordInput.value = password;
            confirmPassword.value = password;
            confirmPassword.classList.remove('is-invalid');
            document.getElementById('passwordMatchError').style.display = 'none';
        };
        
        passwordInput.parentNode.appendChild(generatePasswordBtn);
        
        function generatePassword() {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            let password = '';
            
            // Ensure at least one of each type
            password += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.charAt(Math.floor(Math.random() * 26));
            password += 'abcdefghijklmnopqrstuvwxyz'.charAt(Math.floor(Math.random() * 26));
            password += '0123456789'.charAt(Math.floor(Math.random() * 10));
            password += '!@#$%^&*'.charAt(Math.floor(Math.random() * 8));
            
            // Fill to 12 characters
            for (let i = password.length; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            // Shuffle
            return password.split('').sort(() => 0.5 - Math.random()).join('');
        }
        
        // Chart.js - User Assignment Chart
        const assignmentCtx = document.getElementById('assignmentChart').getContext('2d');
        
        <?php
        $active_users = $division_stats['active_users'] ?? 0;
        $inactive_users = ($division_stats['assigned_users'] ?? 0) - $active_users;
        $unassigned = ($division_stats['total_gns'] ?? 0) - ($division_stats['assigned_users'] ?? 0);
        ?>
        
        const assignmentChart = new Chart(assignmentCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active Users', 'Inactive Users', 'Unassigned'],
                datasets: [{
                    data: [<?php echo $active_users; ?>, <?php echo $inactive_users; ?>, <?php echo $unassigned; ?>],
                    backgroundColor: [
                        '#198754', // Success green
                        '#dc3545', // Danger red
                        '#6c757d'  // Secondary gray
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Modal functions
        window.setAssignGN = function(gn_id, gn_name) {
            document.getElementById('assign_gn_id').value = gn_id;
            document.getElementById('assign_gn_name').value = gn_name;
            
            // Clear form
            assignForm.reset();
            
            // Set username suggestion
            const usernameInput = assignForm.querySelector('input[name="username"]');
            const suggestedUsername = gn_name.toLowerCase()
                .replace(/[^a-z0-9]/g, '_')
                .replace(/_+/g, '_')
                .replace(/^_|_$/g, '');
            usernameInput.placeholder = suggestedUsername + '_user';
        };
        
        window.loadUserData = function(gnData) {
            document.getElementById('edit_user_id').value = gnData.user_id;
            document.getElementById('edit_gn_name').value = gnData.GN;
            document.getElementById('edit_username').value = gnData.username;
            document.getElementById('edit_is_active').checked = gnData.user_active == 1;
            document.getElementById('edit_email').value = gnData.email || '';
            document.getElementById('edit_phone').value = gnData.phone || '';
        };
        
        window.setResetUser = function(user_id, username) {
            document.getElementById('reset_user_id').value = user_id;
            document.getElementById('reset_username').value = username;
            
            // Find GN name from table row
            const row = document.querySelector(`tr:has(input[value="${user_id}"])`);
            if (row) {
                const gnName = row.querySelector('td:first-child strong').textContent;
                document.getElementById('reset_gn_name').value = gnName;
            }
        };
        
        // Auto-refresh page every 60 seconds
        let refreshTimer = setTimeout(function refreshPage() {
            if (!document.hidden && <?php echo $page; ?> === 1 && 
                <?php echo empty($search_query) && $filter_status === 'all' ? 'true' : 'false'; ?>) {
                
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const newDoc = parser.parseFromString(html, 'text/html');
                        const newTable = newDoc.querySelector('#gnDivisionsTable tbody');
                        const currentTable = document.querySelector('#gnDivisionsTable tbody');
                        
                        if (newTable && currentTable && newTable.innerHTML !== currentTable.innerHTML) {
                            currentTable.innerHTML = newTable.innerHTML;
                            showNotification('GN divisions list updated', 'info');
                        }
                        
                        refreshTimer = setTimeout(refreshPage, 60000);
                    })
                    .catch(error => {
                        console.error('Failed to refresh:', error);
                        refreshTimer = setTimeout(refreshPage, 60000);
                    });
            } else {
                refreshTimer = setTimeout(refreshPage, 60000);
            }
        }, 60000);
        
        // Stop auto-refresh when page is hidden
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearTimeout(refreshTimer);
            } else {
                refreshTimer = setTimeout(refreshPage, 60000);
            }
        });
    });
    
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
                <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-info-circle'} me-2"></i>
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