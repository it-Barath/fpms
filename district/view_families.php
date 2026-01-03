<?php
/**
 * District - View All Families
 * Allows district officers to view all families under their district
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

// Get district info
$districtCode = $_SESSION['office_code'];
$districtName = $_SESSION['office_name'];

// Set page title
$pageTitle = "View All Families";

// Initialize Database
require_once '../classes/Database.php';
$db = new Database();

// Get filters from URL
$filterDivision = $_GET['division'] ?? '';
$filterGN = $_GET['gn'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$searchQuery = $_GET['search'] ?? '';

try {
    $conn = $db->getMainConnection();
    $refConn = $db->getRefConnection();
    
    // Get all divisions for filter dropdown
    $divQuery = "SELECT office_code, office_name FROM users WHERE user_type = 'division' ORDER BY office_name";
    $divResult = $conn->query($divQuery);
    $divisions = $divResult->fetch_all(MYSQLI_ASSOC);
    
    // Get GN offices for filter (optionally filtered by division)
    if ($filterDivision) {
        $gnQuery = "SELECT office_code, office_name FROM users WHERE user_type = 'gn' AND parent_division_code = ? ORDER BY office_name";
        $gnStmt = $conn->prepare($gnQuery);
        $gnStmt->bind_param("s", $filterDivision);
        $gnStmt->execute();
        $gnOffices = $gnStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $gnQuery = "SELECT office_code, office_name FROM users WHERE user_type = 'gn' ORDER BY office_name";
        $gnResult = $conn->query($gnQuery);
        $gnOffices = $gnResult->fetch_all(MYSQLI_ASSOC);
    }
    
    // Build the main query with filters
    $query = "
        SELECT 
            f.family_id,
            f.gn_id,
            f.address,
            f.total_members,
            f.is_transferred,
            f.has_pending_transfer,
            f.created_at,
            gn.office_name as gn_name,
            gn.parent_division_code,
            division.office_name as division_name,
            -- Get family head details
            (SELECT c.full_name 
             FROM citizens c 
             WHERE c.family_id = f.family_id 
             AND c.relation_to_head = 'self' 
             LIMIT 1) as head_name,
            (SELECT c.identification_number 
             FROM citizens c 
             WHERE c.family_id = f.family_id 
             AND c.relation_to_head = 'self' 
             LIMIT 1) as head_nic,
            (SELECT c.mobile_phone 
             FROM citizens c 
             WHERE c.family_id = f.family_id 
             AND c.relation_to_head = 'self' 
             LIMIT 1) as head_phone
        FROM families f
        LEFT JOIN users gn ON f.gn_id = gn.office_code AND gn.user_type = 'gn'
        LEFT JOIN users division ON gn.parent_division_code = division.office_code AND division.user_type = 'division'
        WHERE 1=1
    ";
    
    $params = [];
    $types = "";
    
    // Apply division filter
    if ($filterDivision) {
        $query .= " AND gn.parent_division_code = ?";
        $params[] = $filterDivision;
        $types .= "s";
    }
    
    // Apply GN filter
    if ($filterGN) {
        $query .= " AND f.gn_id = ?";
        $params[] = $filterGN;
        $types .= "s";
    }
    
    // Apply status filter
    if ($filterStatus === 'transferred') {
        $query .= " AND f.is_transferred = 1";
    } elseif ($filterStatus === 'pending') {
        $query .= " AND f.has_pending_transfer = 1";
    } elseif ($filterStatus === 'active') {
        $query .= " AND f.is_transferred = 0 AND f.has_pending_transfer = 0";
    }
    
    // Apply search filter
    if ($searchQuery) {
        $query .= " AND (
            f.family_id LIKE ? OR 
            f.address LIKE ? OR
            (SELECT c.full_name FROM citizens c WHERE c.family_id = f.family_id AND c.relation_to_head = 'self' LIMIT 1) LIKE ? OR
            (SELECT c.identification_number FROM citizens c WHERE c.family_id = f.family_id AND c.relation_to_head = 'self' LIMIT 1) LIKE ?
        )";
        $searchParam = "%$searchQuery%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "ssss";
    }
    
    $query .= " ORDER BY f.created_at DESC";
    
    // Prepare and execute
    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
    
    $families = $result->fetch_all(MYSQLI_ASSOC);
    
    // Get statistics
    $statsQuery = "
        SELECT 
            COUNT(DISTINCT f.family_id) as total_families,
            COALESCE(SUM(f.total_members), 0) as total_members,
            SUM(CASE WHEN f.is_transferred = 1 THEN 1 ELSE 0 END) as transferred_count,
            SUM(CASE WHEN f.has_pending_transfer = 1 THEN 1 ELSE 0 END) as pending_count,
            COUNT(DISTINCT f.gn_id) as unique_gn_count,
            COUNT(DISTINCT gn.parent_division_code) as unique_division_count
        FROM families f
        LEFT JOIN users gn ON f.gn_id = gn.office_code AND gn.user_type = 'gn'
    ";
    
    $statsResult = $conn->query($statsQuery);
    $stats = $statsResult->fetch_assoc();
    
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
            <!-- Page header -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-people-fill me-2"></i>All Families - <?php echo htmlspecialchars($districtName); ?>
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="exportToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">Total Families</h6>
                                    <h2 class="mb-0"><?php echo number_format($stats['total_families']); ?></h2>
                                </div>
                                <i class="bi bi-house-door fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">Total Members</h6>
                                    <h2 class="mb-0"><?php echo number_format($stats['total_members']); ?></h2>
                                </div>
                                <i class="bi bi-people fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">Divisions</h6>
                                    <h2 class="mb-0"><?php echo $stats['unique_division_count']; ?></h2>
                                </div>
                                <i class="bi bi-building fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">GN Offices</h6>
                                    <h2 class="mb-0"><?php echo $stats['unique_gn_count']; ?></h2>
                                </div>
                                <i class="bi bi-geo-alt fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Panel -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-funnel me-2"></i>Filters
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" id="filterForm">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="division" class="form-label">Division</label>
                                <select class="form-select" id="division" name="division" onchange="this.form.submit()">
                                    <option value="">All Divisions</option>
                                    <?php foreach ($divisions as $div): ?>
                                        <option value="<?php echo htmlspecialchars($div['office_code']); ?>"
                                                <?php echo ($filterDivision === $div['office_code']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($div['office_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="gn" class="form-label">GN Office</label>
                                <select class="form-select" id="gn" name="gn" onchange="this.form.submit()">
                                    <option value="">All GN Offices</option>
                                    <?php foreach ($gnOffices as $gn): ?>
                                        <option value="<?php echo htmlspecialchars($gn['office_code']); ?>"
                                                <?php echo ($filterGN === $gn['office_code']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($gn['office_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" onchange="this.form.submit()">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo ($filterStatus === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="pending" <?php echo ($filterStatus === 'pending') ? 'selected' : ''; ?>>Pending Transfer</option>
                                    <option value="transferred" <?php echo ($filterStatus === 'transferred') ? 'selected' : ''; ?>>Transferred</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       placeholder="Family ID, Name, NIC..." 
                                       value="<?php echo htmlspecialchars($searchQuery); ?>">
                            </div>
                            
                            <div class="col-md-1 mb-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                        
                        <?php if ($filterDivision || $filterGN || $filterStatus || $searchQuery): ?>
                            <div class="row">
                                <div class="col-12">
                                    <a href="view_families.php" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-x-circle me-1"></i> Clear Filters
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- Families Table -->
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul me-2"></i>Families List
                        <span class="badge bg-primary"><?php echo count($families); ?> families</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="familiesTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Family ID</th>
                                    <th>Head of Family</th>
                                    <th>NIC</th>
                                    <th>Contact</th>
                                    <th>Division</th>
                                    <th>GN Office</th>
                                    <th>Members</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($families)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                            No families found matching the criteria
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($families as $index => $family): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($family['family_id']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($family['head_name']): ?>
                                                    <?php echo htmlspecialchars($family['head_name']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not specified</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($family['head_nic']): ?>
                                                    <code><?php echo htmlspecialchars($family['head_nic']); ?></code>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($family['head_phone']): ?>
                                                    <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($family['head_phone']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($family['division_name']): ?>
                                                    <small><?php echo htmlspecialchars($family['division_name']); ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Not Mapped</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($family['gn_name']); ?></small><br>
                                                <code class="small"><?php echo htmlspecialchars($family['gn_id']); ?></code>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-info"><?php echo $family['total_members']; ?></span>
                                            </td>
                                            <td>
                                                <?php if ($family['has_pending_transfer']): ?>
                                                    <span class="badge bg-warning">Pending Transfer</span>
                                                <?php elseif ($family['is_transferred']): ?>
                                                    <span class="badge bg-secondary">Transferred</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="family_details.php?id=<?php echo urlencode($family['family_id']); ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="family_members.php?id=<?php echo urlencode($family['family_id']); ?>" 
                                                       class="btn btn-sm btn-outline-info" title="View Members">
                                                        <i class="bi bi-people"></i>
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
                <div class="card-footer text-muted">
                    Showing <?php echo count($families); ?> families
                    <?php if ($filterDivision || $filterGN || $filterStatus || $searchQuery): ?>
                        <span class="text-info">(filtered)</span>
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

<!-- Load jQuery FIRST -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- DataTables JavaScript -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<script>
$(document).ready(function() {
    // Initialize DataTable
    if (!$.fn.dataTable.isDataTable('#familiesTable')) {
        $('#familiesTable').DataTable({
            "order": [[1, "asc"]], // Sort by Family ID
            "pageLength": 25,
            "responsive": true,
            "language": {
                "search": "Search families:",
                "lengthMenu": "Show _MENU_ families",
                "info": "Showing _START_ to _END_ of _TOTAL_ families",
                "infoEmpty": "No families found",
                "zeroRecords": "No matching families found"
            },
            "columnDefs": [
                { "orderable": false, "targets": [9] } // Disable sorting on Actions column
            ]
        });
    }
});

// Export to Excel function
function exportToExcel() {
    // Get table data
    let table = document.getElementById('familiesTable');
    let html = table.outerHTML;
    
    // Create downloadable file
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    let downloadLink = document.createElement("a");
    downloadLink.href = url;
    downloadLink.download = 'families_export_' + new Date().toISOString().slice(0,10) + '.xls';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>