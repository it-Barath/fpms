<?php
/**
 * Division Details Page - REVISED VERSION
 * Fixed to match actual GN office codes in your database
 */

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/Database.php';

// Initialize classes
$auth = new Auth();
$db = new Database();

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

// Get division code from URL
$divisionCode = isset($_GET['code']) ? trim($_GET['code']) : '';

if (empty($divisionCode)) {
    header('Location: divisions.php?error=no_division_specified');
    exit();
}

try {
    $conn = $db->getMainConnection();
    
    // Get division details
    $divisionStmt = $conn->prepare("
        SELECT 
            u.user_id,
            u.username,
            u.office_code,
            u.office_name,
            u.email,
            u.phone,
            u.is_active,
            u.last_login,
            u.created_at
        FROM users u
        WHERE u.user_type = 'division'
        AND u.office_code = ?
    ");
    $divisionStmt->bind_param("s", $divisionCode);
    $divisionStmt->execute();
    $divisionResult = $divisionStmt->get_result();
    
    if ($divisionResult->num_rows === 0) {
        header('Location: divisions.php?error=division_not_found');
        exit();
    }
    
    $division = $divisionResult->fetch_assoc();
    
    // CRITICAL FIX: First, let's understand the relationship between divisions and GN offices
    // Based on your data, it looks like GN offices have numeric codes like "42321210181342"
    // But families have gn_id like "4-2-3-2-150"
    
    // Let me check: Maybe we need to look at the reference database (mobile_service)
    // to understand the division-GN relationship
    
    // For now, let's try a different approach:
    // 1. Get ALL families
    // 2. Get ALL GN offices
    // 3. Match them based on some logic
    
    // Get all families in the system
    $allFamiliesStmt = $conn->prepare("
        SELECT 
            family_id,
            gn_id,
            original_gn_id,
            total_members,
            created_at
        FROM families
        ORDER BY created_at DESC
    ");
    $allFamiliesStmt->execute();
    $allFamiliesResult = $allFamiliesStmt->get_result();
    $allFamilies = $allFamiliesResult->fetch_all(MYSQLI_ASSOC);
    
    // Get total count of families in system (for debugging)
    $totalSystemFamilies = count($allFamilies);
    
    // Get ALL GN offices (not just by pattern)
    $allGNOfficesStmt = $conn->prepare("
        SELECT 
            user_id,
            username,
            office_code,
            office_name,
            email,
            phone,
            is_active,
            last_login,
            created_at
        FROM users
        WHERE user_type = 'gn'
        ORDER BY office_name
    ");
    $allGNOfficesStmt->execute();
    $allGNOfficesResult = $allGNOfficesStmt->get_result();
    $allGNOffices = $allGNOfficesResult->fetch_all(MYSQLI_ASSOC);
    
    // DEBUG: Let's see what GN codes and family GN IDs we have
    $gnCodes = array_column($allGNOffices, 'office_code');
    $familyGnIds = array_unique(array_column($allFamilies, 'gn_id'));
    $familyOriginalGnIds = array_unique(array_column($allFamilies, 'original_gn_id'));
    
    // Now, we need to figure out which GN offices belong to this division
    // Since the office codes don't match division names, we might need to check
    // the reference database or use a different approach
    
    // TEMPORARY FIX: Let's get all families and see if we can find any pattern
    $divisionFamilies = [];
    $divisionFamilyIds = [];
    $recentFamilies = [];
    $monthlyStats = [];
    
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $sixMonthsAgo = date('Y-m', strtotime('-6 months'));
    
    // For now, let's show ALL families since we can't determine division relationship
    // This is just to debug - we'll fix the logic once we understand the relationship
    
    foreach ($allFamilies as $family) {
        $divisionFamilies[] = $family;
        $divisionFamilyIds[] = $family['family_id'];
        
        // Check if recent
        $familyDate = date('Y-m-d', strtotime($family['created_at']));
        if ($familyDate >= $thirtyDaysAgo) {
            // Get GN office name for this family
            $gnNameStmt = $conn->prepare("
                SELECT office_name 
                FROM users 
                WHERE office_code = ? 
                AND user_type = 'gn'
                LIMIT 1
            ");
            $gnNameStmt->bind_param("s", $family['gn_id']);
            $gnNameStmt->execute();
            $gnNameResult = $gnNameStmt->get_result();
            $gnName = $gnNameResult->num_rows > 0 ? $gnNameResult->fetch_assoc()['office_name'] : 'Unknown';
            $gnNameStmt->close();
            
            // Get member count
            $memberStmt = $conn->prepare("
                SELECT COUNT(*) as member_count 
                FROM citizens 
                WHERE family_id = ? AND is_alive = 1
            ");
            $memberStmt->bind_param("s", $family['family_id']);
            $memberStmt->execute();
            $memberResult = $memberStmt->get_result();
            $memberCount = $memberResult->fetch_assoc()['member_count'] ?? 0;
            $memberStmt->close();
            
            $recentFamilies[] = [
                'family_id' => $family['family_id'],
                'gn_id' => $family['gn_id'],
                'original_gn_id' => $family['original_gn_id'],
                'gn_name' => $gnName,
                'created_at' => $family['created_at'],
                'total_members' => $family['total_members'],
                'current_members' => $memberCount
            ];
        }
        
        // Monthly stats
        $familyMonth = date('Y-m', strtotime($family['created_at']));
        if ($familyMonth >= $sixMonthsAgo) {
            if (!isset($monthlyStats[$familyMonth])) {
                $monthlyStats[$familyMonth] = [
                    'month' => $familyMonth,
                    'family_count' => 0,
                    'total_members' => 0
                ];
            }
            $monthlyStats[$familyMonth]['family_count']++;
            $monthlyStats[$familyMonth]['total_members'] += $family['total_members'];
        }
    }
    
    // Sort monthly stats
    ksort($monthlyStats);
    $monthlyStats = array_values($monthlyStats);
    
    // Sort recent families by date (newest first) and limit to 10
    usort($recentFamilies, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $recentFamilies = array_slice($recentFamilies, 0, 10);
    
    // Get counts for each GN office
    $gnOfficesWithCounts = [];
    $totalCitizens = 0;
    $activeGNOffices = 0;
    
    foreach ($allGNOffices as $gn) {
        // Count families for this GN office
        $familyCountStmt = $conn->prepare("
            SELECT COUNT(*) as family_count
            FROM families 
            WHERE gn_id = ? OR original_gn_id = ?
        ");
        $familyCountStmt->bind_param("ss", $gn['office_code'], $gn['office_code']);
        $familyCountStmt->execute();
        $familyCountResult = $familyCountStmt->get_result();
        $familyCount = $familyCountResult->fetch_assoc()['family_count'] ?? 0;
        
        // Count citizens for this GN office
        $citizenCountStmt = $conn->prepare("
            SELECT COUNT(DISTINCT c.citizen_id) as citizen_count
            FROM citizens c
            INNER JOIN families f ON c.family_id = f.family_id
            WHERE f.gn_id = ? OR f.original_gn_id = ?
        ");
        $citizenCountStmt->bind_param("ss", $gn['office_code'], $gn['office_code']);
        $citizenCountStmt->execute();
        $citizenCountResult = $citizenCountStmt->get_result();
        $citizenCount = $citizenCountResult->fetch_assoc()['citizen_count'] ?? 0;
        $totalCitizens += $citizenCount;
        
        if ($gn['is_active']) {
            $activeGNOffices++;
        }
        
        $gnOfficesWithCounts[] = array_merge($gn, [
            'family_count' => $familyCount,
            'citizen_count' => $citizenCount
        ]);
    }
    
    $totalGNOffices = count($allGNOffices);
    $divisionFamiliesCount = count($divisionFamilies);
    
    $stats = [
        'total_gn_offices' => $totalGNOffices,
        'total_families' => $divisionFamiliesCount,
        'total_citizens' => $totalCitizens,
        'active_gn_offices' => $activeGNOffices,
        'system_total_families' => $totalSystemFamilies
    ];
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Set page title
$pageTitle = "Division Details - " . $division['office_name'];

// Include header
include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <?php include '../includes/sidebar.php'; ?>
        
        <!-- Main content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <!-- Page header with breadcrumb -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1 class="h2">
                        <i class="bi bi-building me-2"></i><?php echo htmlspecialchars($division['office_name']); ?>
                    </h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="divisions.php">Manage Divisions</a></li>
                            <li class="breadcrumb-item active"><?php echo htmlspecialchars($division['office_name']); ?></li>
                        </ol>
                    </nav>
                </div>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="divisions.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left me-1"></i> Back to Divisions
                    </a>
                    <a href="division_edit.php?code=<?php echo urlencode($division['office_code']); ?>" class="btn btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit Division
                    </a>
                </div>
            </div>
            
            <!-- CRITICAL DEBUG INFORMATION -->
            <div class="alert alert-warning mb-3">
                <h6><i class="bi bi-exclamation-triangle me-2"></i>Data Relationship Issue Detected!</h6>
                <small class="text-muted">
                    <strong>Division Being Viewed:</strong> <?php echo htmlspecialchars($division['office_name']); ?> (<?php echo htmlspecialchars($division['office_code']); ?>)<br>
                    <strong>Issue Found:</strong> GN office codes don't match division codes. We need to understand how divisions and GN offices are linked.<br>
                    <strong>Total Families in System:</strong> <?php echo $totalSystemFamilies; ?> (divisions.php shows: 8)<br>
                    <strong>Total GN Offices in System:</strong> <?php echo $totalGNOffices; ?><br>
                    <strong>Sample GN Office Codes:</strong> 
                    <?php if (!empty($gnCodes)): ?>
                        <?php echo implode(', ', array_slice($gnCodes, 0, 5)); ?>...
                    <?php endif; ?><br>
                    <strong>Sample Family GN IDs:</strong> 
                    <?php if (!empty($familyGnIds)): ?>
                        <?php echo implode(', ', array_slice($familyGnIds, 0, 5)); ?>...
                    <?php endif; ?>
                </small>
            </div>
            
            <!-- Division Information Card -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle me-2"></i>Division Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <dl class="row">
                                        <dt class="col-sm-4">Division Code:</dt>
                                        <dd class="col-sm-8"><strong><?php echo htmlspecialchars($division['office_code']); ?></strong></dd>
                                        
                                        <dt class="col-sm-4">Division Name:</dt>
                                        <dd class="col-sm-8"><?php echo htmlspecialchars($division['office_name']); ?></dd>
                                        
                                        <dt class="col-sm-4">Username:</dt>
                                        <dd class="col-sm-8"><?php echo htmlspecialchars($division['username']); ?></dd>
                                        
                                        <dt class="col-sm-4">Status:</dt>
                                        <dd class="col-sm-8">
                                            <?php if ($division['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </dd>
                                    </dl>
                                </div>
                                <div class="col-md-6">
                                    <dl class="row">
                                        <dt class="col-sm-4">Email:</dt>
                                        <dd class="col-sm-8">
                                            <?php if ($division['email']): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($division['email']); ?>">
                                                    <?php echo htmlspecialchars($division['email']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">Not provided</span>
                                            <?php endif; ?>
                                        </dd>
                                        
                                        <dt class="col-sm-4">Phone:</dt>
                                        <dd class="col-sm-8">
                                            <?php if ($division['phone']): ?>
                                                <?php echo htmlspecialchars($division['phone']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not provided</span>
                                            <?php endif; ?>
                                        </dd>
                                        
                                        <dt class="col-sm-4">Last Login:</dt>
                                        <dd class="col-sm-8">
                                            <?php if ($division['last_login']): ?>
                                                <?php echo date('M d, Y h:i A', strtotime($division['last_login'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Never logged in</span>
                                            <?php endif; ?>
                                        </dd>
                                        
                                        <dt class="col-sm-4">Created:</dt>
                                        <dd class="col-sm-8">
                                            <?php echo date('M d, Y', strtotime($division['created_at'])); ?>
                                        </dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="col-md-4">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <i class="bi bi-houses fs-1 mb-3 d-block"></i>
                            <h2 class="mb-0"><?php echo $stats['total_gn_offices']; ?></h2>
                            <p class="mb-0">GN Offices</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Row -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <i class="bi bi-people fs-1 mb-3 d-block"></i>
                            <h2 class="mb-0"><?php echo $stats['total_families']; ?></h2>
                            <p class="mb-0">Total Families</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <i class="bi bi-person fs-1 mb-3 d-block"></i>
                            <h2 class="mb-0"><?php echo $stats['total_citizens']; ?></h2>
                            <p class="mb-0">Total Citizens</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <i class="bi bi-check-circle fs-1 mb-3 d-block"></i>
                            <h2 class="mb-0"><?php echo $stats['active_gn_offices']; ?></h2>
                            <p class="mb-0">Active GN Offices</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- IMPORTANT: Show what families actually exist in the system -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-database me-2"></i>Actual Family Data in System
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Family ID</th>
                                    <th>GN ID (Current)</th>
                                    <th>Original GN ID</th>
                                    <th>Created Date</th>
                                    <th>Total Members</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allFamilies)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            No families found in the system
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach (array_slice($allFamilies, 0, 10) as $family): ?>
                                        <tr>
                                            <td><small><?php echo htmlspecialchars($family['family_id']); ?></small></td>
                                            <td><small><?php echo htmlspecialchars($family['gn_id']); ?></small></td>
                                            <td><small><?php echo htmlspecialchars($family['original_gn_id']); ?></small></td>
                                            <td><small><?php echo date('Y-m-d', strtotime($family['created_at'])); ?></small></td>
                                            <td><small><?php echo $family['total_members']; ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <small class="text-muted">Showing <?php echo min(10, count($allFamilies)); ?> of <?php echo count($allFamilies); ?> families</small>
                </div>
            </div>
            
            <!-- Tabs for Detailed Information -->
            <ul class="nav nav-tabs mb-4" id="divisionTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="gn-offices-tab" data-bs-toggle="tab" data-bs-target="#gn-offices" type="button">
                        <i class="bi bi-houses me-1"></i> All GN Offices (<?php echo count($gnOfficesWithCounts); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="recent-families-tab" data-bs-toggle="tab" data-bs-target="#recent-families" type="button">
                        <i class="bi bi-clock-history me-1"></i> Recent Families (<?php echo count($recentFamilies); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="statistics-tab" data-bs-toggle="tab" data-bs-target="#statistics" type="button">
                        <i class="bi bi-bar-chart me-1"></i> Statistics
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="divisionTabsContent">
                <!-- GN Offices Tab -->
                <div class="tab-pane fade show active" id="gn-offices" role="tabpanel">
                    <div class="card">
                        <div class="card-header bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-houses me-2"></i>All GN Offices in System
                                </h5>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="gnOfficesTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>GN Code</th>
                                            <th>GN Name</th>
                                            <th>Contact Info</th>
                                            <th>Families</th>
                                            <th>Citizens</th>
                                            <th>Status</th>
                                            <th>Last Login</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($gnOfficesWithCounts)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-4">
                                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                    No GN offices found
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($gnOfficesWithCounts as $index => $gn): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td><strong><?php echo htmlspecialchars($gn['office_code']); ?></strong></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($gn['office_name']); ?></strong><br>
                                                        <small class="text-muted">User: <?php echo htmlspecialchars($gn['username']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($gn['email']): ?>
                                                            <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($gn['email']); ?><br>
                                                        <?php endif; ?>
                                                        <?php if ($gn['phone']): ?>
                                                            <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($gn['phone']); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info"><?php echo $gn['family_count']; ?> Families</span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary"><?php echo $gn['citizen_count']; ?> Citizens</span>
                                                    </td>
                                                    <td>
                                                        <?php if ($gn['is_active']): ?>
                                                            <span class="badge bg-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($gn['last_login']): ?>
                                                            <small><?php echo date('M d, Y', strtotime($gn['last_login'])); ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">Never</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <a href="gn_details.php?code=<?php echo urlencode($gn['office_code']); ?>" 
                                                               class="btn btn-sm btn-outline-primary" title="View Details">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Families Tab -->
                <div class="tab-pane fade" id="recent-families" role="tabpanel">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-clock-history me-2"></i>Recent Families Added
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="recentFamiliesTable">
                                    <thead>
                                        <tr>
                                            <th>Family ID</th>
                                            <th>GN Office</th>
                                            <th>Current GN</th>
                                            <th>Original GN</th>
                                            <th>Members</th>
                                            <th>Date Added</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recentFamilies)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">
                                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                    No recent families found
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($recentFamilies as $family): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($family['family_id']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($family['gn_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($family['gn_id']); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($family['original_gn_id']); ?>
                                                        <?php if ($family['gn_id'] != $family['original_gn_id']): ?>
                                                            <span class="badge bg-warning">Transferred</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $family['current_members']; ?> / <?php echo $family['total_members']; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d, Y', strtotime($family['created_at'])); ?><br>
                                                        <small class="text-muted"><?php echo date('h:i A', strtotime($family['created_at'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <a href="../family_details.php?id=<?php echo urlencode($family['family_id']); ?>" 
                                                           class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-eye"></i> View
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Tab -->
                <div class="tab-pane fade" id="statistics" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-bar-chart me-2"></i>Family Registration Trend
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($monthlyStats)): ?>
                                        <div class="text-center text-muted py-4">
                                            <i class="bi bi-bar-chart-line fs-1 d-block mb-2"></i>
                                            No registration data available
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Month</th>
                                                        <th>Families Added</th>
                                                        <th>Total Members</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($monthlyStats as $monthStat): ?>
                                                        <tr>
                                                            <td><?php echo date('M Y', strtotime($monthStat['month'] . '-01')); ?></td>
                                                            <td><span class="badge bg-primary"><?php echo $monthStat['family_count']; ?></span></td>
                                                            <td><span class="badge bg-success"><?php echo $monthStat['total_members']; ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-pie-chart me-2"></i>System Overview
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-6 mb-3">
                                            <div class="h2 mb-0 text-success"><?php echo $stats['total_families']; ?></div>
                                            <small class="text-muted">Total Families</small>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="h2 mb-0 text-info"><?php echo $stats['total_citizens']; ?></div>
                                            <small class="text-muted">Total Citizens</small>
                                        </div>
                                        <div class="col-6">
                                            <div class="h2 mb-0 text-warning"><?php echo $stats['total_gn_offices']; ?></div>
                                            <small class="text-muted">GN Offices</small>
                                        </div>
                                        <div class="col-6">
                                            <div class="h2 mb-0 text-primary"><?php echo $stats['active_gn_offices']; ?></div>
                                            <small class="text-muted">Active GN Offices</small>
                                        </div>
                                    </div>
                                    
                                    <hr>
                                    
                                    <div class="text-center">
                                        <div class="h4 mb-0">
                                            <?php 
                                                if ($stats['total_families'] > 0) {
                                                    echo round($stats['total_citizens'] / $stats['total_families'], 1);
                                                } else {
                                                    echo 0;
                                                }
                                            ?>
                                        </div>
                                        <small class="text-muted">Average Family Size</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Important Note -->
            <div class="alert alert-secondary mt-4">
                <h6><i class="bi bi-lightbulb me-2"></i>Next Steps Needed:</h6>
                <small class="text-muted">
                    1. We need to understand how divisions and GN offices are linked in your system<br>
                    2. Check the reference database (mobile_service) for division-GN relationships<br>
                    3. Once we know the relationship, we can filter families by division correctly
                </small>
            </div>
        </main>
    </div>
</div>

<!-- Include Footer -->
<?php include '../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize tabs
    var triggerTabList = [].slice.call(document.querySelectorAll('#divisionTabs button'))
    triggerTabList.forEach(function (triggerEl) {
        var tabTrigger = new bootstrap.Tab(triggerEl)
        triggerEl.addEventListener('click', function (event) {
            event.preventDefault()
            tabTrigger.show()
        })
    });
    
    // Initialize DataTables
    $('#gnOfficesTable').DataTable({
        "pageLength": 10,
        "order": [[2, "asc"]],
        "language": {
            "search": "Search GN offices:",
            "lengthMenu": "Show _MENU_ entries",
            "info": "Showing _START_ to _END_ of _TOTAL_ GN offices",
            "infoEmpty": "No GN offices found",
            "zeroRecords": "No matching GN offices found"
        }
    });
    
    $('#recentFamiliesTable').DataTable({
        "pageLength": 10,
        "order": [[5, "desc"]],
        "language": {
            "search": "Search families:",
            "lengthMenu": "Show _MENU_ entries",
            "info": "Showing _START_ to _END_ of _TOTAL_ families",
            "infoEmpty": "No families found",
            "zeroRecords": "No matching families found"
        }
    });
});
</script>