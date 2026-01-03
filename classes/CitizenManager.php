<?php
/**
 * CitizenManager - Handles citizen and family data operations
 */
class CitizenManager {
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
     * Get families by GN division
     */
    public function getFamiliesByGN($gnId, $filters = []) {
        try {
            $search = $filters['search'] ?? '';
            $page = $filters['page'] ?? 1;
            $limit = $filters['limit'] ?? 50;
            $offset = ($page - 1) * $limit;
            
            $whereClauses = ["f.gn_id = ?"];
            $params = [$gnId];
            $types = 's';
            
            if ($search) {
                $searchTerm = "%{$search}%";
                $whereClauses[] = "(f.family_id LIKE ? OR f.address LIKE ? OR f.family_head_nic LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= 'sss';
            }
            
            $sql = "
                SELECT f.*, 
                COUNT(c.citizen_id) as total_members,
                GROUP_CONCAT(c.full_name SEPARATOR ', ') as member_names
                FROM families f
                LEFT JOIN citizens c ON f.family_id = c.family_id
                WHERE " . implode(' AND ', $whereClauses) . "
                GROUP BY f.family_id
                ORDER BY f.family_id
                LIMIT ? OFFSET ?
            ";
            
            $params[] = $limit;
            $params[] = $offset;
            $types .= 'ii';
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                return [];
            }
            
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
     * Get citizens by GN division
     */
    public function getCitizensByGN($gnId, $filters = []) {
        try {
            $search = $filters['search'] ?? '';
            $page = $filters['page'] ?? 1;
            $limit = $filters['limit'] ?? 50;
            $offset = ($page - 1) * $limit;
            
            $whereClauses = ["c.family_id IN (SELECT family_id FROM families WHERE gn_id = ?)"];
            $params = [$gnId];
            $types = 's';
            
            if ($search) {
                $searchTerm = "%{$search}%";
                $whereClauses[] = "(c.full_name LIKE ? OR c.name_with_initials LIKE ? OR c.identification_number LIKE ? OR c.mobile_phone LIKE ?)";
                for ($i = 0; $i < 4; $i++) {
                    $params[] = $searchTerm;
                    $types .= 's';
                }
            }
            
            $sql = "
                SELECT c.*, f.address as family_address, f.gn_id
                FROM citizens c
                JOIN families f ON c.family_id = f.family_id
                WHERE " . implode(' AND ', $whereClauses) . "
                ORDER BY c.full_name
                LIMIT ? OFFSET ?
            ";
            
            $params[] = $limit;
            $params[] = $offset;
            $types .= 'ii';
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                return [];
            }
            
            $result = $stmt->get_result();
            $citizens = [];
            
            while ($row = $result->fetch_assoc()) {
                $citizens[] = $row;
            }
            
            return $citizens;
            
        } catch (Exception $e) {
            error_log('Error getting citizens by GN: ' . $e->getMessage());
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
                COUNT(c.citizen_id) as total_members,
                u.office_name as gn_name
                FROM families f
                LEFT JOIN citizens c ON f.family_id = c.family_id
                LEFT JOIN users u ON f.gn_id = u.office_code
                WHERE f.family_id = ?
                GROUP BY f.family_id
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return null;
            }
            
            $stmt->bind_param('s', $familyId);
            
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                return null;
            }
            
            $result = $stmt->get_result();
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            error_log('Error getting family by ID: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get citizen by ID
     */
    public function getCitizenById($citizenId) {
        try {
            $sql = "
                SELECT c.*, f.address as family_address, f.gn_id, u.office_name as gn_name
                FROM citizens c
                JOIN families f ON c.family_id = f.family_id
                LEFT JOIN users u ON f.gn_id = u.office_code
                WHERE c.citizen_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return null;
            }
            
            $stmt->bind_param('i', $citizenId);
            
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                return null;
            }
            
            $result = $stmt->get_result();
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            error_log('Error getting citizen by ID: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get family members
     */
    public function getFamilyMembers($familyId) {
        try {
            $sql = "
                SELECT c.* 
                FROM citizens c
                WHERE c.family_id = ?
                ORDER BY 
                    CASE 
                        WHEN c.relation_to_head = 'Head' THEN 1
                        WHEN c.relation_to_head = 'Spouse' THEN 2
                        ELSE 3
                    END,
                    c.date_of_birth ASC
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            $stmt->bind_param('s', $familyId);
            
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                return [];
            }
            
            $result = $stmt->get_result();
            $members = [];
            
            while ($row = $result->fetch_assoc()) {
                $members[] = $row;
            }
            
            return $members;
            
        } catch (Exception $e) {
            error_log('Error getting family members: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Search families
     */
    public function searchFamilies($searchTerm, $gnId = null, $limit = 20) {
        try {
            $searchTerm = "%{$searchTerm}%";
            $whereClauses = [];
            $params = [];
            $types = '';
            
            $whereClauses[] = "(f.family_id LIKE ? OR f.address LIKE ? OR f.family_head_nic LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= 'sss';
            
            if ($gnId) {
                $whereClauses[] = "f.gn_id = ?";
                $params[] = $gnId;
                $types .= 's';
            }
            
            $sql = "
                SELECT f.*, 
                COUNT(c.citizen_id) as total_members
                FROM families f
                LEFT JOIN citizens c ON f.family_id = c.family_id
                WHERE " . implode(' AND ', $whereClauses) . "
                GROUP BY f.family_id
                ORDER BY f.family_id
                LIMIT ?
            ";
            
            $params[] = $limit;
            $types .= 'i';
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                return [];
            }
            
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
    
    /**
     * Search citizens
     */
    public function searchCitizens($searchTerm, $gnId = null, $limit = 20) {
        try {
            $searchTerm = "%{$searchTerm}%";
            $whereClauses = [];
            $params = [];
            $types = '';
            
            $whereClauses[] = "(c.full_name LIKE ? OR c.name_with_initials LIKE ? OR c.identification_number LIKE ? OR c.mobile_phone LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= 'ssss';
            
            if ($gnId) {
                $whereClauses[] = "f.gn_id = ?";
                $params[] = $gnId;
                $types .= 's';
            }
            
            $sql = "
                SELECT c.*, f.address as family_address, f.gn_id
                FROM citizens c
                JOIN families f ON c.family_id = f.family_id
                WHERE " . implode(' AND ', $whereClauses) . "
                ORDER BY c.full_name
                LIMIT ?
            ";
            
            $params[] = $limit;
            $types .= 'i';
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                return [];
            }
            
            $result = $stmt->get_result();
            $citizens = [];
            
            while ($row = $result->fetch_assoc()) {
                $citizens[] = $row;
            }
            
            return $citizens;
            
        } catch (Exception $e) {
            error_log('Error searching citizens: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create new family
     */
    public function createFamily($familyData) {
        try {
            $this->conn->begin_transaction();
            
            // Generate family ID
            $familyId = $this->generateFamilyId($familyData['gn_id']);
            
            $sql = "
                INSERT INTO families (
                    family_id, gn_id, original_gn_id, family_head_nic,
                    address, total_members, created_by
                ) VALUES (?, ?, ?, ?, ?, 1, ?)
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $familyId = $familyData['family_id'] ?? $familyId;
            $gnId = $familyData['gn_id'] ?? '';
            $originalGnId = $familyData['original_gn_id'] ?? $gnId;
            $headNic = $familyData['family_head_nic'] ?? null;
            $address = $familyData['address'] ?? '';
            $createdBy = $familyData['created_by'] ?? null;
            
            $stmt->bind_param(
                'sssssi',
                $familyId,
                $gnId,
                $originalGnId,
                $headNic,
                $address,
                $createdBy
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            // Create family head citizen if provided
            if (!empty($familyData['head_citizen'])) {
                $headCitizen = $familyData['head_citizen'];
                $headCitizen['family_id'] = $familyId;
                $headCitizen['relation_to_head'] = 'Head';
                
                $this->createCitizen($headCitizen);
            }
            
            $this->conn->commit();
            
            // Log activity
            $this->logActivity(
                $createdBy,
                'create_family',
                'families',
                $familyId,
                null,
                json_encode(['family_id' => $familyId, 'gn_id' => $gnId])
            );
            
            return ['success' => true, 'family_id' => $familyId];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log('Error creating family: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error creating family: ' . $e->getMessage()];
        }
    }
    
    /**
     * Create new citizen
     */
    public function createCitizen($citizenData) {
        try {
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
            
            $familyId = $citizenData['family_id'] ?? '';
            $idType = $citizenData['identification_type'] ?? 'nic';
            $idNumber = $citizenData['identification_number'] ?? '';
            $fullName = $citizenData['full_name'] ?? '';
            $nameWithInitials = $citizenData['name_with_initials'] ?? null;
            $gender = $citizenData['gender'] ?? 'other';
            $dob = $citizenData['date_of_birth'] ?? null;
            $ethnicity = $citizenData['ethnicity'] ?? null;
            $religion = $citizenData['religion'] ?? null;
            $mobilePhone = $citizenData['mobile_phone'] ?? null;
            $homePhone = $citizenData['home_phone'] ?? null;
            $email = $citizenData['email'] ?? null;
            $address = $citizenData['address'] ?? null;
            $relation = $citizenData['relation_to_head'] ?? null;
            $maritalStatus = $citizenData['marital_status'] ?? null;
            $isAlive = isset($citizenData['is_alive']) ? (int)$citizenData['is_alive'] : 1;
            
            $stmt->bind_param(
                'sssssssssssssssi',
                $familyId,
                $idType,
                $idNumber,
                $fullName,
                $nameWithInitials,
                $gender,
                $dob,
                $ethnicity,
                $religion,
                $mobilePhone,
                $homePhone,
                $email,
                $address,
                $relation,
                $maritalStatus,
                $isAlive
            );
            
            if ($stmt->execute()) {
                $citizenId = $stmt->insert_id;
                
                // Update family member count
                $this->updateFamilyMemberCount($familyId);
                
                // Log activity
                $this->logActivity(
                    $_SESSION['user_id'] ?? 0,
                    'create_citizen',
                    'citizens',
                    $citizenId,
                    null,
                    json_encode(['full_name' => $fullName, 'family_id' => $familyId])
                );
                
                return ['success' => true, 'citizen_id' => $citizenId];
            } else {
                return ['success' => false, 'error' => 'Database error: ' . $stmt->error];
            }
            
        } catch (Exception $e) {
            error_log('Error creating citizen: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error creating citizen: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update family
     */
    public function updateFamily($familyId, $familyData) {
        try {
            $sql = "
                UPDATE families SET 
                    address = ?, family_head_nic = ?, updated_at = CURRENT_TIMESTAMP
                WHERE family_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $address = $familyData['address'] ?? '';
            $headNic = $familyData['family_head_nic'] ?? null;
            
            $stmt->bind_param('sss', $address, $headNic, $familyId);
            
            if ($stmt->execute()) {
                // Log activity
                $this->logActivity(
                    $_SESSION['user_id'] ?? 0,
                    'update_family',
                    'families',
                    $familyId,
                    null,
                    json_encode(['address' => $address])
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
     * Update citizen
     */
    public function updateCitizen($citizenId, $citizenData) {
        try {
            $sql = "
                UPDATE citizens SET 
                    identification_type = ?, identification_number = ?,
                    full_name = ?, name_with_initials = ?, gender = ?,
                    date_of_birth = ?, ethnicity = ?, religion = ?,
                    mobile_phone = ?, home_phone = ?, email = ?,
                    address = ?, relation_to_head = ?, marital_status = ?,
                    is_alive = ?, updated_at = CURRENT_TIMESTAMP
                WHERE citizen_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $idType = $citizenData['identification_type'] ?? 'nic';
            $idNumber = $citizenData['identification_number'] ?? '';
            $fullName = $citizenData['full_name'] ?? '';
            $nameWithInitials = $citizenData['name_with_initials'] ?? null;
            $gender = $citizenData['gender'] ?? 'other';
            $dob = $citizenData['date_of_birth'] ?? null;
            $ethnicity = $citizenData['ethnicity'] ?? null;
            $religion = $citizenData['religion'] ?? null;
            $mobilePhone = $citizenData['mobile_phone'] ?? null;
            $homePhone = $citizenData['home_phone'] ?? null;
            $email = $citizenData['email'] ?? null;
            $address = $citizenData['address'] ?? null;
            $relation = $citizenData['relation_to_head'] ?? null;
            $maritalStatus = $citizenData['marital_status'] ?? null;
            $isAlive = isset($citizenData['is_alive']) ? (int)$citizenData['is_alive'] : 1;
            
            $stmt->bind_param(
                'ssssssssssssssis',
                $idType,
                $idNumber,
                $fullName,
                $nameWithInitials,
                $gender,
                $dob,
                $ethnicity,
                $religion,
                $mobilePhone,
                $homePhone,
                $email,
                $address,
                $relation,
                $maritalStatus,
                $isAlive,
                $citizenId
            );
            
            if ($stmt->execute()) {
                // Log activity
                $this->logActivity(
                    $_SESSION['user_id'] ?? 0,
                    'update_citizen',
                    'citizens',
                    $citizenId,
                    null,
                    json_encode(['full_name' => $fullName])
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
     * Delete citizen
     */
    public function deleteCitizen($citizenId) {
        try {
            // Get citizen details before deletion
            $citizen = $this->getCitizenById($citizenId);
            if (!$citizen) {
                return ['success' => false, 'error' => 'Citizen not found'];
            }
            
            $sql = "DELETE FROM citizens WHERE citizen_id = ?";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
            }
            
            $stmt->bind_param('i', $citizenId);
            
            if ($stmt->execute()) {
                // Update family member count
                $this->updateFamilyMemberCount($citizen['family_id']);
                
                // Log activity
                $this->logActivity(
                    $_SESSION['user_id'] ?? 0,
                    'delete_citizen',
                    'citizens',
                    $citizenId,
                    json_encode(['full_name' => $citizen['full_name'], 'family_id' => $citizen['family_id']]),
                    null
                );
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Database error: ' . $stmt->error];
            }
            
        } catch (Exception $e) {
            error_log('Error deleting citizen: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error deleting citizen: ' . $e->getMessage()];
        }
    }
    
    /**
     * Transfer family to another GN
     */
    public function transferFamily($familyId, $toGnId, $reason, $requestedBy) {
        try {
            $this->conn->begin_transaction();
            
            // Get current family details
            $family = $this->getFamilyById($familyId);
            if (!$family) {
                throw new Exception("Family not found");
            }
            
            // Create transfer request
            $transferId = 'TR' . date('YmdHis') . rand(100, 999);
            
            $sql = "
                INSERT INTO transfer_history (
                    transfer_id, family_id, from_gn_id, to_gn_id,
                    transfer_reason, requested_by_user_id, request_date,
                    current_status
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pending')
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param(
                'sssssi',
                $transferId,
                $familyId,
                $family['gn_id'],
                $toGnId,
                $reason,
                $requestedBy
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            // Update family to show pending transfer
            $updateSql = "
                UPDATE families SET 
                    has_pending_transfer = 1,
                    last_transfer_request = ?
                WHERE family_id = ?
            ";
            
            $updateStmt = $this->conn->prepare($updateSql);
            if (!$updateStmt) {
                throw new Exception("Prepare failed for family update");
            }
            
            $updateStmt->bind_param('ss', $transferId, $familyId);
            $updateStmt->execute();
            
            $this->conn->commit();
            
            // Log activity
            $this->logActivity(
                $requestedBy,
                'request_transfer',
                'families',
                $familyId,
                $family['gn_id'],
                $toGnId
            );
            
            return ['success' => true, 'transfer_id' => $transferId];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log('Error transferring family: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Error transferring family: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get transfer history for family
     */
    public function getFamilyTransferHistory($familyId) {
        try {
            $sql = "
                SELECT th.*,
                u1.username as requested_by_name,
                u2.username as approved_by_name,
                u3.username as rejected_by_name,
                u4.username as completed_by_name
                FROM transfer_history th
                LEFT JOIN users u1 ON th.requested_by_user_id = u1.user_id
                LEFT JOIN users u2 ON th.approved_by_user_id = u2.user_id
                LEFT JOIN users u3 ON th.rejected_by_user_id = u3.user_id
                LEFT JOIN users u4 ON th.completed_by_user_id = u4.user_id
                WHERE th.family_id = ?
                ORDER BY th.request_date DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Prepare failed: " . $this->conn->error);
                return [];
            }
            
            $stmt->bind_param('s', $familyId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $history = [];
            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
            
            return $history;
            
        } catch (Exception $e) {
            error_log('Error getting transfer history: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get citizen statistics
     */
    public function getCitizenStats($gnId = null) {
        try {
            $whereClause = "";
            $params = [];
            $types = "";
            
            if ($gnId) {
                $whereClause = "WHERE f.gn_id = ?";
                $params[] = $gnId;
                $types = "s";
            }
            
            $sql = "
                SELECT 
                    COUNT(DISTINCT f.family_id) as total_families,
                    COUNT(c.citizen_id) as total_citizens,
                    SUM(CASE WHEN c.gender = 'male' THEN 1 ELSE 0 END) as male_count,
                    SUM(CASE WHEN c.gender = 'female' THEN 1 ELSE 0 END) as female_count,
                    SUM(CASE WHEN c.gender = 'other' THEN 1 ELSE 0 END) as other_gender_count,
                    AVG(f.total_members) as avg_family_size,
                    SUM(CASE WHEN YEAR(CURDATE()) - YEAR(c.date_of_birth) < 18 THEN 1 ELSE 0 END) as children_count,
                    SUM(CASE WHEN YEAR(CURDATE()) - YEAR(c.date_of_birth) BETWEEN 18 AND 60 THEN 1 ELSE 0 END) as adult_count,
                    SUM(CASE WHEN YEAR(CURDATE()) - YEAR(c.date_of_birth) > 60 THEN 1 ELSE 0 END) as senior_count
                FROM families f
                LEFT JOIN citizens c ON f.family_id = c.family_id
                $whereClause
            ";
            
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
            
            return $result->fetch_assoc() ?? [];
            
        } catch (Exception $e) {
            error_log('Error getting citizen stats: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent citizens
     */
    public function getRecentCitizens($limit = 10, $gnId = null) {
        try {
            $whereClause = "";
            $params = [];
            $types = "";
            
            if ($gnId) {
                $whereClause = "WHERE f.gn_id = ?";
                $params[] = $gnId;
                $types = "s";
            }
            
            $sql = "
                SELECT c.*, f.address as family_address
                FROM citizens c
                JOIN families f ON c.family_id = f.family_id
                $whereClause
                ORDER BY c.created_at DESC
                LIMIT ?
            ";
            
            $params[] = $limit;
            $types .= "i";
            
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
            
            $citizens = [];
            while ($row = $result->fetch_assoc()) {
                $citizens[] = $row;
            }
            
            return $citizens;
            
        } catch (Exception $e) {
            error_log('Error getting recent citizens: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent families
     */
    public function getRecentFamilies($limit = 10, $gnId = null) {
        try {
            $whereClause = "";
            $params = [];
            $types = "";
            
            if ($gnId) {
                $whereClause = "WHERE gn_id = ?";
                $params[] = $gnId;
                $types = "s";
            }
            
            $sql = "
                SELECT f.*, 
                COUNT(c.citizen_id) as total_members
                FROM families f
                LEFT JOIN citizens c ON f.family_id = c.family_id
                $whereClause
                GROUP BY f.family_id
                ORDER BY f.created_at DESC
                LIMIT ?
            ";
            
            $params[] = $limit;
            $types .= "i";
            
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
            error_log('Error getting recent families: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate unique family ID
     */
    private function generateFamilyId($gnId) {
        try {
            // Format: GNCODE-YEAR-MONTH-SEQ (e.g., GN123-2023-12-001)
            $yearMonth = date('Y-m');
            $prefix = $gnId . '-' . $yearMonth . '-';
            
            $sql = "SELECT COUNT(*) as count FROM families WHERE family_id LIKE CONCAT(?, '%')";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $gnId . '-' . date('YmdHis') . rand(100, 999);
            }
            
            $stmt->bind_param('s', $prefix);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $sequence = str_pad($row['count'] + 1, 3, '0', STR_PAD_LEFT);
            return $prefix . $sequence;
            
        } catch (Exception $e) {
            error_log('Error generating family ID: ' . $e->getMessage());
            return $gnId . '-' . date('YmdHis') . rand(100, 999);
        }
    }
    
    /**
     * Update family member count
     */
    private function updateFamilyMemberCount($familyId) {
        try {
            $sql = "
                UPDATE families f
                SET total_members = (
                    SELECT COUNT(*) 
                    FROM citizens c 
                    WHERE c.family_id = f.family_id
                    AND c.is_alive = 1
                ),
                updated_at = CURRENT_TIMESTAMP
                WHERE f.family_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $familyId);
                $stmt->execute();
            }
            
        } catch (Exception $e) {
            error_log('Error updating family member count: ' . $e->getMessage());
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
}
?>