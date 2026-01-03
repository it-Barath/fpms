<?php
// users/search_member.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Search Family Member";
$pageIcon = "bi bi-search";
$pageDescription = "Search for family members by name, NIC, or family ID";
$bodyClass = "bg-light";

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

    $search_results = [];
    $search_query = '';
    $search_type = 'member_name';
    $family_id = $_GET['family_id'] ?? '';
    $family_info = null;
    $error = '';
    $message = '';
    $total_results = 0;
    
    // Sanitize inputs
    $sanitizer = new Sanitizer();
    $family_id = $sanitizer->sanitize($family_id);
    
    // Get family info if family_id is provided
    if (!empty($family_id)) {
        $family_stmt = $db->prepare("
            SELECT f.*, c.full_name as head_name, c.identification_number as head_nic
            FROM families f
            LEFT JOIN citizens c ON f.family_id = c.family_id AND c.relation_to_head = 'Self'
            WHERE f.family_id = ? AND f.gn_id = ?
        ");
        if (!$family_stmt) {
            throw new Exception("Family query preparation failed: " . $db->error);
        }
        $family_stmt->bind_param("ss", $family_id, $gn_id);
        $family_stmt->execute();
        $family_result = $family_stmt->get_result();
        $family_info = $family_result->fetch_assoc();
        
        if (!$family_info) {
            $error = "Family not found or you don't have access to this family.";
            $family_id = ''; // Reset family_id if not found
        }
    }
    
    // Process search form - Use GET method for search
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
        $search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
        $search_type = isset($_GET['type']) ? $_GET['type'] : 'member_name';
        
        // Sanitize search inputs
        $search_query = $sanitizer->sanitize($search_query);
        $search_type = $sanitizer->sanitize($search_type);
        
        if (!empty($search_query)) {
            try {
                // Prepare search SQL
                $sql = "SELECT 
                    c.citizen_id,
                    c.family_id,
                    c.identification_type,
                    c.identification_number,
                    c.full_name,
                    c.name_with_initials,
                    c.gender,
                    c.date_of_birth,
                    c.relation_to_head,
                    c.mobile_phone,
                    c.home_phone,
                    c.email,
                    c.address as member_address,
                    f.address as family_address,
                    f.total_members,
                    f.created_at as family_reg_date,
                    fh.full_name as head_name,
                    fh.identification_number as head_nic,
                    TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) as age
                FROM citizens c
                INNER JOIN families f ON c.family_id = f.family_id
                LEFT JOIN citizens fh ON f.family_id = fh.family_id AND fh.relation_to_head = 'Self'
                WHERE f.gn_id = ?";
                
                $params = [$gn_id];
                $types = "s";
                
                // Add family filter if specific family is selected
                if (!empty($family_id) && $family_info) {
                    $sql .= " AND c.family_id = ?";
                    $params[] = $family_id;
                    $types .= "s";
                }
                
                // Add search conditions with proper wildcard placement
                switch ($search_type) {
                    case 'member_name':
                        $sql .= " AND (c.full_name LIKE ? OR c.name_with_initials LIKE ?)";
                        $params[] = "%" . $search_query . "%";
                        $params[] = "%" . $search_query . "%";
                        $types .= "ss";
                        break;
                        
                    case 'identification':
                        $sql .= " AND (c.identification_number LIKE ? OR c.identification_number LIKE ?)";
                        // Search with and without spaces/dashes
                        $clean_id = preg_replace('/[-\s]/', '', $search_query);
                        $params[] = "%" . $clean_id . "%";
                        $params[] = "%" . $search_query . "%";
                        $types .= "ss";
                        break;
                        
                    case 'family_id':
                        $sql .= " AND c.family_id LIKE ?";
                        $params[] = "%" . $search_query . "%";
                        $types .= "s";
                        break;
                        
                    case 'phone':
                        $clean_phone = preg_replace('/[-\s]/', '', $search_query);
                        $sql .= " AND (REPLACE(REPLACE(c.mobile_phone, ' ', ''), '-', '') LIKE ? 
                                   OR REPLACE(REPLACE(c.home_phone, ' ', ''), '-', '') LIKE ?)";
                        $params[] = "%" . $clean_phone . "%";
                        $params[] = "%" . $clean_phone . "%";
                        $types .= "ss";
                        break;
                        
                    case 'dob':
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search_query)) {
                            $sql .= " AND c.date_of_birth = ?";
                            $params[] = $search_query;
                            $types .= "s";
                        } else {
                            $error = "Please enter date in YYYY-MM-DD format";
                        }
                        break;
                }
                
                $sql .= " ORDER BY 
                    CASE WHEN c.relation_to_head = 'Self' THEN 1 
                         WHEN c.relation_to_head IN ('Husband', 'Wife') THEN 2
                         WHEN c.relation_to_head IN ('Son', 'Daughter') THEN 3
                         WHEN c.relation_to_head IN ('Father', 'Mother') THEN 4
                         ELSE 5 
                    END,
                    CASE c.gender
                        WHEN 'male' THEN 1
                        WHEN 'female' THEN 2
                        ELSE 3
                    END,
                    c.date_of_birth";
                
                // First, get total count for pagination info
                $count_sql = preg_replace('/SELECT.*FROM/i', 'SELECT COUNT(*) as total FROM', $sql);
                $count_stmt = $db->prepare($count_sql);
                if ($count_stmt) {
                    $count_stmt->bind_param($types, ...$params);
                    $count_stmt->execute();
                    $count_result = $count_stmt->get_result();
                    $count_row = $count_result->fetch_assoc();
                    $total_results = $count_row['total'] ?? 0;
                }
                
                // Add LIMIT for main query
                $sql .= " LIMIT 100";
                
                $stmt = $db->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Database query preparation failed: " . $db->error);
                }
                
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $search_results = $result->fetch_all(MYSQLI_ASSOC);
                
                if (empty($search_results)) {
                    $message = "No members found matching your search criteria.";
                } else if ($total_results > 100) {
                    $message = "Showing 100 of $total_results results. Use more specific search terms to narrow results.";
                }
                
            } catch (Exception $e) {
                $error = "Search error: " . $e->getMessage();
                error_log("Member Search Error: " . $e->getMessage());
            }
        } else {
            $error = "Please enter a search term";
        }
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("Member Search System Error: " . $e->getMessage());
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
        <main class="col-md-9 ms-sm-auto col-xl-10 px-md-4 main-content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-search me-2"></i>
                    Search Family Members
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <?php if (!empty($family_id) && $family_info): ?>
                        <a href="../gn/citizens/view_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-secondary me-2">
                            <i class="bi bi-arrow-left"></i> Back to Family
                        </a>
                    <?php endif; ?>
                    <a href="search_family.php" class="btn btn-outline-secondary">
                        <i class="bi bi-house"></i> Search Families
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
            
            <!-- Family Info Card (if searching within specific family) -->
            <?php if (!empty($family_id) && $family_info): ?>
                <div class="card mb-4 border-info">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-house-door me-2"></i> Searching Within Family</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-2">
                                <strong>Family ID:</strong><br>
                                <span class="font-monospace text-primary"><?php echo htmlspecialchars($family_info['family_id']); ?></span>
                            </div>
                            <div class="col-md-2">
                                <strong>Family Head:</strong><br>
                                <?php echo htmlspecialchars($family_info['head_name'] ?? 'N/A'); ?>
                            </div>
                            <div class="col-md-2">
                                <strong>Head NIC:</strong><br>
                                <?php echo htmlspecialchars($family_info['head_nic'] ?? 'N/A'); ?>
                            </div>
                            <div class="col-md-2">
                                <strong>Total Members:</strong><br>
                                <span class="badge bg-primary"><?php echo $family_info['total_members']; ?> members</span>
                            </div>
                            <div class="col-md-4">
                                <strong>Address:</strong><br>
                                <small><?php echo nl2br(htmlspecialchars($family_info['address'] ?? 'Address not provided')); ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent">
                        <a href="../gn/citizens/add_member.php?family_id=<?php echo urlencode($family_id); ?>" 
                           class="btn btn-sm btn-success">
                            <i class="bi bi-person-plus"></i> Add New Member to This Family
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Search Form -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-search me-2"></i> Search Criteria</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" id="searchForm">
                        <?php if (!empty($family_id)): ?>
                            <input type="hidden" name="family_id" value="<?php echo htmlspecialchars($family_id); ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search Type</label>
                                <select class="form-select" name="type" id="searchType">
                                    <option value="member_name" <?php echo ($search_type === 'member_name') ? 'selected' : ''; ?>>Member Name</option>
                                    <option value="identification" <?php echo ($search_type === 'identification') ? 'selected' : ''; ?>>Identification Number</option>
                                    <option value="family_id" <?php echo ($search_type === 'family_id') ? 'selected' : ''; ?>>Family ID</option>
                                    <option value="phone" <?php echo ($search_type === 'phone') ? 'selected' : ''; ?>>Phone Number</option>
                                    <option value="dob" <?php echo ($search_type === 'dob') ? 'selected' : ''; ?>>Date of Birth</option>
                                </select>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">Search Term</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="search" 
                                           value="<?php echo htmlspecialchars($search_query); ?>"
                                           placeholder="Enter search term..." required
                                           id="searchInput">
                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-search"></i> Search
                                    </button>
                                </div>
                                <div id="searchHint" class="form-text"></div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <a href="search_member.php<?php echo !empty($family_id) ? '?family_id=' . urlencode($family_id) : ''; ?>" 
                                   class="btn btn-outline-secondary w-100">
                                    <i class="bi bi-arrow-clockwise"></i> Clear
                                </a>
                            </div>
                        </div>
                        
                        <?php if (empty($family_id)): ?>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="form-text">
                                        <i class="bi bi-info-circle"></i> Searching all families in your GN division
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <?php if (!empty($search_results)): ?>
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body p-3">
                                <h6 class="card-title">Total Found</h6>
                                <h2 class="mb-0"><?php echo $total_results; ?></h2>
                                <small>members</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body p-3">
                                <h6 class="card-title">Unique Families</h6>
                                <h2 class="mb-0"><?php echo count(array_unique(array_column($search_results, 'family_id'))); ?></h2>
                                <small>families</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <?php 
                        $head_count = 0;
                        $male_count = 0;
                        $female_count = 0;
                        foreach ($search_results as $member) {
                            if ($member['relation_to_head'] === 'Self') $head_count++;
                            if ($member['gender'] === 'male') $male_count++;
                            if ($member['gender'] === 'female') $female_count++;
                        }
                        ?>
                        <div class="card text-white bg-info">
                            <div class="card-body p-3">
                                <h6 class="card-title">Family Heads</h6>
                                <h2 class="mb-0"><?php echo $head_count; ?></h2>
                                <small>heads</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body p-3">
                                <h6 class="card-title">Gender Ratio</h6>
                                <h5 class="mb-0"><?php echo $male_count; ?>M : <?php echo $female_count; ?>F</h5>
                                <small>male:female</small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Search Results -->
            <?php if (!empty($search_results)): ?>
                <div class="card">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-list-check me-2"></i>
                            Search Results 
                            <small class="fs-6 fw-normal">
                                (<?php echo count($search_results); ?> displayed of <?php echo $total_results; ?> total)
                            </small>
                        </h5>
                        <button class="btn btn-light btn-sm" onclick="exportResults()">
                            <i class="bi bi-download"></i> Export
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped" id="searchResultsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Member Details</th>
                                        <th>Family Information</th>
                                        <th>Identification</th>
                                        <th>Contact Info</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; ?>
                                    <?php foreach ($search_results as $member): ?>
                                        <tr>
                                            <td class="text-muted"><?php echo $counter++; ?></td>
                                            <td>
                                                <strong class="d-block">
                                                    <?php echo htmlspecialchars($member['full_name']); ?>
                                                    <?php if ($member['relation_to_head'] === 'Self'): ?>
                                                        <span class="badge bg-warning ms-1">Head</span>
                                                    <?php endif; ?>
                                                </strong>
                                                <small class="text-muted d-block">
                                                    <i class="bi bi-person"></i> <?php echo htmlspecialchars($member['name_with_initials']); ?>
                                                </small>
                                                <small class="text-muted d-block">
                                                    <i class="bi bi-gender-<?php echo $member['gender'] === 'male' ? 'male' : ($member['gender'] === 'female' ? 'female' : 'other'); ?>"></i>
                                                    <?php echo ucfirst($member['gender']); ?> | 
                                                    <i class="bi bi-calendar"></i> Age: <?php echo $member['age']; ?> yrs
                                                </small>
                                                <span class="badge bg-info mt-1"><?php echo htmlspecialchars($member['relation_to_head']); ?></span>
                                            </td>
                                            <td>
                                                <div class="mb-1">
                                                    <strong>Family ID:</strong><br>
                                                    <a href="../gn/citizens/view_family.php?id=<?php echo urlencode($member['family_id']); ?>"
                                                       class="text-decoration-none font-monospace">
                                                        <?php echo htmlspecialchars($member['family_id']); ?>
                                                    </a>
                                                </div>
                                                <div class="mb-1">
                                                    <strong>Head:</strong><br>
                                                    <?php echo htmlspecialchars($member['head_name'] ?? 'N/A'); ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-people"></i> <?php echo $member['total_members']; ?> members
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($member['identification_number'])): ?>
                                                    <div class="mb-1">
                                                        <strong><?php echo strtoupper($member['identification_type']); ?>:</strong><br>
                                                        <span class="font-monospace"><?php echo htmlspecialchars($member['identification_number']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="small text-muted">
                                                    <i class="bi bi-calendar"></i> DOB: <?php echo date('Y-m-d', strtotime($member['date_of_birth'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($member['mobile_phone'])): ?>
                                                    <div class="mb-1">
                                                        <i class="bi bi-phone"></i> <?php echo htmlspecialchars($member['mobile_phone']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($member['home_phone'])): ?>
                                                    <div class="mb-1">
                                                        <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($member['home_phone']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($member['email'])): ?>
                                                    <div>
                                                        <i class="bi bi-envelope"></i> 
                                                        <a href="mailto:<?php echo htmlspecialchars($member['email']); ?>">
                                                            <?php echo htmlspecialchars($member['email']); ?>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group-vertical btn-group-sm" role="group">
                                                    <a href="../gn/citizens/view_member.php?family_id=<?php echo urlencode($member['family_id']); ?>&member_id=<?php echo $member['citizen_id']; ?>" 
                                                       class="btn btn-outline-primary" title="View Member Details">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                    <a href="../gn/citizens/edit_member.php?family_id=<?php echo urlencode($member['family_id']); ?>&member_id=<?php echo $member['citizen_id']; ?>" 
                                                       class="btn btn-outline-warning" title="Edit Member">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </a>
                                                    <a href="../gn/citizens/view_family.php?id=<?php echo urlencode($member['family_id']); ?>" 
                                                       class="btn btn-outline-info" title="View Family">
                                                        <i class="bi bi-house-door"></i> Family
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-muted">
                        <small>
                            <i class="bi bi-info-circle"></i> 
                            <?php if ($total_results > 100): ?>
                                Showing first 100 results. Use more specific search terms for better results.
                            <?php else: ?>
                                End of results
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            <?php elseif (!empty($search_query)): ?>
                <!-- No Results Found -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-search display-1 text-muted mb-3"></i>
                        <h3>No Members Found</h3>
                        <p class="text-muted">No members found matching "<?php echo htmlspecialchars($search_query); ?>"</p>
                        <div class="mt-4">
                            <a href="search_member.php<?php echo !empty($family_id) ? '?family_id=' . urlencode($family_id) : ''; ?>" 
                               class="btn btn-outline-primary me-2">
                                <i class="bi bi-arrow-clockwise"></i> Clear Search
                            </a>
                            <?php if (empty($family_id)): ?>
                                <a href="search_family.php" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Search Families First
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Initial State -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-people display-1 text-muted mb-3"></i>
                        <h3>Search Family Members</h3>
                        <p class="text-muted mb-4">
                            <?php if (!empty($family_id) && $family_info): ?>
                                You are searching within Family: <strong><?php echo htmlspecialchars($family_info['family_id']); ?></strong>
                            <?php else: ?>
                                Enter a search term above to find family members in your GN division
                            <?php endif; ?>
                        </p>
                        
                        <div class="row justify-content-center mt-4">
                            <div class="col-lg-10">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <div class="card h-100 border-start border-start-4 border-start-primary">
                                            <div class="card-body text-center">
                                                <i class="bi bi-person fs-1 text-primary mb-3"></i>
                                                <h5>Search by Name</h5>
                                                <p class="small text-muted">Find members by full name or initials</p>
                                                <div class="mt-3">
                                                    <small class="text-muted">Examples:</small><br>
                                                    <span class="badge bg-light text-dark">John Smith</span><br>
                                                    <span class="badge bg-light text-dark">J.A. Perera</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="card h-100 border-start border-start-4 border-start-success">
                                            <div class="card-body text-center">
                                                <i class="bi bi-card-text fs-1 text-success mb-3"></i>
                                                <h5>Search by ID</h5>
                                                <p class="small text-muted">Find by NIC, Passport, or other ID</p>
                                                <div class="mt-3">
                                                    <small class="text-muted">Examples:</small><br>
                                                    <span class="badge bg-light text-dark">901234567V</span><br>
                                                    <span class="badge bg-light text-dark">N1234567</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="card h-100 border-start border-start-4 border-start-info">
                                            <div class="card-body text-center">
                                                <i class="bi bi-telephone fs-1 text-info mb-3"></i>
                                                <h5>Search by Phone</h5>
                                                <p class="small text-muted">Find members by contact number</p>
                                                <div class="mt-3">
                                                    <small class="text-muted">Examples:</small><br>
                                                    <span class="badge bg-light text-dark">0712345678</span><br>
                                                    <span class="badge bg-light text-dark">0112345678</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (empty($family_id)): ?>
                            <div class="mt-5">
                                <p class="text-muted mb-3">Or search for a specific family first:</p>
                                <a href="search_family.php" class="btn btn-lg btn-outline-primary">
                                    <i class="bi bi-search me-2"></i> Search Families
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<style>
    .table-hover tbody tr:hover {
        background-color: rgba(13, 110, 253, 0.1);
    }
    .btn-group-vertical .btn {
        margin-bottom: 2px;
        font-size: 0.875rem;
        padding: 0.25rem 0.5rem;
    }
    .font-monospace {
        font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, monospace;
        font-size: 0.9em;
    }
    .border-start-4 {
        border-left-width: 4px !important;
    }
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
</style>

<script src="../../assets/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchType = document.getElementById('searchType');
        const searchInput = document.getElementById('searchInput');
        const searchHint = document.getElementById('searchHint');
        
        // Set placeholder based on search type
        function updateSearchHint() {
            const type = searchType.value;
            let hint = '';
            let placeholder = '';
            
            switch(type) {
                case 'member_name':
                    hint = 'Enter member name (full name or initials)';
                    placeholder = 'e.g., John Smith or J.A. Perera';
                    break;
                case 'identification':
                    hint = 'Enter identification number (NIC, Passport, etc.)';
                    placeholder = 'e.g., 901234567V or AB123456';
                    break;
                case 'family_id':
                    hint = 'Enter Family ID (14 digits)';
                    placeholder = 'e.g., 12345678901234';
                    break;
                case 'phone':
                    hint = 'Enter phone number (mobile or home)';
                    placeholder = 'e.g., 0712345678';
                    break;
                case 'dob':
                    hint = 'Enter date of birth (YYYY-MM-DD format)';
                    placeholder = 'e.g., 1990-05-15';
                    break;
            }
            
            searchInput.placeholder = placeholder;
            searchHint.textContent = hint;
            
            // Set input type for date
            if (type === 'dob') {
                searchInput.type = 'date';
            } else {
                searchInput.type = 'text';
            }
        }
        
        // Initialize hint
        updateSearchHint();
        
        // Update hint when search type changes
        searchType.addEventListener('change', updateSearchHint);
        
        // Form validation
        const form = document.getElementById('searchForm');
        form.addEventListener('submit', function(e) {
            const value = searchInput.value.trim();
            if (!value) {
                e.preventDefault();
                searchInput.focus();
                searchInput.classList.add('is-invalid');
                return false;
            }
            
            // Additional validation for specific types
            const type = searchType.value;
            if (type === 'dob' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                e.preventDefault();
                alert('Please enter date in YYYY-MM-DD format');
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
        
        // Auto-focus search input
        searchInput.focus();
    });
    
    // Export function
    function exportResults() {
        const table = document.getElementById('searchResultsTable');
        const ws = XLSX.utils.table_to_sheet(table);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Search Results");
        
        // Generate filename
        const familyId = "<?php echo !empty($family_id) ? $family_id : 'all-families'; ?>";
        const searchTerm = "<?php echo !empty($search_query) ? preg_replace('/[^a-z0-9]/i', '-', $search_query) : 'search'; ?>";
        const date = new Date().toISOString().split('T')[0];
        const filename = `member-search-${familyId}-${searchTerm}-${date}.xlsx`;
        
        XLSX.writeFile(wb, filename);
    }
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