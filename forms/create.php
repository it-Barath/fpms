<?php
/**
 * create.php
 * Create new form for data collection
 */

// Start session and include required files
require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/FormManager.php';

// Initialize Auth
$auth = new Auth();
$auth->requireLogin();
// Only MOHA, District, and Division users can create forms
$auth->requireRole(['moha', 'district', 'division']);

// Initialize FormManager
$formManager = new FormManager();

// Get current user info
$currentUser = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$officeCode = $_SESSION['office_code'];

// Initialize variables
$formData = [];
$error = '';
$success = false;
$formId = null;

// Generate default form code based on timestamp
$defaultFormCode = 'form_' . date('Ymd') . '_' . substr(uniqid(), -6);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Collect form data
        $formData = [
            'form_code' => trim($_POST['form_code'] ?? $defaultFormCode),
            'form_name' => trim($_POST['form_name'] ?? ''),
            'form_description' => trim($_POST['form_description'] ?? ''),
            'form_type' => $_POST['form_type'] ?? 'both',
            'form_category' => trim($_POST['form_category'] ?? ''),
            'target_entity' => $_POST['target_entity'] ?? 'both',
            'created_by_user_id' => $currentUser,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'max_submissions_per_entity' => intval($_POST['max_submissions_per_entity'] ?? 1),
            'start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] . ' 00:00:00' : null,
            'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] . ' 23:59:59' : null
        ];

        // Validate required fields
        if (empty($formData['form_name'])) {
            throw new Exception("Form name is required");
        }
        
        // Generate form code if not provided
        if (empty($formData['form_code'])) {
            $formData['form_code'] = generateFormCodeFromName($formData['form_name']);
        }
        
        // Validate form code format
        if (!preg_match('/^[a-z0-9_]+$/', $formData['form_code'])) {
            throw new Exception("Form code can only contain lowercase letters, numbers, and underscores");
        }
        
        // Check if form code already exists
        $conn = getMainConnection();
        $sql = "SELECT 1 FROM forms WHERE form_code = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $formData['form_code']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                // Append timestamp to make it unique
                $formData['form_code'] .= '_' . time();
            }
        }
        
        // Validate max submissions
        if ($formData['max_submissions_per_entity'] < 0) {
            throw new Exception("Maximum submissions cannot be negative");
        }
        
        // Validate dates
        $currentDate = date('Y-m-d');
        
        if ($formData['start_date'] && $formData['end_date']) {
            if (strtotime($formData['end_date']) < strtotime($formData['start_date'])) {
                throw new Exception("End date must be after start date");
            }
            
            // Check if form will expire based on dates
            $endDate = date('Y-m-d', strtotime($formData['end_date']));
            $formExpired = ($endDate < $currentDate);
        } else {
            $formExpired = false;
        }
        
        // Create the form using FormManager
        $result = $formManager->createForm($formData);
        
        if ($result['success']) {
            $formId = $result['form_id'];
            
            // Redirect to form builder if requested
            if (isset($_POST['save_and_build'])) {
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'message' => 'Form created successfully! You can now add fields to the form.'
                ];
                header("Location: builder.php?form_id=" . $formId);
                exit();
            } else {
                $success = true;
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'message' => 'Form created successfully!'
                ];
                header("Location: manage.php");
                exit();
            }
        } else {
            throw new Exception($result['error']);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/**
 * Generate form code from form name
 */
function generateFormCodeFromName($formName) {
    // Convert to lowercase
    $code = strtolower($formName);
    
    // Replace spaces and special characters with underscores
    $code = preg_replace('/[^a-z0-9]+/', '_', $code);
    
    // Remove multiple underscores
    $code = preg_replace('/_+/', '_', $code);
    
    // Remove leading/trailing underscores
    $code = trim($code, '_');
    
    // Add timestamp if code is too short
    if (strlen($code) < 3) {
        $code .= '_' . time();
    }
    
    // Limit length
    $code = substr($code, 0, 50);
    
    return $code;
}

/**
 * Determine form status based on dates and active flag
 */
function getFormStatusDescription($startDate, $endDate, $isActive) {
    $currentDate = date('Y-m-d');
    
    if (!$isActive) {
        return [
            'status' => 'inactive',
            'label' => 'Inactive',
            'color' => 'secondary',
            'icon' => 'fas fa-ban',
            'description' => 'Form is inactive and not available to users'
        ];
    }
    
    if ($startDate && $endDate) {
        $start = date('Y-m-d', strtotime($startDate));
        $end = date('Y-m-d', strtotime($endDate));
        
        if ($currentDate < $start) {
            return [
                'status' => 'scheduled',
                'label' => 'Scheduled',
                'color' => 'info',
                'icon' => 'fas fa-calendar-alt',
                'description' => 'Form will be available from ' . date('M d, Y', strtotime($start))
            ];
        } elseif ($currentDate >= $start && $currentDate <= $end) {
            return [
                'status' => 'active',
                'label' => 'Active',
                'color' => 'success',
                'icon' => 'fas fa-check-circle',
                'description' => 'Form is currently active and available'
            ];
        } else {
            return [
                'status' => 'expired',
                'label' => 'Expired',
                'color' => 'danger',
                'icon' => 'fas fa-clock',
                'description' => 'Form expired on ' . date('M d, Y', strtotime($end))
            ];
        }
    } else {
        // No dates specified
        return [
            'status' => 'active',
            'label' => 'Active',
            'color' => 'success',
            'icon' => 'fas fa-check-circle',
            'description' => 'Form is active with no date restrictions'
        ];
    }
}

// Set page title
$pageTitle = "Create New Form - " . SITE_NAME;

// Include header
include '../includes/header.php';

// Calculate default dates
$defaultStartDate = date('Y-m-d');
$defaultEndDate = date('Y-m-d', strtotime('+30 days'));

// Get status preview
$statusPreview = getFormStatusDescription(
    $formData['start_date'] ?? $defaultStartDate,
    $formData['end_date'] ?? $defaultEndDate,
    $formData['is_active'] ?? 1
);
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
                            <i class="fas fa-plus-circle"></i> Create New Form
                        </h1>
                        <p class="text-muted mb-0">
                            Create a new data collection form for families or members
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="manage.php" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-arrow-left"></i> Back to Forms
                        </a>
                        <a href="manage.php" class="btn btn-outline-primary">
                            <i class="fas fa-list"></i> View All Forms
                        </a>
                    </div>
                </div>
                
                <?php displayFlashMessage(); ?>
                
                <!-- Success Message -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> Form created successfully!
                    <div class="mt-2">
                        <a href="builder.php?form_id=<?php echo $formId; ?>" class="btn btn-sm btn-success">
                            <i class="fas fa-tools"></i> Go to Form Builder
                        </a>
                        <a href="manage.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-list"></i> View All Forms
                        </a>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Error Message -->
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Form Creation Card -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-file-alt"></i> Form Details
                            </h5>
                            <div class="form-status-preview">
                                <span class="badge bg-<?php echo $statusPreview['color']; ?>">
                                    <i class="<?php echo $statusPreview['icon']; ?>"></i>
                                    <?php echo $statusPreview['label']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="createFormForm" class="needs-validation" novalidate>
                            <div class="row">
                                <!-- Basic Information -->
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="form_name" class="form-label">
                                            Form Name <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="form_name" 
                                               name="form_name" 
                                               value="<?php echo htmlspecialchars($formData['form_name'] ?? ''); ?>"
                                               required
                                               placeholder="e.g., Annual Family Survey 2024"
                                               onblur="generateFormCode()">
                                        <div class="form-text">
                                            Descriptive name that users will see
                                        </div>
                                        <div class="invalid-feedback">
                                            Please enter a form name
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="form_code" class="form-label">
                                            Form Code
                                        </label>
                                        <div class="input-group">
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="form_code" 
                                                   name="form_code" 
                                                   value="<?php echo htmlspecialchars($formData['form_code'] ?? $defaultFormCode); ?>"
                                                   pattern="[a-z0-9_]+"
                                                   placeholder="e.g., family_survey_2024">
                                            <button class="btn btn-outline-secondary" type="button" onclick="generateFormCode()" title="Generate from form name">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                            <button class="btn btn-outline-info" type="button" onclick="suggestUniqueCode()" title="Suggest unique code">
                                                <i class="fas fa-magic"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">
                                            Unique identifier (auto-generated). Lowercase letters, numbers, underscores only.
                                        </div>
                                        <div class="invalid-feedback">
                                            Please enter a valid form code (lowercase letters, numbers, underscores)
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="form_description" class="form-label">
                                            Description
                                        </label>
                                        <textarea class="form-control" 
                                                  id="form_description" 
                                                  name="form_description" 
                                                  rows="3"
                                                  placeholder="Describe the purpose of this form..."><?php echo htmlspecialchars($formData['form_description'] ?? ''); ?></textarea>
                                        <div class="form-text">
                                            Optional description to help users understand the form's purpose
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="form_category" class="form-label">
                                            Category
                                        </label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="form_category" 
                                               name="form_category" 
                                               value="<?php echo htmlspecialchars($formData['form_category'] ?? ''); ?>"
                                               placeholder="e.g., Survey, Application, Report">
                                        <div class="form-text">
                                            Optional category to organize forms
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Settings & Status -->
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="target_entity" class="form-label">
                                            Target Entity <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-control" 
                                                id="target_entity" 
                                                name="target_entity" 
                                                required>
                                            <option value="family" <?php echo ($formData['target_entity'] ?? 'both') === 'family' ? 'selected' : ''; ?>>
                                                Family Forms
                                            </option>
                                            <option value="member" <?php echo ($formData['target_entity'] ?? 'both') === 'member' ? 'selected' : ''; ?>>
                                                Member Forms
                                            </option>
                                            <option value="both" <?php echo ($formData['target_entity'] ?? 'both') === 'both' ? 'selected' : ''; ?>>
                                                Both Family & Member
                                            </option>
                                        </select>
                                        <div class="form-text">
                                            Who will fill this form? Families, individual members, or both?
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="form_type" class="form-label">
                                            Form Type
                                        </label>
                                        <select class="form-control" 
                                                id="form_type" 
                                                name="form_type">
                                            <option value="survey" <?php echo ($formData['form_type'] ?? 'both') === 'survey' ? 'selected' : ''; ?>>
                                                Survey
                                            </option>
                                            <option value="application" <?php echo ($formData['form_type'] ?? 'both') === 'application' ? 'selected' : ''; ?>>
                                                Application
                                            </option>
                                            <option value="report" <?php echo ($formData['form_type'] ?? 'both') === 'report' ? 'selected' : ''; ?>>
                                                Report
                                            </option>
                                            <option value="registration" <?php echo ($formData['form_type'] ?? 'both') === 'registration' ? 'selected' : ''; ?>>
                                                Registration
                                            </option>
                                            <option value="both" <?php echo ($formData['form_type'] ?? 'both') === 'both' ? 'selected' : ''; ?>>
                                                Other/General
                                            </option>
                                        </select>
                                        <div class="form-text">
                                            General category for organizing forms
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="max_submissions_per_entity" class="form-label">
                                            Maximum Submissions per Family/Member
                                        </label>
                                        <input type="number" 
                                               class="form-control" 
                                               id="max_submissions_per_entity" 
                                               name="max_submissions_per_entity" 
                                               value="<?php echo htmlspecialchars($formData['max_submissions_per_entity'] ?? 1); ?>"
                                               min="0"
                                               max="999">
                                        <div class="form-text">
                                            0 = Unlimited submissions, 1 = One-time form, 2+ = Multiple submissions allowed
                                        </div>
                                    </div>
                                    
                                    <!-- Date Range Section -->
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">
                                                <i class="fas fa-calendar-alt"></i> Availability Period
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label for="start_date" class="form-label">Start Date</label>
                                                    <input type="date" 
                                                           class="form-control" 
                                                           id="start_date" 
                                                           name="start_date" 
                                                           value="<?php echo !empty($formData['start_date']) ? date('Y-m-d', strtotime($formData['start_date'])) : $defaultStartDate; ?>"
                                                           onchange="updateStatusPreview()">
                                                    <div class="form-text">
                                                        Form becomes available from this date
                                                    </div>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label for="end_date" class="form-label">End Date</label>
                                                    <input type="date" 
                                                           class="form-control" 
                                                           id="end_date" 
                                                           name="end_date" 
                                                           value="<?php echo !empty($formData['end_date']) ? date('Y-m-d', strtotime($formData['end_date'])) : $defaultEndDate; ?>"
                                                           onchange="updateStatusPreview()">
                                                    <div class="form-text">
                                                        Form becomes unavailable after this date
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" 
                                                       type="checkbox" 
                                                       id="no_expiry" 
                                                       name="no_expiry" 
                                                       onclick="toggleExpiry()">
                                                <label class="form-check-label" for="no_expiry">
                                                    No expiry date (form never expires)
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fas fa-info-circle"></i> 
                                                Leave dates empty for immediate availability without expiry
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Status Preview -->
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">
                                                <i class="fas fa-info-circle"></i> Form Status Preview
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-flex align-items-center" id="statusPreview">
                                                <span class="badge bg-<?php echo $statusPreview['color']; ?> me-2 p-2">
                                                    <i class="<?php echo $statusPreview['icon']; ?>"></i>
                                                    <?php echo $statusPreview['label']; ?>
                                                </span>
                                                <div>
                                                    <small class="text-muted" id="statusDescription">
                                                        <?php echo $statusPreview['description']; ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" 
                                                       type="checkbox" 
                                                       id="is_active" 
                                                       name="is_active" 
                                                       value="1"
                                                       <?php echo isset($formData['is_active']) && $formData['is_active'] ? 'checked' : 'checked'; ?>
                                                       onchange="updateStatusPreview()">
                                                <label class="form-check-label" for="is_active">
                                                    Activate form immediately
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                If unchecked, form will be created but won't be available to users until activated
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Form Actions -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="d-flex justify-content-between">
                                        <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                                            <i class="fas fa-redo"></i> Reset Form
                                        </button>
                                        <div>
                                            <button type="submit" name="save_form" class="btn btn-primary me-2">
                                                <i class="fas fa-save"></i> Save Form
                                            </button>
                                            <button type="submit" name="save_and_build" class="btn btn-success">
                                                <i class="fas fa-tools"></i> Save & Start Building
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Help & Guidelines -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-question-circle"></i> Form Creation Guidelines
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <li><strong>Form Code:</strong> Auto-generated from name. Can be manually edited if needed.</li>
                                    <li><strong>Target Entity:</strong> Choose whether the form is for families, individual members, or both</li>
                                    <li><strong>Dates:</strong> Set availability period or leave empty for immediate/unlimited access</li>
                                    <li><strong>Status:</strong> Preview shows how the form will appear to users based on dates and activation</li>
                                    <li><strong>Maximum Submissions:</strong> Set to 1 for one-time forms, 0 for unlimited submissions</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-lightbulb"></i> Best Practices
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <li>Use clear, descriptive form names that users will understand</li>
                                    <li>Add helpful descriptions to explain the form's purpose</li>
                                    <li>Set appropriate dates for time-bound forms (surveys, applications)</li>
                                    <li>Use "No expiry" for permanent forms (registration, ongoing reporting)</li>
                                    <li>Test forms thoroughly before assigning to users</li>
                                    <li>Use "Save & Start Building" to immediately add form fields</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<!-- Form Code Availability Modal -->
<div class="modal fade" id="codeCheckModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Check Form Code Availability</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="checkCode" placeholder="Enter form code">
                    <button class="btn btn-outline-primary" type="button" onclick="checkCodeAvailability()">
                        Check
                    </button>
                </div>
                <div id="codeCheckResult"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Form validation
(function() {
    'use strict';
    
    const form = document.getElementById('createFormForm');
    
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

// Generate form code from form name
function generateFormCode() {
    const formName = document.getElementById('form_name').value.trim();
    if (!formName) {
        showNotification('Please enter a form name first', 'warning');
        return;
    }
    
    // Convert to lowercase and replace special characters
    let code = formName.toLowerCase()
        .replace(/[^a-z0-9\s]/g, '')  // Remove special characters
        .replace(/\s+/g, '_')         // Replace spaces with underscores
        .replace(/_+/g, '_')          // Remove duplicate underscores
        .trim('_');                   // Remove leading/trailing underscores
    
    // If code is too short, add current year
    if (code.length < 3) {
        code = 'form_' + new Date().getFullYear();
    }
    
    // Add timestamp for uniqueness
    const timestamp = Math.floor(Date.now() / 1000);
    code = code + '_' + timestamp.toString().slice(-6);
    
    // Limit length
    code = code.substring(0, 50);
    
    document.getElementById('form_code').value = code;
    validateFormCode();
}

// Suggest unique code
function suggestUniqueCode() {
    const baseCode = document.getElementById('form_code').value || 'form';
    const timestamp = Date.now();
    const random = Math.floor(Math.random() * 1000);
    const uniqueCode = baseCode.replace(/_\d+$/, '') + '_' + timestamp.toString().slice(-6) + '_' + random;
    
    document.getElementById('form_code').value = uniqueCode.substring(0, 50);
    validateFormCode();
}

// Validate form code format
function validateFormCode() {
    const codeInput = document.getElementById('form_code');
    const code = codeInput.value;
    
    if (code && !code.match(/^[a-z0-9_]+$/)) {
        codeInput.classList.add('is-invalid');
        codeInput.nextElementSibling.textContent = 'Form code can only contain lowercase letters, numbers, and underscores';
        return false;
    } else {
        codeInput.classList.remove('is-invalid');
        return true;
    }
}

// Form code validation on blur
document.getElementById('form_code').addEventListener('blur', function() {
    validateFormCode();
});

// Toggle expiry date
function toggleExpiry() {
    const noExpiryCheckbox = document.getElementById('no_expiry');
    const endDateInput = document.getElementById('end_date');
    
    if (noExpiryCheckbox.checked) {
        endDateInput.value = '';
        endDateInput.disabled = true;
    } else {
        endDateInput.disabled = false;
        if (!endDateInput.value) {
            const defaultEndDate = new Date();
            defaultEndDate.setDate(defaultEndDate.getDate() + 30);
            endDateInput.value = defaultEndDate.toISOString().split('T')[0];
        }
    }
    updateStatusPreview();
}

// Update status preview
function updateStatusPreview() {
    const isActive = document.getElementById('is_active').checked;
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const currentDate = new Date().toISOString().split('T')[0];
    
    let status = {
        label: 'Unknown',
        color: 'secondary',
        icon: 'fas fa-question-circle',
        description: 'Form status will be determined based on dates and activation'
    };
    
    if (!isActive) {
        status = {
            label: 'Inactive',
            color: 'secondary',
            icon: 'fas fa-ban',
            description: 'Form is inactive and not available to users'
        };
    } else if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const now = new Date();
        
        if (now < start) {
            status = {
                label: 'Scheduled',
                color: 'info',
                icon: 'fas fa-calendar-alt',
                description: 'Form will be available from ' + formatDate(startDate)
            };
        } else if (now >= start && now <= end) {
            status = {
                label: 'Active',
                color: 'success',
                icon: 'fas fa-check-circle',
                description: 'Form is currently active and available'
            };
        } else {
            status = {
                label: 'Expired',
                color: 'danger',
                icon: 'fas fa-clock',
                description: 'Form expired on ' + formatDate(endDate)
            };
        }
    } else if (startDate && !endDate) {
        const start = new Date(startDate);
        const now = new Date();
        
        if (now < start) {
            status = {
                label: 'Scheduled',
                color: 'info',
                icon: 'fas fa-calendar-alt',
                description: 'Form will be available from ' + formatDate(startDate)
            };
        } else {
            status = {
                label: 'Active (No Expiry)',
                color: 'success',
                icon: 'fas fa-infinity',
                description: 'Form is active with no expiry date'
            };
        }
    } else if (!startDate && endDate) {
        const end = new Date(endDate);
        const now = new Date();
        
        if (now > end) {
            status = {
                label: 'Expired',
                color: 'danger',
                icon: 'fas fa-clock',
                description: 'Form expired on ' + formatDate(endDate)
            };
        } else {
            status = {
                label: 'Active',
                color: 'success',
                icon: 'fas fa-check-circle',
                description: 'Form is active and will expire on ' + formatDate(endDate)
            };
        }
    } else {
        status = {
            label: 'Active (No Dates)',
            color: 'success',
            icon: 'fas fa-check-circle',
            description: 'Form is active with no date restrictions'
        };
    }
    
    // Update preview display
    const statusPreview = document.getElementById('statusPreview');
    statusPreview.innerHTML = `
        <span class="badge bg-${status.color} me-2 p-2">
            <i class="${status.icon}"></i>
            ${status.label}
        </span>
        <div>
            <small class="text-muted">${status.description}</small>
        </div>
    `;
}

// Format date for display
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
}

// Reset form
function resetForm() {
    if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        document.getElementById('createFormForm').reset();
        document.getElementById('createFormForm').classList.remove('was-validated');
        
        // Reset dates to defaults
        const today = new Date();
        const defaultEnd = new Date();
        defaultEnd.setDate(today.getDate() + 30);
        
        document.getElementById('start_date').value = today.toISOString().split('T')[0];
        document.getElementById('end_date').value = defaultEnd.toISOString().split('T')[0];
        document.getElementById('end_date').disabled = false;
        document.getElementById('no_expiry').checked = false;
        
        // Update status preview
        updateStatusPreview();
        
        showNotification('Form has been reset', 'info');
    }
}

// Check code availability
function checkCodeAvailability() {
    const code = document.getElementById('checkCode').value;
    const resultDiv = document.getElementById('codeCheckResult');
    
    if (!code) {
        resultDiv.innerHTML = '<div class="alert alert-warning">Please enter a form code to check</div>';
        return;
    }
    
    if (!code.match(/^[a-z0-9_]+$/)) {
        resultDiv.innerHTML = '<div class="alert alert-danger">Invalid format. Use only lowercase letters, numbers, and underscores</div>';
        return;
    }
    
    resultDiv.innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div><p>Checking...</p></div>';
    
    // AJAX call to check code
    fetch('../ajax/check_form_code.php?code=' + encodeURIComponent(code))
        .then(response => response.json())
        .then(data => {
            if (data.available) {
                resultDiv.innerHTML = `
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> Form code "${code}" is available!
                    </div>
                    <button class="btn btn-sm btn-success" onclick="useCode('${code}')">
                        <i class="fas fa-check"></i> Use This Code
                    </button>
                `;
            } else {
                resultDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle"></i> Form code "${code}" is already in use
                    </div>
                    <div class="text-muted">Suggested alternative: ${code}_${new Date().getFullYear()}_${Math.floor(Math.random() * 1000)}</div>
                `;
            }
        })
        .catch(error => {
            resultDiv.innerHTML = '<div class="alert alert-danger">Error checking code availability</div>';
            console.error('Error:', error);
        });
}

// Use selected code
function useCode(code) {
    document.getElementById('form_code').value = code;
    validateFormCode();
    $('#codeCheckModal').modal('hide');
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

// Date validation
document.getElementById('end_date').addEventListener('change', function() {
    const startDate = document.getElementById('start_date').value;
    const endDate = this.value;
    
    if (startDate && endDate) {
        if (new Date(endDate) < new Date(startDate)) {
            showNotification('End date must be after start date', 'warning');
            this.value = '';
        }
    }
    updateStatusPreview();
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(element => {
        new bootstrap.Tooltip(element);
    });
    
    // Set minimum date for start date to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('start_date').min = today;
    
    // Initialize status preview
    updateStatusPreview();
    
    // Generate initial form code if form name exists
    if (document.getElementById('form_name').value) {
        generateFormCode();
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

.form-label {
    font-weight: 500;
    color: #495057;
}

.form-text {
    color: #6c757d;
    font-size: 0.875em;
}

.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
}

.alert.position-fixed {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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

/* Status preview */
#statusPreview .badge {
    font-size: 0.9rem;
    min-width: 100px;
    text-align: center;
}

/* Date input styling */
input[type="date"] {
    font-family: inherit;
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
    
    #statusPreview {
        flex-direction: column;
        align-items: flex-start;
    }
    
    #statusPreview .badge {
        margin-bottom: 10px;
    }
}

/* Input group buttons */
.input-group .btn-outline-secondary,
.input-group .btn-outline-info {
    border-color: #dee2e6;
}

.input-group .btn-outline-secondary:hover,
.input-group .btn-outline-info:hover {
    background-color: #f8f9fa;
}

/* Help cards */
.card ul {
    padding-left: 1.2rem;
}

.card ul li {
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}
</style>

<?php include '../includes/footer.php'; ?>