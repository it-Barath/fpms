<?php
/**
 * family_forms.php
 * Handle family form submissions and management
 */

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';
require_once '../classes/FamilyManager.php';

// Initialize Auth
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['moha', 'district', 'division', 'gn']);

// Initialize managers
$formManager = new FormManager();
$familyManager = new FamilyManager();

// Get current user info
$currentUser = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$officeCode = $_SESSION['office_code'];

// Get available forms for this user
$availableForms = $formManager->getAvailableForms($currentUser, $userType, $officeCode);

// Initialize variables
$error = '';
$success = '';
$formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
$familyId = isset($_GET['family_id']) ? $_GET['family_id'] : '';
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Get form details if form_id is provided
$form = null;
if ($formId > 0) {
    $form = $formManager->getFormWithFields($formId);
    
    // Check if user can fill this form
    if ($form && !$formManager->canUserFillForm($formId, $currentUser, $userType, $officeCode)) {
        $error = "You do not have permission to fill this form.";
        $form = null;
    }
}

// Get family details if family_id is provided
$family = null;
if ($familyId) {
    $family = $familyManager->getFamilyById($familyId);
    
    // Check if user has access to this family
    if ($family && $userType === 'gn' && $family['gn_id'] !== $officeCode) {
        $error = "You do not have access to this family.";
        $family = null;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $formId = intval($_POST['form_id']);
        $familyId = $_POST['family_id'];
        $saveDraft = isset($_POST['save_draft']);
        
        // Validate form ID
        $form = $formManager->getFormWithFields($formId);
        if (!$form) {
            throw new Exception("Form not found");
        }
        
        // Validate permissions
        if (!$formManager->canUserFillForm($formId, $currentUser, $userType, $officeCode)) {
            throw new Exception("You do not have permission to fill this form");
        }
        
        // Validate family
        $family = $familyManager->getFamilyById($familyId);
        if (!$family) {
            throw new Exception("Family not found");
        }
        
        // Check if user has access to this family
        if ($userType === 'gn' && $family['gn_id'] !== $officeCode) {
            throw new Exception("You do not have access to this family");
        }
        
        // Check submission limit
        $maxSubmissions = $form['max_submissions_per_entity'] ?? 1;
        if ($maxSubmissions > 0) {
            $submissionCount = $formManager->getFormStatsForUser($formId, $currentUser);
            $completed = $submissionCount['completed'] ?? 0;
            if ($completed >= $maxSubmissions) {
                throw new Exception("You have reached the maximum submissions limit for this form");
            }
        }
        
        // Prepare submission data
        $submission = [
            'form_id' => $formId,
            'family_id' => $familyId,
            'gn_id' => $family['gn_id'],
            'submitted_by' => $currentUser,
            'status' => $saveDraft ? 'draft' : 'submitted',
            'submission_date' => date('Y-m-d H:i:s')
        ];
        
        // Collect form data
        $formData = $_POST;
        $files = $_FILES;
        
        // Submit the form
        $result = $formManager->submitFamilyForm($submission, $formData, $files);
        
        if ($result['success']) {
            if ($saveDraft) {
                $success = "Form saved as draft successfully!";
            } else {
                $success = "Form submitted successfully!";
            }
            
            // Redirect to submissions list
            $_SESSION['flash_message'] = [
                'type' => 'success',
                'message' => $success
            ];
            header("Location: family_submissions.php");
            exit();
        } else {
            throw new Exception($result['error']);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = "Family Forms - " . SITE_NAME;

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
                            <i class="fas fa-file-alt"></i> Family Forms
                        </h1>
                        <p class="text-muted mb-0">
                            Fill out forms for families in your area
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="family_submissions.php" class="btn btn-outline-primary">
                            <i class="fas fa-history"></i> View Submissions
                        </a>
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
                <?php if ($action === 'fill' && $formId > 0): ?>
                    <!-- Form Filling Interface -->
                    <?php if ($form && $family): ?>
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-edit"></i> 
                                    <?php echo htmlspecialchars($form['form_name']); ?>
                                    <span class="badge bg-light text-primary ms-2">Family Form</span>
                                </h5>
                                <p class="mb-0 mt-1 small opacity-75">
                                    Family: <?php echo htmlspecialchars($familyId); ?> | 
                                    GN Division: <?php echo htmlspecialchars($family['gn_id']); ?>
                                </p>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($form['form_description'])): ?>
                                <div class="alert alert-info mb-4">
                                    <i class="fas fa-info-circle"></i>
                                    <?php echo htmlspecialchars($form['form_description']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <form method="POST" action="" id="familyForm" enctype="multipart/form-data" class="needs-validation" novalidate>
                                    <input type="hidden" name="form_id" value="<?php echo $formId; ?>">
                                    <input type="hidden" name="family_id" value="<?php echo htmlspecialchars($familyId); ?>">
                                    
                                    <?php if (!empty($form['fields'])): ?>
                                        <?php foreach ($form['fields'] as $index => $field): ?>
                                            <div class="mb-4">
                                                <label for="field_<?php echo $field['field_id']; ?>" class="form-label">
                                                    <?php echo htmlspecialchars($field['field_label']); ?>
                                                    <?php if ($field['is_required']): ?>
                                                        <span class="text-danger">*</span>
                                                    <?php endif; ?>
                                                </label>
                                                
                                                <?php if (!empty($field['hint_text'])): ?>
                                                    <div class="form-text mb-2">
                                                        <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($field['hint_text']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php switch ($field['field_type']):
                                                    case 'text': ?>
                                                        <input type="text" 
                                                               class="form-control" 
                                                               id="field_<?php echo $field['field_id']; ?>"
                                                               name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'textarea': ?>
                                                        <textarea class="form-control" 
                                                                  id="field_<?php echo $field['field_id']; ?>"
                                                                  name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                                  rows="4"
                                                                  placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                                  <?php echo $field['is_required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($field['default_value'] ?? ''); ?></textarea>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'number': ?>
                                                        <input type="number" 
                                                               class="form-control" 
                                                               id="field_<?php echo $field['field_id']; ?>"
                                                               name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'date': ?>
                                                        <input type="date" 
                                                               class="form-control" 
                                                               id="field_<?php echo $field['field_id']; ?>"
                                                               name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'radio': ?>
                                                        <?php if (!empty($field['field_options'])): ?>
                                                            <div class="form-group">
                                                                <?php foreach ($field['field_options'] as $option): ?>
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" 
                                                                               type="radio" 
                                                                               name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                                               id="field_<?php echo $field['field_id']; ?>_<?php echo htmlspecialchars($option['value']); ?>"
                                                                               value="<?php echo htmlspecialchars($option['value']); ?>"
                                                                               <?php echo ($field['default_value'] ?? '') === $option['value'] ? 'checked' : ''; ?>
                                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                                        <label class="form-check-label" for="field_<?php echo $field['field_id']; ?>_<?php echo htmlspecialchars($option['value']); ?>">
                                                                            <?php echo htmlspecialchars($option['label']); ?>
                                                                        </label>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'checkbox': ?>
                                                        <?php if (!empty($field['field_options'])): ?>
                                                            <div class="form-group">
                                                                <?php foreach ($field['field_options'] as $option): ?>
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" 
                                                                               type="checkbox" 
                                                                               name="<?php echo htmlspecialchars($field['field_code']); ?>[]"
                                                                               id="field_<?php echo $field['field_id']; ?>_<?php echo htmlspecialchars($option['value']); ?>"
                                                                               value="<?php echo htmlspecialchars($option['value']); ?>"
                                                                               <?php echo in_array($option['value'], explode(',', $field['default_value'] ?? '')) ? 'checked' : ''; ?>>
                                                                        <label class="form-check-label" for="field_<?php echo $field['field_id']; ?>_<?php echo htmlspecialchars($option['value']); ?>">
                                                                            <?php echo htmlspecialchars($option['label']); ?>
                                                                        </label>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'dropdown': ?>
                                                        <?php if (!empty($field['field_options'])): ?>
                                                            <select class="form-control" 
                                                                    id="field_<?php echo $field['field_id']; ?>"
                                                                    name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                                    <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                                <option value="">-- Select --</option>
                                                                <?php foreach ($field['field_options'] as $option): ?>
                                                                    <option value="<?php echo htmlspecialchars($option['value']); ?>"
                                                                            <?php echo ($field['default_value'] ?? '') === $option['value'] ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($option['label']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        <?php endif; ?>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'email': ?>
                                                        <input type="email" 
                                                               class="form-control" 
                                                               id="field_<?php echo $field['field_id']; ?>"
                                                               name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'phone': ?>
                                                        <input type="tel" 
                                                               class="form-control" 
                                                               id="field_<?php echo $field['field_id']; ?>"
                                                               name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                               pattern="[0-9+\-\s()]*"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'yesno': ?>
                                                        <div class="form-group">
                                                            <div class="form-check form-check-inline">
                                                                <input class="form-check-input" 
                                                                       type="radio" 
                                                                       name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                                       id="field_<?php echo $field['field_id']; ?>_yes"
                                                                       value="yes"
                                                                       <?php echo ($field['default_value'] ?? '') === 'yes' ? 'checked' : ''; ?>
                                                                       <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                                <label class="form-check-label" for="field_<?php echo $field['field_id']; ?>_yes">Yes</label>
                                                            </div>
                                                            <div class="form-check form-check-inline">
                                                                <input class="form-check-input" 
                                                                       type="radio" 
                                                                       name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                                       id="field_<?php echo $field['field_id']; ?>_no"
                                                                       value="no"
                                                                       <?php echo ($field['default_value'] ?? '') === 'no' ? 'checked' : ''; ?>
                                                                       <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                                <label class="form-check-label" for="field_<?php echo $field['field_id']; ?>_no">No</label>
                                                            </div>
                                                        </div>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'file': ?>
                                                        <input type="file" 
                                                               class="form-control" 
                                                               id="field_<?php echo $field['field_id']; ?>"
                                                               name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                               accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <div class="form-text">
                                                            Accepted formats: JPG, PNG, PDF, DOC (Max: 2MB)
                                                        </div>
                                                        <?php break; ?>
                                                    
                                                    <?php case 'rating': ?>
                                                        <div class="rating-input">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <div class="form-check form-check-inline">
                                                                    <input class="form-check-input" 
                                                                           type="radio" 
                                                                           name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                                           id="field_<?php echo $field['field_id']; ?>_<?php echo $i; ?>"
                                                                           value="<?php echo $i; ?>"
                                                                           <?php echo ($field['default_value'] ?? '') == $i ? 'checked' : ''; ?>
                                                                           <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                                    <label class="form-check-label" for="field_<?php echo $field['field_id']; ?>_<?php echo $i; ?>">
                                                                        <?php echo $i; ?> <i class="fas fa-star text-warning"></i>
                                                                    </label>
                                                                </div>
                                                            <?php endfor; ?>
                                                        </div>
                                                        <?php break; ?>
                                                    
                                                    <?php default: ?>
                                                        <input type="text" 
                                                               class="form-control" 
                                                               id="field_<?php echo $field['field_id']; ?>"
                                                               name="<?php echo htmlspecialchars($field['field_code']); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                <?php endswitch; ?>
                                                
                                                <div class="invalid-feedback">
                                                    Please fill out this field.
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle"></i> This form doesn't have any fields yet.
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Form Actions -->
                                    <div class="d-flex justify-content-between mt-5 pt-4 border-top">
                                        <a href="family_forms.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                        <div>
                                            <button type="submit" name="save_draft" value="1" class="btn btn-outline-primary me-2">
                                                <i class="fas fa-save"></i> Save as Draft
                                            </button>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-paper-plane"></i> Submit Form
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> Form or family not found.
                        </div>
                    <?php endif; ?>
                
                <?php else: ?>
                    <!-- Available Forms List -->
                    <div class="row">
                        <div class="col-md-8">
                            <!-- Forms List -->
                            <div class="card mb-4">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-list"></i> Available Forms
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($availableForms)): ?>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> No forms are currently available for you to fill.
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Form Name</th>
                                                        <th>Category</th>
                                                        <th>Type</th>
                                                        <th>Created By</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($availableForms as $formItem): ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?php echo htmlspecialchars($formItem['form_name']); ?></strong>
                                                                <?php if (!empty($formItem['form_description'])): ?>
                                                                    <br><small class="text-muted"><?php echo htmlspecialchars(substr($formItem['form_description'], 0, 100)) . '...'; ?></small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php echo htmlspecialchars($formItem['form_category'] ?? 'Uncategorized'); ?>
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
                                                                <div class="btn-group btn-group-sm">
                                                                    <button type="button" 
                                                                            class="btn btn-outline-primary"
                                                                            onclick="selectForm(<?php echo $formItem['form_id']; ?>)">
                                                                        <i class="fas fa-edit"></i> Fill Form
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Quick Stats -->
                            <?php
                            $stats = $formManager->getUserFormStats($currentUser);
                            ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card text-white bg-primary mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="card-title">Assigned Forms</h6>
                                                    <h2 class="mb-0"><?php echo $stats['assigned_forms']; ?></h2>
                                                </div>
                                                <i class="fas fa-file-alt fa-3x opacity-50"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card text-white bg-success mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="card-title">Completed Submissions</h6>
                                                    <h2 class="mb-0"><?php echo $stats['completed_submissions']; ?></h2>
                                                </div>
                                                <i class="fas fa-check-circle fa-3x opacity-50"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <!-- Family Search -->
                            <div class="card mb-4">
                                <div class="card-header bg-info text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-search"></i> Select Family
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="familySearch" class="form-label">Search Family</label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="familySearch" 
                                               placeholder="Enter Family ID or Head NIC">
                                    </div>
                                    
                                    <div id="searchResults" class="mt-3" style="max-height: 300px; overflow-y: auto;">
                                        <!-- Search results will appear here -->
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button class="btn btn-outline-info btn-sm w-100" onclick="searchFamilies()">
                                            <i class="fas fa-search"></i> Search
                                        </button>
                                    </div>
                                    
                                    <div class="mt-3 border-top pt-3">
                                        <h6>Quick Access</h6>
                                        <div class="list-group list-group-flush" id="recentFamilies">
                                            <!-- Recent families will appear here -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Selected Form & Family -->
                            <div class="card">
                                <div class="card-header bg-warning text-dark">
                                    <h5 class="mb-0">
                                        <i class="fas fa-play-circle"></i> Ready to Fill
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div id="selectedFormInfo" class="mb-3">
                                        <p class="text-muted">Select a form and a family to begin.</p>
                                    </div>
                                    
                                    <div id="selectedFamilyInfo" class="mb-3">
                                        <p class="text-muted">No family selected.</p>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button id="startFormBtn" 
                                                class="btn btn-primary" 
                                                disabled
                                                onclick="startFormFilling()">
                                            <i class="fas fa-play"></i> Start Filling Form
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>

<!-- Modal for Family Search -->
<div class="modal fade" id="familySearchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Search Families</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Search by Family ID</label>
                            <input type="text" class="form-control" id="searchFamilyId" placeholder="Enter Family ID">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Search by Head NIC</label>
                            <input type="text" class="form-control" id="searchHeadNic" placeholder="Enter Head NIC">
                        </div>
                    </div>
                </div>
                
                <div class="text-center">
                    <button class="btn btn-primary" onclick="advancedFamilySearch()">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
                
                <div id="advancedSearchResults" class="mt-3">
                    <!-- Results will appear here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Selected form and family
let selectedFormId = null;
let selectedFormName = null;
let selectedFamilyId = null;
let selectedFamilyInfo = null;

// Select a form
function selectForm(formId) {
    // Reset family selection
    selectedFamilyId = null;
    selectedFamilyInfo = null;
    updateFamilyInfo();
    
    // Find form details
    <?php foreach ($availableForms as $formItem): ?>
        if (<?php echo $formItem['form_id']; ?> === formId) {
            selectedFormId = formId;
            selectedFormName = "<?php echo addslashes($formItem['form_name']); ?>";
            
            // Update UI
            document.getElementById('selectedFormInfo').innerHTML = `
                <div class="alert alert-info p-2">
                    <strong><i class="fas fa-file-alt"></i> Selected Form:</strong><br>
                    ${selectedFormName}
                    <br><small class="text-muted">
                        Type: <?php echo addslashes(ucfirst($formItem['target_entity'])); ?> | 
                        Category: <?php echo addslashes($formItem['form_category'] ?? 'Uncategorized'); ?>
                    </small>
                </div>
            `;
            
            // Update button
            updateStartButton();
            return;
        }
    <?php endforeach; ?>
}

// Select a family
function selectFamily(familyId, familyInfo) {
    selectedFamilyId = familyId;
    selectedFamilyInfo = familyInfo;
    updateFamilyInfo();
    updateStartButton();
}

// Update family info display
function updateFamilyInfo() {
    const familyInfoDiv = document.getElementById('selectedFamilyInfo');
    
    if (selectedFamilyId && selectedFamilyInfo) {
        familyInfoDiv.innerHTML = `
            <div class="alert alert-success p-2">
                <strong><i class="fas fa-home"></i> Selected Family:</strong><br>
                    ${selectedFamilyInfo.family_id}
                <br><small class="text-muted">
                    GN: ${selectedFamilyInfo.gn_id} | 
                    Head: ${selectedFamilyInfo.family_head_nic || 'N/A'}
                </small>
            </div>
        `;
    } else {
        familyInfoDiv.innerHTML = '<p class="text-muted">No family selected.</p>';
    }
}

// Update start button state
function updateStartButton() {
    const startBtn = document.getElementById('startFormBtn');
    
    if (selectedFormId && selectedFamilyId) {
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-play"></i> Start Filling Form';
    } else {
        startBtn.disabled = true;
        if (!selectedFormId && !selectedFamilyId) {
            startBtn.innerHTML = 'Select a form and family';
        } else if (!selectedFormId) {
            startBtn.innerHTML = 'Select a form';
        } else {
            startBtn.innerHTML = 'Select a family';
        }
    }
}

// Start form filling
function startFormFilling() {
    if (selectedFormId && selectedFamilyId) {
        window.location.href = `family_forms.php?action=fill&form_id=${selectedFormId}&family_id=${selectedFamilyId}`;
    }
}

// Search families
function searchFamilies() {
    const searchTerm = document.getElementById('familySearch').value.trim();
    const resultsDiv = document.getElementById('searchResults');
    
    if (!searchTerm) {
        resultsDiv.innerHTML = '<div class="alert alert-warning">Please enter a search term</div>';
        return;
    }
    
    resultsDiv.innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div><p>Searching...</p></div>';
    
    // AJAX search
    fetch(`../ajax/search_families.php?q=${encodeURIComponent(searchTerm)}&office_code=<?php echo $officeCode; ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.families.length > 0) {
                let html = '<div class="list-group">';
                data.families.forEach(family => {
                    html += `
                        <a href="#" class="list-group-item list-group-item-action" onclick="selectFamily('${family.family_id}', ${JSON.stringify(family)})">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">${family.family_id}</h6>
                                <small>GN: ${family.gn_id}</small>
                            </div>
                            <p class="mb-1">Head NIC: ${family.family_head_nic || 'Not specified'}</p>
                            <small>Members: ${family.total_members || 1}</small>
                        </a>
                    `;
                });
                html += '</div>';
                resultsDiv.innerHTML = html;
            } else {
                resultsDiv.innerHTML = '<div class="alert alert-warning">No families found</div>';
            }
        })
        .catch(error => {
            resultsDiv.innerHTML = '<div class="alert alert-danger">Search failed</div>';
            console.error('Error:', error);
        });
}

// Advanced family search
function advancedFamilySearch() {
    const familyId = document.getElementById('searchFamilyId').value.trim();
    const headNic = document.getElementById('searchHeadNic').value.trim();
    const resultsDiv = document.getElementById('advancedSearchResults');
    
    if (!familyId && !headNic) {
        resultsDiv.innerHTML = '<div class="alert alert-warning">Please enter search criteria</div>';
        return;
    }
    
    resultsDiv.innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div><p>Searching...</p></div>';
    
    let url = `../ajax/search_families.php?office_code=<?php echo $officeCode; ?>`;
    if (familyId) url += `&family_id=${encodeURIComponent(familyId)}`;
    if (headNic) url += `&head_nic=${encodeURIComponent(headNic)}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.families.length > 0) {
                let html = '<div class="list-group">';
                data.families.forEach(family => {
                    html += `
                        <a href="#" class="list-group-item list-group-item-action" onclick="selectFamily('${family.family_id}', ${JSON.stringify(family)}); $('#familySearchModal').modal('hide');">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">${family.family_id}</h6>
                                <small>GN: ${family.gn_id}</small>
                            </div>
                            <p class="mb-1">Head NIC: ${family.family_head_nic || 'Not specified'}</p>
                            <small>Members: ${family.total_members || 1}</small>
                        </a>
                    `;
                });
                html += '</div>';
                resultsDiv.innerHTML = html;
            } else {
                resultsDiv.innerHTML = '<div class="alert alert-warning">No families found</div>';
            }
        })
        .catch(error => {
            resultsDiv.innerHTML = '<div class="alert alert-danger">Search failed</div>';
            console.error('Error:', error);
        });
}

// Load recent families
function loadRecentFamilies() {
    const recentDiv = document.getElementById('recentFamilies');
    
    fetch(`../ajax/get_recent_families.php?user_id=<?php echo $currentUser; ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.families.length > 0) {
                let html = '';
                data.families.forEach(family => {
                    html += `
                        <a href="#" class="list-group-item list-group-item-action py-2" onclick="selectFamily('${family.family_id}', ${JSON.stringify(family)})">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>${family.family_id}</span>
                                <small class="text-muted">${family.total_members || 1} members</small>
                            </div>
                        </a>
                    `;
                });
                recentDiv.innerHTML = html;
            } else {
                recentDiv.innerHTML = '<div class="text-muted small">No recent families</div>';
            }
        })
        .catch(error => {
            recentDiv.innerHTML = '<div class="text-muted small">Unable to load</div>';
        });
}

// Form validation
(function() {
    'use strict';
    
    const form = document.getElementById('familyForm');
    if (form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                
                // Find first invalid field
                const firstInvalid = form.querySelector(':invalid');
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstInvalid.focus();
                }
            }
            
            form.classList.add('was-validated');
        }, false);
    }
})();

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadRecentFamilies();
    
    // Enable Enter key for search
    document.getElementById('familySearch').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchFamilies();
        }
    });
    
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(element => {
        new bootstrap.Tooltip(element);
    });
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

.list-group-item {
    border: 1px solid rgba(0,0,0,0.125);
    margin-bottom: -1px;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}

.rating-input .form-check-label {
    cursor: pointer;
}

.rating-input .form-check-input:checked + .form-check-label {
    color: #ffc107;
}

.invalid-feedback {
    display: block;
}

.was-validated .form-control:invalid {
    border-color: #dc3545;
}

.was-validated .form-control:valid {
    border-color: #198754;
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
}
</style>

<?php include '../includes/footer.php'; ?>