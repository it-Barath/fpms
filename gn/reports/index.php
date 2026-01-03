<?php
// users/gn/reports/index.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Reports Dashboard";
$pageIcon = "bi bi-graph-up";
$pageDescription = "Generate and view statistical reports for your GN division";
$bodyClass = "bg-light";

try {
    require_once '../../../config.php';
    require_once '../../../classes/Auth.php';
    require_once '../../../classes/Sanitizer.php';
    require_once '../../../classes/ReportGenerator.php';
    
    // Check if session is already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in and has GN level access
    if (!$auth->isLoggedIn()) {
        header('Location: ../../../login.php');
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
    $office_name = $_SESSION['office_name'] ?? '';
    
    // Initialize classes
    $sanitizer = new Sanitizer();
    $reportGenerator = new ReportGenerator($db);
    
    // Get report parameters from GET
    $report_type = $_GET['report'] ?? 'overview';
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : ceil(date('n') / 3);
    
    // Validate parameters
    $year = $sanitizer->sanitizeNumber($year, 2020, 2030);
    $month = $sanitizer->sanitizeNumber($month, 1, 12);
    $quarter = $sanitizer->sanitizeNumber($quarter, 1, 4);
    
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
        'monthly' => 'Monthly Report'
    ];
    
    // Generate report based on type
    $report_data = [];
    $report_title = $report_types[$report_type] ?? 'Overview Dashboard';
    $chart_data = [];
    
    switch ($report_type) {
        case 'overview':
            $report_data = $reportGenerator->getOverviewStats($gn_id);
            $chart_data = $reportGenerator->getMonthlyRegistrationTrend($gn_id, $year);
            break;
            
        case 'population':
            $report_data = $reportGenerator->getPopulationStats($gn_id);
            $chart_data = $reportGenerator->getPopulationPyramid($gn_id);
            break;
            
        case 'family':
            $report_data = $reportGenerator->getFamilyStats($gn_id);
            $chart_data = $reportGenerator->getFamilySizeDistribution($gn_id);
            break;
            
        case 'demographic':
            $report_data = $reportGenerator->getDemographicStats($gn_id);
            $chart_data = $reportGenerator->getReligionDistribution($gn_id);
            break;
            
        case 'education':
            $report_data = $reportGenerator->getEducationStats($gn_id);
            $chart_data = $reportGenerator->getEducationLevelDistribution($gn_id);
            break;
            
        case 'employment':
            $report_data = $reportGenerator->getEmploymentStats($gn_id);
            $chart_data = $reportGenerator->getEmploymentTypeDistribution($gn_id);
            break;
            
        case 'health':
            $report_data = $reportGenerator->getHealthStats($gn_id);
            $chart_data = $reportGenerator->getHealthConditionDistribution($gn_id);
            break;
            
        case 'age':
            $report_data = $reportGenerator->getAgeGroupStats($gn_id);
            $chart_data = $reportGenerator->getAgeGroupDistribution($gn_id);
            break;
            
        case 'gender':
            $report_data = $reportGenerator->getGenderStats($gn_id);
            $chart_data = $reportGenerator->getGenderRatioChart($gn_id);
            break;
            
        case 'monthly':
            $report_data = $reportGenerator->getMonthlyReport($gn_id, $year, $month);
            $chart_data = $reportGenerator->getMonthlyComparison($gn_id, $year, $month);
            break;
            
        default:
            $report_data = $reportGenerator->getOverviewStats($gn_id);
            $chart_data = $reportGenerator->getMonthlyRegistrationTrend($gn_id, $year);
            break;
    }
    
    // Get recent activities
    $recent_activities = $reportGenerator->getRecentActivities($gn_id, 10);
    
    // Get quick stats
    $quick_stats = $reportGenerator->getQuickStats($gn_id);

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Reports Dashboard Error: " . $e->getMessage());
}
?>

<?php require_once '../../../includes/header.php'; ?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../../../includes/sidebar.php'; ?>
        </div>
        
        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-xl-10 px-md-4 main-content">
            <!-- Page Header -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1 class="h2">
                        <i class="bi bi-graph-up me-2"></i>
                        Reports Dashboard
                    </h1>
                    <p class="text-muted mb-0">
                        GN Division: <strong><?php echo htmlspecialchars($office_name); ?></strong> 
                        (<?php echo htmlspecialchars($gn_id); ?>)
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
                    <a href="../dashboard.php" class="btn btn-outline-secondary">
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
                                   href="?report=<?php echo $key; ?>">
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
            
            <!-- Date/Period Selector -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-calendar me-2"></i> Report Period</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3" id="periodForm">
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
                        
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-filter"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Quick Stats Dashboard -->
            <?php if ($report_type === 'overview' && isset($quick_stats)): ?>
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title">Total Families</h6>
                                    <h2 class="mb-0"><?php echo $quick_stats['total_families'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-house-door display-6 opacity-50"></i>
                            </div>
                            <small class="opacity-75">Registered in GN</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title">Total Population</h6>
                                    <h2 class="mb-0"><?php echo $quick_stats['total_population'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-people display-6 opacity-50"></i>
                            </div>
                            <small class="opacity-75">Citizens registered</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card text-white bg-warning h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title">Avg Family Size</h6>
                                    <h2 class="mb-0"><?php echo number_format($quick_stats['avg_family_size'] ?? 0, 1); ?></h2>
                                </div>
                                <i class="bi bi-person-bounding-box display-6 opacity-50"></i>
                            </div>
                            <small class="opacity-75">Members per family</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title">This Month</h6>
                                    <h2 class="mb-0"><?php echo $quick_stats['this_month_registrations'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-calendar-plus display-6 opacity-50"></i>
                            </div>
                            <small class="opacity-75">New registrations</small>
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
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-bar-chart me-2"></i>
                                <?php echo htmlspecialchars($report_title); ?> Report
                                <small class="fw-normal">- <?php echo date('F Y'); ?></small>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($report_data) && is_array($report_data)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Count</th>
                                                <th>Percentage</th>
                                                <th>Trend</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data as $category => $data): 
                                                if (is_array($data) && isset($data['count'])): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($category); ?></td>
                                                        <td>
                                                            <strong><?php echo number_format($data['count']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <?php if (isset($data['percentage'])): ?>
                                                                <span class="badge bg-info"><?php echo number_format($data['percentage'], 1); ?>%</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (isset($data['trend'])): 
                                                                $trend_icon = $data['trend'] > 0 ? 'arrow-up' : ($data['trend'] < 0 ? 'arrow-down' : 'dash');
                                                                $trend_color = $data['trend'] > 0 ? 'success' : ($data['trend'] < 0 ? 'danger' : 'secondary');
                                                            ?>
                                                                <span class="badge bg-<?php echo $trend_color; ?>">
                                                                    <i class="bi bi-<?php echo $trend_icon; ?>"></i>
                                                                    <?php echo abs($data['trend']); ?>%
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-bar-chart display-1 text-muted mb-3"></i>
                                    <h4>No Data Available</h4>
                                    <p class="text-muted">There is no data available for this report.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Chart Visualization -->
                    <?php if (!empty($chart_data) && is_array($chart_data)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i> Visual Representation</h5>
                        </div>
                        <div class="card-body">
                            <div id="chartContainer" style="height: 400px;">
                                <!-- Chart will be rendered here -->
                                <canvas id="reportChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Sidebar - Additional Info -->
                <div class="col-lg-4">
                    <!-- Recent Activities -->
                    <?php if (!empty($recent_activities)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i> Recent Activities</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($recent_activities as $activity): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($activity['title']); ?></h6>
                                        <small class="text-muted"><?php echo $activity['time_ago']; ?></small>
                                    </div>
                                    <p class="mb-1 small"><?php echo htmlspecialchars($activity['description']); ?></p>
                                    <small class="text-muted"><?php echo $activity['details']; ?></small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Key Insights -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-lightbulb me-2"></i> Key Insights</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <?php if (isset($quick_stats)): ?>
                                    <li class="mb-2">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        <strong><?php echo $quick_stats['total_families'] ?? 0; ?></strong> families registered
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        <strong><?php echo $quick_stats['total_population'] ?? 0; ?></strong> total population
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        Average family size: <strong><?php echo number_format($quick_stats['avg_family_size'] ?? 0, 1); ?></strong>
                                    </li>
                                    <li class="mb-2">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        <strong><?php echo $quick_stats['this_month_registrations'] ?? 0; ?></strong> new registrations this month
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
                    
                    <!-- Quick Actions -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-lightning me-2"></i> Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="summary_report.php" class="btn btn-outline-primary">
                                    <i class="bi bi-file-text"></i> Summary Report
                                </a>
                                <a href="detailed_report.php" class="btn btn-outline-success">
                                    <i class="bi bi-file-earmark-text"></i> Detailed Report
                                </a>
                                <a href="comparison_report.php" class="btn btn-outline-info">
                                    <i class="bi bi-arrow-left-right"></i> Comparison Report
                                </a>
                                <button class="btn btn-outline-warning" onclick="generateCustomReport()">
                                    <i class="bi bi-gear"></i> Custom Report
                                </button>
                            </div>
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
                                <h6>GN Division:</h6>
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
                                <li>Family Registration Database</li>
                                <li>Citizen Information System</li>
                                <li>Education Records</li>
                                <li>Employment Records</li>
                                <li>Health Records</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Report Limitations:</h6>
                            <ul class="small">
                                <li>Data is updated daily at midnight</li>
                                <li>Only includes registered citizens</li>
                                <li>Historical data available from 2020</li>
                                <li>Report generation time: <?php echo date('H:i:s'); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Custom Report Modal -->
<div class="modal fade" id="customReportModal" tabindex="-1" aria-labelledby="customReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="customReportModalLabel">
                    <i class="bi bi-gear me-2"></i> Generate Custom Report
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="customReportForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Report Type</label>
                            <select class="form-select" name="custom_report_type" required>
                                <option value="">Select report type...</option>
                                <option value="summary">Summary Report</option>
                                <option value="detailed">Detailed Analysis</option>
                                <option value="comparison">Comparison Report</option>
                                <option value="trend">Trend Analysis</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Time Period</label>
                            <select class="form-select" name="time_period" required>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                                <option value="custom">Custom Range</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Include Data Categories</label>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categories[]" value="population" checked>
                                    <label class="form-check-label">Population Data</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categories[]" value="demographic" checked>
                                    <label class="form-check-label">Demographic Data</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categories[]" value="education">
                                    <label class="form-check-label">Education Data</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categories[]" value="employment">
                                    <label class="form-check-label">Employment Data</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categories[]" value="health">
                                    <label class="form-check-label">Health Data</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categories[]" value="family">
                                    <label class="form-check-label">Family Data</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Output Format</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="format" value="html" checked>
                            <label class="form-check-label">Web View</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="format" value="pdf">
                            <label class="form-check-label">PDF Document</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="format" value="excel">
                            <label class="form-check-label">Excel Spreadsheet</label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitCustomReport()">
                    <i class="bi bi-gear"></i> Generate Report
                </button>
            </div>
        </div>
    </div>
</div>

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
    }
    .list-group-item {
        border-left: none;
        border-right: none;
    }
    .list-group-item:first-child {
        border-top: none;
    }
    .list-group-item:last-child {
        border-bottom: none;
    }
    @media print {
        .sidebar-column, .btn-toolbar, .nav, .modal {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
        }
        .card {
            border: 1px solid #dee2e6 !important;
            box-shadow: none !important;
        }
    }
</style>

<script src="../../../assets/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Chart
        <?php if (!empty($chart_data) && is_array($chart_data)): ?>
        initializeChart(<?php echo json_encode($chart_data); ?>);
        <?php endif; ?>
        
        // Update form based on period selection
        const periodSelect = document.querySelector('select[name="time_period"]');
        if (periodSelect) {
            periodSelect.addEventListener('change', function() {
                const startDate = document.querySelector('input[name="start_date"]');
                const endDate = document.querySelector('input[name="end_date"]');
                
                if (this.value === 'monthly') {
                    startDate.value = '<?php echo date("Y-m-01"); ?>';
                    endDate.value = '<?php echo date("Y-m-d"); ?>';
                } else if (this.value === 'quarterly') {
                    const quarter = Math.floor((new Date().getMonth() + 3) / 3);
                    const startMonth = (quarter - 1) * 3 + 1;
                    const year = new Date().getFullYear();
                    startDate.value = year + '-' + startMonth.toString().padStart(2, '0') + '-01';
                    endDate.value = '<?php echo date("Y-m-d"); ?>';
                } else if (this.value === 'yearly') {
                    const year = new Date().getFullYear();
                    startDate.value = year + '-01-01';
                    endDate.value = '<?php echo date("Y-m-d"); ?>';
                }
            });
        }
        
        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
    
    // Chart initialization
    function initializeChart(chartData) {
        const ctx = document.getElementById('reportChart').getContext('2d');
        
        // Determine chart type based on data
        let chartType = 'bar';
        let datasets = [];
        
        if (chartData.labels && chartData.datasets) {
            // Already formatted for Chart.js
            datasets = chartData.datasets;
        } else if (Array.isArray(chartData)) {
            // Simple array of values
            chartType = 'pie';
            datasets = [{
                data: chartData.map(item => item.value || item.count || 0),
                backgroundColor: [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                    '#9966FF', '#FF9F40', '#8AC926', '#1982C4'
                ]
            }];
        }
        
        new Chart(ctx, {
            type: chartType,
            data: {
                labels: chartData.labels || chartData.map(item => item.label || item.category),
                datasets: datasets.length > 0 ? datasets : [{
                    label: 'Data',
                    data: chartData.map(item => item.value || item.count || 0),
                    backgroundColor: '#36A2EB',
                    borderColor: '#1E88E5',
                    borderWidth: 1
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
    
    // Export report
    function exportReport() {
        const table = document.querySelector('.table');
        const ws = XLSX.utils.table_to_sheet(table);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Report");
        
        const filename = 'report-<?php echo $report_type; ?>-<?php echo date("Y-m-d"); ?>.xlsx';
        XLSX.writeFile(wb, filename);
    }
    
    // Generate custom report
    function generateCustomReport() {
        const modal = new bootstrap.Modal(document.getElementById('customReportModal'));
        modal.show();
    }
    
    // Submit custom report
    function submitCustomReport() {
        const form = document.getElementById('customReportForm');
        const formData = new FormData(form);
        
        // Validate form
        if (!formData.get('custom_report_type')) {
            alert('Please select a report type');
            return;
        }
        
        // Submit via AJAX or redirect
        const params = new URLSearchParams(formData).toString();
        window.location.href = 'custom_report.php?' + params;
    }
</script>  

<?php 
// Include footer if exists
$footer_path = '../../../includes/footer.php';
if (file_exists($footer_path)) {
    include $footer_path;
} else {
    echo '</div></div></body></html>';
}
?>