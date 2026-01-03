<?php
// forms/load_form_modal.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../../config.php';
require_once '../../../classes/Auth.php';

// Check session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo '<div class="alert alert-danger">Authentication required</div>';
    exit();
}

// Get database connection
$db = getMainConnection();
if (!$db) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Database connection failed</div>';
    exit();
}

// Get parameters
$form_id = $_GET['form_id'] ?? 0;
$family_id = $_GET['family_id'] ?? '';
$submission_id = $_GET['submission_id'] ?? 0;
$action = $_GET['action'] ?? 'view';

// Validate parameters
if (empty($form_id) || empty($family_id)) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Missing required parameters</div>';
    exit();
}

// Get form details
$form_query = "SELECT * FROM forms WHERE form_id = ? AND is_active = 1";
$form_stmt = $db->prepare($form_query);
$form_stmt->bind_param("i", $form_id);
$form_stmt->execute();
$form_result = $form_stmt->get_result();

if ($form_result->num_rows === 0) {
    http_response_code(404);
    echo '<div class="alert alert-danger">Form not found or inactive</div>';
    exit();
}

$form = $form_result->fetch_assoc();

// Verify user has access to this family (GN level)
$user_type = $_SESSION['user_type'] ?? '';
$gn_id = $_SESSION['office_code'] ?? '';

if ($user_type === 'gn') {
    $verify_query = "SELECT family_id FROM families WHERE family_id = ? AND gn_id = ?";
    $verify_stmt = $db->prepare($verify_query);
    $verify_stmt->bind_param("ss", $family_id, $gn_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        http_response_code(403);
        echo '<div class="alert alert-danger">Access denied to this family</div>';
        exit();
    }
}

// Get form fields
$fields_query = "SELECT * FROM form_fields WHERE form_id = ? ORDER BY field_order ASC";
$fields_stmt = $db->prepare($fields_query);
$fields_stmt->bind_param("i", $form_id);
$fields_stmt->execute();
$fields_result = $fields_stmt->get_result();
$fields = $fields_result->fetch_all(MYSQLI_ASSOC);

// Get existing submission data if editing/viewing
$submission_data = [];
if ($submission_id) {
    $response_query = "SELECT field_id, field_value, file_path, file_name 
                       FROM form_responses_family 
                       WHERE submission_id = ?";
    $response_stmt = $db->prepare($response_query);
    $response_stmt->bind_param("i", $submission_id);
    $response_stmt->execute();
    $response_result = $response_stmt->get_result();
    
    while ($row = $response_result->fetch_assoc()) {
        $submission_data[$row['field_id']] = $row;
    }
}

// Form header
?>
<div class="form-header mb-4">
    <h4 class="text-primary">
        <i class="bi bi-file-text me-2"></i>
        <?php echo htmlspecialchars($form['form_name']); ?>
    </h4>
    <p class="text-muted mb-3"><?php echo htmlspecialchars($form['form_description'] ?? ''); ?></p>
    
    <div class="row mb-3">
        <div class="col-md-4">
            <small class="text-muted">
                <i class="bi bi-code me-1"></i>
                Code: <?php echo htmlspecialchars($form['form_code']); ?>
            </small>
        </div>
        <div class="col-md-4">
            <small class="text-muted">
                <i class="bi bi-calendar-range me-1"></i>
                Type: <?php echo ucfirst($form['target_entity']); ?>
            </small>
        </div>
        <div class="col-md-4">
            <small class="text-muted">
                <i class="bi bi-person me-1"></i>
                Family ID: <?php echo htmlspecialchars($family_id); ?>
            </small>
        </div>
    </div>
    
    <?php if ($action === 'view'): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        This is a read-only view of the form submission.
    </div>
    <?php endif; ?>
</div>

<form id="dynamicForm" data-form-id="<?php echo $form_id; ?>" data-family-id="<?php echo $family_id; ?>">
    <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
    <input type="hidden" name="family_id" value="<?php echo $family_id; ?>">
    <?php if ($submission_id): ?>
        <input type="hidden" name="submission_id" value="<?php echo $submission_id; ?>">
    <?php endif; ?>
    
    <div class="form-sections">
        <?php
        $current_section = '';
        $field_counter = 0;
        
        foreach ($fields as $field):
            $field_id = $field['field_id'];
            $field_value = $submission_data[$field_id]['field_value'] ?? '';
            $is_readonly = ($action === 'view');
            $required_attr = $field['is_required'] ? 'required' : '';
            $required_label = $field['is_required'] ? ' <span class="text-danger">*</span>' : '';
            
            // Check if this is a new section
            if (strpos($field['field_label'], '::') !== false) {
                $parts = explode('::', $field['field_label']);
                if (count($parts) > 1) {
                    $section_title = trim($parts[0]);
                    $field_label = trim($parts[1]);
                    
                    if ($section_title !== $current_section) {
                        if ($current_section !== '') {
                            echo '</div>'; // Close previous section
                        }
                        echo '<div class="form-section mb-4">';
                        echo '<h6 class="section-title border-bottom pb-2 mb-3">' . htmlspecialchars($section_title) . '</h6>';
                        $current_section = $section_title;
                    }
                } else {
                    $field_label = $field['field_label'];
                }
            } else {
                $field_label = $field['field_label'];
                
                // Start a section if we're not in one
                if ($current_section === '' && $field_counter === 0) {
                    echo '<div class="form-section mb-4">';
                    $current_section = 'general';
                }
            }
            
            $field_counter++;
        ?>
        
        <div class="form-group mb-3 field-<?php echo $field['field_type']; ?>" data-field-id="<?php echo $field_id; ?>">
            <label for="field_<?php echo $field_id; ?>" class="form-label fw-medium">
                <?php echo htmlspecialchars($field_label); ?><?php echo $required_label; ?>
            </label>
            
            <?php if (!empty($field['hint_text'])): ?>
                <small class="text-muted d-block mb-2">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php echo htmlspecialchars($field['hint_text']); ?>
                </small>
            <?php endif; ?>
            
            <?php
            switch ($field['field_type']):
                case 'text':
                case 'email':
                case 'phone':
                case 'number':
                case 'date':
            ?>
                <input type="<?php echo $field['field_type']; ?>" 
                       id="field_<?php echo $field_id; ?>" 
                       name="field_<?php echo $field_id; ?>" 
                       class="form-control" 
                       value="<?php echo htmlspecialchars($field_value); ?>"
                       placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                       <?php echo $required_attr; ?>
                       <?php echo $is_readonly ? 'readonly' : ''; ?>
                       <?php if ($field['field_type'] === 'date'): ?>
                           min="1900-01-01" max="<?php echo date('Y-m-d'); ?>"
                       <?php endif; ?>>
            
            <?php
                break;
                case 'textarea':
            ?>
                <textarea id="field_<?php echo $field_id; ?>" 
                          name="field_<?php echo $field_id; ?>" 
                          class="form-control" 
                          rows="<?php echo $field['field_options'] ? intval($field['field_options']) : 3; ?>"
                          placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                          <?php echo $required_attr; ?>
                          <?php echo $is_readonly ? 'readonly' : ''; ?>><?php echo htmlspecialchars($field_value); ?></textarea>
            
            <?php
                break;
                case 'dropdown':
                    $options = json_decode($field['field_options'] ?? '[]', true);
                    if (!is_array($options)) $options = [];
            ?>
                <select id="field_<?php echo $field_id; ?>" 
                        name="field_<?php echo $field_id; ?>" 
                        class="form-select"
                        <?php echo $required_attr; ?>
                        <?php echo $is_readonly ? 'disabled' : ''; ?>>
                    <option value="">-- Select --</option>
                    <?php foreach ($options as $option): ?>
                        <?php if (is_array($option) && isset($option['value'])): ?>
                            <option value="<?php echo htmlspecialchars($option['value']); ?>" 
                                <?php echo ($field_value == $option['value']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($option['label'] ?? $option['value']); ?>
                            </option>
                        <?php elseif (is_string($option)): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"
                                <?php echo ($field_value == $option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            
            <?php
                break;
                case 'radio':
                case 'checkbox':
                    $options = json_decode($field['field_options'] ?? '[]', true);
                    if (!is_array($options)) $options = [];
                    $field_value_array = $field['field_type'] === 'checkbox' ? json_decode($field_value ?? '[]', true) : [];
                    if (!is_array($field_value_array)) $field_value_array = [];
            ?>
                <div class="options-group">
                    <?php foreach ($options as $option): ?>
                        <?php 
                        $option_value = is_array($option) ? ($option['value'] ?? '') : $option;
                        $option_label = is_array($option) ? ($option['label'] ?? $option_value) : $option_value;
                        $checked = false;
                        
                        if ($field['field_type'] === 'checkbox') {
                            $checked = in_array($option_value, $field_value_array);
                        } else {
                            $checked = ($field_value == $option_value);
                        }
                        ?>
                        <div class="form-check">
                            <input type="<?php echo $field['field_type']; ?>" 
                                   id="field_<?php echo $field_id; ?>_<?php echo htmlspecialchars($option_value); ?>" 
                                   name="<?php echo $field['field_type'] === 'radio' ? "field_$field_id" : "field_{$field_id}[]"; ?>" 
                                   class="form-check-input" 
                                   value="<?php echo htmlspecialchars($option_value); ?>"
                                   <?php echo $checked ? 'checked' : ''; ?>
                                   <?php echo $required_attr; ?>
                                   <?php echo $is_readonly ? 'disabled' : ''; ?>>
                            <label class="form-check-label" for="field_<?php echo $field_id; ?>_<?php echo htmlspecialchars($option_value); ?>">
                                <?php echo htmlspecialchars($option_label); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            
            <?php
                break;
                case 'yesno':
            ?>
                <div class="btn-group" role="group">
                    <input type="radio" class="btn-check" 
                           name="field_<?php echo $field_id; ?>" 
                           id="field_<?php echo $field_id; ?>_yes" 
                           value="yes"
                           <?php echo ($field_value === 'yes') ? 'checked' : ''; ?>
                           <?php echo $required_attr; ?>
                           <?php echo $is_readonly ? 'disabled' : ''; ?>>
                    <label class="btn btn-outline-success" for="field_<?php echo $field_id; ?>_yes">Yes</label>
                    
                    <input type="radio" class="btn-check" 
                           name="field_<?php echo $field_id; ?>" 
                           id="field_<?php echo $field_id; ?>_no" 
                           value="no"
                           <?php echo ($field_value === 'no') ? 'checked' : ''; ?>
                           <?php echo $required_attr; ?>
                           <?php echo $is_readonly ? 'disabled' : ''; ?>>
                    <label class="btn btn-outline-danger" for="field_<?php echo $field_id; ?>_no">No</label>
                </div>
            
            <?php
                break;
                case 'file':
                    $file_info = $submission_data[$field_id] ?? [];
                    $has_file = !empty($file_info['file_path']) && file_exists('../../../' . $file_info['file_path']);
            ?>
                <div class="file-upload-container">
                    <?php if ($is_readonly): ?>
                        <?php if ($has_file): ?>
                            <div class="alert alert-light border">
                                <i class="bi bi-file-earmark me-2"></i>
                                <a href="<?php echo htmlspecialchars($file_info['file_path']); ?>" 
                                   target="_blank" class="text-decoration-none">
                                    <?php echo htmlspecialchars($file_info['file_name']); ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-secondary">
                                <i class="bi bi-file-x me-2"></i>
                                No file uploaded
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="input-group">
                            <input type="file" 
                                   id="field_<?php echo $field_id; ?>" 
                                   name="field_<?php echo $field_id; ?>" 
                                   class="form-control"
                                   <?php echo $required_attr; ?>
                                   accept="<?php echo htmlspecialchars($field['field_options'] ?? '*/*'); ?>">
                            <?php if ($has_file): ?>
                                <button type="button" class="btn btn-outline-secondary view-file-btn" 
                                        data-file-path="<?php echo htmlspecialchars($file_info['file_path']); ?>">
                                    <i class="bi bi-eye"></i> View Current
                                </button>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted">Max file size: 5MB</small>
                        
                        <?php if ($has_file): ?>
                            <div class="form-check mt-2">
                                <input type="checkbox" 
                                       id="remove_file_<?php echo $field_id; ?>" 
                                       name="remove_file_<?php echo $field_id; ?>" 
                                       class="form-check-input" 
                                       value="1">
                                <label class="form-check-label text-danger" for="remove_file_<?php echo $field_id; ?>">
                                    Remove current file
                                </label>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            
            <?php
                break;
                case 'rating':
                    $max_rating = intval($field['field_options']) ?: 5;
            ?>
                <div class="rating-container">
                    <div class="star-rating">
                        <?php for ($i = 1; $i <= $max_rating; $i++): ?>
                            <div class="form-check form-check-inline">
                                <input type="radio" 
                                       id="field_<?php echo $field_id; ?>_<?php echo $i; ?>" 
                                       name="field_<?php echo $field_id; ?>" 
                                       class="form-check-input" 
                                       value="<?php echo $i; ?>"
                                       <?php echo ($field_value == $i) ? 'checked' : ''; ?>
                                       <?php echo $required_attr; ?>
                                       <?php echo $is_readonly ? 'disabled' : ''; ?>>
                                <label class="form-check-label star-label" for="field_<?php echo $field_id; ?>_<?php echo $i; ?>">
                                    <i class="bi bi-star<?php echo $i <= $field_value ? '-fill' : ''; ?> text-warning"></i>
                                </label>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            
            <?php
                break;
                default:
                    // Default to text input for unknown types
            ?>
                <input type="text" 
                       id="field_<?php echo $field_id; ?>" 
                       name="field_<?php echo $field_id; ?>" 
                       class="form-control" 
                       value="<?php echo htmlspecialchars($field_value); ?>"
                       placeholder="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>"
                       <?php echo $required_attr; ?>
                       <?php echo $is_readonly ? 'readonly' : ''; ?>>
            <?php
                break;
            endswitch;
            ?>
        </div>
        
        <?php endforeach; ?>
        
        <?php if ($current_section !== ''): ?>
            </div> <!-- Close last section -->
        <?php endif; ?>
    </div>
    
    <?php if (!empty($form['target_entity']) && $form['target_entity'] === 'family'): ?>
        <div class="alert alert-light border mt-3">
            <h6 class="alert-heading"><i class="bi bi-info-circle me-2"></i> Family Form</h6>
            <p class="mb-0">This form is related to the entire family <strong><?php echo htmlspecialchars($family_id); ?></strong>.</p>
        </div>
    <?php endif; ?>
</form>

<style>
    .form-section {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        border-left: 4px solid #0d6efd;
    }
    .form-section h6 {
        color: #0d6efd;
        margin-bottom: 15px;
        font-weight: 600;
    }
    .star-rating .star-label {
        font-size: 1.5rem;
        cursor: pointer;
    }
    .star-rating input[type="radio"] {
        display: none;
    }
    .star-rating input[type="radio"]:checked + .star-label i {
        color: #ffc107;
    }
    .options-group {
        background: white;
        padding: 15px;
        border-radius: 6px;
        border: 1px solid #dee2e6;
    }
    .form-check {
        margin-bottom: 8px;
    }
    .btn-group .btn {
        padding: 0.5rem 1.5rem;
    }
    .file-upload-container {
        background: white;
        padding: 15px;
        border-radius: 6px;
        border: 1px solid #dee2e6;
    }
    .view-file-btn {
        min-width: 100px;
    }
    @media (max-width: 768px) {
        .form-section {
            padding: 15px;
        }
        .btn-group .btn {
            padding: 0.375rem 1rem;
            font-size: 0.875rem;
        }
        .star-rating .star-label {
            font-size: 1.25rem;
        }
    }
</style>

<script>
    // Make file view buttons work
    document.querySelectorAll('.view-file-btn').forEach(button => {
        button.addEventListener('click', function() {
            const filePath = this.getAttribute('data-file-path');
            if (filePath) {
                window.open('../../../' + filePath, '_blank');
            }
        });
    });
    
    // Star rating hover effect
    document.querySelectorAll('.star-rating').forEach(rating => {
        const stars = rating.querySelectorAll('.star-label');
        stars.forEach((star, index) => {
            star.addEventListener('mouseenter', function() {
                const radioId = this.getAttribute('for');
                const radio = document.getElementById(radioId);
                if (!radio.disabled) {
                    // Highlight stars up to this one
                    stars.forEach((s, i) => {
                        const starIcon = s.querySelector('i');
                        if (i <= index) {
                            starIcon.classList.remove('bi-star');
                            starIcon.classList.add('bi-star-fill');
                        }
                    });
                }
            });  
            
            star.addEventListener('mouseleave', function() {
                const checkedStar = rating.querySelector('input[type="radio"]:checked');
                stars.forEach((s, i) => {
                    const starIcon = s.querySelector('i');
                    const radioId = s.getAttribute('for');
                    const radio = document.getElementById(radioId);
                    
                    if (!checkedStar || radio.value > checkedStar.value) {
                        starIcon.classList.remove('bi-star-fill');
                        starIcon.classList.add('bi-star');
                    }
                });
            });
        });
    });
</script>