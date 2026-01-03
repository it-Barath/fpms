<?php
/**
 * assignments.php
 * Manage form assignments to users, offices, and user types
 */

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';
require_once '../classes/UserManager.php';

// Initialize Auth
$auth = new Auth();
$auth->requireLogin();
// Only MOHA, district, and division users can manage assignments
$auth->requireRole(['moha', 'district', 'division']);

// Initialize managers
$formManager = new FormManager();
$userManager = new UserManager();

// Get current user info
$currentUser = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$officeCode = $_SESSION['office_code'];

// Get form ID if provided
$formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
$form = null;
if ($formId > 0) {
    $form = $formManager->getFormById($formId);
    if (!$form) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'message' => 'Form not found'
        ];
        header("Location: manage.php");
        exit();
    }
    
    // Check if user has permission to assign this form
    if ($userType !== 'moha' && $form['created_by_user_id'] != $currentUser) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'message' => 'You do not have permission to assign this form'
        ];
        header("Location: manage.php");
        exit();
    }
}

// Initialize variables
$error = '';
$success = '';
$assignments = [];
$users = [];
$offices = [];

// Get existing assignments for this form
if ($formId > 0) {
    $assignments = $formManager->getFormAssignments($formId);
}

// Get users for assignment based on user type
switch ($userType) {
    case 'moha':
        // MOHA can assign to all users
        $users = $userManager->getAllUsers(['is_active' => 1]);
        $offices = $userManager->getAllOffices();
        break;
        
    case 'district':
        // District can assign to divisions and GNs in their district
        $users = $userManager->getUsersByDistrict($officeCode, ['is_active' => 1]);
        $offices = $userManager->getOfficesByDistrict($officeCode);
        break;
        
    case 'division':
        // Division can assign to GNs in their division
        $users = $userManager->getUsersByDivision($officeCode, ['is_active' => 1]);
        $offices = $userManager->getOfficesByDivision($officeCode);
        break;
}

// Handle form submission for new assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_assignment'])) {
    try {
        if (!$formId) {
            throw new Exception("Form ID is required");
        }
        
        // Collect assignment data
        $assignData = [
            'form_id' => $formId,
            'assigned_to_user_type' => $_POST['assigned_to_user_type'] ?? '',
            'assigned_to_office_code' => !empty($_POST['assigned_to_office_code']) ? $_POST['assigned_to_office_code'] : null,
            'assigned_to_user_id' => !empty($_POST['assigned_to_user_id']) ? intval($_POST['assigned_to_user_id']) : null,
            'assignment_type' => $_POST['assignment_type'] ?? 'fill',
            'can_edit' => isset($_POST['can_edit']) ? 1 : 0,
            'can_delete' => isset($_POST['can_delete']) ? 1 : 0,
            'can_review' => isset($_POST['can_review']) ? 1 : 0,
            'assigned_by_user_id' => $currentUser,
            'expires_at' => !empty($_POST['expires_at']) ? $_POST['expires_at'] . ' 23:59:59' : null
        ];
        
        // Validate assignment data
        if (empty($assignData['assigned_to_user_type'])) {
            throw new Exception("User type is required");
        }
        
        // If assigning to specific user or office
        if ($assignData['assigned_to_user_type'] === 'specific') {
            if (!$assignData['assigned_to_user_id'] && !$assignData['assigned_to_office_code']) {
                throw new Exception("Either user or office must be selected for specific assignment");
            }
        }
        
        // Validate expiration date if provided
        if ($assignData['expires_at'] && strtotime($assignData['expires_at']) < time()) {
            throw new Exception("Expiration date must be in the future");
        }
        
        // Create the assignment
        $result = $formManager->assignForm($formId, $assignData);
        
        if ($result['success']) {
            $success = "Form assigned successfully!";
            
            // Refresh assignments list
            $assignments = $formManager->getFormAssignments($formId);
            
            // Clear form
            $_POST = [];
        } else {
            throw new Exception($result['error']);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle assignment removal
if (isset($_GET['remove_assignment'])) {
    $assignmentId = intval($_GET['remove_assignment']);
    
    try {
        // Get assignment details
        $conn = getMainConnection();
        $sql = "SELECT form_id FROM form_assignments WHERE assignment_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $assignmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Check permission
            if ($userType !== 'moha') {
                $formCheck = $formManager->getFormById($row['form_id']);
                if ($formCheck['created_by_user_id'] != $currentUser) {
                    throw new Exception("You do not have permission to remove this assignment");
                }
            }
            
            // Remove assignment
            $sql = "DELETE FROM form_assignments WHERE assignment_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $assignmentId);
            
            if ($stmt->execute()) {
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'message' => 'Assignment removed successfully!'
                ];
                header("Location: assignments.php?form_id=" . $formId);
                exit();
            } else {
                throw new Exception("Failed to remove assignment");
            }
        } else {
            throw new Exception("Assignment not found");
        }
        
    } catch (Exception $e) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'message' => $e->getMessage()
        ];
        header("Location: assignments.php?form_id=" . $formId);
        exit();
    }
}

$pageTitle = "Form Assignments - " . SITE_NAME;

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
                            <i class="fas fa-share-alt"></i> Form Assignments
                            <?php if ($form): ?>
                                <small class="text-muted">- <?php echo htmlspecialchars($form['form_name']); ?></small>
                            <?php endif; ?>
                        </h1>
                        <p class="text-muted mb-0">
                            Manage who can access and fill forms
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="manage.php" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-arrow-left"></i> Back to Forms
                        </a>
                        <?php if ($form): ?>
                            <a href="builder.php?form_id=<?php echo $formId; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-tools"></i> Form Builder
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php displayFlashMessage(); ?>
                
                <!-- Error Message -->
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Success Message -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Main Content -->
                <?php if (!$formId): ?>
                    <!-- Form Selection -->
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-file-alt"></i> Select a Form
                            </h5>
                        </div>
                        <div class="card-body">
                            <p>Please select a form to manage its assignments.</p>
                            
                            <!-- Available Forms Table -->
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Form Name</th>
                                            <th>Form Code</th>
                                            <th>Type</th>
                                            <th>Created By</th>
                                            <th>Created Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Get forms this user can manage
                                        $filters = [];
                                        if ($userType !== 'moha') {
                                            $filters['created_by'] = $currentUser;
                                        }
                                        $forms = $formManager->getAllForms($filters);
                                        
                                        foreach ($forms as $formItem): 
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($formItem['form_name']); ?></strong>
                                                    <?php if (!empty($formItem['form_description'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($formItem['form_description'], 0, 100)); ?>...</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($formItem['form_code']); ?></code>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $typeBadge = '';
                                                    switch ($formItem['target_entity']) {
                                                        case 'family': $typeBadge = 'bg-info'; break;
                                                        case 'member': $typeBadge = 'bg-warning'; break;
                                                        default: $typeBadge = 'bg-primary';
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $typeBadge; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($formItem['target_entity'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($formItem['created_by_name'] ?? 'System'); ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($formItem['created_by_office'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo date('Y-m-d', strtotime($formItem['created_at'])); ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="assignments.php?form_id=<?php echo $formItem['form_id']; ?>" 
                                                           class="btn btn-primary">
                                                            <i class="fas fa-share-alt"></i> Manage Assignments
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if (empty($forms)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> You don't have any forms to manage assignments for.
                                    <a href="create.php" class="alert-link">Create a new form</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                
                <?php else: ?>
                    <!-- Assignment Management -->
                    <div class="row">
                        <!-- Form Information -->
                        <div class="col-md-4">
                            <div class="card mb-4">
                                <div class="card-header bg-info text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-info-circle"></i> Form Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <h6><?php echo htmlspecialchars($form['form_name']); ?></h6>
                                    <p class="text-muted"><?php echo htmlspecialchars($form['form_description'] ?? 'No description'); ?></p>
                                    
                                    <dl class="row mb-0">
                                        <dt class="col-sm-5">Form Code:</dt>
                                        <dd class="col-sm-7"><code><?php echo htmlspecialchars($form['form_code']); ?></code></dd>
                                        
                                        <dt class="col-sm-5">Target Entity:</dt>
                                        <dd class="col-sm-7">
                                            <span class="badge bg-primary">
                                                <?php echo htmlspecialchars(ucfirst($form['target_entity'])); ?>
                                            </span>
                                        </dd>
                                        
                                        <dt class="col-sm-5">Category:</dt>
                                        <dd class="col-sm-7"><?php echo htmlspecialchars($form['form_category'] ?? 'Uncategorized'); ?></dd>
                                        
                                        <dt class="col-sm-5">Status:</dt>
                                        <dd class="col-sm-7">
                                            <?php if ($form['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </dd>
                                        
                                        <dt class="col-sm-5">Created By:</dt>
                                        <dd class="col-sm-7"><?php echo htmlspecialchars($form['created_by_name'] ?? 'System'); ?></dd>
                                        
                                        <dt class="col-sm-5">Created Date:</dt>
                                        <dd class="col-sm-7"><?php echo date('Y-m-d', strtotime($form['created_at'])); ?></dd>
                                    </dl>
                                </div>
                            </div>
                            
                            <!-- Quick Stats -->
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-chart-bar"></i> Assignment Stats
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="text-center mb-3">
                                        <h3><?php echo count($assignments); ?></h3>
                                        <p class="text-muted mb-0">Total Assignments</p>
                                    </div>
                                    
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <h5><?php echo count(array_filter($assignments, fn($a) => $a['assignment_type'] === 'fill')); ?></h5>
                                            <small class="text-muted">Fill Only</small>
                                        </div>
                                        <div class="col-6">
                                            <h5><?php echo count(array_filter($assignments, fn($a) => $a['assignment_type'] === 'all')); ?></h5>
                                            <small class="text-muted">Full Access</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Assignment Management -->
                        <div class="col-md-8">
                            <!-- Add New Assignment -->
                            <div class="card mb-4">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-plus-circle"></i> Add New Assignment
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="" id="assignmentForm">
                                        <input type="hidden" name="form_id" value="<?php echo $formId; ?>">
                                        
                                        <div class="row">
                                            <!-- Assignment Type -->
                                            <div class="col-md-6 mb-3">
                                                <label for="assigned_to_user_type" class="form-label required-field">
                                                    Assign To <span class="text-danger">*</span>
                                                </label>
                                                <select class="form-control" 
                                                        id="assigned_to_user_type" 
                                                        name="assigned_to_user_type" 
                                                        required
                                                        onchange="toggleAssignmentFields()">
                                                    <option value="">-- Select Assignment Type --</option>
                                                    <option value="moha" <?php echo ($_POST['assigned_to_user_type'] ?? '') === 'moha' ? 'selected' : ''; ?>>
                                                        All MOHA Users
                                                    </option>
                                                    <option value="district" <?php echo ($_POST['assigned_to_user_type'] ?? '') === 'district' ? 'selected' : ''; ?>>
                                                        All District Users
                                                    </option>
                                                    <option value="division" <?php echo ($_POST['assigned_to_user_type'] ?? '') === 'division' ? 'selected' : ''; ?>>
                                                        All Division Users
                                                    </option>
                                                    <option value="gn" <?php echo ($_POST['assigned_to_user_type'] ?? '') === 'gn' ? 'selected' : ''; ?>>
                                                        All GN Users
                                                    </option>
                                                    <option value="specific" <?php echo ($_POST['assigned_to_user_type'] ?? '') === 'specific' ? 'selected' : ''; ?>>
                                                        Specific User or Office
                                                    </option>
                                                </select>
                                                <div class="form-text">
                                                    Select who should have access to this form
                                                </div>
                                            </div>
                                            
                                            <!-- Assignment Permissions -->
                                            <div class="col-md-6 mb-3">
                                                <label for="assignment_type" class="form-label required-field">
                                                    Access Level <span class="text-danger">*</span>
                                                </label>
                                                <select class="form-control" 
                                                        id="assignment_type" 
                                                        name="assignment_type" 
                                                        required>
                                                    <option value="fill" <?php echo ($_POST['assignment_type'] ?? 'fill') === 'fill' ? 'selected' : ''; ?>>
                                                        Fill Only (Can only submit forms)
                                                    </option>
                                                    <option value="all" <?php echo ($_POST['assignment_type'] ?? '') === 'all' ? 'selected' : ''; ?>>
                                                        Full Access (Can fill and manage)
                                                    </option>
                                                    <option value="review" <?php echo ($_POST['assignment_type'] ?? '') === 'review' ? 'selected' : ''; ?>>
                                                        Review Only (Can review submissions)
                                                    </option>
                                                </select>
                                                <div class="form-text">
                                                    What level of access should they have?
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Specific Assignment Fields (Hidden by default) -->
                                        <div id="specificAssignmentFields" style="display: none;">
                                            <div class="row">
                                                <!-- Office Selection -->
                                                <div class="col-md-6 mb-3">
                                                    <label for="assigned_to_office_code" class="form-label">
                                                        Assign to Office
                                                    </label>
                                                    <select class="form-control" 
                                                            id="assigned_to_office_code" 
                                                            name="assigned_to_office_code">
                                                        <option value="">-- Select Office --</option>
                                                        <?php foreach ($offices as $office): ?>
                                                            <option value="<?php echo htmlspecialchars($office['office_code']); ?>"
                                                                    <?php echo ($_POST['assigned_to_office_code'] ?? '') === $office['office_code'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($office['office_name']); ?>
                                                                (<?php echo htmlspecialchars($office['office_code']); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="form-text">
                                                        All users in this office will get access
                                                    </div>
                                                </div>
                                                
                                                <!-- User Selection -->
                                                <div class="col-md-6 mb-3">
                                                    <label for="assigned_to_user_id" class="form-label">
                                                        Assign to Specific User
                                                    </label>
                                                    <select class="form-control" 
                                                            id="assigned_to_user_id" 
                                                            name="assigned_to_user_id">
                                                        <option value="">-- Select User --</option>
                                                        <?php foreach ($users as $user): ?>
                                                            <option value="<?php echo $user['user_id']; ?>"
                                                                    <?php echo ($_POST['assigned_to_user_id'] ?? 0) == $user['user_id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($user['username']); ?>
                                                                (<?php echo htmlspecialchars($user['office_name']); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="form-text">
                                                        Only this specific user will get access
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle"></i> You can assign to either an office (all users in that office) 
                                                or a specific user. Leave both blank to assign to all users of the selected type.
                                            </div>
                                        </div>
                                        
                                        <!-- Additional Permissions -->
                                        <div class="row mb-3">
                                            <div class="col-md-12">
                                                <label class="form-label">Additional Permissions</label>
                                                <div class="form-check">
                                                    <input class="form-check-input" 
                                                           type="checkbox" 
                                                           id="can_edit" 
                                                           name="can_edit" 
                                                           value="1"
                                                           <?php echo isset($_POST['can_edit']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="can_edit">
                                                        Can edit submitted forms
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" 
                                                           type="checkbox" 
                                                           id="can_delete" 
                                                           name="can_delete" 
                                                           value="1"
                                                           <?php echo isset($_POST['can_delete']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="can_delete">
                                                        Can delete submitted forms
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" 
                                                           type="checkbox" 
                                                           id="can_review" 
                                                           name="can_review" 
                                                           value="1"
                                                           <?php echo isset($_POST['can_review']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="can_review">
                                                        Can review and approve submissions
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Expiration Date -->
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="expires_at" class="form-label">
                                                    Expiration Date (Optional)
                                                </label>
                                                <input type="date" 
                                                       class="form-control" 
                                                       id="expires_at" 
                                                       name="expires_at" 
                                                       value="<?php echo $_POST['expires_at'] ?? ''; ?>"
                                                       min="<?php echo date('Y-m-d'); ?>">
                                                <div class="form-text">
                                                    Leave empty for permanent assignment
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Form Actions -->
                                        <div class="d-flex justify-content-between">
                                            <button type="button" class="btn btn-outline-secondary" onclick="resetAssignmentForm()">
                                                <i class="fas fa-redo"></i> Reset
                                            </button>
                                            <button type="submit" name="add_assignment" value="1" class="btn btn-primary">
                                                <i class="fas fa-share-alt"></i> Add Assignment
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Existing Assignments -->
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-list"></i> Current Assignments
                                        <span class="badge bg-light text-dark ms-2"><?php echo count($assignments); ?></span>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($assignments)): ?>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> No assignments have been created for this form yet.
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Assigned To</th>
                                                        <th>Type</th>
                                                        <th>Permissions</th>
                                                        <th>Expires</th>
                                                        <th>Assigned By</th>
                                                        <th>Date</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($assignments as $assignment): ?>
                                                        <tr>
                                                            <td>
                                                                <?php if ($assignment['assigned_to_user_id']): ?>
                                                                    <i class="fas fa-user"></i> 
                                                                    <?php echo htmlspecialchars($assignment['assigned_to_username']); ?>
                                                                    <br><small class="text-muted">User ID: <?php echo $assignment['assigned_to_user_id']; ?></small>
                                                                <?php elseif ($assignment['assigned_to_office_code']): ?>
                                                                    <i class="fas fa-building"></i> 
                                                                    <?php echo htmlspecialchars($assignment['assigned_to_office_name']); ?>
                                                                    <br><small class="text-muted">Office: <?php echo htmlspecialchars($assignment['assigned_to_office_code']); ?></small>
                                                                <?php else: ?>
                                                                    <i class="fas fa-users"></i> 
                                                                    All <?php echo strtoupper($assignment['assigned_to_user_type']); ?> Users
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php 
                                                                $typeBadge = '';
                                                                switch ($assignment['assignment_type']) {
                                                                    case 'fill': $typeBadge = 'bg-info'; break;
                                                                    case 'all': $typeBadge = 'bg-success'; break;
                                                                    case 'review': $typeBadge = 'bg-warning'; break;
                                                                }
                                                                ?>
                                                                <span class="badge <?php echo $typeBadge; ?>">
                                                                    <?php echo htmlspecialchars(ucfirst($assignment['assignment_type'])); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <div class="d-flex flex-wrap gap-1">
                                                                    <?php if ($assignment['can_edit']): ?>
                                                                        <span class="badge bg-primary">Edit</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($assignment['can_delete']): ?>
                                                                        <span class="badge bg-danger">Delete</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($assignment['can_review']): ?>
                                                                        <span class="badge bg-warning">Review</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <?php if ($assignment['expires_at']): ?>
                                                                    <?php echo date('Y-m-d', strtotime($assignment['expires_at'])); ?>
                                                                    <?php if (strtotime($assignment['expires_at']) < time()): ?>
                                                                        <br><small class="text-danger">Expired</small>
                                                                    <?php else: ?>
                                                                        <br><small class="text-success">Active</small>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Never</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php echo htmlspecialchars($assignment['assigned_by_username'] ?? 'System'); ?>
                                                                <br><small class="text-muted"><?php echo date('Y-m-d', strtotime($assignment['assigned_at'])); ?></small>
                                                            </td>
                                                            <td>
                                                                <?php echo date('Y-m-d', strtotime($assignment['assigned_at'])); ?>
                                                            </td>
                                                            <td>
                                                                <button type="button" 
                                                                        class="btn btn-sm btn-outline-danger"
                                                                        onclick="confirmRemoveAssignment(<?php echo $assignment['assignment_id']; ?>)">
                                                                    <i class="fas fa-trash-alt"></i> Remove
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>

<script>
// Toggle specific assignment fields
function toggleAssignmentFields() {
    const assignmentType = document.getElementById('assigned_to_user_type').value;
    const specificFields = document.getElementById('specificAssignmentFields');
    
    if (assignmentType === 'specific') {
        specificFields.style.display = 'block';
    } else {
        specificFields.style.display = 'none';
        // Clear specific fields
        document.getElementById('assigned_to_office_code').value = '';
        document.getElementById('assigned_to_user_id').value = '';
    }
}

// Reset assignment form
function resetAssignmentForm() {
    if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        document.getElementById('assignmentForm').reset();
        toggleAssignmentFields();
        showNotification('Form has been reset', 'info');
    }
}

// Confirm assignment removal
function confirmRemoveAssignment(assignmentId) {
    if (confirm('Are you sure you want to remove this assignment? This action cannot be undone.')) {
        window.location.href = 'assignments.php?form_id=<?php echo $formId; ?>&remove_assignment=' + assignmentId;
    }
}

// Show notification
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.custom-notification');
    existingNotifications.forEach(notification => notification.remove());
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show custom-notification position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    notification.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 5000);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set minimum date for expiration
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('expires_at').min = today;
    
    // Initialize assignment fields
    toggleAssignmentFields();
    
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(element => {
        new bootstrap.Tooltip(element);
    });
    
    // Form validation
    const form = document.getElementById('assignmentForm');
    if (form) {
        form.addEventListener('submit', function(event) {
            const assignmentType = document.getElementById('assigned_to_user_type').value;
            
            if (assignmentType === 'specific') {
                const office = document.getElementById('assigned_to_office_code').value;
                const user = document.getElementById('assigned_to_user_id').value;
                
                if (!office && !user) {
                    event.preventDefault();
                    alert('For specific assignment, please select either an office or a user.');
                    return false;
                }
            }
            
            return true;
        });
    }
});

// Auto-populate users based on selected office
document.getElementById('assigned_to_office_code').addEventListener('change', function() {
    const officeCode = this.value;
    const userIdSelect = document.getElementById('assigned_to_user_id');
    
    if (officeCode) {
        // Clear existing options except the first one
        userIdSelect.innerHTML = '<option value="">-- Select User --</option>';
        
        // Fetch users for this office
        fetch(`../ajax/get_users_by_office.php?office_code=${encodeURIComponent(officeCode)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    data.users.forEach(user => {
                        const option = document.createElement('option');
                        option.value = user.user_id;
                        option.textContent = `${user.username} (${user.office_name})`;
                        userIdSelect.appendChild(option);
                    });
                }
            })
            .catch(error => {
                console.error('Error fetching users:', error);
            });
    }
});
</script>

<style>
.card {
    margin-bottom: 1.5rem;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.card-header {
    border-bottom: 1px solid rgba(0,0,0,0.1);
}

.table th {
    font-weight: 600;
    color: #495057;
    background-color: #f8f9fa;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.badge {
    font-weight: 500;
}

.form-check {
    margin-bottom: 0.5rem;
}

.alert.position-fixed {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.9rem;
    }
    
    .btn-group-sm {
        flex-wrap: wrap;
    }
    
    .btn-group-sm .btn {
        margin-bottom: 0.25rem;
    }
    
    .col-md-4, .col-md-8 {
        margin-bottom: 1rem;
    }
}
</style>

<?php include '../includes/footer.php'; ?>