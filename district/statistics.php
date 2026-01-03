<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Database.php'; // Include the Database class

// Check if user is logged in as District Secretariat
if (!isset($_SESSION['user_id']) || $_SESSION['office_type'] !== 'DISTRICT') {
    header('Location: ../login.php');
    exit();
}

// Get district information
$district_name = $_SESSION['office_identifier'];

// Initialize variables for filters
$division_filter = isset($_GET['division']) ? sanitizeInput($_GET['division']) : '';
$date_from = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : date('Y-m-d');

// Validate date inputs
if (!validateDate($date_from) || !validateDate($date_to)) {
    $date_from = date('Y-m-01');
    $date_to = date('Y-m-d');
}

// Get all divisions under this district
$divisions = getDivisionsByDistrict($district_name);

// Prepare SQL queries for statistics
$stats = array();

try {
    $db = Database::getInstance();
    
    // 1. Total Families in District
    $sql = "SELECT COUNT(DISTINCT f.family_id) as total_families
            FROM families f
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ?";
    
    if ($division_filter) { 
        $sql .= " AND ws.Division_Name = ?";
        $params = [$district_name, $division_filter];
    } else {
        $params = [$district_name];
    }
    
    $row = $db->getRow($sql, $params);
    $stats['total_families'] = $row['total_families'] ?? 0;

    // 2. Total Citizens in District
    $sql = "SELECT COUNT(DISTINCT c.citizen_id) as total_citizens
            FROM citizens c
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE'";
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params = [$district_name, $division_filter];
    } else {
        $params = [$district_name];
    }
    
    $row = $db->getRow($sql, $params);
    $stats['total_citizens'] = $row['total_citizens'] ?? 0;

    // 3. Population by Gender
    $sql = "SELECT 
                c.gender,
                COUNT(c.citizen_id) as count
            FROM citizens c
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE'";
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params = [$district_name, $division_filter];
    } else {
        $params = [$district_name];
    }
    
    $sql .= " GROUP BY c.gender ORDER BY c.gender";
    
    $result = $db->getAll($sql, $params);
    $stats['gender_distribution'] = array();
    foreach ($result as $row) {
        $stats['gender_distribution'][$row['gender']] = $row['count'];
    }

    // 4. Education Level Statistics
    $sql = "SELECT 
                e.education_level,
                COUNT(DISTINCT e.citizen_id) as count
            FROM education e
            INNER JOIN citizens c ON e.citizen_id = c.citizen_id
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE'";
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params = [$district_name, $division_filter];
    } else {
        $params = [$district_name];
    }
    
    $sql .= " GROUP BY e.education_level 
              ORDER BY FIELD(e.education_level, 
                'PHD','MPHIL','MASTERS','DEGREE','A/L','O/L',
                '10','9','8','7','6','5','4','3','2','1')";
    
    $result = $db->getAll($sql, $params);
    $stats['education_levels'] = array();
    foreach ($result as $row) {
        $stats['education_levels'][$row['education_level']] = $row['count'];
    }

    // 5. Employment Statistics
    $sql = "SELECT 
                em.employment_type,
                COUNT(DISTINCT em.citizen_id) as count
            FROM employment em
            INNER JOIN citizens c ON em.citizen_id = c.citizen_id
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE'";
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params = [$district_name, $division_filter];
    } else {
        $params = [$district_name];
    }
    
    $sql .= " GROUP BY em.employment_type ORDER BY em.employment_type";
    
    $result = $db->getAll($sql, $params);
    $stats['employment_types'] = array();
    foreach ($result as $row) {
        $stats['employment_types'][$row['employment_type']] = $row['count'];
    }

    // 6. Health Condition Statistics
    $sql = "SELECT 
                hc.condition_name,
                COUNT(DISTINCT hc.citizen_id) as count
            FROM health_conditions hc
            INNER JOIN citizens c ON hc.citizen_id = c.citizen_id
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE'";
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params = [$district_name, $division_filter];
    } else {
        $params = [$district_name];
    }
    
    $sql .= " GROUP BY hc.condition_name ORDER BY count DESC LIMIT 10";
    
    $result = $db->getAll($sql, $params);
    $stats['health_conditions'] = array();
    foreach ($result as $row) {
        $stats['health_conditions'][$row['condition_name']] = $row['count'];
    }

    // 7. Land Ownership Statistics
    $sql = "SELECT 
                ld.land_type,
                COUNT(ld.id) as count,
                SUM(ld.size_perches) as total_size
            FROM land_details ld
            INNER JOIN families f ON ld.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ?";
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params = [$district_name, $division_filter];
    } else {
        $params = [$district_name];
    }
    
    $sql .= " GROUP BY ld.land_type ORDER BY ld.land_type";
    
    $result = $db->getAll($sql, $params);
    $stats['land_ownership'] = array();
    foreach ($result as $row) {
        $stats['land_ownership'][$row['land_type']] = array(
            'count' => $row['count'],
            'total_size' => $row['total_size'] ?? 0
        );
    }

    // 8. Recent Registrations
    $sql = "SELECT 
                DATE(f.registration_date) as reg_date,
                COUNT(f.family_id) as count
            FROM families f
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? 
                AND f.registration_date BETWEEN ? AND ?";
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params = [$district_name, $date_from, $date_to, $division_filter];
    } else {
        $params = [$district_name, $date_from, $date_to];
    }
    
    $sql .= " GROUP BY DATE(f.registration_date) ORDER BY reg_date";
    
    $result = $db->getAll($sql, $params);
    $stats['recent_registrations'] = array();
    foreach ($result as $row) {
        $stats['recent_registrations'][$row['reg_date']] = $row['count'];
    }

    // 9. Division-wise Statistics
    $sql = "SELECT 
                ws.Division_Name,
                COUNT(DISTINCT f.family_id) as families,
                COUNT(DISTINCT c.citizen_id) as citizens,
                COUNT(DISTINCT CASE WHEN c.gender = 'MALE' THEN c.citizen_id END) as male,
                COUNT(DISTINCT CASE WHEN c.gender = 'FEMALE' THEN c.citizen_id END) as female
            FROM families f
            INNER JOIN citizens c ON f.family_id = c.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE'
            GROUP BY ws.Division_Name
            ORDER BY ws.Division_Name";
    
    $params = [$district_name];
    $result = $db->getAll($sql, $params);
    $stats['division_wise'] = array();
    foreach ($result as $row) {
        $stats['division_wise'][$row['Division_Name']] = array(
            'families' => $row['families'],
            'citizens' => $row['citizens'],
            'male' => $row['male'],
            'female' => $row['female']
        );
    }

    // 10. GN Division Statistics
    $sql = "SELECT 
                ws.GN,
                ws.GN_ID,
                COUNT(DISTINCT f.family_id) as families,
                COUNT(DISTINCT c.citizen_id) as citizens
            FROM families f
            INNER JOIN citizens c ON f.family_id = c.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ?";
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params = [$district_name, $division_filter];
    } else {
        $params = [$district_name];
    }
    
    $sql .= " GROUP BY ws.GN_ID, ws.GN ORDER BY families DESC LIMIT 20";
    
    $result = $db->getAll($sql, $params);
    $stats['gn_wise'] = array();
    foreach ($result as $row) {
        $stats['gn_wise'][$row['GN_ID']] = array(
            'gn_name' => $row['GN'],
            'families' => $row['families'],
            'citizens' => $row['citizens']
        );
    }

} catch (Exception $e) {
    $error = "Error loading statistics: " . $e->getMessage();
    logActivity('STATISTICS_ERROR', "District stats error: " . $e->getMessage(), $_SESSION['user_id']);
}

// Helper functions
function getDivisionsByDistrict($district_name) {
    try {
        $db = Database::getInstance();
        $sql = "SELECT DISTINCT Division_Name 
                FROM mobile_service.fix_work_station 
                WHERE District_Name = ? 
                ORDER BY Division_Name";
        
        $params = [$district_name];
        $result = $db->getAll($sql, $params);
        
        $divisions = array();
        foreach ($result as $row) {
            $divisions[] = $row['Division_Name'];
        }
        
        return $divisions;
    } catch (Exception $e) {
        logActivity('GET_DIVISIONS_ERROR', $e->getMessage());
        return array();
    }
}

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Get chart data for visualization
$chart_data = array(
    'gender_labels' => array_keys($stats['gender_distribution'] ?? array()),
    'gender_data' => array_values($stats['gender_distribution'] ?? array()),
    'education_labels' => array_keys($stats['education_levels'] ?? array()),
    'education_data' => array_values($stats['education_levels'] ?? array()),
    'employment_labels' => array_keys($stats['employment_types'] ?? array()),
    'employment_data' => array_values($stats['employment_types'] ?? array())
);

// Log access
logActivity('VIEW_STATISTICS', "District statistics viewed for $district_name", $_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>District Statistics - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card {
            transition: transform 0.3s;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        .card-header {
            background: linear-gradient(45deg, #1e88e5, #0d47a1);
            color: white;
            font-weight: bold;
        }
        .dashboard-header {
            background: linear-gradient(45deg, #1565c0, #0d47a1);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .filter-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .export-btn {
            float: right;
        }
        @media print {
            .no-print, .filter-section, .export-btn {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <?php 
    // Use your existing navbar or create a simple one
    if (file_exists('../includes/navbar.php')) {
        include '../includes/navbar.php';
    } else {
        // Simple navbar if not exists
        echo '<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
            <div class="container-fluid">
                <a class="navbar-brand" href="../district/">
                    <i class="bi bi-house-door"></i> ' . SITE_SHORT_NAME . '
                </a>
                <div class="navbar-nav ms-auto">
                    <span class="navbar-text me-3">
                        <i class="bi bi-geo-alt"></i> ' . htmlspecialchars($district_name) . ' District
                    </span>
                    <a href="../logout.php" class="btn btn-outline-light">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </div>
            </div>
        </nav>';
    }
    
    displayFlashMessage();
    ?>

    <div class="container-fluid mt-4">
        <div class="dashboard-header">
            <h2><i class="bi bi-bar-chart-fill"></i> District Statistics Dashboard</h2>
            <h4><?php echo htmlspecialchars($district_name); ?> District</h4>
            <p class="mb-0">Comprehensive overview of family data and demographics</p>
        </div>

        <!-- Filters Section -->
        <div class="filter-section no-print">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label for="division" class="form-label">Division Filter</label>
                    <select name="division" id="division" class="form-select">
                        <option value="">All Divisions</option>
                        <?php foreach ($divisions as $division): ?>
                            <option value="<?php echo htmlspecialchars($division); ?>" 
                                <?php echo ($division_filter == $division) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($division); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" name="date_from" id="date_from" 
                           class="form-control" value="<?php echo $date_from; ?>" 
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" name="date_to" id="date_to" 
                           class="form-control" value="<?php echo $date_to; ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i> Apply Filters
                    </button>
                </div>
            </form>
            <?php if ($division_filter): ?>
                <div class="mt-2">
                    <span class="badge bg-info">
                        <i class="bi bi-filter"></i> Filtered by Division: <?php echo htmlspecialchars($division_filter); ?>
                    </span>
                    <a href="statistics.php" class="btn btn-sm btn-outline-secondary ms-2">
                        <i class="bi bi-x-circle"></i> Clear Filter
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Summary Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card stat-card text-white bg-primary">
                    <div class="card-body text-center">
                        <h1 class="display-4"><?php echo number_format($stats['total_families'] ?? 0); ?></h1>
                        <h5>Total Families</h5>
                        <i class="bi bi-house-door-fill display-6"></i>
                        <?php if ($division_filter): ?>
                            <div class="mt-2 small">
                                <i class="bi bi-filter"></i> Filtered
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card stat-card text-white bg-success">
                    <div class="card-body text-center">
                        <h1 class="display-4"><?php echo number_format($stats['total_citizens'] ?? 0); ?></h1>
                        <h5>Total Citizens</h5>
                        <i class="bi bi-people-fill display-6"></i>
                        <?php if ($stats['total_families'] > 0): ?>
                            <div class="mt-2 small">
                                Avg: <?php echo round($stats['total_citizens'] / $stats['total_families'], 1); ?> per family
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card stat-card text-white bg-warning">
                    <div class="card-body text-center">
                        <h1 class="display-4">
                            <?php echo number_format($stats['gender_distribution']['MALE'] ?? 0); ?>
                        </h1>
                        <h5>Male Population</h5>
                        <i class="bi bi-gender-male display-6"></i>
                        <?php if ($stats['total_citizens'] > 0): ?>
                            <div class="mt-2 small">
                                <?php echo round(($stats['gender_distribution']['MALE'] ?? 0) / $stats['total_citizens'] * 100, 1); ?>%
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card stat-card text-white bg-info">
                    <div class="card-body text-center">
                        <h1 class="display-4">
                            <?php echo number_format($stats['gender_distribution']['FEMALE'] ?? 0); ?>
                        </h1>
                        <h5>Female Population</h5>
                        <i class="bi bi-gender-female display-6"></i>
                        <?php if ($stats['total_citizens'] > 0): ?>
                            <div class="mt-2 small">
                                <?php echo round(($stats['gender_distribution']['FEMALE'] ?? 0) / $stats['total_citizens'] * 100, 1); ?>%
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row mb-4">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-pie-chart-fill"></i> Gender Distribution
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="genderChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-bar-chart-fill"></i> Education Levels
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="educationChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Statistics Tables -->
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-briefcase-fill"></i> Employment Statistics
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-primary">
                                    <tr>
                                        <th>Employment Type</th>
                                        <th>Count</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_employment = array_sum($stats['employment_types'] ?? array());
                                    foreach (($stats['employment_types'] ?? array()) as $type => $count): 
                                        $percentage = $total_employment > 0 ? ($count / $total_employment * 100) : 0;
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($type); ?></td>
                                            <td><?php echo number_format($count); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1" style="height: 20px;">
                                                        <div class="progress-bar bg-success" role="progressbar" 
                                                             style="width: <?php echo $percentage; ?>%" 
                                                             aria-valuenow="<?php echo $percentage; ?>" 
                                                             aria-valuemin="0" aria-valuemax="100">
                                                        </div>
                                                    </div>
                                                    <span class="ms-2" style="min-width: 50px;">
                                                        <?php echo number_format($percentage, 1); ?>%
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($stats['employment_types'])): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">
                                                <i class="bi bi-info-circle"></i> No employment data available
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-hospital-fill"></i> Top Health Conditions
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-primary">
                                    <tr>
                                        <th>Condition</th>
                                        <th>Cases</th>
                                        <th>Prevalence</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_cases = array_sum($stats['health_conditions'] ?? array());
                                    foreach (($stats['health_conditions'] ?? array()) as $condition => $count): 
                                        $prevalence = $stats['total_citizens'] > 0 ? 
                                                     ($count / $stats['total_citizens'] * 10000) : 0;
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($condition); ?></td>
                                            <td>
                                                <span class="badge bg-danger rounded-pill">
                                                    <?php echo number_format($count); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo number_format($prevalence, 2); ?> per 10,000
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($stats['health_conditions'])): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">
                                                <i class="bi bi-info-circle"></i> No health condition data available
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Division-wise Statistics -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-map-fill"></i> Division-wise Statistics</span>
                        <span class="badge bg-primary">
                            <?php echo count($stats['division_wise'] ?? array()); ?> Divisions
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Division</th>
                                        <th>Families</th>
                                        <th>Citizens</th>
                                        <th>Male</th>
                                        <th>Female</th>
                                        <th>Avg Family Size</th>
                                        <th>% of District</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $district_families = $stats['total_families'] ?? 1;
                                    $district_citizens = $stats['total_citizens'] ?? 1;
                                    
                                    foreach (($stats['division_wise'] ?? array()) as $division => $data): 
                                        $avg_size = $data['families'] > 0 ? 
                                                   round($data['citizens'] / $data['families'], 2) : 0;
                                        $family_percent = round(($data['families'] / $district_families) * 100, 1);
                                        $citizen_percent = round(($data['citizens'] / $district_citizens) * 100, 1);
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($division); ?></strong></td>
                                            <td><?php echo number_format($data['families']); ?></td>
                                            <td><?php echo number_format($data['citizens']); ?></td>
                                            <td><?php echo number_format($data['male']); ?></td>
                                            <td><?php echo number_format($data['female']); ?></td>
                                            <td><?php echo $avg_size; ?></td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-info" role="progressbar" 
                                                         style="width: <?php echo $family_percent; ?>%">
                                                        <?php echo $family_percent; ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($stats['division_wise'])): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                <i class="bi bi-info-circle"></i> No division data available
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Button -->
        <div class="mt-4 mb-4 no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <button onclick="exportToExcel()" class="btn btn-success export-btn">
                <i class="bi bi-file-earmark-excel"></i> Export to Excel
            </button>
            <button onclick="exportCharts()" class="btn btn-warning export-btn">
                <i class="bi bi-download"></i> Export Charts
            </button>
        </div>
    </div>

    <script>
        // Gender Distribution Chart
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        const genderChart = new Chart(genderCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode($chart_data['gender_labels']); ?>,
                datasets: [{
                    data: <?php echo json_encode($chart_data['gender_data']); ?>,
                    backgroundColor: [
                        '#36A2EB',
                        '#FF6384',
                        '#FFCE56'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Education Levels Chart
        const educationCtx = document.getElementById('educationChart').getContext('2d');
        const educationChart = new Chart(educationCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_data['education_labels']); ?>,
                datasets: [{
                    label: 'Number of Citizens',
                    data: <?php echo json_encode($chart_data['education_data']); ?>,
                    backgroundColor: '#4CAF50',
                    borderColor: '#388E3C',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Citizens'
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Education Level'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Export to Excel function
        function exportToExcel() {
            // Create a temporary table with all data
            const html = `
                <table border="1">
                    <tr>
                        <th colspan="4" style="font-size: 16px; background-color: #0d47a1; color: white; padding: 10px;">
                            District Statistics Report - <?php echo $district_name; ?>
                        </th>
                    </tr>
                    <tr>
                        <th>Metric</th>
                        <th>Value</th>
                        <th>Division Filter</th>
                        <th>Date Range</th>
                    </tr>
                    <tr>
                        <td>Total Families</td>
                        <td><?php echo $stats['total_families']; ?></td>
                        <td><?php echo $division_filter ?: 'All Divisions'; ?></td>
                        <td><?php echo $date_from . ' to ' . $date_to; ?></td>
                    </tr>
                    <tr>
                        <td>Total Citizens</td>
                        <td><?php echo $stats['total_citizens']; ?></td>
                        <td><?php echo $division_filter ?: 'All Divisions'; ?></td>
                        <td><?php echo $date_from . ' to ' . $date_to; ?></td>
                    </tr>
                    <tr>
                        <td>Male Population</td>
                        <td><?php echo $stats['gender_distribution']['MALE'] ?? 0; ?></td>
                        <td><?php echo $division_filter ?: 'All Divisions'; ?></td>
                        <td><?php echo $date_from . ' to ' . $date_to; ?></td>
                    </tr>
                    <tr>
                        <td>Female Population</td>
                        <td><?php echo $stats['gender_distribution']['FEMALE'] ?? 0; ?></td>
                        <td><?php echo $division_filter ?: 'All Divisions'; ?></td>
                        <td><?php echo $date_from . ' to ' . $date_to; ?></td>
                    </tr>
                </table>
            `;
            
            // Convert to Excel
            const uri = 'data:application/vnd.ms-excel;base64,' + 
                       btoa(unescape(encodeURIComponent(html)));
            const link = document.createElement("a");
            link.href = uri;
            link.download = "district_statistics_<?php echo date('Y-m-d'); ?>.xls";
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Export Charts function
        function exportCharts() {
            const genderCanvas = document.getElementById('genderChart');
            const educationCanvas = document.getElementById('educationChart');
            
            const link = document.createElement('a');
            link.download = 'charts_export_<?php echo date('Y-m-d_H-i-s'); ?>.zip';
            link.href = '#';
            link.click();
            
            alert('Chart export feature requires additional server-side implementation.');
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh data every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000); // 5 minutes
        
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>