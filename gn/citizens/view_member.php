<?php
// gn/citizens/view_member.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "View Family Member";
$pageIcon = "bi bi-person";
$pageDescription = "View detailed information of a family member";
$bodyClass = "bg-light";

try {
    require_once '../../config.php';
    require_once '../../classes/Auth.php';
    require_once '../../classes/Sanitizer.php';
    
    // Check if session is already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in and has GN level access
    if (!$auth->isLoggedIn()) {
        header('Location: ../../login.php');
        exit();
    }
    
    // Check if user has GN level access
    if ($_SESSION['user_type'] !== 'gn') {
        header('Location: ../dashboard.php');
        exit();
    }

    // Get database connection
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $gn_id = $_SESSION['office_code'] ?? '';
    $username = $_SESSION['username'] ?? '';
    
    // Sanitize inputs
    $sanitizer = new Sanitizer();
    $family_id = isset($_GET['family_id']) ? $sanitizer->sanitizeFamilyId($_GET['family_id']) : '';
    $member_id = isset($_GET['member_id']) ? $sanitizer->sanitizeNumber($_GET['member_id'], 1) : 0;
    
    if (empty($family_id) || $member_id <= 0) {
        header('Location: list_families.php?error=Invalid member parameters');
        exit();
    }
    
    // Fetch member details with family verification
    $member_sql = "SELECT 
        c.*,
        f.family_id,
        f.gn_id,
        f.address as family_address,
        f.total_members,
        f.created_at as family_registered_date,
        fh.full_name as head_name,
        fh.identification_number as head_nic,
        TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) as age
    FROM citizens c
    INNER JOIN families f ON c.family_id = f.family_id
    LEFT JOIN citizens fh ON f.family_id = fh.family_id AND fh.relation_to_head = 'Self'
    WHERE c.citizen_id = ? AND c.family_id = ? AND f.gn_id = ?";
    
    $stmt = $db->prepare($member_sql);
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $db->error);
    }
    
    $stmt->bind_param("iss", $member_id, $family_id, $gn_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    
    if (!$member) {
        header('Location: list_families.php?error=Member not found or access denied');
        exit();
    }
    
    // Fetch education details
    $education_sql = "SELECT * FROM education WHERE citizen_id = ? ORDER BY 
        CASE education_level
            WHEN 'phd' THEN 1 WHEN 'mphil' THEN 2 WHEN 'degree' THEN 3 
            WHEN 'diploma' THEN 4 WHEN 'al' THEN 5 WHEN 'ol' THEN 6
            ELSE CAST(education_level AS UNSIGNED) + 10
        END DESC";
    $edu_stmt = $db->prepare($education_sql);
    $edu_stmt->bind_param("i", $member_id);
    $edu_stmt->execute();
    $education = $edu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Fetch employment details
    $employment_sql = "SELECT * FROM employment WHERE citizen_id = ? ORDER BY is_current_job DESC, start_date DESC";
    $emp_stmt = $db->prepare($employment_sql);
    $emp_stmt->bind_param("i", $member_id);
    $emp_stmt->execute();
    $employment = $emp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Fetch health conditions
    $health_sql = "SELECT * FROM health_conditions WHERE citizen_id = ? ORDER BY is_permanent DESC, diagnosis_date DESC";
    $health_stmt = $db->prepare($health_sql);
    $health_stmt->bind_param("i", $member_id);
    $health_stmt->execute();
    $health_conditions = $health_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Fetch other family members (excluding current member)
    $family_members_sql = "SELECT 
        citizen_id,
        full_name,
        name_with_initials,
        gender,
        date_of_birth,
        relation_to_head,
        identification_number,
        TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) as age
    FROM citizens 
    WHERE family_id = ? AND citizen_id != ?
    ORDER BY 
        CASE WHEN relation_to_head = 'Self' THEN 1 
             WHEN relation_to_head IN ('Husband', 'Wife') THEN 2
             WHEN relation_to_head IN ('Son', 'Daughter') THEN 3
             WHEN relation_to_head IN ('Father', 'Mother') THEN 4
             ELSE 5 
        END,
        date_of_birth";
    $family_stmt = $db->prepare($family_members_sql);
    $family_stmt->bind_param("si", $family_id, $member_id);
    $family_stmt->execute();
    $family_members = $family_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("View Member Error: " . $e->getMessage());
    // Don't show full error to user, redirect to safe page
    header('Location: list_families.php?error=System error occurred');
    exit();
}
?>

<?php require_once '../../includes/header.php'; ?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../../includes/sidebar.php'; ?>
        </div>
        
        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-xl-10 px-md-4 main-content">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mt-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="list_families.php">Families</a></li>
                    <li class="breadcrumb-item"><a href="view_family.php?id=<?php echo urlencode($family_id); ?>">Family Details</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Member Details</li>
                </ol>
            </nav>
            
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-person me-2"></i>
                    Family Member Details
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="edit_member.php?family_id=<?php echo urlencode($family_id); ?>&member_id=<?php echo $member_id; ?>" 
                           class="btn btn-warning">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" 
                           class="btn btn-secondary">
                            <i class="bi bi-house-door"></i> View Family
                        </a>
                    </div>
                    <a href="list_families.php" class="btn btn-outline-secondary">
                        <i class="bi bi-list"></i> All Families
                    </a>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($_GET['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Main Member Card -->
            <div class="row">
                <!-- Personal Information Column -->
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i> Personal Information</h5>
                            <span class="badge bg-light text-dark">
                                <?php echo $member['relation_to_head'] === 'Self' ? 'Family Head' : 'Member'; ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <th width="40%">Full Name:</th>
                                            <td>
                                                <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                                <?php if ($member['relation_to_head'] === 'Self'): ?>
                                                    <span class="badge bg-warning ms-2">Head</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Name with Initials:</th>
                                            <td><?php echo htmlspecialchars($member['name_with_initials'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Gender:</th>
                                            <td>
                                                <?php 
                                                $gender_icon = '';
                                                if ($member['gender'] === 'male') $gender_icon = '<i class="bi bi-gender-male text-primary"></i> Male';
                                                elseif ($member['gender'] === 'female') $gender_icon = '<i class="bi bi-gender-female text-danger"></i> Female';
                                                else $gender_icon = '<i class="bi bi-gender-ambiguous text-secondary"></i> Other';
                                                echo $gender_icon;
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Date of Birth:</th>
                                            <td>
                                                <i class="bi bi-calendar"></i> 
                                                <?php echo date('F j, Y', strtotime($member['date_of_birth'])); ?>
                                                (<?php echo $member['age']; ?> years)
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Age Group:</th>
                                            <td>
                                                <?php
                                                $age = $member['age'];
                                                $age_group = '';
                                                if ($age < 1) $age_group = '<span class="badge bg-info">Infant</span>';
                                                elseif ($age <= 5) $age_group = '<span class="badge bg-info">Toddler</span>';
                                                elseif ($age <= 12) $age_group = '<span class="badge bg-success">Child</span>';
                                                elseif ($age <= 19) $age_group = '<span class="badge bg-primary">Teenager</span>';
                                                elseif ($age <= 35) $age_group = '<span class="badge bg-secondary">Young Adult</span>';
                                                elseif ($age <= 60) $age_group = '<span class="badge bg-warning">Adult</span>';
                                                else $age_group = '<span class="badge bg-danger">Senior</span>';
                                                echo $age_group;
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <th width="40%">Identification:</th>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo strtoupper($member['identification_type']); ?></span>
                                                <span class="font-monospace"><?php echo htmlspecialchars($member['identification_number']); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Marital Status:</th>
                                            <td>
                                                <?php 
                                                $status_badge = '';
                                                switch($member['marital_status']) {
                                                    case 'single': $status_badge = 'bg-secondary'; break;
                                                    case 'married': $status_badge = 'bg-success'; break;
                                                    case 'divorced': $status_badge = 'bg-warning'; break;
                                                    case 'widowed': $status_badge = 'bg-danger'; break;
                                                    default: $status_badge = 'bg-light text-dark';
                                                }
                                                ?>
                                                <span class="badge <?php echo $status_badge; ?>">
                                                    <?php echo ucfirst($member['marital_status'] ?? 'Not specified'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Relation to Head:</th>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo htmlspecialchars($member['relation_to_head']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Ethnicity:</th>
                                            <td><?php echo htmlspecialchars($member['ethnicity'] ?? 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Religion:</th>
                                            <td><?php echo htmlspecialchars($member['religion'] ?? 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Status:</th>
                                            <td>
                                                <?php if ($member['is_alive'] == 1): ?>
                                                    <span class="badge bg-success"><i class="bi bi-heart"></i> Alive</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger"><i class="bi bi-heartbreak"></i> Deceased</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Contact Information -->
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6 class="border-bottom pb-2"><i class="bi bi-telephone me-2"></i> Contact Information</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <th width="30%">Mobile Phone:</th>
                                                    <td>
                                                        <?php if (!empty($member['mobile_phone'])): ?>
                                                            <i class="bi bi-phone"></i> 
                                                            <a href="tel:<?php echo htmlspecialchars($member['mobile_phone']); ?>">
                                                                <?php echo htmlspecialchars($member['mobile_phone']); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted">Not provided</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th>Home Phone:</th>
                                                    <td>
                                                        <?php if (!empty($member['home_phone'])): ?>
                                                            <i class="bi bi-telephone"></i> 
                                                            <a href="tel:<?php echo htmlspecialchars($member['home_phone']); ?>">
                                                                <?php echo htmlspecialchars($member['home_phone']); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted">Not provided</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <th width="30%">Email:</th>
                                                    <td>
                                                        <?php if (!empty($member['email'])): ?>
                                                            <i class="bi bi-envelope"></i> 
                                                            <a href="mailto:<?php echo htmlspecialchars($member['email']); ?>">
                                                                <?php echo htmlspecialchars($member['email']); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted">Not provided</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th>Address:</th>
                                                    <td>
                                                        <?php if (!empty($member['address'])): ?>
                                                            <i class="bi bi-geo-alt"></i> 
                                                            <?php echo nl2br(htmlspecialchars($member['address'])); ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">Same as family address</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Education Details -->
                    <?php if (!empty($education)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i> Education Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Level</th>
                                            <th>School/Institution</th>
                                            <th>Year Completed</th>
                                            <th>Stream/Results</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($education as $edu): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                $level_names = [
                                                    '1' => 'Grade 1', '2' => 'Grade 2', '3' => 'Grade 3',
                                                    '4' => 'Grade 4', '5' => 'Grade 5', '6' => 'Grade 6',
                                                    '7' => 'Grade 7', '8' => 'Grade 8', '9' => 'Grade 9',
                                                    '10' => 'Grade 10', 'ol' => 'O/L', 'al' => 'A/L',
                                                    'diploma' => 'Diploma', 'degree' => 'Degree',
                                                    'masters' => "Master's", 'mphil' => 'MPhil', 'phd' => 'PhD'
                                                ];
                                                echo $level_names[$edu['education_level']] ?? ucfirst($edu['education_level']);
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($edu['school_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo $edu['year_completed'] ?? 'Ongoing'; ?></td>
                                            <td>
                                                <?php if (!empty($edu['stream'])): ?>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($edu['stream']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($edu['results'])): ?>
                                                    <div class="small"><?php echo htmlspecialchars($edu['results']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($edu['is_current'] == 1): ?>
                                                    <span class="badge bg-success">Current</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Completed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Employment Details -->
                    <?php if (!empty($employment)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="bi bi-briefcase me-2"></i> Employment Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Designation</th>
                                            <th>Employer</th>
                                            <th>Income</th>
                                            <th>Period</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employment as $emp): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                $emp_types = [
                                                    'government' => 'Government', 'private' => 'Private',
                                                    'self' => 'Self-employed', 'labor' => 'Labor',
                                                    'unemployed' => 'Unemployed', 'student' => 'Student',
                                                    'retired' => 'Retired'
                                                ];
                                                echo $emp_types[$emp['employment_type']] ?? ucfirst($emp['employment_type']);
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($emp['designation'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($emp['employer_name'] ?? 'N/A'); ?>
                                                <?php if (!empty($emp['employment_sector'])): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($emp['employment_sector']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($emp['monthly_income'])): ?>
                                                    LKR <?php echo number_format($emp['monthly_income'], 2); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not specified</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (!empty($emp['start_date'])) {
                                                    echo date('M Y', strtotime($emp['start_date']));
                                                    if (!empty($emp['end_date'])) {
                                                        echo ' - ' . date('M Y', strtotime($emp['end_date']));
                                                    } else {
                                                        echo ' - Present';
                                                    }
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($emp['is_current_job'] == 1): ?>
                                                    <span class="badge bg-success">Current</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Past</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Right Side Column -->
                <div class="col-lg-4">
                    <!-- Family Information -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-house-door me-2"></i> Family Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="display-6 text-success">
                                    <i class="bi bi-people-fill"></i>
                                </div>
                                <h4><?php echo $member['total_members']; ?> Family Members</h4>
                            </div>
                            
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th>Family ID:</th>
                                    <td class="font-monospace"><?php echo htmlspecialchars($family_id); ?></td>
                                </tr>
                                <tr>
                                    <th>Family Head:</th>
                                    <td>
                                        <?php echo htmlspecialchars($member['head_name']); ?>
                                        <?php if ($member['relation_to_head'] === 'Self'): ?>
                                            <span class="badge bg-warning ms-1">(This Member)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Head NIC:</th>
                                    <td><?php echo htmlspecialchars($member['head_nic'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>GN Division:</th>
                                    <td><?php echo htmlspecialchars($member['gn_id']); ?></td>
                                </tr>
                                <tr>
                                    <th>Registered:</th>
                                    <td><?php echo date('d M Y', strtotime($member['family_registered_date'])); ?></td>
                                </tr>
                            </table>
                            
                            <div class="mt-3">
                                <strong>Family Address:</strong>
                                <p class="small mb-0"><?php echo nl2br(htmlspecialchars($member['family_address'])); ?></p>
                            </div>
                            
                            <div class="mt-3">
                                <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" 
                                   class="btn btn-outline-success btn-sm w-100">
                                    <i class="bi bi-eye"></i> View Full Family Details
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Health Conditions -->
                    <?php if (!empty($health_conditions)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="bi bi-heart-pulse me-2"></i> Health Conditions</h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($health_conditions as $health): ?>
                            <div class="border-start border-3 border-danger ps-3 mb-3">
                                <h6><?php echo htmlspecialchars($health['condition_name']); ?></h6>
                                <div class="small">
                                    <span class="badge bg-<?php echo $health['severity'] === 'severe' ? 'danger' : ($health['severity'] === 'moderate' ? 'warning' : 'info'); ?>">
                                        <?php echo ucfirst($health['severity']); ?>
                                    </span>
                                    <?php if (!empty($health['diagnosis_date'])): ?>
                                        <span class="text-muted">Diagnosed: <?php echo date('M Y', strtotime($health['diagnosis_date'])); ?></span>
                                    <?php endif; ?>
                                    <?php if ($health['is_permanent'] == 1): ?>
                                        <span class="badge bg-dark">Permanent</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($health['treatment_details'])): ?>
                                    <p class="small mb-0 mt-1"><?php echo nl2br(htmlspecialchars($health['treatment_details'])); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Other Family Members -->
                    <?php if (!empty($family_members)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="bi bi-people me-2"></i> Other Family Members (<?php echo count($family_members); ?>)</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($family_members as $other_member): ?>
                                <a href="view_member.php?family_id=<?php echo urlencode($family_id); ?>&member_id=<?php echo $other_member['citizen_id']; ?>" 
                                   class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($other_member['full_name']); ?></h6>
                                        <small class="text-muted"><?php echo $other_member['age']; ?>y</small>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <span class="badge bg-info"><?php echo $other_member['relation_to_head']; ?></span>
                                            <?php if (!empty($other_member['identification_number'])): ?>
                                                • <?php echo htmlspecialchars($other_member['identification_number']); ?>
                                            <?php endif; ?>
                                        </small>
                                        <span class="badge bg-light text-dark">
                                            <?php echo ucfirst($other_member['gender']); ?>
                                        </span>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="card-footer text-center">
                            <a href="add_member.php?family_id=<?php echo urlencode($family_id); ?>" 
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-person-plus"></i> Add New Member
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Quick Actions -->
                    <div class="card mb-4">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="bi bi-lightning me-2"></i> Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="edit_member.php?family_id=<?php echo urlencode($family_id); ?>&member_id=<?php echo $member_id; ?>" 
                                   class="btn btn-warning">
                                    <i class="bi bi-pencil"></i> Edit Member Details
                                </a>
                                <a href="add_member.php?family_id=<?php echo urlencode($family_id); ?>" 
                                   class="btn btn-primary">
                                    <i class="bi bi-person-plus"></i> Add Family Member
                                </a>
                                <?php if ($member['relation_to_head'] !== 'Self'): ?>
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                    <i class="bi bi-trash"></i> Remove from Family
                                </button>
                                <?php endif; ?>
                                <a href="search_member.php?family_id=<?php echo urlencode($family_id); ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="bi bi-search"></i> Search in Family
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Member Statistics -->
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i> Member Statistics</h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-2">
                                        <div class="text-muted small">Education Levels</div>
                                        <div class="h4"><?php echo count($education); ?></div>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-2">
                                        <div class="text-muted small">Jobs</div>
                                        <div class="h4"><?php echo count($employment); ?></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-2">
                                        <div class="text-muted small">Health Conditions</div>
                                        <div class="h4"><?php echo count($health_conditions); ?></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-2">
                                        <div class="text-muted small">Family Position</div>
                                        <div class="h6"><?php echo ucfirst($member['relation_to_head']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- System Information -->
            <div class="card mt-4">
                <div class="card-header bg-light text-dark">
                    <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i> System Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted">Member ID:</small><br>
                            <span class="font-monospace"><?php echo $member_id; ?></span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">Last Updated:</small><br>
                            <?php echo date('Y-m-d H:i', strtotime($member['updated_at'])); ?>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">Registered:</small><br>
                            <?php echo date('Y-m-d H:i', strtotime($member['created_at'])); ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<?php if ($member['relation_to_head'] !== 'Self'): ?>
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel"><i class="bi bi-exclamation-triangle me-2"></i> Confirm Removal</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove <strong><?php echo htmlspecialchars($member['full_name']); ?></strong> from family <?php echo htmlspecialchars($family_id); ?>?</p>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Warning:</strong> This action cannot be undone. The member will be permanently removed from this family.
                </div>
                <p class="mb-0">This member is related as: <span class="badge bg-info"><?php echo $member['relation_to_head']; ?></span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="delete_member.php" style="display: inline;">
                    <input type="hidden" name="family_id" value="<?php echo htmlspecialchars($family_id); ?>">
                    <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $sanitizer->generateCsrfToken(); ?>">
                    <button type="submit" class="btn btn-danger">Remove Member</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .table-borderless th {
        font-weight: 600;
        color: #495057;
    }
    .table-borderless td {
        color: #212529;
    }
    .font-monospace {
        font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, monospace;
        font-size: 0.9em;
    }
    .list-group-item:hover {
        background-color: rgba(0, 123, 255, 0.1);
    }
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
    .border-start {
        border-left-width: 4px !important;
    }
</style>

<script src="../../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Print functionality
        document.getElementById('printBtn')?.addEventListener('click', function() {
            window.print();
        });
        
        // Confirmation for delete
        const deleteModal = document.getElementById('deleteModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function(event) {
                // Optional: Additional confirmation logic
            });
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
</script>    

<?php 
// Include footer if exists
$footer_path = '../../includes/footer.php';
if (file_exists($footer_path)) {  
    include $footer_path;  
} else {
    echo '</div></div></body></html>';
}
?>