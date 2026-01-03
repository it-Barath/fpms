<?php
/**
 * District - View Family Details
 * Comprehensive view of a single family's information
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

// Get family ID from URL
$familyId = $_GET['id'] ?? '';

if (empty($familyId)) {
    header('Location: view_families.php');
    exit();
}

// Set page title
$pageTitle = "Family Details";

// Initialize Database
require_once '../classes/Database.php';
$db = new Database();

try {
    $conn = $db->getMainConnection();
    
    // Get family details
    $familyQuery = "
        SELECT 
            f.*,
            gn.office_name as gn_name,
            gn.office_code as gn_code,
            gn.parent_division_code,
            division.office_name as division_name,
            division.office_code as division_code,
            creator.username as created_by_username,
            -- Get family head info
            (SELECT c.full_name FROM citizens c WHERE c.family_id = f.family_id AND c.relation_to_head = 'self' LIMIT 1) as head_name,
            (SELECT c.identification_number FROM citizens c WHERE c.family_id = f.family_id AND c.relation_to_head = 'self' LIMIT 1) as head_nic,
            (SELECT c.mobile_phone FROM citizens c WHERE c.family_id = f.family_id AND c.relation_to_head = 'self' LIMIT 1) as head_phone,
            (SELECT c.email FROM citizens c WHERE c.family_id = f.family_id AND c.relation_to_head = 'self' LIMIT 1) as head_email
        FROM families f
        LEFT JOIN users gn ON f.gn_id = gn.office_code AND gn.user_type = 'gn'
        LEFT JOIN users division ON gn.parent_division_code = division.office_code AND division.user_type = 'division'
        LEFT JOIN users creator ON f.created_by = creator.user_id
        WHERE f.family_id = ?
    ";
    
    $stmt = $conn->prepare($familyQuery);
    $stmt->bind_param("s", $familyId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['error'] = "Family not found";
        header('Location: view_families.php');
        exit();
    }
    
    $family = $result->fetch_assoc();
    
    // Get all family members
    $membersQuery = "
        SELECT 
            c.*,
            TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) as age
        FROM citizens c
        WHERE c.family_id = ?
        ORDER BY 
            CASE c.relation_to_head 
                WHEN 'self' THEN 1
                WHEN 'spouse' THEN 2
                WHEN 'child' THEN 3
                ELSE 4
            END,
            c.date_of_birth ASC
    ";
    
    $membersStmt = $conn->prepare($membersQuery);
    $membersStmt->bind_param("s", $familyId);
    $membersStmt->execute();
    $members = $membersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get employment details for members
    $employmentQuery = "
        SELECT e.*, c.full_name, c.citizen_id
        FROM employment e
        INNER JOIN citizens c ON e.citizen_id = c.citizen_id
        WHERE c.family_id = ? AND e.is_current_job = 1
    ";
    
    $empStmt = $conn->prepare($employmentQuery);
    $empStmt->bind_param("s", $familyId);
    $empStmt->execute();
    $employments = $empStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get education details for members
    $educationQuery = "
        SELECT ed.*, c.full_name, c.citizen_id
        FROM education ed
        INNER JOIN citizens c ON ed.citizen_id = c.citizen_id
        WHERE c.family_id = ? AND ed.is_current = 1
    ";
    
    $eduStmt = $conn->prepare($educationQuery);
    $eduStmt->bind_param("s", $familyId);
    $eduStmt->execute();
    $educations = $eduStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get health conditions
    $healthQuery = "
        SELECT h.*, c.full_name, c.citizen_id
        FROM health_conditions h
        INNER JOIN citizens c ON h.citizen_id = c.citizen_id
        WHERE c.family_id = ?
    ";
    
    $healthStmt = $conn->prepare($healthQuery);
    $healthStmt->bind_param("s", $familyId);
    $healthStmt->execute();
    $healthConditions = $healthStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get land details
    $landQuery = "SELECT * FROM land_details WHERE family_id = ?";
    $landStmt = $conn->prepare($landQuery);
    $landStmt->bind_param("s", $familyId);
    $landStmt->execute();
    $lands = $landStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get transfer history if any
    $transferQuery = "
        SELECT 
            th.*,
            from_gn.office_name as from_gn_name,
            to_gn.office_name as to_gn_name,
            req_user.username as requested_by_username,
            app_user.username as approved_by_username
        FROM transfer_history th
        LEFT JOIN users from_gn ON th.from_gn_id = from_gn.office_code
        LEFT JOIN users to_gn ON th.to_gn_id = to_gn.office_code
        LEFT JOIN users req_user ON th.requested_by_user_id = req_user.user_id
        LEFT JOIN users app_user ON th.approved_by_user_id = app_user.user_id
        WHERE th.family_id = ?
        ORDER BY th.request_date DESC
    ";
    
    $transferStmt = $conn->prepare($transferQuery);
    $transferStmt->bind_param("s", $familyId);
    $transferStmt->execute();
    $transfers = $transferStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Calculate family statistics
    $stats = [
        'total_members' => count($members),
        'adults' => 0,
        'children' => 0,
        'employed' => 0,
        'students' => 0,
        'total_income' => 0,
        'health_issues' => count($healthConditions)
    ];
    
    foreach ($members as $member) {
        $age = $member['age'];
        if ($age >= 18) {
            $stats['adults']++;
        } else {
            $stats['children']++;
        }
    }
    
    foreach ($employments as $emp) {
        if ($emp['employment_type'] !== 'unemployed') {
            $stats['employed']++;
            $stats['total_income'] += floatval($emp['monthly_income']);
        }
    }
    
    $stats['students'] = count($educations);
    
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
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <!-- Page header -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-house-door-fill me-2"></i>Family Details
                    <small class="text-muted"><?php echo htmlspecialchars($familyId); ?></small>
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="view_families.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Back to List
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Family Status Alert -->
            <?php if ($family['has_pending_transfer']): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Pending Transfer:</strong> This family has a pending transfer request.
            </div>
            <?php endif; ?>
            
            <?php if ($family['is_transferred']): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Transferred:</strong> This family has been transferred to another GN office.
            </div>
            <?php endif; ?>
            
            <!-- Family Overview Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <i class="bi bi-people fs-1 text-primary"></i>
                            <h3 class="mt-2"><?php echo $stats['total_members']; ?></h3>
                            <p class="text-muted mb-0">Total Members</p>
                            <small class="text-muted">
                                <?php echo $stats['adults']; ?> Adults, <?php echo $stats['children']; ?> Children
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <i class="bi bi-briefcase fs-1 text-success"></i>
                            <h3 class="mt-2"><?php echo $stats['employed']; ?></h3>
                            <p class="text-muted mb-0">Employed</p>
                            <small class="text-muted">
                                Income: Rs. <?php echo number_format($stats['total_income'], 2); ?>
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <i class="bi bi-book fs-1 text-info"></i>
                            <h3 class="mt-2"><?php echo $stats['students']; ?></h3>
                            <p class="text-muted mb-0">Students</p>
                            <small class="text-muted">Currently studying</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card border-warning">
                        <div class="card-body text-center">
                            <i class="bi bi-heart-pulse fs-1 text-warning"></i>
                            <h3 class="mt-2"><?php echo $stats['health_issues']; ?></h3>
                            <p class="text-muted mb-0">Health Issues</p>
                            <small class="text-muted">Reported conditions</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- Left Column -->
                <div class="col-md-6">
                    <!-- Basic Family Information -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle me-2"></i>Basic Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Family ID:</th>
                                    <td><strong><?php echo htmlspecialchars($family['family_id']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Division:</th>
                                    <td>
                                        <?php if ($family['division_name']): ?>
                                            <?php echo htmlspecialchars($family['division_name']); ?>
                                            <br><small class="text-muted">Code: <?php echo htmlspecialchars($family['division_code']); ?></small>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Not Mapped</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>GN Office:</th>
                                    <td>
                                        <?php echo htmlspecialchars($family['gn_name']); ?>
                                        <br><small class="text-muted">Code: <?php echo htmlspecialchars($family['gn_id']); ?></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Address:</th>
                                    <td><?php echo htmlspecialchars($family['address'] ?? 'Not specified'); ?></td>
                                </tr>
                                <tr>
                                    <th>Total Members:</th>
                                    <td><span class="badge bg-info"><?php echo $family['total_members']; ?></span></td>
                                </tr>
                                <tr>
                                    <th>Registered On:</th>
                                    <td><?php echo date('d/m/Y h:i A', strtotime($family['created_at'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Registered By:</th>
                                    <td><?php echo htmlspecialchars($family['created_by_username'] ?? 'Unknown'); ?></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <?php if ($family['has_pending_transfer']): ?>
                                            <span class="badge bg-warning">Pending Transfer</span>
                                        <?php elseif ($family['is_transferred']): ?>
                                            <span class="badge bg-secondary">Transferred</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Head of Family -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-person-badge me-2"></i>Head of Family
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($family['head_name']): ?>
                                <table class="table table-sm">
                                    <tr>
                                        <th width="40%">Name:</th>
                                        <td><strong><?php echo htmlspecialchars($family['head_name']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th>NIC:</th>
                                        <td><code><?php echo htmlspecialchars($family['head_nic']); ?></code></td>
                                    </tr>
                                    <tr>
                                        <th>Mobile:</th>
                                        <td>
                                            <?php if ($family['head_phone']): ?>
                                                <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($family['head_phone']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not provided</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Email:</th>
                                        <td>
                                            <?php if ($family['head_email']): ?>
                                                <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($family['head_email']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not provided</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            <?php else: ?>
                                <p class="text-muted text-center">Head of family information not available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Land Details -->
                    <?php if (!empty($lands)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-geo-alt me-2"></i>Land Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($lands as $land): ?>
                                <div class="border-bottom pb-3 mb-3">
                                    <div class="row">
                                        <div class="col-6">
                                            <strong>Type:</strong> 
                                            <span class="badge bg-secondary"><?php echo ucfirst($land['land_type']); ?></span>
                                        </div>
                                        <div class="col-6 text-end">
                                            <strong>Size:</strong> <?php echo $land['land_size_perches']; ?> perches
                                        </div>
                                    </div>
                                    <?php if ($land['deed_number']): ?>
                                        <div class="mt-2">
                                            <small><strong>Deed:</strong> <?php echo htmlspecialchars($land['deed_number']); ?></small>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($land['land_address']): ?>
                                        <div class="mt-1">
                                            <small><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($land['land_address']); ?></small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Right Column -->
                <div class="col-md-6">
                    <!-- Family Members -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-people me-2"></i>Family Members (<?php echo count($members); ?>)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($members)): ?>
                                <p class="text-muted text-center">No members registered</p>
                            <?php else: ?>
                                <div class="list-group">
                                    <?php foreach ($members as $member): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <?php echo htmlspecialchars($member['full_name']); ?>
                                                        <?php if ($member['relation_to_head'] === 'self'): ?>
                                                            <span class="badge bg-success">Head</span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <p class="mb-1">
                                                        <small class="text-muted">
                                                            <i class="bi bi-card-text"></i> <?php echo htmlspecialchars($member['identification_number']); ?> |
                                                            <i class="bi bi-calendar"></i> <?php echo $member['age']; ?> years |
                                                            <i class="bi bi-gender-<?php echo $member['gender'] === 'male' ? 'male' : 'female'; ?>"></i> <?php echo ucfirst($member['gender']); ?>
                                                        </small>
                                                    </p>
                                                    <p class="mb-0">
                                                        <small>
                                                            <span class="badge bg-info"><?php echo ucfirst(str_replace('_', ' ', $member['relation_to_head'])); ?></span>
                                                            <?php if ($member['marital_status']): ?>
                                                                <span class="badge bg-secondary"><?php echo ucfirst($member['marital_status']); ?></span>
                                                            <?php endif; ?>
                                                        </small>
                                                    </p>
                                                </div>
                                                <a href="citizen_details.php?id=<?php echo $member['citizen_id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Employment Information -->
                    <?php if (!empty($employments)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-briefcase me-2"></i>Employment Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($employments as $emp): ?>
                                <div class="border-bottom pb-3 mb-3">
                                    <h6><?php echo htmlspecialchars($emp['full_name']); ?></h6>
                                    <p class="mb-1">
                                        <strong>Type:</strong> 
                                        <span class="badge bg-primary"><?php echo ucfirst(str_replace('_', ' ', $emp['employment_type'])); ?></span>
                                    </p>
                                    <?php if ($emp['designation']): ?>
                                        <p class="mb-1"><strong>Designation:</strong> <?php echo htmlspecialchars($emp['designation']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($emp['employer_name']): ?>
                                        <p class="mb-1"><strong>Employer:</strong> <?php echo htmlspecialchars($emp['employer_name']); ?></p>
                                    <?php endif; ?>
                                    <p class="mb-0">
                                        <strong>Monthly Income:</strong> 
                                        <span class="text-success">Rs. <?php echo number_format($emp['monthly_income'], 2); ?></span>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Health Conditions -->
                    <?php if (!empty($healthConditions)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-heart-pulse me-2"></i>Health Conditions
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($healthConditions as $health): ?>
                                <div class="border-bottom pb-3 mb-3">
                                    <h6><?php echo htmlspecialchars($health['full_name']); ?></h6>
                                    <p class="mb-1">
                                        <strong>Condition:</strong> <?php echo htmlspecialchars($health['condition_name']); ?>
                                        <span class="badge bg-<?php 
                                            echo $health['severity'] === 'severe' ? 'danger' : 
                                                ($health['severity'] === 'moderate' ? 'warning' : 'info'); 
                                        ?>">
                                            <?php echo ucfirst($health['severity']); ?>
                                        </span>
                                    </p>
                                    <p class="mb-0">
                                        <small class="text-muted">
                                            Type: <?php echo ucfirst(str_replace('_', ' ', $health['condition_type'])); ?>
                                            <?php if ($health['is_permanent']): ?>
                                                | <span class="badge bg-secondary">Permanent</span>
                                            <?php endif; ?>
                                        </small>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Transfer History -->
                    <?php if (!empty($transfers)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-arrow-left-right me-2"></i>Transfer History
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($transfers as $transfer): ?>
                                <div class="border-bottom pb-3 mb-3">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong>From:</strong> <?php echo htmlspecialchars($transfer['from_gn_name']); ?><br>
                                            <strong>To:</strong> <?php echo htmlspecialchars($transfer['to_gn_name']); ?>
                                        </div>
                                        <div>
                                            <span class="badge bg-<?php 
                                                echo $transfer['current_status'] === 'completed' ? 'success' : 
                                                    ($transfer['current_status'] === 'approved' ? 'info' : 
                                                    ($transfer['current_status'] === 'rejected' ? 'danger' : 'warning')); 
                                            ?>">
                                                <?php echo ucfirst($transfer['current_status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <p class="mb-0 mt-2">
                                        <small class="text-muted">
                                            Requested: <?php echo date('d/m/Y', strtotime($transfer['request_date'])); ?>
                                            by <?php echo htmlspecialchars($transfer['requested_by_username']); ?>
                                        </small>
                                    </p>
                                    <?php if ($transfer['transfer_reason']): ?>
                                        <p class="mb-0 mt-1">
                                            <small><strong>Reason:</strong> <?php echo htmlspecialchars($transfer['transfer_reason']); ?></small>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
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

<style>
@media print {
    .btn-toolbar, .sidebar, .border-bottom { display: none !important; }
    .col-md-9 { flex: 0 0 100%; max-width: 100%; }
}
</style>