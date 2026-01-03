<?php
// users/gn/citizens/view_member.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Member Details";
$pageIcon = "bi bi-person-badge";
$pageDescription = "View and manage member details";
$bodyClass = "bg-light";

try {
    require_once '../../../config.php';
    require_once '../../../classes/Auth.php';
    
    // Check if session is already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize Auth
    $auth = new Auth();
    
    // Check if user is logged in and has GN level access
    if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'gn') {
        header('Location: ../../../login.php');
        exit();
    }

    // Get database connection from config
    $db = getMainConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $user_id = $_SESSION['user_id'] ?? 0;
    $gn_id = $_SESSION['office_code'] ?? '';
    
    // Check if citizen ID is provided
    if (!isset($_GET['citizen_id']) || empty(trim($_GET['citizen_id']))) {
        header('Location: list_families.php?error=missing_citizen_id');
        exit();
    }
    
    $citizen_id = intval(trim($_GET['citizen_id']));
    
    // Get member details
    $member_query = "SELECT c.*, f.family_id, f.gn_id, f.address as family_address 
                     FROM citizens c
                     LEFT JOIN families f ON c.family_id = f.family_id
                     WHERE c.citizen_id = ? AND f.gn_id = ?";
    
    $member_stmt = $db->prepare($member_query);
    $member_stmt->bind_param("is", $citizen_id, $gn_id);
    $member_stmt->execute();
    $member_result = $member_stmt->get_result();
    
    if ($member_result->num_rows === 0) {
        header('Location: list_families.php?error=member_not_found');
        exit();
    }
    
    $member = $member_result->fetch_assoc();
    $family_id = $member['family_id'];
    
    // Get family details
    $family_query = "SELECT * FROM families WHERE family_id = ?";
    $family_stmt = $db->prepare($family_query);
    $family_stmt->bind_param("s", $family_id);
    $family_stmt->execute();
    $family_result = $family_stmt->get_result();
    $family = $family_result->fetch_assoc();
    
    // Get GN details
    $ref_db = getRefConnection();
    $gn_details = [];
    if ($ref_db) {
        $gn_query = "SELECT GN, Division_Name, District_Name, Province_Name 
                     FROM mobile_service.fix_work_station 
                     WHERE GN_ID = ?";
        $gn_stmt = $ref_db->prepare($gn_query);
        $gn_stmt->bind_param("s", $gn_id);
        $gn_stmt->execute();
        $gn_result = $gn_stmt->get_result();
        $gn_details = $gn_result->fetch_assoc() ?? [];
    }
    
    // Fetch form submissions for this member with completion details
    $submissions_query = "SELECT 
        fs.submission_id,
        fs.submission_status,
        fs.submission_date,
        fs.review_date,
        fs.review_notes,
        fs.total_fields,
        fs.completed_fields,
        f.form_id,
        f.form_code,
        f.form_name,
        f.form_description,
        f.form_type,
        f.target_entity,
        u.username as reviewed_by_name
    FROM form_submissions_member fs
    JOIN forms f ON fs.form_id = f.form_id
    LEFT JOIN users u ON fs.reviewed_by_user_id = u.user_id
    WHERE fs.citizen_id = ? AND fs.is_latest = 1
    ORDER BY fs.submission_date DESC";
    
    $submissions_stmt = $db->prepare($submissions_query);
    $submissions_stmt->bind_param("i", $citizen_id);
    $submissions_stmt->execute();
    $submissions_result = $submissions_stmt->get_result();
    $form_submissions = $submissions_result ? $submissions_result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Fetch assigned forms for this member (GN level)
    $assigned_forms_query = "SELECT 
        f.form_id,
        f.form_code,
        f.form_name,
        f.form_description,
        f.form_type,
        f.target_entity,
        fa.assigned_at,
        fa.expires_at,
        fa.assignment_type,
        u.username as assigned_by_name
    FROM form_assignments fa
    JOIN forms f ON fa.form_id = f.form_id
    LEFT JOIN users u ON fa.assigned_by_user_id = u.user_id
    WHERE f.is_active = 1 
        AND (f.target_entity = 'member' OR f.target_entity = 'both')
        AND fa.assigned_to_user_type = 'gn'
        AND fa.assigned_to_office_code = ?
        AND (fa.expires_at IS NULL OR fa.expires_at >= NOW())
        AND (f.start_date IS NULL OR f.start_date <= NOW())
        AND (f.end_date IS NULL OR f.end_date >= NOW())
    ORDER BY fa.assigned_at DESC";
    
    $assigned_stmt = $db->prepare($assigned_forms_query);
    $assigned_stmt->bind_param("s", $gn_id);
    $assigned_stmt->execute();
    $assigned_result = $assigned_stmt->get_result();
    $assigned_forms = $assigned_result ? $assigned_result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Fetch all available forms for members
    $member_forms_query = "SELECT * 
        FROM forms 
        WHERE is_active = 1 
        AND (target_entity = 'member' OR target_entity = 'both')
        AND (start_date IS NULL OR start_date <= NOW())
        AND (end_date IS NULL OR end_date >= NOW())
        AND form_id NOT IN (
            SELECT form_id FROM form_assignments 
            WHERE assigned_to_user_type = 'gn' 
            AND assigned_to_office_code = ?
            AND (expires_at IS NULL OR expires_at >= NOW())
        )
        ORDER BY form_name ASC";
    
    $forms_stmt = $db->prepare($member_forms_query);
    $forms_stmt->bind_param("s", $gn_id);
    $forms_stmt->execute();
    $forms_result = $forms_stmt->get_result();
    $member_forms = $forms_result ? $forms_result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Merge assigned forms with submission status
    foreach ($assigned_forms as &$form) {
        // Find submission for this form and member
        $submission_found = null;
        foreach ($form_submissions as $submission) {
            if ($submission['form_id'] == $form['form_id']) {
                $submission_found = $submission;
                break;
            }
        }
        
        $form['submission_status'] = $submission_found ? $submission_found['submission_status'] : null;
        $form['submission_date'] = $submission_found ? $submission_found['submission_date'] : null;
        $form['review_date'] = $submission_found ? $submission_found['review_date'] : null;
        $form['review_notes'] = $submission_found ? $submission_found['review_notes'] : null;
        $form['reviewed_by_name'] = $submission_found ? $submission_found['reviewed_by_name'] : null;
        $form['submission_id'] = $submission_found ? $submission_found['submission_id'] : null;
        $form['total_fields'] = $submission_found ? $submission_found['total_fields'] : 0;
        $form['completed_fields'] = $submission_found ? $submission_found['completed_fields'] : 0;
        
        // Calculate if form is expired
        $form['is_expired'] = false;
        if ($form['expires_at'] && strtotime($form['expires_at']) < time()) {
            $form['is_expired'] = true;
        }
    }
    unset($form);
    
    // Calculate age
    $age = '';
    if (!empty($member['date_of_birth'])) {
        try {
            $birthDate = new DateTime($member['date_of_birth']);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;
        } catch (Exception $e) {
            $age = 'N/A';
        }
    }
    
    // Check for profile picture
    $profile_picture = '';
    $profile_thumbnail_path = '../../../assets/uploads/members/' . $member['citizen_id'] . '/profile_thumb.jpg';
    $profile_picture_path = '../../../assets/uploads/members/' . $member['citizen_id'] . '/profile.jpg';
    
    if (file_exists($profile_thumbnail_path)) {
        $profile_picture = $profile_thumbnail_path;
    } elseif (file_exists($profile_picture_path)) {
        $profile_picture = $profile_picture_path;
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("View Member Error: " . $e->getMessage());
}
?>

<?php require_once '../../../includes/header.php'; ?>

<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include '../../../includes/sidebar.php'; ?>
        </div>

        <main class="">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-person-badge me-2"></i>
                    Member Details
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Family
                    </a>
                    <a href="edit_member.php?citizen_id=<?php echo $citizen_id; ?>" class="btn btn-primary me-2">
                        <i class="bi bi-pencil"></i> Edit Member
                    </a>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteMemberModal">
                        <i class="bi bi-trash"></i> Delete Member
                    </button>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <h5><i class="bi bi-exclamation-triangle"></i> Error</h5>
                    <p><?php echo htmlspecialchars($error); ?></p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <h5><i class="bi bi-check-circle"></i> Success!</h5>
                    <p>
                        <?php 
                        $success_msg = '';
                        switch ($_GET['success']) {
                            case 'member_updated': $success_msg = 'Member details updated successfully.'; break;
                            case 'form_submitted': $success_msg = 'Form submitted successfully.'; break;
                            case 'form_saved': $success_msg = 'Form saved as draft.'; break;
                            case 'photo_updated': $success_msg = 'Profile photo updated successfully.'; break;
                            case 'photo_removed': $success_msg = 'Profile photo removed successfully.'; break;
                            default: $success_msg = 'Operation completed successfully.';
                        }
                        echo $success_msg;
                        ?>
                    </p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Member Info Card -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-person"></i> Member Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 text-center mb-4">
                            <div class="position-relative d-inline-block">
                                <?php if ($profile_picture): ?>
                                    <img src="<?php echo $profile_picture; ?>" 
                                         class="rounded-circle border border-4 border-primary" 
                                         alt="Profile Picture" 
                                         style="width: 150px; height: 150px; object-fit: cover;"
                                         id="memberProfileImg">
                                    <div class="position-absolute bottom-0 end-0">
                                        <button class="btn btn-sm btn-dark rounded-circle p-2" 
                                                onclick="document.getElementById('profilePictureInput').click()"
                                                title="Change photo">
                                            <i class="bi bi-camera-fill"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="avatar-title bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center"
                                         style="width: 150px; height: 150px; font-size: 60px; cursor: pointer;"
                                         onclick="document.getElementById('profilePictureInput').click()">
                                        <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                        <div class="position-absolute bottom-0 end-0">
                                            <i class="bi bi-camera-fill text-white bg-dark rounded-circle p-2" style="font-size: 16px;"></i>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Hidden file input for photo upload -->
                                <input type="file" id="profilePictureInput" 
                                       accept="image/*" 
                                       style="display: none;"
                                       onchange="uploadProfilePicture(this)">
                            </div>
                            <div class="mt-3">
                                <?php if ($profile_picture): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="removeProfilePicture()">
                                        <i class="bi bi-trash"></i> Remove Photo
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-9">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold text-muted">Full Name</label>
                                    <p class="fs-5"><?php echo htmlspecialchars($member['full_name']); ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold text-muted">Name with Initials</label>
                                    <p><?php echo htmlspecialchars($member['name_with_initials']); ?></p>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">NIC/ID Number</label>
                                    <p><span class="badge bg-secondary font-monospace"><?php echo htmlspecialchars($member['identification_number']); ?></span></p>
                                    <small class="text-muted"><?php echo ucfirst($member['identification_type']); ?></small>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Date of Birth</label>
                                    <p>
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo date('d M Y', strtotime($member['date_of_birth'])); ?>
                                        <?php if ($age): ?>
                                            <span class="badge bg-info ms-2"><?php echo $age; ?> years</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Gender</label>
                                    <p>
                                        <span class="badge <?php echo $member['gender'] === 'male' ? 'bg-primary' : ($member['gender'] === 'female' ? 'bg-danger' : 'bg-secondary'); ?>">
                                            <?php echo ucfirst($member['gender']); ?>
                                        </span>
                                    </p>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Family</label>
                                    <p>
                                        <a href="view_family.php?id=<?php echo urlencode($family_id); ?>" 
                                           class="text-decoration-none">
                                            <i class="bi bi-house-door me-1"></i>
                                            <?php echo htmlspecialchars($family_id); ?>
                                        </a>
                                    </p>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Relation to Head</label>
                                    <p>
                                        <span class="badge <?php 
                                            echo $member['relation_to_head'] === 'Self' ? 'bg-primary' : 
                                                (in_array($member['relation_to_head'], ['Husband', 'Wife']) ? 'bg-info' : 
                                                (in_array($member['relation_to_head'], ['Son', 'Daughter']) ? 'bg-success' : 
                                                (in_array($member['relation_to_head'], ['Father', 'Mother']) ? 'bg-warning' : 'bg-secondary'))); 
                                        ?>">
                                            <?php echo htmlspecialchars($member['relation_to_head']); ?>
                                            <?php if ($member['relation_to_head'] === 'Self'): ?>
                                                (Head)
                                            <?php endif; ?>
                                        </span>
                                    </p>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Marital Status</label>
                                    <p>
                                        <?php if ($member['marital_status']): ?>
                                            <span class="badge bg-info"><?php echo ucfirst($member['marital_status']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <?php if ($member['ethnicity']): ?>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Ethnicity</label>
                                    <p><?php echo htmlspecialchars($member['ethnicity']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($member['religion']): ?>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Religion</label>
                                    <p><?php echo htmlspecialchars($member['religion']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Status</label>
                                    <p>
                                        <span class="badge <?php echo $member['is_alive'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $member['is_alive'] ? 'Alive' : 'Deceased'; ?>
                                        </span>
                                    </p>
                                </div>
                                
                                <!-- Contact Information -->
                                <div class="col-12 mt-3">
                                    <h6 class="border-bottom pb-2">
                                        <i class="bi bi-telephone me-2"></i> Contact Information
                                    </h6>
                                </div>
                                
                                <?php if ($member['mobile_phone']): ?>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Mobile Phone</label>
                                    <p>
                                        <i class="bi bi-phone me-1"></i>
                                        <?php echo htmlspecialchars($member['mobile_phone']); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($member['home_phone']): ?>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Home Phone</label>
                                    <p>
                                        <i class="bi bi-telephone me-1"></i>
                                        <?php echo htmlspecialchars($member['home_phone']); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($member['email']): ?>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold text-muted">Email</label>
                                    <p>
                                        <i class="bi bi-envelope me-1"></i>
                                        <?php echo htmlspecialchars($member['email']); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Address -->
                                <?php if ($member['address']): ?>
                                <div class="col-12 mt-3">
                                    <label class="form-label fw-bold text-muted">Address</label>
                                    <div class="p-3 bg-light rounded">
                                        <?php echo nl2br(htmlspecialchars($member['address'])); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Family Address -->
                                <?php if ($member['family_address']): ?>
                                <div class="col-12 mt-3">
                                    <label class="form-label fw-bold text-muted">Family Address</label>
                                    <div class="p-3 bg-light rounded">
                                        <?php echo nl2br(htmlspecialchars($member['family_address'])); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- GN Details -->
                                <?php if (!empty($gn_details)): ?>
                                <div class="col-12 mt-3">
                                    <h6 class="border-bottom pb-2">
                                        <i class="bi bi-geo-alt me-2"></i> Administrative Information
                                    </h6>
                                    <div class="row">
                                        <?php if (!empty($gn_details['GN'])): ?>
                                        <div class="col-md-3 mb-2">
                                            <small class="text-muted">GN Division:</small>
                                            <p class="mb-0"><?php echo htmlspecialchars($gn_details['GN']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($gn_details['Division_Name'])): ?>
                                        <div class="col-md-3 mb-2">
                                            <small class="text-muted">Division:</small>
                                            <p class="mb-0"><?php echo htmlspecialchars($gn_details['Division_Name']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($gn_details['District_Name'])): ?>
                                        <div class="col-md-3 mb-2">
                                            <small class="text-muted">District:</small>
                                            <p class="mb-0"><?php echo htmlspecialchars($gn_details['District_Name']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($gn_details['Province_Name'])): ?>
                                        <div class="col-md-3 mb-2">
                                            <small class="text-muted">Province:</small>
                                            <p class="mb-0"><?php echo htmlspecialchars($gn_details['Province_Name']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Timestamps -->
                                <div class="col-12 mt-3">
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label fw-bold text-muted">Created At</label>
                                            <p><?php echo date('d M Y, h:i A', strtotime($member['created_at'])); ?></p>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label fw-bold text-muted">Last Updated</label>
                                            <p><?php echo date('d M Y, h:i A', strtotime($member['updated_at'])); ?></p>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label fw-bold text-muted">GN ID</label>
                                            <p><code><?php echo htmlspecialchars($gn_id); ?></code></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filled Form Details Table -->
            <?php if (!empty($form_submissions)): ?>
            <div class="card mb-4">
                <div class="card-header bg-warning text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-list-check me-2"></i> Filled Form Details</h5>
                    <span class="badge bg-light text-dark"><?php echo count($form_submissions); ?> forms filled</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Form Name</th>
                                    <th>Status</th>
                                    <th>Submitted On</th>
                                    <th>Fields Filled</th>
                                    <th>Review Status</th>
                                    <th>Last Updated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($form_submissions as $submission): ?>
                                    <?php
                                    // Determine badge class based on status
                                    $status_class = 'secondary';
                                    switch ($submission['submission_status']) {
                                        case 'draft': $status_class = 'warning'; break;
                                        case 'submitted': $status_class = 'info'; break;
                                        case 'approved': $status_class = 'success'; break;
                                        case 'rejected': $status_class = 'danger'; break;
                                        case 'pending_review': $status_class = 'primary'; break;
                                    }
                                    
                                    // Calculate completion percentage
                                    $completion_percentage = 0;
                                    if ($submission['total_fields'] > 0) {
                                        $completion_percentage = round(($submission['completed_fields'] / $submission['total_fields']) * 100);
                                    }
                                    
                                    // Determine completion badge color
                                    $completion_class = 'danger';
                                    if ($completion_percentage >= 80) {
                                        $completion_class = 'success';
                                    } elseif ($completion_percentage >= 50) {
                                        $completion_class = 'warning';
                                    } elseif ($completion_percentage > 0) {
                                        $completion_class = 'info';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-medium"><?php echo htmlspecialchars($submission['form_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($submission['form_code']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $submission['submission_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($submission['submission_date']): ?>
                                                <?php echo date('d M Y', strtotime($submission['submission_date'])); ?>
                                                <br><small class="text-muted"><?php echo date('h:i A', strtotime($submission['submission_date'])); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">Not submitted</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1" style="height: 8px;">
                                                    <div class="progress-bar bg-<?php echo $completion_class; ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $completion_percentage; ?>%"
                                                         aria-valuenow="<?php echo $completion_percentage; ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                    </div>
                                                </div>
                                                <small class="ms-2 text-muted"><?php echo $completion_percentage; ?>%</small>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo $submission['completed_fields']; ?> of <?php echo $submission['total_fields']; ?> fields
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($submission['review_date']): ?>
                                                <span class="badge bg-info">Reviewed</span>
                                                <br><small class="text-muted"><?php echo date('d M Y', strtotime($submission['review_date'])); ?></small>
                                            <?php elseif ($submission['submission_status'] === 'submitted' || $submission['submission_status'] === 'pending_review'): ?>
                                                <span class="badge bg-warning">Pending Review</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Not Reviewed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $last_update = $submission['review_date'] ? $submission['review_date'] : 
                                                        ($submission['submission_date'] ? $submission['submission_date'] : $submission['updated_at']);
                                            ?>
                                            <?php echo date('d M Y', strtotime($last_update)); ?>
                                            <br><small class="text-muted"><?php echo date('h:i A', strtotime($last_update)); ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-info view-form-btn" 
                                                        data-form-id="<?php echo $submission['form_id']; ?>"
                                                        data-submission-id="<?php echo $submission['submission_id']; ?>"
                                                        data-citizen-id="<?php echo $citizen_id; ?>"
                                                        data-type="member"
                                                        title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($submission['submission_status'] === 'draft' || $submission['submission_status'] === 'rejected'): ?>
                                                    <button type="button" class="btn btn-outline-warning edit-form-btn" 
                                                            data-form-id="<?php echo $submission['form_id']; ?>"
                                                            data-submission-id="<?php echo $submission['submission_id']; ?>"
                                                            data-citizen-id="<?php echo $citizen_id; ?>"
                                                            data-type="member"
                                                            title="Edit Form">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($submission['submission_status'] === 'approved'): ?>
                                                    <a href="../../../forms/print_submission.php?type=member&id=<?php echo $submission['submission_id']; ?>" 
                                                       class="btn btn-outline-success" 
                                                       title="Print Form" 
                                                       target="_blank">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                <strong>Legend:</strong>
                                <span class="badge bg-success ms-2">Approved</span>
                                <span class="badge bg-info ms-1">Submitted</span>
                                <span class="badge bg-warning ms-1">Draft/Pending</span>
                            </small>
                        </div>
                        <div class="col-md-8 text-end">
                            <a href="../../../forms/my_submissions.php?citizen_id=<?php echo urlencode($citizen_id); ?>" 
                               class="btn btn-sm btn-outline-info me-2">
                                <i class="bi bi-clock-history me-1"></i> View History
                            </a>
                            <button class="btn btn-sm btn-outline-primary" onclick="exportMemberFormData()">
                                <i class="bi bi-download me-1"></i> Export All Data
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            

 <!-- Submitted Forms Section -->
            <?php if (!empty($form_submissions)): ?>
            <div class="card mb-4">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-file-check me-2"></i> Submitted Forms</h5>
                    <span class="badge bg-light text-dark"><?php echo count($form_submissions); ?> submissions</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Form Name</th>
                                    <th>Code</th>
                                    <th>Status</th>
                                    <th>Submitted On</th>
                                    <th>Reviewed On</th>
                                    <th>Reviewed By</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($form_submissions as $submission): ?>
                                    <?php
                                    // Determine badge class based on status
                                    $status_class = 'secondary';
                                    switch ($submission['submission_status']) {
                                        case 'draft': $status_class = 'warning'; break;
                                        case 'submitted': $status_class = 'info'; break;
                                        case 'approved': $status_class = 'success'; break;
                                        case 'rejected': $status_class = 'danger'; break;
                                        case 'pending_review': $status_class = 'primary'; break;
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-medium"><?php echo htmlspecialchars($submission['form_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($submission['form_description'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($submission['form_code']); ?></code>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $submission['submission_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($submission['submission_date']): ?>
                                                <?php echo date('d M Y, h:i A', strtotime($submission['submission_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($submission['review_date']): ?>
                                                <?php echo date('d M Y, h:i A', strtotime($submission['review_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($submission['reviewed_by_name']): ?>
                                                <?php echo htmlspecialchars($submission['reviewed_by_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($submission['review_notes']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars(substr($submission['review_notes'], 0, 50)); ?>...</small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-info view-form-btn" 
                                                        data-form-id="<?php echo $submission['form_id']; ?>"
                                                        data-submission-id="<?php echo $submission['submission_id']; ?>"
                                                        data-family-id="<?php echo $family_id; ?>"
                                                        title="View Submission">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($submission['submission_status'] === 'draft' || $submission['submission_status'] === 'rejected'): ?>
                                                    <button type="button" class="btn btn-outline-warning edit-form-btn" 
                                                            data-form-id="<?php echo $submission['form_id']; ?>"
                                                            data-submission-id="<?php echo $submission['submission_id']; ?>"
                                                            data-family-id="<?php echo $family_id; ?>"
                                                            title="Edit Submission">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="../../../forms/my_submissions.php?family_id=<?php echo urlencode($family_id); ?>" 
                       class="btn btn-outline-info">
                        <i class="bi bi-clock-history me-1"></i>
                        View All Submissions History
                    </a>
                </div>
            </div>
            <?php endif; ?>












            <!-- Available Forms Section -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i> Available Forms</h5>
                    <span class="badge bg-light text-dark">
                        <?php 
                        $total_forms = count($assigned_forms) + count($member_forms);
                        echo $total_forms > 0 ? $total_forms . ' forms' : 'No forms';
                        ?>
                    </span>
                </div>
                <div class="card-body">
                    <!-- Assigned Forms Section -->
                    <?php if (!empty($assigned_forms)): ?>
                        <h6 class="text-primary mb-3 border-bottom pb-2">
                            <i class="bi bi-person-check me-2"></i> Assigned Forms
                            <small class="text-muted">(Directly assigned to your GN office)</small>
                        </h6>
                        <div class="row g-3 mb-4">
                            <?php foreach ($assigned_forms as $form): ?>
                                <?php
                                // Determine badge class based on status
                                $status_class = 'secondary';
                                $status_text = 'Not Started';
                                $action_text = 'Start Form';
                                $action_class = 'primary';
                                
                                if ($form['submission_status']) {
                                    switch ($form['submission_status']) {
                                        case 'draft':
                                            $status_class = 'warning';
                                            $status_text = 'In Progress';
                                            $action_text = 'Continue';
                                            $action_class = 'warning';
                                            break;
                                        case 'submitted':
                                            $status_class = 'info';
                                            $status_text = 'Submitted';
                                            $action_text = 'View';
                                            $action_class = 'info';
                                            break;
                                        case 'approved':
                                            $status_class = 'success';
                                            $status_text = 'Approved';
                                            $action_text = 'View';
                                            $action_class = 'success';
                                            break;
                                        case 'rejected':
                                            $status_class = 'danger';
                                            $status_text = 'Rejected';
                                            $action_text = 'Resubmit';
                                            $action_class = 'danger';
                                            break;
                                        case 'pending_review':
                                            $status_class = 'primary';
                                            $status_text = 'Pending Review';
                                            $action_text = 'View';
                                            $action_class = 'primary';
                                            break;
                                    }
                                }
                                
                                // Check if expired
                                if ($form['is_expired']) {
                                    $status_class = 'danger';
                                    $status_text = 'Expired';
                                    $action_class = 'secondary';
                                    $action_text = 'Expired';
                                }
                                
                                // Calculate completion percentage
                                $completion_percentage = 0;
                                if ($form['total_fields'] > 0) {
                                    $completion_percentage = round(($form['completed_fields'] / $form['total_fields']) * 100);
                                }
                                ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card h-100 border <?php echo $form['is_expired'] ? 'border-danger' : ''; ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title mb-0">
                                                    <i class="bi bi-file-earmark-text text-primary me-2"></i>
                                                    <?php echo htmlspecialchars($form['form_name']); ?>
                                                </h6>
                                                <span class="badge bg-<?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </div>
                                            <p class="card-text small text-muted mb-2">
                                                <?php echo htmlspecialchars($form['form_description'] ?? 'No description'); ?>
                                            </p>
                                            
                                            <div class="small text-muted mb-3">
                                                <div><i class="bi bi-code"></i> Code: <?php echo htmlspecialchars($form['form_code']); ?></div>
                                                <div><i class="bi bi-calendar-check"></i> Assigned: <?php echo date('d M Y', strtotime($form['assigned_at'])); ?></div>
                                                <?php if ($form['assigned_by_name']): ?>
                                                    <div><i class="bi bi-person"></i> By: <?php echo htmlspecialchars($form['assigned_by_name']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($form['expires_at']): ?>
                                                    <div class="<?php echo $form['is_expired'] ? 'text-danger' : 'text-warning'; ?>">
                                                        <i class="bi bi-clock"></i> Expires: <?php echo date('d M Y', strtotime($form['expires_at'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($form['submission_date']): ?>
                                                    <div class="text-info">
                                                        <i class="bi bi-calendar-event"></i> Last: <?php echo date('d M Y', strtotime($form['submission_date'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($form['submission_status'] && $form['total_fields'] > 0): ?>
                                                    <div class="mt-2">
                                                        <small>Completion: <?php echo $completion_percentage; ?>%</small>
                                                        <div class="progress" style="height: 5px;">
                                                            <div class="progress-bar bg-<?php echo $completion_percentage >= 80 ? 'success' : ($completion_percentage >= 50 ? 'warning' : 'info'); ?>" 
                                                                 style="width: <?php echo $completion_percentage; ?>%"></div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="card-footer bg-transparent border-top-0">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <?php if ($form['assignment_type']): ?>
                                                        <span class="badge bg-light text-dark"><?php echo ucfirst($form['assignment_type']); ?></span>
                                                    <?php endif; ?>
                                                </small>
                                                <?php if (!$form['is_expired']): ?>
                                                    <?php if ($form['submission_status'] === 'approved'): ?>
                                                        <button type="button" class="btn btn-sm btn-success view-form-btn"
                                                                data-form-id="<?php echo $form['form_id']; ?>"
                                                                data-submission-id="<?php echo $form['submission_id']; ?>"
                                                                data-citizen-id="<?php echo $citizen_id; ?>"
                                                                data-type="member">
                                                            <i class="bi bi-eye me-1"></i> View
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-sm btn-<?php echo $action_class; ?> fill-form-btn"
                                                                data-form-id="<?php echo $form['form_id']; ?>"
                                                                data-submission-id="<?php echo $form['submission_id']; ?>"
                                                                data-citizen-id="<?php echo $citizen_id; ?>"
                                                                data-type="member"
                                                                data-action="<?php echo $form['submission_status'] ? 'edit' : 'new'; ?>">
                                                            <i class="bi bi-pencil-square me-1"></i>
                                                            <?php echo $action_text; ?>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-secondary" disabled>
                                                        <i class="bi bi-clock-history me-1"></i> Expired
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- General Available Forms Section -->
                    <?php if (!empty($member_forms)): ?>
                        <h6 class="text-success mb-3 border-bottom pb-2">
                            <i class="bi bi-folder-plus me-2"></i> All Available Forms
                            <small class="text-muted">(All active forms for members)</small>
                        </h6>
                        <div class="row g-3">
                            <?php foreach ($member_forms as $form): ?>
                                <?php
                                // Check if this form is already assigned
                                $is_assigned = false;
                                foreach ($assigned_forms as $assigned) {
                                    if ($assigned['form_id'] == $form['form_id']) {
                                        $is_assigned = true;
                                        break;
                                    }
                                }
                                
                                // Skip if already assigned (shown in assigned section)
                                if ($is_assigned) continue;
                                
                                // Check if already submitted for this member
                                $submission_found = null;
                                $submission_id = null;
                                $completion_percentage = 0;
                                foreach ($form_submissions as $submission) {
                                    if ($submission['form_id'] == $form['form_id']) {
                                        $submission_found = $submission;
                                        $submission_id = $submission['submission_id'];
                                        if ($submission['total_fields'] > 0) {
                                            $completion_percentage = round(($submission['completed_fields'] / $submission['total_fields']) * 100);
                                        }
                                        break;
                                    }
                                }
                                ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card h-100 border-success border-2">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title text-success mb-0">
                                                    <i class="bi bi-plus-circle me-2"></i>
                                                    <?php echo htmlspecialchars($form['form_name']); ?>
                                                </h6>
                                                <?php if ($submission_found): ?>
                                                    <?php
                                                    $status_class = 'secondary';
                                                    switch ($submission_found['submission_status']) {
                                                        case 'draft': $status_class = 'warning'; break;
                                                        case 'submitted': $status_class = 'info'; break;
                                                        case 'approved': $status_class = 'success'; break;
                                                        case 'rejected': $status_class = 'danger'; break;
                                                        case 'pending_review': $status_class = 'primary'; break;
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $submission_found['submission_status'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="card-text small text-muted mb-3">
                                                <?php echo htmlspecialchars($form['form_description'] ?? 'No description available'); ?>
                                            </p>
                                            
                                            <div class="small text-muted mb-2">
                                                <div><i class="bi bi-code"></i> Code: <?php echo htmlspecialchars($form['form_code']); ?></div>
                                                <div><i class="bi bi-calendar-range"></i> Type: <?php echo ucfirst($form['target_entity']); ?></div>
                                                <?php if ($form['start_date'] || $form['end_date']): ?>
                                                    <div><i class="bi bi-calendar"></i> Period: 
                                                        <?php echo $form['start_date'] ? date('d M Y', strtotime($form['start_date'])) : 'Open'; ?>
                                                        to
                                                        <?php echo $form['end_date'] ? date('d M Y', strtotime($form['end_date'])) : 'Open'; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($submission_found && $submission_found['total_fields'] > 0): ?>
                                                    <div class="mt-2">
                                                        <small>Completion: <?php echo $completion_percentage; ?>%</small>
                                                        <div class="progress" style="height: 5px;">
                                                            <div class="progress-bar bg-<?php echo $completion_percentage >= 80 ? 'success' : ($completion_percentage >= 50 ? 'warning' : 'info'); ?>" 
                                                                 style="width: <?php echo $completion_percentage; ?>%"></div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="card-footer bg-transparent border-top-0 text-end">
                                            <?php if ($submission_found): ?>
                                                <?php if ($submission_found['submission_status'] === 'approved'): ?>
                                                    <button type="button" class="btn btn-sm btn-success view-form-btn"
                                                            data-form-id="<?php echo $form['form_id']; ?>"
                                                            data-submission-id="<?php echo $submission_id; ?>"
                                                            data-citizen-id="<?php echo $citizen_id; ?>"
                                                            data-type="member">
                                                        <i class="bi bi-eye me-1"></i> View
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-warning fill-form-btn"
                                                            data-form-id="<?php echo $form['form_id']; ?>"
                                                            data-submission-id="<?php echo $submission_id; ?>"
                                                            data-citizen-id="<?php echo $citizen_id; ?>"
                                                            data-type="member"
                                                            data-action="edit">
                                                        <i class="bi bi-pencil me-1"></i>
                                                        <?php echo $submission_found['submission_status'] === 'rejected' ? 'Resubmit' : 'Continue'; ?>
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-success fill-form-btn"
                                                        data-form-id="<?php echo $form['form_id']; ?>"
                                                        data-citizen-id="<?php echo $citizen_id; ?>"
                                                        data-type="member"
                                                        data-action="new">
                                                    <i class="bi bi-pencil-square me-1"></i>
                                                    Fill Form
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (empty($assigned_forms) && empty($member_forms)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-journal-x display-4 text-muted mb-3"></i>
                            <h5 class="text-muted">No Forms Available</h5>
                            <p class="text-muted mb-4">There are no forms assigned or available for this member.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Delete Member Confirmation Modal -->
<div class="modal fade" id="deleteMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle"></i> Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete member <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>?</p>
                <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
                <p class="text-muted small">
                    Family: <?php echo htmlspecialchars($family_id); ?><br>
                    NIC: <?php echo htmlspecialchars($member['identification_number']); ?>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="delete_member.php?citizen_id=<?php echo $citizen_id; ?>&family_id=<?php echo urlencode($family_id); ?>" 
                   class="btn btn-danger">Delete Member</a>
            </div>
        </div>
    </div>
</div>

<!-- Form Modal -->
<div class="modal fade" id="formModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="formModalTitle">
                    <i class="bi bi-file-text me-2"></i> Fill Form
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="formModalBody">
                <!-- Form content will be loaded here -->
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading form...</span>
                    </div>
                    <p class="mt-3 text-muted">Loading form...</p>
                </div>
            </div>
            <div class="modal-footer bg-light" id="formModalFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="saveDraftBtn" style="display: none;">
                    <i class="bi bi-save me-1"></i> Save Draft
                </button>
                <button type="button" class="btn btn-success" id="submitFormBtn" style="display: none;">
                    <i class="bi bi-check-circle me-1"></i> Submit Form
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Saved Form Data Modal -->
<div class="modal fade" id="savedDataModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-check me-2"></i> Saved Form Data
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="savedDataModalBody">
                <!-- Saved form data will be loaded here -->
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="editSavedFormBtn">
                    <i class="bi bi-pencil me-1"></i> Edit
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .avatar-sm {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .avatar-title {
        font-weight: 600;
        font-size: 16px;
    }
    .table th {
        font-weight: 600;
        color: #495057;
        border-bottom: 2px solid #dee2e6;
        background-color: #f8f9fa;
    }
    .table-hover tbody tr:hover {
        background-color: rgba(0, 123, 255, 0.05);
    }
    .badge {
        font-weight: 500;
    }
    .form-label.fw-bold.text-muted {
        color: #6c757d !important;
        font-size: 0.875rem;
        margin-bottom: 0.25rem;
    }
    .card-header h5 {
        display: flex;
        align-items: center;
    }
    .card-header h5 i {
        margin-right: 8px;
    }
    .rounded-circle {
        border-radius: 50% !important;
    }
    .btn-outline-success {
        color: #198754;
        border-color: #198754;
    }  
    .btn-outline-success:hover {
        background-color: #198754;
        color: white;
    }
    .card-title .bi {
        font-size: 1.1em;
    }
    .badge.bg-warning {
        color: #000;
    }
    .card[href]:hover {
        transform: translateY(-5px);
        transition: transform 0.2s ease;
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
    .border-success {
        border-color: #198754 !important;
    }
    .border-danger {
        border-color: #dc3545 !important;
    }
    .small.text-muted div {
        margin-bottom: 3px;
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
    }
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
    .progress {
        background-color: #e9ecef;
    }
    .progress-bar {
        transition: width 0.6s ease;
    }
    @media (max-width: 768px) {
        .table-responsive {
            font-size: 14px;
        }
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        .avatar-sm {
            width: 36px;
            height: 36px;
            font-size: 14px;
        }
        .card .row > div {
            margin-bottom: 1rem;
        }
        .card-footer .btn {
            font-size: 0.875rem;
            padding: 0.375rem 0.75rem;
        }
        .card-body .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        #formModal .modal-dialog {
            margin: 0.5rem;
        }
    }
    @media print {
        .btn-toolbar, .btn-group, button, .modal {
            display: none !important;
        }
        .card {
            border: 1px solid #dee2e6;
        }
        .badge {
            border: 1px solid #000;
            color: #000 !important;
            background-color: transparent !important;
        }
    }
</style>

<script src="../../../assets/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Delete member modal
        const deleteMemberModal = new bootstrap.Modal(document.getElementById('deleteMemberModal'));
        
        // Form modals
        const formModal = new bootstrap.Modal(document.getElementById('formModal'));
        const savedDataModal = new bootstrap.Modal(document.getElementById('savedDataModal'));
        const formModalBody = document.getElementById('formModalBody');
        const formModalTitle = document.getElementById('formModalTitle');
        const savedDataModalBody = document.getElementById('savedDataModalBody');
        const saveDraftBtn = document.getElementById('saveDraftBtn');
        const submitFormBtn = document.getElementById('submitFormBtn');
        const editSavedFormBtn = document.getElementById('editSavedFormBtn');
        
        let currentFormId = null;
        let currentCitizenId = <?php echo $citizen_id; ?>;
        let currentFamilyId = '<?php echo $family_id; ?>';
        let currentSubmissionId = null;
        let currentAction = null;
        let currentType = 'member'; // Always member for this page
        
        // Profile picture upload
        window.uploadProfilePicture = function(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const maxSize = 5 * 1024 * 1024; // 5MB
                
                if (file.size > maxSize) {
                    alert('File size must be less than 5MB');
                    return;
                }
                
                const formData = new FormData();
                formData.append('citizen_id', <?php echo $citizen_id; ?>);
                formData.append('profile_picture', file);
                formData.append('action', 'upload');
                
                // Show loading
                const originalImg = document.getElementById('memberProfileImg');
                if (originalImg) {
                    originalImg.style.opacity = '0.5';
                }
                
                fetch('upload_profile_picture.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error uploading photo');
                        if (originalImg) {
                            originalImg.style.opacity = '1';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error uploading photo');
                    if (originalImg) {
                        originalImg.style.opacity = '1';
                    }
                });
            }
        };
        
        // Remove profile picture
        window.removeProfilePicture = function() {
            if (confirm('Are you sure you want to remove the profile picture?')) {
                const formData = new FormData();
                formData.append('citizen_id', <?php echo $citizen_id; ?>);
                formData.append('action', 'remove');
                
                fetch('remove_profile_picture.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Error removing photo');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error removing photo');
                });
            }
        };
        
        // Function to load form in modal
// Function to load form in modal - FIXED URL
function loadFormInModal(formId, citizenId, familyId, submissionId = null, action = 'view', type = 'member') {
    currentFormId = formId;
    currentCitizenId = citizenId;
    currentFamilyId = familyId;
    currentSubmissionId = submissionId;
    currentAction = action;
    currentType = type;
    
    // Update modal title
    if (action === 'view') {
        formModalTitle.innerHTML = '<i class="bi bi-eye me-2"></i> View Form Submission';
    } else if (action === 'edit') {
        formModalTitle.innerHTML = '<i class="bi bi-pencil me-2"></i> Edit Form';
    } else {
        formModalTitle.innerHTML = '<i class="bi bi-file-text me-2"></i> Fill Form';
    }
    
    // Show loading spinner
    formModalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading form...</span>
            </div>
            <p class="mt-3 text-muted">Loading form...</p>
        </div>
    `;
    
    // Show/hide buttons
    saveDraftBtn.style.display = action === 'view' ? 'none' : 'block';
    submitFormBtn.style.display = action === 'view' ? 'none' : 'block';
    
    // Load form via AJAX - Use the existing load_form_modal.php
    const url = new URL('/fpms/users/gn/citizens/load_form_modal.php', window.location.origin);
    url.searchParams.append('form_id', formId);
    url.searchParams.append('citizen_id', citizenId);
    url.searchParams.append('family_id', familyId);
    if (submissionId) {
        url.searchParams.append('submission_id', submissionId);
    }
    url.searchParams.append('action', action);
    url.searchParams.append('target', 'member');
    
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(html => {
            formModalBody.innerHTML = html;
            
            // Attach form submission handlers
            if (action !== 'view') {
                attachFormHandlers(formId, citizenId, familyId, submissionId, type);
            }
        })
        .catch(error => {
            console.error('Error loading form:', error);
            formModalBody.innerHTML = `
                <div class="alert alert-danger">
                    <h5><i class="bi bi-exclamation-triangle"></i> Error Loading Form</h5>
                    <p>Unable to load the form. Please try again.</p>
                    <p class="small text-muted">Error: ${error.message}</p>
                </div>
            `;
        });
    
    // Show modal
    formModal.show();
}
        
        // Function to load saved form data
 // Function to load saved form data - FIXED URL
function loadSavedFormData(formId, citizenId, familyId, submissionId, type = 'member') {
    // Show loading spinner
    savedDataModalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading saved data...</span>
            </div>
            <p class="mt-3 text-muted">Loading saved form data...</p>
        </div>
    `;
    
    // Load saved data via AJAX - Use the existing load_saved_data.php
    const url = new URL('load_saved_data.php', window.location.origin);
    url.searchParams.append('form_id', formId);
    url.searchParams.append('citizen_id', citizenId);
    url.searchParams.append('family_id', familyId);
    url.searchParams.append('submission_id', submissionId);
    url.searchParams.append('target', 'member');
    
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(html => {
            savedDataModalBody.innerHTML = html;
            
            // Set edit button action
            editSavedFormBtn.onclick = function() {
                savedDataModal.hide();
                setTimeout(() => {
                    loadFormInModal(formId, citizenId, familyId, submissionId, 'edit', type);
                }, 300);
            };
        })
        .catch(error => {
            console.error('Error loading saved data:', error);
            savedDataModalBody.innerHTML = `
                <div class="alert alert-danger">
                    <h5><i class="bi bi-exclamation-triangle"></i> Error Loading Saved Data</h5>
                    <p>Unable to load the saved form data.</p>
                    <p class="small text-muted">Error: ${error.message}</p>
                </div>
            `;
            editSavedFormBtn.style.display = 'none';
        });
    
    // Show modal
    savedDataModal.show();
}
        
        // Function to attach form submission handlers
        function attachFormHandlers(formId, citizenId, familyId, submissionId, type) {
            const form = formModalBody.querySelector('form');
            if (!form) return;
            
            // Save Draft button handler
            saveDraftBtn.onclick = function() {
                submitForm(form, 'draft', formId, citizenId, familyId, submissionId, type);
            };
            
            // Submit Form button handler
            submitFormBtn.onclick = function() {
                if (confirm('Are you sure you want to submit this form? Once submitted, it will be sent for review.')) {
                    submitForm(form, 'submitted', formId, citizenId, familyId, submissionId, type);
                }
            };
            
            function submitForm(form, status, formId, citizenId, familyId, submissionId, type) {
                const formData = new FormData(form);
                formData.append('status', status);
                formData.append('form_id', formId);
                formData.append('type', type);
                
                if (type === 'member') {
                    formData.append('citizen_id', citizenId);
                } else {
                    formData.append('family_id', familyId);
                }
                
                if (submissionId) {
                    formData.append('submission_id', submissionId);
                }
                
                // Disable buttons during submission
                saveDraftBtn.disabled = true;
                submitFormBtn.disabled = true;
                saveDraftBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                submitFormBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Submitting...';
                
                fetch('/fpms/users/gn/citizens/submit_form_modal.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Show success message
                        const alert = document.createElement('div');
                        alert.className = 'alert alert-success alert-dismissible fade show';
                        alert.innerHTML = `
                            <h5><i class="bi bi-check-circle"></i> Success!</h5>
                            <p>${data.message}</p>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        
                        // Insert at top of form
                        formModalBody.insertBefore(alert, formModalBody.firstChild);
                        
                        // Close modal after delay
                        setTimeout(() => {
                            formModal.hide();
                            // Refresh page to show updated form status
                            window.location.href = window.location.pathname + '?citizen_id=' + citizenId + '&success=' + 
                                (status === 'draft' ? 'form_saved' : 'form_submitted');
                        }, 1500);
                    } else {
                        throw new Error(data.message || 'Submission failed');
                    }
                })
                .catch(error => {
                    console.error('Error submitting form:', error);
                    
                    const alert = document.createElement('div');
                    alert.className = 'alert alert-danger alert-dismissible fade show';
                    alert.innerHTML = `
                        <h5><i class="bi bi-exclamation-triangle"></i> Submission Error</h5>
                        <p>${error.message}</p>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    
                    formModalBody.insertBefore(alert, formModalBody.firstChild);
                    
                    // Re-enable buttons
                    saveDraftBtn.disabled = false;
                    submitFormBtn.disabled = false;
                    saveDraftBtn.innerHTML = '<i class="bi bi-save me-1"></i> Save Draft';
                    submitFormBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Submit Form';
                });
            }
        }
        
        // Attach click handlers to form buttons
        document.querySelectorAll('.fill-form-btn').forEach(button => {
            button.addEventListener('click', function() {
                const formId = this.getAttribute('data-form-id');
                const citizenId = this.getAttribute('data-citizen-id');
                const submissionId = this.getAttribute('data-submission-id');
                const action = this.getAttribute('data-action');
                const type = this.getAttribute('data-type') || 'member';
                
                const familyId = '<?php echo $family_id; ?>';
                
                if (submissionId && action === 'edit') {
                    loadFormInModal(formId, citizenId, familyId, submissionId, 'edit', type);
                } else {
                    loadFormInModal(formId, citizenId, familyId, null, 'new', type);
                }
            });
        });
        
        document.querySelectorAll('.view-form-btn').forEach(button => {
            button.addEventListener('click', function() {
                const formId = this.getAttribute('data-form-id');
                const citizenId = this.getAttribute('data-citizen-id');
                const submissionId = this.getAttribute('data-submission-id');
                const type = this.getAttribute('data-type') || 'member';
                
                const familyId = '<?php echo $family_id; ?>';
                
                if (submissionId) {
                    loadSavedFormData(formId, citizenId, familyId, submissionId, type);
                } else {
                    loadFormInModal(formId, citizenId, familyId, null, 'view', type);
                }
            });
        });
        
        document.querySelectorAll('.edit-form-btn').forEach(button => {
            button.addEventListener('click', function() {
                const formId = this.getAttribute('data-form-id');
                const citizenId = this.getAttribute('data-citizen-id');
                const submissionId = this.getAttribute('data-submission-id');
                const type = this.getAttribute('data-type') || 'member';
                
                const familyId = '<?php echo $family_id; ?>';
                
                loadFormInModal(formId, citizenId, familyId, submissionId, 'edit', type);
            });
        });
        
        // Print functionality
        const printBtn = document.createElement('button');
        printBtn.className = 'btn btn-outline-secondary ms-2';
        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
        printBtn.title = 'Print Member Details';
        
        const toolbar = document.querySelector('.btn-toolbar');
        if (toolbar) {
            toolbar.appendChild(printBtn);
        }
        
        printBtn.addEventListener('click', function() {
            window.print();
        });
        
        // Copy NIC to clipboard
        const nicElement = document.querySelector('.badge.bg-secondary.font-monospace');
        if (nicElement) {
            nicElement.style.cursor = 'pointer';
            nicElement.title = 'Click to copy NIC';
            
            nicElement.addEventListener('click', function() {
                const textToCopy = this.textContent;
                navigator.clipboard.writeText(textToCopy).then(function() {
                    const originalText = nicElement.textContent;
                    nicElement.textContent = 'Copied!';
                    nicElement.classList.add('text-success');
                    
                    setTimeout(function() {
                        nicElement.textContent = originalText;
                        nicElement.classList.remove('text-success');
                    }, 1500);
                }).catch(function(err) {
                    console.error('Failed to copy: ', err);
                });
            });
        }
        
        // Export member form data to CSV
        window.exportMemberFormData = function() {
            const data = [];
            
            // Add header
            data.push(['Form Name', 'Code', 'Status', 'Submitted Date', 'Completed Fields', 'Total Fields', 'Completion %', 'Review Status', 'Review Date', 'Reviewed By', 'Notes']);
            
            // Add rows
            <?php foreach ($form_submissions as $submission): ?>
                data.push([
                    '<?php echo addslashes($submission['form_name']); ?>',
                    '<?php echo addslashes($submission['form_code']); ?>',
                    '<?php echo addslashes($submission['submission_status']); ?>',
                    '<?php echo addslashes($submission['submission_date'] ? date('Y-m-d H:i:s', strtotime($submission['submission_date'])) : ''); ?>',
                    '<?php echo $submission['completed_fields']; ?>',
                    '<?php echo $submission['total_fields']; ?>',
                    '<?php echo round(($submission['completed_fields'] / max($submission['total_fields'], 1)) * 100); ?>%',
                    '<?php echo $submission['review_date'] ? 'Reviewed' : ($submission['submission_status'] === 'submitted' ? 'Pending' : 'Not Reviewed'); ?>',
                    '<?php echo addslashes($submission['review_date'] ? date('Y-m-d H:i:s', strtotime($submission['review_date'])) : ''); ?>',
                    '<?php echo addslashes($submission['reviewed_by_name'] ?? ''); ?>',
                    '<?php echo addslashes(substr($submission['review_notes'] ?? '', 0, 100)); ?>'
                ]);
            <?php endforeach; ?>
            
            // Convert to CSV
            const csv = data.map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
            
            // Create download link
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'member_<?php echo $citizen_id; ?>_form_data_<?php echo date('Ymd_His'); ?>.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        };
        
        // Export member details to CSV
        const exportBtn = document.createElement('button');
        exportBtn.className = 'btn btn-outline-success ms-2';
        exportBtn.innerHTML = '<i class="bi bi-download"></i> Export';
        exportBtn.title = 'Export Member Details';
        
        if (toolbar) {
            toolbar.appendChild(exportBtn);
        }
        
        exportBtn.addEventListener('click', function() {
            const memberData = {
                'Name': '<?php echo addslashes($member['full_name']); ?>',
                'Name with Initials': '<?php echo addslashes($member['name_with_initials']); ?>',
                'NIC/ID': '<?php echo addslashes($member['identification_number']); ?>',
                'ID Type': '<?php echo addslashes($member['identification_type']); ?>',
                'Date of Birth': '<?php echo addslashes($member['date_of_birth']); ?>',
                'Age': '<?php echo $age; ?>',
                'Gender': '<?php echo addslashes($member['gender']); ?>',
                'Family ID': '<?php echo addslashes($family_id); ?>',
                'Relation to Head': '<?php echo addslashes($member['relation_to_head']); ?>',
                'Marital Status': '<?php echo addslashes($member['marital_status']); ?>',
                'Ethnicity': '<?php echo addslashes($member['ethnicity']); ?>',
                'Religion': '<?php echo addslashes($member['religion']); ?>',
                'Mobile Phone': '<?php echo addslashes($member['mobile_phone']); ?>',
                'Home Phone': '<?php echo addslashes($member['home_phone']); ?>',
                'Email': '<?php echo addslashes($member['email']); ?>',
                'Address': '<?php echo addslashes(str_replace(["\r", "\n"], ' ', $member['address'])); ?>',
                'Family Address': '<?php echo addslashes(str_replace(["\r", "\n"], ' ', $member['family_address'])); ?>',
                'GN Division': '<?php echo addslashes($gn_details['GN'] ?? ''); ?>',
                'Division': '<?php echo addslashes($gn_details['Division_Name'] ?? ''); ?>',
                'District': '<?php echo addslashes($gn_details['District_Name'] ?? ''); ?>',
                'Province': '<?php echo addslashes($gn_details['Province_Name'] ?? ''); ?>',
                'Status': '<?php echo $member['is_alive'] ? 'Alive' : 'Deceased'; ?>',
                'Created At': '<?php echo addslashes($member['created_at']); ?>',
                'Updated At': '<?php echo addslashes($member['updated_at']); ?>'
            };
            
            let csv = '';
            
            // Add headers
            csv += Object.keys(memberData).join(',') + '\n';
            
            // Add values
            csv += Object.values(memberData).map(value => `"${value}"`).join(',') + '\n';
            
            // Create download link
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'member_<?php echo $citizen_id; ?>_<?php echo addslashes($member['identification_number']); ?>.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        });
        
        // Auto-refresh page after 5 minutes of inactivity
        let lastActivity = Date.now();
        const refreshTime = 5 * 60 * 1000;
        
        function updateActivity() {
            lastActivity = Date.now();
        }
        
        ['click', 'keypress', 'scroll', 'mousemove'].forEach(event => {
            document.addEventListener(event, updateActivity);
        });
        
        setInterval(function() {
            if (Date.now() - lastActivity > refreshTime) {
                location.reload();
            }
        }, 60000);
    });
</script>

<?php 
$footer_path = '../../../includes/footer.php';
if (file_exists($footer_path)) {
    include $footer_path;
} else {
    echo '</body></html>';
}
?>