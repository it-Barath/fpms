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
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $auth = new Auth();
    
    if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'division') {
        header('Location: ../login.php');
        exit();
    }

    // Get database connections
    $db = getMainConnection();
    $ref_db = getRefConnection();
    
    if (!$db || !$ref_db) {
        throw new Exception("Database connection failed");
    }
    
    // ============================================================
    // CRITICAL FIX: Set session-level collation for both connections
    // ============================================================
    $ref_db->query("SET collation_connection = 'utf8mb4_unicode_ci'");
    $ref_db->query("SET collation_database = 'utf8mb4_unicode_ci'");
    $ref_db->query("SET collation_server = 'utf8mb4_unicode_ci'");
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $office_code = $_SESSION['office_code'] ?? '';
    $division_name = $_SESSION['office_name'] ?? '';
    
    // Initialize variables
    $error = '';
    $success = '';
    $gn_divisions = [];
    $division_stats = [];
    $division_info = [];
    $search_query = '';
    $filter_status = 'all';
    
    // Get current user info
    $user_query = "SELECT * FROM users WHERE user_id = ?";
    $user_stmt = $db->prepare($user_query);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $current_user = $user_result->fetch_assoc();
    
    if (!$current_user) {
        throw new Exception("User not found");
    }
    
    // ============================================================
    // SOLUTION: Use BINARY comparison to bypass collation issues
    // ============================================================
    $division_info_query = "SELECT DISTINCT 
                           District_Name, 
                           Division_Name,
                           GN,
                           GN_ID
                           FROM mobile_service.fix_work_station 
                           WHERE BINARY Division_Name = BINARY ?
                           LIMIT 1";
    
    $division_info_stmt = $ref_db->prepare($division_info_query);
    $division_info_stmt->bind_param("s", $division_name);
    $division_info_stmt->execute();
    $division_info_result = $division_info_stmt->get_result();
    
    if ($division_info_result->num_rows > 0) {
        $division_row = $division_info_result->fetch_assoc();
        $division_info = [
            'Division_Name' => $division_row['Division_Name'] ?? $division_name,
            'District_Name' => $division_row['District_Name'] ?? 'Unknown District',
            'Province_Name' => 'Province',
            'Office_Code' => $office_code
        ];
    } else {
        $division_info = [
            'Division_Name' => $current_user['office_name'],
            'District_Name' => 'Unknown District',
            'Province_Name' => 'Unknown Province',
            'Office_Code' => $office_code
        ];
    }
    
    // Get filter parameters
    $search_query = $_GET['search'] ?? '';
    $filter_status = $_GET['status'] ?? 'all';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = 25;
    $offset = ($page - 1) * $per_page;
    
    // ============================================================
    // Build queries using BINARY comparison
    // ============================================================
    if (!empty($search_query)) {
        $gn_query = "SELECT 
                    fws.GN as gn_name,
                    fws.GN_ID as gn_id,
                    fws.Division_Name as division_name,
                    fws.District_Name as district_name
                    FROM mobile_service.fix_work_station fws
                    WHERE BINARY fws.Division_Name = BINARY ?
                    AND (BINARY fws.GN LIKE BINARY ? 
                         OR BINARY fws.GN_ID LIKE BINARY ?)
                    ORDER BY fws.GN ASC
                    LIMIT ? OFFSET ?";
        
        $count_query = "SELECT COUNT(*) as total 
                       FROM mobile_service.fix_work_station fws
                       WHERE BINARY fws.Division_Name = BINARY ?
                       AND (BINARY fws.GN LIKE BINARY ? 
                            OR BINARY fws.GN_ID LIKE BINARY ?)";
        
        $search_param = "%$search_query%";
        
        $count_stmt = $ref_db->prepare($count_query);
        $count_stmt->bind_param("sss", $division_name, $search_param, $search_param);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $total_row = $count_result->fetch_assoc();
        $total_divisions = $total_row['total'] ?? 0;
        
        $gn_stmt = $ref_db->prepare($gn_query);
        $gn_stmt->bind_param("sssii", $division_name, $search_param, $search_param, $per_page, $offset);
        
    } else {
        $gn_query = "SELECT 
                    fws.GN as gn_name,
                    fws.GN_ID as gn_id,
                    fws.Division_Name as division_name,
                    fws.District_Name as district_name
                    FROM mobile_service.fix_work_station fws
                    WHERE BINARY fws.Division_Name = BINARY ?
                    ORDER BY fws.GN ASC
                    LIMIT ? OFFSET ?";
        
        $count_query = "SELECT COUNT(*) as total 
                       FROM mobile_service.fix_work_station fws
                       WHERE BINARY fws.Division_Name = BINARY ?";
        
        $count_stmt = $ref_db->prepare($count_query);
        $count_stmt->bind_param("s", $division_name);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $total_row = $count_result->fetch_assoc();
        $total_divisions = $total_row['total'] ?? 0;
        
        $gn_stmt = $ref_db->prepare($gn_query);
        $gn_stmt->bind_param("sii", $division_name, $per_page, $offset);
    }
    
    $total_pages = ceil($total_divisions / $per_page);
    
    $gn_stmt->execute();
    $gn_result = $gn_stmt->get_result();
    $gn_data = $gn_result->fetch_all(MYSQLI_ASSOC);
    
    // Process GN divisions
    foreach ($gn_data as $gn) {
        $user_check_query = "SELECT 
                           u.user_id,
                           u.username,
                           u.email,
                           u.phone,
                           u.is_active as user_active,
                           u.last_login,
                           u.created_at
                           FROM users u
                           WHERE u.user_type = 'gn' 
                           AND u.office_code = ?";
        
        $user_check_stmt = $db->prepare($user_check_query);
        $user_check_stmt->bind_param("s", $gn['gn_id']);
        $user_check_stmt->execute();
        $user_check_result = $user_check_stmt->get_result();
        
        $user_data = null;
        if ($user_check_result->num_rows > 0) {
            $user_data = $user_check_result->fetch_assoc();
        }
        
        $stats_query = "SELECT 
                       (SELECT COUNT(*) FROM families WHERE gn_id = ?) as total_families,
                       (SELECT COUNT(*) FROM citizens c 
                        JOIN families f ON c.family_id = f.family_id 
                        WHERE f.gn_id = ?) as total_citizens,
                       (SELECT COUNT(*) FROM families 
                        WHERE gn_id = ? AND has_pending_transfer = 1) as pending_transfers";
        
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->bind_param("sss", $gn['gn_id'], $gn['gn_id'], $gn['gn_id']);
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        $gn_stats = $stats_result->fetch_assoc();
        
        $gn_divisions[] = array_merge($gn, [
            'user_id' => $user_data['user_id'] ?? null,
            'username' => $user_data['username'] ?? null,
            'email' => $user_data['email'] ?? null,
            'phone' => $user_data['phone'] ?? null,
            'user_active' => $user_data['user_active'] ?? 0,
            'last_login' => $user_data['last_login'] ?? null,
            'created_at' => $user_data['created_at'] ?? null,
            'total_families' => $gn_stats['total_families'] ?? 0,
            'total_citizens' => $gn_stats['total_citizens'] ?? 0,
            'pending_transfers' => $gn_stats['pending_transfers'] ?? 0
        ]);
    }
    
    // Get division statistics using BINARY
    $stats_query = "SELECT 
                    (SELECT COUNT(*) FROM mobile_service.fix_work_station fws 
                     WHERE BINARY fws.Division_Name = BINARY ?) as total_gns,
                    SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) as active_users,
                    COUNT(DISTINCT u.user_id) as assigned_users
                    FROM users u
                    WHERE u.user_type = 'gn'
                    AND u.office_code IN (
                        SELECT fws.GN_ID 
                        FROM mobile_service.fix_work_station fws
                        WHERE BINARY fws.Division_Name = BINARY ?
                    )";
    
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bind_param("ss", $division_name, $division_name);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $division_stats = $stats_result->fetch_assoc() ?? ['total_gns' => 0, 'active_users' => 0, 'assigned_users' => 0];
    
    // Get family statistics using BINARY
    $family_stats_query = "SELECT 
                          COUNT(*) as total_families,
                          (SELECT COUNT(*) FROM citizens c 
                           JOIN families f ON c.family_id = f.family_id 
                           WHERE f.gn_id IN (
                               SELECT fws.GN_ID 
                               FROM mobile_service.fix_work_station fws
                               WHERE BINARY fws.Division_Name = BINARY ?
                           )) as total_citizens
                          FROM families f
                          WHERE f.gn_id IN (
                              SELECT fws.GN_ID 
                              FROM mobile_service.fix_work_station fws
                              WHERE BINARY fws.Division_Name = BINARY ?
                          )";
    
    $family_stmt = $db->prepare($family_stats_query);
    $family_stmt->bind_param("ss", $division_name, $division_name);
    $family_stmt->execute();
    $family_result = $family_stmt->get_result();
    $family_stats = $family_result->fetch_assoc() ?? ['total_families' => 0, 'total_citizens' => 0];
    
    $division_stats = array_merge($division_stats, $family_stats);
    
    // Process POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (isset($_POST['assign_user'])) {
                $gn_id = $_POST['gn_id'] ?? '';
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                
                if (empty($gn_id) || empty($username) || empty($password)) {
                    throw new Exception("GN ID, username, and password are required");
                }
                
                // Check if GN exists using BINARY
                $check_gn = "SELECT GN, Division_Name FROM mobile_service.fix_work_station 
                            WHERE GN_ID = ? 
                            AND BINARY Division_Name = BINARY ?";
                $check_stmt = $ref_db->prepare($check_gn);
                $check_stmt->bind_param("ss", $gn_id, $division_name);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception("GN division not found or not under your jurisdiction");
                }
                
                $check_user = "SELECT user_id FROM users WHERE username = ?";
                $user_stmt = $db->prepare($check_user);
                $user_stmt->bind_param("s", $username);
                $user_stmt->execute();
                
                if ($user_stmt->get_result()->num_rows > 0) {
                    throw new Exception("Username already exists");
                }
                
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $gn_row = $check_result->fetch_assoc();
                $gn_name = $gn_row['GN'];
                
                $insert_query = "INSERT INTO users 
                                (username, password_hash, user_type, office_code, office_name, 
                                 email, phone, is_active, parent_division_code, created_at) 
                                VALUES (?, ?, 'gn', ?, ?, ?, ?, 1, ?, NOW())";
                
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->bind_param("sssssss", $username, $password_hash, $gn_id, 
                                        $gn_name, $email, $phone, $office_code);
                
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
                header("Location: gn_divisions.php");
                exit();
                
            } elseif (isset($_POST['update_user'])) {
                $user_id_update = $_POST['user_id'] ?? 0;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                
                $check_user = "SELECT u.* FROM users u
                              WHERE u.user_id = ? 
                              AND u.user_type = 'gn'
                              AND u.parent_division_code = ?";
                $check_stmt = $db->prepare($check_user);
                $check_stmt->bind_param("is", $user_id_update, $office_code);
                $check_stmt->execute();
                $user_result = $check_stmt->get_result();
                
                if ($user_result->num_rows === 0) {
                    throw new Exception("GN user not found or not under your jurisdiction");
                }
                
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
                
                $success = "User updated successfully!";
                header("Location: gn_divisions.php");
                exit();
                
            } elseif (isset($_POST['reset_password'])) {
                $user_id_reset = $_POST['user_id'] ?? 0;
                $new_password = bin2hex(random_bytes(8));
                
                $check_user = "SELECT u.* FROM users u
                              WHERE u.user_id = ? 
                              AND u.user_type = 'gn'
                              AND u.parent_division_code = ?";
                $check_stmt = $db->prepare($check_user);
                $check_stmt->bind_param("is", $user_id_reset, $office_code);
                $check_stmt->execute();
                $user_result = $check_stmt->get_result();
                
                if ($user_result->num_rows === 0) {
                    throw new Exception("GN user not found or not under your jurisdiction");
                }
                
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                $update_query = "UPDATE users SET 
                                password_hash = ?, 
                                updated_at = NOW() 
                                WHERE user_id = ?";
                
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bind_param("si", $password_hash, $user_id_reset);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to reset password: " . $update_stmt->error);
                }
                
                $success = "Password reset successfully! New password: <strong>$new_password</strong>";
                
            } elseif (isset($_POST['export_data'])) {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="gn_divisions_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                
                fputcsv($output, [
                    'GN Division',
                    'GN ID',
                    'Assigned User',
                    'User Status',
                    'Last Login',
                    'Total Families',
                    'Total Citizens',
                    'Pending Transfers'
                ]);
                
                $export_query = "SELECT 
                                fws.GN as gn_name,
                                fws.GN_ID as gn_id,
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
                                WHERE BINARY fws.Division_Name = BINARY ?
                                ORDER BY fws.GN ASC";
                
                $export_stmt = $ref_db->prepare($export_query);
                $export_stmt->bind_param("s", $division_name);
                $export_stmt->execute();
                $export_result = $export_stmt->get_result();
                
                while ($row = $export_result->fetch_assoc()) {
                    fputcsv($output, [
                        $row['gn_name'],
                        $row['gn_id'],
                        $row['username'] ?: 'Not Assigned',
                        $row['username'] ? ($row['user_active'] ? 'Active' : 'Inactive') : 'Unassigned',
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

// Helper functions
function getStatusBadge($username, $is_active) {
    if (!$username) {
        return '<span class="badge bg-secondary">Unassigned</span>';
    }
    return $is_active 
        ? '<span class="badge bg-success">Active</span>' 
        : '<span class="badge bg-danger">Inactive</span>';
}

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

// Continue with your existing HTML/UI code from line 577 onwards...
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
        <div class="main-content">
            <main class="ms-sm-auto px-md-4">
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
                                    Division Overview: <?php echo htmlspecialchars($division_info['Division_Name'] ?? $division_name); ?>
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
                                    <div class="col-md-4">
                                        <strong>District:</strong> <?php echo htmlspecialchars($division_info['District_Name'] ?? 'Unknown District'); ?>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Province:</strong> <?php echo htmlspecialchars($division_info['Province_Name'] ?? 'Unknown Province'); ?>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Division Code:</strong> <?php echo htmlspecialchars($division_info['Office_Code'] ?? $office_code); ?>
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
                                           placeholder="Search by GN name or GN ID">
                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-search"></i> Search
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Status Filter</label>
                                <select class="form-select" name="status">
                                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Divisions</option>
                                    <option value="assigned" <?php echo $filter_status === 'assigned' ? 'selected' : ''; ?>>Assigned Only</option>
                                    <option value="unassigned" <?php echo $filter_status === 'unassigned' ? 'selected' : ''; ?>>Unassigned Only</option>
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
                                        No GN divisions found in your division. Please contact your district administrator.
                                    <?php endif; ?>
                                </p>
                                <a href="gn_divisions.php" class="btn btn-primary">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Clear Filters
                                </a>
                            </div>
                        <?php else: ?>
                            <?php 
                            // Filter by status if needed
                            $filtered_divisions = $gn_divisions;
                            if ($filter_status === 'assigned') {
                                $filtered_divisions = array_filter($gn_divisions, function($gn) {
                                    return $gn['username'] !== null;
                                });
                            } elseif ($filter_status === 'unassigned') {
                                $filtered_divisions = array_filter($gn_divisions, function($gn) {
                                    return $gn['username'] === null;
                                });
                            }
                            ?>
                            
                            <?php if (empty($filtered_divisions)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-inbox display-1 text-muted mb-3"></i>
                                    <h4>No GN Divisions Found</h4>
                                    <p class="text-muted">
                                        No GN divisions match the selected filter.
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
                                            <?php foreach ($filtered_divisions as $gn): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($gn['gn_name']); ?></strong><br>
                                                        <code class="small"><?php echo htmlspecialchars($gn['gn_id']); ?></code>
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
                                                                <a href="../gn/reports.php?gn_id=<?php echo urlencode($gn['gn_id']); ?>" 
                                                                   class="btn btn-outline-info" title="View Reports">
                                                                    <i class="bi bi-graph-up"></i>
                                                                </a>
                                                            <?php else: ?>
                                                                <!-- Assign user button for unassigned GN -->
                                                                <button type="button" class="btn btn-outline-success"
                                                                        data-bs-toggle="modal" data-bs-target="#assignUserModal"
                                                                        onclick="setAssignGN('<?php echo htmlspecialchars($gn['gn_id']); ?>', '<?php echo htmlspecialchars($gn['gn_name']); ?>')">
                                                                    <i class="bi bi-person-plus"></i> Assign
                                                                </button>
                                                                <a href="../gn/reports.php?gn_id=<?php echo urlencode($gn['gn_id']); ?>" 
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
                                            | Showing <?php echo min($per_page, count($filtered_divisions)); ?> of <?php echo number_format(count($filtered_divisions)); ?> GN divisions
                                        </div>
                                    </nav>
                                <?php endif; ?>
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
                                <?php 
                                $assigned = 0;
                                $unassigned = 0;
                                foreach ($gn_divisions as $gn) {
                                    if ($gn['username']) {
                                        $assigned++;
                                    } else {
                                        $unassigned++;
                                    }
                                }
                                $total_gns = count($gn_divisions);
                                $assigned_percent = $total_gns > 0 ? ($assigned / $total_gns) * 100 : 0;
                                ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <canvas id="assignmentChart" width="200" height="200"></canvas>
                                    </div>
                                    <div class="col-md-6">
                                        <ul class="list-unstyled mb-0">
                                            <li class="mb-2">
                                                <span class="badge bg-success me-2">●</span>
                                                <strong>Assigned GN Divisions:</strong> <?php echo $assigned; ?>
                                            </li>
                                            <li class="mb-2">
                                                <span class="badge bg-secondary me-2">●</span>
                                                <strong>Unassigned GN Divisions:</strong> <?php echo $unassigned; ?>
                                            </li>
                                            <li class="mb-2">
                                                <span class="badge bg-primary me-2">●</span>
                                                <strong>Total GN Divisions:</strong> <?php echo $total_gns; ?>
                                            </li>
                                        </ul>
                                        <div class="mt-3">
                                            <div class="progress" style="height: 10px;">
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
                                        <a href="reports_division.php" class="btn btn-outline-success w-100 mb-2">
                                            <i class="bi bi-graph-up me-1"></i> Division Reports
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="../admin/audit_logs.php" class="btn btn-outline-info w-100 mb-2">
                                            <i class="bi bi-clock-history me-1"></i> Activity Logs
                                        </a>
                                        <a href="../users/manage_passwords.php" class="btn btn-outline-dark w-100 mb-2">
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
                                            Session: <?php echo substr(session_id(), 0, 8) . '...'; ?>
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
</div>

<!-- Include the modals section (same as before) -->
<!-- ... (modals section remains the same as in the previous code) ... -->

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
        
        if (confirmPassword) {
            confirmPassword.addEventListener('input', function() {
                if (passwordInput.value !== this.value) {
                    this.classList.add('is-invalid');
                    document.getElementById('passwordMatchError').style.display = 'block';
                } else {
                    this.classList.remove('is-invalid');
                    document.getElementById('passwordMatchError').style.display = 'none';
                }
            });
        }
        
        // Assign User Form validation
        const assignForm = document.getElementById('assignUserForm');
        if (assignForm) {
            assignForm.addEventListener('submit', function(e) {
                if (passwordInput && confirmPassword && passwordInput.value !== confirmPassword.value) {
                    e.preventDefault();
                    confirmPassword.focus();
                    confirmPassword.classList.add('is-invalid');
                    document.getElementById('passwordMatchError').style.display = 'block';
                }
            });
        }
        
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
        if (passwordInput) {
            const generatePasswordBtn = document.createElement('button');
            generatePasswordBtn.type = 'button';
            generatePasswordBtn.className = 'btn btn-sm btn-outline-secondary mt-1';
            generatePasswordBtn.innerHTML = '<i class="bi bi-shuffle me-1"></i> Generate Password';
            generatePasswordBtn.onclick = function() {
                const password = generatePassword();
                passwordInput.value = password;
                if (confirmPassword) {
                    confirmPassword.value = password;
                    confirmPassword.classList.remove('is-invalid');
                    document.getElementById('passwordMatchError').style.display = 'none';
                }
            };
            
            passwordInput.parentNode.appendChild(generatePasswordBtn);
        }
        
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
        const assignmentCtx = document.getElementById('assignmentChart');
        if (assignmentCtx) {
            <?php
            $assigned = 0;
            $unassigned = 0;
            foreach ($gn_divisions as $gn) {
                if ($gn['username']) {
                    $assigned++;
                } else {
                    $unassigned++;
                }
            }
            ?>
            
            const assignmentChart = new Chart(assignmentCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Assigned', 'Unassigned'],
                    datasets: [{
                        data: [<?php echo $assigned; ?>, <?php echo $unassigned; ?>],
                        backgroundColor: [
                            '#198754', // Success green
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
        }
        
        // Modal functions
        window.setAssignGN = function(gn_id, gn_name) {
            document.getElementById('assign_gn_id').value = gn_id;
            document.getElementById('assign_gn_name').value = gn_name;
            
            // Clear form
            if (assignForm) assignForm.reset();
            
            // Set username suggestion
            const usernameInput = assignForm.querySelector('input[name="username"]');
            if (usernameInput) {
                const suggestedUsername = gn_name.toLowerCase()
                    .replace(/[^a-z0-9]/g, '_')
                    .replace(/_+/g, '_')
                    .replace(/^_|_$/g, '');
                usernameInput.placeholder = suggestedUsername + '_user';
            }
        };
        
        window.loadUserData = function(gnData) {
            document.getElementById('edit_user_id').value = gnData.user_id;
            document.getElementById('edit_gn_name').value = gnData.gn_name;
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