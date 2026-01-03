<?php

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

// Set page title and description
$pageTitle = "Manage Districts";
$pageDescription = "Manage district secretariats and their information";

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $successMessage = '';
    $errorMessage = '';
    
    switch ($action) {
        case 'add_district':
            // Add new district
            $districtCode = $validator->sanitize($_POST['district_code'] ?? '');
            $districtName = $validator->sanitize($_POST['district_name'] ?? '');
            $username = $validator->sanitize($_POST['username'] ?? '');
            $email = $validator->sanitize($_POST['email'] ?? '');
            $phone = $validator->sanitize($_POST['phone'] ?? '');
            $address = $validator->sanitize($_POST['address'] ?? '');
            
            // Validate inputs
            $errors = [];
            
            if (empty($districtCode)) {
                $errors[] = "District code is required";
            }
            
            if (empty($districtName)) {
                $errors[] = "District name is required";
            }
            
            if (empty($username)) {
                $errors[] = "Username is required";
            }
            
            if (!empty($email) && !$validator->validateEmail($email)) {
                $errors[] = "Invalid email address";
            }
            
            if (!empty($phone) && !$validator->validatePhone($phone)) {
                $errors[] = "Invalid phone number";
            }
            
            if (empty($errors)) {
                // Generate default password if not provided
                $password = $_POST['password'] ?? generateRandomPassword(12);
                
                // Create user data array
                $userData = [
                    'username' => $username,
                    'password' => $password,
                    'user_type' => 'district',
                    'office_code' => $districtCode,
                    'office_name' => $districtName,
                    'email' => $email,
                    'phone' => $phone
                ];
                
                // Create district user
                list($success, $message, $userId) = $auth->createUser($userData);
                
                if ($success) {
                    // Log additional district details if needed
                    logActivity('district_created', 
                        "Created district: {$districtName} ({$districtCode}) with username: {$username}", 
                        $currentUser['user_id']);
                    
                    $successMessage = "District '{$districtName}' created successfully. ";
                    $successMessage .= "Username: <strong>{$username}</strong>, Password: <strong>{$password}</strong>";
                } else {
                    $errorMessage = $message;
                }
            } else {
                $errorMessage = implode('<br>', $errors);
            }
            break;
            
        case 'reset_password':
            // Reset district officer password
            $userId = (int)($_POST['user_id'] ?? 0);
            
            if ($userId > 0) {
                list($success, $message, $newPassword) = $auth->resetPassword($userId, $currentUser['user_id']);
                
                if ($success) {
                    $successMessage = "Password reset successfully. New password: <strong>{$newPassword}</strong>";
                } else {
                    $errorMessage = $message;
                }
            }
            break;
            
        case 'toggle_status':
            // Activate/Deactivate district
            $userId = (int)($_POST['user_id'] ?? 0);
            $newStatus = (int)($_POST['new_status'] ?? 0);
            
            if ($userId > 0) {
                $sql = "UPDATE users SET is_active = ?, updated_at = NOW() WHERE user_id = ? AND user_type = 'district'";
                $stmt = $userManager->getConnection()->prepare($sql);


                $stmt->bind_param("ii", $newStatus, $userId);
                
                if ($stmt->execute()) {
                    $statusText = $newStatus ? 'activated' : 'deactivated';
                    $successMessage = "District account {$statusText} successfully";
                    
                    logActivity('user_status_changed', 
                        "District account {$statusText} for user ID: {$userId}", 
                        $currentUser['user_id']);
                } else {
                    $errorMessage = "Failed to update district status";
                }
            }
            break;
            
        case 'edit_district':
            // Edit district information
            $userId = (int)($_POST['user_id'] ?? 0);
            $officeName = $validator->sanitize($_POST['office_name'] ?? '');
            $email = $validator->sanitize($_POST['email'] ?? '');
            $phone = $validator->sanitize($_POST['phone'] ?? '');
            
            if ($userId > 0 && !empty($officeName)) {
                $sql = "UPDATE users SET office_name = ?, email = ?, phone = ?, updated_at = NOW() 
                       WHERE user_id = ? AND user_type = 'district'";
                $stmt = $userManager->getConnection()->prepare($sql);


                $stmt->bind_param("sssi", $officeName, $email, $phone, $userId);
                
                if ($stmt->execute()) {
                    $successMessage = "District information updated successfully";
                    logActivity('district_updated', 
                        "Updated district info for user ID: {$userId}", 
                        $currentUser['user_id']);
                } else {
                    $errorMessage = "Failed to update district information";
                }
            }
            break;
    }
    
    // Store messages in session for display
    if ($successMessage) {
        $_SESSION['success_message'] = $successMessage;
    }
    if ($errorMessage) {
        $_SESSION['error_message'] = $errorMessage;
    }
    
    // Redirect to avoid form resubmission
    header("Location: districts.php");
    exit();
}

// Get all district users
$districts = $userManager->getAllDistrictsWithStats();

// Get district statistics
$stats = $userManager->getMohaStats();

// Get recent activities
$recentActivities = $userManager->getRecentActivities(10);

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
                        <i class="fas fa-map-marked-alt me-2"></i><?php echo $pageTitle; ?>
                    </h1>
                    <p class="lead mb-0"><?php echo $pageDescription; ?></p>
                </div>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="exportToExcel()">
                            <i class="fas fa-file-excel me-1"></i> Export
                        </button>
                    </div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDistrictModal">
                        <i class="fas fa-plus-circle me-1"></i> Add New District
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
                                        Total Districts
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo count($districts); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-building fa-2x text-gray-300"></i>
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
                                        Active Districts
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php 
                                        $activeCount = array_filter($districts, function($d) {
                                            return !empty($d['is_active']);
                                        });
                                        echo count($activeCount);
                                        ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-user-check fa-2x text-gray-300"></i>
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
                                        Total Divisions
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo $stats['total_divisions'] ?? 0; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-sitemap fa-2x text-gray-300"></i>
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
                                        GN Divisions
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo $stats['total_gn_divisions'] ?? 0; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-home fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Districts Table -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-table me-2"></i>District Secretariats
                    </h6>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="refreshTable()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($districts)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-building fa-4x text-muted mb-3"></i>
                            <h5>No Districts Found</h5>
                            <p class="text-muted">No district secretariats have been created yet.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDistrictModal">
                                <i class="fas fa-plus-circle me-1"></i> Add First District
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="districtsTable" width="100%" cellspacing="0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th>
                                        <th>District Information</th>
                                        <th>Statistics</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($districts as $index => $district): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($district['office_name']); ?></strong><br>
                                            <!--code class="small"><?php echo htmlspecialchars($district['office_code']); ?></code><br-->
                                            <span class="text-muted small">User: <?php echo htmlspecialchars($district['username']); ?></span>
                                        </td>
                                    
                                        <td>
                                            <?php 
                                            // Get district-specific statistics
                                            $districtStats = $userManager->getDistrictStats($district['office_name']);
                                            ?>
                                            <div class="d-flex flex-wrap gap-1">
                                                <span class="badge bg-info"><?php echo $districtStats['total_divisions']; ?> Divisions</span>
                                                <span class="badge bg-success"><?php echo $districtStats['total_gn_divisions']; ?> GN Divisions</span>
                                                <span class="badge bg-warning"><?php echo $districtStats['total_families']; ?> Families</span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($district['is_active'])): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($district['last_login'])): ?>
                                                <span class="small" title="<?php echo $district['last_login']; ?>">
                                                    <?php echo date('d M Y', strtotime($district['last_login'])); ?><br>
                                                    <?php echo date('h:i A', strtotime($district['last_login'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <!-- Edit Button -->
                                                <!--button type="button" class="btn btn-outline-primary" 
                                                        data-bs-toggle="modal" data-bs-target="#editDistrictModal"
                                                        data-userid="<?php echo $district['user_id']; ?>"
                                                        data-username="<?php echo htmlspecialchars($district['username']); ?>"
                                                        data-officename="<?php echo htmlspecialchars($district['office_name']); ?>"
                                                        data-email="<?php echo htmlspecialchars($district['email'] ?? ''); ?>"
                                                        data-phone="<?php echo htmlspecialchars($district['phone'] ?? ''); ?>"
                                                        onclick="setEditData(this)">
                                                    <i class="fas fa-edit"></i>
                                                </button-->
                                                
                                                <!-- Reset Password Form -->
                                                <form method="POST" class="d-inline" 
                                                      onsubmit="return confirm('Reset password for <?php echo htmlspecialchars($district['office_name']); ?>?')">
                                                    <input type="hidden" name="action" value="reset_password">
                                                    <input type="hidden" name="user_id" value="<?php echo $district['user_id']; ?>">
                                                    <button type="submit" class="btn btn-outline-warning" title="Reset Password">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                </form>
                                                &nbsp;
                                                <!-- Toggle Status Form -->
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $district['user_id']; ?>">
                                                    <input type="hidden" name="new_status" value="<?php echo $district['is_active'] ? 0 : 1; ?>">
                                                    <button type="submit" class="btn btn-outline-<?php echo $district['is_active'] ? 'danger' : 'success'; ?>"
                                                            title="<?php echo $district['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                                            onclick="return confirm('<?php echo $district['is_active'] ? 'Deactivate' : 'Activate'; ?> this district?')">
                                                        <i class="fas fa-power-off"></i>
                                                    </button>
                                                </form>
                                                &nbsp;
                                                <!-- View Details Link -->
                                                <a href="district_details.php?id=<?php echo $district['user_id']; ?>" 
                                                   class="btn btn-outline-info" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Additional Information Section -->
            <div class="row">
                <!-- Recent Activities -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-history me-2"></i>Recent Activities
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentActivities)): ?>
                                <p class="text-muted text-center py-3">No recent activities</p>
                            <?php else: ?>
                                <div class="activity-timeline">
                                    <?php foreach ($recentActivities as $activity): ?>
                                        <div class="activity-item mb-3">
                                            <div class="activity-icon">
                                                <i class="fas fa-<?php echo $this->getActivityIcon($activity['action_type']); ?>"></i>
                                            </div>
                                            <div class="activity-content">
                                                <p class="mb-1 small"><?php echo htmlspecialchars($activity['action_type']); ?></p>
                                                <p class="mb-0 text-muted small">
                                                    <?php echo htmlspecialchars($activity['username'] ?? 'System'); ?> • 
                                                    <?php echo date('d M Y H:i', strtotime($activity['created_at'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-chart-pie me-2"></i>System Statistics
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="card bg-light">
                                        <div class="card-body p-3">
                                            <h6 class="card-title text-muted">Total Families</h6>
                                            <h3 class="mb-0"><?php echo number_format($stats['total_families'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="card bg-light">
                                        <div class="card-body p-3">
                                            <h6 class="card-title text-muted">Total Population</h6>
                                            <h3 class="mb-0"><?php echo number_format($stats['total_population'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <h6>Distribution by District</h6>
                                <div class="progress mb-2" style="height: 20px;">
                                    <div class="progress-bar bg-primary" style="width: 25%">Colombo (25%)</div>
                                    <div class="progress-bar bg-success" style="width: 20%">Gampaha (20%)</div>
                                    <div class="progress-bar bg-info" style="width: 15%">Kalutara (15%)</div>
                                    <div class="progress-bar bg-warning" style="width: 40%">Other (40%)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Add District Modal -->
<div class="modal fade" id="addDistrictModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="addDistrictForm" onsubmit="return validateDistrictForm()">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>Add New District
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_district">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="district_code" class="form-label">District Code *</label>
                            <input type="text" class="form-control" id="district_code" name="district_code" required
                                   pattern="[A-Z0-9]{2,10}" title="2-10 character alphanumeric code">
                            <div class="form-text">Unique code for the district (e.g., D001, COL)</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="district_name" class="form-label">District Name *</label>
                            <input type="text" class="form-control" id="district_name" name="district_name" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Username *</label>
                            <input type="text" class="form-control" id="username" name="username" required
                                   pattern="[a-z0-9_]{4,20}" title="4-20 lowercase letters, numbers, underscores">
                            <div class="form-text">Login username for district officer</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="text" class="form-control" id="password" name="password"
                                   placeholder="Leave blank for auto-generation">
                            <div class="form-text">Auto-generate if left blank (recommended)</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Office Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> The district officer account will be created with 
                        user type <code>district</code> and will be able to manage divisions under this district.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create District</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit District Modal -->
<div class="modal fade" id="editDistrictModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editDistrictForm">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit District Information
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_district">
                    <input type="hidden" name="user_id" id="editUserId">
                    
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" id="editUsername" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editOfficeName" class="form-label">District Name *</label>
                        <input type="text" class="form-control" id="editOfficeName" name="office_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editEmail" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="editEmail" name="email">
                    </div>
                    
                    <div class="mb-3">
                        <label for="editPhone" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="editPhone" name="phone">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update District</button>
                </div>
            </form>
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
    $('#districtsTable').DataTable({
        "order": [[1, "asc"]],
        "pageLength": 25,
        "language": {
            "search": "Search districts:",
            "lengthMenu": "Show _MENU_ entries",
            "info": "Showing _START_ to _END_ of _TOTAL_ districts",
            "infoEmpty": "No districts found",
            "zeroRecords": "No matching districts found"
        }
    });
    
    // Auto-generate password
    $('#addDistrictForm #password').on('focus', function() {
        if (!$(this).val()) {
            $(this).val(generatePassword(12));
        }
    });
});

// Set data for edit modal
function setEditData(button) {
    const userId = button.getAttribute('data-userid');
    const username = button.getAttribute('data-username');
    const officeName = button.getAttribute('data-officename');
    const email = button.getAttribute('data-email');
    const phone = button.getAttribute('data-phone');
    
    document.getElementById('editUserId').value = userId;
    document.getElementById('editUsername').value = username;
    document.getElementById('editOfficeName').value = officeName;
    document.getElementById('editEmail').value = email;
    document.getElementById('editPhone').value = phone;
}

// Validate district form
function validateDistrictForm() {
    const districtCode = document.getElementById('district_code').value.trim();
    const districtName = document.getElementById('district_name').value.trim();
    const username = document.getElementById('username').value.trim();
    
    if (!districtCode || !districtName || !username) {
        alert('Please fill in all required fields');
        return false;
    }
    
    if (!/^[A-Z0-9]{2,10}$/.test(districtCode)) {
        alert('District code must be 2-10 uppercase alphanumeric characters');
        return false;
    }
    
    if (!/^[a-z0-9_]{4,20}$/.test(username)) {
        alert('Username must be 4-20 lowercase letters, numbers, or underscores');
        return false;
    }
    
    return true;
}

// Generate random password
function generatePassword(length = 12) {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    let password = '';
    for (let i = 0; i < length; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return password;
}

// Export to Excel
function exportToExcel() {
    let csv = 'District Name,District Code,Username,Email,Phone,Status,Last Login\n';
    
    const rows = document.querySelectorAll('#districtsTable tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const rowData = [];
        
        cells.forEach((cell, index) => {
            if (index !== 6) { // Skip actions column
                let text = cell.textContent.trim();
                text = text.replace(/\s+/g, ' '); // Remove extra spaces
                
                if (text.includes(',')) {
                    text = '"' + text + '"';
                }
                
                rowData.push(text);
            }
        });
        
        csv += rowData.join(',') + '\n';
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'districts_<?php echo date('Y-m-d'); ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Refresh table
function refreshTable() {
    const refreshBtn = document.querySelector('button[onclick="refreshTable()"]');
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    refreshBtn.disabled = true;
    
    setTimeout(() => {
        window.location.reload();
    }, 1000);
}

// Helper function for activity icons
function getActivityIcon(actionType) {
    const icons = {
        'login': 'sign-in-alt',
        'logout': 'sign-out-alt',
        'password_reset': 'key',
        'user_created': 'user-plus',
        'user_updated': 'user-edit',
        'district_created': 'building',
        'district_updated': 'edit'
    };
    return icons[actionType] || 'circle';
}
</script>

<!-- CSS Styles -->
<style>
.activity-timeline {
    position: relative;
    padding-left: 30px;
}

.activity-timeline:before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e0e0e0;
}

.activity-item {
    position: relative;
    padding-left: 10px;
}

.activity-icon {
    position: absolute;
    left: -30px;
    top: 0;
    width: 30px;
    height: 30px;
    background: #f8f9fa;
    border: 2px solid #dee2e6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
}

.activity-content {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    border-left: 3px solid #007bff;
}

.card {
    border: none;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}

.card-header {
    border-bottom: 1px solid #e3e6f0;
}

.badge {
    font-size: 0.8em;
    padding: 0.4em 0.8em;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Responsive table */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.9rem;
    }
    
    .btn-group {
        flex-wrap: wrap;
    }
    
    .btn-group .btn {
        margin-bottom: 2px;
    }
}
</style>