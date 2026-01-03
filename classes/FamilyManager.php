<?php
/**
 * FamilyManager.php
 * Handles family and member management for FPMS
 */

class FamilyManager {
    private $conn;
    
    public function __construct() {
        $this->conn = getMainConnection();
    }
    
    // ============================================================================
    // FAMILY MANAGEMENT
    // ============================================================================
    
    /**
     * Get families by GN division
     */
    public function getFamiliesByGN($gnId) {
        try {
            $sql = "
                SELECT f.*, 
                       c.full_name as head_name,
                       c.identification_number as head_nic
                FROM families f
                LEFT JOIN citizens c ON f.family_head_nic = c.identification_number
                WHERE f.gn_id = ?
                ORDER BY f.created_at DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            $stmt->bind_param('s', $gnId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $families = [];
            while ($row = $result->fetch_assoc()) {
                $families[] = $row;
            }
            
            return $families;
            
        } catch (Exception $e) {
            error_log('Error getting families by GN: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get family by ID
     */
    public function getFamilyById($familyId) {
        try {
            $sql = "
                SELECT f.*, 
                       c.full_name as head_name,
                       c.identification_number as head_nic,
                       c.mobile_phone as head_phone,
                       c.date_of_birth as head_dob,
                       u.office_name as gn_name
                FROM families f
                LEFT JOIN citizens c ON f.family_head_nic = c.identification_number
                LEFT JOIN users u ON f.gn_id = u.office_code
                WHERE f.family_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return null;
            }
            
            $stmt->bind_param('s', $familyId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                return $row;
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('Error getting family by ID: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create new family
     */
    public function createFamily($familyData, $userId) {
        try {
            $this->conn->begin_transaction();
            
            // Generate family ID
            $familyId = $this->generateFamilyId($familyData['gn_id']);
            
            // Insert family
            $sql = "
                INSERT INTO families (
                    family_id, gn_id, original_gn_id, family_head_nic,
                    address, total_members, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $gnId = $familyData['gn_id'] ?? '';
            $headNic = $familyData['family_head_nic'] ?? null;
            $address = $familyData['address'] ?? '';
            $totalMembers = $familyData['total_members'] ?? 1;
            
            $stmt->bind_param(
                'sssssii',
                $familyId,
                $gnId,
                $gnId,
                $headNic,
                $address,
                $totalMembers,
                $userId
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            // If head NIC is provided, create head as citizen
            if ($headNic && !empty($familyData['head_data'])) {
                $headData = array_merge($familyData['head_data'], [
                    'family_id' => $familyId,
                    'relation_to_head' => 'head'
                ]);
                
                $this->createCitizen($headData, $userId);
            }
            
            $this->conn->commit();
            
            // Log activity
            $this->logActivity(
                $userId,
                'create_family',
                'families',
                $familyId,
                null,
                json_encode(['family_id' => $familyId, 'head_nic' => $headNic])
            );
            
            return ['success' => true, 'family_id' => $familyId];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log('Error creating family: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Update family
     */
    public function updateFamily($familyId, $familyData, $userId) {
        try {
            $sql = "
                UPDATE families SET
                    address = ?,
                    family_head_nic = ?,
                    total_members = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE family_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $address = $familyData['address'] ?? '';
            $headNic = $familyData['family_head_nic'] ?? null;
            $totalMembers = $familyData['total_members'] ?? 1;
            
            $stmt->bind_param(
                'ssis',
                $address,
                $headNic,
                $totalMembers,
                $familyId
            );
            
            if ($stmt->execute()) {
                // Log activity
                $this->logActivity(
                    $userId,
                    'update_family',
                    'families',
                    $familyId,
                    null,
                    json_encode(['family_id' => $familyId])
                );
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Database error: ' . $stmt->error];
            }
            
        } catch (Exception $e) {
            error_log('Error updating family: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error updating family: ' . $e->getMessage()];
        }
    }
    
    /**
     * Search families
     */
    public function searchFamilies($searchTerm, $gnId = null, $limit = 50) {
        try {
            $whereClauses = [];
            $params = [];
            $types = '';
            
            if ($searchTerm) {
                $searchTerm = "%{$searchTerm}%";
                $whereClauses[] = "(f.family_id LIKE ? OR f.address LIKE ? OR c.full_name LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= 'sss';
            }
            
            if ($gnId) {
                $whereClauses[] = "f.gn_id = ?";
                $params[] = $gnId;
                $types .= 's';
            }
            
            $whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : '';
            
            $sql = "
                SELECT f.*, 
                       c.full_name as head_name,
                       c.identification_number as head_nic,
                       u.office_name as gn_name
                FROM families f
                LEFT JOIN citizens c ON f.family_head_nic = c.identification_number
                LEFT JOIN users u ON f.gn_id = u.office_code
                $whereSql
                ORDER BY f.created_at DESC
                LIMIT ?
            ";
            
            $params[] = $limit;
            $types .= 'i';
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $families = [];
            while ($row = $result->fetch_assoc()) {
                $families[] = $row;
            }
            
            return $families;
            
        } catch (Exception $e) {
            error_log('Error searching families: ' . $e->getMessage());
            return [];
        }
    }
    
    // ============================================================================
    // MEMBER MANAGEMENT
    // ============================================================================
    
    /**
     * Get members by family
     */
    public function getMembersByFamily($familyId) {
        try {
            $sql = "
                SELECT c.*,
                       TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) as age
                FROM citizens c
                WHERE c.family_id = ?
                ORDER BY 
                    CASE WHEN c.relation_to_head = 'head' THEN 1 ELSE 2 END,
                    c.date_of_birth
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            $stmt->bind_param('s', $familyId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $members = [];
            while ($row = $result->fetch_assoc()) {
                $members[] = $row;
            }
            
            return $members;
            
        } catch (Exception $e) {
            error_log('Error getting members by family: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get member by ID
     */
    public function getMemberById($memberId) {
        try {
            $sql = "
                SELECT c.*, 
                       f.family_id,
                       f.gn_id,
                       f.address as family_address,
                       TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) as age
                FROM citizens c
                JOIN families f ON c.family_id = f.family_id
                WHERE c.citizen_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return null;
            }
            
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                return $row;
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('Error getting member by ID: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create new citizen/member
     */
    public function createCitizen($citizenData, $userId) {
        try {
            // Validate identification
            if (!$this->validateIdentification($citizenData)) {
                return ['success' => false, 'error' => 'Invalid identification data'];
            }
            
            $sql = "
                INSERT INTO citizens (
                    family_id, identification_type, identification_number,
                    full_name, name_with_initials, gender, date_of_birth,
                    ethnicity, religion, mobile_phone, home_phone, email,
                    address, relation_to_head, marital_status, is_alive
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            // Get values with defaults
            $familyId = $citizenData['family_id'] ?? '';
            $idType = $citizenData['identification_type'] ?? 'nic';
            $idNumber = $citizenData['identification_number'] ?? '';
            $fullName = $citizenData['full_name'] ?? '';
            $initials = $citizenData['name_with_initials'] ?? '';
            $gender = $citizenData['gender'] ?? 'male';
            $dob = $citizenData['date_of_birth'] ?? null;
            $ethnicity = $citizenData['ethnicity'] ?? null;
            $religion = $citizenData['religion'] ?? null;
            $mobile = $citizenData['mobile_phone'] ?? null;
            $homePhone = $citizenData['home_phone'] ?? null;
            $email = $citizenData['email'] ?? null;
            $address = $citizenData['address'] ?? null;
            $relation = $citizenData['relation_to_head'] ?? null;
            $marital = $citizenData['marital_status'] ?? null;
            $isAlive = isset($citizenData['is_alive']) ? (int)$citizenData['is_alive'] : 1;
            
            $stmt->bind_param(
                'sssssssssssssssi',
                $familyId,
                $idType,
                $idNumber,
                $fullName,
                $initials,
                $gender,
                $dob,
                $ethnicity,
                $religion,
                $mobile,
                $homePhone,
                $email,
                $address,
                $relation,
                $marital,
                $isAlive
            );
            
            if ($stmt->execute()) {
                $citizenId = $stmt->insert_id;
                
                // Update family member count
                $this->updateFamilyMemberCount($familyId);
                
                // Log activity
                $this->logActivity(
                    $userId,
                    'create_citizen',
                    'citizens',
                    $citizenId,
                    null,
                    json_encode(['citizen_id' => $citizenId, 'full_name' => $fullName])
                );
                
                return ['success' => true, 'citizen_id' => $citizenId];
            } else {
                // Check for duplicate identification
                if ($stmt->errno == 1062) {
                    return ['success' => false, 'error' => 'Identification number already exists'];
                }
                return ['success' => false, 'error' => 'Database error: ' . $stmt->error];
            }
            
        } catch (Exception $e) {
            error_log('Error creating citizen: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error creating citizen: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update citizen
     */
    public function updateCitizen($citizenId, $citizenData, $userId) {
        try {
            $sql = "
                UPDATE citizens SET
                    full_name = ?,
                    name_with_initials = ?,
                    gender = ?,
                    date_of_birth = ?,
                    ethnicity = ?,
                    religion = ?,
                    mobile_phone = ?,
                    home_phone = ?,
                    email = ?,
                    address = ?,
                    relation_to_head = ?,
                    marital_status = ?,
                    is_alive = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE citizen_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $fullName = $citizenData['full_name'] ?? '';
            $initials = $citizenData['name_with_initials'] ?? '';
            $gender = $citizenData['gender'] ?? 'male';
            $dob = $citizenData['date_of_birth'] ?? null;
            $ethnicity = $citizenData['ethnicity'] ?? null;
            $religion = $citizenData['religion'] ?? null;
            $mobile = $citizenData['mobile_phone'] ?? null;
            $homePhone = $citizenData['home_phone'] ?? null;
            $email = $citizenData['email'] ?? null;
            $address = $citizenData['address'] ?? null;
            $relation = $citizenData['relation_to_head'] ?? null;
            $marital = $citizenData['marital_status'] ?? null;
            $isAlive = isset($citizenData['is_alive']) ? (int)$citizenData['is_alive'] : 1;
            
            $stmt->bind_param(
                'ssssssssssssii',
                $fullName,
                $initials,
                $gender,
                $dob,
                $ethnicity,
                $religion,
                $mobile,
                $homePhone,
                $email,
                $address,
                $relation,
                $marital,
                $isAlive,
                $citizenId
            );
            
            if ($stmt->execute()) {
                // Log activity
                $this->logActivity(
                    $userId,
                    'update_citizen',
                    'citizens',
                    $citizenId,
                    null,
                    json_encode(['citizen_id' => $citizenId, 'full_name' => $fullName])
                );
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Database error: ' . $stmt->error];
            }
            
        } catch (Exception $e) {
            error_log('Error updating citizen: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error updating citizen: ' . $e->getMessage()];
        }
    }
    
    /**
     * Search members
     */
    public function searchMembers($searchTerm, $gnId = null, $limit = 50) {
        try {
            $whereClauses = [];
            $params = [];
            $types = '';
            
            if ($searchTerm) {
                $searchTerm = "%{$searchTerm}%";
                $whereClauses[] = "(c.full_name LIKE ? OR c.name_with_initials LIKE ? OR c.identification_number LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= 'sss';
            }
            
            if ($gnId) {
                $whereClauses[] = "f.gn_id = ?";
                $params[] = $gnId;
                $types .= 's';
            }
            
            $whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : '';
            
            $sql = "
                SELECT c.*, 
                       f.family_id,
                       f.gn_id,
                       u.office_name as gn_name,
                       TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) as age
                FROM citizens c
                JOIN families f ON c.family_id = f.family_id
                LEFT JOIN users u ON f.gn_id = u.office_code
                $whereSql
                ORDER BY c.created_at DESC
                LIMIT ?
            ";
            
            $params[] = $limit;
            $types .= 'i';
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $members = [];
            while ($row = $result->fetch_assoc()) {
                $members[] = $row;
            }
            
            return $members;
            
        } catch (Exception $e) {
            error_log('Error searching members: ' . $e->getMessage());
            return [];
        }
    }
    
    // ============================================================================
    // TRANSFER MANAGEMENT
    // ============================================================================
    
    /**
     * Request family transfer
     */
    public function requestFamilyTransfer($transferData, $userId) {
        try {
            $this->conn->begin_transaction();
            
            // Check if family exists
            $family = $this->getFamilyById($transferData['family_id']);
            if (!$family) {
                throw new Exception("Family not found");
            }
            
            // Check if already has pending transfer
            $sql = "
                SELECT 1 FROM families 
                WHERE family_id = ? AND has_pending_transfer = 1
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('s', $transferData['family_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                throw new Exception("Family already has a pending transfer request");
            }
            
            // Generate transfer ID
            $transferId = 'TR' . date('YmdHis') . rand(100, 999);
            
            // Insert transfer history
            $sql = "
                INSERT INTO transfer_history (
                    transfer_id, family_id, from_gn_id, to_gn_id,
                    transfer_reason, transfer_notes, requested_by_user_id,
                    request_date, current_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed");
            }
            
            $reason = $transferData['transfer_reason'] ?? '';
            $notes = $transferData['transfer_notes'] ?? '';
            
            $stmt->bind_param(
                'ssssssi',
                $transferId,
                $transferData['family_id'],
                $family['gn_id'],
                $transferData['to_gn_id'],
                $reason,
                $notes,
                $userId
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            // Update family to show pending transfer
            $sql = "
                UPDATE families SET 
                    has_pending_transfer = 1,
                    last_transfer_request = ?
                WHERE family_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('ss', $transferId, $transferData['family_id']);
            $stmt->execute();
            
            $this->conn->commit();
            
            // Log activity
            $this->logActivity(
                $userId,
                'request_transfer',
                'transfer_history',
                $transferId,
                null,
                json_encode(['family_id' => $transferData['family_id'], 'to_gn' => $transferData['to_gn_id']])
            );
            
            return ['success' => true, 'transfer_id' => $transferId];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log('Error requesting transfer: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get transfer requests for GN
     */
    public function getTransferRequests($gnId, $status = 'pending', $userType = 'gn') {
        try {
            if ($userType === 'gn') {
                // GN can see requests from their division
                $sql = "
                    SELECT th.*,
                           f.family_id,
                           f.address as family_address,
                           fu.office_name as from_gn_name,
                           tu.office_name as to_gn_name,
                           ru.username as requested_by_name
                    FROM transfer_history th
                    JOIN families f ON th.family_id = f.family_id
                    LEFT JOIN users fu ON th.from_gn_id = fu.office_code
                    LEFT JOIN users tu ON th.to_gn_id = tu.office_code
                    LEFT JOIN users ru ON th.requested_by_user_id = ru.user_id
                    WHERE (th.from_gn_id = ? OR th.to_gn_id = ?)
                    AND th.current_status = ?
                    ORDER BY th.request_date DESC
                ";
                
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param('sss', $gnId, $gnId, $status);
            } else {
                // Division/District can see all requests in their jurisdiction
                $sql = "
                    SELECT th.*,
                           f.family_id,
                           f.address as family_address,
                           fu.office_name as from_gn_name,
                           tu.office_name as to_gn_name,
                           ru.username as requested_by_name
                    FROM transfer_history th
                    JOIN families f ON th.family_id = f.family_id
                    LEFT JOIN users fu ON th.from_gn_id = fu.office_code
                    LEFT JOIN users tu ON th.to_gn_id = tu.office_code
                    LEFT JOIN users ru ON th.requested_by_user_id = ru.user_id
                    WHERE th.current_status = ?
                    ORDER BY th.request_date DESC
                ";
                
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param('s', $status);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $transfers = [];
            while ($row = $result->fetch_assoc()) {
                $transfers[] = $row;
            }
            
            return $transfers;
            
        } catch (Exception $e) {
            error_log('Error getting transfer requests: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Approve transfer request
     */
    public function approveTransfer($transferId, $userId, $notes = '') {
        try {
            $this->conn->begin_transaction();
            
            // Get transfer details
            $sql = "SELECT * FROM transfer_history WHERE transfer_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('s', $transferId);
            $stmt->execute();
            $result = $stmt->get_result();
            $transfer = $result->fetch_assoc();
            
            if (!$transfer) {
                throw new Exception("Transfer request not found");
            }
            
            // Update transfer
            $sql = "
                UPDATE transfer_history SET
                    approved_by_user_id = ?,
                    approval_date = NOW(),
                    current_status = 'approved',
                    completion_notes = ?
                WHERE transfer_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('iss', $userId, $notes, $transferId);
            $stmt->execute();
            
            // Update family GN
            $sql = "
                UPDATE families SET
                    gn_id = ?,
                    has_pending_transfer = 0,
                    is_transferred = 1
                WHERE family_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('ss', $transfer['to_gn_id'], $transfer['family_id']);
            $stmt->execute();
            
            $this->conn->commit();
            
            // Log activity
            $this->logActivity(
                $userId,
                'approve_transfer',
                'transfer_history',
                $transferId,
                null,
                json_encode(['transfer_id' => $transferId, 'family_id' => $transfer['family_id']])
            );
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log('Error approving transfer: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Reject transfer request
     */
    public function rejectTransfer($transferId, $userId, $reason = '') {
        try {
            $this->conn->begin_transaction();
            
            // Update transfer
            $sql = "
                UPDATE transfer_history SET
                    rejected_by_user_id = ?,
                    rejection_date = NOW(),
                    rejection_reason = ?,
                    current_status = 'rejected'
                WHERE transfer_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('iss', $userId, $reason, $transferId);
            $stmt->execute();
            
            // Update family to remove pending flag
            $sql = "
                UPDATE families SET has_pending_transfer = 0
                WHERE last_transfer_request = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('s', $transferId);
            $stmt->execute();
            
            $this->conn->commit();
            
            // Log activity
            $this->logActivity(
                $userId,
                'reject_transfer',
                'transfer_history',
                $transferId,
                null,
                json_encode(['transfer_id' => $transferId, 'reason' => $reason])
            );
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log('Error rejecting transfer: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ============================================================================
    // UTILITY METHODS
    // ============================================================================
    
    /**
     * Generate unique family ID
     */
    private function generateFamilyId($gnId) {
        $prefix = 'FAM-' . $gnId . '-';
        $year = date('Y');
        $month = date('m');
        
        // Get last family number for this GN
        $sql = "
            SELECT family_id 
            FROM families 
            WHERE family_id LIKE CONCAT(?, '%')
            ORDER BY family_id DESC 
            LIMIT 1
        ";
        
        $stmt = $this->conn->prepare($sql);
        $likePattern = $prefix . $year . $month . '%';
        $stmt->bind_param('s', $likePattern);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $lastId = $row['family_id'];
            $lastNumber = intval(substr($lastId, -4));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }
        
        return $prefix . $year . $month . $newNumber;
    }
    
    /**
     * Validate identification data
     */
    private function validateIdentification($citizenData) {
        $idType = $citizenData['identification_type'] ?? '';
        $idNumber = $citizenData['identification_number'] ?? '';
        
        if (empty($idNumber)) {
            return false;
        }
        
        if ($idType === 'nic') {
            // Validate Sri Lankan NIC
            return $this->validateSriLankanNIC($idNumber);
        }
        
        return true;
    }
    
    /**
     * Validate Sri Lankan NIC
     */
    private function validateSriLankanNIC($nic) {
        $nic = strtoupper(trim($nic));
        
        // Old format: 9 digits + V/X
        if (preg_match('/^[0-9]{9}[VX]$/', $nic)) {
            return true;
        }
        
        // New format: 12 digits
        if (preg_match('/^[0-9]{12}$/', $nic)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Update family member count
     */
    private function updateFamilyMemberCount($familyId) {
        $sql = "
            UPDATE families f
            SET f.total_members = (
                SELECT COUNT(*) FROM citizens c 
                WHERE c.family_id = f.family_id AND c.is_alive = 1
            )
            WHERE f.family_id = ?
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('s', $familyId);
        $stmt->execute();
    }
    
    /**
     * Get family statistics for GN
     */
    public function getFamilyStats($gnId) {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_families,
                    SUM(total_members) as total_members,
                    COUNT(CASE WHEN is_transferred = 1 THEN 1 END) as transferred_families,
                    COUNT(CASE WHEN has_pending_transfer = 1 THEN 1 END) as pending_transfers,
                    COUNT(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) THEN 1 END) as new_this_month
                FROM families 
                WHERE gn_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('s', $gnId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc() ?: [
                'total_families' => 0,
                'total_members' => 0,
                'transferred_families' => 0,
                'pending_transfers' => 0,
                'new_this_month' => 0
            ];
            
        } catch (Exception $e) {
            error_log('Error getting family stats: ' . $e->getMessage());
            return [
                'total_families' => 0,
                'total_members' => 0,
                'transferred_families' => 0,
                'pending_transfers' => 0,
                'new_this_month' => 0
            ];
        }
    }
    
    /**
     * Get member statistics for family
     */
    public function getMemberStats($familyId) {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_members,
                    COUNT(CASE WHEN gender = 'male' THEN 1 END) as males,
                    COUNT(CASE WHEN gender = 'female' THEN 1 END) as females,
                    COUNT(CASE WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 18 THEN 1 END) as children,
                    COUNT(CASE WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 60 THEN 1 END) as adults,
                    COUNT(CASE WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) > 60 THEN 1 END) as seniors
                FROM citizens 
                WHERE family_id = ? AND is_alive = 1
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('s', $familyId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc() ?: [
                'total_members' => 0,
                'males' => 0,
                'females' => 0,
                'children' => 0,
                'adults' => 0,
                'seniors' => 0
            ];
            
        } catch (Exception $e) {
            error_log('Error getting member stats: ' . $e->getMessage());
            return [
                'total_members' => 0,
                'males' => 0,
                'females' => 0,
                'children' => 0,
                'adults' => 0,
                'seniors' => 0
            ];
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
     * Get GN divisions under a division
     */
    public function getGNDivisions($divisionCode) {
        try {
            $sql = "
                SELECT u.* 
                FROM users u
                JOIN division_gn_mapping dgm ON u.office_code = dgm.gn_office_code
                WHERE dgm.division_code = ?
                AND u.user_type = 'gn'
                AND u.is_active = 1
                ORDER BY u.office_name
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('s', $divisionCode);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $gnDivisions = [];
            while ($row = $result->fetch_assoc()) {
                $gnDivisions[] = $row;
            }
            
            return $gnDivisions;
            
        } catch (Exception $e) {
            error_log('Error getting GN divisions: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if user can manage family
     */
    public function canManageFamily($userId, $userType, $officeCode, $familyId) {
        try {
            if ($userType === 'moha') {
                return true; // MOHA can manage all
            }
            
            // Get family's GN
            $sql = "SELECT gn_id FROM families WHERE family_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('s', $familyId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $familyGnId = $row['gn_id'];
                
                if ($userType === 'gn') {
                    // GN can only manage families in their GN
                    return $familyGnId === $officeCode;
                } elseif ($userType === 'division') {
                    // Division can manage families in GNs under them
                    $sql = "SELECT 1 FROM division_gn_mapping WHERE division_code = ? AND gn_office_code = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param('ss', $officeCode, $familyGnId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    return $result->num_rows > 0;
                } elseif ($userType === 'district') {
                    // District can manage families in their district
                    // This would require checking the hierarchy
                    return true; // Simplified for now
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log('Error checking family permissions: ' . $e->getMessage());
            return false;
        }
    }
}