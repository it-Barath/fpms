<?php
require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';

$auth = new Auth();
$auth->requireRole(['moha', 'district', 'division']); // Form management access

// Get database connection
$dbConnection = getMainConnection();
$formManager = new FormManager($dbConnection);

// Get current user info
$currentUser = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$officeCode = $_SESSION['office_code'];

// Handle filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'all';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$category = isset($_GET['category']) ? $_GET['category'] : '';

// Get available forms for the user
$forms = $formManager->getAvailableForms($currentUser, $userType, $officeCode);

// If filters are applied, filter the results
if ($search || $type !== 'all' || $status !== 'all' || $category) {
    $filteredForms = [];
    foreach ($forms as $form) {
        $matches = true;
        
        // Search filter
        if ($search) {
            $searchLower = strtolower($search);
            $matches = strpos(strtolower($form['form_name']), $searchLower) !== false ||
                      strpos(strtolower($form['form_code']), $searchLower) !== false ||
                      strpos(strtolower($form['form_description']), $searchLower) !== false;
        }
        
        // Type filter
        if ($matches && $type !== 'all') {
            $matches = $form['target_entity'] === $type;
        }
        
        // Status filter
        if ($matches && $status !== 'all') {
            switch ($status) {
                case 'active':
                    $matches = $form['is_active'] == 1;
                    break;
                case 'inactive':
                    $matches = $form['is_active'] == 0;
                    break;
                case 'expired':
                    $matches = $form['end_date'] && strtotime($form['end_date']) < time();
                    break;
                case 'upcoming':
                    $matches = $form['start_date'] && strtotime($form['start_date']) > time();
                    break;
                case 'current':
                    $matches = $form['is_active'] == 1 && 
                              (!$form['start_date'] || strtotime($form['start_date']) <= time()) &&
                              (!$form['end_date'] || strtotime($form['end_date']) >= time());
                    break;
            }
        }
        
        // Category filter
        if ($matches && $category) {
            $matches = $form['form_category'] === $category;
        }
        
        if ($matches) {
            $filteredForms[] = $form;
        }
    }
    $forms = $filteredForms;
}

// Get form categories for filter dropdown
$categories = $formManager->getFormCategories();

// Get form statistics
$stats = $formManager->getFormStats();

$pageTitle = "Manage Forms - " . SITE_NAME;

// Handle form deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_form'])) {
    $formId = intval($_POST['form_id']);
    
    // Check if user has permission to delete (creator or MOHA)
    $form = $formManager->getFormById($formId);
    if ($form && ($form['created_by_user_id'] == $currentUser || $userType === 'moha')) {
        $result = $formManager->deleteForm($formId);
        
        if ($result['success']) {
            $_SESSION['flash_message'] = [
                'type' => 'success',
                'message' => 'Form deleted successfully!'
            ];
            header("Location: manage.php");
            exit();
        } else {
            $error = $result['error'];
        }
    } else {
        $error = "You don't have permission to delete this form";
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <main class="ms-sm-auto px-md-4">
                <!-- Top Navigation -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2">
                            <i class="bi bi-file-earmark-text"></i> Manage Forms
                        </h1>
                        <p class="text-muted mb-0">
                            Create, edit, and manage data collection forms for families and members
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <?php if ($userType === 'moha' || $userType === 'district'): ?>
                        <a href="create.php" class="btn btn-success me-2">
                            <i class="bi bi-plus-circle"></i> Create New Form
                        </a>
                        <?php endif; ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <i class="bi bi-download"></i> Export
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#">Export Form List (CSV)</a></li>
                                <li><a class="dropdown-item" href="#">Export Form Templates</a></li>
                                <li><a class="dropdown-item" href="#">Export All Forms Data</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <?php displayFlashMessage(); ?>
                
                <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Total Forms</h6>
                                        <h3 class="mb-0"><?php echo $stats['total_forms'] ?? 0; ?></h3>
                                    </div>
                                    <div class="icon">
                                        <i class="bi bi-files fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Active Forms</h6>
                                        <h3 class="mb-0"><?php echo $stats['active_forms'] ?? 0; ?></h3>
                                    </div>
                                    <div class="icon">
                                        <i class="bi bi-check-circle fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Family Submissions</h6>
                                        <h3 class="mb-0"><?php echo $stats['total_family_submissions'] ?? 0; ?></h3>
                                    </div>
                                    <div class="icon">
                                        <i class="bi bi-people fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Member Submissions</h6>
                                        <h3 class="mb-0"><?php echo $stats['total_member_submissions'] ?? 0; ?></h3>
                                    </div>
                                    <div class="icon">
                                        <i class="bi bi-person fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bi bi-filter"></i> Filter Forms
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-4">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Search forms..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-2">
                                <select class="form-control" name="type">
                                    <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="family" <?php echo $type === 'family' ? 'selected' : ''; ?>>Family Forms</option>
                                    <option value="member" <?php echo $type === 'member' ? 'selected' : ''; ?>>Member Forms</option>
                                    <option value="both" <?php echo $type === 'both' ? 'selected' : ''; ?>>Both Types</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-control" name="status">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="current" <?php echo $status === 'current' ? 'selected' : ''; ?>>Current</option>
                                    <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                    <option value="upcoming" <?php echo $status === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-control" name="category">
                                    <option value="" <?php echo empty($category) ? 'selected' : ''; ?>>All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" 
                                            <?php echo $category === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Forms List -->
                <div class="card">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-list-check"></i> Available Forms
                                <span class="badge bg-secondary"><?php echo count($forms); ?></span>
                            </h5>
                            <small class="text-muted">
                                Showing forms you have access to
                            </small>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($forms)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-file-earmark-x fs-1 text-muted"></i>
                            <h5 class="text-muted mt-3">No Forms Available</h5>
                            <p class="text-muted">
                                <?php if ($search || $type !== 'all' || $status !== 'all'): ?>
                                Try adjusting your filters or 
                                <?php endif; ?>
                                <?php if ($userType === 'moha' || $userType === 'district'): ?>
                                <a href="create.php">create a new form</a>.
                                <?php else: ?>
                                contact your administrator to get access to forms.
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Form Name</th>
                                        <th>Code</th>
                                        <th>Type</th>
                                        <th>Category</th>
                                        <th>Created By</th>
                                        <th>Status</th>
                                        <th>Submissions</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $form): ?>
                                    <?php
                                    // Get form-specific stats
                                    $formStats = $formManager->getFormStats($form['form_id']);
                                    $totalSubmissions = ($formStats['family_submissions'] ?? 0) + ($formStats['member_submissions'] ?? 0);
                                    
                                    // Status indicators
                                    $isExpired = $form['end_date'] && strtotime($form['end_date']) < time();
                                    $isUpcoming = $form['start_date'] && strtotime($form['start_date']) > time();
                                    $isCurrent = $form['is_active'] && 
                                                (!$form['start_date'] || strtotime($form['start_date']) <= time()) &&
                                                (!$form['end_date'] || strtotime($form['end_date']) >= time());
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($form['form_name']); ?></strong>
                                            <?php if ($form['form_description']): ?>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars(substr($form['form_description'], 0, 100)); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($form['form_code']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge 
                                                <?php echo $form['target_entity'] === 'family' ? 'bg-info' : 
                                                       ($form['target_entity'] === 'member' ? 'bg-warning' : 'bg-primary'); ?>">
                                                <?php echo ucfirst($form['target_entity']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($form['form_category']): ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($form['form_category']); ?></span>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="d-block"><?php echo htmlspecialchars($form['created_by_name'] ?? 'System'); ?></small>
                                            <small class="text-muted"><?php echo htmlspecialchars($form['created_by_office'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php if (!$form['is_active']): ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                            <?php elseif ($isExpired): ?>
                                            <span class="badge bg-danger">Expired</span>
                                            <?php elseif ($isUpcoming): ?>
                                            <span class="badge bg-warning">Upcoming</span>
                                            <?php elseif ($isCurrent): ?>
                                            <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                            <span class="badge bg-primary">Active</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($form['start_date'] || $form['end_date']): ?>
                                            <br>
                                            <small class="text-muted">
                                                <?php if ($form['start_date']): ?>
                                                From: <?php echo date('d/m/Y', strtotime($form['start_date'])); ?>
                                                <?php endif; ?>
                                                <?php if ($form['end_date']): ?>
                                                <br>To: <?php echo date('d/m/Y', strtotime($form['end_date'])); ?>
                                                <?php endif; ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <small class="d-block text-muted">Family</small>
                                                    <span class="badge bg-info"><?php echo $formStats['family_submissions'] ?? 0; ?></span>
                                                </div>
                                                <div>
                                                    <small class="d-block text-muted">Member</small>
                                                    <span class="badge bg-warning"><?php echo $formStats['member_submissions'] ?? 0; ?></span>
                                                </div>
                                            </div>
                                            <small class="text-muted d-block mt-1">Total: <?php echo $totalSubmissions; ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="view.php?form_id=<?php echo $form['form_id']; ?>" 
                                                   class="btn btn-outline-primary" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                
                                                <?php if ($form['created_by_user_id'] == $currentUser || $userType === 'moha'): ?>
                                                <a href="builder.php?form_id=<?php echo $form['form_id']; ?>" 
                                                   class="btn btn-outline-success" title="Build">
                                                    <i class="bi bi-tools"></i>
                                                </a>
                                                
                                                <a href="edit.php?form_id=<?php echo $form['form_id']; ?>" 
                                                   class="btn btn-outline-warning" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                
                                                <button class="btn btn-outline-danger delete-form-btn" 
                                                        data-form-id="<?php echo $form['form_id']; ?>"
                                                        data-form-name="<?php echo htmlspecialchars($form['form_name']); ?>"
                                                        title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($userType === 'moha' || $userType === 'district'): ?>
                                                <button class="btn btn-outline-info assign-form-btn" 
                                                        data-form-id="<?php echo $form['form_id']; ?>"
                                                        data-form-name="<?php echo htmlspecialchars($form['form_name']); ?>"
                                                        title="Assign">
                                                    <i class="bi bi-person-plus"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($forms)): ?>
                    <div class="card-footer">
                        <small class="text-muted">
                            Showing <?php echo count($forms); ?> form(s)
                            <?php if ($search || $type !== 'all' || $status !== 'all'): ?>
                            matching your filters
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Stats -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-bar-chart"></i> Form Usage Overview
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-3">Form Types Distribution</h6>
                                        <div id="formTypeChart" style="height: 200px;">
                                            <?php
                                            $typeCounts = ['family' => 0, 'member' => 0, 'both' => 0];
                                            foreach ($forms as $form) {
                                                $type = $form['target_entity'];
                                                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
                                            }
                                            ?>
                                            <?php foreach ($typeCounts as $type => $count): ?>
                                            <?php if ($count > 0): ?>
                                            <div class="mb-2">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <small class="text-muted">
                                                        <?php echo ucfirst($type); ?> Forms
                                                    </small>
                                                    <small class="text-muted"><?php echo $count; ?></small>
                                                </div>
                                                <div class="progress" style="height: 8px;">
                                                    <?php 
                                                    $percentage = count($forms) > 0 ? ($count / count($forms)) * 100 : 0;
                                                    $color = $type === 'family' ? 'bg-info' : ($type === 'member' ? 'bg-warning' : 'bg-primary');
                                                    ?>
                                                    <div class="progress-bar <?php echo $color; ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $percentage; ?>%">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-3">Recent Activity</h6>
                                        <?php
                                        $recentForms = array_slice($forms, 0, 3);
                                        if (!empty($recentForms)):
                                        ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($recentForms as $recent): ?>
                                            <div class="list-group-item px-0">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($recent['form_name']); ?></strong>
                                                        <small class="text-muted d-block">
                                                            Created: <?php echo date('M d, Y', strtotime($recent['created_at'])); ?>
                                                        </small>
                                                    </div>
                                                    <div>
                                                        <span class="badge 
                                                            <?php echo $recent['target_entity'] === 'family' ? 'bg-info' : 
                                                                   ($recent['target_entity'] === 'member' ? 'bg-warning' : 'bg-primary'); ?>">
                                                            <?php echo ucfirst($recent['target_entity']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="text-center py-3">
                                            <small class="text-muted">No recent activity</small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<!-- Delete Form Modal -->
<div class="modal fade" id="deleteFormModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Form</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the form "<span id="deleteFormName"></span>"?</p>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> 
                    This action cannot be undone. All form fields and assignments will be deleted.
                </div>
                <form method="POST" action="" id="deleteFormForm">
                    <input type="hidden" name="form_id" id="deleteFormId">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="deleteFormForm" name="delete_form" class="btn btn-danger">
                    <i class="bi bi-trash"></i> Delete Form
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Form Modal -->
<div class="modal fade" id="assignFormModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Form</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Assign form "<span id="assignFormName"></span>" to:</p>
                <form id="assignFormForm">
                    <input type="hidden" name="form_id" id="assignFormId">
                    <div class="mb-3">
                        <label class="form-label">Assign To</label>
                        <select class="form-control" name="assign_to" id="assignTo">
                            <option value="all_gn">All GN Officers</option>
                            <option value="all_division">All Division Officers</option>
                            <option value="all_district">All District Officers</option>
                            <option value="specific_office">Specific Office</option>
                            <option value="specific_user">Specific User</option>
                        </select>
                    </div>
                    <div class="mb-3" id="specificOfficeContainer" style="display: none;">
                        <label class="form-label">Select Office</label>
                        <input type="text" class="form-control" name="specific_office" 
                               placeholder="Enter office code...">
                    </div>
                    <div class="mb-3" id="specificUserContainer" style="display: none;">
                        <label class="form-label">Select User</label>
                        <input type="text" class="form-control" name="specific_user" 
                               placeholder="Search for user...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expiry Date (Optional)</label>
                        <input type="date" class="form-control" name="expires_at">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Permissions</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="can_edit" id="canEdit" checked>
                            <label class="form-check-label" for="canEdit">
                                Can Edit/Update Responses
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="can_review" id="canReview">
                            <label class="form-check-label" for="canReview">
                                Can Review Submissions
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="saveAssignment">
                    <i class="bi bi-person-plus"></i> Assign Form
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Delete form confirmation
document.querySelectorAll('.delete-form-btn').forEach(button => {
    button.addEventListener('click', function() {
        const formId = this.dataset.formId;
        const formName = this.dataset.formName;
        
        document.getElementById('deleteFormId').value = formId;
        document.getElementById('deleteFormName').textContent = formName;
        
        $('#deleteFormModal').modal('show');
    });
});

// Assign form
document.querySelectorAll('.assign-form-btn').forEach(button => {
    button.addEventListener('click', function() {
        const formId = this.dataset.formId;
        const formName = this.dataset.formName;
        
        document.getElementById('assignFormId').value = formId;
        document.getElementById('assignFormName').textContent = formName;
        
        $('#assignFormModal').modal('show');
    });
});

// Show/hide specific assignment fields
document.getElementById('assignTo').addEventListener('change', function() {
    const specificOfficeContainer = document.getElementById('specificOfficeContainer');
    const specificUserContainer = document.getElementById('specificUserContainer');
    
    if (this.value === 'specific_office') {
        specificOfficeContainer.style.display = 'block';
        specificUserContainer.style.display = 'none';
    } else if (this.value === 'specific_user') {
        specificOfficeContainer.style.display = 'none';
        specificUserContainer.style.display = 'block';
    } else {
        specificOfficeContainer.style.display = 'none';
        specificUserContainer.style.display = 'none';
    }
});

// Save assignment
document.getElementById('saveAssignment').addEventListener('click', function() {
    const formId = document.getElementById('assignFormId').value;
    const assignTo = document.getElementById('assignTo').value;
    
    // In a real implementation, you would send this to the server
    // For now, just show a success message
    alert('Form assignment saved! (This is a demo)');
    $('#assignFormModal').modal('hide');
});

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltips = document.querySelectorAll('[title]');
    tooltips.forEach(element => {
        new bootstrap.Tooltip(element);
    });
});
</script>

<style>
.card .icon {
    opacity: 0.8;
}

.table th {
    border-top: none;
    font-weight: 600;
    color: #495057;
}

.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
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
</style>

<?php include '../includes/footer.php'; ?>