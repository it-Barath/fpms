<?php
// division/reports_division.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Division Reports Dashboard";
$pageIcon = "bi bi-graph-up";
$pageDescription = "Generate and view statistical reports for your division";
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

// Custom sanitization function for backward compatibility
function safe_sanitize_string($input) {
    if (!is_string($input)) {
        return '';
    }
    $input = trim($input);
    $input = strip_tags($input);
    $input = htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    return $input;
}

try {
    require_once '../config.php';
    require_once '../classes/Auth.php';
    require_once '../classes/Sanitizer.php';
    require_once '../classes/ReportGenerator.php';
    require_once '../classes/DivisionReport.php';
    
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
    $division_code = $_SESSION['office_code'] ?? '';
    $username = $_SESSION['username'] ?? '';
    $office_name = $_SESSION['office_name'] ?? '';
    
    // Initialize classes
    $sanitizer = new Sanitizer();
    $reportGenerator = new ReportGenerator($db);
    $divisionReport = new DivisionReport($db, $division_code);
    
    // Get report parameters from GET
    $report_type = $_GET['report'] ?? 'overview';
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : ceil(date('n') / 3);
    $gn_filter = $_GET['gn'] ?? 'all';
    
    // Validate parameters
    $year = $sanitizer->sanitizeNumber($year, 2020, 2030);
    $month = $sanitizer->sanitizeNumber($month, 1, 12);
    $quarter = $sanitizer->sanitizeNumber($quarter, 1, 4);
    
    // Safe string sanitization for PHP 8.1+
    if (method_exists($sanitizer, 'sanitizeString')) {
        $gn_filter = $sanitizer->sanitizeString($gn_filter);
    } else {
        // Fallback using custom function
        $gn_filter = safe_sanitize_string($gn_filter);
    }
    
    // Get all GN divisions under this division
    $gn_divisions = $divisionReport->getGNDivisions();
    
    // Debug: Check if we got any GN divisions
    if (empty($gn_divisions)) {
        error_log("No GN divisions found for division code: $division_code");
    }
    
    // Validate GN filter
    if ($gn_filter !== 'all') {
        $valid_gn = false;
        foreach ($gn_divisions as $gn) {
            if ($gn['office_code'] == $gn_filter || $gn['gn_id'] == $gn_filter) {
                $valid_gn = true;
                break;
            }
        }
        if (!$valid_gn) {
            $gn_filter = 'all';
        }
    }
    
    // Available report types
    $report_types = [
        'overview' => 'Overview Dashboard',
        'population' => 'Population Statistics',
        'family' => 'Family Statistics',
        'demographic' => 'Demographic Analysis',
        'education' => 'Education Statistics',
        'employment' => 'Employment Statistics',
        'health' => 'Health Statistics',
        'age' => 'Age Distribution',
        'gender' => 'Gender Analysis',
        'comparison' => 'GN Comparison',
        'trends' => 'Trend Analysis',
        'monthly' => 'Monthly Report'
    ];
    
    // Generate report based on type
    $report_data = [];
    $report_title = $report_types[$report_type] ?? 'Overview Dashboard';
    $chart_data = [];
    $comparison_data = [];
    
    // Set parameters for the report
    $divisionReport->setFilters($year, $month, $quarter, $gn_filter);
    
    switch ($report_type) {
        case 'overview':
            $report_data = $divisionReport->getDivisionOverview();
            $chart_data = $divisionReport->getGNPerformanceChart();
            break;
            
        case 'population':
            $report_data = $divisionReport->getDivisionPopulationStats();
            $chart_data = $divisionReport->getPopulationDistributionChart();
            break;
            
        case 'family':
            $report_data = $divisionReport->getDivisionFamilyStats();
            $chart_data = $divisionReport->getFamilySizeComparisonChart();
            break;
            
        case 'demographic':
            $report_data = $divisionReport->getDivisionDemographicStats();
            $chart_data = $divisionReport->getEthnicityDistributionChart();
            break;
            
        case 'education':
            $report_data = $divisionReport->getDivisionEducationStats();
            $chart_data = $divisionReport->getEducationComparisonChart();
            break;
            
        case 'employment':
            $report_data = $divisionReport->getDivisionEmploymentStats();
            $chart_data = $divisionReport->getEmploymentComparisonChart();
            break;
            
        case 'health':
            $report_data = $divisionReport->getDivisionHealthStats();
            $chart_data = $divisionReport->getHealthComparisonChart();
            break;
            
        case 'age':
            $report_data = $divisionReport->getDivisionAgeStats();
            $chart_data = $divisionReport->getAgeGroupComparisonChart();
            break;
            
        case 'gender':
            $report_data = $divisionReport->getDivisionGenderStats();
            $chart_data = $divisionReport->getGenderRatioComparisonChart();
            break;
            
        case 'comparison':
            $comparison_data = $divisionReport->getGNComparison();
            $chart_data = $divisionReport->getGNComparisonChart();
            break;
            
        case 'trends':
            $report_data = $divisionReport->getTrendAnalysis();
            $chart_data = $divisionReport->getRegistrationTrendChart();
            break;
            
        case 'monthly':
            $report_data = $divisionReport->getMonthlyDivisionReport();
            $chart_data = $divisionReport->getMonthlyComparisonChart();
            break;
            
        default:
            $report_data = $divisionReport->getDivisionOverview();
            $chart_data = $divisionReport->getGNPerformanceChart();
            break;
    }
    
    // Get recent activities
    $recent_activities = $divisionReport->getDivisionActivities(10);
    
    // Get quick stats
    $quick_stats = $divisionReport->getDivisionQuickStats();

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Division Reports Dashboard Error: " . $e->getMessage());
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
        .nav-scrollable {
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
        }
        .nav-scrollable .nav {
            flex-wrap: nowrap;
        }
        .nav-scrollable .nav-link {
            white-space: nowrap;
        }
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
        .sidebar-column {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        @media (max-width: 768px) {
            .sidebar-column {
                position: static;
                height: auto;
            }
        }
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
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
    <!-- Sidebar (typically 3 columns on md, 2 on lg) -->
    <nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
      <?php 
                $sidebar_path = '../includes/sidebar.php';
                if (file_exists($sidebar_path)) {
                    include $sidebar_path;
                }
                ?>
    </nav>
    
    <!-- Main content area -->
    <main class="">
                <!-- Page Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    
                
                <div>
                        <h1 class="h2">
                            <i class="<?php echo $pageIcon; ?> me-2"></i>
                            <?php echo htmlspecialchars($pageTitle); ?>
                        </h1>
                        <p class="text-muted mb-0">
                            Division: <strong><?php echo htmlspecialchars($office_name); ?></strong> 
                            (<?php echo htmlspecialchars($division_code); ?>)
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
                
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle me-2"></i>
                        <?php echo htmlspecialchars($_GET['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Report Navigation -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-clipboard-data me-2"></i> Report Types</h5>
                    </div>
                    <div class="card-body p-2">
                        <div class="nav-scrollable">
                            <nav class="nav nav-pills nav-fill">
                                <?php foreach ($report_types as $key => $name): ?>
                                    <a class="nav-link <?php echo ($report_type === $key) ? 'active' : ''; ?>" 
                                       href="?report=<?php echo $key; ?><?php echo !empty($gn_filter) && $gn_filter !== 'all' ? '&gn=' . urlencode($gn_filter) : ''; ?>">
                                        <i class="bi bi-<?php 
                                            $icons = [
                                                'overview' => 'speedometer2',
                                                'population' => 'people',
                                                'family' => 'house-door',
                                                'demographic' => 'globe2',
                                                'education' => 'mortarboard',
                                                'employment' => 'briefcase',
                                                'health' => 'heart-pulse',
                                                'age' => 'calendar',
                                                'gender' => 'gender-ambiguous',
                                                'comparison' => 'bar-chart',
                                                'trends' => 'graph-up',
                                                'monthly' => 'calendar-month'
                                            ];
                                            echo $icons[$key] ?? 'graph-up';
                                        ?> me-1"></i>
                                        <?php echo $name; ?>
                                    </a>
                                <?php endforeach; ?>
                            </nav>
                        </div>
                    </div>
                </div>
                
                <!-- Report Filters -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-funnel me-2"></i> Report Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3" id="filterForm">
                            <input type="hidden" name="report" value="<?php echo htmlspecialchars($report_type); ?>">
                            
                            <div class="col-md-3">
                                <label class="form-label">Year</label>
                                <select class="form-select" name="year" id="yearSelect">
                                    <?php for ($y = 2020; $y <= date('Y'); $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($year == $y) ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <?php if ($report_type === 'monthly'): ?>
                            <div class="col-md-3">
                                <label class="form-label">Month</label>
                                <select class="form-select" name="month" id="monthSelect">
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
                            <?php endif; ?>
                            
                            <div class="col-md-3">
                                <label class="form-label">Quarter</label>
                                <select class="form-select" name="quarter" id="quarterSelect">
                                    <option value="1" <?php echo ($quarter == 1) ? 'selected' : ''; ?>>Q1 (Jan-Mar)</option>
                                    <option value="2" <?php echo ($quarter == 2) ? 'selected' : ''; ?>>Q2 (Apr-Jun)</option>
                                    <option value="3" <?php echo ($quarter == 3) ? 'selected' : ''; ?>>Q3 (Jul-Sep)</option>
                                    <option value="4" <?php echo ($quarter == 4) ? 'selected' : ''; ?>>Q4 (Oct-Dec)</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">GN Division</label>
                                <select class="form-select" name="gn" id="gnSelect">
                                    <option value="all" <?php echo ($gn_filter === 'all') ? 'selected' : ''; ?>>All GN Divisions</option>
                                    <?php foreach ($gn_divisions as $gn): ?>
                                        <option value="<?php echo htmlspecialchars($gn['office_code'] ?? $gn['gn_id']); ?>" 
                                                <?php echo ($gn_filter === ($gn['office_code'] ?? $gn['gn_id'])) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($gn['office_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-filter"></i> Apply Filters
                                </button>
                                <a href="reports_division.php?report=<?php echo htmlspecialchars($report_type); ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Quick Stats Dashboard -->
                <?php if ($report_type === 'overview' && isset($quick_stats)): ?>
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-white bg-primary h-100 stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Total GN Divisions</h6>
                                        <h2 class="mb-0"><?php echo safe_number_format($quick_stats['total_gn'] ?? 0); ?></h2>
                                    </div>
                                    <i class="bi bi-geo-alt display-6 opacity-50"></i>
                                </div>
                                <small class="opacity-75">Under this division</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-white bg-success h-100 stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Total Families</h6>
                                        <h2 class="mb-0"><?php echo safe_number_format($quick_stats['total_families'] ?? 0); ?></h2>
                                    </div>
                                    <i class="bi bi-house-door display-6 opacity-50"></i>
                                </div>
                                <small class="opacity-75">Across all GN divisions</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-white bg-warning h-100 stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Total Population</h6>
                                        <h2 class="mb-0"><?php echo safe_number_format($quick_stats['total_population'] ?? 0); ?></h2>
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
                                        <h2 class="mb-0"><?php echo safe_number_format($quick_stats['avg_family_size'] ?? 0, 1); ?></h2>
                                    </div>
                                    <i class="bi bi-person-bounding-box display-6 opacity-50"></i>
                                </div>
                                <small class="opacity-75">Members per family</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Top Performing GN Divisions -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="bi bi-trophy me-2"></i> Top Performing GN Divisions</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>GN Division</th>
                                                <th>Families</th>
                                                <th>Population</th>
                                                <th>Completeness</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (isset($quick_stats['top_gn']) && is_array($quick_stats['top_gn'])): 
                                                $counter = 1;
                                                foreach ($quick_stats['top_gn'] as $gn): ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td><?php echo htmlspecialchars($gn['office_name'] ?? ''); ?></td>
                                                <td><?php echo safe_number_format($gn['families'] ?? 0); ?></td>
                                                <td><?php echo safe_number_format($gn['population'] ?? 0); ?></td>
                                                <td>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar bg-success" 
                                                             role="progressbar" 
                                                             style="width: <?php echo min(100, $gn['completeness'] ?? 0); ?>%">
                                                        </div>
                                                    </div>
                                                    <small><?php echo safe_number_format($gn['completeness'] ?? 0, 1); ?>%</small>
                                                </td>
                                            </tr>
                                            <?php endforeach; 
                                            else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">No data available</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i> Recent Activity</h6>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php if (!empty($recent_activities)): ?>
                                        <?php foreach ($recent_activities as $activity): ?>
                                        <div class="list-group-item border-0 px-0">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($activity['title']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($activity['time_ago']); ?></small>
                                            </div>
                                            <p class="mb-1 small"><?php echo htmlspecialchars($activity['description']); ?></p>
                                            <small class="text-muted"><?php echo htmlspecialchars($activity['gn_name'] ?? ''); ?></small>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-3">
                                            <i class="bi bi-info-circle text-muted"></i>
                                            <p class="text-muted mb-0">No recent activity</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Main Report Content -->
                <div class="row">
                    <!-- Report Statistics -->
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-bar-chart me-2"></i>
                                    <?php echo htmlspecialchars($report_title); ?>
                                    <?php if ($gn_filter !== 'all'): ?>
                                        <small class="fw-normal">
                                            - <?php 
                                                foreach ($gn_divisions as $gn) {
                                                    if (($gn['office_code'] ?? $gn['gn_id']) === $gn_filter) {
                                                        echo htmlspecialchars($gn['office_name']);
                                                        break;
                                                    }
                                                }
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </h5>
                                <span class="badge bg-light text-dark">
                                    <?php echo ($gn_filter === 'all') ? 'All GN Divisions' : 'Single GN Division'; ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($report_data) && is_array($report_data) || ($report_type === 'comparison' && !empty($comparison_data))): ?>
                                    <?php if ($report_type === 'comparison' && !empty($comparison_data)): ?>
                                        <!-- Comparison Table -->
                                        <div class="table-responsive">
                                            <table class="table table-hover table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>GN Division</th>
                                                        <th>Families</th>
                                                        <th>Population</th>
                                                        <th>Avg Family Size</th>
                                                        <th>Gender Ratio (M:F)</th>
                                                        <th>Completeness</th>
                                                        <th>Rank</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($comparison_data as $gn): ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?php echo htmlspecialchars($gn['office_name'] ?? ''); ?></strong><br>
                                                                <small class="text-muted"><?php echo htmlspecialchars($gn['gn_id'] ?? $gn['office_code'] ?? ''); ?></small>
                                                            </td>
                                                            <td><?php echo safe_number_format($gn['families'] ?? 0); ?></td>
                                                            <td><?php echo safe_number_format($gn['population'] ?? 0); ?></td>
                                                            <td><?php echo safe_number_format($gn['avg_family_size'] ?? 0, 1); ?></td>
                                                            <td>
                                                                <?php 
                                                                $male_percent = $gn['male_percent'] ?? 0;
                                                                $female_percent = $gn['female_percent'] ?? 0;
                                                                echo safe_number_format($male_percent, 1) . ':' . safe_number_format($female_percent, 1);
                                                                ?>
                                                            </td>
                                                            <td>
                                                                <?php $completeness = $gn['completeness'] ?? 0; ?>
                                                                <div class="progress" style="height: 8px;">
                                                                    <div class="progress-bar bg-<?php echo ($completeness >= 80) ? 'success' : (($completeness >= 60) ? 'warning' : 'danger'); ?>" 
                                                                         role="progressbar" 
                                                                         style="width: <?php echo min(100, $completeness); ?>%">
                                                                    </div>
                                                                </div>
                                                                <small><?php echo safe_number_format($completeness, 1); ?>%</small>
                                                            </td>
                                                            <td>
                                                                <?php $rank = $gn['rank'] ?? 0; ?>
                                                                <span class="badge bg-<?php 
                                                                    echo ($rank <= 3) ? 'success' : 
                                                                         (($rank <= 6) ? 'warning' : 'secondary');
                                                                ?>">
                                                                    #<?php echo $rank; ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <!-- Standard Report Table -->
                                        <div class="table-responsive">
                                            <table class="table table-hover table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Category</th>
                                                        <th>Count</th>
                                                        <th>Percentage</th>
                                                        <th>Distribution</th>
                                                        <?php if ($gn_filter === 'all'): ?>
                                                        <th>Average per GN</th>
                                                        <?php endif; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (!empty($report_data) && is_array($report_data)): ?>
                                                        <?php foreach ($report_data as $category => $data): 
                                                            if (is_array($data) && isset($data['count'])): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($category); ?></td>
                                                                    <td>
                                                                        <strong><?php echo safe_number_format($data['count']); ?></strong>
                                                                    </td>
                                                                    <td>
                                                                        <?php if (isset($data['percentage'])): ?>
                                                                            <span class="badge bg-info"><?php echo safe_percentage_format($data['percentage']); ?></span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td>
                                                                        <?php if (isset($data['percentage'])): ?>
                                                                        <div class="progress" style="height: 6px; width: 100px;">
                                                                            <div class="progress-bar" 
                                                                                 role="progressbar" 
                                                                                 style="width: <?php echo min(100, $data['percentage']); ?>%">
                                                                            </div>
                                                                        </div>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <?php if ($gn_filter === 'all'): ?>
                                                                    <td>
                                                                        <?php if (isset($data['avg_per_gn'])): ?>
                                                                            <?php echo safe_number_format($data['avg_per_gn'], 1); ?>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <?php endif; ?>
                                                                </tr>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr>
                                                            <td colspan="5" class="text-center text-muted">No data available for this report</td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="bi bi-bar-chart display-1 text-muted mb-3"></i>
                                        <h4>No Data Available</h4>
                                        <p class="text-muted">There is no data available for this report.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($report_data) || !empty($comparison_data)): ?>
                            <div class="card-footer text-muted">
                                <small>
                                    <i class="bi bi-info-circle"></i> 
                                    Report generated for: 
                                    <?php if ($gn_filter === 'all'): ?>
                                        All GN Divisions in <?php echo htmlspecialchars($office_name); ?>
                                    <?php else: ?>
                                        <?php 
                                        $gn_name = 'Selected GN Division';
                                        foreach ($gn_divisions as $gn) {
                                            if (($gn['office_code'] ?? $gn['gn_id']) === $gn_filter) {
                                                $gn_name = $gn['office_name'];
                                                break;
                                            }
                                        }
                                        echo htmlspecialchars($gn_name);
                                        ?>
                                    <?php endif; ?>
                                    | Generated: <?php echo date('d M Y, h:i A'); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Chart Visualization -->
                        <?php if (!empty($chart_data) && is_array($chart_data)): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i> Visual Representation</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="reportChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Sidebar - Additional Info -->
                    <div class="col-lg-4">
                        <!-- GN Division Summary -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i> GN Division Summary</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush">
                                    <?php if (!empty($gn_divisions)): ?>
                                        <?php foreach ($gn_divisions as $gn): 
                                            $gn_code = $gn['office_code'] ?? $gn['gn_id'];
                                        ?>
                                        <a href="?report=<?php echo urlencode($report_type); ?>&gn=<?php echo urlencode($gn_code); ?>" 
                                           class="list-group-item list-group-item-action <?php echo ($gn_filter === $gn_code) ? 'active' : ''; ?>">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($gn['office_name']); ?></h6>
                                                <small class="<?php echo ($gn_filter === $gn_code) ? '' : 'text-muted'; ?>">
                                                    <?php echo safe_number_format($gn['families'] ?? 0); ?> families
                                                </small>
                                            </div>
                                            <p class="mb-1 small <?php echo ($gn_filter === $gn_code) ? '' : 'text-muted'; ?>">
                                                <i class="bi bi-people"></i> <?php echo safe_number_format($gn['population'] ?? 0); ?> people
                                            </p>
                                            <small class="<?php echo ($gn_filter === $gn_code) ? '' : 'text-muted'; ?>">
                                                ID: <?php echo htmlspecialchars($gn_code); ?>
                                            </small>
                                        </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="list-group-item text-center text-muted">
                                            No GN divisions found
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-footer text-center">
                                <a href="?report=<?php echo urlencode($report_type); ?>&gn=all" 
                                   class="btn btn-sm btn-outline-primary <?php echo ($gn_filter === 'all') ? 'active' : ''; ?>">
                                    Show All GN Divisions
                                </a>
                            </div>
                        </div>
                        
                        <!-- Key Insights -->
                        <div class="card mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="bi bi-lightbulb me-2"></i> Key Insights</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <?php if (isset($quick_stats)): ?>
                                        <li class="mb-2">
                                            <i class="bi bi-check-circle-fill text-success me-2"></i>
                                            <strong><?php echo safe_number_format($quick_stats['total_gn'] ?? 0); ?></strong> GN divisions
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-check-circle-fill text-success me-2"></i>
                                            <strong><?php echo safe_number_format($quick_stats['total_families'] ?? 0); ?></strong> total families
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-check-circle-fill text-success me-2"></i>
                                            <strong><?php echo safe_number_format($quick_stats['total_population'] ?? 0); ?></strong> total population
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-check-circle-fill text-success me-2"></i>
                                            Average family size: <strong><?php echo safe_number_format($quick_stats['avg_family_size'] ?? 0, 1); ?></strong>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-info-circle-fill text-info me-2"></i>
                                            Data completeness: <strong><?php echo safe_percentage_format($quick_stats['data_completeness'] ?? 0); ?></strong>
                                        </li>
                                    <?php endif; ?>
                                    <li class="mb-2">
                                        <i class="bi bi-info-circle-fill text-info me-2"></i>
                                        Report generated: <?php echo date('d M Y, h:i A'); ?>
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-info-circle-fill text-info me-2"></i>
                                        Data as of: <?php echo date('d M Y'); ?>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        
                        <!-- Report Summary -->
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-file-earmark-ppt me-2"></i> Report Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <h6>Report Type:</h6>
                                    <p class="mb-0"><?php echo htmlspecialchars($report_title); ?></p>
                                </div>
                                <div class="mb-3">
                                    <h6>Scope:</h6>
                                    <p class="mb-0">
                                        <?php echo ($gn_filter === 'all') ? 'All GN Divisions' : 'Single GN Division'; ?>
                                    </p>
                                </div>
                                <div class="mb-3">
                                    <h6>Period:</h6>
                                    <p class="mb-0">
                                        <?php 
                                        if ($report_type === 'monthly') {
                                            echo date('F Y', strtotime("$year-$month-01"));
                                        } else {
                                            echo 'Year: ' . $year . ' | Q' . $quarter;
                                        }
                                        ?>
                                    </p>
                                </div>
                                <div class="mb-3">
                                    <h6>Generated By:</h6>
                                    <p class="mb-0"><?php echo htmlspecialchars($username); ?></p>
                                </div>
                                <div>
                                    <h6>Division:</h6>
                                    <p class="mb-0"><?php echo htmlspecialchars($office_name); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Report Notes -->
                <div class="card mt-4">
                    <div class="card-header bg-light text-dark">
                        <h6 class="mb-0"><i class="bi bi-chat-square-text me-2"></i> Report Notes</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Data Sources:</h6>
                                <ul class="small">
                                    <li>GN Division Family Registrations</li>
                                    <li>Citizen Information Database</li>
                                    <li>Education Records System</li>
                                    <li>Employment Registry</li>
                                    <li>Health Records Database</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Report Limitations:</h6>
                                <ul class="small">
                                    <li>Data is aggregated from all GN divisions</li>
                                    <li>Updates are processed nightly</li>
                                    <li>Historical data available from 2020</li>
                                    <li>Report generation time: <?php echo date('H:i:s'); ?></li>
                                    <li>Data completeness varies by GN division</li>
                                </ul>
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
            // Initialize Chart
            <?php if (!empty($chart_data) && is_array($chart_data)): ?>
            initializeChart(<?php echo json_encode($chart_data); ?>);
            <?php endif; ?>
            
            // Auto-hide alerts
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Update form based on scope selection
            const scopeSelect = document.querySelector('select[name="gn_scope"]');
            const gnCheckboxes = document.querySelectorAll('input[name="selected_gn[]"]');
            
            if (scopeSelect) {
                scopeSelect.addEventListener('change', function() {
                    const isSelected = this.value === 'selected';
                    gnCheckboxes.forEach(checkbox => {
                        checkbox.disabled = !isSelected;
                        if (!isSelected) {
                            checkbox.checked = false;
                        }
                    });
                });
                
                // Trigger change on load
                scopeSelect.dispatchEvent(new Event('change'));
            }
        });
        
        // Chart initialization
        function initializeChart(chartData) {
            const ctx = document.getElementById('reportChart').getContext('2d');
            
            // Determine chart type based on data
            let chartType = 'bar';
            
            if (chartData.labels && chartData.datasets) {
                // Already formatted for Chart.js
                new Chart(ctx, {
                    type: chartData.type || 'bar',
                    data: chartData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            title: {
                                display: true,
                                text: chartData.title || '<?php echo htmlspecialchars($report_title); ?>'
                            }
                        },
                        scales: chartData.scales || {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            } else if (Array.isArray(chartData)) {
                // Simple array of values
                chartType = 'pie';
                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: chartData.map(item => item.label || item.category),
                        datasets: [{
                            data: chartData.map(item => item.value || item.count || 0),
                            backgroundColor: [
                                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                                '#9966FF', '#FF9F40', '#8AC926', '#1982C4',
                                '#6A0572', '#3A7D44'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            title: {
                                display: true,
                                text: '<?php echo htmlspecialchars($report_title); ?> Distribution'
                            }
                        }
                    }
                });
            }
        }
        
        // Export report
        function exportReport() {
            const table = document.querySelector('.table');
            if (!table) {
                alert('No table data to export');
                return;
            }
            
            const ws = XLSX.utils.table_to_sheet(table);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Division Report");
            
            const filename = 'division-report-<?php echo $report_type; ?>-<?php echo date("Y-m-d"); ?>.xlsx';
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