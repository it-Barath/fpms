<?php
// users/search_family.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Search Family";
$pageIcon = "bi bi-search";
$pageDescription = "Search for families by ID, head name or address";
$bodyClass = "bg-light";

try {
    require_once '../config.php';
    require_once '../classes/Auth.php';
    
    // Check if session is already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in and has GN level access
    if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'gn') {
        header('Location: ../../login.php');
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

    $search_results = [];
    $search_query = '';
    $search_type = '';
    $error = '';
    $message = '';
    
    // Process search form
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['search'])) {
        $search_query = isset($_POST['search_query']) ? trim($_POST['search_query']) : (isset($_GET['search']) ? trim($_GET['search']) : '');
        $search_type = isset($_POST['search_type']) ? $_POST['search_type'] : (isset($_GET['type']) ? $_GET['type'] : 'family_id');
        
        if (!empty($search_query)) {
            try {
                // Prepare search SQL based on search type
                $sql = "SELECT 
                    f.family_id,
                    f.address as family_address,
                    f.total_members,
                    f.created_at,
                    c.full_name as head_name,
                    c.identification_number as head_nic
                FROM families f
                LEFT JOIN citizens c ON f.family_id = c.family_id AND c.relation_to_head = 'Self'
                WHERE f.gn_id = ?";
                
                $params = [$gn_id];
                $types = "s";
                
                switch ($search_type) {
                    case 'family_id':
                        $sql .= " AND f.family_id LIKE ?";
                        $params[] = "%$search_query%";
                        $types .= "s";
                        break;
                        
                    case 'head_name':
                        $sql .= " AND (c.full_name LIKE ? OR c.name_with_initials LIKE ?)";
                        $params[] = "%$search_query%";
                        $params[] = "%$search_query%";
                        $types .= "ss";
                        break;
                        
                    case 'head_nic':
                        $sql .= " AND c.identification_number LIKE ?";
                        $params[] = "%$search_query%";
                        $types .= "s";
                        break;
                        
                    case 'address':
                        $sql .= " AND f.address LIKE ?";
                        $params[] = "%$search_query%";
                        $types .= "s";
                        break;
                }
                
                $sql .= " ORDER BY f.created_at DESC LIMIT 100";
                
                $stmt = $db->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Database query preparation failed: " . $db->error);
                }
                
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $search_results = $result->fetch_all(MYSQLI_ASSOC);
                
                if (empty($search_results)) {
                    $message = "No families found matching your search criteria.";
                }
                
            } catch (Exception $e) {
                $error = "Search error: " . $e->getMessage();
                error_log("Family Search Error: " . $e->getMessage());
            }
        } else {
            $error = "Please enter a search term";
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Family Search System Error: " . $e->getMessage());
}
?>

<?php require_once '../includes/header.php'; ?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../includes/sidebar.php'; ?>
        </div>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-search me-2"></i>
                    Search Families
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="../users/gn/citizens/list_families.php" class="btn btn-secondary">
                        <i class="bi bi-list"></i> View All Families
                    </a>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle me-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Search Form -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-search me-2"></i> Search Criteria</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" id="searchForm">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search Type</label>
                                <select class="form-select" name="type" id="searchType">
                                    <option value="family_id" <?php echo ($search_type === 'family_id') ? 'selected' : ''; ?>>Family ID</option>
                                    <option value="head_name" <?php echo ($search_type === 'head_name') ? 'selected' : ''; ?>>Family Head Name</option>
                                    <option value="head_nic" <?php echo ($search_type === 'head_nic') ? 'selected' : ''; ?>>Head NIC</option>
                                    <option value="address" <?php echo ($search_type === 'address') ? 'selected' : ''; ?>>Family Address</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Search Term</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="search" 
                                           value="<?php echo htmlspecialchars($search_query); ?>"
                                           placeholder="Enter search term..." required>
                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-search"></i> Search
                                    </button>
                                </div>
                                <div id="searchHint" class="form-text"></div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <a href="search_family.php" class="btn btn-outline-secondary w-100">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Search Results -->
            <?php if (!empty($search_results)): ?>
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-list-check me-2"></i>
                            Search Results (<?php echo count($search_results); ?> found)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th>Family ID</th>
                                        <th>Family Head</th>
                                        <th>Head NIC</th>
                                        <th>Address</th>
                                        <th>Members</th>
                                        <th>Registered</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($search_results as $family): ?>
                                        <tr>
                                            <td>
                                                <span class="font-monospace text-primary"><?php echo htmlspecialchars($family['family_id']); ?></span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($family['head_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($family['head_nic'] ?? 'N/A'); ?>
                                            </td>
                                            <td class="small">
                                                <?php echo nl2br(htmlspecialchars(substr($family['family_address'] ?? '', 0, 100))); ?>
                                                <?php if (strlen($family['family_address'] ?? '') > 100): ?>...<?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $family['total_members']; ?></span>
                                            </td>
                                            <td class="small">
                                                <?php echo date('Y-m-d', strtotime($family['created_at'])); ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="../users/gn/citizens/view_family.php?id=<?php echo urlencode($family['family_id']); ?>" 
                                                       class="btn btn-outline-primary" title="View Family">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="../users/gn/citizens/add_member.php?family_id=<?php echo urlencode($family['family_id']); ?>" 
                                                       class="btn btn-outline-success" title="Add Member">
                                                        <i class="bi bi-person-plus"></i>
                                                    </a>
                                                    <a href="search_member.php?family_id=<?php echo urlencode($family['family_id']); ?>" 
                                                       class="btn btn-outline-info" title="Search Members">
                                                        <i class="bi bi-search"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php elseif (!empty($search_query)): ?>
                <!-- No Results Found -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-search display-1 text-muted mb-3"></i>
                        <h3>No Families Found</h3>
                        <p class="text-muted">No families found matching "<?php echo htmlspecialchars($search_query); ?>"</p>
                        <a href="search_family.php" class="btn btn-primary mt-3">
                            <i class="bi bi-arrow-clockwise"></i> Try Different Search
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Initial State -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-search display-1 text-muted mb-3"></i>
                        <h3>Search Families</h3>
                        <p class="text-muted">Enter a search term above to find families in your GN division</p>
                        <div class="row mt-4">
                            <div class="col-md-4 mb-2">
                                <div class="p-3 border rounded">
                                    <i class="bi bi-card-heading fs-1 text-primary"></i>
                                    <h5 class="mt-2">Search by Family ID</h5>
                                    <p class="small text-muted">Find using the 14-digit Family ID</p>
                                </div>
                            </div>
                            <div class="col-md-4 mb-2">
                                <div class="p-3 border rounded">
                                    <i class="bi bi-person fs-1 text-success"></i>
                                    <h5 class="mt-2">Search by Head Name</h5>
                                    <p class="small text-muted">Find by family head's full name</p>
                                </div>
                            </div>
                            <div class="col-md-4 mb-2">
                                <div class="p-3 border rounded">
                                    <i class="bi bi-house fs-1 text-info"></i>
                                    <h5 class="mt-2">Search by Address</h5>
                                    <p class="small text-muted">Find families by their address</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<style>
    .table-hover tbody tr:hover {
        background-color: rgba(0, 123, 255, 0.1);
    }
    .btn-group .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
    .font-monospace {
        font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, monospace;
    }
</style>

<script src="../../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchType = document.getElementById('searchType');
        const searchInput = document.querySelector('input[name="search"]');
        const searchHint = document.getElementById('searchHint');
        
        // Set placeholder based on search type
        function updateSearchHint() {
            const type = searchType.value;
            let hint = '';
            let placeholder = '';
            
            switch(type) {
                case 'family_id':
                    hint = 'Enter Family ID (14 digits)';
                    placeholder = 'e.g., 12345678901234';
                    break;
                case 'head_name':
                    hint = 'Enter family head name (full name or initials)';
                    placeholder = 'e.g., John Smith or J.A. Perera';
                    break;
                case 'head_nic':
                    hint = 'Enter NIC number (9 digits with V/X or 12 digits)';
                    placeholder = 'e.g., 901234567V or 199012345678';
                    break;
                case 'address':
                    hint = 'Enter address keyword or street name';
                    placeholder = 'e.g., Main Street or Colombo';
                    break;
            }
            
            searchInput.placeholder = placeholder;
            searchHint.textContent = hint;
        }
        
        // Initialize hint
        updateSearchHint();
        
        // Update hint when search type changes
        searchType.addEventListener('change', updateSearchHint);
        
        // Form validation
        const form = document.getElementById('searchForm');
        form.addEventListener('submit', function(e) {
            if (!searchInput.value.trim()) {
                e.preventDefault();
                searchInput.focus();
                searchInput.classList.add('is-invalid');
                return false;
            }
            searchInput.classList.remove('is-invalid');
        });
        
        // Clear validation on input
        searchInput.addEventListener('input', function() {
            if (this.value.trim()) {
                this.classList.remove('is-invalid');
            }
        });
    });
</script>

<?php 
// Include footer if exists
$footer_path = '../includes/footer.php';
if (file_exists($footer_path)) {
    include $footer_path;
} else {
    echo '</div></div></body></html>';
}
?>