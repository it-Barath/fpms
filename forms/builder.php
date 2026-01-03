<?php
require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';

$auth = new Auth();
$auth->requireRole(['moha', 'district', 'division']); // MOHA and higher levels can access

$formManager = new FormManager();

// Get form ID
$formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
if (!$formId) {
    header('Location: manage.php');
    exit();
}

// Get form details
$form = $formManager->getFormWithFields($formId);
if (!$form) {
    header('Location: manage.php');
    exit();
}

// Check permissions - only creator or MOHA can edit
$currentUser = $_SESSION['user_id'];
if ($form['created_by_user_id'] != $currentUser && $_SESSION['user_type'] != 'moha') {
    header('Location: manage.php');
    exit();
}

$pageTitle = "Form Builder - " . htmlspecialchars($form['form_name']) . " - " . SITE_NAME;

// Handle field addition
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_field'])) {
        // Clean and prepare field data
        $fieldData = [
            'field_code' => trim($_POST['field_code']),
            'field_label' => trim($_POST['field_label']),
            'field_type' => $_POST['field_type'],
            'field_options' => isset($_POST['field_options']) ? 
                array_map('trim', array_filter(explode("\n", trim($_POST['field_options'])))) : [],
            'is_required' => isset($_POST['is_required']) ? 1 : 0,
            'field_order' => intval($_POST['field_order']),
            'default_value' => trim($_POST['default_value'] ?? ''),
            'placeholder' => trim($_POST['placeholder'] ?? ''),
            'hint_text' => trim($_POST['hint_text'] ?? ''),
            'validation_rules' => trim($_POST['validation_rules'] ?? '')
        ];
        
        // Validate field code
        if (!preg_match('/^[a-z0-9_]+$/', $fieldData['field_code'])) {
            $error = "Field code must contain only lowercase letters, numbers, and underscores";
        } elseif (strlen($fieldData['field_code']) > 100) {
            $error = "Field code must be less than 100 characters";
        } else {
            $result = $formManager->addFormField($formId, $fieldData);
            
            if ($result['success']) {
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'message' => 'Field added successfully!'
                ];
                header("Location: builder.php?form_id=" . $formId);
                exit();
            } else {
                $error = $result['error'];
            }
        }
    }
    
    // Handle field deletion
    if (isset($_POST['delete_field'])) {
        $fieldId = intval($_POST['field_id']);
        $result = $formManager->deleteFormField($fieldId);
        
        if ($result['success']) {
            $_SESSION['flash_message'] = [
                'type' => 'success',
                'message' => 'Field deleted successfully!'
            ];
            header("Location: builder.php?form_id=" . $formId);
            exit();
        } else {
            $error = $result['error'];
        }
    }
    
    // Handle field reordering
    if (isset($_POST['reorder_fields'])) {
        $fieldOrders = json_decode($_POST['field_orders'], true);
        if (is_array($fieldOrders)) {
            $result = $formManager->reorderFormFields($fieldOrders);
            if ($result['success']) {
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'message' => 'Field order updated!'
                ];
                echo json_encode(['success' => true]);
                exit();
            }
        }
        echo json_encode(['success' => false]);
        exit();
    }
}

// Function to generate field code from label
function generateFieldCode($label, $existingCodes = [], $maxLength = 50) {
    // Convert to lowercase
    $code = strtolower(trim($label));
    
    // Replace spaces and special characters with underscores
    $code = preg_replace('/[^a-z0-9]+/', '_', $code);
    
    // Remove leading/trailing underscores
    $code = trim($code, '_');
    
    // Remove consecutive underscores
    $code = preg_replace('/_+/', '_', $code);
    
    // Truncate if too long
    if (strlen($code) > $maxLength) {
        $code = substr($code, 0, $maxLength);
        $code = rtrim($code, '_');
    }
    
    // If code is empty after processing, use a default
    if (empty($code)) {
        $code = 'field_' . time();
    }
    
    // Check if code already exists
    $originalCode = $code;
    $counter = 1;
    
    while (in_array($code, $existingCodes)) {
        $suffix = '_' . $counter;
        $truncatedCode = substr($originalCode, 0, $maxLength - strlen($suffix));
        $code = rtrim($truncatedCode, '_') . $suffix;
        $counter++;
        
        // Prevent infinite loop
        if ($counter > 100) {
            $code = $originalCode . '_' . uniqid();
            break;
        }
    }
    
    return $code;
}

// Get existing field codes for this form
$existingFieldCodes = [];
if (!empty($form['fields'])) {
    foreach ($form['fields'] as $field) {
        $existingFieldCodes[] = $field['field_code'];
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
                            <i class="bi bi-tools"></i> Form Builder
                            <small class="text-muted fs-6"><?php echo htmlspecialchars($form['form_name']); ?></small>
                        </h1>
                        <p class="text-muted mb-0">
                            Form Code: <span class="badge bg-info"><?php echo htmlspecialchars($form['form_code']); ?></span> | 
                            Type: <span class="badge bg-primary"><?php echo htmlspecialchars($form['target_entity']); ?></span> | 
                            Fields: <span class="badge bg-secondary"><?php echo count($form['fields'] ?? []); ?></span> |
                            Status: <span class="badge <?php echo $form['is_active'] ? 'bg-success' : 'bg-warning'; ?>">
                                <?php echo $form['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="manage.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left"></i> Back to Forms
                        </a>
                        <a href="preview.php?form_id=<?php echo $formId; ?>" class="btn btn-outline-info me-2" target="_blank">
                            <i class="bi bi-eye"></i> Preview
                        </a>
                        <?php if ($_SESSION['user_type'] == 'moha' || $form['created_by_user_id'] == $_SESSION['user_id']): ?>
                        <div class="btn-group">
                            <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i> Actions
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="edit.php?form_id=<?php echo $formId; ?>">
                                        <i class="bi bi-pencil"></i> Edit Form Details
                                    </a>
                                </li>
                                <li>
                                    <button class="dropdown-item" onclick="toggleFormStatus(<?php echo $formId; ?>, <?php echo $form['is_active'] ? 0 : 1; ?>)">
                                        <i class="bi bi-power"></i> 
                                        <?php echo $form['is_active'] ? 'Deactivate' : 'Activate'; ?> Form
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button class="dropdown-item text-success" data-bs-toggle="modal" data-bs-target="#assignModal">
                                        <i class="bi bi-person-plus"></i> Assign Form
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item text-warning" data-bs-toggle="modal" data-bs-target="#exportModal">
                                        <i class="bi bi-download"></i> Export Form Data
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php displayFlashMessage(); ?>
                
                <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <!-- Form Fields List -->
                    <div class="col-md-8">
                        <div class="card mb-4">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-list-check"></i> Form Fields
                                    <span class="badge bg-secondary"><?php echo count($form['fields'] ?? []); ?></span>
                                </h5>
                                <button class="btn btn-sm btn-outline-primary" onclick="sortFields()">
                                    <i class="bi bi-sort-down"></i> Sort Fields
                                </button>
                            </div>
                            <div class="card-body">
                                <?php if (empty($form['fields'])): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-input-cursor-text fs-1 text-muted"></i>
                                    <h5 class="text-muted mt-3">No fields added yet</h5>
                                    <p class="text-muted">Start by adding your first form field using the form on the right.</p>
                                </div>
                                <?php else: ?>
                                <div id="sortableFields" class="fields-list">
                                    <?php foreach ($form['fields'] as $index => $field): ?>
                                    <div class="card mb-3 field-item" data-field-id="<?php echo $field['field_id']; ?>">
                                        <div class="card-body py-3">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <span class="drag-handle me-2" style="cursor: move;">
                                                            <i class="bi bi-grip-vertical text-muted"></i>
                                                        </span>
                                                        <span class="badge bg-secondary me-2 field-type-badge">
                                                            <?php echo strtoupper(htmlspecialchars($field['field_type'])); ?>
                                                        </span>
                                                        <strong class="me-2"><?php echo htmlspecialchars($field['field_label']); ?></strong>
                                                        <?php if ($field['is_required']): ?>
                                                        <span class="badge bg-danger me-2">Required</span>
                                                        <?php endif; ?>
                                                        <small class="text-muted">
                                                            <i class="bi bi-hash"></i> <?php echo htmlspecialchars($field['field_code']); ?>
                                                        </small>
                                                        <span class="badge bg-light text-dark border ms-2">
                                                            Order: <?php echo $field['field_order']; ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <?php if ($field['hint_text']): ?>
                                                    <div class="mb-2">
                                                        <small class="text-muted">
                                                            <i class="bi bi-info-circle"></i> 
                                                            <?php echo htmlspecialchars($field['hint_text']); ?>
                                                        </small>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($field['field_type'] === 'dropdown' || $field['field_type'] === 'radio' || $field['field_type'] === 'checkbox'): ?>
                                                    <div class="mb-2">
                                                        <small class="text-muted d-block mb-1">
                                                            <i class="bi bi-list-ul"></i> Options:
                                                        </small>
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
                                                        <?php if (is_array($options) && !empty($options)): ?>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <?php foreach ($options as $option): ?>
                                                            <span class="badge bg-light text-dark border">
                                                                <?php echo htmlspecialchars($option); ?>
                                                            </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <?php else: ?>
                                                        <span class="badge bg-warning">No options defined</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($field['default_value'] || $field['placeholder']): ?>
                                                    <div class="row">
                                                        <?php if ($field['default_value']): ?>
                                                        <div class="col-md-6">
                                                            <small class="text-muted d-block mb-1">
                                                                <i class="bi bi-check-circle"></i> Default:
                                                            </small>
                                                            <code class="bg-light p-1 rounded"><?php echo htmlspecialchars($field['default_value']); ?></code>
                                                        </div>
                                                        <?php endif; ?>
                                                        <?php if ($field['placeholder']): ?>
                                                        <div class="col-md-6">
                                                            <small class="text-muted d-block mb-1">
                                                                <i class="bi bi-textarea-t"></i> Placeholder:
                                                            </small>
                                                            <code class="bg-light p-1 rounded"><?php echo htmlspecialchars($field['placeholder']); ?></code>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary edit-field-btn" 
                                                            data-field-id="<?php echo $field['field_id']; ?>"
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editFieldModal">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="POST" class="d-inline" 
                                                          onsubmit="return confirm('Are you sure you want to delete this field? This action cannot be undone.');">
                                                        <input type="hidden" name="field_id" value="<?php echo $field['field_id']; ?>">
                                                        <button type="submit" name="delete_field" class="btn btn-outline-danger">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($form['fields'])): ?>
                            <div class="card-footer">
                                <button class="btn btn-success" onclick="saveFieldOrder()">
                                    <i class="bi bi-check-circle"></i> Save Field Order
                                </button>
                                <span class="text-muted ms-2">
                                    Drag and drop fields to reorder, then click save
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Form Statistics -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title text-muted mb-3">
                                            <i class="bi bi-bar-chart"></i> Field Type Distribution
                                        </h6>
                                        <div id="fieldTypeChart" style="height: 200px;">
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
                                                <small class="text-muted">No field data available</small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title text-muted mb-3">
                                            <i class="bi bi-clipboard-check"></i> Form Summary
                                        </h6>
                                        <div class="row">
                                            <div class="col-6 mb-3">
                                                <small class="text-muted d-block">Total Fields</small>
                                                <h4 class="mb-0"><?php echo count($form['fields'] ?? []); ?></h4>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <small class="text-muted d-block">Required Fields</small>
                                                <h4 class="mb-0">
                                                    <?php 
                                                    $required = 0;
                                                    if (!empty($form['fields'])) {
                                                        foreach ($form['fields'] as $field) {
                                                            if ($field['is_required']) $required++;
                                                        }
                                                    }
                                                    echo $required;
                                                    ?>
                                                </h4>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <small class="text-muted d-block">Created On</small>
                                                <small class="d-block">
                                                    <?php echo date('M d, Y', strtotime($form['created_at'])); ?>
                                                </small>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <small class="text-muted d-block">Last Updated</small>
                                                <small class="d-block">
                                                    <?php 
                                                    if ($form['updated_at']) {
                                                        echo date('M d, Y', strtotime($form['updated_at']));
                                                    } else {
                                                        echo 'Never';
                                                    }
                                                    ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Add Field Sidebar -->
                    <div class="col-md-4">
                        <div class="card sticky-top" style="top: 20px; z-index: 1000;">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-plus-circle"></i> Add New Field
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" id="addFieldForm">
                                    <div class="mb-3">
                                        <label for="field_code" class="form-label">
                                            Field Code <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="field_code" 
                                                   name="field_code" required 
                                                   pattern="[a-z0-9_]+" 
                                                   title="Lowercase letters, numbers and underscores only"
                                                   maxlength="100"
                                                   placeholder="e.g., family_address">
                                            <button class="btn btn-outline-secondary" type="button" id="generateCodeBtn">
                                                <i class="bi bi-magic"></i> Auto
                                            </button>
                                        </div>
                                        <small class="text-muted">Unique identifier (lowercase letters, numbers, underscores)</small>
                                        <div id="fieldCodeFeedback" class="invalid-feedback"></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="field_label" class="form-label">
                                            Field Label <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="field_label" 
                                               name="field_label" required maxlength="500"
                                               placeholder="e.g., Family Address">
                                        <small class="text-muted">Display label for the field</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="field_type" class="form-label">
                                            Field Type <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-control" id="field_type" 
                                                name="field_type" required>
                                            <option value="">Select Type</option>
                                            <option value="text">Text Field</option>
                                            <option value="textarea">Text Area</option>
                                            <option value="number">Number</option>
                                            <option value="date">Date</option>
                                            <option value="radio">Radio Buttons</option>
                                            <option value="checkbox">Checkboxes</option>
                                            <option value="dropdown">Dropdown</option>
                                            <option value="email">Email</option>
                                            <option value="phone">Phone Number</option>
                                            <option value="yesno">Yes/No</option>
                                            <option value="file">File Upload</option>
                                            <option value="rating">Rating</option>
                                        </select>
                                        <small class="text-muted">Select the type of input field</small>
                                    </div>
                                    
                                    <!-- Options for radio/checkbox/dropdown -->
                                    <div class="mb-3" id="optionsContainer" style="display: none;">
                                        <label for="field_options" class="form-label">Options (one per line)</label>
                                        <textarea class="form-control" id="field_options" 
                                                  name="field_options" rows="3"
                                                  placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                                        <small class="text-muted">Enter one option per line</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="field_order" class="form-label">
                                            Field Order <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" class="form-control" id="field_order" 
                                               name="field_order" required min="0" 
                                               value="<?php echo count($form['fields'] ?? []); ?>">
                                        <small class="text-muted">Order in the form (lower numbers appear first)</small>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" 
                                                       id="is_required" name="is_required" value="1">
                                                <label class="form-check-label" for="is_required">
                                                    Required Field
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" 
                                                       id="show_in_preview" name="show_in_preview" value="1" checked>
                                                <label class="form-check-label" for="show_in_preview">
                                                    Show in Preview
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="default_value" class="form-label">Default Value</label>
                                        <input type="text" class="form-control" id="default_value" 
                                               name="default_value" maxlength="500"
                                               placeholder="Pre-filled value">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="placeholder" class="form-label">Placeholder Text</label>
                                        <input type="text" class="form-control" id="placeholder" 
                                               name="placeholder" maxlength="500"
                                               placeholder="Hint inside the field">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="hint_text" class="form-label">Hint/Help Text</label>
                                        <textarea class="form-control" id="hint_text" 
                                                  name="hint_text" rows="2"
                                                  placeholder="Additional instructions for users"></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="validation_rules" class="form-label">Validation Rules</label>
                                        <input type="text" class="form-control" id="validation_rules" 
                                               name="validation_rules" 
                                               placeholder="e.g., min:5,max:100,pattern:[A-Za-z]+">
                                        <small class="text-muted">Comma-separated validation rules</small>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="add_field" class="btn btn-primary">
                                            <i class="bi bi-plus-circle"></i> Add Field to Form
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" 
                                                onclick="resetFieldForm()">
                                            <i class="bi bi-arrow-clockwise"></i> Reset Form
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <div class="card-footer bg-light">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle"></i> 
                                    Fields will be saved immediately when added. Use drag-and-drop to reorder.
                                </small>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="card mt-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="bi bi-lightning"></i> Quick Actions
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button class="btn btn-outline-info" onclick="window.location.href='preview.php?form_id=<?php echo $formId; ?>'">
                                        <i class="bi bi-eye"></i> Preview Form
                                    </button>
                                    <button class="btn btn-outline-warning" onclick="duplicateForm()">
                                        <i class="bi bi-copy"></i> Duplicate Form
                                    </button>
                                    <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#testModal">
                                        <i class="bi bi-play-circle"></i> Test Form
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<!-- Edit Field Modal -->
<div class="modal fade" id="editFieldModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Field</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editFieldForm">
                    <input type="hidden" id="edit_field_id" name="field_id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_field_label" class="form-label">Field Label *</label>
                                <input type="text" class="form-control" id="edit_field_label" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_field_type" class="form-label">Field Type</label>
                                <input type="text" class="form-control" id="edit_field_type" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_field_order" class="form-label">Field Order *</label>
                                <input type="number" class="form-control" id="edit_field_order" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3 pt-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_is_required">
                                    <label class="form-check-label" for="edit_is_required">
                                        Required Field
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_hint_text" class="form-label">Hint/Help Text</label>
                        <textarea class="form-control" id="edit_hint_text" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_default_value" class="form-label">Default Value</label>
                        <input type="text" class="form-control" id="edit_default_value">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveFieldChanges">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Test Form Modal -->
<div class="modal fade" id="testModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Test Form Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> This is a preview of how the form will look to users.
                </div>
                <div class="preview-container p-3 border rounded">
                    <!-- Form preview will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="testFormSubmission()">
                    <i class="bi bi-send"></i> Test Submit
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Form Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Form</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="assignForm">
                    <input type="hidden" name="form_id" value="<?php echo $formId; ?>">
                    <div class="mb-3">
                        <label for="assign_to" class="form-label">Assign To</label>
                        <select class="form-control" id="assign_to" name="assign_to" required>
                            <option value="">Select User/Office</option>
                            <option value="all_gn">All GN Officers</option>
                            <option value="all_division">All Division Officers</option>
                            <option value="all_district">All District Officers</option>
                            <option value="specific">Specific Officer</option>
                        </select>
                    </div>
                    <div class="mb-3" id="specificOfficerContainer" style="display: none;">
                        <label for="specific_officer" class="form-label">Select Officer</label>
                        <input type="text" class="form-control" id="specific_officer" 
                               placeholder="Search for officer...">
                    </div>
                    <div class="mb-3">
                        <label for="expires_at" class="form-label">Expiry Date</label>
                        <input type="datetime-local" class="form-control" id="expires_at" name="expires_at">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-success" onclick="assignForm()">
                    <i class="bi bi-person-plus"></i> Assign
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Export Form Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Export Format</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="export_format" id="format_csv" value="csv" checked>
                        <label class="form-check-label" for="format_csv">
                            CSV (Excel)
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="export_format" id="format_json" value="json">
                        <label class="form-check-label" for="format_json">
                            JSON
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="export_format" id="format_pdf" value="pdf">
                        <label class="form-check-label" for="format_pdf">
                            PDF Report
                        </label>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Date Range</label>
                    <div class="row">
                        <div class="col-md-6">
                            <input type="date" class="form-control" id="export_from" name="export_from">
                        </div>
                        <div class="col-md-6">
                            <input type="date" class="form-control" id="export_to" name="export_to">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="exportFormData()">
                    <i class="bi bi-download"></i> Export
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize SortableJS for drag and drop
let sortable;

// Show/hide options field based on field type
document.getElementById('field_type').addEventListener('change', function() {
    const optionsContainer = document.getElementById('optionsContainer');
    const fieldType = this.value;
    
    if (fieldType === 'radio' || fieldType === 'checkbox' || fieldType === 'dropdown') {
        optionsContainer.style.display = 'block';
    } else {
        optionsContainer.style.display = 'none';
    }
});

// Generate field code from label
function generateFieldCodeFromLabel(label) {
    // Convert to lowercase
    let code = label.toLowerCase();
    
    // Replace spaces and special characters with underscores
    code = code.replace(/[^a-z0-9]+/g, '_');
    
    // Remove leading/trailing underscores
    code = code.replace(/^_+|_+$/g, '');
    
    // Remove consecutive underscores
    code = code.replace(/_+/g, '_');
    
    // Truncate if too long
    if (code.length > 50) {
        code = code.substring(0, 50);
        code = code.replace(/_+$/, '');
    }
    
    return code;
}

// Auto-generate field code when label changes
document.getElementById('field_label').addEventListener('input', function() {
    const label = this.value;
    const fieldCodeInput = document.getElementById('field_code');
    
    if (label && !fieldCodeInput.value) {
        const generatedCode = generateFieldCodeFromLabel(label);
        fieldCodeInput.value = generatedCode;
        validateFieldCode();
    }
});

// Manual code generation button
document.getElementById('generateCodeBtn').addEventListener('click', function() {
    const label = document.getElementById('field_label').value;
    const fieldCodeInput = document.getElementById('field_code');
    
    if (label) {
        const generatedCode = generateFieldCodeFromLabel(label);
        fieldCodeInput.value = generatedCode;
        validateFieldCode();
    } else {
        showNotification('Please enter a field label first', 'warning');
    }
});

// Field code validation
function validateFieldCode() {
    const fieldCode = document.getElementById('field_code').value;
    const feedback = document.getElementById('fieldCodeFeedback');
    
    if (!fieldCode.match(/^[a-z0-9_]+$/)) {
        document.getElementById('field_code').classList.add('is-invalid');
        feedback.textContent = 'Only lowercase letters, numbers, and underscores allowed';
        return false;
    } else if (fieldCode.length > 100) {
        document.getElementById('field_code').classList.add('is-invalid');
        feedback.textContent = 'Field code must be less than 100 characters';
        return false;
    } else {
        document.getElementById('field_code').classList.remove('is-invalid');
        return true;
    }
}

document.getElementById('field_code').addEventListener('blur', function() {
    validateFieldCode();
    
    // Check if field code already exists
    const fieldCode = this.value;
    if (fieldCode && validateFieldCode()) {
        fetch('check_field_code.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `form_id=<?php echo $formId; ?>&field_code=${fieldCode}`
        })
        .then(response => response.json())
        .then(data => {
            const feedback = document.getElementById('fieldCodeFeedback');
            if (!data.available) {
                this.classList.add('is-invalid');
                feedback.textContent = 'Field code already exists in this form';
            } else {
                this.classList.remove('is-invalid');
            }
        });
    }
});

// Form submission validation
document.getElementById('addFieldForm').addEventListener('submit', function(e) {
    if (!validateFieldCode()) {
        e.preventDefault();
        showNotification('Please fix the field code errors', 'danger');
    }
});

// Reset field form
function resetFieldForm() {
    document.getElementById('addFieldForm').reset();
    document.getElementById('optionsContainer').style.display = 'none';
    document.getElementById('field_order').value = <?php echo count($form['fields'] ?? []); ?>;
}

// Initialize drag and drop
function initSortable() {
    const sortableFields = document.getElementById('sortableFields');
    if (sortableFields) {
        sortable = new Sortable(sortableFields, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            onEnd: function() {
                // Update order numbers visually
                updateFieldOrderDisplay();
            }
        });
    }
}

// Update field order display
function updateFieldOrderDisplay() {
    const fieldItems = document.querySelectorAll('.field-item');
    fieldItems.forEach((item, index) => {
        const orderBadge = item.querySelector('.badge.bg-light');
        if (orderBadge) {
            orderBadge.textContent = `Order: ${index}`;
        }
    });
}

// Save field order
function saveFieldOrder() {
    const fieldOrders = [];
    const fieldItems = document.querySelectorAll('.field-item');
    
    fieldItems.forEach((item, index) => {
        const fieldId = item.dataset.fieldId;
        fieldOrders.push({
            field_id: fieldId,
            field_order: index
        });
    });
    
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `reorder_fields=true&field_orders=${encodeURIComponent(JSON.stringify(fieldOrders))}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Field order saved successfully!', 'success');
            setTimeout(() => location.reload(), 1000);
        }
    });
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

// Edit field functionality
document.querySelectorAll('.edit-field-btn').forEach(button => {
    button.addEventListener('click', function() {
        const fieldId = this.dataset.fieldId;
        // Load field data via AJAX
        fetch(`get_field.php?field_id=${fieldId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_field_id').value = data.field.field_id;
                document.getElementById('edit_field_label').value = data.field.field_label;
                document.getElementById('edit_field_type').value = data.field.field_type;
                document.getElementById('edit_field_order').value = data.field.field_order;
                document.getElementById('edit_is_required').checked = data.field.is_required == 1;
                document.getElementById('edit_hint_text').value = data.field.hint_text || '';
                document.getElementById('edit_default_value').value = data.field.default_value || '';
            }
        });
    });
});

// Save field changes
document.getElementById('saveFieldChanges').addEventListener('click', function() {
    const fieldId = document.getElementById('edit_field_id').value;
    const fieldData = {
        field_label: document.getElementById('edit_field_label').value,
        field_order: document.getElementById('edit_field_order').value,
        is_required: document.getElementById('edit_is_required').checked ? 1 : 0,
        hint_text: document.getElementById('edit_hint_text').value,
        default_value: document.getElementById('edit_default_value').value
    };
    
    fetch('update_field.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            field_id: fieldId,
            ...fieldData
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Field updated successfully!', 'success');
            $('#editFieldModal').modal('hide');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('Error: ' + data.error, 'danger');
        }
    });
});

// Toggle form status
function toggleFormStatus(formId, newStatus) {
    if (confirm(`Are you sure you want to ${newStatus ? 'activate' : 'deactivate'} this form?`)) {
        fetch('toggle_form_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                form_id: formId,
                is_active: newStatus
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(`Form ${newStatus ? 'activated' : 'deactivated'} successfully!`, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showNotification('Error: ' + data.error, 'danger');
            }
        });
    }
}

// Duplicate form
function duplicateForm() {
    if (confirm('Duplicate this form?')) {
        fetch('duplicate_form.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                form_id: <?php echo $formId; ?>
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Form duplicated successfully!', 'success');
                setTimeout(() => {
                    window.location.href = `builder.php?form_id=${data.new_form_id}`;
                }, 1000);
            } else {
                showNotification('Error: ' + data.error, 'danger');
            }
        });
    }
}

// Test form submission
function testFormSubmission() {
    const formData = new FormData();
    // Collect all form data from preview
    const previewForm = document.querySelector('#testModal .preview-container form');
    if (previewForm) {
        const inputs = previewForm.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            if (input.name) {
                formData.append(input.name, input.value);
            }
        });
        
        fetch('test_submit.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Form test submitted successfully!', 'success');
            } else {
                showNotification('Test submission failed: ' + data.error, 'danger');
            }
        });
    }
}

// Initialize when document is ready
document.addEventListener('DOMContentLoaded', function() {
    initSortable();
    
    // Show assign form options
    document.getElementById('assign_to').addEventListener('change', function() {
        const specificContainer = document.getElementById('specificOfficerContainer');
        if (this.value === 'specific') {
            specificContainer.style.display = 'block';
        } else {
            specificContainer.style.display = 'none';
        }
    });
    
    // Load form preview in test modal
    $('#testModal').on('show.bs.modal', function() {
        const previewContainer = this.querySelector('.preview-container');
        previewContainer.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
        
        fetch(`preview_content.php?form_id=<?php echo $formId; ?>`)
        .then(response => response.text())
        .then(html => {
            previewContainer.innerHTML = html;
        });
    });
});

// Assign form to users
function assignForm() {
    const assignTo = document.getElementById('assign_to').value;
    const expiresAt = document.getElementById('expires_at').value;
    
    if (!assignTo) {
        showNotification('Please select assignment target', 'warning');
        return;
    }
    
    fetch('assign_form.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            form_id: <?php echo $formId; ?>,
            assign_to: assignTo,
            expires_at: expiresAt
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Form assigned successfully!', 'success');
            $('#assignModal').modal('hide');
        } else {
            showNotification('Error: ' + data.error, 'danger');
        }
    });
}

// Export form data
function exportFormData() {
    const format = document.querySelector('input[name="export_format"]:checked').value;
    const from = document.getElementById('export_from').value;
    const to = document.getElementById('export_to').value;
    
    let url = `export_form.php?form_id=<?php echo $formId; ?>&format=${format}`;
    if (from) url += `&from=${from}`;
    if (to) url += `&to=${to}`;
    
    window.open(url, '_blank');
    $('#exportModal').modal('hide');
}

// Enable sort fields mode
function sortFields() {
    const fieldItems = document.querySelectorAll('.field-item');
    fieldItems.forEach(item => {
        item.querySelector('.drag-handle').style.opacity = '1';
        item.querySelector('.drag-handle').style.cursor = 'move';
    });
    showNotification('Drag and drop fields to reorder them', 'info');
}
</script>

<style>
.field-item {
    transition: all 0.3s ease;
    border-left: 4px solid #007bff;
}

.field-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.drag-handle {
    opacity: 0.5;
    transition: opacity 0.3s ease;
}

.drag-handle:hover {
    opacity: 1;
}

.sortable-ghost {
    opacity: 0.4;
    background-color: #f8f9fa;
}

.sortable-chosen {
    background-color: #e9ecef;
}

.field-type-badge {
    min-width: 80px;
    text-align: center;
}

.fields-list {
    min-height: 200px;
}

.preview-container {
    background-color: #f8f9fa;
    border-radius: 8px;
}
</style>

<?php include '../includes/footer.php'; ?>