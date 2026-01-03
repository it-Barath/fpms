<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Database.php';

// Check if user is logged in as District Secretariat
if (!isset($_SESSION['user_id']) || $_SESSION['office_type'] !== 'DISTRICT') {  
    header('Location: ../login.php');
    exit();
}

// Get district information
$district_name = $_SESSION['office_identifier'];
$user_id = $_SESSION['user_id'];

// Initialize report type
$report_type = isset($_GET['report_type']) ? sanitizeInput($_GET['report_type']) : 'family_summary';
$format = isset($_GET['format']) ? sanitizeInput($_GET['format']) : 'html';
$action = isset($_POST['action']) ? sanitizeInput($_POST['action']) : '';

// Date range filters
$date_from = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : date('Y-m-d');
$division_filter = isset($_GET['division']) ? sanitizeInput($_GET['division']) : '';
$gn_filter = isset($_GET['gn']) ? sanitizeInput($_GET['gn']) : '';

// Get all divisions under this district
$divisions = getDivisionsByDistrict($district_name);

// Get GN Divisions based on filters
$gn_divisions = array();
if ($division_filter) {
    $gn_divisions = getGNDivisionsByDivision($division_filter);
}

// Report data
$report_data = array();
$report_title = '';
$report_headers = array();
$report_footer = '';

// Process report generation
if ($action === 'generate_report' || $format !== 'html') {
    try {
        $db = Database::getInstance();
        
        switch ($report_type) {
            case 'family_summary':
                $report_title = "Family Summary Report - $district_name District";
                $report_data = generateFamilySummaryReport($db, $district_name, $division_filter, $gn_filter, $date_from, $date_to);
                $report_headers = ['Division', 'GN Division', 'Family ID', 'Head of Family', 'Address', 'Members', 'Registered Date', 'Status'];
                break;
                
            case 'population_demographics':
                $report_title = "Population Demographics Report - $district_name District";
                $report_data = generatePopulationDemographicsReport($db, $district_name, $division_filter, $gn_filter);
                $report_headers = ['Age Group', 'Male', 'Female', 'Total', 'Percentage'];
                break;
                
            case 'education_levels':
                $report_title = "Education Levels Report - $district_name District";
                $report_data = generateEducationLevelsReport($db, $district_name, $division_filter, $gn_filter);
                $report_headers = ['Education Level', 'Male', 'Female', 'Total', 'Percentage'];
                break;
                
            case 'employment_status':
                $report_title = "Employment Status Report - $district_name District";
                $report_data = generateEmploymentStatusReport($db, $district_name, $division_filter, $gn_filter);
                $report_headers = ['Employment Type', 'Count', 'Percentage', 'Avg Income (Rs.)', 'Total Income (Rs.)'];
                break;
                
            case 'health_conditions':
                $report_title = "Health Conditions Report - $district_name District";
                $report_data = generateHealthConditionsReport($db, $district_name, $division_filter, $gn_filter);
                $report_headers = ['Condition', 'Cases', 'Male', 'Female', 'Prevalence per 10,000'];
                break;
                
            case 'land_ownership':
                $report_title = "Land Ownership Report - $district_name District";
                $report_data = generateLandOwnershipReport($db, $district_name, $division_filter, $gn_filter);
                $report_headers = ['Land Type', 'Count', 'Total Area (Perches)', 'Avg Area (Perches)', '% of Total'];
                break;
                
            case 'citizen_details':
                $report_title = "Citizen Details Report - $district_name District";
                $report_data = generateCitizenDetailsReport($db, $district_name, $division_filter, $gn_filter, $date_from, $date_to);
                $report_headers = ['Citizen ID', 'Name', 'NIC/ID', 'Date of Birth', 'Gender', 'Address', 'Phone', 'Family ID'];
                break;
                
            case 'new_registrations':
                $report_title = "New Registrations Report - $district_name District";
                $report_data = generateNewRegistrationsReport($db, $district_name, $division_filter, $gn_filter, $date_from, $date_to);
                $report_headers = ['Date', 'Division', 'GN Division', 'Families', 'Citizens', 'Avg Family Size'];
                break;
                
            case 'transfer_history':
                $report_title = "Family Transfer History Report - $district_name District";
                $report_data = generateTransferHistoryReport($db, $district_name, $division_filter, $date_from, $date_to);
                $report_headers = ['Transfer Date', 'Family ID', 'From GN', 'To GN', 'Reason'];
                break;
                
            default:
                $report_title = "Family Summary Report - $district_name District";
                $report_data = generateFamilySummaryReport($db, $district_name, $division_filter, $gn_filter, $date_from, $date_to);
                $report_headers = ['Division', 'GN Division', 'Family ID', 'Head of Family', 'Address', 'Members', 'Registered Date', 'Status'];
        }
        
        // Log report generation
        logActivity('GENERATE_REPORT', "$report_type report generated for $district_name", $user_id);
        
        // Export if requested
        if ($format !== 'html') {
            exportReport($report_type, $report_data, $report_headers, $report_title, $format);
            exit();
        }
        
    } catch (Exception $e) {
        $error = "Error generating report: " . $e->getMessage();
        logActivity('REPORT_ERROR', "Error generating $report_type report: " . $e->getMessage(), $user_id);
    }
}

// Helper functions for different reports
function generateFamilySummaryReport($db, $district_name, $division_filter, $gn_filter, $date_from, $date_to) {
    $sql = "SELECT 
                ws.Division_Name,
                ws.GN,
                f.family_id,
                c.name as head_name,
                f.address,
                COUNT(DISTINCT c2.citizen_id) as members,
                DATE(f.registration_date) as reg_date,
                f.status
            FROM families f
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            LEFT JOIN citizens c ON f.family_head_id = c.citizen_id
            LEFT JOIN citizens c2 ON f.family_id = c2.family_id AND c2.status = 'ACTIVE'
            WHERE ws.District_Name = ? 
                AND f.registration_date BETWEEN ? AND ?";
    
    $params = [$district_name, $date_from, $date_to];
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params[] = $division_filter;
    }
    
    if ($gn_filter) {
        $sql .= " AND ws.GN_ID = ?";
        $params[] = $gn_filter;
    }
    
    $sql .= " GROUP BY f.family_id
              ORDER BY ws.Division_Name, ws.GN, f.registration_date DESC
              LIMIT 1000";
    
    return $db->getAll($sql, $params);
}

function generatePopulationDemographicsReport($db, $district_name, $division_filter, $gn_filter) {
    $sql = "SELECT 
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 18 THEN '0-17 (Children)'
                    WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN '18-35 (Youth)'
                    WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) BETWEEN 36 AND 60 THEN '36-60 (Adults)'
                    ELSE '60+ (Senior)'
                END as age_group,
                SUM(CASE WHEN c.gender = 'MALE' THEN 1 ELSE 0 END) as male,
                SUM(CASE WHEN c.gender = 'FEMALE' THEN 1 ELSE 0 END) as female,
                COUNT(*) as total
            FROM citizens c
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE'";
    
    $params = [$district_name];
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params[] = $division_filter;
    }
    
    if ($gn_filter) {
        $sql .= " AND ws.GN_ID = ?";
        $params[] = $gn_filter;
    }
    
    $sql .= " GROUP BY age_group
              ORDER BY 
                CASE age_group
                    WHEN '0-17 (Children)' THEN 1
                    WHEN '18-35 (Youth)' THEN 2
                    WHEN '36-60 (Adults)' THEN 3
                    ELSE 4
                END";
    
    $data = $db->getAll($sql, $params);
    
    // Calculate total and percentages
    $total_population = array_sum(array_column($data, 'total'));
    
    foreach ($data as &$row) {
        $row['percentage'] = $total_population > 0 ? round(($row['total'] / $total_population) * 100, 2) : 0;
    }
    
    return $data;
}

function generateEducationLevelsReport($db, $district_name, $division_filter, $gn_filter) {
    $sql = "SELECT 
                e.education_level,
                SUM(CASE WHEN c.gender = 'MALE' THEN 1 ELSE 0 END) as male,
                SUM(CASE WHEN c.gender = 'FEMALE' THEN 1 ELSE 0 END) as female,
                COUNT(*) as total
            FROM education e
            INNER JOIN citizens c ON e.citizen_id = c.citizen_id
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE'";
    
    $params = [$district_name];
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params[] = $division_filter;
    }
    
    if ($gn_filter) {
        $sql .= " AND ws.GN_ID = ?";
        $params[] = $gn_filter;
    }
    
    $sql .= " GROUP BY e.education_level
              ORDER BY FIELD(e.education_level, 
                'PHD','MPHIL','MASTERS','DEGREE','A/L','O/L',
                '10','9','8','7','6','5','4','3','2','1')";
    
    $data = $db->getAll($sql, $params);
    
    // Calculate total and percentages
    $total_education = array_sum(array_column($data, 'total'));
    
    foreach ($data as &$row) {
        $row['percentage'] = $total_education > 0 ? round(($row['total'] / $total_education) * 100, 2) : 0;
    }
    
    return $data;
}

function generateEmploymentStatusReport($db, $district_name, $division_filter, $gn_filter) {
    $sql = "SELECT 
                em.employment_type,
                COUNT(*) as count,
                AVG(em.monthly_income) as avg_income,
                SUM(em.monthly_income) as total_income
            FROM employment em
            INNER JOIN citizens c ON em.citizen_id = c.citizen_id
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE' AND em.monthly_income > 0";
    
    $params = [$district_name];
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params[] = $division_filter;
    }
    
    if ($gn_filter) {
        $sql .= " AND ws.GN_ID = ?";
        $params[] = $gn_filter;
    }
    
    $sql .= " GROUP BY em.employment_type
              ORDER BY em.employment_type";
    
    $data = $db->getAll($sql, $params);
    
    // Calculate total and percentages
    $total_employment = array_sum(array_column($data, 'count'));
    
    foreach ($data as &$row) {
        $row['percentage'] = $total_employment > 0 ? round(($row['count'] / $total_employment) * 100, 2) : 0;
        $row['avg_income'] = round($row['avg_income'], 2);
        $row['total_income'] = round($row['total_income'], 2);
    }
    
    return $data;
}

function generateHealthConditionsReport($db, $district_name, $division_filter, $gn_filter) {
    $sql = "SELECT 
                hc.condition_name,
                COUNT(*) as cases,
                SUM(CASE WHEN c.gender = 'MALE' THEN 1 ELSE 0 END) as male,
                SUM(CASE WHEN c.gender = 'FEMALE' THEN 1 ELSE 0 END) as female
            FROM health_conditions hc
            INNER JOIN citizens c ON hc.citizen_id = c.citizen_id
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? AND c.status = 'ACTIVE'";
    
    $params = [$district_name];
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params[] = $division_filter;
    }
    
    if ($gn_filter) {
        $sql .= " AND ws.GN_ID = ?";
        $params[] = $gn_filter;
    }
    
    $sql .= " GROUP BY hc.condition_name
              ORDER BY cases DESC";
    
    $data = $db->getAll($sql, $params);
    
    // Get total population for prevalence calculation
    $sql_total = "SELECT COUNT(*) as total_population
                  FROM citizens c
                  INNER JOIN families f ON c.family_id = f.family_id
                  INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
                  WHERE ws.District_Name = ? AND c.status = 'ACTIVE'";
    
    $params_total = [$district_name];
    
    if ($division_filter) {
        $sql_total .= " AND ws.Division_Name = ?";
        $params_total[] = $division_filter;
    }
    
    if ($gn_filter) {
        $sql_total .= " AND ws.GN_ID = ?";
        $params_total[] = $gn_filter;
    }
    
    $total_pop = $db->getRow($sql_total, $params_total);
    $total_population = $total_pop['total_population'] ?? 1;
    
    foreach ($data as &$row) {
        $row['prevalence'] = round(($row['cases'] / $total_population) * 10000, 2);
    }
    
    return $data;
}

function generateLandOwnershipReport($db, $district_name, $division_filter, $gn_filter) {
    $sql = "SELECT 
                ld.land_type,
                COUNT(*) as count,
                SUM(ld.size_perches) as total_area,
                AVG(ld.size_perches) as avg_area
            FROM land_details ld
            INNER JOIN families f ON ld.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ?";
    
    $params = [$district_name];
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params[] = $division_filter;
    }
    
    if ($gn_filter) {
        $sql .= " AND ws.GN_ID = ?";
        $params[] = $gn_filter;
    }
    
    $sql .= " GROUP BY ld.land_type
              ORDER BY ld.land_type";
    
    $data = $db->getAll($sql, $params);
    
    // Calculate total and percentages
    $total_area = array_sum(array_column($data, 'total_area'));
    
    foreach ($data as &$row) {
        $row['percentage'] = $total_area > 0 ? round(($row['total_area'] / $total_area) * 100, 2) : 0;
        $row['avg_area'] = round($row['avg_area'], 2);
        $row['total_area'] = round($row['total_area'], 2);
    }
    
    return $data;
}

function generateCitizenDetailsReport($db, $district_name, $division_filter, $gn_filter, $date_from, $date_to) {
    $sql = "SELECT 
                c.citizen_id,
                c.name,
                CONCAT(c.id_type, ': ', c.id_number) as id_info,
                c.date_of_birth,
                c.gender,
                f.address,
                c.telephone_mobile,
                f.family_id
            FROM citizens c
            INNER JOIN families f ON c.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? 
                AND c.status = 'ACTIVE'
                AND f.registration_date BETWEEN ? AND ?";
    
    $params = [$district_name, $date_from, $date_to];
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params[] = $division_filter;
    }
    
    if ($gn_filter) {
        $sql .= " AND ws.GN_ID = ?";
        $params[] = $gn_filter;
    }
    
    $sql .= " ORDER BY ws.Division_Name, ws.GN, c.name
              LIMIT 1000";
    
    return $db->getAll($sql, $params);
}

function generateNewRegistrationsReport($db, $district_name, $division_filter, $gn_filter, $date_from, $date_to) {
    $sql = "SELECT 
                DATE(f.registration_date) as reg_date,
                ws.Division_Name,
                ws.GN,
                COUNT(DISTINCT f.family_id) as families,
                COUNT(DISTINCT c.citizen_id) as citizens,
                ROUND(COUNT(DISTINCT c.citizen_id) / COUNT(DISTINCT f.family_id), 2) as avg_family_size
            FROM families f
            INNER JOIN citizens c ON f.family_id = c.family_id
            INNER JOIN mobile_service.fix_work_station ws ON f.current_gn_id = ws.GN_ID
            WHERE ws.District_Name = ? 
                AND f.registration_date BETWEEN ? AND ?
                AND c.status = 'ACTIVE'";
    
    $params = [$district_name, $date_from, $date_to];
    
    if ($division_filter) {
        $sql .= " AND ws.Division_Name = ?";
        $params[] = $division_filter;
    }
    
    if ($gn_filter) {
        $sql .= " AND ws.GN_ID = ?";
        $params[] = $gn_filter;
    }
    
    $sql .= " GROUP BY DATE(f.registration_date), ws.Division_Name, ws.GN
              ORDER BY reg_date DESC, families DESC
              LIMIT 500";
    
    return $db->getAll($sql, $params);
}

function generateTransferHistoryReport($db, $district_name, $division_filter, $date_from, $date_to) {
    $sql = "SELECT 
                DATE(ft.transfer_date) as transfer_date,
                ft.family_id,
                ws1.GN as from_gn,
                ws2.GN as to_gn,
                ft.reason
            FROM family_transfers ft
            INNER JOIN families f ON ft.family_id = f.family_id
            INNER JOIN mobile_service.fix_work_station ws1 ON ft.from_gn_id = ws1.GN_ID
            INNER JOIN mobile_service.fix_work_station ws2 ON ft.to_gn_id = ws2.GN_ID
            WHERE ws1.District_Name = ? 
                AND ft.transfer_date BETWEEN ? AND ?";
    
    $params = [$district_name, $date_from, $date_to];
    
    if ($division_filter) {
        $sql .= " AND ws1.Division_Name = ?";
        $params[] = $division_filter;
    }
    
    $sql .= " ORDER BY ft.transfer_date DESC
              LIMIT 500";
    
    return $db->getAll($sql, $params);
}

function getDivisionsByDistrict($district_name) {
    try {
        $db = Database::getInstance();
        $sql = "SELECT DISTINCT Division_Name 
                FROM mobile_service.fix_work_station 
                WHERE District_Name = ? 
                ORDER BY Division_Name";
        
        $result = $db->getAll($sql, [$district_name]);
        $divisions = array();
        foreach ($result as $row) {
            $divisions[] = $row['Division_Name'];
        }
        return $divisions;
    } catch (Exception $e) {
        return array();
    }
}

function getGNDivisionsByDivision($division_name) {
    try {
        $db = Database::getInstance();
        $sql = "SELECT GN_ID, GN 
                FROM mobile_service.fix_work_station 
                WHERE Division_Name = ? 
                ORDER BY GN";
        
        $result = $db->getAll($sql, [$division_name]);
        $gns = array();
        foreach ($result as $row) {
            $gns[$row['GN_ID']] = $row['GN'];
        }
        return $gns;
    } catch (Exception $e) {
        return array();
    }
}

function exportReport($report_type, $data, $headers, $title, $format) {
    if ($format === 'pdf') {
        exportToPDF($report_type, $data, $headers, $title);
    } elseif ($format === 'excel') {
        exportToExcel($report_type, $data, $headers, $title);
    } elseif ($format === 'csv') {
        exportToCSV($report_type, $data, $headers, $title);
    }
}

function exportToPDF($report_type, $data, $headers, $title) {
    // PDF generation would require a library like TCPDF or DomPDF
    // For now, redirect to HTML version with print CSS
    header('Content-Type: text/html');
    echo '<script>window.print();</script>';
    exit();
}

function exportToExcel($report_type, $data, $headers, $title) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $report_type . '_' . date('Y-m-d') . '.xls"');
    
    echo '<html><body>';
    echo '<table border="1">';
    echo '<tr><th colspan="' . count($headers) . '" style="background-color: #4CAF50; color: white; padding: 10px; font-size: 16px;">' . $title . '</th></tr>';
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th style="background-color: #f2f2f2; padding: 8px;">' . htmlspecialchars($header) . '</th>';
    }
    echo '</tr>';
    
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td style="padding: 6px;">' . htmlspecialchars($cell) . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</table>';
    echo '<p style="margin-top: 20px; font-style: italic;">Generated on: ' . date('Y-m-d H:i:s') . '</p>';
    echo '</body></html>';
    exit();
}

function exportToCSV($report_type, $data, $headers, $title) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $report_type . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write headers
    fputcsv($output, $headers);
    
    // Write data
    foreach ($data as $row) {
        fputcsv($output, array_values($row));
    }
    
    fclose($output);
    exit();
}

// Report descriptions
$report_types = array(
    'family_summary' => array(
        'name' => 'Family Summary',
        'description' => 'Detailed list of families with head information and member count',
        'icon' => 'bi-house-door'
    ),
    'population_demographics' => array(
        'name' => 'Population Demographics',
        'description' => 'Age group and gender distribution analysis',
        'icon' => 'bi-people'
    ),
    'education_levels' => array(
        'name' => 'Education Levels',
        'description' => 'Educational attainment distribution across population',
        'icon' => 'bi-book'
    ),
    'employment_status' => array(
        'name' => 'Employment Status',
        'description' => 'Employment types and income statistics',
        'icon' => 'bi-briefcase'
    ),
    'health_conditions' => array(
        'name' => 'Health Conditions',
        'description' => 'Prevalence of health conditions and diseases',
        'icon' => 'bi-heart-pulse'
    ),
    'land_ownership' => array(
        'name' => 'Land Ownership',
        'description' => 'Land types, sizes, and ownership patterns',
        'icon' => 'bi-map'
    ),
    'citizen_details' => array(
        'name' => 'Citizen Details',
        'description' => 'Complete citizen information listing',
        'icon' => 'bi-person-lines-fill'
    ),
    'new_registrations' => array(
        'name' => 'New Registrations',
        'description' => 'Daily registration statistics and trends',
        'icon' => 'bi-calendar-plus'
    ),
    'transfer_history' => array(
        'name' => 'Transfer History',
        'description' => 'Family transfer records between GN divisions',
        'icon' => 'bi-arrow-left-right'
    )
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .report-card {
            transition: all 0.3s;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            margin-bottom: 20px;
            cursor: pointer;
        }
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            border-color: #0d6efd;
        }
        .report-card.selected {
            border: 2px solid #0d6efd;
            background-color: #f8f9fa;
        }
        .report-icon {
            font-size: 2.5rem;
            color: #0d6efd;
            margin-bottom: 10px;
        }
        .filter-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .report-preview {
            max-height: 500px;
            overflow-y: auto;
        }
        .report-table th {
            position: sticky;
            top: 0;
            background-color: #0d6efd;
            color: white;
        }
        .export-buttons .btn {
            min-width: 120px;
        }
        .stat-badge {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 20px;
        }
    </style>
</head>
<body>
    <?php 
    if (file_exists('../includes/navbar.php')) {
        include '../includes/navbar.php';
    } else {
        echo '<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
            <div class="container-fluid">
                <a class="navbar-brand" href="../district/">
                    <i class="bi bi-bar-chart-line"></i> ' . SITE_SHORT_NAME . ' Reports
                </a>
                <div class="navbar-nav ms-auto">
                    <span class="navbar-text me-3">
                        <i class="bi bi-geo-alt"></i> ' . htmlspecialchars($district_name) . ' District
                    </span>
                    <a href="dashboard.php" class="btn btn-outline-light me-2">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
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
        <div class="row">
            <div class="col-lg-3">
                <!-- Report Type Selection -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Select Report Type</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($report_types as $type => $info): ?>
                            <div class="report-card p-3 mb-3 <?php echo ($report_type == $type) ? 'selected' : ''; ?>" 
                                 onclick="selectReportType('<?php echo $type; ?>')">
                                <div class="text-center">
                                    <i class="bi <?php echo $info['icon']; ?> report-icon"></i>
                                    <h6><?php echo $info['name']; ?></h6>
                                    <p class="text-muted small mb-0"><?php echo $info['description']; ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Report Information</h5>
                    </div>
                    <div class="card-body">
                        <p class="small">
                            <strong>Selected Report:</strong><br>
                            <?php echo $report_types[$report_type]['name']; ?>
                        </p>
                        <p class="small">
                            <strong>Description:</strong><br>
                            <?php echo $report_types[$report_type]['description']; ?>
                        </p>
                        <hr>
                        <p class="small text-muted">
                            <i class="bi bi-lightbulb"></i> <strong>Tip:</strong> Use filters to customize your report data. 
                            You can export reports in multiple formats.
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-9">
                <!-- Report Filters and Controls -->
                <div class="filter-section">
                    <h4><i class="bi bi-funnel"></i> Report Filters</h4>
                    <form method="GET" id="reportForm">
                        <input type="hidden" name="report_type" id="reportType" value="<?php echo $report_type; ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Date Range</label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="date" name="date_from" class="form-control" 
                                               value="<?php echo $date_from; ?>" 
                                               max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-6">
                                        <input type="date" name="date_to" class="form-control" 
                                               value="<?php echo $date_to; ?>" 
                                               max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Division</label>
                                <select name="division" id="divisionSelect" class="form-select" 
                                        onchange="updateGNDivisions()">
                                    <option value="">All Divisions</option>
                                    <?php foreach ($divisions as $division): ?>
                                        <option value="<?php echo htmlspecialchars($division); ?>" 
                                            <?php echo ($division_filter == $division) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($division); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">GN Division</label>
                                <select name="gn" id="gnSelect" class="form-select">
                                    <option value="">All GN Divisions</option>
                                    <?php foreach ($gn_divisions as $gn_id => $gn_name): ?>
                                        <option value="<?php echo htmlspecialchars($gn_id); ?>" 
                                            <?php echo ($gn_filter == $gn_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($gn_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Actions</label>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-eye"></i> Preview Report
                                    </button>
                                    <button type="button" class="btn btn-success" onclick="showExportOptions()">
                                        <i class="bi bi-download"></i> Export Report
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Export Options (Hidden by default) -->
                        <div id="exportOptions" class="mt-3 p-3 border rounded" style="display: none;">
                            <h6><i class="bi bi-download"></i> Export Format</h6>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-primary" onclick="exportReport('html')">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="exportReport('excel')">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="exportReport('csv')">
                                    <i class="bi bi-filetype-csv"></i> CSV
                                </button>
                                <button type="button" class="btn btn-outline-danger" onclick="exportReport('pdf')">
                                    <i class="bi bi-file-pdf"></i> PDF
                                </button>
                            </div>
                            <button type="button" class="btn btn-link" onclick="hideExportOptions()">Cancel</button>
                        </div>
                    </form>
                </div>
                
                <!-- Report Preview -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-file-earmark-text"></i> 
                            <?php echo $report_title; ?>
                        </h5>
                        <div>
                            <span class="badge bg-primary stat-badge">
                                <i class="bi bi-table"></i> <?php echo count($report_data); ?> Records
                            </span>
                            <?php if ($division_filter): ?>
                                <span class="badge bg-info stat-badge">
                                    <i class="bi bi-filter"></i> Division: <?php echo htmlspecialchars($division_filter); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($gn_filter): ?>
                                <span class="badge bg-warning stat-badge">
                                    <i class="bi bi-geo-alt"></i> GN: <?php echo htmlspecialchars($gn_divisions[$gn_filter] ?? $gn_filter); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($report_data) && $action === 'generate_report'): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No data found for the selected criteria.
                            </div>
                        <?php elseif (!empty($report_data)): ?>
                            <div class="report-preview">
                                <table class="table table-striped table-hover report-table">
                                    <thead>
                                        <tr>
                                            <?php foreach ($report_headers as $header): ?>
                                                <th><?php echo htmlspecialchars($header); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <?php foreach ($row as $cell): ?>
                                                    <td>
                                                        <?php 
                                                        if (is_numeric($cell) && strpos((string)$cell, '.') !== false) {
                                                            echo number_format($cell, 2);
                                                        } elseif (is_numeric($cell)) {
                                                            echo number_format($cell);
                                                        } else {
                                                            echo htmlspecialchars($cell);
                                                        }
                                                        ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="mt-3 text-muted small">
                                <i class="bi bi-info-circle"></i> 
                                Showing <?php echo count($report_data); ?> records. 
                                Generated on <?php echo date('Y-m-d H:i:s'); ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="bi bi-file-earmark-text display-1 text-muted"></i>
                                <h5 class="mt-3 text-muted">No Report Generated Yet</h5>
                                <p class="text-muted">Select report type and filters, then click "Preview Report"</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function selectReportType(type) {
            document.getElementById('reportType').value = type;
            document.getElementById('reportForm').submit();
        }
        
        function updateGNDivisions() {
            const division = document.getElementById('divisionSelect').value;
            if (division) {
                // In a real application, you would fetch GN divisions via AJAX
                // For now, we'll submit the form to refresh the page
                document.getElementById('reportForm').submit();
            } else {
                document.getElementById('gnSelect').innerHTML = '<option value="">All GN Divisions</option>';
                document.getElementById('reportForm').submit();
            }
        }
        
        function showExportOptions() {
            document.getElementById('exportOptions').style.display = 'block';
        }
        
        function hideExportOptions() {
            document.getElementById('exportOptions').style.display = 'none';
        }
        
        function exportReport(format) {
            const form = document.getElementById('reportForm');
            const formatInput = document.createElement('input');
            formatInput.type = 'hidden';
            formatInput.name = 'format';
            formatInput.value = format;
            form.appendChild(formatInput);
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'generate_report';
            form.appendChild(actionInput);
            
            form.submit();
        }
        
        // Auto-submit form for preview when filters change
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('reportForm');
            const inputs = form.querySelectorAll('select, input[type="date"]');
            
            // Don't auto-submit on initial load
            let initialLoad = true;
            
            inputs.forEach(input => {
                input.addEventListener('change', function() {
                    if (!initialLoad) {
                        form.submit();
                    }
                });
            });
            
            setTimeout(() => { initialLoad = false; }, 1000);
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>