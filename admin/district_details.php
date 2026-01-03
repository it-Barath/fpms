<?php
// district_details.php
// View detailed information about a specific district

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

// Get district ID from URL
$districtId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($districtId <= 0) {
    header('Location: districts.php?error=invalid_id');
    exit();
}

// Get district information
$district = $userManager->getUserById($districtId);

if (!$district || $district['user_type'] !== 'district') {
    header('Location: districts.php?error=district_not_found');
    exit();
}

// Get district statistics
$districtStats = $userManager->getDistrictStats($district['office_name']);

// Get divisions under this district
$divisions = $userManager->getDivisionalSecretariatsUnderDistrict($district['office_name']);

// Get recent activities for this district
$recentActivities = $userManager->getRecentActivities(20, $district['office_name']);

// Get form submission statistics for this district
$formStats = $userManager->getFormSubmissionStats();

// Get user statistics for this district
$userStats = $userManager->getUserStatistics();

// Get all users under this district
$districtUsers = $userManager->getUsersByDistrict($district['office_name']);

// Set page title
$pageTitle = "District Details: " . htmlspecialchars($district['office_name']);
$pageDescription = "Detailed information and statistics for " . htmlspecialchars($district['office_name']);

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
                        <i class="fas fa-building me-2"></i><?php echo $pageTitle; ?>
                    </h1>
                    <p class="lead mb-0"><?php echo $pageDescription; ?></p>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="districts.php">Districts</a></li>
                            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($district['office_name']); ?></li>
                        </ol>
                    </nav>
                </div>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="districts.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Back to Districts
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="exportDistrictData()">
                            <i class="fas fa-file-excel me-1"></i> Export
                        </button>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-primary dropdown-toggle" type="button" id="districtActions" data-bs-toggle="dropdown">
                            <i class="fas fa-cog me-1"></i> Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#editDistrictModal">
                                    <i class="fas fa-edit me-2"></i> Edit District
                                </a>
                            </li>
                            <li>
                                <form method="POST" action="districts.php" class="d-inline">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?php echo $district['user_id']; ?>">
                                    <button type="submit" class="dropdown-item" 
                                            onclick="return confirm('Reset password for <?php echo htmlspecialchars($district['office_name']); ?>?')">
                                        <i class="fas fa-key me-2"></i> Reset Password
                                    </button>
                                </form>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="districts.php" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?php echo $district['user_id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $district['is_active'] ? 0 : 1; ?>">
                                    <button type="submit" class="dropdown-item text-<?php echo $district['is_active'] ? 'danger' : 'success'; ?>"
                                            onclick="return confirm('<?php echo $district['is_active'] ? 'Deactivate' : 'Activate'; ?> this district?')">
                                        <i class="fas fa-power-off me-2"></i> 
                                        <?php echo $district['is_active'] ? 'Deactivate District' : 'Activate District'; ?>
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
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
            
            <!-- District Overview Row -->
            <div class="row mb-4">
                <!-- District Info Card -->
                <div class="col-lg-4 mb-4">
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-info-circle me-2"></i>District Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <div class="d-flex align-items-center mb-2">
                                        <h4 class="mb-0"><?php echo htmlspecialchars($district['office_name']); ?></h4>
                                        <span class="badge bg-<?php echo $district['is_active'] ? 'success' : 'secondary'; ?> ms-2">
                                            <?php echo $district['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                    <p class="text-muted mb-0">
                                        <code><?php echo htmlspecialchars($district['office_code']); ?></code>
                                    </p>
                                </div>
                                
                                <div class="col-12">
                                    <table class="table table-sm">
                                        <tr>
                                            <td width="40%"><strong>Username:</strong></td>
                                            <td><?php echo htmlspecialchars($district['username']); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Email:</strong></td>
                                            <td>
                                                <?php if (!empty($district['email'])): ?>
                                                    <a href="mailto:<?php echo htmlspecialchars($district['email']); ?>">
                                                        <?php echo htmlspecialchars($district['email']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">Not provided</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Phone:</strong></td>
                                            <td>
                                                <?php if (!empty($district['phone'])): ?>
                                                    <?php echo htmlspecialchars($district['phone']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not provided</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Account Created:</strong></td>
                                            <td><?php echo date('d M Y', strtotime($district['created_at'])); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Last Updated:</strong></td>
                                            <td><?php echo date('d M Y', strtotime($district['updated_at'])); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Last Login:</strong></td>
                                            <td>
                                                <?php if ($district['last_login']): ?>
                                                    <?php echo date('d M Y h:i A', strtotime($district['last_login'])); ?>
                                                    <?php 
                                                    $daysSinceLogin = floor((time() - strtotime($district['last_login'])) / (60 * 60 * 24));
                                                    if ($daysSinceLogin > 30): ?>
                                                        <span class="badge bg-warning ms-1"><?php echo $daysSinceLogin; ?> days ago</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Card -->
                <div class="col-lg-8 mb-4">
                    <div class="card shadow">
                        <div class="card-header bg-info text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-bar me-2"></i>District Statistics
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Key Statistics -->
                                <div class="col-md-6">
                                    <div class="mb-4">
                                        <h6 class="text-muted mb-3">Key Statistics</h6>
                                        <div class="row">
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body p-3 text-center">
                                                        <div class="h3 mb-1 text-primary"><?php echo $districtStats['total_divisions']; ?></div>
                                                        <div class="small text-muted">Divisional Secretariats</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body p-3 text-center">
                                                        <div class="h3 mb-1 text-success"><?php echo $districtStats['total_gn_divisions']; ?></div>
                                                        <div class="small text-muted">GN Divisions</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body p-3 text-center">
                                                        <div class="h3 mb-1 text-warning"><?php echo number_format($districtStats['total_families']); ?></div>
                                                        <div class="small text-muted">Total Families</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body p-3 text-center">
                                                        <div class="h3 mb-1 text-primary"><?php echo number_format($districtStats['total_population']); ?></div>
                                                        <div class="small text-muted">Total Population</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- User Statistics -->
                                <div class="col-md-6">
                                    <div class="mb-4">
                                        <h6 class="text-muted mb-3">User Statistics</h6>
                                        <div class="row">
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body p-3 text-center">
                                                        <div class="h3 mb-1 text-success"><?php echo $districtStats['active_users']; ?></div>
                                                        <div class="small text-muted">Active Users</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body p-3 text-center">
                                                        <div class="h3 mb-1 text-secondary"><?php echo $districtStats['inactive_users']; ?></div>
                                                        <div class="small text-muted">Inactive Users</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body p-3 text-center">
                                                        <div class="h3 mb-1 text-warning"><?php echo $districtStats['pending_transfers']; ?></div>
                                                        <div class="small text-muted">Pending Transfers</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body p-3 text-center">
                                                        <div class="h3 mb-1 text-info"><?php echo $districtStats['pending_reviews']; ?></div>
                                                        <div class="small text-muted">Pending Reviews</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Averages -->
                                    <div class="mt-3">
                                        <h6 class="text-muted mb-2">Averages</h6>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php if ($districtStats['total_gn_divisions'] > 0): ?>
                                                <span class="badge bg-info">
                                                    <?php echo number_format($districtStats['total_families'] / $districtStats['total_gn_divisions'], 1); ?> families/GN
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($districtStats['total_families'] > 0): ?>
                                                <span class="badge bg-success">
                                                    <?php echo number_format($districtStats['total_population'] / $districtStats['total_families'], 1); ?> people/family
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($districtStats['total_divisions'] > 0): ?>
                                                <span class="badge bg-warning">
                                                    <?php echo number_format($districtStats['total_gn_divisions'] / $districtStats['total_divisions'], 1); ?> GN/Division
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Divisional Secretariats Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow">
                        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-sitemap me-2"></i>Divisional Secretariats
                                <span class="badge bg-light text-dark ms-2"><?php echo count($divisions); ?></span>
                            </h5>
                            <button class="btn btn-light btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#divisionsCollapse">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="collapse show" id="divisionsCollapse">
                            <div class="card-body">
                                <?php if (empty($divisions)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-sitemap fa-3x text-muted mb-3"></i>
                                        <h5>No Divisional Secretariats</h5>
                                        <p class="text-muted">No divisional secretariats found under this district.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="divisionsTable">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Division Name</th>
                                                    <th>Username</th>
                                                    <th>Contact</th>
                                                    <th>GN Divisions</th>
                                                    <th>Families</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($divisions as $index => $division): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($division['office_name']); ?></strong><br>
                                                        <code class="small"><?php echo htmlspecialchars($division['office_code']); ?></code>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($division['username']); ?></td>
                                                    <td>
                                                        <?php if (!empty($division['email'])): ?>
                                                            <div class="small">
                                                                <i class="fas fa-envelope text-primary"></i> 
                                                                <a href="mailto:<?php echo htmlspecialchars($division['email']); ?>" class="small">
                                                                    <?php echo htmlspecialchars($division['email']); ?>
                                                                </a>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($division['phone'])): ?>
                                                            <div class="small">
                                                                <i class="fas fa-phone text-success"></i> 
                                                                <?php echo htmlspecialchars($division['phone']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-success"><?php echo $division['gn_count'] ?? 0; ?> GN</span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-warning"><?php echo number_format($division['family_count'] ?? 0); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($division['is_active']): ?>
                                                            <span class="badge bg-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="division_details.php?id=<?php echo $division['user_id']; ?>" 
                                                               class="btn btn-outline-info" title="View Details">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-outline-primary" 
                                                                    data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                                    onclick="loadUserData(<?php echo $division['user_id']; ?>)">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
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
                    </div>
                </div>
            </div>
            
            <!-- Users Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow">
                        <div class="card-header bg-warning text-white d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-users me-2"></i>All Users in District
                                <span class="badge bg-light text-dark ms-2"><?php echo count($districtUsers); ?></span>
                            </h5>
                            <button class="btn btn-light btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#usersCollapse">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="collapse show" id="usersCollapse">
                            <div class="card-body">
                                <?php if (empty($districtUsers)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                        <h5>No Users Found</h5>
                                        <p class="text-muted">No users found under this district.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="usersTable">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>User Type</th>
                                                    <th>Username</th>
                                                    <th>Office Name</th>
                                                    <th>Contact</th>
                                                    <th>Last Login</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $userTypes = [
                                                    'district' => 'District',
                                                    'division' => 'Division', 
                                                    'gn' => 'GN Division'
                                                ];
                                                
                                                foreach ($districtUsers as $index => $user): 
                                                    $statusInfo = $user['status_info'] ?? [];
                                                ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $user['user_type'] === 'district' ? 'primary' : 
                                                                 ($user['user_type'] === 'division' ? 'success' : 'info'); 
                                                        ?>">
                                                            <?php echo $userTypes[$user['user_type']] ?? ucfirst($user['user_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($user['office_name']); ?></strong><br>
                                                        <code class="small"><?php echo htmlspecialchars($user['office_code']); ?></code>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($user['email'])): ?>
                                                            <div class="small">
                                                                <i class="fas fa-envelope text-primary"></i> 
                                                                <?php echo htmlspecialchars($user['email']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($user['phone'])): ?>
                                                            <div class="small">
                                                                <i class="fas fa-phone text-success"></i> 
                                                                <?php echo htmlspecialchars($user['phone']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($user['last_login']): ?>
                                                            <div class="small">
                                                                <?php echo date('d M Y', strtotime($user['last_login'])); ?><br>
                                                                <?php echo date('h:i A', strtotime($user['last_login'])); ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted small">Never</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($user['is_active']): ?>
                                                            <span class="badge bg-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <?php if ($user['user_type'] === 'division'): ?>
                                                                <a href="division_details.php?id=<?php echo $user['user_id']; ?>" 
                                                                   class="btn btn-outline-info" title="View Details">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                            <?php elseif ($user['user_type'] === 'gn'): ?>
                                                                <a href="gn_details.php?id=<?php echo $user['user_id']; ?>" 
                                                                   class="btn btn-outline-info" title="View Details">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-outline-primary" 
                                                                    data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                                    onclick="loadUserData(<?php echo $user['user_id']; ?>)">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <form method="POST" action="districts.php" class="d-inline">
                                                                <input type="hidden" name="action" value="reset_password">
                                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                                <button type="submit" class="btn btn-outline-warning" title="Reset Password">
                                                                    <i class="fas fa-key"></i>
                                                                </button>
                                                            </form>
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
                    </div>
                </div>
            </div>
            
            <!-- Recent Activities Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card shadow">
                        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-history me-2"></i>Recent Activities
                                <span class="badge bg-light text-dark ms-2"><?php echo count($recentActivities); ?></span>
                            </h5>
                            <button class="btn btn-light btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#activitiesCollapse">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="collapse show" id="activitiesCollapse">
                            <div class="card-body">
                                <?php if (empty($recentActivities)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                        <h5>No Recent Activities</h5>
                                        <p class="text-muted">No activities recorded for this district.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="activity-timeline">
                                        <?php foreach ($recentActivities as $activity): ?>
                                            <div class="activity-item mb-3">
                                                <div class="activity-icon">
                                                    <?php 
                                                    $icon = 'circle';
                                                    switch($activity['action_type']) {
                                                        case 'login': $icon = 'sign-in-alt'; break;
                                                        case 'logout': $icon = 'sign-out-alt'; break;
                                                        case 'password_reset': $icon = 'key'; break;
                                                        case 'user_created': $icon = 'user-plus'; break;
                                                        case 'user_updated': $icon = 'user-edit'; break;
                                                        case 'form_submission': $icon = 'file-upload'; break;
                                                        default: $icon = 'circle';
                                                    }
                                                    ?>
                                                    <i class="fas fa-<?php echo $icon; ?>"></i>
                                                </div>
                                                <div class="activity-content">
                                                    <div class="d-flex justify-content-between">
                                                        <strong class="mb-1">
                                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $activity['action_type']))); ?>
                                                        </strong>
                                                        <small class="text-muted">
                                                            <?php echo date('d M Y H:i', strtotime($activity['created_at'])); ?>
                                                        </small>
                                                    </div>
                                                    <p class="mb-1 small">
                                                        <?php if ($activity['username']): ?>
                                                            <strong>User:</strong> <?php echo htmlspecialchars($activity['username']); ?> 
                                                            (<?php echo htmlspecialchars($activity['office_name']); ?>)
                                                        <?php endif; ?>
                                                    </p>
                                                    <?php if ($activity['ip_address']): ?>
                                                        <small class="text-muted">
                                                            <i class="fas fa-globe me-1"></i>IP: <?php echo htmlspecialchars($activity['ip_address']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Edit District Modal -->
<div class="modal fade" id="editDistrictModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="districts.php" id="editDistrictForm">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit District Information
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_district">
                    <input type="hidden" name="user_id" value="<?php echo $district['user_id']; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($district['username']); ?>" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editOfficeName" class="form-label">District Name *</label>
                        <input type="text" class="form-control" id="editOfficeName" name="office_name" 
                               value="<?php echo htmlspecialchars($district['office_name']); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editEmail" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="editEmail" name="email" 
                               value="<?php echo htmlspecialchars($district['email'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="editPhone" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="editPhone" name="phone" 
                               value="<?php echo htmlspecialchars($district['phone'] ?? ''); ?>">
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

<!-- Edit User Modal (for editing division/GN users) -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="districts.php" id="editUserForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit User Information
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="editUserId">
                    
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" id="editUserUsername" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editUserOfficeName" class="form-label">Office Name *</label>
                        <input type="text" class="form-control" id="editUserOfficeName" name="office_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editUserEmail" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="editUserEmail" name="email">
                    </div>
                    
                    <div class="mb-3">
                        <label for="editUserPhone" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="editUserPhone" name="phone">
                    </div>
                    
                    <div class="mb-3">
                        <label for="editUserStatus" class="form-label">Status</label>
                        <select class="form-control" id="editUserStatus" name="is_active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Include Footer -->
<?php include '../includes/footer.php'; ?>

<!-- JavaScript -->
<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#divisionsTable').DataTable({
        "pageLength": 10,
        "order": [[0, "asc"]]
    });
    
    $('#usersTable').DataTable({
        "pageLength": 10,
        "order": [[1, "asc"], [0, "asc"]]
    });
});

// Export district data to Excel
function exportDistrictData() {
    // Create CSV content
    let csv = 'District Information\n';
    csv += 'Name,Code,Username,Email,Phone,Status,Last Login,Created At\n';
    csv += `"${$district['office_name']}","${$district['office_code']}","${$district['username']}",`;
    csv += `"${$district['email'] || ''}","${$district['phone'] || ' '}",`;
    csv += `"${$district['is_active'] ? 'Active' : 'Inactive'}",`;
    csv += `"${$district['last_login'] ? date('Y-m-d H:i', strtotime($district['last_login'])) : 'Never'}",`;
    csv += `"${date('Y-m-d', strtotime($district['created_at']))}"\n\n`;
    
    csv += 'District Statistics\n';
    csv += 'Divisional Secretariats,GN Divisions,Total Families,Total Population,Active Users,Inactive Users,Pending Transfers,Pending Reviews\n';
    csv += `${$districtStats['total_divisions']},${$districtStats['total_gn_divisions']},`;
    csv += `${$districtStats['total_families']},${$districtStats['total_population']},`;
    csv += `${$districtStats['active_users']},${$districtStats['inactive_users']},`;
    csv += `${$districtStats['pending_transfers']},${$districtStats['pending_reviews']}\n\n`;
    
    csv += 'Divisional Secretariats\n';
    csv += '#,Division Name,Username,Email,Phone,GN Divisions,Families,Status\n';
    
    const divisionRows = document.querySelectorAll('#divisionsTable tbody tr');
    divisionRows.forEach((row, index) => {
        const cells = row.querySelectorAll('td');
        const rowData = [
            index + 1,
            cells[1].textContent.trim(),
            cells[2].textContent.trim(),
            cells[3].textContent.includes('@') ? cells[3].querySelector('a')?.textContent || '' : '',
            cells[3].textContent.includes('fa-phone') ? cells[3].textContent.match(/\d+/g)?.join('') || '' : '',
            cells[4].textContent.match(/\d+/)?.[0] || '0',
            cells[5].textContent.match(/[\d,]+/)?.[0]?.replace(/,/g, '') || '0',
            cells[6].textContent.trim()
        ];
        
        csv += rowData.map(cell => `"${cell}"`).join(',') + '\n';
    });
    
    // Create and download CSV file
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `district_${$district['office_code']}_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Load user data for editing
function loadUserData(userId) {
    // In a real implementation, you would fetch user data via AJAX
    // For now, we'll just set the user ID
    document.getElementById('editUserId').value = userId;
    
    // You would typically fetch user data here:
    // fetch(`get_user_data.php?id=${userId}`)
    //     .then(response => response.json())
    //     .then(data => {
    //         document.getElementById('editUserUsername').value = data.username;
    //         document.getElementById('editUserOfficeName').value = data.office_name;
    //         document.getElementById('editUserEmail').value = data.email || '';
    //         document.getElementById('editUserPhone').value = data.phone || '';
    //         document.getElementById('editUserStatus').value = data.is_active;
    //     });
}

// Refresh page
function refreshPage() {
    window.location.reload();
}

// Print district details
function printDistrictDetails() {
    window.print();
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
    padding: 15px;
    border-radius: 4px;
    border-left: 3px solid #007bff;
    margin-bottom: 10px;
}

.card-header .btn-light {
    color: #fff;
    background: rgba(255, 255, 255, 0.2);
    border-color: rgba(255, 255, 255, 0.3);
}

.card-header .btn-light:hover {
    background: rgba(255, 255, 255, 0.3);
}

.breadcrumb {
    background: transparent;
    padding-left: 0;
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}

.badge {
    font-size: 0.85em;
    padding: 0.35em 0.65em;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
}

@media print {
    .sidebar-column, .btn-toolbar, .dropdown, .card-header .btn-light {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
    }
    
    .card-header {
        background: #fff !important;
        color: #000 !important;
        border-bottom: 2px solid #000 !important;
    }
}
</style>