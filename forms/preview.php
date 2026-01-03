<?php
/**
 * preview.php
 * Preview form layout and content
 */

require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';

$auth = new Auth();
$auth->requireLogin(); // Anyone logged in can preview

$formManager = new FormManager();

// Get form ID
$formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
if (!$formId) {
    header('Location: manage.php');
    exit();
}

// Get form details with fields
$form = $formManager->getFormWithFields($formId);
if (!$form) {
    header('Location: manage.php');
    exit();
}

$pageTitle = "Preview Form - " . htmlspecialchars($form['form_name']) . " - " . SITE_NAME;

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
                            <i class="bi bi-eye"></i> Form Preview
                        </h1>
                        <p class="text-muted mb-0">
                            Preview how the form will appear to users
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="builder.php?form_id=<?php echo $formId; ?>" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left"></i> Back to Builder
                        </a>
                        <a href="manage.php" class="btn btn-outline-primary">
                            <i class="bi bi-list"></i> Manage Forms
                        </a>
                    </div>
                </div>
                
                <!-- Form Info Card -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h3 class="card-title mb-2">
                                    <?php echo htmlspecialchars($form['form_name']); ?>
                                    <span class="badge <?php echo $form['is_active'] ? 'bg-success' : 'bg-warning'; ?>">
                                        <?php echo $form['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </h3>
                                <?php if ($form['form_description']): ?>
                                <p class="card-text"><?php echo htmlspecialchars($form['form_description']); ?></p>
                                <?php endif; ?>
                                <div class="d-flex align-items-center mt-3">
                                    <span class="badge bg-secondary me-2">
                                        Code: <?php echo htmlspecialchars($form['form_code']); ?>
                                    </span>
                                    <span class="badge 
                                        <?php echo $form['target_entity'] === 'family' ? 'bg-info' : 
                                               ($form['target_entity'] === 'member' ? 'bg-warning' : 'bg-primary'); ?>">
                                        Type: <?php echo ucfirst($form['target_entity']); ?>
                                    </span>
                                    <?php if ($form['form_category']): ?>
                                    <span class="badge bg-light text-dark border ms-2">
                                        <?php echo htmlspecialchars($form['form_category']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <small class="text-muted d-block">Created By:</small>
                                <strong><?php echo htmlspecialchars($form['created_by_name'] ?? 'System'); ?></strong>
                                <small class="text-muted d-block">
                                    On <?php echo date('F j, Y', strtotime($form['created_at'])); ?>
                                </small>
                                
                                <?php if ($form['start_date'] || $form['end_date']): ?>
                                <div class="mt-3">
                                    <small class="text-muted d-block">Availability:</small>
                                    <?php if ($form['start_date']): ?>
                                    <small class="text-muted">From: <?php echo date('M d, Y', strtotime($form['start_date'])); ?></small>
                                    <?php endif; ?>
                                    <?php if ($form['end_date']): ?>
                                    <br>
                                    <small class="text-muted">To: <?php echo date('M d, Y', strtotime($form['end_date'])); ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Preview -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-card-text"></i> Form Preview
                            <small class="text-white-50">(This is how users will see the form)</small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Demo Header -->
                        <div class="demo-header mb-4 p-3 bg-light rounded">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Family/Member Information</label>
                                        <div class="input-group">
                                            <select class="form-control">
                                                <option value="">Select Family ID</option>
                                                <option value="FAM-001">FAM-001: Perera Family</option>
                                                <option value="FAM-002">FAM-002: Silva Family</option>
                                            </select>
                                            <?php if ($form['target_entity'] === 'member' || $form['target_entity'] === 'both'): ?>
                                            <select class="form-control ms-2">
                                                <option value="">Select Member</option>
                                                <option value="001">John Perera</option>
                                                <option value="002">Mary Perera</option>
                                            </select>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">GN Division</label>
                                        <input type="text" class="form-control" value="GN-001 - Colombo" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Fields -->
                        <form id="formPreview" class="needs-validation" novalidate>
                            <?php if (empty($form['fields'])): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-input-cursor-text fs-1 text-muted"></i>
                                <h5 class="text-muted mt-3">No fields added yet</h5>
                                <p class="text-muted">Add fields using the form builder to see preview here.</p>
                            </div>
                            <?php else: ?>
                            <?php 
                            // Sort fields by order
                            usort($form['fields'], function($a, $b) {
                                return $a['field_order'] <=> $b['field_order'];
                            });
                            
                            $fieldCount = 0;
                            foreach ($form['fields'] as $field): 
                                $fieldCount++;
                                $fieldId = 'field_' . $field['field_id'];
                                $required = $field['is_required'] ? ' <span class="text-danger">*</span>' : '';
                            ?>
                            <div class="mb-4 field-preview" data-field-id="<?php echo $field['field_id']; ?>">
                                <label for="<?php echo $fieldId; ?>" class="form-label">
                                    <?php echo htmlspecialchars($field['field_label']); ?><?php echo $required; ?>
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
                                               name="<?php echo $field['field_code']; ?>"
                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                        <?php break; ?>
                                        
                                    <?php case 'textarea': ?>
                                        <textarea class="form-control" 
                                                  id="<?php echo $fieldId; ?>"
                                                  name="<?php echo $field['field_code']; ?>"
                                                  rows="<?php echo substr_count($field['default_value'] ?? '', "\n") + 3; ?>"
                                                  placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                  <?php echo $field['is_required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($field['default_value'] ?? ''); ?></textarea>
                                        <?php break; ?>
                                        
                                    <?php case 'number': ?>
                                        <input type="number" 
                                               class="form-control" 
                                               id="<?php echo $fieldId; ?>"
                                               name="<?php echo $field['field_code']; ?>"
                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                        <?php break; ?>
                                        
                                    <?php case 'date': ?>
                                        <input type="date" 
                                               class="form-control" 
                                               id="<?php echo $fieldId; ?>"
                                               name="<?php echo $field['field_code']; ?>"
                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                        <?php break; ?>
                                        
                                    <?php case 'email': ?>
                                        <input type="email" 
                                               class="form-control" 
                                               id="<?php echo $fieldId; ?>"
                                               name="<?php echo $field['field_code']; ?>"
                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                        <?php break; ?>
                                        
                                    <?php case 'phone': ?>
                                        <input type="tel" 
                                               class="form-control" 
                                               id="<?php echo $fieldId; ?>"
                                               name="<?php echo $field['field_code']; ?>"
                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                               pattern="[0-9]{10}"
                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
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
                                                name="<?php echo $field['field_code']; ?>"
                                                <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                            <option value="">-- Select --</option>
                                            <?php if (is_array($options)): ?>
                                            <?php foreach ($options as $option): ?>
                                            <option value="<?php echo htmlspecialchars($option); ?>"
                                                    <?php echo ($field['default_value'] ?? '') === $option ? 'selected' : ''; ?>>
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
                                                       name="<?php echo $field['field_code']; ?>"
                                                       id="<?php echo $fieldId . '_' . $index; ?>"
                                                       value="<?php echo htmlspecialchars($option); ?>"
                                                       <?php echo ($field['default_value'] ?? '') === $option ? 'checked' : ''; ?>
                                                       <?php echo $field['is_required'] ? 'required' : ''; ?>>
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
                                            ?>
                                            <?php foreach ($options as $index => $option): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" 
                                                       type="checkbox" 
                                                       name="<?php echo $field['field_code']; ?>[]"
                                                       id="<?php echo $fieldId . '_' . $index; ?>"
                                                       value="<?php echo htmlspecialchars($option); ?>"
                                                       <?php echo in_array($option, $defaultValues) ? 'checked' : ''; ?>>
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
                                                       name="<?php echo $field['field_code']; ?>"
                                                       id="<?php echo $fieldId; ?>_yes"
                                                       value="yes"
                                                       <?php echo ($field['default_value'] ?? '') === 'yes' ? 'checked' : ''; ?>
                                                       <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                <label class="form-check-label" for="<?php echo $fieldId; ?>_yes">
                                                    Yes
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" 
                                                       type="radio" 
                                                       name="<?php echo $field['field_code']; ?>"
                                                       id="<?php echo $fieldId; ?>_no"
                                                       value="no"
                                                       <?php echo ($field['default_value'] ?? '') === 'no' ? 'checked' : ''; ?>
                                                       <?php echo $field['is_required'] ? 'required' : ''; ?>>
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
                                               name="<?php echo $field['field_code']; ?>"
                                               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                        <small class="text-muted">
                                            Accepted: PDF, DOC, JPG, PNG (Max: 2MB)
                                        </small>
                                        <?php break; ?>
                                        
                                    <?php case 'rating': ?>
                                        <div class="rating-group">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" 
                                                       type="radio" 
                                                       name="<?php echo $field['field_code']; ?>"
                                                       id="<?php echo $fieldId . '_' . $i; ?>"
                                                       value="<?php echo $i; ?>"
                                                       <?php echo ($field['default_value'] ?? '') == $i ? 'checked' : ''; ?>
                                                       <?php echo $field['is_required'] ? 'required' : ''; ?>>
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
                                               name="<?php echo $field['field_code']; ?>"
                                               placeholder="Field type not supported"
                                               readonly>
                                <?php endswitch; ?>
                                
                                <div class="field-info mt-1">
                                    <small class="text-muted">
                                        <i class="bi bi-tag"></i> Field Code: <?php echo htmlspecialchars($field['field_code']); ?> |
                                        <i class="bi bi-list-ol"></i> Order: <?php echo $field['field_order']; ?>
                                    </small>
                                </div>
                            </div>
                            
                            <?php if ($fieldCount % 3 === 0): ?>
                            <hr class="my-4">
                            <?php endif; ?>
                            
                            <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <!-- Form Actions -->
                            <div class="mt-5 pt-4 border-top">
                                <div class="d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="resetPreviewForm()">
                                        <i class="bi bi-arrow-clockwise"></i> Reset Form
                                    </button>
                                    <div>
                                        <button type="button" class="btn btn-outline-info me-2" onclick="saveAsDraft()">
                                            <i class="bi bi-save"></i> Save as Draft
                                        </button>
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-check-circle"></i> Submit Form
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Form Statistics -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-info-circle"></i> Form Information
                                </h6>
                            </div>
                            <div class="card-body">
                                <dl class="row mb-0">
                                    <dt class="col-sm-5">Form ID:</dt>
                                    <dd class="col-sm-7"><?php echo $form['form_id']; ?></dd>
                                    
                                    <dt class="col-sm-5">Total Fields:</dt>
                                    <dd class="col-sm-7"><?php echo count($form['fields'] ?? []); ?></dd>
                                    
                                    <dt class="col-sm-5">Required Fields:</dt>
                                    <dd class="col-sm-7">
                                        <?php 
                                        $requiredCount = 0;
                                        if (!empty($form['fields'])) {
                                            foreach ($form['fields'] as $field) {
                                                if ($field['is_required']) $requiredCount++;
                                            }
                                        }
                                        echo $requiredCount;
                                        ?>
                                    </dd>
                                    
                                    <dt class="col-sm-5">Created:</dt>
                                    <dd class="col-sm-7"><?php echo date('M d, Y H:i', strtotime($form['created_at'])); ?></dd>
                                    
                                    <dt class="col-sm-5">Last Updated:</dt>
                                    <dd class="col-sm-7">
                                        <?php 
                                        if ($form['updated_at']) {
                                            echo date('M d, Y H:i', strtotime($form['updated_at']));
                                        } else {
                                            echo 'Never';
                                        }
                                        ?>
                                    </dd>
                                    
                                    <dt class="col-sm-5">Max Submissions:</dt>
                                    <dd class="col-sm-7">
                                        <?php echo $form['max_submissions_per_entity'] == 0 ? 'Unlimited' : $form['max_submissions_per_entity']; ?>
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-bar-chart"></i> Field Types Summary
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php
                                $fieldTypes = [];
                                if (!empty($form['fields'])) {
                                    foreach ($form['fields'] as $field) {
                                        $type = $field['field_type'];
                                        $fieldTypes[$type] = ($fieldTypes[$type] ?? 0) + 1;
                                    }
                                }
                                ?>
                                <?php if (!empty($fieldTypes)): ?>
                                <?php foreach ($fieldTypes as $type => $count): ?>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="text-muted"><?php echo strtoupper($type); ?></small>
                                        <small class="text-muted"><?php echo $count; ?></small>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <?php 
                                        $percentage = ($count / count($form['fields'])) * 100;
                                        ?>
                                        <div class="progress-bar bg-info" 
                                             role="progressbar" 
                                             style="width: <?php echo $percentage; ?>%">
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <div class="text-center py-3">
                                    <small class="text-muted">No fields added yet</small>
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

<!-- Print Modal -->
<div class="modal fade" id="printModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Print Form</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Print Options</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="printWithData" checked>
                        <label class="form-check-label" for="printWithData">
                            Include sample data
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="printInstructions" checked>
                        <label class="form-check-label" for="printInstructions">
                            Include instructions
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="printFieldCodes">
                        <label class="form-check-label" for="printFieldCodes">
                            Include field codes
                        </label>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Paper Size</label>
                    <select class="form-control" id="paperSize">
                        <option value="A4">A4</option>
                        <option value="Letter">Letter</option>
                        <option value="Legal">Legal</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="printForm()">
                    <i class="bi bi-printer"></i> Print Form
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Form validation
(function() {
    'use strict';
    
    const form = document.getElementById('formPreview');
    
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
        } else {
            event.preventDefault();
            alert('Form submitted successfully! (This is a preview only)');
            resetPreviewForm();
        }
        
        form.classList.add('was-validated');
    }, false);
})();

// Reset form
function resetPreviewForm() {
    const form = document.getElementById('formPreview');
    form.reset();
    form.classList.remove('was-validated');
    
    // Reset to default values
    const fields = document.querySelectorAll('.field-preview');
    fields.forEach(field => {
        const fieldId = field.dataset.fieldId;
        // In real implementation, you would reset to default values
    });
    
    showNotification('Form has been reset', 'info');
}

// Save as draft
function saveAsDraft() {
    // Collect form data
    const formData = new FormData(document.getElementById('formPreview'));
    const data = {};
    
    for (let [key, value] of formData.entries()) {
        if (data[key]) {
            if (!Array.isArray(data[key])) {
                data[key] = [data[key]];
            }
            data[key].push(value);
        } else {
            data[key] = value;
        }
    }
    
    console.log('Draft saved:', data);
    showNotification('Form saved as draft successfully!', 'success');
}

// Show notification
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    notification.innerHTML = `
        <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-info-circle'}"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 5000);
}

// Print form
function printForm() {
    const printWindow = window.open('', '_blank');
    const formName = '<?php echo htmlspecialchars($form["form_name"]); ?>';
    const fields = <?php echo json_encode($form['fields'] ?? []); ?>;
    
    let printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>${formName} - Print</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .print-header { border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
                .print-title { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
                .print-info { font-size: 12px; color: #666; }
                .field-group { margin-bottom: 20px; page-break-inside: avoid; }
                .field-label { font-weight: bold; margin-bottom: 5px; }
                .field-hint { font-style: italic; color: #666; font-size: 12px; margin-bottom: 5px; }
                .field-input { border-bottom: 1px solid #000; min-height: 20px; }
                .required::after { content: " *"; color: red; }
                .page-break { page-break-after: always; }
                @media print {
                    body { margin: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="print-header">
                <div class="print-title">${formName}</div>
                <div class="print-info">
                    Form Code: <?php echo htmlspecialchars($form['form_code']); ?> | 
                    Type: <?php echo ucfirst($form['target_entity']); ?> |
                    Printed: ${new Date().toLocaleDateString()}
                </div>
            </div>
    `;
    
    // Sort fields by order
    fields.sort((a, b) => a.field_order - b.field_order);
    
    fields.forEach((field, index) => {
        const required = field.is_required ? 'required' : '';
        
        printContent += `
            <div class="field-group">
                <div class="field-label ${required}">${field.field_label}</div>
                ${field.hint_text ? `<div class="field-hint">${field.hint_text}</div>` : ''}
                <div class="field-input" style="min-height: 30px;"></div>
                <div class="print-info" style="font-size: 10px;">
                    Field Code: ${field.field_code} | Type: ${field.field_type}
                </div>
            </div>
        `;
        
        // Add page break after every 10 fields
        if ((index + 1) % 10 === 0) {
            printContent += '<div class="page-break"></div>';
        }
    });
    
    printContent += `
            <div class="no-print" style="margin-top: 50px; text-align: center;">
                <button onclick="window.print()">Print</button>
                <button onclick="window.close()">Close</button>
            </div>
        </body>
        </html>
    `;
    
    printWindow.document.write(printContent);
    printWindow.document.close();
    $('#printModal').modal('hide');
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    // Enable Bootstrap tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Add print button to header
    const header = document.querySelector('.d-flex.justify-content-between.flex-wrap.flex-md-nowrap.align-items-center');
    if (header) {
        const printBtn = document.createElement('button');
        printBtn.className = 'btn btn-outline-secondary ms-2';
        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
        printBtn.onclick = () => $('#printModal').modal('show');
        header.querySelector('.btn-toolbar').appendChild(printBtn);
    }
});
</script>

<style>
.field-preview {
    padding: 15px;
    border-radius: 8px;
    background-color: #f8f9fa;
    transition: all 0.3s ease;
}

.field-preview:hover {
    background-color: #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.radio-group .form-check,
.checkbox-group .form-check {
    margin-bottom: 8px;
}

.yesno-group,
.rating-group {
    padding: 10px;
    background-color: white;
    border-radius: 6px;
}

.demo-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.demo-header label {
    color: white;
}

.form-label {
    font-weight: 500;
    color: #495057;
}

.form-text {
    color: #6c757d;
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
</style>

<?php include '../includes/footer.php'; ?>