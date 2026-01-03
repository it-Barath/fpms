<?php
// division/statistics.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Division Statistics";
$pageIcon = "bi bi-bar-chart-line";
$pageDescription = "Detailed statistical analysis for your division";
$bodyClass = "bg-light";

// Safe number formatting functions
function safe_number_format($value, $decimals = 0) {
    if (!is_numeric($value)) {
        return number_format(0, $decimals);
    }
    return number_format((float)$value, $decimals);
}

function safe_percentage_format($value, $decimals = 1) {
    if (!is_numeric($value)) {
        return number_format(0, $decimals) . '%';
    }
    return number_format((float)$value, $decimals) . '%';
}

try {
    require_once '../config.php';
    require_once '../classes/Auth.php';
    require_once '../classes/Sanitizer.php';
    
    // Check if session is already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in and has Division level access
    if (!$auth->isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }
    
    // Check if user has Division level access
    if ($_SESSION['user_type'] !== 'division') {
        header('Location: dashboard.php');
        exit();
    }

    // Get database connection
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $division_name = $_SESSION['office_name'] ?? '';
    $username = $_SESSION['username'] ?? '';
    
    // Initialize sanitizer
    $sanitizer = new Sanitizer();
    
    // Get filter parameters
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $gn_filter = $_GET['gn'] ?? 'all';
    
    // Validate parameters
    $year = $sanitizer->sanitizeNumber($year, 2020, 2030);
    $month = $sanitizer->sanitizeNumber($month, 1, 12);
    
    // Get all GN divisions
    $gn_divisions = getGNDivisions($db);
    
    // Get comprehensive statistics
    $total_stats = getTotalStatistics($db);
    $gender_stats = getGenderStatistics($db, $gn_filter);
    $age_stats = getAgeStatistics($db, $gn_filter);
    $family_stats = getFamilyStatistics($db, $gn_filter);
    $education_stats = getEducationStatistics($db, $gn_filter);
    $employment_stats = getEmploymentStatistics($db, $gn_filter);
    $ethnicity_stats = getEthnicityStatistics($db, $gn_filter);
    $religion_stats = getReligionStatistics($db, $gn_filter);
    
    // Get monthly trends
    $monthly_trends = getMonthlyTrends($db, $year);
    
    // Get top GN divisions
    $top_gn_divisions = getTopGNDivisions($db, 10);

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Division Statistics Error: " . $e->getMessage());
}

// Helper functions
function getGNDivisions($db) {
    $gn_divisions = [];
    
    $sql = "SELECT 
                office_code,
                office_name,
                username,
                email,
                phone
            FROM users 
            WHERE user_type = 'gn' 
            AND is_active = 1
            ORDER BY office_name";
    
    $result = $db->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $gn_code = $row['office_code'];
        
        // Get family count
        $family_sql = "SELECT COUNT(*) as families FROM families WHERE gn_id = ?";
        $stmt = $db->prepare($family_sql);
        $stmt->bind_param("s", $gn_code);
        $stmt->execute();
        $family_result = $stmt->get_result();
        $family_row = $family_result->fetch_assoc();
        
        // Get population count
        $pop_sql = "SELECT COUNT(*) as population FROM citizens c 
                   INNER JOIN families f ON c.family_id = f.family_id 
                   WHERE f.gn_id = ?";
        $stmt = $db->prepare($pop_sql);
        $stmt->bind_param("s", $gn_code);
        $stmt->execute();
        $pop_result = $stmt->get_result();
        $pop_row = $pop_result->fetch_assoc();
        
        // Calculate average family size
        $avg_size = ($family_row['families'] ?? 0) > 0 ? 
                   ($pop_row['population'] ?? 0) / ($family_row['families'] ?? 1) : 0;
        
        $gn_divisions[] = [
            'gn_id' => $gn_code,
            'office_code' => $gn_code,
            'office_name' => $row['office_name'],
            'officer_name' => $row['username'],
            'families' => $family_row['families'] ?? 0,
            'population' => $pop_row['population'] ?? 0,
            'avg_family_size' => round($avg_size, 1)
        ];
    }
    
    return $gn_divisions;
}

function getTotalStatistics($db) {
    $stats = [];
    
    // Total families
    $sql = "SELECT COUNT(*) as total FROM families";
    $result = $db->query($sql);
    $row = $result->fetch_assoc();
    $stats['total_families'] = $row['total'] ?? 0;
    
    // Total population
    $sql = "SELECT COUNT(*) as total FROM citizens";
    $result = $db->query($sql);
    $row = $result->fetch_assoc();
    $stats['total_population'] = $row['total'] ?? 0;
    
    // Average family size
    $stats['avg_family_size'] = $stats['total_families'] > 0 ? 
                               round($stats['total_population'] / $stats['total_families'], 1) : 0;
    
    // Total GN divisions
    $sql = "SELECT COUNT(*) as total FROM users WHERE user_type = 'gn' AND is_active = 1";
    $result = $db->query($sql);
    $row = $result->fetch_assoc();
    $stats['total_gn'] = $row['total'] ?? 0;
    
    return $stats;
}

function getGenderStatistics($db, $gn_filter = 'all') {
    $stats = [];
    
    if ($gn_filter === 'all') {
        $sql = "SELECT 
                    gender,
                    COUNT(*) as count
                FROM citizens 
                GROUP BY gender
                ORDER BY count DESC";
        
        $result = $db->query($sql);
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $gender = ucfirst($row['gender']);
            $stats[$gender] = [
                'count' => $row['count'],
                'percentage' => 0
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
    } else {
        $sql = "SELECT 
                    c.gender,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY c.gender
                ORDER BY count DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $gn_filter);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $gender = ucfirst($row['gender']);
            $stats[$gender] = [
                'count' => $row['count'],
                'percentage' => 0
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
    }
    
    return $stats;
}

function getAgeStatistics($db, $gn_filter = 'all') {
    $stats = [];
    
    if ($gn_filter === 'all') {
        $sql = "SELECT 
                    CASE
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 18 THEN 'Children (0-17)'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Youth (18-35)'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 60 THEN 'Adults (36-60)'
                        ELSE 'Seniors (60+)'
                    END as age_group,
                    COUNT(*) as count
                FROM citizens 
                GROUP BY age_group
                ORDER BY 
                    CASE age_group
                        WHEN 'Children (0-17)' THEN 1
                        WHEN 'Youth (18-35)' THEN 2
                        WHEN 'Adults (36-60)' THEN 3
                        ELSE 4
                    END";
    } else {
        $sql = "SELECT 
                    CASE
                        WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 18 THEN 'Children (0-17)'
                        WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Youth (18-35)'
                        WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) BETWEEN 36 AND 60 THEN 'Adults (36-60)'
                        ELSE 'Seniors (60+)'
                    END as age_group,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY age_group
                ORDER BY 
                    CASE age_group
                        WHEN 'Children (0-17)' THEN 1
                        WHEN 'Youth (18-35)' THEN 2
                        WHEN 'Adults (36-60)' THEN 3
                        ELSE 4
                    END";
    }
    
    if ($gn_filter === 'all') {
        $result = $db->query($sql);
    } else {
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $gn_filter);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $stats[$row['age_group']] = [
            'count' => $row['count'],
            'percentage' => 0
        ];
        $total += $row['count'];
    }
    
    // Calculate percentages
    foreach ($stats as &$data) {
        $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
    }
    
    return $stats;
}

function getFamilyStatistics($db, $gn_filter = 'all') {
    $stats = [];
    
    if ($gn_filter === 'all') {
        $sql = "SELECT 
                    total_members as size,
                    COUNT(*) as count
                FROM families 
                GROUP BY total_members
                ORDER BY total_members";
        
        $result = $db->query($sql);
    } else {
        $sql = "SELECT 
                    total_members as size,
                    COUNT(*) as count
                FROM families 
                WHERE gn_id = ?
                GROUP BY total_members
                ORDER BY total_members";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $gn_filter);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    while ($row = $result->fetch_assoc()) {
        $stats["Family Size {$row['size']}"] = [
            'count' => $row['count'],
            'percentage' => 0
        ];
    }
    
    // Calculate total and percentages
    $total = array_sum(array_column($stats, 'count'));
    foreach ($stats as &$data) {
        $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
    }
    
    return $stats;
}

function getEducationStatistics($db, $gn_filter = 'all') {
    $stats = [];
    
    if ($gn_filter === 'all') {
        $sql = "SELECT 
                    COALESCE(e.education_level, 'Not recorded') as level,
                    COUNT(DISTINCT e.citizen_id) as count
                FROM citizens c 
                LEFT JOIN education e ON c.citizen_id = e.citizen_id
                GROUP BY e.education_level
                ORDER BY 
                    CASE level
                        WHEN 'phd' THEN 1 WHEN 'mphil' THEN 2 WHEN 'degree' THEN 3 
                        WHEN 'diploma' THEN 4 WHEN 'al' THEN 5 WHEN 'ol' THEN 6
                        WHEN '10' THEN 7 WHEN '9' THEN 8 WHEN '8' THEN 9
                        WHEN '7' THEN 10 WHEN '6' THEN 11 WHEN '5' THEN 12
                        WHEN '4' THEN 13 WHEN '3' THEN 14 WHEN '2' THEN 15
                        WHEN '1' THEN 16 ELSE 17
                    END";
        
        $result = $db->query($sql);
    } else {
        $sql = "SELECT 
                    COALESCE(e.education_level, 'Not recorded') as level,
                    COUNT(DISTINCT e.citizen_id) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                LEFT JOIN education e ON c.citizen_id = e.citizen_id
                WHERE f.gn_id = ?
                GROUP BY e.education_level
                ORDER BY 
                    CASE level
                        WHEN 'phd' THEN 1 WHEN 'mphil' THEN 2 WHEN 'degree' THEN 3 
                        WHEN 'diploma' THEN 4 WHEN 'al' THEN 5 WHEN 'ol' THEN 6
                        WHEN '10' THEN 7 WHEN '9' THEN 8 WHEN '8' THEN 9
                        WHEN '7' THEN 10 WHEN '6' THEN 11 WHEN '5' THEN 12
                        WHEN '4' THEN 13 WHEN '3' THEN 14 WHEN '2' THEN 15
                        WHEN '1' THEN 16 ELSE 17
                    END";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $gn_filter);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $education_levels = [
        '1' => 'Grade 1', '2' => 'Grade 2', '3' => 'Grade 3',
        '4' => 'Grade 4', '5' => 'Grade 5', '6' => 'Grade 6',
        '7' => 'Grade 7', '8' => 'Grade 8', '9' => 'Grade 9',
        '10' => 'Grade 10', 'ol' => 'O/L', 'al' => 'A/L',
        'diploma' => 'Diploma', 'degree' => 'Degree',
        'masters' => "Master's", 'mphil' => 'MPhil', 'phd' => 'PhD',
        'Not recorded' => 'No Education Recorded'
    ];
    
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $level_name = $education_levels[$row['level']] ?? ucfirst($row['level']);
        $stats[$level_name] = [
            'count' => $row['count'],
            'percentage' => 0
        ];
        $total += $row['count'];
    }
    
    // Calculate percentages
    foreach ($stats as &$data) {
        $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
    }
    
    return $stats;
}

function getEmploymentStatistics($db, $gn_filter = 'all') {
    $stats = [];
    
    if ($gn_filter === 'all') {
        $sql = "SELECT 
                    COALESCE(e.employment_type, 'Not employed') as type,
                    COUNT(DISTINCT e.citizen_id) as count
                FROM citizens c 
                LEFT JOIN employment e ON c.citizen_id = e.citizen_id AND e.is_current_job = 1
                GROUP BY e.employment_type
                ORDER BY count DESC";
        
        $result = $db->query($sql);
    } else {
        $sql = "SELECT 
                    COALESCE(e.employment_type, 'Not employed') as type,
                    COUNT(DISTINCT e.citizen_id) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                LEFT JOIN employment e ON c.citizen_id = e.citizen_id AND e.is_current_job = 1
                WHERE f.gn_id = ?
                GROUP BY e.employment_type
                ORDER BY count DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $gn_filter);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $employment_types = [
        'government' => 'Government',
        'private' => 'Private Sector',
        'self' => 'Self-employed',
        'labor' => 'Labor',
        'unemployed' => 'Unemployed',
        'student' => 'Student',
        'retired' => 'Retired',
        'Not employed' => 'Not Employed'
    ];
    
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $type_name = $employment_types[$row['type']] ?? ucfirst($row['type']);
        $stats[$type_name] = [
            'count' => $row['count'],
            'percentage' => 0
        ];
        $total += $row['count'];
    }
    
    // Calculate percentages
    foreach ($stats as &$data) {
        $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
    }
    
    return $stats;
}

function getEthnicityStatistics($db, $gn_filter = 'all') {
    $stats = [];
    
    if ($gn_filter === 'all') {
        $sql = "SELECT 
                    COALESCE(ethnicity, 'Not specified') as ethnicity,
                    COUNT(*) as count
                FROM citizens 
                GROUP BY ethnicity
                ORDER BY count DESC
                LIMIT 10";
        
        $result = $db->query($sql);
    } else {
        $sql = "SELECT 
                    COALESCE(c.ethnicity, 'Not specified') as ethnicity,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY c.ethnicity
                ORDER BY count DESC
                LIMIT 10";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $gn_filter);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $stats[$row['ethnicity']] = [
            'count' => $row['count'],
            'percentage' => 0
        ];
        $total += $row['count'];
    }
    
    // Calculate percentages
    foreach ($stats as &$data) {
        $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
    }
    
    return $stats;
}

function getReligionStatistics($db, $gn_filter = 'all') {
    $stats = [];
    
    if ($gn_filter === 'all') {
        $sql = "SELECT 
                    COALESCE(religion, 'Not specified') as religion,
                    COUNT(*) as count
                FROM citizens 
                GROUP BY religion
                ORDER BY count DESC
                LIMIT 10";
        
        $result = $db->query($sql);
    } else {
        $sql = "SELECT 
                    COALESCE(c.religion, 'Not specified') as religion,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY c.religion
                ORDER BY count DESC
                LIMIT 10";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $gn_filter);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $stats[$row['religion']] = [
            'count' => $row['count'],
            'percentage' => 0
        ];
        $total += $row['count'];
    }
    
    // Calculate percentages
    foreach ($stats as &$data) {
        $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
    }
    
    return $stats;
}

function getMonthlyTrends($db, $year) {
    $trends = [];
    
    for ($month = 1; $month <= 12; $month++) {
        $sql = "SELECT COUNT(*) as families 
                FROM families 
                WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ii", $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $month_name = date('M', mktime(0, 0, 0, $month, 1));
        $trends[$month_name] = $row['families'] ?? 0;
    }
    
    return $trends;
}

function getTopGNDivisions($db, $limit = 10) {
    $top_gn = [];
    
    $sql = "SELECT 
                u.office_code as gn_id,
                u.office_name,
                COUNT(DISTINCT f.family_id) as families,
                COUNT(DISTINCT c.citizen_id) as population
            FROM users u 
            LEFT JOIN families f ON u.office_code = f.gn_id
            LEFT JOIN citizens c ON f.family_id = c.family_id
            WHERE u.user_type = 'gn' AND u.is_active = 1
            GROUP BY u.office_code, u.office_name
            ORDER BY population DESC
            LIMIT ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $avg_size = $row['families'] > 0 ? round($row['population'] / $row['families'], 1) : 0;
        $top_gn[] = [
            'gn_id' => $row['gn_id'],
            'office_name' => $row['office_name'],
            'families' => $row['families'],
            'population' => $row['population'],
            'avg_family_size' => $avg_size
        ];
    }
    
    return $top_gn;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - FPMS</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: none;
            margin-bottom: 1rem;
        }
        .card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .stat-card {
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }
        .stat-badge {
            font-size: 0.85rem;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body class="<?php echo $bodyClass; ?>">
    
<?php if (isset($_SESSION['user_id'])): ?>
    <!-- Include Header -->
    <?php 
    $header_path = '../includes/header.php';
    if (file_exists($header_path)) {
        include $header_path;
    }
    ?>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
                <?php 
                $sidebar_path = '../includes/sidebar.php';
                if (file_exists($sidebar_path)) {
                    include $sidebar_path;
                }
                ?>
            </div>
            
            <!-- Main Content -->
            <main class="">
                <!-- Page Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    
                
                <div>
                        <h1 class="h2">
                            <i class="<?php echo $pageIcon; ?> me-2"></i>
                            <?php echo htmlspecialchars($pageTitle); ?>
                        </h1>
                        <p class="text-muted mb-0">
                            Division: <strong><?php echo htmlspecialchars($division_name); ?></strong>
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-success" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print Report
                            </button>
                            <button type="button" class="btn btn-outline-success" onclick="exportReport()">
                                <i class="bi bi-download"></i> Export
                            </button>
                        </div>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-speedometer2"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Alert Messages -->
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Report Filters -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-funnel me-2"></i> Statistics Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Year</label>
                                <select class="form-select" name="year">
                                    <?php for ($y = 2020; $y <= date('Y'); $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($year == $y) ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Month</label>
                                <select class="form-select" name="month">
                                    <?php 
                                    $months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                             'July', 'August', 'September', 'October', 'November', 'December'];
                                    foreach ($months as $index => $month_name): ?>
                                        <option value="<?php echo $index + 1; ?>" <?php echo ($month == $index + 1) ? 'selected' : ''; ?>>
                                            <?php echo $month_name; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">GN Division</label>
                                <select class="form-select" name="gn">
                                    <option value="all" <?php echo ($gn_filter === 'all') ? 'selected' : ''; ?>>All GN Divisions</option>
                                    <?php foreach ($gn_divisions as $gn): ?>
                                        <option value="<?php echo htmlspecialchars($gn['office_code']); ?>" 
                                                <?php echo ($gn_filter === $gn['office_code']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($gn['office_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-filter"></i> Apply Filters
                                </button>
                                <a href="statistics.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Summary Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-white bg-primary h-100 stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Total GN Divisions</h6>
                                        <h2 class="mb-0"><?php echo safe_number_format($total_stats['total_gn'] ?? 0); ?></h2>
                                    </div>
                                    <i class="bi bi-geo-alt display-6 opacity-50"></i>
                                </div>
                                <small class="opacity-75">Active GN divisions</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-white bg-success h-100 stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Total Families</h6>
                                        <h2 class="mb-0"><?php echo safe_number_format($total_stats['total_families'] ?? 0); ?></h2>
                                    </div>
                                    <i class="bi bi-house-door display-6 opacity-50"></i>
                                </div>
                                <small class="opacity-75">Registered families</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-white bg-warning h-100 stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Total Population</h6>
                                        <h2 class="mb-0"><?php echo safe_number_format($total_stats['total_population'] ?? 0); ?></h2>
                                    </div>
                                    <i class="bi bi-people display-6 opacity-50"></i>
                                </div>
                                <small class="opacity-75">Registered citizens</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-white bg-info h-100 stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Avg Family Size</h6>
                                        <h2 class="mb-0"><?php echo safe_number_format($total_stats['avg_family_size'] ?? 0, 1); ?></h2>
                                    </div>
                                    <i class="bi bi-person-bounding-box display-6 opacity-50"></i>
                                </div>
                                <small class="opacity-75">Members per family</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Main Statistics Grid -->
                <div class="row">
                    <!-- Left Column -->
                    <div class="col-lg-8">
                        <!-- Gender Distribution -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-gender-ambiguous me-2"></i> Gender Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="chart-container">
                                            <canvas id="genderChart"></canvas>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Gender</th>
                                                        <th>Count</th>
                                                        <th>Percentage</th>
                                                        <th>Distribution</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($gender_stats as $gender => $data): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($gender); ?></td>
                                                        <td><?php echo safe_number_format($data['count']); ?></td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo safe_percentage_format($data['percentage']); ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="progress" style="height: 6px; width: 100px;">
                                                                <div class="progress-bar" role="progressbar" style="width: <?php echo min(100, $data['percentage']); ?>%"></div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Age Distribution -->
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="bi bi-calendar me-2"></i> Age Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="chart-container">
                                            <canvas id="ageChart"></canvas>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Age Group</th>
                                                        <th>Count</th>
                                                        <th>Percentage</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($age_stats as $group => $data): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($group); ?></td>
                                                        <td><?php echo safe_number_format($data['count']); ?></td>
                                                        <td>
                                                            <span class="badge bg-success"><?php echo safe_percentage_format($data['percentage']); ?></span>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Education Statistics -->
                        <div class="card mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i> Education Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Education Level</th>
                                                <th>Count</th>
                                                <th>Percentage</th>
                                                <th>Distribution</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($education_stats as $level => $data): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($level); ?></td>
                                                <td><?php echo safe_number_format($data['count']); ?></td>
                                                <td>
                                                    <span class="badge bg-warning"><?php echo safe_percentage_format($data['percentage']); ?></span>
                                                </td>
                                                <td>
                                                    <div class="progress" style="height: 6px; width: 100px;">
                                                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo min(100, $data['percentage']); ?>%"></div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column -->
                    <div class="col-lg-4">
                        <!-- Top GN Divisions -->
                        <div class="card mb-4">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-trophy me-2"></i> Top GN Divisions</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>GN Division</th>
                                                <th>Population</th>
                                                <th>Families</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $counter = 1; ?>
                                            <?php foreach ($top_gn_divisions as $gn): ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td><?php echo htmlspecialchars($gn['office_name']); ?></td>
                                                <td><?php echo safe_number_format($gn['population']); ?></td>
                                                <td><?php echo safe_number_format($gn['families']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Ethnicity Distribution -->
                        <div class="card mb-4">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0"><i class="bi bi-globe me-2"></i> Ethnicity Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="ethnicityChart"></canvas>
                                </div>
                                <div class="mt-3">
                                    <table class="table table-sm">
                                        <tbody>
                                            <?php foreach ($ethnicity_stats as $ethnicity => $data): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ethnicity); ?></td>
                                                <td class="text-end"><?php echo safe_percentage_format($data['percentage']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Employment Statistics -->
                        <div class="card mb-4">
                            <div class="card-header bg-dark text-white">
                                <h5 class="mb-0"><i class="bi bi-briefcase me-2"></i> Employment Status</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="employmentChart"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Family Size Distribution -->
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-house-door me-2"></i> Family Size Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Family Size</th>
                                                <th>Count</th>
                                                <th>Percentage</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($family_stats as $size => $data): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($size); ?></td>
                                                <td><?php echo safe_number_format($data['count']); ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo safe_percentage_format($data['percentage']); ?></span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Trends -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i> Monthly Registration Trends (<?php echo $year; ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="trendsChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Report Summary -->
                <div class="card mt-4">
                    <div class="card-header bg-light text-dark">
                        <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i> Report Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Report Generated:</strong> <?php echo date('d M Y, h:i A'); ?></p>
                                <p><strong>Generated By:</strong> <?php echo htmlspecialchars($username); ?></p>
                                <p><strong>Division:</strong> <?php echo htmlspecialchars($division_name); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Scope:</strong> <?php echo ($gn_filter === 'all') ? 'All GN Divisions' : 'Selected GN Division'; ?></p>
                                <p><strong>Period:</strong> Year <?php echo $year; ?> | Month <?php echo date('F', mktime(0, 0, 0, $month, 1)); ?></p>
                                <p><strong>Data Source:</strong> Family Population Management System</p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Export Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Charts
            initializeGenderChart();
            initializeAgeChart();
            initializeEthnicityChart();
            initializeEmploymentChart();
            initializeTrendsChart();
        });
        
        function initializeGenderChart() {
            const ctx = document.getElementById('genderChart').getContext('2d');
            const data = {
                labels: <?php echo json_encode(array_keys($gender_stats)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($gender_stats, 'count')); ?>,
                    backgroundColor: [
                        '#36A2EB', // Male - Blue
                        '#FF6384', // Female - Pink
                        '#FFCE56'  // Other - Yellow
                    ],
                    borderWidth: 1
                }]
            };
            
            new Chart(ctx, {
                type: 'pie',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        title: {
                            display: true,
                            text: 'Gender Distribution'
                        }
                    }
                }
            });
        }
        
        function initializeAgeChart() {
            const ctx = document.getElementById('ageChart').getContext('2d');
            const data = {
                labels: <?php echo json_encode(array_keys($age_stats)); ?>,
                datasets: [{
                    label: 'Population',
                    data: <?php echo json_encode(array_column($age_stats, 'count')); ?>,
                    backgroundColor: [
                        '#4BC0C0', // Children
                        '#36A2EB', // Youth
                        '#FF9F40', // Adults
                        '#9966FF'  // Seniors
                    ],
                    borderWidth: 1
                }]
            };
            
            new Chart(ctx, {
                type: 'bar',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Age Group Distribution'
                        }
                    }
                }
            });
        }
        
        function initializeEthnicityChart() {
            const ctx = document.getElementById('ethnicityChart').getContext('2d');
            const data = {
                labels: <?php echo json_encode(array_keys($ethnicity_stats)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($ethnicity_stats, 'percentage')); ?>,
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                        '#9966FF', '#FF9F40', '#8AC926', '#1982C4'
                    ],
                    borderWidth: 1
                }]
            };
            
            new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Ethnicity Distribution'
                        }
                    }
                }
            });
        }
        
        function initializeEmploymentChart() {
            const ctx = document.getElementById('employmentChart').getContext('2d');
            const data = {
                labels: <?php echo json_encode(array_keys($employment_stats)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($employment_stats, 'percentage')); ?>,
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                        '#9966FF', '#FF9F40', '#8AC926'
                    ],
                    borderWidth: 1
                }]
            };
            
            new Chart(ctx, {
                type: 'polarArea',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Employment Status'
                        }
                    }
                }
            });
        }
        
        function initializeTrendsChart() {
            const ctx = document.getElementById('trendsChart').getContext('2d');
            const data = {
                labels: <?php echo json_encode(array_keys($monthly_trends)); ?>,
                datasets: [{
                    label: 'New Families Registered',
                    data: <?php echo json_encode(array_values($monthly_trends)); ?>,
                    borderColor: '#36A2EB',
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            };
            
            new Chart(ctx, {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Families'
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Monthly Registration Trends'
                        }
                    }
                }
            });
        }
        
        // Export report
        function exportReport() {
            // Get all tables
            const tables = document.querySelectorAll('.table');
            
            if (tables.length === 0) {
                alert('No data to export');
                return;
            }
            
            // Create workbook
            const wb = XLSX.utils.book_new();
            
            // Add each table as a separate sheet
            tables.forEach((table, index) => {
                const ws = XLSX.utils.table_to_sheet(table);
                XLSX.utils.book_append_sheet(wb, ws, `Sheet${index + 1}`);
            });
            
            const filename = 'division-statistics-<?php echo date("Y-m-d"); ?>.xlsx';
            XLSX.writeFile(wb, filename);
        }
    </script>

<?php else: ?>
    <div class="container mt-5">
        <div class="alert alert-danger">
            <h4>Access Denied</h4>
            <p>You are not logged in. Please <a href="../login.php">login</a> to access this page.</p>
        </div>
    </div>
<?php endif; ?>

</body>
</html>