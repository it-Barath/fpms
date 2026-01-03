<?php
// division/view_families.php
// View families under division jurisdiction

session_start();
require_once '../../config.php';
require_once '../../classes/Auth.php';
//require_once '../../classes/CitizenManager.php';

// Check authentication
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole('division');

// Get current user info
$currentUser = $auth->getCurrentUser();
$divisionName = $currentUser['office_code'];
$divisionDisplayName = $currentUser['office_name'];

// Initialize CitizenManager
$citizenManager = new CitizenManager();

// Get all GN divisions under this division
$gnDivisions = [];
$conn = getRefConnection();
$query = "SELECT GN_ID, GN FROM fix_work_station WHERE Division_Name = ? ORDER BY GN";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $divisionName);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $gnDivisions[] = $row;
}

// Handle filters
$gnFilter = $_GET['gn_id'] ?? '';
$familyIdFilter = $_GET['family_id'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = $_GET['page'] ?? 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get families with filters
$families = [];
$totalFamilies = 0;

try {
    // Build query
    $query = "SELECT f.*, COUNT(c.citizen_id) as member_count 
              FROM families f 
              LEFT JOIN citizens c ON f.family_id = c.family_id";
    
    $whereConditions = [];
    $params = [];
    $types = "";
    
    if ($gnFilter) {
        $whereConditions[] = "f.gn_id = ?";
        $params[] = $gnFilter;
        $types .= "s";
    } else {
        // Show families from all GN divisions under this division
        $gnIds = array_column($gnDivisions, 'GN_ID');
        if (!empty($gnIds)) {
            $placeholders = str_repeat('?,', count($gnIds) - 1) . '?';
            $whereConditions[] = "f.gn_id IN ($placeholders)";
            $params = array_merge($params, $gnIds);
            $types .= str_repeat('s', count($gnIds));
        }
    }
   
    if ($familyIdFilter) {
        $whereConditions[] = "f.family_id LIKE ?";
        $params[] = "%$familyIdFilter%";
        $types .= "s";
    }
    
    if ($searchFilter) {
        $whereConditions[] = "(f.family_head_nic LIKE ? OR f.address LIKE ?)";
        $params[] = "%$searchFilter%";
        $params[] = "%$searchFilter%";
        $types .= "ss";
    }
    
    if ($statusFilter === 'transferred') {
        $whereConditions[] = "f.is_transferred = 1";
    } elseif ($statusFilter === 'local') {
        $whereConditions[] = "f.is_transferred = 0";
    }
    
    if (!empty($whereConditions)) {
        $query .= " WHERE " . implode(" AND ", $whereConditions);
    }
    
    $query .= " GROUP BY f.family_id";
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(DISTINCT f.family_id) as total " . substr($query, strpos($query, 'FROM'));
    $stmt = $conn->prepare($countQuery);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $countResult = $stmt->get_result();
    $totalData = $countResult->fetch_assoc();
    $totalFamilies = $totalData['total'] ?? 0;
    
    // Get paginated data
    $query .= " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Get family head details if available
        if ($row['family_head_nic']) {
            $headQuery = "SELECT full_name, mobile_phone FROM citizens 
                          WHERE identification_number = ? AND identification_type = 'nic' 
                          LIMIT 1";
            $headStmt = $conn->prepare($headQuery);
            $headStmt->bind_param("s", $row['family_head_nic']);
            $headStmt->execute();
            $headResult = $headStmt->get_result();
            $headInfo = $headResult->fetch_assoc();
            
            if ($headInfo) {
                $row['head_name'] = $headInfo['full_name'];
                $row['head_phone'] = $headInfo['mobile_phone'];
            }
        }
        
        $families[] = $row;
    }
    
} catch (Exception $e) {
    $error = "Error fetching families: " . $e->getMessage();
}

// Set page variables
$pageTitle = "Family Management - " . $divisionDisplayName;
$pageDescription = "View and manage families under " . $divisionDisplayName . " division";
$pageIcon = "fas fa-house-user";

// Breadcrumb
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index.php'],
    ['title' => $divisionDisplayName . ' Division', 'url' => 'index.php'],
    ['title' => 'Family Management']
];

// Include header
include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2">
                <i class="<?php echo $pageIcon; ?>"></i>
                <?php echo htmlspecialchars($pageTitle); ?>
            </h1>
            <p class="lead mb-0"><?php echo htmlspecialchars($pageDescription); ?></p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="exportBtn">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-home"></i> Total Families</h5>
                    <h2 class="card-text"><?php echo $totalFamilies; ?></h2>
                    <small>Under <?php echo htmlspecialchars($divisionDisplayName); ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-users"></i> GN Divisions</h5>
                    <h2 class="card-text"><?php echo count($gnDivisions); ?></h2>
                    <small>In this division</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-exchange-alt"></i> Transferred</h5>
                    <h2 class="card-text">
                        <?php 
                        $transferredCount = 0;
                        foreach ($families as $family) {
                            if ($family['is_transferred']) $transferredCount++;
                        }
                        echo $transferredCount;
                        ?>
                    </h2>
                    <small>Families transferred out</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-map-marker-alt"></i> Active GN</h5>
                    <h2 class="card-text">
                        <?php 
                        $activeGN = [];
                        foreach ($families as $family) {
                            $activeGN[$family['gn_id']] = true;
                        }
                        echo count($activeGN);
                        ?>
                    </h2>
                    <small>GN divisions with families</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-filter"></i> Filter Families</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label for="gn_id" class="form-label">GN Division</label>
                    <select class="form-select" id="gn_id" name="gn_id">
                        <option value="">All GN Divisions</option>
                        <?php foreach ($gnDivisions as $gn): ?>
                            <option value="<?php echo htmlspecialchars($gn['GN_ID']); ?>"
                                <?php echo ($gnFilter == $gn['GN_ID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($gn['GN'] . ' (' . $gn['GN_ID'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="family_id" class="form-label">Family ID</label>
                    <input type="text" class="form-control" id="family_id" name="family_id" 
                           value="<?php echo htmlspecialchars($familyIdFilter); ?>" 
                           placeholder="e.g., GN12345-0001">
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="local" <?php echo ($statusFilter == 'local') ? 'selected' : ''; ?>>Local Families</option>
                        <option value="transferred" <?php echo ($statusFilter == 'transferred') ? 'selected' : ''; ?>>Transferred Families</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($searchFilter); ?>" 
                           placeholder="NIC or Address">
                </div>
                <div class="col-12">
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="view_families.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Families Table -->
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="fas fa-list"></i> Families List
                <small class="text-muted">(Showing <?php echo count($families); ?> of <?php echo $totalFamilies; ?> families)</small>
            </h5>
        </div>
        <div class="card-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table table-hover table-striped" id="familiesTable">
                    <thead>
                        <tr>
                            <th>Family ID</th>
                            <th>GN Division</th>
                            <th>Head of Family</th>
                            <th>NIC</th>
                            <th>Members</th>
                            <th>Status</th>
                            <th>Address</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($families)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x mb-3"></i><br>
                                    <?php if ($gnFilter || $familyIdFilter || $searchFilter || $statusFilter): ?>
                                        No families found matching your filters
                                    <?php else: ?>
                                        No families registered under this division yet
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($families as $family): ?>
                                <?php
                                // Get GN name
                                $gnName = '';
                                foreach ($gnDivisions as $gn) {
                                    if ($gn['GN_ID'] == $family['gn_id']) {
                                        $gnName = $gn['GN'];
                                        break;
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($family['family_id']); ?></strong>
                                        <?php if ($family['original_gn_id'] != $family['gn_id']): ?>
                                            <br><small class="text-muted">Original: <?php echo htmlspecialchars($family['original_gn_id']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($gnName); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($family['gn_id']); ?></small>
                                    </td>
                                    <td>
                                        <?php if (isset($family['head_name'])): ?>
                                            <strong><?php echo htmlspecialchars($family['head_name']); ?></strong>
                                            <?php if (isset($family['head_phone'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($family['head_phone']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($family['family_head_nic']): ?>
                                            <code><?php echo htmlspecialchars($family['family_head_nic']); ?></code>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $family['member_count']; ?> members</span>
                                    </td>
                                    <td>
                                        <?php if ($family['is_transferred']): ?>
                                            <span class="badge bg-warning" title="Transferred from another GN">
                                                <i class="fas fa-exchange-alt"></i> Transferred
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-home"></i> Local
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($family['address']): ?>
                                            <small><?php echo htmlspecialchars(substr($family['address'], 0, 50)); ?>
                                            <?php if (strlen($family['address']) > 50): ?>...<?php endif; ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($family['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <!-- View Details -->
                                            <a href="family_detail.php?family_id=<?php echo urlencode($family['family_id']); ?>" 
                                               class="btn btn-outline-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <!-- View Members -->
                                            <a href="view_citizens.php?family_id=<?php echo urlencode($family['family_id']); ?>" 
                                               class="btn btn-outline-primary" title="View Members">
                                                <i class="fas fa-users"></i>
                                            </a>
                                            
                                            <!-- Transfer History -->
                                            <?php if ($family['is_transferred'] || $family['original_gn_id'] != $family['gn_id']): ?>
                                                <button type="button" class="btn btn-outline-warning" 
                                                        title="View Transfer History"
                                                        onclick="showTransferHistory('<?php echo addslashes($family['family_id']); ?>')">
                                                    <i class="fas fa-history"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <!-- Reports -->
                                            <a href="family_report.php?family_id=<?php echo urlencode($family['family_id']); ?>" 
                                               class="btn btn-outline-success" title="Generate Report">
                                                <i class="fas fa-file-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalFamilies > $perPage): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php
                        $totalPages = ceil($totalFamilies / $perPage);
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        // Previous button
                        if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif;
                        
                        // Page numbers
                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor;
                        
                        // Next button
                        if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
        <div class="card-footer text-muted">
            <small>
                <i class="fas fa-info-circle"></i> 
                Showing families from <?php echo $gnFilter ? 'selected GN' : 'all GN divisions'; ?>.
                <?php if ($totalFamilies > 0): ?>
                    Page <?php echo $page; ?> of <?php echo ceil($totalFamilies / $perPage); ?>
                <?php endif; ?>
            </small>
        </div>
    </div>
    
    <!-- Export Options Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-download"></i> Export Families Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="list-group">
                        <a href="export_families.php?format=csv&<?php echo http_build_query($_GET); ?>" 
                           class="list-group-item list-group-item-action">
                            <i class="fas fa-file-csv text-success"></i> Export as CSV
                        </a>
                        <a href="export_families.php?format=excel&<?php echo http_build_query($_GET); ?>" 
                           class="list-group-item list-group-item-action">
                            <i class="fas fa-file-excel text-success"></i> Export as Excel
                        </a>
                        <a href="export_families.php?format=pdf&<?php echo http_build_query($_GET); ?>" 
                           class="list-group-item list-group-item-action">
                            <i class="fas fa-file-pdf text-danger"></i> Export as PDF
                        </a>
                        <a href="print_families.php?<?php echo http_build_query($_GET); ?>" 
                           class="list-group-item list-group-item-action" target="_blank">
                            <i class="fas fa-print text-primary"></i> Print View
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#familiesTable').DataTable({
        "pageLength": 25,
        "order": [[7, 'desc']],
        "dom": '<"top"f>rt<"bottom"lip><"clear">',
        "language": {
            "search": "Search families:",
            "lengthMenu": "Show _MENU_ families per page",
            "info": "Showing _START_ to _END_ of _TOTAL_ families",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            }
        }
    });
    
    // Export button click
    $('#exportBtn').click(function() {
        $('#exportModal').modal('show');
    });
    
    // Auto-refresh every 5 minutes
    setInterval(function() {
        $.ajax({
            url: 'check_family_updates.php',
            type: 'GET',
            success: function(response) {
                if (response.newFamilies > 0) {
                    // Show notification
                    showNotification(response.newFamilies + ' new families added');
                }
            }
        });
    }, 300000);
});

function showTransferHistory(familyId) {
    $.ajax({
        url: 'get_transfer_history.php',
        type: 'GET',
        data: {family_id: familyId},
        success: function(response) {
            $('#transferHistoryContent').html(response);
            $('#transferHistoryModal').modal('show');
        }
    });
}

function showNotification(message) {
    // Create notification element
    var notification = $('<div class="alert alert-info alert-dismissible fade show position-fixed" style="top:20px;right:20px;z-index:9999;">' +
                        '<i class="fas fa-bell me-2"></i>' + message +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
                        '</div>');
    
    $('body').append(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(function() {
        notification.alert('close');
    }, 5000);
}

// GN Division selector with search
$('#gn_id').select2({
    placeholder: "Select GN Division",
    allowClear: true
});
</script>

<!-- Transfer History Modal -->
<div class="modal fade" id="transferHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-history"></i> Transfer History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="transferHistoryContent">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
include '../includes/footer.php';
?>