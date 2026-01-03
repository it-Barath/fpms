<?php
/**
 * District - View Family Members
 * Detailed view of all members in a family with their complete information
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
$pageTitle = "Family Members";

// Initialize Database
require_once '../classes/Database.php';
$db = new Database();

try {
    $conn = $db->getMainConnection();
    
    // Get family basic info
    $familyQuery = "
        SELECT 
            f.family_id,
            f.address,
            f.gn_id,
            gn.office_name as gn_name,
            division.office_name as division_name
        FROM families f
        LEFT JOIN users gn ON f.gn_id = gn.office_code AND gn.user_type = 'gn'
        LEFT JOIN users division ON gn.parent_division_code = division.office_code AND division.user_type = 'division'
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
    
    // Get all family members with comprehensive details
    $membersQuery = "
        SELECT 
            c.*,
            TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) as age,
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 5 THEN 'Infant'
                WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 18 THEN 'Minor'
                WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 60 THEN 'Adult'
                ELSE 'Senior'
            END as age_category
        FROM citizens c
        WHERE c.family_id = ?
        ORDER BY 
            CASE c.relation_to_head 
                WHEN 'self' THEN 1
                WHEN 'spouse' THEN 2
                WHEN 'child' THEN 3
                WHEN 'parent' THEN 4
                ELSE 5
            END,
            c.date_of_birth ASC
    ";
    
    $membersStmt = $conn->prepare($membersQuery);
    $membersStmt->bind_param("s", $familyId);
    $membersStmt->execute();
    $members = $membersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get employment info for each member
    $employmentData = [];
    $educationData = [];
    $healthData = [];
    
    foreach ($members as $member) {
        $citizenId = $member['citizen_id'];
        
        // Get employment
        $empQuery = "SELECT * FROM employment WHERE citizen_id = ? ORDER BY is_current_job DESC, start_date DESC";
        $empStmt = $conn->prepare($empQuery);
        $empStmt->bind_param("i", $citizenId);
        $empStmt->execute();
        $employmentData[$citizenId] = $empStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get education
        $eduQuery = "SELECT * FROM education WHERE citizen_id = ? ORDER BY is_current DESC, year_completed DESC";
        $eduStmt = $conn->prepare($eduQuery);
        $eduStmt->bind_param("i", $citizenId);
        $eduStmt->execute();
        $educationData[$citizenId] = $eduStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get health conditions
        $healthQuery = "SELECT * FROM health_conditions WHERE citizen_id = ?";
        $healthStmt = $conn->prepare($healthQuery);
        $healthStmt->bind_param("i", $citizenId);
        $healthStmt->execute();
        $healthData[$citizenId] = $healthStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    // Calculate statistics
    $stats = [
        'total_members' => count($members),
        'male' => 0,
        'female' => 0,
        'infants' => 0,
        'minors' => 0,
        'adults' => 0,
        'seniors' => 0,
        'employed' => 0,
        'students' => 0,
        'health_issues' => 0
    ];
    
    foreach ($members as $member) {
        // Gender count
        if ($member['gender'] === 'male') $stats['male']++;
        else if ($member['gender'] === 'female') $stats['female']++;
        
        // Age category count
        switch ($member['age_category']) {
            case 'Infant': $stats['infants']++; break;
            case 'Minor': $stats['minors']++; break;
            case 'Adult': $stats['adults']++; break;
            case 'Senior': $stats['seniors']++; break;
        }
        
        // Employment count
        $citizenId = $member['citizen_id'];
        if (!empty($employmentData[$citizenId])) {
            foreach ($employmentData[$citizenId] as $emp) {
                if ($emp['is_current_job'] && $emp['employment_type'] !== 'unemployed') {
                    $stats['employed']++;
                    break;
                }
            }
        }
        
        // Education count
        if (!empty($educationData[$citizenId])) {
            foreach ($educationData[$citizenId] as $edu) {
                if ($edu['is_current']) {
                    $stats['students']++;
                    break;
                }
            }
        }
        
        // Health issues count
        if (!empty($healthData[$citizenId])) {
            $stats['health_issues'] += count($healthData[$citizenId]);
        }
    }
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Education level labels
$educationLevels = [
    '1' => 'Grade 1', '2' => 'Grade 2', '3' => 'Grade 3', '4' => 'Grade 4', '5' => 'Grade 5',
    '6' => 'Grade 6', '7' => 'Grade 7', '8' => 'Grade 8', '9' => 'Grade 9', '10' => 'Grade 10',
    'ol' => 'O/L', 'al' => 'A/L', 'diploma' => 'Diploma', 'degree' => 'Degree',
    'masters' => 'Masters', 'mphil' => 'MPhil', 'phd' => 'PhD'
];

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
                <div>
                    <h1 class="h2">
                        <i class="bi bi-people-fill me-2"></i>Family Members
                    </h1>
                    <p class="text-muted mb-0">
                        <small>
                            Family ID: <strong><?php echo htmlspecialchars($familyId); ?></strong> | 
                            <?php echo htmlspecialchars($family['division_name']); ?> - 
                            <?php echo htmlspecialchars($family['gn_name']); ?>
                        </small>
                    </p>
                </div>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="family_details.php?id=<?php echo urlencode($familyId); ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Back to Family
                        </a>
                        <a href="view_families.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-list me-1"></i> All Families
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-2 mb-3">
                    <div class="card border-primary text-center">
                        <div class="card-body py-3">
                            <i class="bi bi-people fs-3 text-primary"></i>
                            <h4 class="mt-2 mb-0"><?php echo $stats['total_members']; ?></h4>
                            <small class="text-muted">Total</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card border-info text-center">
                        <div class="card-body py-3">
                            <i class="bi bi-gender-male fs-3 text-info"></i>
                            <h4 class="mt-2 mb-0"><?php echo $stats['male']; ?></h4>
                            <small class="text-muted">Male</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card border-danger text-center">
                        <div class="card-body py-3">
                            <i class="bi bi-gender-female fs-3 text-danger"></i>
                            <h4 class="mt-2 mb-0"><?php echo $stats['female']; ?></h4>
                            <small class="text-muted">Female</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card border-success text-center">
                        <div class="card-body py-3">
                            <i class="bi bi-briefcase fs-3 text-success"></i>
                            <h4 class="mt-2 mb-0"><?php echo $stats['employed']; ?></h4>
                            <small class="text-muted">Employed</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card border-warning text-center">
                        <div class="card-body py-3">
                            <i class="bi bi-book fs-3 text-warning"></i>
                            <h4 class="mt-2 mb-0"><?php echo $stats['students']; ?></h4>
                            <small class="text-muted">Students</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card border-secondary text-center">
                        <div class="card-body py-3">
                            <i class="bi bi-heart-pulse fs-3 text-secondary"></i>
                            <h4 class="mt-2 mb-0"><?php echo $stats['health_issues']; ?></h4>
                            <small class="text-muted">Health</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Age Distribution -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-bar-chart me-2"></i>Age Distribution
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <span class="badge bg-info rounded-circle p-2">
                                        <i class="bi bi-baby"></i>
                                    </span>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <strong>Infants (0-4):</strong> <?php echo $stats['infants']; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-2">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <span class="badge bg-warning rounded-circle p-2">
                                        <i class="bi bi-person"></i>
                                    </span>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <strong>Minors (5-17):</strong> <?php echo $stats['minors']; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-2">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <span class="badge bg-success rounded-circle p-2">
                                        <i class="bi bi-person-check"></i>
                                    </span>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <strong>Adults (18-59):</strong> <?php echo $stats['adults']; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-2">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <span class="badge bg-secondary rounded-circle p-2">
                                        <i class="bi bi-person-walking"></i>
                                    </span>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <strong>Seniors (60+):</strong> <?php echo $stats['seniors']; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Members List -->
            <?php if (empty($members)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    No members registered for this family yet.
                </div>
            <?php else: ?>
                <?php foreach ($members as $index => $member): ?>
                    <div class="card mb-4">
                        <div class="card-header <?php echo $member['relation_to_head'] === 'self' ? 'bg-primary text-white' : 'bg-light'; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-person-badge me-2"></i>
                                    <?php echo htmlspecialchars($member['full_name']); ?>
                                    <?php if ($member['relation_to_head'] === 'self'): ?>
                                        <span class="badge bg-success">Head of Family</span>
                                    <?php endif; ?>
                                    <?php if (!$member['is_alive']): ?>
                                        <span class="badge bg-dark">Deceased</span>
                                    <?php endif; ?>
                                </h5>
                                <span class="badge <?php echo $member['age_category'] === 'Senior' ? 'bg-secondary' : 
                                    ($member['age_category'] === 'Adult' ? 'bg-success' : 
                                    ($member['age_category'] === 'Minor' ? 'bg-warning' : 'bg-info')); ?>">
                                    <?php echo $member['age_category']; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Personal Information Column -->
                                <div class="col-md-4">
                                    <h6 class="text-primary mb-3">
                                        <i class="bi bi-person me-1"></i>Personal Information
                                    </h6>
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="45%">ID Type:</th>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo strtoupper($member['identification_type']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>ID Number:</th>
                                            <td><code><?php echo htmlspecialchars($member['identification_number']); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th>Name with Initials:</th>
                                            <td><?php echo htmlspecialchars($member['name_with_initials'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Date of Birth:</th>
                                            <td>
                                                <?php echo date('d/m/Y', strtotime($member['date_of_birth'])); ?>
                                                <small class="text-muted">(<?php echo $member['age']; ?> years)</small>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Gender:</th>
                                            <td>
                                                <i class="bi bi-gender-<?php echo $member['gender'] === 'male' ? 'male' : 'female'; ?>"></i>
                                                <?php echo ucfirst($member['gender']); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Ethnicity:</th>
                                            <td><?php echo htmlspecialchars($member['ethnicity'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Religion:</th>
                                            <td><?php echo htmlspecialchars($member['religion'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Marital Status:</th>
                                            <td>
                                                <?php if ($member['marital_status']): ?>
                                                    <span class="badge bg-info">
                                                        <?php echo ucfirst($member['marital_status']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Relationship:</th>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?php echo ucfirst(str_replace('_', ' ', $member['relation_to_head'])); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <!-- Contact & Address Column -->
                                <div class="col-md-4">
                                    <h6 class="text-success mb-3">
                                        <i class="bi bi-telephone me-1"></i>Contact Information
                                    </h6>
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="35%">Mobile:</th>
                                            <td>
                                                <?php if ($member['mobile_phone']): ?>
                                                    <i class="bi bi-phone"></i> <?php echo htmlspecialchars($member['mobile_phone']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not provided</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Home Phone:</th>
                                            <td>
                                                <?php if ($member['home_phone']): ?>
                                                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($member['home_phone']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not provided</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Email:</th>
                                            <td>
                                                <?php if ($member['email']): ?>
                                                    <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($member['email']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not provided</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Address:</th>
                                            <td>
                                                <?php if ($member['address']): ?>
                                                    <?php echo nl2br(htmlspecialchars($member['address'])); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Same as family</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <!-- Employment Info -->
                                    <?php if (!empty($employmentData[$member['citizen_id']])): ?>
                                        <h6 class="text-warning mb-3 mt-3">
                                            <i class="bi bi-briefcase me-1"></i>Employment
                                        </h6>
                                        <?php foreach ($employmentData[$member['citizen_id']] as $emp): ?>
                                            <?php if ($emp['is_current_job']): ?>
                                                <div class="alert alert-warning py-2 px-3 mb-2">
                                                    <strong><?php echo ucfirst(str_replace('_', ' ', $emp['employment_type'])); ?></strong>
                                                    <?php if ($emp['designation']): ?>
                                                        <br><small><?php echo htmlspecialchars($emp['designation']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($emp['employer_name']): ?>
                                                        <br><small><i class="bi bi-building"></i> <?php echo htmlspecialchars($emp['employer_name']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($emp['monthly_income']): ?>
                                                        <br><small><strong>Income:</strong> Rs. <?php echo number_format($emp['monthly_income'], 2); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Education & Health Column -->
                                <div class="col-md-4">
                                    <!-- Education Info -->
                                    <?php if (!empty($educationData[$member['citizen_id']])): ?>
                                        <h6 class="text-info mb-3">
                                            <i class="bi bi-mortarboard me-1"></i>Education
                                        </h6>
                                        <?php foreach ($educationData[$member['citizen_id']] as $edu): ?>
                                            <div class="alert alert-info py-2 px-3 mb-2">
                                                <strong><?php echo $educationLevels[$edu['education_level']] ?? $edu['education_level']; ?></strong>
                                                <?php if ($edu['is_current']): ?>
                                                    <span class="badge bg-success">Current</span>
                                                <?php endif; ?>
                                                <?php if ($edu['school_name']): ?>
                                                    <br><small><i class="bi bi-building"></i> <?php echo htmlspecialchars($edu['school_name']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($edu['year_completed']): ?>
                                                    <br><small><i class="bi bi-calendar"></i> <?php echo $edu['year_completed']; ?></small>
                                                <?php endif; ?>
                                                <?php if ($edu['stream']): ?>
                                                    <br><small><i class="bi bi-book"></i> <?php echo htmlspecialchars($edu['stream']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <!-- Health Conditions -->
                                    <?php if (!empty($healthData[$member['citizen_id']])): ?>
                                        <h6 class="text-danger mb-3 mt-3">
                                            <i class="bi bi-heart-pulse me-1"></i>Health Conditions
                                        </h6>
                                        <?php foreach ($healthData[$member['citizen_id']] as $health): ?>
                                            <div class="alert alert-danger py-2 px-3 mb-2">
                                                <strong><?php echo htmlspecialchars($health['condition_name']); ?></strong>
                                                <span class="badge bg-<?php 
                                                    echo $health['severity'] === 'severe' ? 'danger' : 
                                                        ($health['severity'] === 'moderate' ? 'warning' : 'info'); 
                                                ?>">
                                                    <?php echo ucfirst($health['severity']); ?>
                                                </span>
                                                <?php if ($health['is_permanent']): ?>
                                                    <span class="badge bg-dark">Permanent</span>
                                                <?php endif; ?>
                                                <br><small><?php echo ucfirst(str_replace('_', ' ', $health['condition_type'])); ?></small>
                                                <?php if ($health['diagnosis_date']): ?>
                                                    <br><small><i class="bi bi-calendar"></i> Diagnosed: <?php echo date('d/m/Y', strtotime($health['diagnosis_date'])); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <!-- Registration Info -->
                                    <div class="mt-3">
                                        <h6 class="text-muted mb-2">
                                            <i class="bi bi-clock-history me-1"></i>Record Info
                                        </h6>
                                        <small class="text-muted">
                                            <strong>Registered:</strong> <?php echo date('d/m/Y h:i A', strtotime($member['created_at'])); ?>
                                            <br>
                                            <strong>Last Updated:</strong> <?php echo date('d/m/Y h:i A', strtotime($member['updated_at'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
    .card { page-break-inside: avoid; }
}
</style>