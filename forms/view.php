<?php
require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';

$auth = new Auth();
$auth->requireLogin();

$formManager = new FormManager();

$formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
if (!$formId) {
    header("Location: manage.php");
    exit();
}

$form = $formManager->getFormWithFields($formId);
if (!$form) {
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'Form not found'
    ];
    header("Location: manage.php");
    exit();
}

$pageTitle = "View Form: " . htmlspecialchars($form['form_name']) . " - " . SITE_NAME;

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <main class="ms-sm-auto px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-eye"></i> View Form
                        </h1>
                        <p class="text-muted mb-0">
                            <?php echo htmlspecialchars($form['form_name']); ?>
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="manage.php" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                        <a href="fill.php?form_id=<?php echo $formId; ?>" class="btn btn-primary me-2">
                            <i class="fas fa-edit"></i> Fill Form
                        </a>
                        <div class="btn-group">
                            <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fas fa-cog"></i> Actions
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="builder.php?form_id=<?php echo $formId; ?>">
                                    <i class="fas fa-tools"></i> Edit Form
                                </a></li>
                                <li><a class="dropdown-item" href="assign.php?form_id=<?php echo $formId; ?>">
                                    <i class="fas fa-users"></i> Assign Form
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="deleteForm(<?php echo $formId; ?>)">
                                    <i class="fas fa-trash"></i> Delete Form
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <?php displayFlashMessage(); ?>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-info-circle"></i> Form Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-borderless">
                                    <tr>
                                        <th width="40%">Form ID:</th>
                                        <td>#<?php echo $form['form_id']; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Form Code:</th>
                                        <td><code><?php echo htmlspecialchars($form['form_code']); ?></code></td>
                                    </tr>
                                    <tr>
                                        <th>Form Name:</th>
                                        <td><?php echo htmlspecialchars($form['form_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Description:</th>
                                        <td><?php echo nl2br(htmlspecialchars($form['form_description'] ?? 'No description')); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Category:</th>
                                        <td><?php echo htmlspecialchars($form['form_category'] ?? 'General'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Form Type:</th>
                                        <td>
                                            <span class="badge bg-info">
                                                <?php echo ucfirst(htmlspecialchars($form['form_type'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Target Entity:</th>
                                        <td>
                                            <span class="badge bg-<?php echo $form['target_entity'] == 'both' ? 'success' : 'primary'; ?>">
                                                <?php echo ucfirst(htmlspecialchars($form['target_entity'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Status:</th>
                                        <td>
                                            <?php
                                            $isActive = $form['is_active'];
                                            $startDate = $form['start_date'];
                                            $endDate = $form['end_date'];
                                            $currentDate = date('Y-m-d');
                                            
                                            if (!$isActive) {
                                                $status = 'Inactive';
                                                $color = 'secondary';
                                            } elseif ($startDate && $endDate) {
                                                if ($currentDate < $startDate) {
                                                    $status = 'Scheduled';
                                                    $color = 'info';
                                                } elseif ($currentDate >= $startDate && $currentDate <= $endDate) {
                                                    $status = 'Active';
                                                    $color = 'success';
                                                } else {
                                                    $status = 'Expired';
                                                    $color = 'danger';
                                                }
                                            } else {
                                                $status = 'Active';
                                                $color = 'success';
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $color; ?>">
                                                <?php echo $status; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Max Submissions:</th>
                                        <td>
                                            <?php echo $form['max_submissions_per_entity'] == 0 ? 'Unlimited' : $form['max_submissions_per_entity']; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Dates:</th>
                                        <td>
                                            <?php if ($form['start_date'] || $form['end_date']): ?>
                                                <?php echo $form['start_date'] ? date('M d, Y', strtotime($form['start_date'])) : 'Immediate'; ?>
                                                to
                                                <?php echo $form['end_date'] ? date('M d, Y', strtotime($form['end_date'])) : 'No expiry'; ?>
                                            <?php else: ?>
                                                No date restrictions
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Created By:</th>
                                        <td><?php echo htmlspecialchars($form['created_by_username'] ?? 'Unknown'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Created Date:</th>
                                        <td><?php echo date('M d, Y H:i', strtotime($form['created_at'])); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Last Updated:</th>
                                        <td><?php echo date('M d, Y H:i', strtotime($form['updated_at'] ?? $form['created_at'])); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="card mb-4">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-bar"></i> Statistics
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $stats = $formManager->getFormSubmissionCounts($formId);
                                ?>
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="display-6 text-primary"><?php echo $stats['family_submissions']; ?></div>
                                        <small class="text-muted">Family Submissions</small>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="display-6 text-success"><?php echo $stats['member_submissions']; ?></div>
                                        <small class="text-muted">Member Submissions</small>
                                    </div>
                                </div>
                                <div class="progress mb-3" style="height: 20px;">
                                    <?php
                                    $totalSubmissions = $stats['family_submissions'] + $stats['member_submissions'];
                                    $maxSubmissions = $form['max_submissions_per_entity'];
                                    $percentage = 0;
                                    
                                    if ($maxSubmissions > 0) {
                                        $percentage = min(100, ($totalSubmissions / $maxSubmissions) * 100);
                                    } elseif ($maxSubmissions == 0) {
                                        $percentage = 0;
                                    }
                                    
                                    $color = $percentage < 70 ? 'success' : ($percentage < 90 ? 'warning' : 'danger');
                                    ?>
                                    <div class="progress-bar bg-<?php echo $color; ?>" 
                                         role="progressbar" 
                                         style="width: <?php echo $percentage; ?>%"
                                         aria-valuenow="<?php echo $percentage; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                        <?php echo round($percentage, 1); ?>%
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <?php echo $totalSubmissions; ?> of 
                                    <?php echo $maxSubmissions == 0 ? '∞' : $maxSubmissions; ?> 
                                    submissions used
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-list"></i> Form Fields
                                        <span class="badge bg-light text-dark ms-2">
                                            <?php echo count($form['fields']); ?> fields
                                        </span>
                                    </h5>
                                    <a href="builder.php?form_id=<?php echo $formId; ?>" class="btn btn-light btn-sm">
                                        <i class="fas fa-plus"></i> Add Field
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($form['fields'])): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> No fields added yet. 
                                        <a href="builder.php?form_id=<?php echo $formId; ?>" class="alert-link">
                                            Add some fields to start using this form.
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Field Name</th>
                                                    <th>Field Type</th>
                                                    <th>Required</th>
                                                    <th>Options</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($form['fields'] as $index => $field): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($field['field_label']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($field['field_code']); ?></small>
                                                        <?php if ($field['hint_text']): ?>
                                                            <br><small class="text-info"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($field['hint_text']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary">
                                                            <?php echo ucfirst(htmlspecialchars($field['field_type'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($field['is_required']): ?>
                                                            <span class="badge bg-danger">Required</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Optional</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($field['field_type'] == 'dropdown' || $field['field_type'] == 'radio' || $field['field_type'] == 'checkbox'): ?>
                                                            <?php if (is_array($field['field_options'])): ?>
                                                                <small>
                                                                    <?php echo count($field['field_options']); ?> options
                                                                </small>
                                                            <?php elseif (is_string($field['field_options'])): ?>
                                                                <small class="text-truncate d-block" style="max-width: 150px;">
                                                                    <?php echo htmlspecialchars($field['field_options']); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <small class="text-muted">-</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-info" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#fieldModal<?php echo $field['field_id']; ?>">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="builder.php?form_id=<?php echo $formId; ?>&edit_field=<?php echo $field['field_id']; ?>" 
                                                           class="btn btn-sm btn-outline-warning">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                                onclick="deleteField(<?php echo $field['field_id']; ?>, '<?php echo htmlspecialchars(addslashes($field['field_label'])); ?>')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                
                                                <!-- Field Details Modal -->
                                                <div class="modal fade" id="fieldModal<?php echo $field['field_id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog modal-lg">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">
                                                                    Field Details: <?php echo htmlspecialchars($field['field_label']); ?>
                                                                </h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <table class="table table-borderless">
                                                                    <tr>
                                                                        <th width="30%">Field Code:</th>
                                                                        <td><code><?php echo htmlspecialchars($field['field_code']); ?></code></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Field Label:</th>
                                                                        <td><?php echo htmlspecialchars($field['field_label']); ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Field Type:</th>
                                                                        <td>
                                                                            <span class="badge bg-secondary">
                                                                                <?php echo ucfirst(htmlspecialchars($field['field_type'])); ?>
                                                                            </span>
                                                                        </td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Required:</th>
                                                                        <td>
                                                                            <?php echo $field['is_required'] ? '<span class="badge bg-danger">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?>
                                                                        </td>
                                                                    </tr>
                                                                    <?php if ($field['placeholder']): ?>
                                                                    <tr>
                                                                        <th>Placeholder:</th>
                                                                        <td><?php echo htmlspecialchars($field['placeholder']); ?></td>
                                                                    </tr>
                                                                    <?php endif; ?>
                                                                    <?php if ($field['hint_text']): ?>
                                                                    <tr>
                                                                        <th>Hint Text:</th>
                                                                        <td><?php echo htmlspecialchars($field['hint_text']); ?></td>
                                                                    </tr>
                                                                    <?php endif; ?>
                                                                    <?php if ($field['default_value']): ?>
                                                                    <tr>
                                                                        <th>Default Value:</th>
                                                                        <td><?php echo htmlspecialchars($field['default_value']); ?></td>
                                                                    </tr>
                                                                    <?php endif; ?>
                                                                    <?php if ($field['field_options']): ?>
                                                                    <tr>
                                                                        <th>Field Options:</th>
                                                                        <td>
                                                                            <?php if (is_array($field['field_options'])): ?>
                                                                                <ul class="mb-0">
                                                                                    <?php foreach ($field['field_options'] as $key => $value): ?>
                                                                                        <li><code><?php echo htmlspecialchars($key); ?></code>: <?php echo htmlspecialchars($value); ?></li>
                                                                                    <?php endforeach; ?>
                                                                                </ul>
                                                                            <?php else: ?>
                                                                                <pre class="mb-0"><?php echo htmlspecialchars($field['field_options']); ?></pre>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                    <?php endif; ?>
                                                                    <?php if ($field['validation_rules']): ?>
                                                                    <tr>
                                                                        <th>Validation Rules:</th>
                                                                        <td>
                                                                            <?php if (is_array($field['validation_rules'])): ?>
                                                                                <pre class="mb-0"><?php echo htmlspecialchars(json_encode($field['validation_rules'], JSON_PRETTY_PRINT)); ?></pre>
                                                                            <?php else: ?>
                                                                                <pre class="mb-0"><?php echo htmlspecialchars($field['validation_rules']); ?></pre>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                    <?php endif; ?>
                                                                    <?php if ($field['visibility_condition']): ?>
                                                                    <tr>
                                                                        <th>Visibility Condition:</th>
                                                                        <td><code><?php echo htmlspecialchars($field['visibility_condition']); ?></code></td>
                                                                    </tr>
                                                                    <?php endif; ?>
                                                                    <tr>
                                                                        <th>Field Order:</th>
                                                                        <td><?php echo $field['field_order']; ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Created:</th>
                                                                        <td><?php echo date('M d, Y H:i', strtotime($field['created_at'])); ?></td>
                                                                    </tr>
                                                                </table>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                                <a href="builder.php?form_id=<?php echo $formId; ?>&edit_field=<?php echo $field['field_id']; ?>" 
                                                                   class="btn btn-primary">
                                                                    <i class="fas fa-edit"></i> Edit Field
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">
                                    <i class="fas fa-paper-plane"></i> Form Preview
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="form-preview">
                                    <h4 class="mb-4"><?php echo htmlspecialchars($form['form_name']); ?></h4>
                                    <?php if ($form['form_description']): ?>
                                        <p class="text-muted mb-4"><?php echo nl2br(htmlspecialchars($form['form_description'])); ?></p>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($form['fields'])): ?>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> No fields to preview. Add fields first.
                                        </div>
                                    <?php else: ?>
                                        <form class="needs-validation" novalidate>
                                            <?php foreach ($form['fields'] as $field): ?>
                                            <div class="mb-3">
                                                <label for="preview_<?php echo $field['field_id']; ?>" class="form-label">
                                                    <?php echo htmlspecialchars($field['field_label']); ?>
                                                    <?php if ($field['is_required']): ?>
                                                        <span class="text-danger">*</span>
                                                    <?php endif; ?>
                                                </label>
                                                
                                                <?php if ($field['hint_text']): ?>
                                                    <div class="form-text mb-2">
                                                        <i class="fas fa-info-circle"></i> 
                                                        <?php echo htmlspecialchars($field['hint_text']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php switch ($field['field_type']): 
                                                    case 'text': ?>
                                                        <input type="text" 
                                                               class="form-control" 
                                                               id="preview_<?php echo $field['field_id']; ?>"
                                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'textarea': ?>
                                                        <textarea class="form-control" 
                                                                  id="preview_<?php echo $field['field_id']; ?>"
                                                                  rows="3"
                                                                  placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                                  <?php echo $field['is_required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($field['default_value'] ?? ''); ?></textarea>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'number': ?>
                                                        <input type="number" 
                                                               class="form-control" 
                                                               id="preview_<?php echo $field['field_id']; ?>"
                                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'email': ?>
                                                        <input type="email" 
                                                               class="form-control" 
                                                               id="preview_<?php echo $field['field_id']; ?>"
                                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'dropdown': ?>
                                                        <select class="form-control" 
                                                                id="preview_<?php echo $field['field_id']; ?>"
                                                                <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                            <option value="">-- Select --</option>
                                                            <?php if (is_array($field['field_options'] ?? '')): ?>
                                                                <?php foreach ($field['field_options'] as $key => $value): ?>
                                                                    <option value="<?php echo htmlspecialchars($key); ?>"
                                                                            <?php echo ($field['default_value'] ?? '') == $key ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($value); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </select>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'radio': ?>
                                                        <?php if (is_array($field['field_options'] ?? '')): ?>
                                                            <?php foreach ($field['field_options'] as $key => $value): ?>
                                                            <div class="form-check">
                                                                <input class="form-check-input" 
                                                                       type="radio" 
                                                                       name="preview_radio_<?php echo $field['field_id']; ?>"
                                                                       id="preview_<?php echo $field['field_id']; ?>_<?php echo $key; ?>"
                                                                       value="<?php echo htmlspecialchars($key); ?>"
                                                                       <?php echo ($field['default_value'] ?? '') == $key ? 'checked' : ''; ?>
                                                                       <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                                <label class="form-check-label" for="preview_<?php echo $field['field_id']; ?>_<?php echo $key; ?>">
                                                                    <?php echo htmlspecialchars($value); ?>
                                                                </label>
                                                            </div>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'checkbox': ?>
                                                        <?php if (is_array($field['field_options'] ?? '')): ?>
                                                            <?php foreach ($field['field_options'] as $key => $value): ?>
                                                            <div class="form-check">
                                                                <input class="form-check-input" 
                                                                       type="checkbox" 
                                                                       id="preview_<?php echo $field['field_id']; ?>_<?php echo $key; ?>"
                                                                       value="<?php echo htmlspecialchars($key); ?>">
                                                                <label class="form-check-label" for="preview_<?php echo $field['field_id']; ?>_<?php echo $key; ?>">
                                                                    <?php echo htmlspecialchars($value); ?>
                                                                </label>
                                                            </div>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'date': ?>
                                                        <input type="date" 
                                                               class="form-control" 
                                                               id="preview_<?php echo $field['field_id']; ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'phone': ?>
                                                        <input type="tel" 
                                                               class="form-control" 
                                                               id="preview_<?php echo $field['field_id']; ?>"
                                                               placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? '07xxxxxxxx'); ?>"
                                                               value="<?php echo htmlspecialchars($field['default_value'] ?? ''); ?>"
                                                               pattern="[0-9]{10}"
                                                               <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'yesno': ?>
                                                        <div>
                                                            <div class="form-check form-check-inline">
                                                                <input class="form-check-input" 
                                                                       type="radio" 
                                                                       name="preview_yesno_<?php echo $field['field_id']; ?>"
                                                                       id="preview_<?php echo $field['field_id']; ?>_yes"
                                                                       value="yes"
                                                                       <?php echo ($field['default_value'] ?? '') == 'yes' ? 'checked' : ''; ?>
                                                                       <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                                <label class="form-check-label" for="preview_<?php echo $field['field_id']; ?>_yes">Yes</label>
                                                            </div>
                                                            <div class="form-check form-check-inline">
                                                                <input class="form-check-input" 
                                                                       type="radio" 
                                                                       name="preview_yesno_<?php echo $field['field_id']; ?>"
                                                                       id="preview_<?php echo $field['field_id']; ?>_no"
                                                                       value="no"
                                                                       <?php echo ($field['default_value'] ?? '') == 'no' ? 'checked' : ''; ?>
                                                                       <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                                <label class="form-check-label" for="preview_<?php echo $field['field_id']; ?>_no">No</label>
                                                            </div>
                                                        </div>
                                                        <?php break; ?>
                                                        
                                                    <?php case 'rating': ?>
                                                        <div class="rating-preview">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <i class="fas fa-star text-warning"></i>
                                                            <?php endfor; ?>
                                                        </div>
                                                        <?php break; ?>
                                                        
                                                    <?php default: ?>
                                                        <input type="text" 
                                                               class="form-control" 
                                                               id="preview_<?php echo $field['field_id']; ?>"
                                                               placeholder="[<?php echo htmlspecialchars($field['field_type']); ?> field type]"
                                                               disabled>
                                                <?php endswitch; ?>
                                                
                                                <?php if ($field['is_required']): ?>
                                                    <div class="invalid-feedback">
                                                        This field is required
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="d-flex justify-content-between mt-4">
                                                <button type="button" class="btn btn-secondary" disabled>
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                                <button type="button" class="btn btn-primary" disabled>
                                                    <i class="fas fa-paper-plane"></i> Submit Form
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-footer text-muted">
                                <small><i class="fas fa-info-circle"></i> This is a preview only. Actual form may have additional functionality.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<script>
function deleteForm(formId) {
    if (confirm('Are you sure you want to delete this form? All fields and submissions will be permanently deleted.')) {
        window.location.href = 'delete.php?form_id=' + formId;
    }
}

function deleteField(fieldId, fieldName) {
    if (confirm('Are you sure you want to delete the field: "' + fieldName + '"? This action cannot be undone.')) {
        window.location.href = 'builder.php?action=delete_field&field_id=' + fieldId;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialize form validation for preview
    const form = document.querySelector('.form-preview form');
    form.addEventListener('submit', function(event) {
        event.preventDefault();
        event.stopPropagation();
        alert('This is a preview form. No data will be submitted.');
    }, false);
    
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(element => {
        new bootstrap.Tooltip(element);
    });
});
</script>

<style>
.form-preview {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
}

.form-preview h4 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}

.rating-preview {
    font-size: 24px;
    color: #f1c40f;
}

.table th {
    font-weight: 600;
}

.badge {
    font-size: 0.85em;
}

.card {
    margin-bottom: 1.5rem;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.card-header {
    font-weight: 600;
}

.progress {
    border-radius: 10px;
}

.display-6 {
    font-size: 2.5rem;
    font-weight: 300;
}

@media (max-width: 768px) {
    .display-6 {
        font-size: 2rem;
    }
    
    .btn-toolbar {
        flex-wrap: wrap;
        margin-top: 10px;
    }
    
    .btn-toolbar .btn {
        margin-bottom: 5px;
    }
}
</style>

<?php include '../includes/footer.php'; ?>