<?php
/**
 * FormManager.php
 * Comprehensive form management class for FPMS
 * Updated with enhanced features, better error handling, and improved structure
 */

class FormManager {
    // Database connection
    private $conn;
    
    // Constants for form types and statuses
    const FORM_TYPES = ['family', 'member', 'both'];
    const TARGET_ENTITIES = ['family', 'member', 'both'];
    const SUBMISSION_STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'pending_review'];
    const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'radio', 'checkbox', 'dropdown', 'email', 'phone', 'yesno', 'file', 'rating'];
    const USER_TYPES = ['moha', 'district', 'division', 'gn'];
    
    public function __construct($conn = null) {
        $this->conn = $conn ?: getMainConnection();
    }
    
    // ============================================
    // FORM MANAGEMENT METHODS
    // ============================================
    
    /**
     * Create a new form
     * 
     * @param array $formData Form data
     * @return array Result with success status, form ID, and error message
     */
    public function createForm(array $formData): array {
        try {
            // Validate required fields
            $required = ['form_code', 'form_name', 'target_entity'];
            foreach ($required as $field) {
                if (empty($formData[$field])) {
                    throw new InvalidArgumentException("Missing required field: $field");
                }
            }
            
            // Validate target entity
            if (!in_array($formData['target_entity'], self::TARGET_ENTITIES)) {
                throw new InvalidArgumentException("Invalid target entity: {$formData['target_entity']}");
            }
            
            // Check if form code already exists
            if ($this->formCodeExists($formData['form_code'])) {
                throw new Exception("Form code '{$formData['form_code']}' already exists");
            }
            
            // Prepare SQL statement
            $sql = "INSERT INTO forms (
                form_code, form_name, form_description, form_type, form_category, 
                target_entity, is_active, max_submissions_per_entity, 
                start_date, end_date, created_by_user_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("SQL preparation failed: " . $this->conn->error);
            }
            
            // Bind parameters
            $formDescription = $formData['form_description'] ?? '';
            $formType = $formData['form_type'] ?? 'both';
            $formCategory = $formData['form_category'] ?? '';
            $isActive = $formData['is_active'] ?? 1;
            $maxSubmissions = $formData['max_submissions_per_entity'] ?? 1;
            $startDate = $formData['start_date'] ?? null;
            $endDate = $formData['end_date'] ?? null;
            $createdBy = $formData['created_by_user_id'] ?? null;
            
            $stmt->bind_param(
                "ssssssiissi", 
                $formData['form_code'],
                $formData['form_name'],
                $formDescription,
                $formType,
                $formCategory,
                $formData['target_entity'],
                $isActive,
                $maxSubmissions,
                $startDate,
                $endDate,
                $createdBy
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $formId = $stmt->insert_id;
            
            // Log activity
            $this->logActivity('create_form', "Created form: {$formData['form_name']} (ID: $formId)", $createdBy);
            
            return [
                'success' => true, 
                'form_id' => $formId, 
                'message' => "Form created successfully"
            ];
            
        } catch (InvalidArgumentException $e) {
            return [
                'success' => false, 
                'form_id' => null, 
                'message' => $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false, 
                'form_id' => null, 
                'message' => "Error creating form: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Check if form code exists
     * 
     * @param string $formCode Form code to check
     * @param int|null $excludeFormId Form ID to exclude from check
     * @return bool True if code exists
     */
    public function formCodeExists(string $formCode, ?int $excludeFormId = null): bool {
        try {
            $sql = "SELECT COUNT(*) as count FROM forms WHERE form_code = ?";
            $params = [$formCode];
            $types = "s";
            
            if ($excludeFormId) {
                $sql .= " AND form_id != ?";
                $params[] = $excludeFormId;
                $types .= "i";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
            
            if (count($params) > 1) {
                $stmt->bind_param($types, ...$params);
            } else {
                $stmt->bind_param($types, $params[0]);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return ($row['count'] > 0);
            
        } catch (Exception $e) {
            error_log("Error checking form code: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get form with fields
     * 
     * @param int $formId Form ID
     * @return array|null Form data with fields or null if not found
     */
    public function getFormWithFields(int $formId): ?array {
        try {
            $form = $this->getFormById($formId);
            if (!$form) {
                return null;
            }
            
            $fields = $this->getFormFields($formId);
            $form['fields'] = $fields;
            
            // Add field count statistics
            $form['total_fields'] = count($fields);
            $form['required_fields'] = count(array_filter($fields, fn($f) => $f['is_required']));
            
            return $form;
            
        } catch (Exception $e) {
            error_log("Error getting form with fields: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get form by ID
     * 
     * @param int $formId Form ID
     * @return array|null Form data or null if not found
     */
    public function getFormById(int $formId): ?array {
        try {
            $sql = "SELECT f.*, 
                           u.username as created_by_username, 
                           u.office_name as created_by_office,
                           u.user_type as created_by_type
                    FROM forms f
                    LEFT JOIN users u ON f.created_by_user_id = u.user_id
                    WHERE f.form_id = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return null;
            }
            
            $stmt->bind_param("i", $formId);
            $stmt->execute();
            $result = $stmt->get_result();
            $form = $result->fetch_assoc();
            
            if ($form) {
                // Format dates for display
                $form['start_date_formatted'] = $form['start_date'] ? date('Y-m-d', strtotime($form['start_date'])) : null;
                $form['end_date_formatted'] = $form['end_date'] ? date('Y-m-d', strtotime($form['end_date'])) : null;
                $form['created_at_formatted'] = date('Y-m-d H:i:s', strtotime($form['created_at']));
                $form['updated_at_formatted'] = date('Y-m-d H:i:s', strtotime($form['updated_at']));
                
                // Check if form is currently active
                $now = date('Y-m-d H:i:s');
                $form['is_currently_active'] = $form['is_active'] 
                    && (!$form['start_date'] || $form['start_date'] <= $now)
                    && (!$form['end_date'] || $form['end_date'] >= $now);
            }
            
            return $form;
            
        } catch (Exception $e) {
            error_log("Error getting form by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all forms with optional filters
     * 
     * @param array $filters Search filters
     * @return array List of forms
     */
    public function getAllForms(array $filters = []): array {
        $forms = [];
        
        try {
            $whereClauses = ["1=1"];
            $params = [];
            $types = "";
            
            $sql = "SELECT f.*, u.username as created_by_username, u.office_name as created_by_office
                    FROM forms f
                    LEFT JOIN users u ON f.created_by_user_id = u.user_id";
            
            // Apply filters
            if (!empty($filters['search'])) {
                $searchTerm = "%{$filters['search']}%";
                $whereClauses[] = "(f.form_name LIKE ? OR f.form_code LIKE ? OR f.form_description LIKE ?)";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
                $types .= "sss";
            }
            
            if (!empty($filters['created_by'])) {
                $whereClauses[] = "f.created_by_user_id = ?";
                $params[] = $filters['created_by'];
                $types .= "i";
            }
            
            if (isset($filters['is_active'])) {
                $whereClauses[] = "f.is_active = ?";
                $params[] = $filters['is_active'];
                $types .= "i";
            }
            
            if (!empty($filters['form_type'])) {
                $whereClauses[] = "f.form_type = ?";
                $params[] = $filters['form_type'];
                $types .= "s";
            }
            
            if (!empty($filters['target_entity'])) {
                $whereClauses[] = "f.target_entity = ?";
                $params[] = $filters['target_entity'];
                $types .= "s";
            }
            
            if (!empty($filters['form_category'])) {
                $whereClauses[] = "f.form_category = ?";
                $params[] = $filters['form_category'];
                $types .= "s";
            }
            
            if (count($whereClauses) > 0) {
                $sql .= " WHERE " . implode(" AND ", $whereClauses);
            }
            
            // Add ordering
            $orderBy = $filters['order_by'] ?? 'f.created_at';
            $orderDir = isset($filters['order_dir']) && in_array(strtoupper($filters['order_dir']), ['ASC', 'DESC']) 
                ? strtoupper($filters['order_dir']) 
                : 'DESC';
            $sql .= " ORDER BY $orderBy $orderDir";
            
            // Add pagination if requested
            if (!empty($filters['limit'])) {
                $limit = (int)$filters['limit'];
                $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;
                $sql .= " LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                $types .= "ii";
            }
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                return $forms;
            }
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $forms[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting all forms: " . $e->getMessage());
        }
        
        return $forms;
    }
    
    /**
     * Update form
     * 
     * @param int $formId Form ID
     * @param array $formData Updated form data
     * @return array Result with success status and message
     */
    public function updateForm(int $formId, array $formData): array {
        try {
            // Check if form exists
            $existingForm = $this->getFormById($formId);
            if (!$existingForm) {
                throw new Exception("Form not found");
            }
            
            // Check form code uniqueness if being updated
            if (isset($formData['form_code']) && $formData['form_code'] !== $existingForm['form_code']) {
                if ($this->formCodeExists($formData['form_code'], $formId)) {
                    throw new Exception("Form code '{$formData['form_code']}' already exists");
                }
            }
            
            // Build update query
            $fields = [];
            $params = [];
            $types = "";
            
            $updatableFields = [
                'form_code' => 's',
                'form_name' => 's',
                'form_description' => 's',
                'form_type' => 's',
                'form_category' => 's',
                'target_entity' => 's',
                'is_active' => 'i',
                'max_submissions_per_entity' => 'i',
                'start_date' => 's',
                'end_date' => 's'
            ];
            
            foreach ($updatableFields as $field => $type) {
                if (array_key_exists($field, $formData)) {
                    $fields[] = "$field = ?";
                    $params[] = $formData[$field];
                    $types .= $type;
                }
            }
            
            if (empty($fields)) {
                throw new Exception("No fields to update");
            }
            
            $fields[] = "updated_at = NOW()";
            
            $params[] = $formId;
            $types .= "i";
            
            $sql = "UPDATE forms SET " . implode(', ', $fields) . " WHERE form_id = ?";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("SQL preparation failed: " . $this->conn->error);
            }
            
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $this->logActivity('update_form', "Updated form ID: $formId");
            
            return [
                'success' => true,
                'message' => "Form updated successfully"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Error updating form: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete form and all related data
     * 
     * @param int $formId Form ID
     * @return array Result with success status and message
     */
    public function deleteForm(int $formId): array {
        try {
            $this->conn->begin_transaction();
            
            // Get form info for logging
            $form = $this->getFormById($formId);
            if (!$form) {
                throw new Exception("Form not found");
            }
            
            // Check if form has submissions
            $submissionCount = $this->getFormSubmissionCounts($formId);
            $totalSubmissions = $submissionCount['family_submissions'] + $submissionCount['member_submissions'];
            
            if ($totalSubmissions > 0 && empty($_SESSION['force_delete'] ?? false)) {
                throw new Exception("Form has $totalSubmissions submissions. Cannot delete.");
            }
            
            // Delete in correct order (child tables first)
            $tables = [
                'form_responses_family',
                'form_responses_member',
                'form_submissions_family',
                'form_submissions_member',
                'form_assignments',
                'form_fields',
                'forms'
            ];
            
            foreach ($tables as $table) {
                $sql = "DELETE FROM $table WHERE form_id = ?";
                if ($table === 'forms') {
                    $sql = "DELETE FROM $table WHERE form_id = ?";
                }
                
                $stmt = $this->conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $formId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            $this->conn->commit();
            
            $this->logActivity('delete_form', "Deleted form: {$form['form_name']} (ID: $formId)");
            
            return [
                'success' => true,
                'message' => "Form deleted successfully"
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => "Error deleting form: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all form categories
     * 
     * @return array List of categories
     */
    public function getFormCategories(): array {
        $categories = [];
        
        try {
            $sql = "SELECT DISTINCT form_category 
                    FROM forms 
                    WHERE form_category IS NOT NULL 
                    AND form_category != ''
                    ORDER BY form_category";
            
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    $categories[] = $row['form_category'];
                }
            }
            
        } catch (Exception $e) {
            error_log("Error getting form categories: " . $e->getMessage());
        }
        
        // Default categories if none found
        if (empty($categories)) {
            $categories = ['General', 'Health', 'Education', 'Employment', 'Housing', 'Social', 'Economic', 'Demographic'];
        }
        
        return $categories;
    }
    
    /**
     * Toggle form active status
     * 
     * @param int $formId Form ID
     * @param bool $isActive New status
     * @return array Result with success status and message
     */
    public function toggleFormStatus(int $formId, bool $isActive): array {
        try {
            $sql = "UPDATE forms SET is_active = ?, updated_at = NOW() WHERE form_id = ?";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("SQL preparation failed: " . $this->conn->error);
            }
            
            $activeInt = $isActive ? 1 : 0;
            $stmt->bind_param("ii", $activeInt, $formId);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $status = $isActive ? 'activated' : 'deactivated';
            $this->logActivity('toggle_form_status', "Form $status (ID: $formId)");
            
            return [
                'success' => true,
                'message' => "Form $status successfully"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Error toggling form status: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Duplicate form with all fields
     * 
     * @param int $formId Form ID to duplicate
     * @param array $options Duplication options
     * @return array Result with success status and new form ID
     */
    public function duplicateForm(int $formId, array $options = []): array {
        try {
            $this->conn->begin_transaction();
            
            // Get original form
            $originalForm = $this->getFormWithFields($formId);
            if (!$originalForm) {
                throw new Exception("Original form not found");
            }
            
            // Generate new form code
            $originalCode = $originalForm['form_code'];
            $suffix = $options['suffix'] ?? '_copy';
            $counter = 1;
            $newFormCode = $originalCode . $suffix . $counter;
            
            while ($this->formCodeExists($newFormCode)) {
                $counter++;
                $newFormCode = $originalCode . $suffix . $counter;
            }
            
            // Prepare new form data
            $newFormData = [
                'form_code' => $newFormCode,
                'form_name' => $options['new_name'] ?? ($originalForm['form_name'] . ' (Copy)'),
                'form_description' => $originalForm['form_description'],
                'form_type' => $originalForm['form_type'],
                'form_category' => $originalForm['form_category'],
                'target_entity' => $originalForm['target_entity'],
                'is_active' => $options['is_active'] ?? 0, // Keep new form inactive by default
                'max_submissions_per_entity' => $originalForm['max_submissions_per_entity'],
                'start_date' => null, // Reset dates
                'end_date' => null,
                'created_by_user_id' => $_SESSION['user_id'] ?? null
            ];
            
            // Create new form
            $result = $this->createForm($newFormData);
            if (!$result['success']) {
                throw new Exception("Failed to create duplicate form: " . $result['message']);
            }
            
            $newFormId = $result['form_id'];
            
            // Copy form fields
            if (!empty($originalForm['fields'])) {
                foreach ($originalForm['fields'] as $field) {
                    $fieldData = [
                        'field_code' => $field['field_code'],
                        'field_label' => $field['field_label'],
                        'field_type' => $field['field_type'],
                        'field_options' => $field['field_options'] ?? [],
                        'validation_rules' => $field['validation_rules'] ?? '',
                        'is_required' => $field['is_required'],
                        'field_order' => $field['field_order'],
                        'default_value' => $field['default_value'] ?? '',
                        'placeholder' => $field['placeholder'] ?? '',
                        'hint_text' => $field['hint_text'] ?? '',
                        'visibility_condition' => $field['visibility_condition'] ?? ''
                    ];
                    
                    $fieldResult = $this->addFormField($newFormId, $fieldData);
                    if (!$fieldResult['success']) {
                        throw new Exception("Failed to copy field '{$field['field_label']}': " . $fieldResult['message']);
                    }
                }
            }
            
            // Copy assignments if requested
            if (!empty($options['copy_assignments'])) {
                $assignments = $this->getFormAssignments($formId);
                foreach ($assignments as $assignment) {
                    $assignData = [
                        'assigned_to_user_type' => $assignment['assigned_to_user_type'],
                        'assigned_to_office_code' => $assignment['assigned_to_office_code'],
                        'assigned_to_user_id' => $assignment['assigned_to_user_id'],
                        'assignment_type' => $assignment['assignment_type'],
                        'can_edit' => $assignment['can_edit'],
                        'can_delete' => $assignment['can_delete'],
                        'can_review' => $assignment['can_review'],
                        'assigned_by_user_id' => $_SESSION['user_id'] ?? null,
                        'expires_at' => $assignment['expires_at']
                    ];
                    
                    $this->assignForm($newFormId, $assignData);
                }
            }
            
            $this->conn->commit();
            
            $this->logActivity('duplicate_form', "Duplicated form ID: $formId to new ID: $newFormId");
            
            return [
                'success' => true,
                'new_form_id' => $newFormId,
                'message' => "Form duplicated successfully"
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'new_form_id' => null,
                'message' => "Error duplicating form: " . $e->getMessage()
            ];
        }
    }
    
    // ============================================
    // FORM FIELD MANAGEMENT METHODS
    // ============================================
    
    /**
     * Get form fields
     * 
     * @param int $formId Form ID
     * @return array List of fields
     */
    public function getFormFields(int $formId): array {
        $fields = [];
        
        try {
            $sql = "SELECT * FROM form_fields WHERE form_id = ? ORDER BY field_order, field_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $formId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                // Decode JSON fields
                $this->decodeFieldData($row);
                $fields[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting form fields: " . $e->getMessage());
        }
        
        return $fields;
    }
    
    /**
     * Add form field
     * 
     * @param int $formId Form ID
     * @param array $fieldData Field data
     * @return array Result with success status and field ID
     */
    public function addFormField(int $formId, array $fieldData): array {
        try {
            // Validate required fields
            $required = ['field_code', 'field_label', 'field_type'];
            foreach ($required as $field) {
                if (empty($fieldData[$field])) {
                    throw new InvalidArgumentException("Missing required field: $field");
                }
            }
            
            // Validate field type
            if (!in_array($fieldData['field_type'], self::FIELD_TYPES)) {
                throw new InvalidArgumentException("Invalid field type: {$fieldData['field_type']}");
            }
            
            // Check if form exists
            $form = $this->getFormById($formId);
            if (!$form) {
                throw new Exception("Form not found");
            }
            
            // Check if field code already exists in this form
            if ($this->fieldCodeExists($formId, $fieldData['field_code'])) {
                throw new Exception("Field code '{$fieldData['field_code']}' already exists in this form");
            }
            
            // Get next field order
            if (empty($fieldData['field_order'])) {
                $fieldData['field_order'] = $this->getNextFieldOrder($formId);
            }
            
            // Prepare field data
            $fieldOptions = $this->encodeFieldData($fieldData['field_options'] ?? []);
            $validationRules = $this->encodeFieldData($fieldData['validation_rules'] ?? []);
            
            $sql = "INSERT INTO form_fields (
                form_id, field_code, field_label, field_type, field_options,
                validation_rules, is_required, field_order, default_value,
                placeholder, hint_text, visibility_condition, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("SQL preparation failed: " . $this->conn->error);
            }
            
            $isRequired = $fieldData['is_required'] ?? 0;
            $defaultValue = $fieldData['default_value'] ?? '';
            $placeholder = $fieldData['placeholder'] ?? '';
            $hintText = $fieldData['hint_text'] ?? '';
            $visibilityCondition = $fieldData['visibility_condition'] ?? '';
            
            $stmt->bind_param(
                "isssssiissss",
                $formId,
                $fieldData['field_code'],
                $fieldData['field_label'],
                $fieldData['field_type'],
                $fieldOptions,
                $validationRules,
                $isRequired,
                $fieldData['field_order'],
                $defaultValue,
                $placeholder,
                $hintText,
                $visibilityCondition
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $fieldId = $stmt->insert_id;
            
            $this->logActivity('add_form_field', "Added field '{$fieldData['field_label']}' to form ID: $formId");
            
            return [
                'success' => true,
                'field_id' => $fieldId,
                'message' => "Field added successfully"
            ];
            
        } catch (InvalidArgumentException $e) {
            return [
                'success' => false,
                'field_id' => null,
                'message' => $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'field_id' => null,
                'message' => "Error adding field: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Update form field
     * 
     * @param int $fieldId Field ID
     * @param array $fieldData Updated field data
     * @return array Result with success status
     */
    public function updateFormField(int $fieldId, array $fieldData): array {
        try {
            // Get existing field
            $field = $this->getFormFieldById($fieldId);
            if (!$field) {
                throw new Exception("Field not found");
            }
            
            // Check field code uniqueness if being updated
            if (isset($fieldData['field_code']) && $fieldData['field_code'] !== $field['field_code']) {
                if ($this->fieldCodeExists($field['form_id'], $fieldData['field_code'], $fieldId)) {
                    throw new Exception("Field code '{$fieldData['field_code']}' already exists in this form");
                }
            }
            
            // Build update query
            $fields = [];
            $params = [];
            $types = "";
            
            $updatableFields = [
                'field_code' => 's',
                'field_label' => 's',
                'field_type' => 's',
                'field_options' => 's',
                'validation_rules' => 's',
                'is_required' => 'i',
                'field_order' => 'i',
                'default_value' => 's',
                'placeholder' => 's',
                'hint_text' => 's',
                'visibility_condition' => 's'
            ];
            
            foreach ($updatableFields as $fieldName => $type) {
                if (array_key_exists($fieldName, $fieldData)) {
                    $fields[] = "$fieldName = ?";
                    
                    // Encode JSON data if needed
                    if (in_array($fieldName, ['field_options', 'validation_rules']) && is_array($fieldData[$fieldName])) {
                        $params[] = $this->encodeFieldData($fieldData[$fieldName]);
                    } else {
                        $params[] = $fieldData[$fieldName];
                    }
                    
                    $types .= $type;
                }
            }
            
            if (empty($fields)) {
                throw new Exception("No fields to update");
            }
            
            $fields[] = "updated_at = NOW()";
            
            $params[] = $fieldId;
            $types .= "i";
            
            $sql = "UPDATE form_fields SET " . implode(', ', $fields) . " WHERE field_id = ?";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("SQL preparation failed: " . $this->conn->error);
            }
            
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $this->logActivity('update_form_field', "Updated field ID: $fieldId");
            
            return [
                'success' => true,
                'message' => "Field updated successfully"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Error updating field: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete form field
     * 
     * @param int $fieldId Field ID
     * @return array Result with success status
     */
    public function deleteFormField(int $fieldId): array {
        try {
            // Get field info for validation
            $field = $this->getFormFieldById($fieldId);
            if (!$field) {
                throw new Exception("Field not found");
            }
            
            // Check if field has responses
            $hasResponses = $this->fieldHasResponses($fieldId);
            if ($hasResponses) {
                throw new Exception("Cannot delete field with existing responses");
            }
            
            $sql = "DELETE FROM form_fields WHERE field_id = ?";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("SQL preparation failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("i", $fieldId);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $this->logActivity('delete_form_field', "Deleted field '{$field['field_label']}' from form ID: {$field['form_id']}");
            
            return [
                'success' => true,
                'message' => "Field deleted successfully"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Error deleting field: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Reorder form fields
     * 
     * @param array $fieldOrders Array of field orders [['field_id' => X, 'field_order' => Y]]
     * @return array Result with success status
     */
    public function reorderFormFields(array $fieldOrders): array {
        try {
            $this->conn->begin_transaction();
            
            foreach ($fieldOrders as $orderData) {
                if (!isset($orderData['field_id']) || !isset($orderData['field_order'])) {
                    throw new InvalidArgumentException("Invalid field order data");
                }
                
                $sql = "UPDATE form_fields SET field_order = ? WHERE field_id = ?";
                $stmt = $this->conn->prepare($sql);
                
                if (!$stmt) {
                    throw new Exception("SQL preparation failed: " . $this->conn->error);
                }
                
                $stmt->bind_param("ii", $orderData['field_order'], $orderData['field_id']);
                
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                
                $stmt->close();
            }
            
            $this->conn->commit();
            
            $this->logActivity('reorder_form_fields', "Reordered form fields");
            
            return [
                'success' => true,
                'message' => "Fields reordered successfully"
            ];
            
        } catch (InvalidArgumentException $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => "Error reordering fields: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get form field by ID
     * 
     * @param int $fieldId Field ID
     * @return array|null Field data or null if not found
     */
    public function getFormFieldById(int $fieldId): ?array {
        try {
            $sql = "SELECT * FROM form_fields WHERE field_id = ?";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                return null;
            }
            
            $stmt->bind_param("i", $fieldId);
            $stmt->execute();
            $result = $stmt->get_result();
            $field = $result->fetch_assoc();
            
            if ($field) {
                $this->decodeFieldData($field);
            }
            
            return $field;
            
        } catch (Exception $e) {
            error_log("Error getting form field by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if field code exists
     * 
     * @param int $formId Form ID
     * @param string $fieldCode Field code to check
     * @param int|null $excludeFieldId Field ID to exclude
     * @return bool True if code exists
     */
    public function fieldCodeExists(int $formId, string $fieldCode, ?int $excludeFieldId = null): bool {
        try {
            $sql = "SELECT COUNT(*) as count FROM form_fields 
                    WHERE form_id = ? AND field_code = ?";
            $params = [$formId, $fieldCode];
            $types = "is";
            
            if ($excludeFieldId) {
                $sql .= " AND field_id != ?";
                $params[] = $excludeFieldId;
                $types .= "i";
            }
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                return false;
            }
            
            if (count($params) == 3) {
                $stmt->bind_param($types, $params[0], $params[1], $params[2]);
            } else {
                $stmt->bind_param($types, $params[0], $params[1]);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return ($row['count'] > 0);
            
        } catch (Exception $e) {
            error_log("Error checking field code: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check field code availability
     * 
     * @param int $formId Form ID
     * @param string $fieldCode Field code to check
     * @param int|null $excludeFieldId Field ID to exclude
     * @return array Availability check result
     */
    public function checkFieldCode(int $formId, string $fieldCode, ?int $excludeFieldId = null): array {
        try {
            $available = !$this->fieldCodeExists($formId, $fieldCode, $excludeFieldId);
            
            return [
                'available' => $available,
                'message' => $available ? "Field code is available" : "Field code already exists"
            ];
            
        } catch (Exception $e) {
            return [
                'available' => false,
                'message' => "Error checking field code: " . $e->getMessage()
            ];
        }
    }
    
    // ============================================
    // FORM ASSIGNMENT METHODS
    // ============================================
    
    /**
     * Get form assignments
     * 
     * @param int $formId Form ID
     * @return array List of assignments
     */
    public function getFormAssignments(int $formId): array {
        $assignments = [];
        
        try {
            $sql = "SELECT 
                    fa.*,
                    u_assigned.username AS assigned_to_username,
                    u_assigned.office_name AS assigned_to_office_name,
                    u_assigned_by.username AS assigned_by_username
                    FROM form_assignments fa
                    LEFT JOIN users u_assigned ON fa.assigned_to_user_id = u_assigned.user_id
                    LEFT JOIN users u_assigned_by ON fa.assigned_by_user_id = u_assigned_by.user_id
                    WHERE fa.form_id = ?
                    ORDER BY fa.assigned_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("i", $formId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    $assignments[] = $row;
                }
            }
            
        } catch (Exception $e) {
            error_log("Error getting form assignments: " . $e->getMessage());
        }
        
        return $assignments;
    }
    
    /**
     * Assign form to user or office
     * 
     * @param int $formId Form ID
     * @param array $assignData Assignment data
     * @return array Result with success status
     */
    public function assignForm(int $formId, array $assignData): array {
        try {
            // Validate form exists
            $form = $this->getFormById($formId);
            if (!$form) {
                throw new Exception("Form not found");
            }
            
            // Validate assignment data
            if (empty($assignData['assigned_to_user_type'])) {
                throw new InvalidArgumentException("User type is required");
            }
            
            if (!in_array($assignData['assigned_to_user_type'], self::USER_TYPES)) {
                throw new InvalidArgumentException("Invalid user type: {$assignData['assigned_to_user_type']}");
            }
            
            // Check if assignment already exists
            if ($this->assignmentExists($formId, $assignData)) {
                throw new Exception("This assignment already exists");
            }
            
            $sql = "INSERT INTO form_assignments (
                    form_id, assigned_to_user_type, assigned_to_office_code,
                    assigned_to_user_id, assignment_type, can_edit, can_delete,
                    can_review, assigned_by_user_id, assigned_at, expires_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("SQL preparation failed: " . $this->conn->error);
            }
            
            $assignedToOfficeCode = $assignData['assigned_to_office_code'] ?? '';
            $assignedToUserId = $assignData['assigned_to_user_id'] ?? null;
            $assignmentType = $assignData['assignment_type'] ?? 'fill';
            $canEdit = $assignData['can_edit'] ?? 1;
            $canDelete = $assignData['can_delete'] ?? 0;
            $canReview = $assignData['can_review'] ?? 0;
            $assignedByUserId = $assignData['assigned_by_user_id'] ?? null;
            $expiresAt = $assignData['expires_at'] ?? null;
            
            $stmt->bind_param(
                "issisiisss",
                $formId,
                $assignData['assigned_to_user_type'],
                $assignedToOfficeCode,
                $assignedToUserId,
                $assignmentType,
                $canEdit,
                $canDelete,
                $canReview,
                $assignedByUserId,
                $expiresAt
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $this->logActivity('assign_form', "Assigned form ID: $formId", $assignedByUserId);
            
            return [
                'success' => true,
                'message' => "Form assigned successfully"
            ];
            
        } catch (InvalidArgumentException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Error assigning form: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get forms assigned to GN office
     * 
     * @param string $gnId GN office code
     * @param string $targetEntity Target entity type
     * @return array List of assigned forms
     */
    public function getAssignedFormsForGN(string $gnId, string $targetEntity = 'both'): array {
        $forms = [];
        
        try {
            $now = date('Y-m-d H:i:s');
            
            $sql = "SELECT 
                    f.form_id,
                    f.form_code,
                    f.form_name,
                    f.form_description,
                    f.form_type,
                    f.target_entity,
                    fa.assigned_at,
                    fa.expires_at,
                    fa.assignment_type,
                    fa.can_edit,
                    fa.can_delete,
                    fa.can_review,
                    u.username as assigned_by_name
                FROM form_assignments fa
                JOIN forms f ON fa.form_id = f.form_id
                LEFT JOIN users u ON fa.assigned_by_user_id = u.user_id
                WHERE f.is_active = 1 
                AND (f.target_entity = ? OR f.target_entity = 'both')
                AND fa.assigned_to_user_type = 'gn'
                AND fa.assigned_to_office_code = ?
                AND (fa.expires_at IS NULL OR fa.expires_at >= ?)
                AND (f.start_date IS NULL OR f.start_date <= ?)
                AND (f.end_date IS NULL OR f.end_date >= ?)
                ORDER BY fa.assigned_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $forms;
            }
            
            $stmt->bind_param("sssss", $targetEntity, $gnId, $now, $now, $now);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $forms[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting assigned forms for GN: " . $e->getMessage());
        }
        
        return $forms;
    }
    
    /**
     * Get form assignments for user
     * 
     * @param array $filters Search filters
     * @return array List of assignments
     */
    public function getFormAssignmentsForUser(array $filters = []): array {
        $assignments = [];
        $whereClauses = [];
        $params = [];
        $types = "";
        
        try {
            $now = date('Y-m-d H:i:s');
            
            $sql = "SELECT 
                    fa.*,
                    f.form_name,
                    f.form_code,
                    f.target_entity,
                    u_assigned.username as assigned_to_username,
                    u_assigned.office_name as assigned_to_office_name,
                    u_assigned_by.username as assigned_by_username
                FROM form_assignments fa
                JOIN forms f ON fa.form_id = f.form_id
                LEFT JOIN users u_assigned ON fa.assigned_to_user_id = u_assigned.user_id
                LEFT JOIN users u_assigned_by ON fa.assigned_by_user_id = u_assigned_by.user_id
                WHERE f.is_active = 1
                AND (fa.expires_at IS NULL OR fa.expires_at >= ?)
                AND (f.start_date IS NULL OR f.start_date <= ?)
                AND (f.end_date IS NULL OR f.end_date >= ?)";
            
            $params[] = $now;
            $params[] = $now;
            $params[] = $now;
            $types .= "sss";
            
            // Apply filters
            if (!empty($filters['user_type'])) {
                $whereClauses[] = "fa.assigned_to_user_type = ?";
                $params[] = $filters['user_type'];
                $types .= "s";
            }
            
            if (!empty($filters['office_code'])) {
                $whereClauses[] = "fa.assigned_to_office_code = ?";
                $params[] = $filters['office_code'];
                $types .= "s";
            }
            
            if (!empty($filters['user_id'])) {
                $whereClauses[] = "fa.assigned_to_user_id = ?";
                $params[] = $filters['user_id'];
                $types .= "i";
            }
            
            if (!empty($filters['form_id'])) {
                $whereClauses[] = "fa.form_id = ?";
                $params[] = $filters['form_id'];
                $types .= "i";
            }
            
            if (!empty($filters['assignment_type'])) {
                $whereClauses[] = "fa.assignment_type = ?";
                $params[] = $filters['assignment_type'];
                $types .= "s";
            }
            
            if (count($whereClauses) > 0) {
                $sql .= " AND " . implode(" AND ", $whereClauses);
            }
            
            $sql .= " ORDER BY fa.assigned_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $assignments;
            }
            
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $assignments[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting form assignments for user: " . $e->getMessage());
        }
        
        return $assignments;
    }
    
    /**
     * Check if user can fill form
     * 
     * @param int $formId Form ID
     * @param int $userId User ID
     * @param string $userType User type
     * @param string $officeCode Office code
     * @return bool True if user can fill form
     */
    public function canUserFillForm(int $formId, int $userId, string $userType, string $officeCode): bool {
        try {
            $now = date('Y-m-d H:i:s');
            
            $sql = "SELECT COUNT(*) as count
                    FROM form_assignments fa
                    WHERE fa.form_id = ? 
                    AND (
                        (fa.assigned_to_user_type = ? AND fa.assigned_to_office_code = ?)
                        OR fa.assigned_to_user_id = ?
                    )
                    AND (fa.expires_at IS NULL OR fa.expires_at >= ?)
                    AND fa.assignment_type IN ('fill', 'all')";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
            
            $stmt->bind_param("issis", $formId, $userType, $officeCode, $userId, $now);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return ($row['count'] > 0);
            
        } catch (Exception $e) {
            error_log("Error checking form permission: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user can review form submissions
     * 
     * @param int $formId Form ID
     * @param int $userId User ID
     * @param string $userType User type
     * @param string $officeCode Office code
     * @return bool True if user can review
     */
    public function canUserReviewForm(int $formId, int $userId, string $userType, string $officeCode): bool {
        try {
            $now = date('Y-m-d H:i:s');
            
            $sql = "SELECT COUNT(*) as count
                    FROM form_assignments fa
                    WHERE fa.form_id = ? 
                    AND (
                        (fa.assigned_to_user_type = ? AND fa.assigned_to_office_code = ?)
                        OR fa.assigned_to_user_id = ?
                    )
                    AND (fa.expires_at IS NULL OR fa.expires_at >= ?)
                    AND fa.can_review = 1";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
            
            $stmt->bind_param("issis", $formId, $userType, $officeCode, $userId, $now);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return ($row['count'] > 0);
            
        } catch (Exception $e) {
            error_log("Error checking review permission: " . $e->getMessage());
            return false;
        }
    }
    
    // ============================================
    // FORM SUBMISSION METHODS
    // ============================================
    
    /**
     * Get member form submissions
     * 
     * @param int $citizenId Citizen ID
     * @param string|null $gnId GN office code
     * @return array List of submissions
     */
    public function getMemberSubmissions(int $citizenId, ?string $gnId = null): array {
        $submissions = [];
        
        try {
            $sql = "SELECT 
                    fs.submission_id,
                    fs.submission_status,
                    fs.submission_date,
                    fs.review_date,
                    fs.review_notes,
                    fs.total_fields,
                    fs.completed_fields,
                    fs.is_latest,
                    fs.version,
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
                WHERE fs.citizen_id = ? 
                AND fs.is_latest = 1";
            
            // Add GN filter if provided
            if ($gnId) {
                $sql .= " AND fs.gn_id = ?";
            }
            
            $sql .= " ORDER BY fs.submission_date DESC";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $submissions;
            }
            
            if ($gnId) {
                $stmt->bind_param("is", $citizenId, $gnId);
            } else {
                $stmt->bind_param("i", $citizenId);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                // Calculate completion percentage
                if ($row['total_fields'] > 0) {
                    $row['completion_percentage'] = round(($row['completed_fields'] / $row['total_fields']) * 100);
                } else {
                    $row['completion_percentage'] = 0;
                }
                
                $submissions[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting member submissions: " . $e->getMessage());
        }
        
        return $submissions;
    }
    
    /**
     * Get family form submissions
     * 
     * @param string $familyId Family ID
     * @param string|null $gnId GN office code
     * @return array List of submissions
     */
    public function getFamilySubmissions(string $familyId, ?string $gnId = null): array {
        $submissions = [];
        
        try {
            $sql = "SELECT 
                    fs.submission_id,
                    fs.submission_status,
                    fs.submission_date,
                    fs.review_date,
                    fs.review_notes,
                    fs.total_fields,
                    fs.completed_fields,
                    fs.is_latest,
                    fs.version,
                    f.form_id,
                    f.form_code,
                    f.form_name,
                    f.form_description,
                    f.form_type,
                    f.target_entity,
                    u.username as reviewed_by_name
                FROM form_submissions_family fs
                JOIN forms f ON fs.form_id = f.form_id
                LEFT JOIN users u ON fs.reviewed_by_user_id = u.user_id
                WHERE fs.family_id = ? 
                AND fs.is_latest = 1";
            
            // Add GN filter if provided
            if ($gnId) {
                $sql .= " AND fs.gn_id = ?";
            }
            
            $sql .= " ORDER BY fs.submission_date DESC";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $submissions;
            }
            
            if ($gnId) {
                $stmt->bind_param("ss", $familyId, $gnId);
            } else {
                $stmt->bind_param("s", $familyId);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                // Calculate completion percentage
                if ($row['total_fields'] > 0) {
                    $row['completion_percentage'] = round(($row['completed_fields'] / $row['total_fields']) * 100);
                } else {
                    $row['completion_percentage'] = 0;
                }
                
                $submissions[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting family submissions: " . $e->getMessage());
        }
        
        return $submissions;
    }
    
    /**
     * Get form submissions with filters
     * 
     * @param array $filters Search filters
     * @param string $type Submission type ('member' or 'family')
     * @return array List of submissions
     */
    public function getFormSubmissions(array $filters = [], string $type = 'member'): array {
        $submissions = [];
        $whereClauses = [];
        $params = [];
        $types = "";
        
        try {
            // Determine which table to query
            $tableName = ($type === 'family') ? 'form_submissions_family' : 'form_submissions_member';
            
            $sql = "SELECT fs.*, f.form_name, f.form_code, f.target_entity,
                           u_submitted.username as submitted_by_name,
                           u_reviewed.username as reviewed_by_name
                    FROM $tableName fs
                    JOIN forms f ON fs.form_id = f.form_id
                    LEFT JOIN users u_submitted ON fs.submitted_by_user_id = u_submitted.user_id
                    LEFT JOIN users u_reviewed ON fs.reviewed_by_user_id = u_reviewed.user_id
                    WHERE fs.is_latest = 1";
            
            // Apply filters
            if (!empty($filters['form_id'])) {
                $whereClauses[] = "fs.form_id = ?";
                $params[] = $filters['form_id'];
                $types .= "i";
            }
            
            if (!empty($filters['gn_id'])) {
                $whereClauses[] = "fs.gn_id = ?";
                $params[] = $filters['gn_id'];
                $types .= "s";
            }
            
            if (!empty($filters['submission_status'])) {
                $whereClauses[] = "fs.submission_status = ?";
                $params[] = $filters['submission_status'];
                $types .= "s";
            }
            
            if (!empty($filters['citizen_id']) && $type === 'member') {
                $whereClauses[] = "fs.citizen_id = ?";
                $params[] = $filters['citizen_id'];
                $types .= "i";
            }
            
            if (!empty($filters['family_id'])) {
                $whereClauses[] = "fs.family_id = ?";
                $params[] = $filters['family_id'];
                $types .= "s";
            }
            
            if (!empty($filters['submitted_by'])) {
                $whereClauses[] = "fs.submitted_by_user_id = ?";
                $params[] = $filters['submitted_by'];
                $types .= "i";
            }
            
            if (!empty($filters['reviewed_by'])) {
                $whereClauses[] = "fs.reviewed_by_user_id = ?";
                $params[] = $filters['reviewed_by'];
                $types .= "i";
            }
            
            if (!empty($filters['date_from'])) {
                $whereClauses[] = "DATE(fs.submission_date) >= ?";
                $params[] = $filters['date_from'];
                $types .= "s";
            }
            
            if (!empty($filters['date_to'])) {
                $whereClauses[] = "DATE(fs.submission_date) <= ?";
                $params[] = $filters['date_to'];
                $types .= "s";
            }
            
            if (!empty($filters['search'])) {
                $searchTerm = "%{$filters['search']}%";
                $whereClauses[] = "(f.form_name LIKE ? OR f.form_code LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= "ss";
            }
            
            if (count($whereClauses) > 0) {
                $sql .= " AND " . implode(" AND ", $whereClauses);
            }
            
            $sql .= " ORDER BY fs.submission_date DESC";
            
            // Add pagination if requested
            if (!empty($filters['limit'])) {
                $limit = (int)$filters['limit'];
                $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;
                $sql .= " LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                $types .= "ii";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $submissions;
            }
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                // Format dates
                if ($row['submission_date']) {
                    $row['submission_date_formatted'] = date('Y-m-d H:i:s', strtotime($row['submission_date']));
                }
                if ($row['review_date']) {
                    $row['review_date_formatted'] = date('Y-m-d H:i:s', strtotime($row['review_date']));
                }
                
                // Calculate completion percentage
                if ($row['total_fields'] > 0) {
                    $row['completion_percentage'] = round(($row['completed_fields'] / $row['total_fields']) * 100);
                } else {
                    $row['completion_percentage'] = 0;
                }
                
                $submissions[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting form submissions: " . $e->getMessage());
        }
        
        return $submissions;
    }
    
    /**
     * Get submission responses
     * 
     * @param int $submissionId Submission ID
     * @param string $type Submission type ('member' or 'family')
     * @return array Responses keyed by field ID
     */
    public function getSubmissionResponses(int $submissionId, string $type = 'member'): array {
        $responses = [];
        
        try {
            $responseTable = ($type === 'family') ? 'form_responses_family' : 'form_responses_member';
            
            $sql = "SELECT fr.*, ff.field_label, ff.field_type, ff.field_code
                    FROM $responseTable fr
                    JOIN form_fields ff ON fr.field_id = ff.field_id
                    WHERE fr.submission_id = ?
                    ORDER BY ff.field_order";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $responses;
            }
            
            $stmt->bind_param("i", $submissionId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $responses[$row['field_code']] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting submission responses: " . $e->getMessage());
        }
        
        return $responses;
    }
    
    /**
     * Save form submission
     * 
     * @param array $submissionData Submission data
     * @param string $type Submission type ('member' or 'family')
     * @return array Result with success status and submission ID
     */
    public function saveFormSubmission(array $submissionData, string $type = 'member'): array {
        try {
            $this->conn->begin_transaction();
            
            // Validate required data
            $required = ['form_id', 'submitted_by_user_id', 'submission_status', 'gn_id'];
            
            if ($type === 'member') {
                $required[] = 'citizen_id';
                $required[] = 'family_id';
            } else {
                $required[] = 'family_id';
            }
            
            foreach ($required as $field) {
                if (empty($submissionData[$field])) {
                    throw new InvalidArgumentException("Missing required field: $field");
                }
            }
            
            // Validate submission status
            if (!in_array($submissionData['submission_status'], self::SUBMISSION_STATUSES)) {
                throw new InvalidArgumentException("Invalid submission status: {$submissionData['submission_status']}");
            }
            
            // Check if user can submit this form
            $canSubmit = $this->canSubmitForm(
                $submissionData['form_id'],
                $submissionData['submitted_by_user_id'],
                $submissionData['user_type'] ?? '',
                $submissionData['office_code'] ?? ''
            );
            
            if (!$canSubmit['can_submit']) {
                throw new Exception($canSubmit['message']);
            }
            
            $tableName = ($type === 'family') ? 'form_submissions_family' : 'form_submissions_member';
            
            // Check if this is an update to existing submission
            $isUpdate = !empty($submissionData['submission_id']);
            $submissionId = $isUpdate ? (int)$submissionData['submission_id'] : null;
            
            if ($isUpdate) {
                // Get existing submission
                $existingSql = "SELECT * FROM $tableName WHERE submission_id = ?";
                $existingStmt = $this->conn->prepare($existingSql);
                $existingStmt->bind_param("i", $submissionId);
                $existingStmt->execute();
                $existingResult = $existingStmt->get_result();
                $existing = $existingResult->fetch_assoc();
                
                if (!$existing) {
                    throw new Exception('Submission not found');
                }
                
                // Mark old version as not latest
                $updateSql = "UPDATE $tableName SET is_latest = 0 WHERE submission_id = ?";
                $updateStmt = $this->conn->prepare($updateSql);
                $updateStmt->bind_param("i", $submissionId);
                $updateStmt->execute();
                
                // Get next version number
                $version = $existing['version'] + 1;
            } else {
                $version = 1;
            }
            
            // Calculate completion stats
            $totalFields = count($this->getFormFields($submissionData['form_id']));
            $completedFields = !empty($submissionData['responses']) ? count($submissionData['responses']) : 0;
            
            // Insert new submission
            if ($type === 'member') {
                $insertSql = "INSERT INTO $tableName (
                    form_id, citizen_id, family_id, gn_id,
                    submitted_by_user_id, submission_status, total_fields,
                    completed_fields, submission_date, is_latest, version,
                    ip_address, user_agent, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW(), NOW())";
                
                $stmt = $this->conn->prepare($insertSql);
                $submissionDate = ($submissionData['submission_status'] === 'draft') ? null : date('Y-m-d H:i:s');
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                
                $stmt->bind_param(
                    "isssisiiisss",
                    $submissionData['form_id'],
                    $submissionData['citizen_id'],
                    $submissionData['family_id'],
                    $submissionData['gn_id'],
                    $submissionData['submitted_by_user_id'],
                    $submissionData['submission_status'],
                    $totalFields,
                    $completedFields,
                    $submissionDate,
                    $version,
                    $ipAddress,
                    $userAgent
                );
            } else {
                $insertSql = "INSERT INTO $tableName (
                    form_id, family_id, gn_id,
                    submitted_by_user_id, submission_status, total_fields,
                    completed_fields, submission_date, is_latest, version,
                    ip_address, user_agent, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW(), NOW())";
                
                $stmt = $this->conn->prepare($insertSql);
                $submissionDate = ($submissionData['submission_status'] === 'draft') ? null : date('Y-m-d H:i:s');
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                
                $stmt->bind_param(
                    "issisiiisss",
                    $submissionData['form_id'],
                    $submissionData['family_id'],
                    $submissionData['gn_id'],
                    $submissionData['submitted_by_user_id'],
                    $submissionData['submission_status'],
                    $totalFields,
                    $completedFields,
                    $submissionDate,
                    $version,
                    $ipAddress,
                    $userAgent
                );
            }
            
            if (!$stmt) {
                throw new Exception("SQL preparation failed");
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $newSubmissionId = $stmt->insert_id;
            
            // Save form responses if provided
            if (!empty($submissionData['responses']) && is_array($submissionData['responses'])) {
                $responseTable = ($type === 'family') ? 'form_responses_family' : 'form_responses_member';
                
                foreach ($submissionData['responses'] as $fieldCode => $response) {
                    // Get field ID from field code
                    $field = $this->getFieldByCode($submissionData['form_id'], $fieldCode);
                    if (!$field) {
                        continue;
                    }
                    
                    $responseSql = "INSERT INTO $responseTable (
                        submission_id, field_id, field_value,
                        file_path, file_name, file_size, file_type,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        field_value = VALUES(field_value),
                        file_path = VALUES(file_path),
                        file_name = VALUES(file_name),
                        file_size = VALUES(file_size),
                        file_type = VALUES(file_type),
                        updated_at = NOW()";
                    
                    $responseStmt = $this->conn->prepare($responseSql);
                    
                    if ($responseStmt) {
                        $fieldValue = is_array($response) ? json_encode($response) : $response;
                        $filePath = $submissionData['files'][$fieldCode]['path'] ?? '';
                        $fileName = $submissionData['files'][$fieldCode]['name'] ?? '';
                        $fileSize = $submissionData['files'][$fieldCode]['size'] ?? null;
                        $fileType = $submissionData['files'][$fieldCode]['type'] ?? '';
                        
                        $responseStmt->bind_param(
                            "iisssis",
                            $newSubmissionId,
                            $field['field_id'],
                            $fieldValue,
                            $filePath,
                            $fileName,
                            $fileSize,
                            $fileType
                        );
                        $responseStmt->execute();
                        $responseStmt->close();
                    }
                }
            }
            
            $this->conn->commit();
            
            $action = $isUpdate ? 'updated' : 'created';
            $this->logActivity(
                'save_form_submission', 
                "Form submission $action (ID: $newSubmissionId, Type: $type, Status: {$submissionData['submission_status']})",
                $submissionData['submitted_by_user_id']
            );
            
            return [
                'success' => true, 
                'message' => 'Form saved successfully',
                'submission_id' => $newSubmissionId,
                'version' => $version
            ];
            
        } catch (InvalidArgumentException $e) {
            $this->conn->rollback();
            return [
                'success' => false, 
                'message' => $e->getMessage()
            ];
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false, 
                'message' => "Error saving submission: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete form submission
     * 
     * @param int $submissionId Submission ID
     * @param string $type Submission type ('member' or 'family')
     * @return array Result with success status
     */
    public function deleteFormSubmission(int $submissionId, string $type = 'member'): array {
        try {
            $tableName = ($type === 'family') ? 'form_submissions_family' : 'form_submissions_member';
            $responseTable = ($type === 'family') ? 'form_responses_family' : 'form_responses_member';
            
            $this->conn->begin_transaction();
            
            // Get submission info
            $sql = "SELECT fs.*, f.form_name 
                    FROM $tableName fs
                    JOIN forms f ON fs.form_id = f.form_id
                    WHERE fs.submission_id = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $submissionId);
            $stmt->execute();
            $result = $stmt->get_result();
            $submission = $result->fetch_assoc();
            
            if (!$submission) {
                throw new Exception('Submission not found');
            }
            
            // Check permissions
            $canDelete = $this->canUserDeleteSubmission(
                $submissionId,
                $_SESSION['user_id'] ?? 0,
                $_SESSION['user_type'] ?? '',
                $type
            );
            
            if (!$canDelete) {
                throw new Exception('You do not have permission to delete this submission');
            }
            
            // Delete responses
            $responseSql = "DELETE FROM $responseTable WHERE submission_id = ?";
            $responseStmt = $this->conn->prepare($responseSql);
            $responseStmt->bind_param("i", $submissionId);
            $responseStmt->execute();
            
            // Delete submission
            $deleteSql = "DELETE FROM $tableName WHERE submission_id = ?";
            $deleteStmt = $this->conn->prepare($deleteSql);
            $deleteStmt->bind_param("i", $submissionId);
            $deleteStmt->execute();
            
            $this->conn->commit();
            
            $this->logActivity(
                'delete_form_submission',
                "Deleted form submission (Form: {$submission['form_name']}, ID: $submissionId)"
            );
            
            return [
                'success' => true,
                'message' => 'Submission deleted successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => "Error deleting submission: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Review form submission
     * 
     * @param int $submissionId Submission ID
     * @param string $status New status ('approved' or 'rejected')
     * @param string $reviewNotes Review notes
     * @param int $reviewedBy User ID of reviewer
     * @param string $type Submission type ('member' or 'family')
     * @return array Result with success status
     */
    public function reviewFormSubmission(int $submissionId, string $status, string $reviewNotes, int $reviewedBy, string $type = 'member'): array {
        try {
            if (!in_array($status, ['approved', 'rejected'])) {
                throw new InvalidArgumentException("Invalid review status: $status");
            }
            
            $tableName = ($type === 'family') ? 'form_submissions_family' : 'form_submissions_member';
            
            $sql = "UPDATE $tableName 
                    SET submission_status = ?, 
                        reviewed_by_user_id = ?, 
                        review_date = NOW(),
                        review_notes = ?,
                        updated_at = NOW()
                    WHERE submission_id = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("SQL preparation failed");
            }
            
            $stmt->bind_param("sisi", $status, $reviewedBy, $reviewNotes, $submissionId);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $this->logActivity('review_form_submission', "Reviewed submission ID: $submissionId as $status", $reviewedBy);
            
            return [
                'success' => true,
                'message' => "Submission $status successfully"
            ];
            
        } catch (InvalidArgumentException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Error reviewing submission: " . $e->getMessage()
            ];
        }
    }
    
    // ============================================
    // FORM AVAILABILITY METHODS
    // ============================================
    
    /**
     * Get available forms for specific entity type
     * 
     * @param string $targetEntity Target entity type
     * @param string|null $gnId GN office code
     * @return array List of available forms
     */
    public function getAvailableFormsForEntity(string $targetEntity = 'both', ?string $gnId = null): array {
        $forms = [];
        
        try {
            if (!in_array($targetEntity, self::TARGET_ENTITIES)) {
                throw new InvalidArgumentException("Invalid target entity: $targetEntity");
            }
            
            $now = date('Y-m-d H:i:s');
            
            $sql = "SELECT * 
                    FROM forms 
                    WHERE is_active = 1 
                    AND (target_entity = ? OR target_entity = 'both')
                    AND (start_date IS NULL OR start_date <= ?)
                    AND (end_date IS NULL OR end_date >= ?)";
            
            // Exclude already assigned forms if GN ID is provided
            if ($gnId) {
                $sql .= " AND form_id NOT IN (
                    SELECT form_id FROM form_assignments 
                    WHERE assigned_to_user_type = 'gn' 
                    AND assigned_to_office_code = ?
                    AND (expires_at IS NULL OR expires_at >= ?)
                )";
            }
            
            $sql .= " ORDER BY form_name ASC";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $forms;
            }
            
            if ($gnId) {
                $stmt->bind_param("sssss", $targetEntity, $now, $now, $gnId, $now);
            } else {
                $stmt->bind_param("sss", $targetEntity, $now, $now);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $forms[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting available forms: " . $e->getMessage());
        }
        
        return $forms;
    }
    
    /**
     * Get available forms (alias for getAvailableFormsForEntity)
     */
    public function getAvailableForms(string $targetEntity = 'both', ?string $gnId = null): array {
        return $this->getAvailableFormsForEntity($targetEntity, $gnId);
    }
    
    /**
     * Check if user can submit form
     * 
     * @param int $formId Form ID
     * @param int $userId User ID
     * @param string $userType User type
     * @param string $officeCode Office code
     * @return array Result with can_submit flag and message
     */
    public function canSubmitForm(int $formId, int $userId, string $userType, string $officeCode): array {
        try {
            $form = $this->getFormById($formId);
            if (!$form) {
                return ['can_submit' => false, 'message' => 'Form not found'];
            }
            
            if (!$form['is_active']) {
                return ['can_submit' => false, 'message' => 'Form is not active'];
            }
            
            $now = date('Y-m-d H:i:s');
            
            // Check if form is within date range
            if ($form['start_date'] && $now < $form['start_date']) {
                return ['can_submit' => false, 'message' => 'Form has not started yet'];
            }
            
            if ($form['end_date'] && $now > $form['end_date']) {
                return ['can_submit' => false, 'message' => 'Form has expired'];
            }
            
            // Check if user has permission
            $canFill = $this->canUserFillForm($formId, $userId, $userType, $officeCode);
            if (!$canFill) {
                return ['can_submit' => false, 'message' => 'You do not have permission to fill this form'];
            }
            
            // Check max submissions per entity
            if ($form['max_submissions_per_entity'] > 0) {
                $submissionCount = $this->getEntityFormSubmissionCount($formId, $userId, $userType);
                if ($submissionCount >= $form['max_submissions_per_entity']) {
                    return ['can_submit' => false, 'message' => 'Maximum submissions reached for this form'];
                }
            }
            
            return ['can_submit' => true, 'message' => ''];
            
        } catch (Exception $e) {
            error_log("Error checking form submission permission: " . $e->getMessage());
            return ['can_submit' => false, 'message' => 'System error'];
        }
    }
    
    // ============================================
    // STATISTICS AND REPORTING METHODS
    // ============================================
    
    /**
     * Get form statistics
     * 
     * @param int|null $formId Form ID (optional)
     * @return array Statistics
     */
    public function getFormStats(?int $formId = null): array {
        $stats = [];
        
        try {
            if ($formId) {
                return $this->getFormSubmissionCounts($formId);
            }
            
            $stats = [
                'total_forms' => 0,
                'active_forms' => 0,
                'inactive_forms' => 0,
                'expired_forms' => 0,
                'upcoming_forms' => 0,
                'total_family_submissions' => 0,
                'total_member_submissions' => 0,
                'draft_submissions' => 0,
                'pending_submissions' => 0,
                'approved_submissions' => 0,
                'rejected_submissions' => 0
            ];
            
            // Form counts
            $formSql = "SELECT 
                       COUNT(*) as total_forms,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_forms,
                       SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_forms
                       FROM forms";
            
            $formResult = $this->conn->query($formSql);
            if ($formResult) {
                $row = $formResult->fetch_assoc();
                $stats['total_forms'] = intval($row['total_forms'] ?? 0);
                $stats['active_forms'] = intval($row['active_forms'] ?? 0);
                $stats['inactive_forms'] = intval($row['inactive_forms'] ?? 0);
            }
            
            // Expired and upcoming forms
            $now = date('Y-m-d H:i:s');
            $dateSql = "SELECT 
                        SUM(CASE WHEN end_date < ? THEN 1 ELSE 0 END) as expired_forms,
                        SUM(CASE WHEN start_date > ? THEN 1 ELSE 0 END) as upcoming_forms
                        FROM forms WHERE is_active = 1";
            
            $dateStmt = $this->conn->prepare($dateSql);
            if ($dateStmt) {
                $dateStmt->bind_param("ss", $now, $now);
                $dateStmt->execute();
                $dateResult = $dateStmt->get_result();
                $dateRow = $dateResult->fetch_assoc();
                $stats['expired_forms'] = intval($dateRow['expired_forms'] ?? 0);
                $stats['upcoming_forms'] = intval($dateRow['upcoming_forms'] ?? 0);
            }
            
            // Submission counts
            $submissionSql = "SELECT 
                             (SELECT COUNT(*) FROM form_submissions_family) as family_submissions,
                             (SELECT COUNT(*) FROM form_submissions_member) as member_submissions,
                             (SELECT COUNT(*) FROM form_submissions_family WHERE submission_status = 'draft') + 
                             (SELECT COUNT(*) FROM form_submissions_member WHERE submission_status = 'draft') as draft_submissions,
                             (SELECT COUNT(*) FROM form_submissions_family WHERE submission_status IN ('submitted', 'pending_review')) + 
                             (SELECT COUNT(*) FROM form_submissions_member WHERE submission_status IN ('submitted', 'pending_review')) as pending_submissions,
                             (SELECT COUNT(*) FROM form_submissions_family WHERE submission_status = 'approved') + 
                             (SELECT COUNT(*) FROM form_submissions_member WHERE submission_status = 'approved') as approved_submissions,
                             (SELECT COUNT(*) FROM form_submissions_family WHERE submission_status = 'rejected') + 
                             (SELECT COUNT(*) FROM form_submissions_member WHERE submission_status = 'rejected') as rejected_submissions";
            
            $submissionResult = $this->conn->query($submissionSql);
            if ($submissionResult) {
                $row = $submissionResult->fetch_assoc();
                $stats['total_family_submissions'] = intval($row['family_submissions'] ?? 0);
                $stats['total_member_submissions'] = intval($row['member_submissions'] ?? 0);
                $stats['draft_submissions'] = intval($row['draft_submissions'] ?? 0);
                $stats['pending_submissions'] = intval($row['pending_submissions'] ?? 0);
                $stats['approved_submissions'] = intval($row['approved_submissions'] ?? 0);
                $stats['rejected_submissions'] = intval($row['rejected_submissions'] ?? 0);
            }
            
            // Calculate completion rate
            $totalSubmissions = $stats['total_family_submissions'] + $stats['total_member_submissions'];
            $approvedSubmissions = $stats['approved_submissions'];
            $stats['completion_rate'] = $totalSubmissions > 0 ? round(($approvedSubmissions / $totalSubmissions) * 100, 2) : 0;
            
        } catch (Exception $e) {
            error_log("Error getting form stats: " . $e->getMessage());
            
            $stats = [
                'total_forms' => 0,
                'active_forms' => 0,
                'inactive_forms' => 0,
                'total_family_submissions' => 0,
                'total_member_submissions' => 0
            ];
        }
        
        return $stats;
    }
    
    /**
     * Get form submission counts
     * 
     * @param int $formId Form ID
     * @return array Submission counts
     */
    public function getFormSubmissionCounts(int $formId): array {
        try {
            $sql = "SELECT 
                    (SELECT COUNT(*) FROM form_submissions_family WHERE form_id = ?) as family_submissions,
                    (SELECT COUNT(*) FROM form_submissions_member WHERE form_id = ?) as member_submissions,
                    (SELECT COUNT(*) FROM form_submissions_family WHERE form_id = ? AND submission_status = 'draft') + 
                    (SELECT COUNT(*) FROM form_submissions_member WHERE form_id = ? AND submission_status = 'draft') as draft_submissions,
                    (SELECT COUNT(*) FROM form_submissions_family WHERE form_id = ? AND submission_status IN ('submitted', 'pending_review')) + 
                    (SELECT COUNT(*) FROM form_submissions_member WHERE form_id = ? AND submission_status IN ('submitted', 'pending_review')) as pending_submissions,
                    (SELECT COUNT(*) FROM form_submissions_family WHERE form_id = ? AND submission_status = 'approved') + 
                    (SELECT COUNT(*) FROM form_submissions_member WHERE form_id = ? AND submission_status = 'approved') as approved_submissions,
                    (SELECT COUNT(*) FROM form_submissions_family WHERE form_id = ? AND submission_status = 'rejected') + 
                    (SELECT COUNT(*) FROM form_submissions_member WHERE form_id = ? AND submission_status = 'rejected') as rejected_submissions";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['family_submissions' => 0, 'member_submissions' => 0];
            }
            
            // Bind all parameters (each ? needs a value)
            $stmt->bind_param("iiiiiiiiii", 
                $formId, $formId, // family, member
                $formId, $formId, // draft
                $formId, $formId, // pending
                $formId, $formId, // approved
                $formId, $formId  // rejected
            );
            
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return [
                'family_submissions' => intval($row['family_submissions'] ?? 0),
                'member_submissions' => intval($row['member_submissions'] ?? 0),
                'draft_submissions' => intval($row['draft_submissions'] ?? 0),
                'pending_submissions' => intval($row['pending_submissions'] ?? 0),
                'approved_submissions' => intval($row['approved_submissions'] ?? 0),
                'rejected_submissions' => intval($row['rejected_submissions'] ?? 0),
                'total_submissions' => intval(($row['family_submissions'] ?? 0) + ($row['member_submissions'] ?? 0))
            ];
            
        } catch (Exception $e) {
            error_log("Error getting form submission counts: " . $e->getMessage());
            return [
                'family_submissions' => 0,
                'member_submissions' => 0,
                'total_submissions' => 0
            ];
        }
    }
    
    /**
     * Get user form statistics
     * 
     * @param int $userId User ID
     * @return array User form stats
     */
    public function getUserFormStats(int $userId): array {
        $stats = [
            'assigned_forms'        => 0,
            'draft_submissions'     => 0,
            'pending_review'        => 0,
            'completed_submissions' => 0,
            'rejected_submissions'  => 0,
            'total_submissions'     => 0
        ];

        try {
            // Get assigned forms count
            $sql = "SELECT COUNT(DISTINCT fa.form_id) AS count
                    FROM form_assignments fa
                    WHERE (fa.assigned_to_user_id = ? OR 
                          (fa.assigned_to_office_code = (SELECT office_code FROM users WHERE user_id = ?)))
                    AND (fa.expires_at IS NULL OR fa.expires_at >= NOW())";
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ii", $userId, $userId);
                $stmt->execute();
                $stmt->bind_result($stats['assigned_forms']);
                $stmt->fetch();
                $stmt->close();
            }

            // Get submission counts
            $sql = "SELECT 
                    SUM(CASE WHEN submission_status = 'draft' THEN 1 ELSE 0 END) as draft_submissions,
                    SUM(CASE WHEN submission_status IN ('submitted', 'pending_review') THEN 1 ELSE 0 END) as pending_review,
                    SUM(CASE WHEN submission_status = 'approved' THEN 1 ELSE 0 END) as completed_submissions,
                    SUM(CASE WHEN submission_status = 'rejected' THEN 1 ELSE 0 END) as rejected_submissions,
                    COUNT(*) as total_submissions
                    FROM (
                        SELECT submission_status FROM form_submissions_family WHERE submitted_by_user_id = ?
                        UNION ALL
                        SELECT submission_status FROM form_submissions_member WHERE submitted_by_user_id = ?
                    ) as all_submissions";
            
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ii", $userId, $userId);
                $stmt->execute();
                $stmt->bind_result(
                    $stats['draft_submissions'],
                    $stats['pending_review'],
                    $stats['completed_submissions'],
                    $stats['rejected_submissions'],
                    $stats['total_submissions']
                );
                $stmt->fetch();
                $stmt->close();
            }

        } catch (Exception $e) {
            error_log('Error getting user form stats: ' . $e->getMessage());
        }

        return $stats;
    }
    
    // ============================================
    // HELPER AND UTILITY METHODS
    // ============================================
    
    /**
     * Get next field order for form
     * 
     * @param int $formId Form ID
     * @return int Next field order
     */
    private function getNextFieldOrder(int $formId): int {
        try {
            $sql = "SELECT MAX(field_order) as max_order FROM form_fields WHERE form_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $formId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return ($row['max_order'] ?? 0) + 1;
            
        } catch (Exception $e) {
            error_log("Error getting next field order: " . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Check if field has responses
     * 
     * @param int $fieldId Field ID
     * @return bool True if field has responses
     */
    private function fieldHasResponses(int $fieldId): bool {
        try {
            // Check both member and family response tables
            $sql = "SELECT 
                    (SELECT COUNT(*) FROM form_responses_family WHERE field_id = ?) +
                    (SELECT COUNT(*) FROM form_responses_member WHERE field_id = ?) as total_responses";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $fieldId, $fieldId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return ($row['total_responses'] ?? 0) > 0;
            
        } catch (Exception $e) {
            error_log("Error checking field responses: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get field by code
     * 
     * @param int $formId Form ID
     * @param string $fieldCode Field code
     * @return array|null Field data or null
     */
    private function getFieldByCode(int $formId, string $fieldCode): ?array {
        try {
            $sql = "SELECT * FROM form_fields WHERE form_id = ? AND field_code = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("is", $formId, $fieldCode);
            $stmt->execute();
            $result = $stmt->get_result();
            $field = $result->fetch_assoc();
            
            if ($field) {
                $this->decodeFieldData($field);
            }
            
            return $field;
            
        } catch (Exception $e) {
            error_log("Error getting field by code: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Decode field data (JSON)
     * 
     * @param array &$field Field reference
     */
    private function decodeFieldData(array &$field): void {
        // Decode field options
        if (!empty($field['field_options']) && is_string($field['field_options'])) {
            $decoded = json_decode($field['field_options'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $field['field_options'] = $decoded;
            } else {
                $field['field_options'] = [];
            }
        }
        
        // Decode validation rules
        if (!empty($field['validation_rules']) && is_string($field['validation_rules'])) {
            $decoded = json_decode($field['validation_rules'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $field['validation_rules'] = $decoded;
            } else {
                $field['validation_rules'] = [];
            }
        }
    }
    
    /**
     * Encode field data to JSON
     * 
     * @param mixed $data Data to encode
     * @return string JSON encoded string
     */
    private function encodeFieldData($data): string {
        if (empty($data)) {
            return '';
        }
        
        if (is_array($data)) {
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        
        return $data;
    }
    
    /**
     * Check if assignment already exists
     * 
     * @param int $formId Form ID
     * @param array $assignData Assignment data
     * @return bool True if assignment exists
     */
    private function assignmentExists(int $formId, array $assignData): bool {
        try {
            $sql = "SELECT COUNT(*) as count FROM form_assignments 
                    WHERE form_id = ? 
                    AND assigned_to_user_type = ? 
                    AND assigned_to_office_code = ? 
                    AND (assigned_to_user_id = ? OR ? IS NULL)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param(
                "issii",
                $formId,
                $assignData['assigned_to_user_type'],
                $assignData['assigned_to_office_code'] ?? '',
                $assignData['assigned_to_user_id'] ?? null,
                $assignData['assigned_to_user_id'] ?? null
            );
            
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return ($row['count'] > 0);
            
        } catch (Exception $e) {
            error_log("Error checking assignment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get entity form submission count
     * 
     * @param int $formId Form ID
     * @param int $userId User ID
     * @param string $userType User type
     * @return int Submission count
     */
    private function getEntityFormSubmissionCount(int $formId, int $userId, string $userType): int {
        try {
            // This is a simplified version - you might need to adjust based on your entity logic
            $sql = "SELECT COUNT(*) as count 
                    FROM form_submissions_family 
                    WHERE form_id = ? AND submitted_by_user_id = ?
                    UNION ALL
                    SELECT COUNT(*) as count 
                    FROM form_submissions_member 
                    WHERE form_id = ? AND submitted_by_user_id = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iiii", $formId, $userId, $formId, $userId);
            $stmt->execute();
            
            $total = 0;
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $total += $row['count'];
            }
            
            return $total;
            
        } catch (Exception $e) {
            error_log("Error getting entity submission count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Check if user can delete submission
     * 
     * @param int $submissionId Submission ID
     * @param int $userId User ID
     * @param string $userType User type
     * @param string $type Submission type
     * @return bool True if user can delete
     */
    private function canUserDeleteSubmission(int $submissionId, int $userId, string $userType, string $type = 'member'): bool {
        try {
            $tableName = ($type === 'family') ? 'form_submissions_family' : 'form_submissions_member';
            
            $sql = "SELECT 
                    CASE 
                        WHEN fs.submitted_by_user_id = ? THEN 1
                        WHEN EXISTS (
                            SELECT 1 FROM form_assignments fa
                            WHERE fa.form_id = fs.form_id
                            AND (fa.assigned_to_user_id = ? OR 
                                (fa.assigned_to_user_type = ? AND fa.assigned_to_office_code = ?))
                            AND fa.can_delete = 1
                            AND (fa.expires_at IS NULL OR fa.expires_at >= NOW())
                        ) THEN 1
                        ELSE 0
                    END as can_delete
                    FROM $tableName fs
                    WHERE fs.submission_id = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iissi", $userId, $userId, $userType, $_SESSION['office_code'] ?? '', $submissionId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return ($row['can_delete'] ?? 0) == 1;
            
        } catch (Exception $e) {
            error_log("Error checking delete permission: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log activity
     * 
     * @param string $action Action type
     * @param string $details Action details
     * @param int|null $userId User ID
     */
    private function logActivity(string $action, string $details = '', ?int $userId = null): void {
        if (function_exists('logActivity')) {
            logActivity($action, $details, $userId);
        } else {
            // Fallback logging
            $logMessage = date('Y-m-d H:i:s') . " - Action: $action - Details: $details - User: " . ($userId ?? 'Unknown');
            error_log($logMessage);
        }
    }
    
    /**
     * Get database connection
     * 
     * @return mysqli Database connection
     */
    public function getConnection() {
        return $this->conn;
    }
    
    /**
     * Close database connection
     */
    public function closeConnection(): void {
        if ($this->conn) {  
            $this->conn->close();
        }
    }
    
    /**
     * Destructor to ensure connection is closed
     */
    public function __destruct() {
        $this->closeConnection();
    }
}
?>