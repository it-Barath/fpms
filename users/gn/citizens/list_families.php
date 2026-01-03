<?php
// users/gn/citizens/list_families.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Manage Families";
$pageIcon = "bi bi-people";
$pageDescription = "View and manage all families in your GN Division";
$bodyClass = "bg-light";

try {
    require_once '../../../config.php';
    
    // Start session FIRST
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Then require Auth class
    require_once '../../../classes/Auth.php';
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in and has GN level access
    if (!$auth->isLoggedIn()) {
        header('Location: ../../../login.php');
        exit();
    }
    
    // Check user type
    if ($_SESSION['user_type'] !== 'gn') {
        header('Location: ../../../index.php');
        exit();
    }

    // Get database connection
    $dbConnection = getMainConnection();
    if (!$dbConnection) {
        throw new Exception("Database connection failed");
    }
    
    $user_id = $_SESSION['user_id'];
    $office_name = $_SESSION['office_name'];
    
    // FIX: Get GN ID from session and remove "gn_" prefix
    $gn_id = $_SESSION['office_code'];
    
    // Remove "gn_" prefix if it exists
    if (strpos($gn_id, 'gn_') === 0) {
        $gn_id = substr($gn_id, 3); // Remove first 3 characters ("gn_")
    }

    // Initialize variables with prefixes
    $errorMessage = '';
    $successMessage = '';
    $fam_collection = [];
    $fam_total_count = 0;
    $fam_total_members = 0;
    $search_query = '';
    $filter_status = 'all';
    $page_number = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $page_limit = 20;
    $page_offset = ($page_number - 1) * $page_limit;

    // Get filter parameters
    if (isset($_GET['search'])) {
        $search_query = trim($_GET['search']);
    }
    
    if (isset($_GET['status'])) {
        $filter_status = $_GET['status'];
    }

    // Handle family deletion
    if (isset($_GET['delete']) && isset($_GET['id'])) {
        $fam_target_id = $_GET['id'];
        
        // Start transaction
        $dbConnection->begin_transaction();
        
        try {
            // First, check if family exists and belongs to current GN
            $check_query = "SELECT family_id FROM families WHERE family_id = ? AND gn_id = ?";
            $check_stmt = $dbConnection->prepare($check_query);
            $check_stmt->bind_param("ss", $fam_target_id, $gn_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception("Family not found or you don't have permission to delete it.");
            }
            
            // Log the family details before deletion
            $log_query = "SELECT family_id, address, total_members FROM families WHERE family_id = ?";
            $log_stmt = $dbConnection->prepare($log_query);
            $log_stmt->bind_param("s", $fam_target_id);
            $log_stmt->execute();
            $fam_log_data = $log_stmt->get_result()->fetch_assoc();
            
            // Delete all related records
            // 1. Delete employment records
            $delete_employment = "DELETE e FROM employment e 
                                  INNER JOIN citizens c ON e.citizen_id = c.citizen_id 
                                  WHERE c.family_id = ?";
            $stmt1 = $dbConnection->prepare($delete_employment);
            $stmt1->bind_param("s", $fam_target_id);
            $stmt1->execute();
            
            // 2. Delete education records
            $delete_education = "DELETE ed FROM education ed 
                                 INNER JOIN citizens c ON ed.citizen_id = c.citizen_id 
                                 WHERE c.family_id = ?";
            $stmt2 = $dbConnection->prepare($delete_education);
            $stmt2->bind_param("s", $fam_target_id);
            $stmt2->execute();
            
            // 3. Delete health conditions
            $delete_health = "DELETE h FROM health_conditions h 
                              INNER JOIN citizens c ON h.citizen_id = c.citizen_id 
                              WHERE c.family_id = ?";
            $stmt3 = $dbConnection->prepare($delete_health);
            $stmt3->bind_param("s", $fam_target_id);
            $stmt3->execute();
            
            // 4. Delete land details
            $delete_land = "DELETE FROM land_details WHERE family_id = ?";
            $stmt4 = $dbConnection->prepare($delete_land);
            $stmt4->bind_param("s", $fam_target_id);
            $stmt4->execute();
            
            // 5. Delete citizens
            $delete_citizens = "DELETE FROM citizens WHERE family_id = ?";
            $stmt5 = $dbConnection->prepare($delete_citizens);
            $stmt5->bind_param("s", $fam_target_id);
            $stmt5->execute();
            
            // 6. Delete the family
            $delete_family = "DELETE FROM families WHERE family_id = ?";
            $stmt6 = $dbConnection->prepare($delete_family);
            $stmt6->bind_param("s", $fam_target_id);
            $stmt6->execute();
            
            // Log the deletion
            $action = 'delete';
            $table = 'families';
            $record_id = $fam_target_id;
            $old_values = json_encode($fam_log_data);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $log_stmt = $dbConnection->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, old_values, ip_address, user_agent) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?)");
            $log_stmt->bind_param("issssss", $user_id, $action, $table, $record_id, $old_values, $ip, $user_agent);
            $log_stmt->execute();
            
            // Commit transaction
            $dbConnection->commit();
            
            $successMessage = "Family with ID: <strong>$fam_target_id</strong> has been successfully deleted.";
            
        } catch (Exception $e) {
            // Rollback on error
            $dbConnection->rollback();
            $errorMessage = "Error deleting family: " . $e->getMessage();
        }
    }

    // Build the main query to get families
    $fam_main_query = "SELECT f.family_id, f.address, f.family_head_nic, f.total_members, 
                     f.is_transferred, f.created_at, f.updated_at,
                     (SELECT full_name FROM citizens 
                      WHERE identification_number = f.family_head_nic 
                      AND identification_type = 'nic' 
                      LIMIT 1) as head_name,
                     (SELECT mobile_phone FROM citizens 
                      WHERE identification_number = f.family_head_nic 
                      AND identification_type = 'nic' 
                      LIMIT 1) as mobile_phone
              FROM families f
              WHERE f.gn_id = ?";
    
    $fam_count_query = "SELECT COUNT(*) as total FROM families WHERE gn_id = ?";
    $query_params = array($gn_id);
    $param_types = "s";
    
    // Add search filter
    if (!empty($search_query)) {
        $search_term = "%$search_query%";
        $fam_main_query .= " AND (f.family_id LIKE ? OR f.address LIKE ? OR f.family_head_nic LIKE ?)";
        $fam_count_query .= " AND (family_id LIKE ? OR address LIKE ? OR family_head_nic LIKE ?)";
        $query_params[] = $search_term;
        $query_params[] = $search_term;
        $query_params[] = $search_term;
        $param_types .= "sss";
    }
    
    // Add status filter
    if ($filter_status !== 'all') {
        if ($filter_status === 'transferred') {
            $fam_main_query .= " AND f.is_transferred = 1";
            $fam_count_query .= " AND is_transferred = 1";
        } elseif ($filter_status === 'active') {
            $fam_main_query .= " AND f.is_transferred = 0";
            $fam_count_query .= " AND is_transferred = 0";
        }
    }
    
    // Add sorting and pagination
    $fam_main_query .= " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";
    $query_params[] = $page_limit;
    $query_params[] = $page_offset;
    $param_types .= "ii";
    
    // Get total count for pagination
    $stmt_count = $dbConnection->prepare($fam_count_query);
    if (!$stmt_count) {
        throw new Exception("Count query preparation failed: " . $dbConnection->error);
    }
    
    // Bind parameters for count query
    $count_params = [$gn_id];
    $count_types = "s";
    
    if (!empty($search_query)) {
        $search_term = "%$search_query%";
        $count_params[] = $search_term;
        $count_params[] = $search_term;
        $count_params[] = $search_term;
        $count_types .= "sss";
    }
    
    $stmt_count->bind_param($count_types, ...$count_params);
    
    if (!$stmt_count->execute()) {
        throw new Exception("Count query execution failed: " . $stmt_count->error);
    }
    
    $count_result = $stmt_count->get_result();
    if (!$count_result) {
        throw new Exception("Failed to get count result");
    }
    
    $count_row = $count_result->fetch_assoc();
    $fam_total_rows = $count_row ? $count_row['total'] : 0;
    $fam_total_pages = $fam_total_rows > 0 ? ceil($fam_total_rows / $page_limit) : 0;
    
    // Get family data
    $stmt = $dbConnection->prepare($fam_main_query);
    if (!$stmt) {
        throw new Exception("Main query preparation failed: " . $dbConnection->error);
    }
    
    // Bind parameters for main query
    if (!$stmt->bind_param($param_types, ...$query_params)) {
        throw new Exception("Failed to bind parameters: " . $stmt->error);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    // Fetch data
    if ($result && $result->num_rows > 0) {
        while ($fam_row = $result->fetch_assoc()) {
            // Get additional details
            $fam_row['land_count'] = 0;
            $fam_row['employment_info'] = '';
            
            // Get land count
            $fam_land_query = "SELECT COUNT(*) as land_count FROM land_details WHERE family_id = ?";
            $fam_land_stmt = $dbConnection->prepare($fam_land_query);
            if ($fam_land_stmt) {
                $fam_land_stmt->bind_param("s", $fam_row['family_id']);
                $fam_land_stmt->execute();
                $fam_land_result = $fam_land_stmt->get_result();
                $fam_land_row = $fam_land_result->fetch_assoc();
                $fam_row['land_count'] = $fam_land_row ? $fam_land_row['land_count'] : 0;
                $fam_land_stmt->close();
            }
            
            // Get employment info
            $fam_emp_query = "SELECT GROUP_CONCAT(CONCAT(em.designation, ' (', em.employment_type, ')') SEPARATOR '; ') as employment_info
                         FROM employment em 
                         INNER JOIN citizens ci ON em.citizen_id = ci.citizen_id 
                         WHERE ci.family_id = ? AND em.is_current_job = 1";
            $fam_emp_stmt = $dbConnection->prepare($fam_emp_query);
            if ($fam_emp_stmt) {
                $fam_emp_stmt->bind_param("s", $fam_row['family_id']);
                $fam_emp_stmt->execute();
                $fam_emp_result = $fam_emp_stmt->get_result();
                $fam_emp_row = $fam_emp_result->fetch_assoc();
                $fam_row['employment_info'] = $fam_emp_row ? $fam_emp_row['employment_info'] : '';
                $fam_emp_stmt->close();
            }
            
            $fam_collection[] = $fam_row;
            $fam_total_members += $fam_row['total_members'];
        }
    }
    
    // Close statements
    if ($stmt) $stmt->close();
    if ($stmt_count) $stmt_count->close();
    
    // Get GN details for display
    $gn_details = ['GN' => 'Unknown', 'Division_Name' => 'Unknown'];
    try {
        $ref_db = getRefConnection();
        $gn_query = "SELECT GN, Division_Name FROM mobile_service.fix_work_station WHERE GN_ID = ?";
        $gn_stmt = $ref_db->prepare($gn_query);
        if ($gn_stmt) {
            $gn_stmt->bind_param("s", $gn_id);
            $gn_stmt->execute();
            $gn_result = $gn_stmt->get_result();
            $gn_details = $gn_result->fetch_assoc() ?: $gn_details;
            $gn_stmt->close();
        }
    } catch (Exception $e) {
        // Use default values
    }

} catch (Exception $e) {
    $errorMessage = "System Error: " . $e->getMessage();
    error_log("List Families Error: " . $e->getMessage());
    
    // Make sure $fam_collection is an array even on error
    $fam_collection = [];
    $fam_total_rows = 0;
    $fam_total_pages = 0;
    $gn_id = $_SESSION['office_code'] ?? '';
}

// Calculate statistics
$fam_active_count = 0;
$fam_transferred_count = 0;
if (is_array($fam_collection)) {
    foreach ($fam_collection as $fam_item) {
        if ($fam_item['is_transferred']) {
            $fam_transferred_count++;
        } else {
            $fam_active_count++;
        }
    }
}

include '../../../includes/header.php';
?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../../../includes/sidebar.php'; ?>
        </div>

                <!-- Page Header -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            
            <h1 class="h2">
                <i class="bi bi-people me-2"></i>
                <?php echo htmlspecialchars($office_name); ?>
            </h1>


            
            <div class="btn-toolbar mb-2 mb-md-0">
                <a href="add_family.php" class="btn btn-success">
                    <i class="bi bi-plus-circle"></i> Add New Family
                </a>
            </div>
        </div>
        
        <!-- Flash Messages -->
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($errorMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo $successMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <!-- Debug Info (Optional - remove in production) -->
        <?php if (isset($_GET['debug'])): ?>
            <div class="alert alert-info">
                <strong>Debug Info:</strong><br>
                GN ID: <?php echo htmlspecialchars($gn_id); ?><br>
                Total Families Found: <?php echo $fam_total_rows; ?><br>
                GN Office: <?php echo htmlspecialchars($gn_details['GN']); ?> (<?php echo htmlspecialchars($gn_details['Division_Name']); ?>)
            </div>
        <?php endif; ?>
        
        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white summary-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-1">Total Families</h6>
                                <h2 class="card-text mb-0"><?php echo number_format($fam_total_rows); ?></h2>
                            </div>
                            <i class="bi bi-house fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white summary-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-1">Total Members</h6>
                                <h2 class="card-text mb-0"><?php echo number_format($fam_total_members); ?></h2>
                            </div>
                            <i class="bi bi-people fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white summary-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-1">Active Families</h6>
                                <h2 class="card-text mb-0"><?php echo $fam_active_count; ?></h2>
                            </div>
                            <i class="bi bi-check-circle fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white summary-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-1">Transferred</h6>
                                <h2 class="card-text mb-0"><?php echo $fam_transferred_count; ?></h2>
                            </div>
                            <i class="bi bi-arrow-left-right fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Search and Filter Card -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="bi bi-search"></i> Search & Filter</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-6">
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Search by Family ID, Address or NIC..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-search"></i> Search
                            </button>
                            <?php if (!empty($search_query)): ?>
                                <a href="list_families.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Clear
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="status" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Families</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active Only</option>
                            <option value="transferred" <?php echo $filter_status === 'transferred' ? 'selected' : ''; ?>>Transferred</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="d-grid">
                            <button type="button" class="btn btn-outline-success" onclick="exportToExcel()">
                                <i class="bi bi-download"></i> Export to Excel
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Families Table -->
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-list"></i> Family List</h5>
                <?php if ($fam_total_pages > 0): ?>
                    <span class="badge bg-primary fs-6 p-2">Page <?php echo $page_number; ?> of <?php echo $fam_total_pages; ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($fam_collection) || !is_array($fam_collection)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-people display-1 text-muted"></i>
                        <h4 class="text-muted mt-3">No families found in your GN Division</h4>
                        <p class="text-muted">GN ID: <?php echo htmlspecialchars($gn_id); ?></p>
                        <a href="add_family.php" class="btn btn-primary btn-lg">
                            <i class="bi bi-plus-circle"></i> Add Your First Family
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="15%">Family ID</th>
                                    <th width="20%">Head of Family</th>
                                    <th width="12%">NIC</th>
                                    <th width="20%">Address</th>
                                    <th width="8%">Members</th>
                                    <th width="10%">Contact</th>
                                    <th width="8%">Status</th>
                                    <th width="12%">Registered</th>
                                    <th width="15%" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fam_collection as $fam_item): ?>
                                    <?php if (!is_array($fam_item)) continue; ?>
                                    <tr>
                                        <td>
                                            <strong class="font-monospace"><?php echo htmlspecialchars($fam_item['family_id']); ?></strong>
                                            <?php if (isset($fam_item['land_count']) && $fam_item['land_count'] > 0): ?>
                                                <span class="badge bg-info ms-1" title="Has land records">
                                                    <i class="bi bi-geo"></i> <?php echo $fam_item['land_count']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span><?php echo htmlspecialchars($fam_item['head_name'] ?? 'N/A'); ?></span>
                                                <?php if (!empty($fam_item['employment_info'])): ?>
                                                    <small class="text-muted" title="<?php echo htmlspecialchars($fam_item['employment_info']); ?>">
                                                        <i class="bi bi-briefcase"></i> 
                                                        <?php echo htmlspecialchars(substr($fam_item['employment_info'], 0, 30)); ?>
                                                        <?php if (strlen($fam_item['employment_info']) > 30): ?>...<?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($fam_item['family_head_nic'] ?? 'N/A'); ?></td>
                                        <td>
                                            <small title="<?php echo htmlspecialchars($fam_item['address']); ?>">
                                                <?php 
                                                $fam_address = htmlspecialchars($fam_item['address']);
                                                echo strlen($fam_address) > 30 ? substr($fam_address, 0, 30) . '...' : $fam_address;
                                                ?>
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary fs-6 px-2 py-1"><?php echo $fam_item['total_members']; ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($fam_item['mobile_phone'])): ?>
                                                <span class="text-nowrap">
                                                    <i class="bi bi-phone"></i> <?php echo htmlspecialchars($fam_item['mobile_phone']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($fam_item['is_transferred']): ?>
                                                <span class="badge bg-warning">
                                                    <i class="bi bi-arrow-left-right"></i> Transferred
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check-circle"></i> Active
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('d/m/Y', strtotime($fam_item['created_at'])); ?><br>
                                                <small><?php echo date('H:i', strtotime($fam_item['created_at'])); ?></small>
                                            </small>
                                        </td>
                                        <td class="text-center action-buttons">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <!-- View Button -->
                                                <a href="view_family.php?id=<?php echo urlencode($fam_item['family_id']); ?>" 
                                                   class="btn btn-outline-primary" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                
                                                <!-- Edit Button -->
                                                <a href="edit_family.php?id=<?php echo urlencode($fam_item['family_id']); ?>" 
                                                   class="btn btn-outline-info" title="Edit Family">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                
                                                <!-- Transfer Button -->
                                                <a href="transfer_family.php?id=<?php echo urlencode($fam_item['family_id']); ?>" 
                                                   class="btn btn-outline-warning" title="Transfer to Another GN">
                                                    <i class="bi bi-arrow-left-right"></i>
                                                </a>
                                                
                                                <!-- Delete Button -->
                                                <button type="button" class="btn btn-outline-danger" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#deleteModal<?php echo $fam_item['family_id']; ?>"
                                                        title="Delete Family">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                            
                                            <!-- Delete Confirmation Modal -->
                                            <div class="modal fade" id="deleteModal<?php echo $fam_item['family_id']; ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-danger text-white">
                                                            <h5 class="modal-title">
                                                                <i class="bi bi-exclamation-triangle"></i> Confirm Deletion
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to delete this family? This action cannot be undone.</p>
                                                            <div class="alert alert-warning">
                                                                <strong>Family ID:</strong> <?php echo htmlspecialchars($fam_item['family_id']); ?><br>
                                                                <strong>Head of Family:</strong> <?php echo htmlspecialchars($fam_item['head_name'] ?? 'N/A'); ?><br>
                                                                <strong>Total Members:</strong> <?php echo $fam_item['total_members']; ?>
                                                            </div>
                                                            <p class="text-danger">
                                                                <i class="bi bi-exclamation-circle"></i> 
                                                                <strong>Warning:</strong> This will permanently delete all family members, 
                                                                their employment, education, health, and land records.
                                                            </p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <a href="list_families.php?delete=1&id=<?php echo urlencode($fam_item['family_id']); ?>" 
                                                               class="btn btn-danger">
                                                                <i class="bi bi-trash"></i> Delete Family
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($fam_total_pages > 1): ?>
                <div class="card-footer">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mb-0">
                            <!-- Previous Page -->
                            <li class="page-item <?php echo $page_number <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="list_families.php?page=<?php echo $page_number - 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo $filter_status !== 'all' ? '&status=' . $filter_status : ''; ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            
                            <!-- Page Numbers -->
                            <?php 
                            $start_page = max(1, $page_number - 2);
                            $end_page = min($fam_total_pages, $start_page + 4);
                            
                            if ($end_page - $start_page < 4) {
                                $start_page = max(1, $end_page - 4);
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?php echo $i == $page_number ? 'active' : ''; ?>">
                                    <a class="page-link" 
                                       href="list_families.php?page=<?php echo $i; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo $filter_status !== 'all' ? '&status=' . $filter_status : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next Page -->
                            <li class="page-item <?php echo $page_number >= $fam_total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="list_families.php?page=<?php echo $page_number + 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo $filter_status !== 'all' ? '&status=' . $filter_status : ''; ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <div class="text-center mt-2">
                        <small class="text-muted">
                            Showing <?php echo count($fam_collection); ?> of <?php echo number_format($fam_total_rows); ?> families
                        </small>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <a href="add_family.php" class="btn btn-success w-100">
                                    <i class="bi bi-plus-lg"></i> Add New Family
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="add_member.php" class="btn btn-primary w-100">
                                    <i class="bi bi-person-plus"></i> Add Family Member
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="bulk_upload.php" class="btn btn-info w-100">
                                    <i class="bi bi-upload"></i> Bulk Upload
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="reports.php" class="btn btn-warning w-100">
                                    <i class="bi bi-graph-up"></i> Generate Reports
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <footer class="pt-3 mt-4 text-muted border-top">
            <div class="row">
                <div class="col-md-6">
                    <small>
                        <i class="bi bi-clock"></i> Last refresh: <?php echo date('H:i:s'); ?> |
                        <i class="bi bi-calendar"></i> <?php echo date('l, F j, Y'); ?>
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <small>
                        GN Division: <?php echo htmlspecialchars($gn_details['GN'] ?? 'Unknown'); ?> |
                        Family Count: <?php echo $fam_total_rows; ?> |
                        User: <?php echo htmlspecialchars($_SESSION['username'] ?? 'Guest'); ?>
                    </small>
                </div>
            </div>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Table row hover effect
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });
            
            // Search input focus
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.focus();
                
                // Clear search on Escape key
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        window.location.href = 'list_families.php';
                    }
                });
            }
        });
        
        // Export to Excel function
        function exportToExcel() {
            alert('Excel export feature would be implemented here. For now, you can use the browser print function (Ctrl+P).');
            // In a real implementation, this would generate an Excel file
        }
        
        // Print function
        function printFamilyList() {
            window.print();
        }
    </script>
</body>
</html>