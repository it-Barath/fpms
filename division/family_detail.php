<?php
// division/family_detail.php
// View detailed family information

session_start();
require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/CitizenManager.php';

// Check authentication
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole('division');

$currentUser = $auth->getCurrentUser();
$divisionName = $currentUser['office_code'];
$divisionDisplayName = $currentUser['office_name'];

// Get family ID
$familyId = $_GET['family_id'] ?? '';
if (empty($familyId)) {
    header('Location: view_families.php');
    exit();
}

// Initialize CitizenManager
$citizenManager = new CitizenManager();

// Get family details
$family = $citizenManager->getFamilyById($familyId);
if (!$family) {
    header('Location: view_families.php?error=family_not_found');
    exit();
}

// Verify family is under this division
$conn = getRefConnection();
$stmt = $conn->prepare("SELECT 1 FROM fix_work_station WHERE GN_ID = ? AND Division_Name = ?");
$stmt->bind_param("ss", $family['gn_id'], $divisionName);
$stmt->execute();

if ($stmt->get_result()->num_rows === 0) {
    header('Location: unauthorized.php');
    exit();
}

// Get family members
$members = $citizenManager->getFamilyMembers($familyId);

// Get GN information
$gnQuery = "SELECT GN FROM fix_work_station WHERE GN_ID = ?";
$gnStmt = $conn->prepare($gnQuery);
$gnStmt->bind_param("s", $family['gn_id']);
$gnStmt->execute();
$gnResult = $gnStmt->get_result();
$gnInfo = $gnResult->fetch_assoc();

// Get transfer history
$transferHistory = json_decode($family['transfer_history'] ?? '[]', true);

// Get land details
$landDetails = $citizenManager->getFamilyLandDetails($familyId);

$pageTitle = "Family Details - " . $familyId;
include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2">
                <i class="fas fa-home"></i>
                Family Details
            </h1>
            <p class="lead mb-0">Family ID: <strong><?php echo htmlspecialchars($familyId); ?></strong></p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="view_families.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="family_report.php?family_id=<?php echo urlencode($familyId); ?>" 
               class="btn btn-primary" target="_blank">
                <i class="fas fa-print"></i> Print Report
            </a>
        </div>
    </div>

    <!-- Family Information Card -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Family Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th>Family ID:</th>
                                    <td><strong><?php echo htmlspecialchars($family['family_id']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Current GN:</th>
                                    <td>
                                        <?php echo htmlspecialchars($gnInfo['GN'] ?? $family['gn_id']); ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($family['gn_id']); ?></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Original GN:</th>
                                    <td>
                                        <?php if ($family['original_gn_id'] != $family['gn_id']): ?>
                                            <?php echo htmlspecialchars($family['original_gn_id']); ?>
                                            <span class="badge bg-warning">Transferred</span>
                                        <?php else: ?>
                                            Same as current
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Total Members:</th>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo count($members); ?> members
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th>Head of Family NIC:</th>
                                    <td>
                                        <?php if ($family['family_head_nic']): ?>
                                            <code><?php echo htmlspecialchars($family['family_head_nic']); ?></code>
                                        <?php else: ?>
                                            <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Created Date:</th>
                                    <td><?php echo date('M d, Y', strtotime($family['created_at'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Last Updated:</th>
                                    <td><?php echo date('M d, Y', strtotime($family['updated_at'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Created By:</th>
                                    <td>
                                        <?php 
                                        if ($family['created_by']) {
                                            // Get creator username
                                            $creatorQuery = "SELECT username FROM users WHERE user_id = ?";
                                            $creatorStmt = $conn->prepare($creatorQuery);
                                            $creatorStmt->bind_param("i", $family['created_by']);
                                            $creatorStmt->execute();
                                            $creatorResult = $creatorStmt->get_result();
                                            $creator = $creatorResult->fetch_assoc();
                                            echo htmlspecialchars($creator['username'] ?? 'Unknown');
                                        } else {
                                            echo '<span class="text-muted">System</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Address -->
                    <div class="mt-3">
                        <h6><i class="fas fa-map-marker-alt"></i> Address</h6>
                        <div class="border rounded p-3 bg-light">
                            <?php if ($family['address']): ?>
                                <?php echo nl2br(htmlspecialchars($family['address'])); ?>
                            <?php else: ?>
                                <span class="text-muted">No address provided</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Quick Statistics</h5>
                </div>
                <div class="card-body">
                    <?php
                    $maleCount = 0;
                    $femaleCount = 0;
                    $childCount = 0;
                    $adultCount = 0;
                    
                    foreach ($members as $member) {
                        if ($member['gender'] == 'male') $maleCount++;
                        if ($member['gender'] == 'female') $femaleCount++;
                        
                        $age = calculateAge($member['date_of_birth']);
                        if ($age < 18) $childCount++;
                        else $adultCount++;
                    }
                    ?>
                    <div class="text-center mb-3">
                        <div class="display-4"><?php echo count($members); ?></div>
                        <small class="text-muted">Total Family Members</small>
                    </div>
                    
                    <table class="table table-sm">
                        <tr>
                            <td><i class="fas fa-male text-primary"></i> Male</td>
                            <td class="text-end"><?php echo $maleCount; ?></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-female text-danger"></i> Female</td>
                            <td class="text-end"><?php echo $femaleCount; ?></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-child text-success"></i> Children (<18)</td>
                            <td class="text-end"><?php echo $childCount; ?></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-user text-warning"></i> Adults (18+)</td>
                            <td class="text-end"><?php echo $adultCount; ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Family Members -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-users"></i> Family Members</h5>
        </div>
        <div class="card-body">
            <?php if (empty($members)): ?>
                <p class="text-muted">No members found in this family</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Full Name</th>
                                <th>NIC/ID</th>
                                <th>Gender</th>
                                <th>Date of Birth</th>
                                <th>Age</th>
                                <th>Relation</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $index => $member): ?>
                                <?php
                                $age = calculateAge($member['date_of_birth']);
                                $isHead = ($member['identification_number'] == $family['family_head_nic']);
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($member['full_name']); ?>
                                        <?php if ($isHead): ?>
                                            <span class="badge bg-primary">Head</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($member['identification_number']); ?></code>
                                    </td>
                                    <td>
                                        <?php if ($member['gender'] == 'male'): ?>
                                            <span class="badge bg-primary">Male</span>
                                        <?php elseif ($member['gender'] == 'female'): ?>
                                            <span class="badge bg-danger">Female</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Other</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($member['date_of_birth'])); ?></td>
                                    <td>
                                        <span class="badge <?php echo ($age < 18) ?