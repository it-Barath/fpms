<?php
// users/gn/citizens/view_family.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set page variables
$pageTitle = "Family Details";
$pageIcon = "bi bi-eye-fill";
$pageDescription = "View family details and members";
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
    
    // Check if family ID is provided
    if (!isset($_GET['id']) || empty(trim($_GET['id']))) {
        header('Location: list_families.php?error=missing_family_id');
        exit();
    }
    
    $family_id = trim($_GET['id']);
    
    // Get family details
    $family_query = "SELECT f.*, u.username as created_by_name 
                     FROM families f
                     LEFT JOIN users u ON f.created_by = u.user_id
                     WHERE f.family_id = ? AND f.gn_id = ?";
    
    $family_stmt = $db->prepare($family_query);
    $family_stmt->bind_param("ss", $family_id, $gn_id);
    $family_stmt->execute();
    $family_result = $family_stmt->get_result();
    
    if ($family_result->num_rows === 0) {
        header('Location: list_families.php?error=family_not_found');
        exit();
    }
    
    $family = $family_result->fetch_assoc();
    
    // Get family members
    $members_query = "SELECT * FROM citizens 
                      WHERE family_id = ? 
                      ORDER BY 
                        CASE 
                            WHEN relation_to_head = 'Self' THEN 1
                            WHEN relation_to_head = 'Husband' THEN 2
                            WHEN relation_to_head = 'Wife' THEN 3
                            WHEN relation_to_head = 'Son' THEN 4
                            WHEN relation_to_head = 'Daughter' THEN 5
                            WHEN relation_to_head = 'Father' THEN 6
                            WHEN relation_to_head = 'Mother' THEN 7
                            ELSE 8
                        END,
                        date_of_birth";
    
    $members_stmt = $db->prepare($members_query);
    $members_stmt->bind_param("s", $family_id);
    $members_stmt->execute();
    $members_result = $members_stmt->get_result();
    $members = $members_result->fetch_all(MYSQLI_ASSOC);
    
    // Get GN details for display
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

    // Fetch form submissions for this family
    $submissions_query = "SELECT 
        fs.submission_id,
        fs.submission_status,
        fs.submission_date,
        fs.review_date,
        fs.review_notes,
        f.form_id,
        f.form_code,
        f.form_name,
        f.form_description,
        f.form_type,
        u.username as reviewed_by_name
    FROM form_submissions_family fs
    JOIN forms f ON fs.form_id = f.form_id
    LEFT JOIN users u ON fs.reviewed_by_user_id = u.user_id
    WHERE fs.family_id = ? AND fs.is_latest = 1
    ORDER BY fs.submission_date DESC";
    
    $submissions_stmt = $db->prepare($submissions_query);
    $submissions_stmt->bind_param("s", $family_id);
    $submissions_stmt->execute();
    $submissions_result = $submissions_stmt->get_result();
    $form_submissions = $submissions_result ? $submissions_result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Fetch assigned forms for this family (GN level)
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
        AND (f.target_entity = 'family' OR f.target_entity = 'both')
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
    
    // Fetch all available forms for families
    $family_forms_query = "SELECT * 
        FROM forms 
        WHERE is_active = 1 
        AND (target_entity = 'family' OR target_entity = 'both')
        AND (start_date IS NULL OR start_date <= NOW())
        AND (end_date IS NULL OR end_date >= NOW())
        AND form_id NOT IN (
            SELECT form_id FROM form_assignments 
            WHERE assigned_to_user_type = 'gn' 
            AND assigned_to_office_code = ?
            AND (expires_at IS NULL OR expires_at >= NOW())
        )
        ORDER BY form_name ASC";
    
    $forms_stmt = $db->prepare($family_forms_query);
    $forms_stmt->bind_param("s", $gn_id);
    $forms_stmt->execute();
    $forms_result = $forms_stmt->get_result();
    $family_forms = $forms_result ? $forms_result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Merge assigned forms with submission status
    foreach ($assigned_forms as &$form) {
        // Find submission for this form and family
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
        
        // Calculate if form is expired
        $form['is_expired'] = false;
        if ($form['expires_at'] && strtotime($form['expires_at']) < time()) {
            $form['is_expired'] = true;
        }
    }
    unset($form);

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
    error_log("View Family Error: " . $e->getMessage());
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
                    <i class="bi bi-eye-fill me-2"></i>
                    Family Details
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="list_families.php" class="btn btn-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                    <a href="add_member.php?family_id=<?php echo urlencode($family_id); ?>" class="btn btn-success me-2">
                        <i class="bi bi-person-plus"></i> Add Member
                    </a>
                    <a href="edit_family.php?id=<?php echo urlencode($family_id); ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Edit Family
                    </a>
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
                            case 'member_added': $success_msg = 'Family member added successfully.'; break;
                            case 'member_updated': $success_msg = 'Family member updated successfully.'; break;
                            case 'member_deleted': $success_msg = 'Family member deleted successfully.'; break;
                            case 'family_updated': $success_msg = 'Family details updated successfully.'; break;
                            case 'family_created': $success_msg = 'New family created successfully.'; break;
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
            
            <!-- Family Info Card -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-house-door"></i> Family Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold text-muted">Family ID</label>
                            <p class="font-monospace text-primary fs-5"><?php echo htmlspecialchars($family['family_id']); ?></p>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold text-muted">GN Division</label>
                            <p><?php echo htmlspecialchars($gn_details['GN'] ?? 'N/A'); ?></p>
                            <small class="text-muted">GN ID: <?php echo $gn_id; ?></small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold text-muted">Total Members</label>
                            <p><span class="badge bg-info fs-6"><?php echo $family['total_members']; ?> members</span></p>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold text-muted">Family Head NIC</label>
                            <p>
                                <?php if ($family['family_head_nic']): ?>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($family['family_head_nic']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Not set</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold text-muted">Family Address</label>
                            <div class="p-3 bg-light rounded">
                                <?php echo nl2br(htmlspecialchars($family['address'])); ?>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-muted">Registered By</label>
                            <p><?php echo htmlspecialchars($family['created_by_name'] ?? 'System'); ?></p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-muted">Registered On</label>
                            <p><?php echo date('d M Y, h:i A', strtotime($family['created_at'])); ?></p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-muted">Last Updated</label>
                            <p><?php echo date('d M Y, h:i A', strtotime($family['updated_at'])); ?></p>
                        </div>
                        
                        <?php if (!empty($gn_details['Division_Name']) || !empty($gn_details['District_Name'])): ?>
                        <div class="col-12 mt-2">
                            <div class="row">
                                <?php if (!empty($gn_details['Division_Name'])): ?>
                                <div class="col-md-4 mb-2">
                                    <small class="text-muted">Division:</small>
                                    <p class="mb-0"><?php echo htmlspecialchars($gn_details['Division_Name']); ?></p>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($gn_details['District_Name'])): ?>
                                <div class="col-md-4 mb-2">
                                    <small class="text-muted">District:</small>
                                    <p class="mb-0"><?php echo htmlspecialchars($gn_details['District_Name']); ?></p>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($gn_details['Province_Name'])): ?>
                                <div class="col-md-4 mb-2">
                                    <small class="text-muted">Province:</small>
                                    <p class="mb-0"><?php echo htmlspecialchars($gn_details['Province_Name']); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Family Members Card -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-people"></i> Family Members</h5>
                    <span class="badge bg-light text-dark"><?php echo count($members); ?> members</span>
                </div>
                <div class="card-body">
                    <?php if (empty($members)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-person-x display-1 text-muted"></i>
                            <h4 class="mt-3">No Members Found</h4>
                            <p class="text-muted">This family has no registered members.</p>
                            <a href="add_member.php?family_id=<?php echo urlencode($family_id); ?>" class="btn btn-success">
                                <i class="bi bi-person-plus"></i> Add First Member
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th width="50">#</th>
                                        <th width="70">Photo</th>
                                        <th>Name</th>
                                        <th>NIC/ID</th>
                                        <th>Relation</th>
                                        <th>Gender</th>
                                        <th>Age</th>
                                        <th>Marital Status</th>
                                        <th>Contact</th>
                                        <th width="120">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $index => $member): ?>
                                        <?php
                                        // Calculate age
                                        $age = '';
                                        $dob_display = 'N/A';
                                        if (!empty($member['date_of_birth'])) {
                                            try {
                                                $birthDate = new DateTime($member['date_of_birth']);
                                                $today = new DateTime();
                                                $age = $today->diff($birthDate)->y;
                                                $dob_display = date('d M Y', strtotime($member['date_of_birth']));
                                            } catch (Exception $e) {
                                                $age = 'N/A';
                                            }
                                        }
                                        
                                        // Set relation badge color
                                        $relation_class = 'bg-secondary';
                                        if ($member['relation_to_head'] === 'Self') {
                                            $relation_class = 'bg-primary';
                                        } elseif (in_array($member['relation_to_head'], ['Husband', 'Wife'])) {
                                            $relation_class = 'bg-info';
                                        } elseif (in_array($member['relation_to_head'], ['Son', 'Daughter'])) {
                                            $relation_class = 'bg-success';
                                        } elseif (in_array($member['relation_to_head'], ['Father', 'Mother'])) {
                                            $relation_class = 'bg-warning text-dark';
                                        }
                                        
                                        // Set gender badge color
                                        $gender_class = 'bg-secondary';
                                        if ($member['gender'] === 'male') {
                                            $gender_class = 'bg-primary';
                                        } elseif ($member['gender'] === 'female') {
                                            $gender_class = 'bg-danger';
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
                                        ?>
                                        <tr>
                                            <td class="text-center fw-bold"><?php echo $index + 1; ?></td>
                                            <td>
                                                <div class="position-relative">
                                                    <?php if ($profile_picture): ?>
                                                        <img src="<?php echo $profile_picture; ?>" 
                                                             class="rounded-circle border border-2 border-primary" 
                                                             alt="Profile Picture" 
                                                             style="width: 50px; height: 50px; object-fit: cover; cursor: pointer;"
                                                             onclick="viewMemberPhoto(<?php echo $member['citizen_id']; ?>)"
                                                             title="Click to view photo">
                                                        <div class="position-absolute bottom-0 end-0">
                                                            <i class="bi bi-camera-fill text-white bg-dark rounded-circle p-1" style="font-size: 10px;"></i>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="avatar-title <?php echo $gender_class; ?> text-white rounded-circle d-flex align-items-center justify-content-center"
                                                             style="width: 50px; height: 50px; cursor: pointer;"
                                                             onclick="viewMemberPhoto(<?php echo $member['citizen_id']; ?>)"
                                                             title="No profile photo - Click to view">
                                                            <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div>
                                                        <div class="fw-medium"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                                        <div class="small text-muted">
                                                            <?php echo htmlspecialchars($member['name_with_initials']); ?>
                                                            <?php if ($member['relation_to_head'] === 'Self'): ?>
                                                                <span class="badge bg-primary ms-2">Head</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="small text-muted">
                                                            <i class="bi bi-calendar"></i> <?php echo $dob_display; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($member['identification_number']): ?>
                                                    <div class="font-monospace small">
                                                        <?php echo htmlspecialchars($member['identification_number']); ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo ucfirst($member['identification_type']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $relation_class; ?>">
                                                    <?php echo htmlspecialchars($member['relation_to_head']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $gender_class; ?>">
                                                    <?php echo ucfirst($member['gender']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($age): ?>
                                                    <span class="fw-medium"><?php echo $age; ?> years</span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($member['marital_status']): ?>
                                                    <span class="badge bg-info"><?php echo ucfirst($member['marital_status']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <?php if ($member['mobile_phone']): ?>
                                                        <div><i class="bi bi-phone"></i> <?php echo htmlspecialchars($member['mobile_phone']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($member['home_phone']): ?>
                                                        <div><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($member['home_phone']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($member['email']): ?>
                                                        <div><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($member['email']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="edit_member.php?citizen_id=<?php echo $member['citizen_id']; ?>" 
                                                    class="btn btn-outline-secondary" title="Edit Member">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    
                                                    <!-- View Full Details Button -->
                                                    <a href="view_member.php?citizen_id=<?php echo $member['citizen_id']; ?>" 
                                                    class="btn btn-outline-info" title="View Full Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    
                                                    <?php if ($member['relation_to_head'] !== 'Self' && !empty($member['identification_number'])): ?>
                                                        <a href="create_family_from_member.php?citizen_id=<?php echo $member['citizen_id']; ?>&source_family_id=<?php echo $family_id; ?>" 
                                                        class="btn btn-outline-success" title="Create New Family">
                                                            <i class="bi bi-person-plus"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <button type="button" class="btn btn-outline-danger delete-member-btn" 
                                                            data-citizen-id="<?php echo $member['citizen_id']; ?>"
                                                            data-member-name="<?php echo htmlspecialchars($member['full_name']); ?>"
                                                            title="Delete Member">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Family Statistics -->
                        <div class="row mt-4">
                            <div class="col-md-3">
                                <div class="card border-0 bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title text-primary"><?php echo count($members); ?></h5>
                                        <p class="card-text text-muted">Total Members</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border-0 bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title text-success">
                                            <?php 
                                            $male_count = array_filter($members, function($m) {
                                                return $m['gender'] === 'male';
                                            });
                                            echo count($male_count);
                                            ?>
                                        </h5>
                                        <p class="card-text text-muted">Male Members</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border-0 bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title text-danger">
                                            <?php 
                                            $female_count = array_filter($members, function($m) {
                                                return $m['gender'] === 'female';
                                            });
                                            echo count($female_count);
                                            ?>
                                        </h5>
                                        <p class="card-text text-muted">Female Members</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border-0 bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title text-warning">
                                            <?php 
                                            $adult_count = 0;
                                            foreach ($members as $member) {
                                                if (!empty($member['date_of_birth'])) {
                                                    try {
                                                        $birthDate = new DateTime($member['date_of_birth']);
                                                        $today = new DateTime();
                                                        $age = $today->diff($birthDate)->y;
                                                        if ($age >= 18) {
                                                            $adult_count++;
                                                        }
                                                    } catch (Exception $e) {}
                                                }
                                            }
                                            echo $adult_count;
                                            ?>
                                        </h5>
                                        <p class="card-text text-muted">Adults (18+)</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

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
                        $total_forms = count($assigned_forms) + count($family_forms);
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
                                                                data-family-id="<?php echo $family_id; ?>">
                                                            <i class="bi bi-eye me-1"></i> View
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-sm btn-<?php echo $action_class; ?> fill-form-btn"
                                                                data-form-id="<?php echo $form['form_id']; ?>"
                                                                data-submission-id="<?php echo $form['submission_id']; ?>"
                                                                data-family-id="<?php echo $family_id; ?>"
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
                    <?php if (!empty($family_forms)): ?>
                        <h6 class="text-success mb-3 border-bottom pb-2">
                            <i class="bi bi-folder-plus me-2"></i> All Available Forms
                            <small class="text-muted">(All active forms for families)</small>
                        </h6>
                        <div class="row g-3">
                            <?php foreach ($family_forms as $form): ?>
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
                                
                                // Check if already submitted for this family
                                $submission_found = null;
                                $submission_id = null;
                                foreach ($form_submissions as $submission) {
                                    if ($submission['form_id'] == $form['form_id']) {
                                        $submission_found = $submission;
                                        $submission_id = $submission['submission_id'];
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
                                            </div>
                                        </div>
                                        <div class="card-footer bg-transparent border-top-0 text-end">
                                            <?php if ($submission_found): ?>
                                                <?php if ($submission_found['submission_status'] === 'approved'): ?>
                                                    <button type="button" class="btn btn-sm btn-success view-form-btn"
                                                            data-form-id="<?php echo $form['form_id']; ?>"
                                                            data-submission-id="<?php echo $submission_id; ?>"
                                                            data-family-id="<?php echo $family_id; ?>">
                                                        <i class="bi bi-eye me-1"></i> View
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-warning fill-form-btn"
                                                            data-form-id="<?php echo $form['form_id']; ?>"
                                                            data-submission-id="<?php echo $submission_id; ?>"
                                                            data-family-id="<?php echo $family_id; ?>"
                                                            data-action="edit">
                                                        <i class="bi bi-pencil me-1"></i>
                                                        <?php echo $submission_found['submission_status'] === 'rejected' ? 'Resubmit' : 'Continue'; ?>
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-success fill-form-btn"
                                                        data-form-id="<?php echo $form['form_id']; ?>"
                                                        data-family-id="<?php echo $family_id; ?>"
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
                    
                    <?php if (empty($assigned_forms) && empty($family_forms)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-journal-x display-4 text-muted mb-3"></i>
                            <h5 class="text-muted">No Forms Available</h5>
                            <p class="text-muted mb-4">There are no forms assigned or available for this family.</p>
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
                <p>Are you sure you want to delete member <strong id="deleteMemberName"></strong>?</p>
                <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
                <p class="text-muted small">If this is the family head, another member will need to be assigned as head.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmDeleteMemberBtn" class="btn btn-danger">Delete Member</a>
            </div>
        </div>
    </div>
</div>

<!-- Member Photo View Modal -->
<div class="modal fade" id="memberPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-person-badge me-2"></i> Member Photo
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img id="memberPhotoImg" class="img-fluid rounded border" alt="Member Photo" style="max-height: 300px;">
                <div class="mt-3">
                    <h6 id="memberPhotoName" class="mb-1"></h6>
                    <small id="memberPhotoDetails" class="text-muted"></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="viewMemberProfileBtn" class="btn btn-primary">
                    <i class="bi bi-person me-1"></i> View Profile
                </a>
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
        #memberPhotoModal .modal-dialog {
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
        const deleteMemberButtons = document.querySelectorAll('.delete-member-btn');
        const deleteMemberName = document.getElementById('deleteMemberName');
        const confirmDeleteMemberBtn = document.getElementById('confirmDeleteMemberBtn');
        
        deleteMemberButtons.forEach(button => {
            button.addEventListener('click', function() {
                const citizenId = this.getAttribute('data-citizen-id');
                const memberName = this.getAttribute('data-member-name');
                
                deleteMemberName.textContent = memberName;
                
                // Set delete URL
                confirmDeleteMemberBtn.href = `delete_member.php?citizen_id=${encodeURIComponent(citizenId)}&family_id=<?php echo urlencode($family_id); ?>`;
                
                // Show modal
                deleteMemberModal.show();
            });
        });
        
        // Member photo modal
        const memberPhotoModal = new bootstrap.Modal(document.getElementById('memberPhotoModal'));
        const memberPhotoImg = document.getElementById('memberPhotoImg');
        const memberPhotoName = document.getElementById('memberPhotoName');
        const memberPhotoDetails = document.getElementById('memberPhotoDetails');
        const viewMemberProfileBtn = document.getElementById('viewMemberProfileBtn');
        
        // Function to view member photo
        window.viewMemberPhoto = function(citizenId) {
            // Get member data from table row
            const memberRow = document.querySelector(`tr[data-citizen-id="${citizenId}"]`);
            if (!memberRow) {
                // Try to find by searching
                const rows = document.querySelectorAll('tbody tr');
                for (const row of rows) {
                    const viewBtn = row.querySelector(`a[href*="citizen_id=${citizenId}"]`);
                    if (viewBtn) {
                        memberRow = row;
                        break;
                    }
                }
            }
            
            if (memberRow) {
                const name = memberRow.querySelector('.fw-medium')?.textContent || 'Member';
                const relation = memberRow.querySelector('.badge.bg-primary, .badge.bg-secondary, .badge.bg-info, .badge.bg-success, .badge.bg-warning')?.textContent || '';
                const age = memberRow.querySelector('.fw-medium')?.nextSibling?.textContent?.trim() || '';
                
                // Set modal content
                memberPhotoName.textContent = name;
                memberPhotoDetails.textContent = `${relation}${age ? ' • ' + age : ''}`;
                viewMemberProfileBtn.href = `view_member.php?citizen_id=${citizenId}`;
                
                // Check for photo
                const photoPath = `../../../assets/uploads/members/${citizenId}/profile_thumb.jpg`;
                const fallbackPath = `../../../assets/uploads/members/${citizenId}/profile.jpg`;
                
                // Create image element
                const img = new Image();
                img.onload = function() {
                    memberPhotoImg.src = this.src;
                    memberPhotoModal.show();
                };
                img.onerror = function() {
                    // Try fallback
                    const img2 = new Image();
                    img2.onload = function() {
                        memberPhotoImg.src = this.src;
                        memberPhotoModal.show();
                    };
                    img2.onerror = function() {
                        // No photo available
                        memberPhotoImg.src = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><circle cx="50" cy="50" r="45" fill="%23f8f9fa" stroke="%236c757d" stroke-width="2"/><text x="50" y="55" text-anchor="middle" fill="%236c757d" font-size="30">?</text></svg>';
                        memberPhotoModal.show();
                    };
                    img2.src = fallbackPath;
                };
                img.src = photoPath;
            }
        };
        
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
        let currentFamilyId = '<?php echo $family_id; ?>';
        let currentSubmissionId = null;
        let currentAction = null;
        
        // Function to load form in modal
        function loadFormInModal(formId, familyId, submissionId = null, action = 'view') {
            currentFormId = formId;
            currentFamilyId = familyId;
            currentSubmissionId = submissionId;
            currentAction = action;
            
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
            
            // Load form via AJAX
            const url = new URL('/fpms/users/gn/citizens/load_form_modal.php', window.location.origin);
            url.searchParams.append('form_id', formId);
            url.searchParams.append('family_id', familyId);
            if (submissionId) {
                url.searchParams.append('submission_id', submissionId);
            }
            url.searchParams.append('action', action);
            
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
                        attachFormHandlers(formId, familyId, submissionId);
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
        function loadSavedFormData(formId, familyId, submissionId) {
            // Show loading spinner
            savedDataModalBody.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading saved data...</span>
                    </div>
                    <p class="mt-3 text-muted">Loading saved form data...</p>
                </div>
            `;
            
            // Load saved data via AJAX
            const url = new URL('/fpms/users/gn/citizens/load_saved_data.php', window.location.origin);
            url.searchParams.append('form_id', formId);
            url.searchParams.append('family_id', familyId);
            url.searchParams.append('submission_id', submissionId);
            url.searchParams.append('type', 'family');
            
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
                            loadFormInModal(formId, familyId, submissionId, 'edit');
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
        function attachFormHandlers(formId, familyId, submissionId) {
            const form = formModalBody.querySelector('form');
            if (!form) return;
            
            // Save Draft button handler
            saveDraftBtn.onclick = function() {
                submitForm(form, 'draft');
            };
            
            // Submit Form button handler
            submitFormBtn.onclick = function() {
                if (confirm('Are you sure you want to submit this form? Once submitted, it will be sent for review.')) {
                    submitForm(form, 'submitted');
                }
            };
            
            function submitForm(form, status) {
                const formData = new FormData(form);
                formData.append('status', status);
                formData.append('family_id', familyId);
                formData.append('form_id', formId);
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
                            window.location.href = window.location.pathname + '?id=' + encodeURIComponent(familyId) + '&success=' + 
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
                const familyId = this.getAttribute('data-family-id');
                const submissionId = this.getAttribute('data-submission-id');
                const action = this.getAttribute('data-action');
                
                if (submissionId && action === 'edit') {
                    loadFormInModal(formId, familyId, submissionId, 'edit');
                } else {
                    loadFormInModal(formId, familyId, null, 'new');
                }
            });
        });
        
        document.querySelectorAll('.view-form-btn').forEach(button => {
            button.addEventListener('click', function() {
                const formId = this.getAttribute('data-form-id');
                const familyId = this.getAttribute('data-family-id');
                const submissionId = this.getAttribute('data-submission-id');
                
                if (submissionId) {
                    loadSavedFormData(formId, familyId, submissionId);
                } else {
                    loadFormInModal(formId, familyId, null, 'view');
                }
            });
        });
        
        document.querySelectorAll('.edit-form-btn').forEach(button => {
            button.addEventListener('click', function() {
                const formId = this.getAttribute('data-form-id');
                const familyId = this.getAttribute('data-family-id');
                const submissionId = this.getAttribute('data-submission-id');
                
                loadFormInModal(formId, familyId, submissionId, 'edit');
            });
        });
        
        // Print functionality
        const printBtn = document.createElement('button');
        printBtn.className = 'btn btn-outline-secondary ms-2';
        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
        printBtn.title = 'Print Family Details';
        
        const toolbar = document.querySelector('.btn-toolbar');
        if (toolbar) {
            toolbar.appendChild(printBtn);
        }
        
        printBtn.addEventListener('click', function() {
            window.print();
        });
        
        // Copy Family ID to clipboard
        const familyIdElement = document.querySelector('.font-monospace.text-primary.fs-5');
        if (familyIdElement) {
            familyIdElement.style.cursor = 'pointer';
            familyIdElement.title = 'Click to copy Family ID';
            
            familyIdElement.addEventListener('click', function() {
                const textToCopy = this.textContent;
                navigator.clipboard.writeText(textToCopy).then(function() {
                    const originalText = familyIdElement.textContent;
                    familyIdElement.textContent = 'Copied!';
                    familyIdElement.classList.add('text-success');
                    
                    setTimeout(function() {
                        familyIdElement.textContent = originalText;
                        familyIdElement.classList.remove('text-success');
                    }, 1500);
                }).catch(function(err) {
                    console.error('Failed to copy: ', err);
                });
            });
        }
        
        // Table row highlighting for family head
        const tableRows = document.querySelectorAll('tbody tr');
        tableRows.forEach(row => {
            const headBadge = row.querySelector('.badge.bg-primary');
            if (headBadge && headBadge.textContent.includes('Head')) {
                row.style.backgroundColor = 'rgba(13, 110, 253, 0.05)';
                row.style.borderLeft = '4px solid #0d6efd';
            }
        });
        
        // Export to CSV button
        const exportBtn = document.createElement('button');
        exportBtn.className = 'btn btn-outline-success ms-2';
        exportBtn.innerHTML = '<i class="bi bi-download"></i> Export';
        exportBtn.title = 'Export Members to CSV';
        
        if (toolbar) {
            toolbar.appendChild(exportBtn);
        }
        
        exportBtn.addEventListener('click', function() {
            let csv = 'Photo,Name,Name with Initials,NIC/ID,Relation,Gender,Age,Marital Status,Mobile,Email\n';
            
            document.querySelectorAll('tbody tr').forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 9) {
                    const hasPhoto = cells[1].querySelector('img') ? 'Yes' : 'No';
                    const name = cells[2].querySelector('.fw-medium')?.textContent?.trim() || '';
                    const nameWithInitials = cells[2].querySelector('.small.text-muted')?.textContent?.split('\n')[0]?.trim() || '';
                    const nic = cells[3].querySelector('.font-monospace')?.textContent?.trim() || '';
                    const relation = cells[4].querySelector('.badge')?.textContent?.trim() || '';
                    const gender = cells[5].querySelector('.badge')?.textContent?.trim() || '';
                    const age = cells[6].querySelector('.fw-medium')?.textContent?.trim() || '';
                    const marital = cells[7].querySelector('.badge')?.textContent?.trim() || '';
                    const mobile = cells[8].querySelector('[class*="bi-phone"]')?.parentElement?.textContent?.replace('📱', '').trim() || '';
                    const email = cells[8].querySelector('[class*="bi-envelope"]')?.parentElement?.textContent?.replace('✉️', '').trim() || '';
                    
                    csv += `"${hasPhoto}","${name}","${nameWithInitials}","${nic}","${relation}","${gender}","${age}","${marital}","${mobile}","${email}"\n`;
                }
            });
            
            // Create download link
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'family_<?php echo $family_id; ?>_members.csv';
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
        
        // Create family button confirmation
        document.querySelectorAll('a[title="Create New Family"]').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('This will create a new family with this member as the head. Continue?')) {
                    e.preventDefault();
                }
            });
        });
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