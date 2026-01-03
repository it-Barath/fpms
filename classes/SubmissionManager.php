<?php
/**
 * SubmissionManager - Handles form submissions for Family and Member forms
 */
class SubmissionManager {
    private $conn;
    
    public function __construct($connection = null) {
        if ($connection && $connection instanceof mysqli) {
            $this->conn = $connection;
        } else {
            // Use config.php function to get connection
            $this->conn = getMainConnection();
        }
        
        // Validate connection
        if (!$this->conn || !($this->conn instanceof mysqli)) {
            throw new Exception("Database connection is not valid or not a mysqli object");
        }
        
        if ($this->conn->connect_error) {
            throw new Exception("Database connection failed: " . $this->conn->connect_error);
        }
    }
    
    /**
     * Get submissions based on filters and user role
     */
    public function getSubmissions($filters = []) {
        try {
            $userType = $filters['user_type'] ?? '';
            $officeCode = $filters['office_code'] ?? '';
            $formId = $filters['form_id'] ?? 0;
            $status = $filters['status'] ?? 'all';
            $entityType = $filters['entity_type'] ?? 'all';
            $dateFrom = $filters['date_from'] ?? '';
            $dateTo = $filters['date_to'] ?? '';
            $search = $filters['search'] ?? '';
            $userId = $filters['user_id'] ?? 0;
            
            // Build WHERE clauses based on user role
            $whereClauses = [];
            $params = [];
            $types = '';
            
            switch ($userType) {
                case 'moha':
                    // MOHA can see all submissions
                    $whereClauses[] = "1=1";
                    break;
                    
                case 'district':
                    // District can see submissions in their district
                    $whereClauses[] = "u.office_code LIKE CONCAT(?, '%')";
                    $params[] = substr($officeCode, 0, 2); // First 2 chars for district
                    $types .= 's';
                    break;
                    
                case 'division':
                    // Division can see submissions in their division
                    $whereClauses[] = "u.office_code LIKE CONCAT(?, '%')";
                    $params[] = substr($officeCode, 0, 5); // First 5 chars for division
                    $types .= 's';
                    break;
                    
                case 'gn':
                    // GN can only see their own submissions
                    $whereClauses[] = "s.gn_id = ?";
                    $params[] = $officeCode;
                    $types .= 's';
                    break;
            }
            
            // Add form filter
            if ($formId > 0) {
                $whereClauses[] = "s.form_id = ?";
                $params[] = $formId;
                $types .= 'i';
            }
            
            // Add status filter
            if ($status !== 'all') {
                $whereClauses[] = "s.submission_status = ?";
                $params[] = $status;
                $types .= 's';
            }
            
            // Add date filters
            if ($dateFrom) {
                $whereClauses[] = "s.created_at >= ?";
                $params[] = $dateFrom . ' 00:00:00';
                $types .= 's';
            }
            
            if ($dateTo) {
                $whereClauses[] = "s.created_at <= ?";
                $params[] = $dateTo . ' 23:59:59';
                $types .= 's';
            }
            
            // Add entity type filter
            if ($entityType !== 'all') {
                $whereClauses[] = "s.submission_type = ?";
                $params[] = $entityType;
                $types .= 's';
            }
            
            // Add search filter
            if ($search) {
                $searchTerm = "%{$search}%";
                $whereClauses[] = "(f.form_name LIKE ? OR f.form_code LIKE ? OR s.family_id LIKE ? OR s.gn_id LIKE ? OR u.username LIKE ?)";
                for ($i = 0; $i < 5; $i++) {
                    $params[] = $searchTerm;
                    $types .= 's';
                }
            }
            
            // Build the query - Family submissions
            $sql = "
                SELECT 
                    'family' as submission_type,
                    sf.submission_id,
                    sf.form_id,
                    sf.family_id,
                    NULL as citizen_id,
                    sf.gn_id,
                    sf.submitted_by_user_id as submitted_by,
                    sf.submission_status,
                    sf.total_fields,
                    sf.completed_fields,
                    sf.submission_date,
                    sf.reviewed_by_user_id as reviewed_by,
                    sf.review_date,
                    sf.review_notes,
                    sf.is_latest,
                    sf.version,
                    sf.ip_address,
                    sf.user_agent,
                    sf.created_at,
                    sf.updated_at,
                    f.form_name,
                    f.form_code,
                    f.target_entity,
                    u.username as submitted_by_name,
                    u.office_name,
                    u.user_type as submitted_by_type,
                    ru.username as reviewed_by_name,
                    fam.address as family_address,
                    NULL as full_name,
                    CASE 
                        WHEN sf.submission_status IN ('draft', 'rejected') AND sf.submitted_by_user_id = ? THEN 1
                        ELSE 0
                    END as can_edit,
                    CASE 
                        WHEN ? IN ('moha', 'district', 'division') AND sf.submission_status IN ('submitted', 'pending_review') THEN 1
                        ELSE 0
                    END as can_review
                FROM form_submissions_family sf
                JOIN forms f ON sf.form_id = f.form_id
                LEFT JOIN users u ON sf.submitted_by_user_id = u.user_id
                LEFT JOIN users ru ON sf.reviewed_by_user_id = ru.user_id
                LEFT JOIN families fam ON sf.family_id = fam.family_id
            ";
            
            // Add user-specific parameters
            $params[] = $userId;
            $params[] = $userType;
            $types .= 'is';
            
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(' AND ', $whereClauses);
            }
            
            // Union with Member submissions
            $sql .= "
                UNION ALL
                
                SELECT 
                    'member' as submission_type,
                    sm.submission_id,
                    sm.form_id,
                    sm.family_id,
                    sm.citizen_id,
                    sm.gn_id,
                    sm.submitted_by_user_id as submitted_by,
                    sm.submission_status,
                    sm.total_fields,
                    sm.completed_fields,
                    sm.submission_date,
                    sm.reviewed_by_user_id as reviewed_by,
                    sm.review_date,
                    sm.review_notes,
                    sm.is_latest,
                    sm.version,
                    sm.ip_address,
                    sm.user_agent,
                    sm.created_at,
                    sm.updated_at,
                    f.form_name,
                    f.form_code,
                    f.target_entity,
                    u.username as submitted_by_name,
                    u.office_name,
                    u.user_type as submitted_by_type,
                    ru.username as reviewed_by_name,
                    fam.address as family_address,
                    c.full_name,
                    CASE 
                        WHEN sm.submission_status IN ('draft', 'rejected') AND sm.submitted_by_user_id = ? THEN 1
                        ELSE 0
                    END as can_edit,
                    CASE 
                        WHEN ? IN ('moha', 'district', 'division') AND sm.submission_status IN ('submitted', 'pending_review') THEN 1
                        ELSE 0
                    END as can_review
                FROM form_submissions_member sm
                JOIN forms f ON sm.form_id = f.form_id
                LEFT JOIN users u ON sm.submitted_by_user_id = u.user_id
                LEFT JOIN users ru ON sm.reviewed_by_user_id = ru.user_id
                LEFT JOIN families fam ON sm.family_id = fam.family_id
                LEFT JOIN citizens c ON sm.citizen_id = c.citizen_id
            ";
            
            // Add where clauses for member submissions
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(' AND ', $whereClauses);
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT 500";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error . " | SQL: " . substr($sql, 0, 200));
                return [];
            }
            
            if ($types && !empty($params)) {
                // Add duplicate parameters for the UNION query
                $unionParams = array_merge($params, $params);
                $unionTypes = $types . $types;
                
                // Adjust for the extra user parameters in each part
                $allParams = [];
                $allTypes = '';
                
                // First set of parameters (for family submissions)
                foreach ($params as $param) {
                    $allParams[] = $param;
                }
                $allTypes .= $types;
                
                // Second set of parameters (for member submissions)
                foreach ($params as $param) {
                    $allParams[] = $param;
                }
                $allTypes .= $types;
                
                $stmt->bind_param($allTypes, ...$allParams);
            }
            
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                return [];
            }
            
            $result = $stmt->get_result();
            $submissions = [];
            
            while ($row = $result->fetch_assoc()) {
                $submissions[] = $row;
            }
            
            return $submissions;
            
        } catch (Exception $e) {
            error_log('Error getting submissions: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get submission statistics
     */
    public function getSubmissionStats($filters = []) {
        try {
            $userType = $filters['user_type'] ?? '';
            $officeCode = $filters['office_code'] ?? '';
            $formId = $filters['form_id'] ?? 0;
            
            // Build WHERE clauses
            $whereClauses = [];
            $params = [];
            $types = '';
            
            switch ($userType) {
                case 'moha':
                    $whereClauses[] = "1=1";
                    break;
                    
                case 'district':
                    $whereClauses[] = "u.office_code LIKE CONCAT(?, '%')";
                    $params[] = substr($officeCode, 0, 2);
                    $types .= 's';
                    break;
                    
                case 'division':
                    $whereClauses[] = "u.office_code LIKE CONCAT(?, '%')";
                    $params[] = substr($officeCode, 0, 5);
                    $types .= 's';
                    break;
                    
                case 'gn':
                    $whereClauses[] = "s.gn_id = ?";
                    $params[] = $officeCode;
                    $types .= 's';
                    break;
            }
            
            if ($formId > 0) {
                $whereClauses[] = "s.form_id = ?";
                $params[] = $formId;
                $types .= 'i';
            }
            
            $whereClause = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";
            
            $sql = "
                SELECT 
                    'family' as type,
                    COUNT(*) as total,
                    SUM(CASE WHEN submission_status = 'draft' THEN 1 ELSE 0 END) as draft,
                    SUM(CASE WHEN submission_status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                    SUM(CASE WHEN submission_status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN submission_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN submission_status = 'pending_review' THEN 1 ELSE 0 END) as pending_review
                FROM form_submissions_family s
                JOIN forms f ON s.form_id = f.form_id
                LEFT JOIN users u ON s.submitted_by_user_id = u.user_id
                $whereClause
                
                UNION ALL
                
                SELECT 
                    'member' as type,
                    COUNT(*) as total,
                    SUM(CASE WHEN submission_status = 'draft' THEN 1 ELSE 0 END) as draft,
                    SUM(CASE WHEN submission_status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                    SUM(CASE WHEN submission_status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN submission_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN submission_status = 'pending_review' THEN 1 ELSE 0 END) as pending_review
                FROM form_submissions_member s
                JOIN forms f ON s.form_id = f.form_id
                LEFT JOIN users u ON s.submitted_by_user_id = u.user_id
                $whereClause
            ";
            
            // Prepare and execute
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed for stats: " . $this->conn->error);
                return $this->getEmptyStats();
            }
            
            if ($types && !empty($params)) {
                // Bind parameters twice (once for each UNION part)
                $allParams = array_merge($params, $params);
                $allTypes = $types . $types;
                $stmt->bind_param($allTypes, ...$allParams);
            }
            
            if (!$stmt->execute()) {
                error_log("Execute failed for stats: " . $stmt->error);
                return $this->getEmptyStats();
            }
            
            $result = $stmt->get_result();
            
            $stats = [
                'total' => 0,
                'draft' => 0,
                'submitted' => 0,
                'approved' => 0,
                'rejected' => 0,
                'pending_review' => 0
            ];
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    foreach ($stats as $key => $value) {
                        $stats[$key] += $row[$key];
                    }
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            error_log('Error getting submission stats: ' . $e->getMessage());
            return $this->getEmptyStats();
        }
    }
    
    /**
     * Get submission by ID
     */
    public function getSubmissionById($submissionId, $type) {
        try {
            if ($type === 'family') {
                $sql = "
                    SELECT sf.*, f.*, 
                           u.username as submitted_by_name,
                           u.office_name as submitted_by_office,
                           ru.username as reviewed_by_name,
                           fam.address as family_address
                    FROM form_submissions_family sf
                    JOIN forms f ON sf.form_id = f.form_id
                    LEFT JOIN users u ON sf.submitted_by_user_id = u.user_id
                    LEFT JOIN users ru ON sf.reviewed_by_user_id = ru.user_id
                    LEFT JOIN families fam ON sf.family_id = fam.family_id
                    WHERE sf.submission_id = ?
                ";
            } else {
                $sql = "
                    SELECT sm.*, f.*, 
                           u.username as submitted_by_name,
                           u.office_name as submitted_by_office,
                           ru.username as reviewed_by_name,
                           fam.address as family_address,
                           c.full_name,
                           c.identification_number
                    FROM form_submissions_member sm
                    JOIN forms f ON sm.form_id = f.form_id
                    LEFT JOIN users u ON sm.submitted_by_user_id = u.user_id
                    LEFT JOIN users ru ON sm.reviewed_by_user_id = ru.user_id
                    LEFT JOIN families fam ON sm.family_id = fam.family_id
                    LEFT JOIN citizens c ON sm.citizen_id = c.citizen_id
                    WHERE sm.submission_id = ?
                ";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed for getSubmissionById: " . $this->conn->error);
                return null;
            }
            
            $stmt->bind_param('i', $submissionId);
            
            if (!$stmt->execute()) {
                error_log("Execute failed for getSubmissionById: " . $stmt->error);
                return null;
            }
            
            $result = $stmt->get_result();
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            error_log('Error getting submission: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get submission responses
     */
    public function getSubmissionResponses($submissionId, $type) {
        try {
            if ($type === 'family') {
                $sql = "
                    SELECT fr.*, ff.field_label, ff.field_type, ff.field_code, ff.is_required
                    FROM form_responses_family fr
                    JOIN form_fields ff ON fr.field_id = ff.field_id
                    WHERE fr.submission_id = ?
                    ORDER BY ff.field_order
                ";
            } else {
                $sql = "
                    SELECT fr.*, ff.field_label, ff.field_type, ff.field_code, ff.is_required
                    FROM form_responses_member fr
                    JOIN form_fields ff ON fr.field_id = ff.field_id
                    WHERE fr.submission_id = ?
                    ORDER BY ff.field_order
                ";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed for getSubmissionResponses: " . $this->conn->error);
                return [];
            }
            
            $stmt->bind_param('i', $submissionId);
            
            if (!$stmt->execute()) {
                error_log("Execute failed for getSubmissionResponses: " . $stmt->error);
                return [];
            }
            
            $result = $stmt->get_result();
            $responses = [];
            
            while ($row = $result->fetch_assoc()) {
                // Parse file info if present
                if (!empty($row['file_path']) && !empty($row['field_value'])) {
                    $fileInfo = json_decode($row['field_value'], true);
                    if (is_array($fileInfo)) {
                        $row['file_info'] = $fileInfo;
                    }
                }
                $responses[] = $row;
            }
            
            return $responses;
            
        } catch (Exception $e) {
            error_log('Error getting submission responses: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create new submission
     */
    public function createSubmission($data) {
        try {
            $type = $data['submission_type'] ?? 'family';
            $formId = $data['form_id'] ?? 0;
            $entityId = $data['entity_id'] ?? null;
            $userId = $data['user_id'] ?? 0;
            $gnId = $data['gn_id'] ?? '';
            
            if (!$entityId) {
                return ['success' => false, 'error' => 'Entity ID is required'];
            }
            
            // Get total fields count
            $totalFields = $this->countFormFields($formId);
            
            if ($type === 'family') {
                $sql = "
                    INSERT INTO form_submissions_family (
                        form_id, family_id, gn_id, submitted_by_user_id,
                        submission_status, total_fields, completed_fields,
                        submission_date, version, ip_address, user_agent
                    ) VALUES (?, ?, ?, ?, 'draft', ?, 0, NOW(), 1, ?, ?)
                ";
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
                }
                
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                
                $stmt->bind_param(
                    'issiiss',
                    $formId,
                    $entityId,
                    $gnId,
                    $userId,
                    $totalFields,
                    $ip,
                    $userAgent
                );
            } else {
                $sql = "
                    INSERT INTO form_submissions_member (
                        form_id, citizen_id, family_id, gn_id, submitted_by_user_id,
                        submission_status, total_fields, completed_fields,
                        submission_date, version, ip_address, user_agent
                    ) VALUES (?, ?, ?, ?, ?, 'draft', ?, 0, NOW(), 1, ?, ?)
                ";
                
                // Get family ID from citizen
                $familyId = $this->getFamilyIdFromCitizen($entityId);
                if (!$familyId) {
                    return ['success' => false, 'error' => 'Citizen not found or has no family'];
                }
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
                }
                
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                
                $stmt->bind_param(
                    'iisiiss',
                    $formId,
                    $entityId,
                    $familyId,
                    $gnId,
                    $userId,
                    $totalFields,
                    $ip,
                    $userAgent
                );
            }
            
            if ($stmt->execute()) {
                $submissionId = $stmt->insert_id;
                
                // Log activity
                $this->logActivity(
                    $userId,
                    'create_submission',
                    $type . '_submissions',
                    $submissionId,
                    null,
                    json_encode(['form_id' => $formId, 'entity_id' => $entityId])
                );
                
                return ['success' => true, 'submission_id' => $submissionId];
            } else {
                return ['success' => false, 'error' => 'Database error: ' . $stmt->error];
            }
            
        } catch (Exception $e) {
            error_log('Error creating submission: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error creating submission: ' . $e->getMessage()];
        }
    }
    
    /**
     * Save submission response
     */
    public function saveResponse($data) {
        try {
            $submissionId = $data['submission_id'] ?? 0;
            $fieldId = $data['field_id'] ?? 0;
            $value = $data['value'] ?? '';
            $type = $data['submission_type'] ?? 'family';
            
            if (!$submissionId || !$fieldId) {
                return ['success' => false, 'error' => 'Submission ID and Field ID are required'];
            }
            
            // Handle file uploads
            if (isset($_FILES['field_' . $fieldId])) {
                $fileResult = $this->handleFileUpload($fieldId, $_FILES['field_' . $fieldId]);
                if (!$fileResult['success']) {
                    return $fileResult;
                }
                $value = json_encode($fileResult['file_info']);
            }
            
            // Save response
            if ($type === 'family') {
                $sql = "
                    INSERT INTO form_responses_family (
                        submission_id, field_id, field_value, file_path, 
                        file_name, file_size, file_type
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        field_value = VALUES(field_value),
                        file_path = VALUES(file_path),
                        file_name = VALUES(file_name),
                        file_size = VALUES(file_size),
                        file_type = VALUES(file_type),
                        updated_at = CURRENT_TIMESTAMP
                ";
            } else {
                $sql = "
                    INSERT INTO form_responses_member (
                        submission_id, field_id, field_value, file_path, 
                        file_name, file_size, file_type
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        field_value = VALUES(field_value),
                        file_path = VALUES(file_path),
                        file_name = VALUES(file_name),
                        file_size = VALUES(file_size),
                        file_type = VALUES(file_type),
                        updated_at = CURRENT_TIMESTAMP
                ";
            }
            
            // Prepare file data
            $filePath = null;
            $fileName = null;
            $fileSize = null;
            $fileType = null;
            
            if (isset($fileResult)) {
                $filePath = $fileResult['file_info']['path'] ?? null;
                $fileName = $fileResult['file_info']['name'] ?? null;
                $fileSize = $fileResult['file_info']['size'] ?? null;
                $fileType = $fileResult['file_info']['type'] ?? null;
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $stmt->bind_param(
                'iisssis',
                $submissionId,
                $fieldId,
                $value,
                $filePath,
                $fileName,
                $fileSize,
                $fileType
            );
            
            if ($stmt->execute()) {
                // Update completed fields count
                $this->updateCompletedFields($submissionId, $type);
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Database error: ' . $stmt->error];
            }
            
        } catch (Exception $e) {
            error_log('Error saving response: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error saving response: ' . $e->getMessage()];
        }
    }
    
    /**
     * Submit form for review
     */
    public function submitForReview($submissionId, $type, $userId) {
        try {
            // Check if all required fields are filled
            $completed = $this->checkRequiredFields($submissionId, $type);
            if (!$completed['success']) {
                return $completed;
            }
            
            if ($type === 'family') {
                $sql = "
                    UPDATE form_submissions_family 
                    SET submission_status = 'submitted',
                        submission_date = NOW(),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE submission_id = ? 
                    AND submission_status = 'draft'
                ";
            } else {
                $sql = "
                    UPDATE form_submissions_member 
                    SET submission_status = 'submitted',
                        submission_date = NOW(),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE submission_id = ? 
                    AND submission_status = 'draft'
                ";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $stmt->bind_param('i', $submissionId);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                // Log activity
                $this->logActivity(
                    $userId,
                    'submit_submission',
                    $type . '_submissions',
                    $submissionId,
                    'draft',
                    'submitted'
                );
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Submission not found or already submitted'];
            }
            
        } catch (Exception $e) {
            error_log('Error submitting for review: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error submitting for review: ' . $e->getMessage()];
        }
    }
    
    /**
     * Review submission (approve/reject)
     */
    public function reviewSubmission($submissionId, $type, $reviewerId, $action, $notes = '') {
        try {
            $status = ($action === 'approve') ? 'approved' : 'rejected';
            
            if ($type === 'family') {
                $sql = "
                    UPDATE form_submissions_family 
                    SET submission_status = ?,
                        reviewed_by_user_id = ?,
                        review_date = NOW(),
                        review_notes = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE submission_id = ? 
                    AND submission_status IN ('submitted', 'pending_review')
                ";
            } else {
                $sql = "
                    UPDATE form_submissions_member 
                    SET submission_status = ?,
                        reviewed_by_user_id = ?,
                        review_date = NOW(),
                        review_notes = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE submission_id = ? 
                    AND submission_status IN ('submitted', 'pending_review')
                ";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $stmt->bind_param('sisi', $status, $reviewerId, $notes, $submissionId);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                // Log activity
                $this->logActivity(
                    $reviewerId,
                    'review_submission',
                    $type . '_submissions',
                    $submissionId,
                    'submitted',
                    $status
                );
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Submission not found or already reviewed'];
            }
            
        } catch (Exception $e) {
            error_log('Error reviewing submission: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error reviewing submission: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete submission
     */
    public function deleteSubmission($submissionId, $type, $userId) {
        try {
            // Check permission
            if (!$this->canDeleteSubmission($submissionId, $type, $userId)) {
                return ['success' => false, 'error' => 'Permission denied'];
            }
            
            if ($type === 'family') {
                $sql = "DELETE FROM form_submissions_family WHERE submission_id = ?";
            } else {
                $sql = "DELETE FROM form_submissions_member WHERE submission_id = ?";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $stmt->bind_param('i', $submissionId);
            
            if ($stmt->execute()) {
                // Log activity
                $this->logActivity(
                    $userId,
                    'delete_submission',
                    $type . '_submissions',
                    $submissionId,
                    null,
                    null
                );
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Database error: ' . $stmt->error];
            }
            
        } catch (Exception $e) {
            error_log('Error deleting submission: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error deleting submission: ' . $e->getMessage()];
        }
    }
    
    /**
     * Bulk actions on submissions
     */
    public function bulkAction($action, $submissionIds, $userId) {
        try {
            $successCount = 0;
            $errorMessages = [];
            
            foreach ($submissionIds as $submissionId) {
                $parts = explode('-', $submissionId);
                if (count($parts) !== 2) continue;
                
                $type = $parts[0];
                $id = $parts[1];
                
                switch ($action) {
                    case 'approve':
                        $result = $this->reviewSubmission($id, $type, $userId, 'approve', 'Bulk approval');
                        break;
                        
                    case 'reject':
                        $result = $this->reviewSubmission($id, $type, $userId, 'reject', 'Bulk rejection');
                        break;
                        
                    case 'delete':
                        $result = $this->deleteSubmission($id, $type, $userId);
                        break;
                        
                    case 'pending':
                        $result = $this->updateSubmissionStatus($id, $type, 'pending_review', $userId);
                        break;
                        
                    default:
                        $result = ['success' => false, 'error' => 'Invalid action'];
                }
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorMessages[] = "Submission {$submissionId}: " . $result['error'];
                }
            }
            
            if ($successCount > 0) {
                $message = "Successfully processed {$successCount} submission(s)";
                if (!empty($errorMessages)) {
                    $message .= ". Errors: " . implode(', ', $errorMessages);
                }
                return ['success' => true, 'message' => $message];
            } else {
                return ['success' => false, 'error' => 'No submissions processed. Errors: ' . implode(', ', $errorMessages)];
            }
            
        } catch (Exception $e) {
            error_log('Error in bulk action: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error processing bulk action: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get submission history for an entity
     */
    public function getSubmissionHistory($formId, $entityId, $type) {
        try {
            if ($type === 'family') {
                $sql = "
                    SELECT sf.*, u.username, u.office_name
                    FROM form_submissions_family sf
                    LEFT JOIN users u ON sf.submitted_by_user_id = u.user_id
                    WHERE sf.form_id = ? AND sf.family_id = ?
                    ORDER BY sf.created_at DESC
                ";
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    error_log("Prepare failed for getSubmissionHistory: " . $this->conn->error);
                    return [];
                }
                
                $stmt->bind_param('is', $formId, $entityId);
            } else {
                $sql = "
                    SELECT sm.*, u.username, u.office_name
                    FROM form_submissions_member sm
                    LEFT JOIN users u ON sm.submitted_by_user_id = u.user_id
                    WHERE sm.form_id = ? AND sm.citizen_id = ?
                    ORDER BY sm.created_at DESC
                ";
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    error_log("Prepare failed for getSubmissionHistory: " . $this->conn->error);
                    return [];
                }
                
                $stmt->bind_param('ii', $formId, $entityId);
            }
            
            if (!$stmt->execute()) {
                error_log("Execute failed for getSubmissionHistory: " . $stmt->error);
                return [];
            }
            
            $result = $stmt->get_result();
            $history = [];
            
            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
            
            return $history;
            
        } catch (Exception $e) {
            error_log('Error getting submission history: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update submission status
     */
    private function updateSubmissionStatus($submissionId, $type, $status, $userId) {
        try {
            if ($type === 'family') {
                $sql = "
                    UPDATE form_submissions_family 
                    SET submission_status = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE submission_id = ?
                ";
            } else {
                $sql = "
                    UPDATE form_submissions_member 
                    SET submission_status = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE submission_id = ?
                ";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $stmt->bind_param('si', $status, $submissionId);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $this->logActivity(
                    $userId,
                    'update_status',
                    $type . '_submissions',
                    $submissionId,
                    null,
                    $status
                );
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Failed to update status'];
            }
            
        } catch (Exception $e) {
            error_log('Error updating submission status: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error updating status: ' . $e->getMessage()];
        }
    }
    
    /**
     * Check if all required fields are filled
     */
    private function checkRequiredFields($submissionId, $type) {
        try {
            if ($type === 'family') {
                $sql = "
                    SELECT COUNT(*) as required_count
                    FROM form_fields ff
                    LEFT JOIN form_responses_family fr ON ff.field_id = fr.field_id AND fr.submission_id = ?
                    WHERE ff.form_id = (SELECT form_id FROM form_submissions_family WHERE submission_id = ?)
                    AND ff.is_required = 1
                    AND (fr.field_value IS NULL OR fr.field_value = '')
                ";
            } else {
                $sql = "
                    SELECT COUNT(*) as required_count
                    FROM form_fields ff
                    LEFT JOIN form_responses_member fr ON ff.field_id = fr.field_id AND fr.submission_id = ?
                    WHERE ff.form_id = (SELECT form_id FROM form_submissions_member WHERE submission_id = ?)
                    AND ff.is_required = 1
                    AND (fr.field_value IS NULL OR fr.field_value = '')
                ";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Database error'];
            }
            
            $stmt->bind_param('ii', $submissionId, $submissionId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['required_count'] > 0) {
                return [
                    'success' => false, 
                    'error' => 'Please fill all required fields before submitting'
                ];
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log('Error checking required fields: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error checking required fields'];
        }
    }
    
    /**
     * Update completed fields count
     */
    private function updateCompletedFields($submissionId, $type) {
        try {
            if ($type === 'family') {
                $sql = "
                    UPDATE form_submissions_family sf
                    SET completed_fields = (
                        SELECT COUNT(*) 
                        FROM form_responses_family fr 
                        WHERE fr.submission_id = sf.submission_id
                        AND (fr.field_value IS NOT NULL AND fr.field_value != '')
                    )
                    WHERE sf.submission_id = ?
                ";
            } else {
                $sql = "
                    UPDATE form_submissions_member sm
                    SET completed_fields = (
                        SELECT COUNT(*) 
                        FROM form_responses_member fr 
                        WHERE fr.submission_id = sm.submission_id
                        AND (fr.field_value IS NOT NULL AND fr.field_value != '')
                    )
                    WHERE sm.submission_id = ?
                ";
            }
            
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $submissionId);
                $stmt->execute();
            }
            
        } catch (Exception $e) {
            error_log('Error updating completed fields: ' . $e->getMessage());
        }
    }
    
    /**
     * Count form fields
     */
    private function countFormFields($formId) {
        try {
            $sql = "SELECT COUNT(*) as count FROM form_fields WHERE form_id = ?";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return 0;
            }
            
            $stmt->bind_param('i', $formId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            return $row['count'] ?? 0;
            
        } catch (Exception $e) {
            error_log('Error counting form fields: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get family ID from citizen
     */
    private function getFamilyIdFromCitizen($citizenId) {
        try {
            $sql = "SELECT family_id FROM citizens WHERE citizen_id = ?";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return null;
            }
            
            $stmt->bind_param('i', $citizenId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            return $row ? $row['family_id'] : null;
            
        } catch (Exception $e) {
            error_log('Error getting family ID from citizen: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Handle file upload
     */
    private function handleFileUpload($fieldId, $file) {
        $uploadDir = '../uploads/form_files/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'File upload error: ' . $file['error']];
        }
        
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'File too large (max 5MB)'];
        }
        
        if (!in_array($file['type'], $allowedTypes)) {
            return ['success' => false, 'error' => 'Invalid file type. Allowed: JPG, PNG, GIF, PDF, DOC, DOCX'];
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'field_' . $fieldId . '_' . time() . '_' . uniqid() . '.' . $extension;
        $filepath = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return [
                'success' => true,
                'file_info' => [
                    'path' => $filepath,
                    'name' => $file['name'],
                    'size' => $file['size'],
                    'type' => $file['type']
                ]
            ];
        } else {
            return ['success' => false, 'error' => 'Failed to save file'];
        }
    }
    
    /**
     * Check if user can delete submission
     */
    private function canDeleteSubmission($submissionId, $type, $userId) {
        try {
            if ($type === 'family') {
                $sql = "SELECT submitted_by_user_id FROM form_submissions_family WHERE submission_id = ?";
            } else {
                $sql = "SELECT submitted_by_user_id FROM form_submissions_member WHERE submission_id = ?";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
            
            $stmt->bind_param('i', $submissionId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return $row && $row['submitted_by_user_id'] == $userId;
            
        } catch (Exception $e) {
            error_log('Error checking delete permission: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log activity
     */
    private function logActivity($userId, $action, $table, $recordId, $oldValue, $newValue) {
        try {
            $sql = "
                INSERT INTO audit_logs (
                    user_id, action_type, table_name, record_id,
                    old_values, new_values, ip_address, user_agent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return;
            }
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $stmt->bind_param(
                'isssssss',
                $userId,
                $action,
                $table,
                $recordId,
                $oldValue,
                $newValue,
                $ip,
                $userAgent
            );
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Error logging activity: ' . $e->getMessage());
        }
    }
    
    /**
     * Get empty stats array
     */
    private function getEmptyStats() {
        return [
            'total' => 0,
            'draft' => 0,
            'submitted' => 0,
            'approved' => 0,
            'rejected' => 0,
            'pending_review' => 0
        ];
    }
}
?>