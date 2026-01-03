<?php
require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';
require_once '../classes/SubmissionManager.php';

$auth = new Auth();
$auth->requireRole(['moha', 'district', 'division', 'gn']);

// Pass the connection to managers
global $conn;
$formManager = new FormManager($conn);
$submissionManager = new SubmissionManager($conn);

// Get current user info
$currentUser = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$officeCode = $_SESSION['office_code'];

// Handle filters
$formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
$formType = isset($_GET['form_type']) ? $_GET['form_type'] : 'all';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$entityType = isset($_GET['entity_type']) ? $_GET['entity_type'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get assigned forms for current user
$assignedForms = $formManager->getAssignedForms($currentUser, $userType, $officeCode);

// Get form details if specific form selected
$currentForm = null;
if ($formId > 0) {
    $currentForm = $formManager->getFormById($formId);
}

// Get submissions based on filters and user role
$submissions = $submissionManager->getSubmissions([
    'user_id' => $currentUser,
    'user_type' => $userType,
    'office_code' => $officeCode,
    'form_id' => $formId,
    'form_type' => $formType,
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'entity_type' => $entityType,
    'search' => $search
]);

// Get submission statistics
$stats = $submissionManager->getSubmissionStats([
    'user_id' => $currentUser,
    'user_type' => $userType,
    'office_code' => $officeCode,
    'form_id' => $formId
]);

$pageTitle = "Form Submissions - " . SITE_NAME;



// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action'])) {
        $action = $_POST['bulk_action'];
        $selected = isset($_POST['selected_submissions']) ? $_POST['selected_submissions'] : [];
        
        if (!empty($selected)) {
            $result = $submissionManager->bulkAction($action, $selected, $currentUser);
            
            if ($result['success']) {
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'message' => $result['message']
                ];
                header("Location: submissions.php?" . $_SERVER['QUERY_STRING']);
                exit();
            } else {
                $error = $result['error'];
            }
        } else {
            $error = "Please select at least one submission";
        }
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
                            <i class="bi bi-clipboard-data"></i> Form Submissions
                        </h1>
                        <p class="text-muted mb-0">
                            View and manage form submissions for families and members
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <?php if ($userType === 'gn' || $userType === 'division' || $userType === 'district'): ?>
                        <a href="fill.php" class="btn btn-success me-2">
                            <i class="bi bi-plus-circle"></i> Fill New Form
                        </a>
                        <?php endif; ?>
                        <?php if ($userType === 'moha' || $userType === 'district' || $userType === 'division'): ?>
                        <button class="btn btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#exportModal">
                            <i class="bi bi-download"></i> Export
                        </button>
                        <?php endif; ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <i class="bi bi-filter"></i> Filter Options
                            </button>
                            <div class="dropdown-menu p-3" style="width: 300px;">
                                <form method="GET" action="">
                                    <div class="mb-3">
                                        <label class="form-label">Form</label>
                                        <select class="form-control" name="form_id">
                                            <option value="0">All Forms</option>
                                            <?php foreach ($assignedForms as $form): ?>
                                            <option value="<?php echo $form['form_id']; ?>" 
                                                    <?php echo $formId == $form['form_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($form['form_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-control" name="status">
                                            <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                            <option value="draft" <?php echo $status == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                            <option value="submitted" <?php echo $status == 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                            <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                            <option value="pending_review" <?php echo $status == 'pending_review' ? 'selected' : ''; ?>>Pending Review</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Entity Type</label>
                                        <select class="form-control" name="entity_type">
                                            <option value="all" <?php echo $entityType == 'all' ? 'selected' : ''; ?>>All Types</option>
                                            <option value="family" <?php echo $entityType == 'family' ? 'selected' : ''; ?>>Family Forms</option>
                                            <option value="member" <?php echo $entityType == 'member' ? 'selected' : ''; ?>>Member Forms</option>
                                        </select>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                                        <a href="submissions.php" class="btn btn-outline-secondary mt-2">Reset</a>
                                    </div>
                                </form>
                            </div>
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
                                        <h6 class="card-title text-white-50">Total Submissions</h6>
                                        <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                                    </div>
                                    <div class="icon">
                                        <i class="bi bi-clipboard-data fs-1"></i>
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
                                        <h6 class="card-title text-white-50">Approved</h6>
                                        <h3 class="mb-0"><?php echo $stats['approved']; ?></h3>
                                    </div>
                                    <div class="icon">
                                        <i class="bi bi-check-circle fs-1"></i>
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
                                        <h6 class="card-title text-white-50">Pending Review</h6>
                                        <h3 class="mb-0"><?php echo $stats['pending_review']; ?></h3>
                                    </div>
                                    <div class="icon">
                                        <i class="bi bi-clock-history fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Rejected</h6>
                                        <h3 class="mb-0"><?php echo $stats['rejected']; ?></h3>
                                    </div>
                                    <div class="icon">
                                        <i class="bi bi-x-circle fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Main Content -->
                <div class="card">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0">
                                    <i class="bi bi-list-check"></i> Submissions
                                    <?php if ($currentForm): ?>
                                    <small class="text-muted">
                                        for <strong><?php echo htmlspecialchars($currentForm['form_name']); ?></strong>
                                    </small>
                                    <?php endif; ?>
                                </h5>
                                <small class="text-muted">
                                    Showing <?php echo count($submissions); ?> submissions
                                    <?php if ($dateFrom || $dateTo): ?>
                                    from <?php echo $dateFrom ?: 'Start'; ?> to <?php echo $dateTo ?: 'Now'; ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div>
                                <?php if (count($submissions) > 0): ?>
                                <form method="POST" class="d-inline" id="bulkActionForm">
                                    <div class="input-group input-group-sm">
                                        <select class="form-control form-control-sm" name="bulk_action" id="bulkAction">
                                            <option value="">Bulk Actions</option>
                                            <?php if ($userType === 'moha' || $userType === 'district' || $userType === 'division'): ?>
                                            <option value="approve">Approve Selected</option>
                                            <option value="reject">Reject Selected</option>
                                            <option value="pending">Mark as Pending Review</option>
                                            <?php endif; ?>
                                            <option value="delete">Delete Selected</option>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            Apply
                                        </button>
                                    </div>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($submissions)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox fs-1 text-muted"></i>
                            <h5 class="text-muted mt-3">No Submissions Found</h5>
                            <p class="text-muted">
                                <?php if ($formId || $status !== 'all' || $dateFrom || $dateTo): ?>
                                Try adjusting your filters or
                                <?php endif; ?>
                                <a href="fill.php">fill out a new form</a>.
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAll" class="form-check-input">
                                        </th>
                                        <th>ID</th>
                                        <th>Form</th>
                                        <th>Entity</th>
                                        <th>Submitted By</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($submissions as $submission): ?>
                                    <?php
                                    $isFamily = $submission['submission_type'] === 'family';
                                    $entityName = $isFamily ? 
                                        'Family: ' . $submission['family_id'] : 
                                        'Member: ' . $submission['full_name'];
                                    $entityLink = $isFamily ? 
                                        '../families/view.php?family_id=' . urlencode($submission['family_id']) :
                                        '../members/view.php?citizen_id=' . $submission['citizen_id'];
                                    $progress = ($submission['completed_fields'] / max($submission['total_fields'], 1)) * 100;
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="selected_submissions[]" 
                                                   value="<?php echo $submission['submission_id']; ?>"
                                                   class="form-check-input submission-checkbox"
                                                   data-type="<?php echo $submission['submission_type']; ?>">
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                #<?php echo str_pad($submission['submission_id'], 6, '0', STR_PAD_LEFT); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($submission['form_name']); ?></strong><br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($submission['form_code']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <a href="<?php echo $entityLink; ?>" target="_blank" 
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars($entityName); ?>
                                            </a>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo $isFamily ? 'Family Form' : 'Member Form'; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($submission['submitted_by']): ?>
                                            <div class="d-flex align-items-center">
                                                <div class="me-2">
                                                    <i class="bi bi-person-circle"></i>
                                                </div>
                                                <div>
                                                    <small class="d-block"><?php echo htmlspecialchars($submission['submitted_by_name']); ?></small>
                                                    <small class="text-muted"><?php echo htmlspecialchars($submission['office_name']); ?></small>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-muted">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="d-block">
                                                <i class="bi bi-calendar"></i> 
                                                <?php echo date('M d, Y', strtotime($submission['created_at'])); ?>
                                            </small>
                                            <small class="text-muted">
                                                <?php echo date('h:i A', strtotime($submission['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php
                                            $statusBadgeClass = [
                                                'draft' => 'bg-secondary',
                                                'submitted' => 'bg-primary',
                                                'approved' => 'bg-success',
                                                'rejected' => 'bg-danger',
                                                'pending_review' => 'bg-warning'
                                            ][$submission['submission_status']] ?? 'bg-secondary';
                                            ?>
                                            <span class="badge <?php echo $statusBadgeClass; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $submission['submission_status'])); ?>
                                            </span>
                                            <?php if ($submission['reviewed_by']): ?>
                                            <small class="d-block text-muted mt-1">
                                                Reviewed by <?php echo htmlspecialchars($submission['reviewed_by_name']); ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                    <div class="progress-bar bg-info" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $progress; ?>%">
                                                    </div>
                                                </div>
                                                <small><?php echo round($progress); ?>%</small>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo $submission['completed_fields']; ?> of <?php echo $submission['total_fields']; ?> fields
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="view_submission.php?id=<?php echo $submission['submission_id']; ?>&type=<?php echo $submission['submission_type']; ?>" 
                                                   class="btn btn-outline-primary" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if ($submission['can_edit']): ?>
                                                <a href="fill.php?edit=<?php echo $submission['submission_id']; ?>&type=<?php echo $submission['submission_type']; ?>" 
                                                   class="btn btn-outline-warning" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if ($submission['can_review'] && in_array($submission['submission_status'], ['submitted', 'pending_review'])): ?>
                                                <button class="btn btn-outline-success review-btn" 
                                                        data-id="<?php echo $submission['submission_id']; ?>"
                                                        data-type="<?php echo $submission['submission_type']; ?>"
                                                        data-status="approved"
                                                        title="Approve">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                                <button class="btn btn-outline-danger review-btn" 
                                                        data-id="<?php echo $submission['submission_id']; ?>"
                                                        data-type="<?php echo $submission['submission_type']; ?>"
                                                        data-status="rejected"
                                                        title="Reject">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if (count($submissions) > 50): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <li class="page-item disabled">
                                    <a class="page-link" href="#" tabindex="-1">Previous</a>
                                </li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item"><a class="page-link" href="#">2</a></li>
                                <li class="page-item"><a class="page-link" href="#">3</a></li>
                                <li class="page-item">
                                    <a class="page-link" href="#">Next</a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($submissions)): ?>
                    <div class="card-footer">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted">
                                    Showing <?php echo count($submissions); ?> submissions
                                </small>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-outline-primary" onclick="printSubmissions()">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Stats -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-pie-chart"></i> Submission Status Distribution
                                </h6>
                            </div>
                            <div class="card-body">
                                <div id="statusChart" style="height: 250px;">
                                    <canvas id="statusChartCanvas"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-calendar-week"></i> Recent Activity
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php
                                $recentSubmissions = array_slice($submissions, 0, 5);
                                if (!empty($recentSubmissions)):
                                ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recentSubmissions as $recent): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <small class="text-muted">
                                                    <?php echo date('h:i A', strtotime($recent['created_at'])); ?>
                                                </small>
                                                <div class="mt-1">
                                                    <strong><?php echo htmlspecialchars($recent['form_name']); ?></strong>
                                                    <small class="text-muted d-block">
                                                        <?php echo $recent['submission_type'] === 'family' ? 'Family Form' : 'Member Form'; ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div>
                                                <?php
                                                $statusColor = [
                                                    'draft' => 'secondary',
                                                    'submitted' => 'primary',
                                                    'approved' => 'success',
                                                    'rejected' => 'danger',
                                                    'pending_review' => 'warning'
                                                ][$recent['submission_status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $statusColor; ?>">
                                                    <?php echo ucfirst($recent['submission_status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-4">
                                    <small class="text-muted">No recent activity</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Export Submissions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="exportForm">
                    <div class="mb-3">
                        <label class="form-label">Export Format</label>
                        <select class="form-control" name="export_format" id="exportFormat">
                            <option value="csv">CSV (Excel)</option>
                            <option value="excel">Excel (XLSX)</option>
                            <option value="pdf">PDF Report</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date Range</label>
                        <div class="row">
                            <div class="col-md-6">
                                <input type="date" class="form-control" name="export_from" id="exportFrom">
                            </div>
                            <div class="col-md-6">
                                <input type="date" class="form-control" name="export_to" id="exportTo">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Include Fields</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="include_details" id="includeDetails" checked>
                            <label class="form-check-label" for="includeDetails">
                                Include Form Responses
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="include_metadata" id="includeMetadata" checked>
                            <label class="form-check-label" for="includeMetadata">
                                Include Submission Metadata
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="exportSubmissions()">
                    <i class="bi bi-download"></i> Export
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Review Submission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="reviewForm">
                    <input type="hidden" id="review_submission_id" name="submission_id">
                    <input type="hidden" id="review_submission_type" name="submission_type">
                    <input type="hidden" id="review_action" name="action">
                    
                    <div class="mb-3">
                        <label for="review_notes" class="form-label">Review Notes</label>
                        <textarea class="form-control" id="review_notes" name="review_notes" 
                                  rows="4" placeholder="Enter review comments..."></textarea>
                        <small class="text-muted">Provide feedback or reasons for approval/rejection</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Final Status</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="final_status" 
                                   id="status_approve" value="approved" checked>
                            <label class="form-check-label text-success" for="status_approve">
                                <i class="bi bi-check-circle"></i> Approve Submission
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="final_status" 
                                   id="status_reject" value="rejected">
                            <label class="form-check-label text-danger" for="status_reject">
                                <i class="bi bi-x-circle"></i> Reject Submission
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="final_status" 
                                   id="status_pending" value="pending_review">
                            <label class="form-check-label text-warning" for="status_pending">
                                <i class="bi bi-clock-history"></i> Mark as Pending Review
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="submitReview">Submit Review</button>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize Chart.js for status distribution
document.addEventListener('DOMContentLoaded', function() {
    // Status distribution chart
    const ctx = document.getElementById('statusChartCanvas');
    if (ctx) {
        const statusData = {
            labels: ['Draft', 'Submitted', 'Approved', 'Rejected', 'Pending Review'],
            datasets: [{
                data: [
                    <?php echo $stats['draft']; ?>,
                    <?php echo $stats['submitted']; ?>,
                    <?php echo $stats['approved']; ?>,
                    <?php echo $stats['rejected']; ?>,
                    <?php echo $stats['pending_review']; ?>
                ],
                backgroundColor: [
                    '#6c757d', // Draft - gray
                    '#0d6efd', // Submitted - blue
                    '#198754', // Approved - green
                    '#dc3545', // Rejected - red
                    '#ffc107'  // Pending - yellow
                ]
            }]
        };
        
        new Chart(ctx, {
            type: 'doughnut',
            data: statusData,
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Bulk actions
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.submission-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
    
    // Bulk action form
    document.getElementById('bulkActionForm').addEventListener('submit', function(e) {
        const action = document.getElementById('bulkAction').value;
        if (!action) {
            e.preventDefault();
            alert('Please select a bulk action');
            return false;
        }
        
        const checked = document.querySelectorAll('.submission-checkbox:checked');
        if (checked.length === 0) {
            e.preventDefault();
            alert('Please select at least one submission');
            return false;
        }
        
        if (action === 'delete' && !confirm('Are you sure you want to delete selected submissions?')) {
            e.preventDefault();
            return false;
        }
        
        if (action === 'approve' && !confirm('Approve selected submissions?')) {
            e.preventDefault();
            return false;
        }
        
        if (action === 'reject' && !confirm('Reject selected submissions?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Review buttons
    document.querySelectorAll('.review-btn').forEach(button => {
        button.addEventListener('click', function() {
            const submissionId = this.dataset.id;
            const submissionType = this.dataset.type;
            const action = this.dataset.status;
            
            document.getElementById('review_submission_id').value = submissionId;
            document.getElementById('review_submission_type').value = submissionType;
            document.getElementById('review_action').value = action;
            
            // Set default status based on button clicked
            if (action === 'approved') {
                document.getElementById('status_approve').checked = true;
            } else if (action === 'rejected') {
                document.getElementById('status_reject').checked = true;
            }
            
            $('#reviewModal').modal('show');
        });
    });
    
    // Submit review
    document.getElementById('submitReview').addEventListener('click', function() {
        const formData = new FormData();
        formData.append('submission_id', document.getElementById('review_submission_id').value);
        formData.append('submission_type', document.getElementById('review_submission_type').value);
        formData.append('action', document.getElementById('review_action').value);
        formData.append('review_notes', document.getElementById('review_notes').value);
        formData.append('final_status', document.querySelector('input[name="final_status"]:checked').value);
        
        fetch('review_submission.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Submission reviewed successfully!', 'success');
                $('#reviewModal').modal('hide');
                setTimeout(() => location.reload(), 1000);
            } else {
                showNotification('Error: ' + data.error, 'danger');
            }
        });
    });
});

// Export submissions
function exportSubmissions() {
    const format = document.getElementById('exportFormat').value;
    const from = document.getElementById('exportFrom').value;
    const to = document.getElementById('exportTo').value;
    const includeDetails = document.getElementById('includeDetails').checked ? '1' : '0';
    const includeMetadata = document.getElementById('includeMetadata').checked ? '1' : '0';
    
    let url = `export_submissions.php?format=${format}`;
    url += `&include_details=${includeDetails}&include_metadata=${includeMetadata}`;
    if (from) url += `&from=${from}`;
    if (to) url += `&to=${to}`;
    if (<?php echo $formId; ?>) url += `&form_id=<?php echo $formId; ?>`;
    if (<?php echo $status !== 'all' ? "'$status'" : "''"; ?>) url += `&status=<?php echo $status; ?>`;
    
    window.open(url, '_blank');
    $('#exportModal').modal('hide');
}

// Print submissions
function printSubmissions() {
    const printContent = document.querySelector('.card').outerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <html>
            <head>
                <title>Form Submissions Report - <?php echo SITE_NAME; ?></title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    @media print {
                        .no-print { display: none !important; }
                        body { font-size: 12px; }
                        .table { font-size: 11px; }
                    }
                </style>
            </head>
            <body>
                <div class="container-fluid">
                    <div class="row mb-4">
                        <div class="col-12 text-center">
                            <h4>Form Submissions Report</h4>
                            <p class="text-muted">Generated on: ${new Date().toLocaleString()}</p>
                            <p class="text-muted">User: <?php echo $_SESSION['username']; ?> (<?php echo strtoupper($_SESSION['user_type']); ?>)</p>
                        </div>
                    </div>
                    ${printContent}
                </div>
                <script>
                    window.onload = function() {
                        window.print();
                        setTimeout(function() {
                            document.body.innerHTML = originalContent;
                            window.location.reload();
                        }, 500);
                    };
                <\/script>
            </body>
        </html>
    `;
}

// Show notification
function showNotification(message, type = 'info') {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alert);
    setTimeout(() => alert.remove(), 5000);
}

// Filter submissions
function filterSubmissions() {
    const form = document.createElement('form');
    form.method = 'GET';
    form.action = '';
    
    const params = new URLSearchParams(window.location.search);
    params.set('form_id', document.querySelector('[name="form_id"]').value);
    params.set('status', document.querySelector('[name="status"]').value);
    params.set('entity_type', document.querySelector('[name="entity_type"]').value);
    params.set('date_from', document.querySelector('[name="date_from"]').value);
    params.set('date_to', document.querySelector('[name="date_to"]').value);
    
    window.location.href = `submissions.php?${params.toString()}`;
}
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

.progress {
    background-color: #e9ecef;
}

.badge {
    font-size: 0.75em;
    font-weight: 500;
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

.submission-checkbox {
    margin: 0;
}

.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>

<?php include '../includes/footer.php'; ?>