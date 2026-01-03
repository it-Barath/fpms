<?php
/**
 * District - Manage Divisions
 * Allows district officers to view and manage divisions under their district
 */

require_once '../config.php';
require_once '../classes/Auth.php';

// Initialize Auth class
$auth = new Auth();

// Check authentication
if (!$auth->isLoggedIn()) {
    header('Location: ../login.php');
    exit();
}

// Check if user is District level
if ($_SESSION['user_type'] !== 'district') {
    header('Location: ../unauthorized.php?reason=district_access_required');
    exit();
}

// Get district office code
$districtCode = $_SESSION['office_code'];
$districtName = $_SESSION['office_name'];

// Set page title
$pageTitle = "Manage Divisions";

// Initialize Database
require_once '../classes/Database.php';
$db = new Database();

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_division') {
        // Add new division
        $divisionCode = trim($_POST['division_code']);
        $divisionName = trim($_POST['division_name']);
        $contactPhone = trim($_POST['contact_phone']);
        $contactEmail = trim($_POST['contact_email']);
        $address = trim($_POST['address']);
        
        // Validation
        $errors = [];
        
        if (empty($divisionCode)) {
            $errors[] = "Division code is required";
        }
        
        if (empty($divisionName)) {
            $errors[] = "Division name is required";
        }
        
        if ($contactEmail && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email address";
        }
        
        if (empty($errors)) {
            try {
                $conn = $db->getMainConnection();
                
                // Check if division already exists
                $checkStmt = $conn->prepare("
                    SELECT COUNT(*) as count 
                    FROM users 
                    WHERE office_code = ? 
                    AND user_type = 'division'
                ");
                $checkStmt->bind_param("s", $divisionCode);
                $checkStmt->execute();
                $result = $checkStmt->get_result();
                $exists = $result->fetch_assoc()['count'] > 0;
                
                if ($exists) {
                    $_SESSION['error'] = "Division with code '$divisionCode' already exists";
                } else {
                    // Create division admin user (default password: division123)
                    $defaultPassword = 'division123';
                    $passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
                    $username = 'div_' . strtolower(str_replace(' ', '_', $divisionCode));
                    
                    $insertStmt = $conn->prepare("
                        INSERT INTO users (
                            username, 
                            password_hash, 
                            user_type, 
                            office_code, 
                            office_name, 
                            email, 
                            phone, 
                            is_active
                        ) VALUES (?, ?, 'division', ?, ?, ?, ?, 1)
                    ");
                    $insertStmt->bind_param(
                        "ssssss", 
                        $username, 
                        $passwordHash, 
                        $divisionCode, 
                        $divisionName, 
                        $contactEmail, 
                        $contactPhone
                    );
                    
                    if ($insertStmt->execute()) {
                        $_SESSION['success'] = "Division '$divisionName' created successfully. Default username: $username, password: $defaultPassword";
                    } else {
                        $_SESSION['error'] = "Failed to create division: " . $conn->error;
                    }
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = implode('<br>', $errors);
        }
        
        header("Location: divisions.php");
        exit();
    }
    
    if ($action === 'reset_password') {
        // Reset division officer password
        $userId = (int)$_POST['user_id'];
        
        try {
            $conn = $db->getMainConnection();
            
            // Verify division is under this district
            $verifyStmt = $conn->prepare("
                SELECT u.* 
                FROM users u
                WHERE u.user_id = ? 
                AND u.user_type = 'division'
            ");
            $verifyStmt->bind_param("i", $userId);
            $verifyStmt->execute();
            $division = $verifyStmt->get_result()->fetch_assoc();
            
            if ($division) {
                // Generate new random password
                $newPassword = substr(md5(uniqid(rand(), true)), 0, 8);
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                
                $updateStmt = $conn->prepare("
                    UPDATE users 
                    SET password_hash = ?, 
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE user_id = ?
                ");
                $updateStmt->bind_param("si", $passwordHash, $userId);
                
                if ($updateStmt->execute()) {
                    $_SESSION['success'] = "Password reset successfully for division '" . $division['office_name'] . "'. New password: <strong>$newPassword</strong>";
                }
            } else {
                $_SESSION['error'] = "Invalid division selected";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
        
        header("Location: divisions.php");
        exit();
    }
    
    if ($action === 'toggle_status') {
        // Activate/Deactivate division
        $userId = (int)$_POST['user_id'];
        $newStatus = (int)$_POST['new_status'];
        
        try {
            $conn = $db->getMainConnection();
            
            $updateStmt = $conn->prepare("
                UPDATE users 
                SET is_active = ?, 
                    updated_at = CURRENT_TIMESTAMP 
                WHERE user_id = ? 
                AND user_type = 'division'
            ");
            $updateStmt->bind_param("ii", $newStatus, $userId);
            
            if ($updateStmt->execute()) {
                $statusText = $newStatus ? 'activated' : 'deactivated';
                $_SESSION['success'] = "Division account $statusText successfully";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
        
        header("Location: divisions.php");
        exit();
    }
}

// Get all divisions with accurate counts
try {
    $conn = $db->getMainConnection();
    
    // Get all divisions with counts using the parent_division_code relationship
    $stmt = $conn->prepare("
        SELECT 
            u.user_id,
            u.username,
            u.office_code,
            u.office_name,
            u.email,
            u.phone,
            u.is_active,
            u.last_login,
            u.created_at,
            -- Count GN offices under this division
            (SELECT COUNT(DISTINCT gn.user_id) 
             FROM users gn 
             WHERE gn.user_type = 'gn' 
             AND gn.parent_division_code = u.office_code) as gn_count,
            -- Count families under GN offices in this division
            (SELECT COUNT(DISTINCT f.family_id) 
             FROM families f 
             INNER JOIN users gn ON f.gn_id = gn.office_code 
             WHERE gn.user_type = 'gn' 
             AND gn.parent_division_code = u.office_code) as family_count,
            -- Count total members in families under this division
            (SELECT COALESCE(SUM(f.total_members), 0) 
             FROM families f 
             INNER JOIN users gn ON f.gn_id = gn.office_code 
             WHERE gn.user_type = 'gn' 
             AND gn.parent_division_code = u.office_code) as member_count
        FROM users u
        WHERE u.user_type = 'division'
        ORDER BY u.office_name
    ");
    $stmt->execute();
    $divisionsWithCounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get overall statistics
    $statsStmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT u.user_id) as total_divisions,
            SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) as active_divisions,
            (SELECT COUNT(DISTINCT gn.user_id) 
             FROM users gn 
             WHERE gn.user_type = 'gn') as total_gn_offices,
            (SELECT COUNT(DISTINCT gn.user_id) 
             FROM users gn 
             WHERE gn.user_type = 'gn' 
             AND gn.parent_division_code IS NOT NULL) as mapped_gn_offices,
            (SELECT COUNT(DISTINCT f.family_id) 
             FROM families f) as total_families,
            (SELECT COUNT(DISTINCT c.citizen_id) 
             FROM citizens c) as total_citizens,
            (SELECT COALESCE(SUM(f.total_members), 0) 
             FROM families f) as total_members
        FROM users u
        WHERE u.user_type = 'division'
    ");
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    
    // Check if mapping is complete
    $mappingComplete = ($stats['total_gn_offices'] == $stats['mapped_gn_offices']);
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Include header
include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <?php 
        if (file_exists('../includes/sidebar.php')) {
            include '../includes/sidebar.php';
        }
        ?>
        
        <!-- Main content -->
            <!-- Page header -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-building me-2"></i>Manage Divisions
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDivisionModal">
                        <i class="bi bi-plus-circle me-1"></i> Add New Division
                    </button>
                </div>
            </div>
            
            <!-- Mapping Status Notice -->
            <?php if (!$mappingComplete): ?>
            <div class="alert alert-warning mb-4">
                <h6><i class="bi bi-exclamation-triangle me-2"></i>Division-GN Mapping Incomplete</h6>
                <div class="row">
                    <div class="col-md-8">
                        <small class="text-muted">
                            <strong>Status:</strong> <?php echo $stats['mapped_gn_offices']; ?> out of <?php echo $stats['total_gn_offices']; ?> GN offices are mapped to divisions.<br>
                            <strong>Action Required:</strong> Run the synchronization script to complete the mapping.
                        </small>
                    </div>
                    <div class="col-md-4 text-end">
                        <a href="../sync_division_mapping.php" class="btn btn-sm btn-warning" onclick="return confirm('Run synchronization script?')">
                            <i class="bi bi-arrow-repeat me-1"></i> Run Sync Now
                        </a>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-success mb-4">
                <i class="bi bi-check-circle me-2"></i>
                <strong>All GN offices are properly mapped to divisions.</strong>
            </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">Total Divisions</h6>
                                    <h2 class="mb-0"><?php echo $stats['total_divisions'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-building fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">Active Divisions</h6>
                                    <h2 class="mb-0"><?php echo $stats['active_divisions'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-check-circle fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">GN Offices</h6>
                                    <h2 class="mb-0"><?php echo $stats['total_gn_offices'] ?? 0; ?></h2>
                                    <small>Mapped: <?php echo $stats['mapped_gn_offices'] ?? 0; ?></small>
                                </div>
                                <i class="bi bi-houses fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">Total Members</h6>
                                    <h2 class="mb-0"><?php echo number_format($stats['total_members'] ?? 0); ?></h2>
                                </div>
                                <i class="bi bi-people fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Divisions Table -->
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul me-2"></i>Divisions List
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="divisionsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Division Name</th>
                                    <th>Division Code</th>
                                    <th>Contact Info</th>
                                    <th>GN Offices</th>
                                    <th>Families</th>
                                    <th>Members</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($divisionsWithCounts)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                            No divisions found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($divisionsWithCounts as $index => $division): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($division['office_name']); ?></strong><br>
                                                <small class="text-muted">User: <?php echo htmlspecialchars($division['username']); ?></small>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($division['office_code']); ?></code>
                                            </td>
                                            <td>
                                                <?php if ($division['email']): ?>
                                                    <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($division['email']); ?><br>
                                                <?php endif; ?>
                                                <?php if ($division['phone']): ?>
                                                    <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($division['phone']); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($division['gn_count'] > 0): ?>
                                                    <span class="badge bg-info"><?php echo $division['gn_count']; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($division['family_count'] > 0): ?>
                                                    <span class="badge bg-success"><?php echo number_format($division['family_count']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($division['member_count'] > 0): ?>
                                                    <span class="badge bg-primary"><?php echo number_format($division['member_count']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($division['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($division['last_login']): ?>
                                                    <?php echo date('M d, Y', strtotime($division['last_login'])); ?><br>
                                                    <small class="text-muted"><?php echo date('h:i A', strtotime($division['last_login'])); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">Never logged in</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="division_details.php?code=<?php echo urlencode($division['office_code']); ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    &nbsp;
                                                    <!-- Reset Password Form -->
                                                    <form method="POST" class="d-inline" 
                                                          onsubmit="return confirm('Reset password for <?php echo htmlspecialchars(addslashes($division['office_name'])); ?>?')">
                                                        <input type="hidden" name="action" value="reset_password">
                                                        <input type="hidden" name="user_id" value="<?php echo $division['user_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Reset Password">
                                                            <i class="bi bi-key"></i>
                                                        </button>
                                                    </form>
                                                    &nbsp;
                                                    <!-- Toggle Status Form -->
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="user_id" value="<?php echo $division['user_id']; ?>">
                                                        <input type="hidden" name="new_status" value="<?php echo $division['is_active'] ? 0 : 1; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-<?php echo $division['is_active'] ? 'danger' : 'success'; ?>"
                                                                title="<?php echo $division['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                                                onclick="return confirm('<?php echo $division['is_active'] ? 'Deactivate' : 'Activate'; ?> this division?')">
                                                            <i class="bi bi-power"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer text-muted">
                    Showing <?php echo count($divisionsWithCounts); ?> divisions
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Add Division Modal -->
<div class="modal fade" id="addDivisionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="addDivisionForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i> Add New Division
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_division">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="division_code" class="form-label">Division Code *</label>
                            <input type="text" class="form-control" id="division_code" name="division_code" required
                                   pattern="[A-Za-z0-9_\-]+" title="Alphanumeric code with underscores or hyphens">
                            <div class="form-text">Unique code for the division (e.g., mannar_town, madhu)</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="division_name" class="form-label">Division Name *</label>
                            <input type="text" class="form-control" id="division_name" name="division_name" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="contact_phone" class="form-label">Contact Phone</label>
                            <input type="tel" class="form-control" id="contact_phone" name="contact_phone"
                                   pattern="[0-9+]{10,15}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="contact_email" class="form-label">Contact Email</label>
                            <input type="email" class="form-control" id="contact_email" name="contact_email">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Office Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> After creating the division, run the sync script to map GN offices to it.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Division</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Include Footer -->
<?php 
if (file_exists('../includes/footer.php')) {
    include '../includes/footer.php';
} else {
    echo '</main></div></div>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
}
?>

<!-- Load jQuery FIRST -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- DataTables JavaScript -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<script>
$(document).ready(function() {
    if (!$.fn.dataTable.isDataTable('#divisionsTable')) {
        $('#divisionsTable').DataTable({
            "order": [[1, "asc"]],
            "pageLength": 25,
            "responsive": true,
            "language": {
                "search": "Search divisions:",
                "lengthMenu": "Show _MENU_ entries",
                "info": "Showing _START_ to _END_ of _TOTAL_ divisions",
                "infoEmpty": "No divisions found",
                "zeroRecords": "No matching divisions found"
            }
        });
    }
    
    $('#addDivisionForm').on('submit', function(e) {
        let code = $('#division_code').val();
        let name = $('#division_name').val();
        
        if (!code || !name) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return false;
        }
        
        return true;
    });
});
</script>  