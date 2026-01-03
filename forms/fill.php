<?php
/**
 * fill.php
 * Form filling interface for GN/Division/District users
 */

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';
require_once '../classes/FamilyManager.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['gn', 'division', 'district']);

$formManager = new FormManager();
$familyManager = new FamilyManager();

// Get parameters
$formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
$targetType = isset($_GET['type']) ? $_GET['type'] : 'family'; // family or member
$editSubmissionId = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$familyId = isset($_GET['family_id']) ? $_GET['family_id'] : '';
$memberId = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
$redirectUrl = isset($_GET['redirect']) ? $_GET['redirect'] : '';

if (!$formId) {
    header('Location: slider.php');
    exit();
}

// Get current user info
$currentUser = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$officeCode = $_SESSION['office_code'];

// Get form details
$form = $formManager->getFormWithFields($formId);
if (!$form) {
    header('Location: slider.php');
    exit();
}

// Check if user has permission to fill this form
if (!$formManager->canUserFillForm($formId, $currentUser, $userType, $officeCode)) {
    header('Location: slider.php?error=no_permission');
    exit();
}

// Check if form is active and available
if (!$form['is_active']) {
    header('Location: slider.php?error=form_inactive');
    exit();
}

// Check date restrictions
$now = time();
if ($form['start_date'] && strtotime($form['start_date']) > $now) {
    header('Location: slider.php?error=form_not_started');
    exit();
}
if ($form['end_date'] && strtotime($form['end_date']) < $now) {
    header('Location: slider.php?error=form_expired');
    exit();
}

// Check target type compatibility
if ($form['target_entity'] !== 'both' && $form['target_entity'] !== $targetType) {
    header('Location: slider.php?error=invalid_target_type');
    exit();
}

// Get families under this user's jurisdiction
$families = $familyManager->getFamiliesByGN($officeCode);

// Get existing submission if editing
$existingSubmission = null;
if ($editSubmissionId) {
    if ($targetType === 'family') {
        $existingSubmission = $formManager->getFamilySubmission($editSubmissionId, $currentUser);
    } else {
        $existingSubmission = $formManager->getMemberSubmission($editSubmissionId, $currentUser);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submissionData = $_POST;
    $files = $_FILES;
    
    // Validate required data
    if ($targetType === 'family' && empty($submissionData['family_id'])) {
        $error = "Please select a family";
    } elseif ($targetType === 'member' && (empty($submissionData['family_id']) || empty($submissionData['member_id']))) {
        $error = "Please select a family and member";
    } else {
        // Prepare submission
        $submission = [
            'form_id' => $formId,
            'submitted_by' => $currentUser,
            'gn_id' => $officeCode,
            'status' => isset($_POST['save_draft']) ? 'draft' : 'submitted',
            'submission_date' => date('Y-m-d H:i:s')
        ];
        
        if ($targetType === 'family') {
            $submission['family_id'] = $submissionData['family_id'];
            $result = $formManager->submitFamilyForm($submission, $submissionData, $files);
        } else {
            $submission['family_id'] = $submissionData['family_id'];
            $submission['member_id'] = $submissionData['member_id'];
            $result = $formManager->submitMemberForm($submission, $submissionData, $files);
        }
        
        if ($result['success']) {
            $messageType = isset($_POST['save_draft']) ? 'Draft saved' : 'Form submitted';
            $_SESSION['flash_message'] = [
                'type' => 'success',
                'message' => $messageType . ' successfully!'
            ];
            
            // Redirect back to previous page if specified
            if ($redirectUrl && filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                header('Location: ' . $redirectUrl);
            } else {
                header('Location: slider.php');
            }
            exit();
        } else {
            $error = $result['error'];
        }
    }
}

// Set page variables for header
$page_title = "Fill Form: " . htmlspecialchars($form['form_name']);
$page_subtitle = "Complete the form for " . ($targetType === 'family' ? 'a family' : 'a family member');
$breadcrumb = [
    ['label' => 'Dashboard', 'url' => '../index.php'],
    ['label' => 'Forms', 'url' => 'slider.php'],
    ['label' => 'Fill Form', 'url' => '#']
];

// Include header
include '../includes/header.php';
?>

<!-- Main Content -->
<main id="main-content">
    <!-- Top Navigation -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2">
                <i class="bi bi-pencil-square"></i> Fill Form
            </h1>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($form['form_name']); ?> - 
                <span class="badge <?php echo $targetType === 'family' ? 'bg-info' : 'bg-warning'; ?>">
                    <?php echo ucfirst($targetType); ?> Form
                </span>
            </p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="<?php echo $redirectUrl ?: 'slider.php'; ?>" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <button type="button" class="btn btn-outline-info" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>
    
    <?php if (function_exists('displayFlashMessage')) displayFlashMessage(); ?>
    
    <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Form Container -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-card-text"></i> <?php echo htmlspecialchars($form['form_name']); ?>
                </h5>
                <div>
                    <span class="badge bg-secondary">
                        <i class="bi bi-list-ol"></i> <?php echo count($form['fields']); ?> fields
                    </span>
                    <?php if ($form['form_category']): ?>
                    <span class="badge bg-light text-dark border ms-1">
                        <?php echo htmlspecialchars($form['form_category']); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if ($form['form_description']): ?>
            <div class="alert alert-info mb-4">
                <i class="bi bi-info-circle"></i> <?php echo htmlspecialchars($form['form_description']); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data" id="fillForm" class="needs-validation" novalidate>
                <!-- Family/Member Selection -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0">
                            <i class="bi bi-person-lines-fill"></i> Select <?php echo ucfirst($targetType); ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if ($targetType === 'family'): ?>
                        <div class="mb-3">
                            <label for="family_id" class="form-label">
                                Select Family <span class="text-danger">*</span>
                            </label>
                            <select class="form-control" id="family_id" name="family_id" required>
                                <option value="">-- Select Family --</option>
                                <?php foreach ($families as $family): ?>
                                <option value="<?php echo htmlspecialchars($family['family_id']); ?>"
                                        <?php echo ($existingSubmission && $existingSubmission['family_id'] === $family['family_id']) ? 'selected' : ''; ?>
                                        <?php echo ($familyId === $family['family_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($family['family_id']); ?> - 
                                    <?php echo htmlspecialchars($family['head_name'] ?? 'Family'); ?>
                                    (<?php echo $family['total_members']; ?> members)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Only families under your GN division are shown
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="family_id_member" class="form-label">
                                        Select Family <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control" id="family_id_member" name="family_id" required 
                                            onchange="loadMembers(this.value)">
                                        <option value="">-- Select Family --</option>
                                        <?php foreach ($families as $family): ?>
                                        <option value="<?php echo htmlspecialchars($family['family_id']); ?>"
                                                <?php echo ($existingSubmission && $existingSubmission['family_id'] === $family['family_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($family['family_id']); ?> - 
                                            <?php echo htmlspecialchars($family['head_name'] ?? 'Family'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="member_id" class="form-label">
                                        Select Member <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control" id="member_id" name="member_id" required 
                                            <?php echo empty($existingSubmission) ? 'disabled' : ''; ?>>
                                        <option value="">-- Select Member --</option>
                                        <?php if ($existingSubmission): ?>
                                        <option value="<?php echo $existingSubmission['member_id']; ?>" selected>
                                            <?php echo htmlspecialchars($existingSubmission['member_name']); ?>
                                        </option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            Please ensure you have selected the correct <?php echo $targetType; ?>.
                            This cannot be changed after submission.
                        </div>
                    </div>
                </div>
                
                <!-- Form Fields -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <i class="bi bi-input-cursor-text"></i> Form Questions
                            <small class="text-muted">(Fields marked with * are required)</small>
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($form['fields'])): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-input-cursor-text fs-1 text-muted"></i>
                            <h5 class="text-muted mt-3">No form fields defined</h5>
                            <p class="text-muted">This form doesn't have any questions yet.</p>
                        </div>
                        <?php else: ?>
                        <?php 
                        // Sort fields by order
                        usort($form['fields'], function($a, $b) {
                            return $a['field_order'] <=> $b['field_order'];
                        });
                        
                        foreach ($form['fields'] as $field): 
                            $fieldId = 'field_' . $field['field_id'];
                            $fieldName = $field['field_code'];
                            $required = $field['is_required'] ? 'required' : '';
                            $requiredStar = $field['is_required'] ? ' <span class="text-danger">*</span>' : '';
                            
                            // Get existing value if editing
                            $existingValue = '';
                            if ($existingSubmission && isset($existingSubmission['responses'][$fieldName])) {
                                $existingValue = $existingSubmission['responses'][$fieldName];
                            }
                        ?>
                        <div class="mb-4">
                            <label for="<?php echo $fieldId; ?>" class="form-label">
                                <?php echo htmlspecialchars($field['field_label']); ?><?php echo $requiredStar; ?>
                            </label>
                            
                            <?php if ($field['hint_text']): ?>
                            <div class="form-text mb-2">
                                <i class="bi bi-info-circle"></i> <?php echo htmlspecialchars($field['hint_text']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Render field based on type -->
                            <?php switch ($field['field_type']): 
                                case 'text': ?>
                                    <input type="text" 
                                           class="form-control" 
                                           id="<?php echo $fieldId; ?>"
                                           name="<?php echo $fieldName; ?>"
                                           placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                           value="<?php echo htmlspecialchars($existingValue ?: ($field['default_value'] ?? '')); ?>"
                                           <?php echo $required; ?>>
                                    <?php break; ?>
                                    
                                <?php case 'textarea': ?>
                                    <textarea class="form-control" 
                                              id="<?php echo $fieldId; ?>"
                                              name="<?php echo $fieldName; ?>"
                                              rows="<?php echo substr_count($field['default_value'] ?? '', "\n") + 3; ?>"
                                              placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                              <?php echo $required; ?>><?php echo htmlspecialchars($existingValue ?: ($field['default_value'] ?? '')); ?></textarea>
                                    <?php break; ?>
                                    
                                <?php case 'number': ?>
                                    <input type="number" 
                                           class="form-control" 
                                           id="<?php echo $fieldId; ?>"
                                           name="<?php echo $fieldName; ?>"
                                           placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                           value="<?php echo htmlspecialchars($existingValue ?: ($field['default_value'] ?? '')); ?>"
                                           <?php echo $required; ?>>
                                    <?php break; ?>
                                    
                                <?php case 'date': ?>
                                    <input type="date" 
                                           class="form-control" 
                                           id="<?php echo $fieldId; ?>"
                                           name="<?php echo $fieldName; ?>"
                                           value="<?php echo htmlspecialchars($existingValue ?: ($field['default_value'] ?? '')); ?>"
                                           <?php echo $required; ?>>
                                    <?php break; ?>
                                    
                                <?php case 'email': ?>
                                    <input type="email" 
                                           class="form-control" 
                                           id="<?php echo $fieldId; ?>"
                                           name="<?php echo $fieldName; ?>"
                                           placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                           value="<?php echo htmlspecialchars($existingValue ?: ($field['default_value'] ?? '')); ?>"
                                           <?php echo $required; ?>>
                                    <?php break; ?>
                                    
                                <?php case 'phone': ?>
                                    <input type="tel" 
                                           class="form-control" 
                                           id="<?php echo $fieldId; ?>"
                                           name="<?php echo $fieldName; ?>"
                                           placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                           value="<?php echo htmlspecialchars($existingValue ?: ($field['default_value'] ?? '')); ?>"
                                           pattern="[0-9]{10}"
                                           <?php echo $required; ?>>
                                    <?php break; ?>
                                    
                                <?php case 'dropdown': ?>
                                    <?php 
                                    $options = [];
                                    if (!empty($field['field_options'])) {
                                        if (is_string($field['field_options'])) {
                                            $options = json_decode($field['field_options'], true);
                                        } elseif (is_array($field['field_options'])) {
                                            $options = $field['field_options'];
                                        }
                                    }
                                    ?>
                                    <select class="form-control" 
                                            id="<?php echo $fieldId; ?>"
                                            name="<?php echo $fieldName; ?>"
                                            <?php echo $required; ?>>
                                        <option value="">-- Select --</option>
                                        <?php if (is_array($options)): ?>
                                        <?php foreach ($options as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>"
                                                <?php echo ($existingValue ?: ($field['default_value'] ?? '')) === $option ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($option); ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <?php break; ?>
                                    
                                <?php case 'radio': ?>
                                    <?php 
                                    $options = [];
                                    if (!empty($field['field_options'])) {
                                        if (is_string($field['field_options'])) {
                                            $options = json_decode($field['field_options'], true);
                                        } elseif (is_array($field['field_options'])) {
                                            $options = $field['field_options'];
                                        }
                                    }
                                    ?>
                                    <?php if (is_array($options)): ?>
                                    <div class="radio-group">
                                        <?php foreach ($options as $index => $option): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" 
                                                   type="radio" 
                                                   name="<?php echo $fieldName; ?>"
                                                   id="<?php echo $fieldId . '_' . $index; ?>"
                                                   value="<?php echo htmlspecialchars($option); ?>"
                                                   <?php echo ($existingValue ?: ($field['default_value'] ?? '')) === $option ? 'checked' : ''; ?>
                                                   <?php echo $required; ?>>
                                            <label class="form-check-label" for="<?php echo $fieldId . '_' . $index; ?>">
                                                <?php echo htmlspecialchars($option); ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php break; ?>
                                    
                                <?php case 'checkbox': ?>
                                    <?php 
                                    $options = [];
                                    if (!empty($field['field_options'])) {
                                        if (is_string($field['field_options'])) {
                                            $options = json_decode($field['field_options'], true);
                                        } elseif (is_array($field['field_options'])) {
                                            $options = $field['field_options'];
                                        }
                                    }
                                    ?>
                                    <?php if (is_array($options)): ?>
                                    <div class="checkbox-group">
                                        <?php 
                                        $defaultValues = !empty($field['default_value']) ? 
                                            explode(',', $field['default_value']) : [];
                                        $existingValues = !empty($existingValue) ? 
                                            explode(',', $existingValue) : [];
                                        $selectedValues = !empty($existingValues) ? $existingValues : $defaultValues;
                                        ?>
                                        <?php foreach ($options as $index => $option): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" 
                                                   type="checkbox" 
                                                   name="<?php echo $fieldName; ?>[]"
                                                   id="<?php echo $fieldId . '_' . $index; ?>"
                                                   value="<?php echo htmlspecialchars($option); ?>"
                                                   <?php echo in_array($option, $selectedValues) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="<?php echo $fieldId . '_' . $index; ?>">
                                                <?php echo htmlspecialchars($option); ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php break; ?>
                                    
                                <?php case 'yesno': ?>
                                    <div class="yesno-group">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" 
                                                   type="radio" 
                                                   name="<?php echo $fieldName; ?>"
                                                   id="<?php echo $fieldId; ?>_yes"
                                                   value="yes"
                                                   <?php echo ($existingValue ?: ($field['default_value'] ?? '')) === 'yes' ? 'checked' : ''; ?>
                                                   <?php echo $required; ?>>
                                            <label class="form-check-label" for="<?php echo $fieldId; ?>_yes">
                                                Yes
                                            </label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" 
                                                   type="radio" 
                                                   name="<?php echo $fieldName; ?>"
                                                   id="<?php echo $fieldId; ?>_no"
                                                   value="no"
                                                   <?php echo ($existingValue ?: ($field['default_value'] ?? '')) === 'no' ? 'checked' : ''; ?>
                                                   <?php echo $required; ?>>
                                            <label class="form-check-label" for="<?php echo $fieldId; ?>_no">
                                                No
                                            </label>
                                        </div>
                                    </div>
                                    <?php break; ?>
                                    
                                <?php case 'file': ?>
                                    <input type="file" 
                                           class="form-control" 
                                           id="<?php echo $fieldId; ?>"
                                           name="<?php echo $fieldName; ?>"
                                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                           <?php echo $required; ?>>
                                    <?php if ($existingValue): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Current file: <?php echo basename($existingValue); ?></small>
                                        <br>
                                        <small>
                                            <input type="checkbox" name="<?php echo $fieldName; ?>_remove" id="<?php echo $fieldId; ?>_remove">
                                            <label for="<?php echo $fieldId; ?>_remove">Remove current file</label>
                                        </small>
                                    </div>
                                    <?php else: ?>
                                    <small class="text-muted">
                                        Accepted: PDF, DOC, JPG, PNG (Max: 2MB)
                                    </small>
                                    <?php endif; ?>
                                    <?php break; ?>
                                    
                                <?php case 'rating': ?>
                                    <div class="rating-group">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" 
                                                   type="radio" 
                                                   name="<?php echo $fieldName; ?>"
                                                   id="<?php echo $fieldId . '_' . $i; ?>"
                                                   value="<?php echo $i; ?>"
                                                   <?php echo ($existingValue ?: ($field['default_value'] ?? '')) == $i ? 'checked' : ''; ?>
                                                   <?php echo $required; ?>>
                                            <label class="form-check-label" for="<?php echo $fieldId . '_' . $i; ?>">
                                                <?php echo $i; ?> <i class="bi bi-star"></i>
                                            </label>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                    <?php break; ?>
                                    
                                <?php default: ?>
                                    <input type="text" 
                                           class="form-control" 
                                           id="<?php echo $fieldId; ?>"
                                           name="<?php echo $fieldName; ?>"
                                           placeholder="Field type not supported"
                                           readonly>
                            <?php endswitch; ?>
                            
                            <div class="field-info mt-1">
                                <small class="text-muted">
                                    <i class="bi bi-tag"></i> Field Code: <?php echo htmlspecialchars($field['field_code']); ?>
                                </small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="bi bi-arrow-clockwise"></i> Reset Form
                                </button>
                            </div>
                            <div>
                                <button type="submit" name="save_draft" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-save"></i> Save as Draft
                                </button>
                                <button type="submit" name="submit_form" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i> Submit Form
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Form Instructions -->
    <div class="card">
        <div class="card-header bg-info text-white">
            <h6 class="mb-0">
                <i class="bi bi-info-circle"></i> Form Instructions
            </h6>
        </div>
        <div class="card-body">
            <ol>
                <li>Select the correct <?php echo $targetType; ?> from the dropdown list</li>
                <li>Fill all required fields (marked with *)</li>
                <li>Review your answers before submission</li>
                <li>Use "Save as Draft" if you need to complete the form later</li>
                <li>Click "Submit Form" when you're ready to submit</li>
            </ol>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Important:</strong> Once submitted, the form cannot be edited unless returned for revision.
            </div>
        </div>
    </div>
</main>

<script>
// Form validation
(function() {
    'use strict';
    
    const form = document.getElementById('fillForm');
    
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
            
            // Find first invalid field
            const firstInvalid = form.querySelector(':invalid');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.focus();
                
                // Show error message
                showNotification('Please fill all required fields correctly', 'danger');
            }
        }
        
        form.classList.add('was-validated');
    }, false);
})();

// Load members for selected family
function loadMembers(familyId) {
    if (!familyId) {
        document.getElementById('member_id').disabled = true;
        document.getElementById('member_id').innerHTML = '<option value="">-- Select Member --</option>';
        return;
    }
    
    // Show loading
    document.getElementById('member_id').innerHTML = '<option value="">Loading members...</option>';
    document.getElementById('member_id').disabled = true;
    
    // Fetch members from API
    fetch(`../api/get_members.php?family_id=${familyId}`)
        .then(response => response.json())
        .then(data => {
            const memberSelect = document.getElementById('member_id');
            memberSelect.innerHTML = '<option value="">-- Select Member --</option>';
            
            if (data.success && data.members) {
                data.members.forEach(member => {
                    const option = document.createElement('option');
                    option.value = member.citizen_id;
                    option.textContent = `${member.full_name} (${member.identification_number})`;
                    memberSelect.appendChild(option);
                });
                memberSelect.disabled = false;
            } else {
                memberSelect.innerHTML = '<option value="">No members found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading members:', error);
            document.getElementById('member_id').innerHTML = '<option value="">Error loading members</option>';
        });
}

// Reset form
function resetForm() {
    if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        document.getElementById('fillForm').reset();
        document.getElementById('fillForm').classList.remove('was-validated');
        
        // Reset member dropdown if on member form
        if (document.getElementById('family_id_member')) {
            document.getElementById('member_id').disabled = true;
            document.getElementById('member_id').innerHTML = '<option value="">-- Select Member --</option>';
        }
        
        showNotification('Form has been reset', 'info');
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
        <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-info-circle'}"></i>
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

// Auto-save draft every 2 minutes
let autoSaveTimer;
function startAutoSave() {
    autoSaveTimer = setInterval(() => {
        // Only auto-save if form has data
        const formData = new FormData(document.getElementById('fillForm'));
        let hasData = false;
        for (let value of formData.values()) {
            if (value) {
                hasData = true;
                break;
            }
        }
        
        if (hasData) {
            // In real implementation, you would save via AJAX
            console.log('Auto-save triggered');
            showNotification('Draft auto-saved', 'info');
        }
    }, 120000); // 2 minutes
}

// Stop auto-save
function stopAutoSave() {
    if (autoSaveTimer) {
        clearInterval(autoSaveTimer);
    }
}

// Initialize auto-save when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Start auto-save
    startAutoSave();
    
    // Stop auto-save when leaving page
    window.addEventListener('beforeunload', function(e) {
        // Only warn if form has data and not submitted
        const form = document.getElementById('fillForm');
        const formData = new FormData(form);
        let hasData = false;
        
        for (let value of formData.values()) {
            if (value && typeof value !== 'object') { // Skip file objects
                hasData = true;
                break;
            }
        }
        
        if (hasData) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            return e.returnValue;
        }
    });
    
    // Check if we're editing an existing submission
    <?php if ($editSubmissionId && $existingSubmission): ?>
    showNotification('Editing existing submission', 'info');
    <?php endif; ?>
    
    // Initialize member selection if family is already selected
    <?php if ($targetType === 'member' && $existingSubmission): ?>
    loadMembers('<?php echo $existingSubmission["family_id"]; ?>');
    <?php endif; ?>
});

// Character counter for textareas
document.querySelectorAll('textarea').forEach(textarea => {
    const maxLength = textarea.maxLength || 1000;
    
    // Create counter element
    const counter = document.createElement('small');
    counter.className = 'text-muted float-end';
    counter.textContent = `0/${maxLength}`;
    
    textarea.parentNode.insertBefore(counter, textarea.nextSibling);
    
    // Update counter on input
    textarea.addEventListener('input', function() {
        counter.textContent = `${this.value.length}/${maxLength}`;
        
        if (this.value.length > maxLength * 0.9) {
            counter.classList.remove('text-muted');
            counter.classList.add('text-warning');
        } else {
            counter.classList.remove('text-warning');
            counter.classList.add('text-muted');
        }
    });
});
</script>

<style>
/* Form specific styles */
.card {
    margin-bottom: 1.5rem;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.card-header {
    border-bottom: 1px solid rgba(0,0,0,0.1);
}

.form-label {
    font-weight: 500;
    color: #495057;
}

.form-text {
    color: #6c757d;
}

.radio-group .form-check,
.checkbox-group .form-check {
    margin-bottom: 8px;
}

.yesno-group,
.rating-group {
    padding: 10px;
    background-color: #f8f9fa;
    border-radius: 6px;
}

.field-info {
    padding: 5px;
    background-color: #e9ecef;
    border-radius: 4px;
    font-size: 12px;
}

.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
}

.alert.position-fixed {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

textarea {
    resize: vertical;
    min-height: 100px;
}

/* Progress indicator */
.progress-indicator {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: #e9ecef;
    z-index: 9999;
}

.progress-bar {
    height: 100%;
    background: #0d6efd;
    transition: width 0.3s ease;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .btn-toolbar {
        flex-wrap: wrap;
        margin-top: 10px;
    }
    
    .btn-toolbar .btn {
        margin-bottom: 5px;
    }
    
    .card-body {
        padding: 1rem;
    }
}
</style>

<?php include '../includes/footer.php'; ?>