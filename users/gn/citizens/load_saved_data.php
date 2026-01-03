<?php
// forms/load_saved_data.php
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
$type = $_GET['type'] ?? 'family';

// Validate parameters
if (empty($form_id) || empty($family_id) || empty($submission_id)) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Missing required parameters</div>';
    exit();
}

// Get submission details
$submission_query = "SELECT fs.*, f.form_name, f.form_description, f.form_code,
                     u1.username as submitted_by, u2.username as reviewed_by
                     FROM " . ($type === 'family' ? 'form_submissions_family' : 'form_submissions_member') . " fs
                     JOIN forms f ON fs.form_id = f.form_id
                     LEFT JOIN users u1 ON fs.submitted_by_user_id = u1.user_id
                     LEFT JOIN users u2 ON fs.reviewed_by_user_id = u2.user_id
                     WHERE fs.submission_id = ? AND fs.form_id = ?";
if ($type === 'family') {
    $submission_query .= " AND fs.family_id = ?";
} else {
    $submission_query .= " AND fs.citizen_id = ?";
}

$submission_stmt = $db->prepare($submission_query);
if ($type === 'family') {
    $submission_stmt->bind_param("iis", $submission_id, $form_id, $family_id);
} else {
    $submission_stmt->bind_param("iii", $submission_id, $form_id, $family_id);
}
$submission_stmt->execute();
$submission_result = $submission_stmt->get_result();

if ($submission_result->num_rows === 0) {
    http_response_code(404);
    echo '<div class="alert alert-danger">Submission not found</div>';
    exit();
}

$submission = $submission_result->fetch_assoc();

// Get form fields
$fields_query = "SELECT ff.* FROM form_fields ff 
                 WHERE ff.form_id = ? 
                 ORDER BY ff.field_order ASC";
$fields_stmt = $db->prepare($fields_query);
$fields_stmt->bind_param("i", $form_id);
$fields_stmt->execute();
$fields_result = $fields_stmt->get_result();
$fields = $fields_result->fetch_all(MYSQLI_ASSOC);

// Get responses
$response_query = "SELECT fr.* FROM " . ($type === 'family' ? 'form_responses_family' : 'form_responses_member') . " fr
                   WHERE fr.submission_id = ?";
$response_stmt = $db->prepare($response_query);
$response_stmt->bind_param("i", $submission_id);
$response_stmt->execute();
$response_result = $response_stmt->get_result();

$responses = [];
while ($row = $response_result->fetch_assoc()) {
    $responses[$row['field_id']] = $row;
}

// Determine status badge color
$status_class = 'secondary';
switch ($submission['submission_status']) {
    case 'draft': $status_class = 'warning'; break;
    case 'submitted': $status_class = 'info'; break;
    case 'approved': $status_class = 'success'; break;
    case 'rejected': $status_class = 'danger'; break;
    case 'pending_review': $status_class = 'primary'; break;
}
?>

<div class="saved-data-container">
    <div class="submission-header mb-4">
        <h4 class="text-info">
            <i class="bi bi-file-earmark-check me-2"></i>
            <?php echo htmlspecialchars($submission['form_name']); ?>
        </h4>
        
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card border-0 bg-light">
                    <div class="card-body p-2">
                        <small class="text-muted d-block">Status</small>
                        <span class="badge bg-<?php echo $status_class; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $submission['submission_status'])); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 bg-light">
                    <div class="card-body p-2">
                        <small class="text-muted d-block">Form Code</small>
                        <code><?php echo htmlspecialchars($submission['form_code']); ?></code>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 bg-light">
                    <div class="card-body p-2">
                        <small class="text-muted d-block">Submitted On</small>
                        <?php echo $submission['submission_date'] ? date('d M Y, h:i A', strtotime($submission['submission_date'])) : 'Not submitted'; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 bg-light">
                    <div class="card-body p-2">
                        <small class="text-muted d-block">Submitted By</small>
                        <?php echo htmlspecialchars($submission['submitted_by'] ?? 'Unknown'); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($submission['reviewed_by']): ?>
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card border-0 bg-light">
                    <div class="card-body p-2">
                        <small class="text-muted d-block">Reviewed On</small>
                        <?php echo $submission['review_date'] ? date('d M Y, h:i A', strtotime($submission['review_date'])) : 'Not reviewed'; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 bg-light">
                    <div class="card-body p-2">
                        <small class="text-muted d-block">Reviewed By</small>
                        <?php echo htmlspecialchars($submission['reviewed_by']); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 bg-light">
                    <div class="card-body p-2">
                        <small class="text-muted d-block">Completion</small>
                        <?php 
                        $completed = $submission['completed_fields'] ?? 0;
                        $total = $submission['total_fields'] ?? 0;
                        $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
                        ?>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-success" style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                        <small><?php echo $completed; ?>/<?php echo $total; ?> fields</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($submission['review_notes']): ?>
        <div class="alert alert-warning">
            <h6><i class="bi bi-chat-left-text me-2"></i> Review Notes</h6>
            <p class="mb-0"><?php echo nl2br(htmlspecialchars($submission['review_notes'])); ?></p>
        </div>
        <?php endif; ?>
        
        <p class="text-muted mb-4"><?php echo htmlspecialchars($submission['form_description'] ?? ''); ?></p>
    </div>
    
    <div class="saved-data-content">
        <h5 class="border-bottom pb-2 mb-3">Form Responses</h5>
        
        <?php
        $current_section = '';
        
        foreach ($fields as $field):
            $field_id = $field['field_id'];
            $response = $responses[$field_id] ?? null;
            $field_value = $response ? $response['field_value'] : '';
            $file_info = $response ? [
                'path' => $response['file_path'],
                'name' => $response['file_name'],
                'size' => $response['file_size'],
                'type' => $response['file_type']
            ] : null;
            
            // Check for section
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
                
                if ($current_section === '') {
                    echo '<div class="form-section mb-4">';
                    $current_section = 'general';
                }
            }
        ?>
        
        <div class="saved-data-item">
            <div class="data-label">
                <?php echo htmlspecialchars($field_label); ?>
                <?php if ($field['is_required']): ?>
                    <span class="text-danger">*</span>
                <?php endif; ?>
            </div>
            
            <div class="data-value">
                <?php
                if ($field['field_type'] === 'file' && $file_info && !empty($file_info['path'])) {
                    $file_path = '../../../' . $file_info['path'];
                    if (file_exists($file_path)):
                ?>
                    <div class="data-file">
                        <i class="bi bi-file-earmark"></i>
                        <a href="<?php echo htmlspecialchars($file_info['path']); ?>" 
                           target="_blank" class="text-decoration-none">
                            <?php echo htmlspecialchars($file_info['name']); ?>
                        </a>
                        <small class="text-muted ms-2">
                            (<?php echo $this->formatFileSize($file_info['size'] ?? 0); ?>)
                        </small>
                    </div>
                <?php
                    else:
                        echo '<span class="text-danger">File not found</span>';
                    endif;
                } elseif ($field['field_type'] === 'checkbox') {
                    $values = json_decode($field_value ?? '[]', true);
                    if (is_array($values) && !empty($values)) {
                        echo '<div class="d-flex flex-wrap gap-1">';
                        foreach ($values as $value) {
                            echo '<span class="badge bg-info">' . htmlspecialchars($value) . '</span>';
                        }
                        echo '</div>';
                    } else {
                        echo '<span class="text-muted">No selection</span>';
                    }
                } elseif ($field['field_type'] === 'yesno') {
                    echo '<span class="badge ' . ($field_value === 'yes' ? 'bg-success' : 'bg-danger') . '">' 
                         . ($field_value === 'yes' ? 'Yes' : 'No') . '</span>';
                } elseif ($field['field_type'] === 'rating') {
                    $rating = intval($field_value);
                    echo '<div class="d-flex align-items-center">';
                    for ($i = 1; $i <= 5; $i++) {
                        echo '<i class="bi bi-star' . ($i <= $rating ? '-fill' : '') . ' text-warning me-1"></i>';
                    }
                    echo '<span class="ms-2">(' . $rating . '/5)</span>';
                    echo '</div>';
                } else {
                    if (empty($field_value) && $field_value !== '0') {
                        echo '<span class="text-muted">Not answered</span>';
                    } else {
                        echo nl2br(htmlspecialchars($field_value));
                    }
                }
                ?>
            </div>
            
            <?php if (!empty($field['hint_text'])): ?>
                <small class="text-muted d-block mt-1">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php echo htmlspecialchars($field['hint_text']); ?>
                </small>
            <?php endif; ?>
        </div>
        
        <?php endforeach; ?>
        
        <?php if ($current_section !== ''): ?>
            </div> <!-- Close last section -->
        <?php endif; ?>
    </div>
</div>

<style>
    .saved-data-item {
        border-bottom: 1px solid #dee2e6;
        padding: 10px 0;
    }
    .saved-data-item:last-child {
        border-bottom: none;
    }
    .data-label {
        font-weight: 600;
        color: #495057;
        margin-bottom: 5px;
    }
    .data-value {
        color: #6c757d;
    }
    .data-file {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 5px;
    }
    .data-file a {
        color: #0d6efd;
        text-decoration: none;
    }
    .data-file a:hover {
        text-decoration: underline;
    }
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
    .progress {
        background-color: #e9ecef;
    }
    @media (max-width: 768px) {
        .submission-header .row > div {
            margin-bottom: 10px;
        }
        .form-section {
            padding: 15px;
        }
    }
</style>  

<?php
// Helper function to format file size
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}
?>