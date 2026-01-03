<?php
// users/gn/citizens/submit_form_modal_member.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

require_once '../../../config.php';
require_once '../../../classes/Auth.php';

// Check session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Get database connection
$db = getMainConnection();
if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get POST data
$form_id = $_POST['form_id'] ?? 0;
$citizen_id = $_POST['citizen_id'] ?? 0;
$family_id = $_POST['family_id'] ?? '';
$submission_id = $_POST['submission_id'] ?? 0;
$status = $_POST['status'] ?? 'draft';
$user_id = $_SESSION['user_id'] ?? 0;
$gn_id = $_SESSION['office_code'] ?? '';

// Validate parameters
if (empty($form_id) || empty($citizen_id) || empty($family_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Verify user has access to this member (GN level)
if ($_SESSION['user_type'] === 'gn') {
    $verify_query = "SELECT c.citizen_id 
                     FROM citizens c
                     JOIN families f ON c.family_id = f.family_id
                     WHERE c.citizen_id = ? AND f.gn_id = ? AND c.family_id = ?";
    $verify_stmt = $db->prepare($verify_query);
    $verify_stmt->bind_param("iss", $citizen_id, $gn_id, $family_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied to this member']);
        exit();
    }
}

// Start transaction
$db->begin_transaction();

try {
    // Check if this is an edit of existing submission
    $is_edit = !empty($submission_id);
    
    if ($is_edit) {
        // Mark old submission as not latest
        $update_query = "UPDATE form_submissions_member SET is_latest = 0 WHERE submission_id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bind_param("i", $submission_id);
        $update_stmt->execute();
        
        // Get version from old submission
        $version_query = "SELECT version FROM form_submissions_member WHERE submission_id = ?";
        $version_stmt = $db->prepare($version_query);
        $version_stmt->bind_param("i", $submission_id);
        $version_stmt->execute();
        $version_result = $version_stmt->get_result();
        $old_submission = $version_result->fetch_assoc();
        $new_version = ($old_submission['version'] ?? 0) + 1;
    } else {
        $new_version = 1;
    }
    
    // Get form fields to calculate totals
    $fields_query = "SELECT field_id, is_required FROM form_fields WHERE form_id = ?";
    $fields_stmt = $db->prepare($fields_query);
    $fields_stmt->bind_param("i", $form_id);
    $fields_stmt->execute();
    $fields_result = $fields_stmt->get_result();
    $fields = $fields_result->fetch_all(MYSQLI_ASSOC);
    
    $total_fields = count($fields);
    $completed_fields = 0;
    
    // Create new submission
    $submission_data = [
        'form_id' => $form_id,
        'citizen_id' => $citizen_id,
        'family_id' => $family_id,
        'gn_id' => $gn_id,
        'submitted_by_user_id' => $user_id,
        'submission_status' => $status,
        'total_fields' => $total_fields,
        'submission_date' => $status === 'draft' ? null : date('Y-m-d H:i:s'),
        'version' => $new_version,
        'is_latest' => 1
    ];
    
    $submission_columns = implode(', ', array_keys($submission_data));
    $submission_placeholders = implode(', ', array_fill(0, count($submission_data), '?'));
    
    $insert_submission_query = "INSERT INTO form_submissions_member ($submission_columns) VALUES ($submission_placeholders)";
    $insert_submission_stmt = $db->prepare($insert_submission_query);
    
    $param_types = '';
    foreach ($submission_data as $value) {
        if (is_int($value)) {
            $param_types .= 'i';
        } else {
            $param_types .= 's';
        }
    }
    
    $insert_submission_stmt->bind_param($param_types, ...array_values($submission_data));
    $insert_submission_stmt->execute();
    
    $new_submission_id = $insert_submission_stmt->insert_id;
    
    // Process each field
    foreach ($fields as $field) {
        $field_id = $field['field_id'];
        $field_name = "field_{$field_id}";
        $remove_field_name = "remove_file_{$field_id}";
        
        // Get field info
        $field_info_query = "SELECT field_type FROM form_fields WHERE field_id = ?";
        $field_info_stmt = $db->prepare($field_info_query);
        $field_info_stmt->bind_param("i", $field_id);
        $field_info_stmt->execute();
        $field_info_result = $field_info_stmt->get_result();
        $field_info = $field_info_result->fetch_assoc();
        $field_type = $field_info['field_type'] ?? 'text';
        
        // Process file uploads
        if ($field_type === 'file') {
            // Check if file is being removed
            $remove_file = isset($_POST[$remove_field_name]) && $_POST[$remove_field_name] == '1';
            
            if ($remove_file) {
                // Remove file from database (keep record but clear file info)
                $response_data = [
                    'submission_id' => $new_submission_id,
                    'field_id' => $field_id,
                    'field_value' => '',
                    'file_path' => null,
                    'file_name' => null,
                    'file_size' => null,
                    'file_type' => null
                ];
                
                $completed_fields++;
            } elseif (isset($_FILES[$field_name]) && $_FILES[$field_name]['error'] === UPLOAD_ERR_OK) {
                // Handle new file upload
                $upload_dir = '../../../assets/uploads/forms/members/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file = $_FILES[$field_name];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $file_name = 'form_' . $form_id . '_member_' . $citizen_id . '_' . time() . '_' . $field_id . '.' . $file_ext;
                $file_path = $upload_dir . $file_name;
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $file_path)) {
                    $relative_path = 'assets/uploads/forms/members/' . $file_name;
                    
                    $response_data = [
                        'submission_id' => $new_submission_id,
                        'field_id' => $field_id,
                        'field_value' => $file['name'],
                        'file_path' => $relative_path,
                        'file_name' => $file['name'],
                        'file_size' => $file['size'],
                        'file_type' => $file['type']
                    ];
                    
                    $completed_fields++;
                } else {
                    throw new Exception("Failed to upload file for field {$field_id}");
                }
            } elseif ($is_edit) {
                // Keep existing file if editing and no new file uploaded
                $existing_query = "SELECT * FROM form_responses_member WHERE submission_id = ? AND field_id = ?";
                $existing_stmt = $db->prepare($existing_query);
                $existing_stmt->bind_param("ii", $submission_id, $field_id);
                $existing_stmt->execute();
                $existing_result = $existing_stmt->get_result();
                $existing = $existing_result->fetch_assoc();
                
                if ($existing) {
                    $response_data = [
                        'submission_id' => $new_submission_id,
                        'field_id' => $field_id,
                        'field_value' => $existing['field_value'],
                        'file_path' => $existing['file_path'],
                        'file_name' => $existing['file_name'],
                        'file_size' => $existing['file_size'],
                        'file_type' => $existing['file_type']
                    ];
                    
                    $completed_fields++;
                }
            }
        } else {
            // Process regular fields
            $field_value = $_POST[$field_name] ?? '';
            
            // Handle checkbox arrays
            if ($field_type === 'checkbox' && is_array($field_value)) {
                $field_value = json_encode(array_values($field_value));
            }
            
            // Check if field is completed
            if (!empty($field_value) || $field_value === '0') {
                $completed_fields++;
            }
            
            $response_data = [
                'submission_id' => $new_submission_id,
                'field_id' => $field_id,
                'field_value' => $field_value,
                'file_path' => null,
                'file_name' => null,
                'file_size' => null,
                'file_type' => null
            ];
        }
        
        // Insert response if data exists
        if (isset($response_data)) {
            $response_columns = implode(', ', array_keys($response_data));
            $response_placeholders = implode(', ', array_fill(0, count($response_data), '?'));
            
            $insert_response_query = "INSERT INTO form_responses_member ($response_columns) VALUES ($response_placeholders)";
            $insert_response_stmt = $db->prepare($insert_response_query);
            
            $response_param_types = '';
            foreach ($response_data as $value) {
                if (is_int($value)) {
                    $response_param_types .= 'i';
                } else {
                    $response_param_types .= 's';
                }
            }
            
            $insert_response_stmt->bind_param($response_param_types, ...array_values($response_data));
            $insert_response_stmt->execute();
            
            unset($response_data);
        }
    }
    
    // Update completed fields count
    $update_completed_query = "UPDATE form_submissions_member SET completed_fields = ? WHERE submission_id = ?";
    $update_completed_stmt = $db->prepare($update_completed_query);
    $update_completed_stmt->bind_param("ii", $completed_fields, $new_submission_id);
    $update_completed_stmt->execute();
    
    // Log the action
    $log_query = "INSERT INTO audit_logs (user_id, action_type, table_name, record_id, new_values) 
                  VALUES (?, ?, ?, ?, ?)";
    $log_stmt = $db->prepare($log_query);
    $action_type = $is_edit ? 'update' : 'create';
    $table_name = 'form_submissions_member';
    $new_values = json_encode([
        'form_id' => $form_id,
        'citizen_id' => $citizen_id,
        'family_id' => $family_id,
        'status' => $status,
        'submission_id' => $new_submission_id
    ]);
    $log_stmt->bind_param("issss", $user_id, $action_type, $table_name, $new_submission_id, $new_values);
    $log_stmt->execute();
    
    // Commit transaction
    $db->commit();
    
    // Prepare success response
    $message = $status === 'draft' 
        ? 'Form saved as draft successfully.' 
        : 'Form submitted successfully for review.';
    
    echo json_encode([
        'success' => true,  
        'message' => $message,
        'submission_id' => $new_submission_id,
        'status' => $status,
        'completed_fields' => $completed_fields,
        'total_fields' => $total_fields
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error  
    $db->rollback();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saving form: ' . $e->getMessage()
    ]);
}
?>