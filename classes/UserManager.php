<?php
/**
 * UserManager.php
 * COMPLETE UPDATED VERSION - Shows ALL Districts (Active/Inactive)
 * User Management and Statistics Class
 */

class UserManager {
    private $conn;
    private $refConn;
    
    public function __construct() {
        $this->conn = getMainConnection();
        $this->refConn = getRefConnection();
    }
    
    // ============================================================================
    // DASHBOARD STATISTICS METHODS - ALL DISTRICTS
    // ============================================================================
    
    /**
     * Get MOHA level statistics (INCLUDES INACTIVE)
     */
    public function getMohaStats() {
        $stats = [
            'total_districts' => 0,
            'active_districts' => 0,
            'inactive_districts' => 0,
            'total_divisions' => 0,
            'total_gn_divisions' => 0,
            'total_families' => 0,
            'total_population' => 0,
            'total_users' => 0,
            'active_users' => 0,
            'inactive_users' => 0,
            'pending_transfers' => 0,
            'pending_reviews' => 0,
            'recent_activity_count' => 0
        ];
        
        try {
            // Get ALL districts count from users table
            $districtSql = "SELECT 
                           COUNT(*) as total_districts,
                           SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_districts,
                           SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_districts
                           FROM users 
                           WHERE user_type = 'district'";
            
            $districtResult = $this->conn->query($districtSql);
            if ($districtResult) {
                $row = $districtResult->fetch_assoc();
                $stats['total_districts'] = intval($row['total_districts'] ?? 0);
                $stats['active_districts'] = intval($row['active_districts'] ?? 0);
                $stats['inactive_districts'] = intval($row['inactive_districts'] ?? 0);
            }
            
            // Get counts from reference database
            $refQueries = [
                'total_divisions' => "SELECT COUNT(DISTINCT Division_Name) as count FROM fix_work_station",
                'total_gn_divisions' => "SELECT COUNT(DISTINCT GN_ID) as count FROM fix_work_station"
            ];
            
            foreach ($refQueries as $key => $sql) {
                $result = $this->refConn->query($sql);
                $stats[$key] = $result ? intval($result->fetch_assoc()['count']) : 0;
            }
            
            // Get counts from main database
            $mainQueries = [
                'total_families' => "SELECT COUNT(*) as count FROM families",
                'total_population' => "SELECT COUNT(*) as count FROM citizens",
                'total_users' => "SELECT COUNT(*) as count FROM users WHERE user_type != 'moha'",
                'active_users' => "SELECT COUNT(*) as count FROM users WHERE is_active = 1 AND user_type != 'moha'",
                'inactive_users' => "SELECT COUNT(*) as count FROM users WHERE is_active = 0 AND user_type != 'moha'",
                'pending_transfers' => "SELECT COUNT(*) as count FROM transfer_requests WHERE status = 'pending'",
                'pending_reviews' => "SELECT COUNT(*) as count FROM form_submissions_family WHERE submission_status = 'pending_review'"
            ];
            
            foreach ($mainQueries as $key => $sql) {
                $result = $this->conn->query($sql);
                $stats[$key] = $result ? intval($result->fetch_assoc()['count']) : 0;
            }
            
            // Get recent activity count (last 7 days)
            $activitySql = "SELECT COUNT(*) as count 
                           FROM audit_logs 
                           WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $activityResult = $this->conn->query($activitySql);
            if ($activityResult) {
                $row = $activityResult->fetch_assoc();
                $stats['recent_activity_count'] = intval($row['count'] ?? 0);
            }
            
        } catch (Exception $e) {
            error_log("Error getting MOHA stats: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Get district statistics (works for both active and inactive districts)
     */
    public function getDistrictStats($officeName) {
        $stats = [
            'total_divisions' => 0,
            'total_gn_divisions' => 0,
            'total_families' => 0,
            'total_population' => 0,
            'pending_transfers' => 0,
            'pending_reviews' => 0,
            'active_users' => 0,
            'inactive_users' => 0
        ];
        
        try {
            // Get GN IDs for this district from reference database
            $gnSql = "SELECT DISTINCT GN_ID, Division_Name 
                     FROM fix_work_station 
                     WHERE District_Name = ?";
            
            $gnStmt = $this->refConn->prepare($gnSql);
            if (!$gnStmt) {
                error_log("Prepare failed for GN query: " . $this->refConn->error);
                return $stats;
            }
            
            $gnStmt->bind_param("s", $officeName);
            $gnStmt->execute();
            $gnResult = $gnStmt->get_result();
            
            $gnIds = [];
            $divisionNames = [];
            
            while ($row = $gnResult->fetch_assoc()) {
                $gnIds[] = $row['GN_ID'];
                if (!in_array($row['Division_Name'], $divisionNames)) {
                    $divisionNames[] = $row['Division_Name'];
                }
            }
            $gnStmt->close();
            
            // Set division count
            $stats['total_divisions'] = count($divisionNames);
            $stats['total_gn_divisions'] = count($gnIds);
            
            // Get statistics if there are GN divisions
            if (!empty($gnIds)) {
                $placeholders = str_repeat('?,', count($gnIds) - 1) . '?';
                
                // Family count
                $familySql = "SELECT COUNT(*) as count FROM families WHERE gn_id IN ($placeholders)";
                $familyStmt = $this->conn->prepare($familySql);
                $familyStmt->bind_param(str_repeat('s', count($gnIds)), ...$gnIds);
                $familyStmt->execute();
                $familyResult = $familyStmt->get_result();
                if ($familyResult) {
                    $stats['total_families'] = intval($familyResult->fetch_assoc()['count'] ?? 0);
                }
                $familyStmt->close();
                
                // Population count
                if ($stats['total_families'] > 0) {
                    $popSql = "SELECT COUNT(*) as count 
                              FROM citizens c 
                              JOIN families f ON c.family_id = f.family_id 
                              WHERE f.gn_id IN ($placeholders)";
                    $popStmt = $this->conn->prepare($popSql);
                    $popStmt->bind_param(str_repeat('s', count($gnIds)), ...$gnIds);
                    $popStmt->execute();
                    $popResult = $popStmt->get_result();
                    if ($popResult) {
                        $stats['total_population'] = intval($popResult->fetch_assoc()['count'] ?? 0);
                    }
                    $popStmt->close();
                }
                
                // Pending transfers
                $transferSql = "SELECT COUNT(*) as count 
                               FROM transfer_requests 
                               WHERE status = 'pending' 
                               AND from_gn_id IN ($placeholders)";
                $transferStmt = $this->conn->prepare($transferSql);
                $transferStmt->bind_param(str_repeat('s', count($gnIds)), ...$gnIds);
                $transferStmt->execute();
                $transferResult = $transferStmt->get_result();
                if ($transferResult) {
                    $stats['pending_transfers'] = intval($transferResult->fetch_assoc()['count'] ?? 0);
                }
                $transferStmt->close();
                
                // Pending reviews
                $reviewSql = "SELECT COUNT(*) as count 
                             FROM form_submissions_family 
                             WHERE submission_status = 'pending_review' 
                             AND gn_id IN ($placeholders)";
                $reviewStmt = $this->conn->prepare($reviewSql);
                $reviewStmt->bind_param(str_repeat('s', count($gnIds)), ...$gnIds);
                $reviewStmt->execute();
                $reviewResult = $reviewStmt->get_result();
                if ($reviewResult) {
                    $stats['pending_reviews'] = intval($reviewResult->fetch_assoc()['count'] ?? 0);
                }
                $reviewStmt->close();
            }
            
            // Active and inactive users in this district
            $userSql = "SELECT 
                       COUNT(*) as total_count,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
                       SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count
                       FROM users 
                       WHERE user_type IN ('district', 'division', 'gn') 
                       AND office_name = ?";
            $userStmt = $this->conn->prepare($userSql);
            $userStmt->bind_param("s", $officeName);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            if ($userResult && $row = $userResult->fetch_assoc()) {
                $stats['active_users'] = intval($row['active_count'] ?? 0);
                $stats['inactive_users'] = intval($row['inactive_count'] ?? 0);
            }
            $userStmt->close();
            
        } catch (Exception $e) {
            error_log("Error getting district stats for '{$officeName}': " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Get ALL districts with complete statistics (ACTIVE AND INACTIVE)
     */
    public function getAllDistrictsWithStats() {
        $districts = [];
        
        try {
            // Get ALL district users (both active and inactive)
            $sql = "SELECT u.* FROM users u 
                    WHERE u.user_type = 'district' 
                    ORDER BY u.is_active DESC, u.office_name";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($district = $result->fetch_assoc()) {
                // Get complete statistics for each district (even if inactive)
                $district['stats'] = $this->getDistrictStats($district['office_name']);
                
                // Get user status information
                $district['status_info'] = $this->getUserStatusInfo($district['user_id']);
                
                // Get division names
                $district['divisions'] = $this->getDivisionsByDistrictName($district['office_name']);
                
                // Format dates
                $district['created_at_formatted'] = date('d M Y', strtotime($district['created_at']));
                $district['updated_at_formatted'] = date('d M Y', strtotime($district['updated_at']));
                
                if ($district['last_login']) {
                    $district['last_login_formatted'] = date('d M Y h:i A', strtotime($district['last_login']));
                    $district['days_since_login'] = floor((time() - strtotime($district['last_login'])) / (60 * 60 * 24));
                } else {
                    $district['last_login_formatted'] = 'Never';
                    $district['days_since_login'] = null;
                }
                
                // Get account age
                $district['account_age_days'] = floor((time() - strtotime($district['created_at'])) / (60 * 60 * 24));
                
                // Get recent activities (last 5)
                $district['recent_activities'] = $this->getUserRecentActivities($district['user_id'], 5);
                
                $districts[] = $district;
            }
            
        } catch (Exception $e) {
            error_log("Error getting districts with stats: " . $e->getMessage());
        }
        
        return $districts;
    }
    
    /**
     * Get division statistics
     */
    public function getDivisionStats($officeName) {
        $stats = [
            'total_gn_divisions' => 0,
            'total_families' => 0,
            'total_population' => 0,
            'pending_transfers' => 0,
            'pending_reviews' => 0,
            'active_users' => 0,
            'inactive_users' => 0
        ];
        
        try {
            // Get GN IDs for this division
            $gnSql = "SELECT DISTINCT GN_ID FROM fix_work_station WHERE Division_Name = ?";
            $gnStmt = $this->refConn->prepare($gnSql);
            $gnStmt->bind_param("s", $officeName);
            $gnStmt->execute();
            $gnResult = $gnStmt->get_result();
            
            $gnIds = [];
            while ($row = $gnResult->fetch_assoc()) {
                $gnIds[] = $row['GN_ID'];
            }
            $gnStmt->close();
            
            // Set GN division count
            $stats['total_gn_divisions'] = count($gnIds);
            
            // Get other statistics if there are GN divisions
            if (!empty($gnIds)) {
                $placeholders = str_repeat('?,', count($gnIds) - 1) . '?';
                
                // Family count
                $familySql = "SELECT COUNT(*) as count FROM families WHERE gn_id IN ($placeholders)";
                $familyStmt = $this->conn->prepare($familySql);
                $familyStmt->bind_param(str_repeat('s', count($gnIds)), ...$gnIds);
                $familyStmt->execute();
                $familyResult = $familyStmt->get_result();
                if ($familyResult) {
                    $stats['total_families'] = intval($familyResult->fetch_assoc()['count'] ?? 0);
                }
                $familyStmt->close();
                
                // Population count
                if ($stats['total_families'] > 0) {
                    $popSql = "SELECT COUNT(*) as count 
                              FROM citizens c 
                              JOIN families f ON c.family_id = f.family_id 
                              WHERE f.gn_id IN ($placeholders)";
                    $popStmt = $this->conn->prepare($popSql);
                    $popStmt->bind_param(str_repeat('s', count($gnIds)), ...$gnIds);
                    $popStmt->execute();
                    $popResult = $popStmt->get_result();
                    if ($popResult) {
                        $stats['total_population'] = intval($popResult->fetch_assoc()['count'] ?? 0);
                    }
                    $popStmt->close();
                }
                
                // Pending transfers
                $transferSql = "SELECT COUNT(*) as count 
                               FROM transfer_requests 
                               WHERE status = 'pending' 
                               AND from_gn_id IN ($placeholders)";
                $transferStmt = $this->conn->prepare($transferSql);
                $transferStmt->bind_param(str_repeat('s', count($gnIds)), ...$gnIds);
                $transferStmt->execute();
                $transferResult = $transferStmt->get_result();
                if ($transferResult) {
                    $stats['pending_transfers'] = intval($transferResult->fetch_assoc()['count'] ?? 0);
                }
                $transferStmt->close();
                
                // Pending reviews
                $reviewSql = "SELECT COUNT(*) as count 
                             FROM form_submissions_family 
                             WHERE submission_status = 'pending_review' 
                             AND gn_id IN ($placeholders)";
                $reviewStmt = $this->conn->prepare($reviewSql);
                $reviewStmt->bind_param(str_repeat('s', count($gnIds)), ...$gnIds);
                $reviewStmt->execute();
                $reviewResult = $reviewStmt->get_result();
                if ($reviewResult) {
                    $stats['pending_reviews'] = intval($reviewResult->fetch_assoc()['count'] ?? 0);
                }
                $reviewStmt->close();
            }
            
            // Active and inactive users in this division
            $userSql = "SELECT 
                       COUNT(*) as total_count,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
                       SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count
                       FROM users 
                       WHERE user_type IN ('division', 'gn') 
                       AND office_name = ?";
            $userStmt = $this->conn->prepare($userSql);
            $userStmt->bind_param("s", $officeName);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            if ($userResult && $row = $userResult->fetch_assoc()) {
                $stats['active_users'] = intval($row['active_count'] ?? 0);
                $stats['inactive_users'] = intval($row['inactive_count'] ?? 0);
            }
            $userStmt->close();
            
        } catch (Exception $e) {
            error_log("Error getting division stats for '{$officeName}': " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Get GN division statistics
     */
    public function getGnStats($officeCode) {
        $stats = [
            'total_families' => 0,
            'total_population' => 0,
            'families_this_month' => 0,
            'pending_transfers' => 0,
            'pending_reviews' => 0,
            'active_users' => 0,
            'inactive_users' => 0
        ];
        
        try {
            // Family count
            $familySql = "SELECT COUNT(*) as count FROM families WHERE gn_id = ?";
            $familyStmt = $this->conn->prepare($familySql);
            $familyStmt->bind_param("s", $officeCode);
            $familyStmt->execute();
            $familyResult = $familyStmt->get_result();
            if ($familyResult) {
                $stats['total_families'] = intval($familyResult->fetch_assoc()['count'] ?? 0);
            }
            $familyStmt->close();
            
            // Population count
            if ($stats['total_families'] > 0) {
                $popSql = "SELECT COUNT(*) as count 
                          FROM citizens c 
                          JOIN families f ON c.family_id = f.family_id 
                          WHERE f.gn_id = ?";
                $popStmt = $this->conn->prepare($popSql);
                $popStmt->bind_param("s", $officeCode);
                $popStmt->execute();
                $popResult = $popStmt->get_result();
                if ($popResult) {
                    $stats['total_population'] = intval($popResult->fetch_assoc()['count'] ?? 0);
                }
                $popStmt->close();
            }
            
            // Families added this month
            $monthSql = "SELECT COUNT(*) as count 
                        FROM families 
                        WHERE gn_id = ? 
                        AND MONTH(created_at) = MONTH(CURDATE()) 
                        AND YEAR(created_at) = YEAR(CURDATE())";
            $monthStmt = $this->conn->prepare($monthSql);
            $monthStmt->bind_param("s", $officeCode);
            $monthStmt->execute();
            $monthResult = $monthStmt->get_result();
            if ($monthResult) {
                $stats['families_this_month'] = intval($monthResult->fetch_assoc()['count'] ?? 0);
            }
            $monthStmt->close();
            
            // Pending transfers
            $transferSql = "SELECT COUNT(*) as count 
                           FROM transfer_requests 
                           WHERE status = 'pending' 
                           AND from_gn_id = ?";
            $transferStmt = $this->conn->prepare($transferSql);
            $transferStmt->bind_param("s", $officeCode);
            $transferStmt->execute();
            $transferResult = $transferStmt->get_result();
            if ($transferResult) {
                $stats['pending_transfers'] = intval($transferResult->fetch_assoc()['count'] ?? 0);
            }
            $transferStmt->close();
            
            // Pending reviews
            $reviewSql = "SELECT COUNT(*) as count 
                         FROM form_submissions_family 
                         WHERE submission_status = 'pending_review' 
                         AND gn_id = ?";
            $reviewStmt = $this->conn->prepare($reviewSql);
            $reviewStmt->bind_param("s", $officeCode);
            $reviewStmt->execute();
            $reviewResult = $reviewStmt->get_result();
            if ($reviewResult) {
                $stats['pending_reviews'] = intval($reviewResult->fetch_assoc()['count'] ?? 0);
            }
            $reviewStmt->close();
            
            // Active and inactive users in this GN division
            $userSql = "SELECT 
                       COUNT(*) as total_count,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
                       SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count
                       FROM users 
                       WHERE user_type = 'gn' 
                       AND office_code = ?";
            $userStmt = $this->conn->prepare($userSql);
            $userStmt->bind_param("s", $officeCode);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            if ($userResult && $row = $userResult->fetch_assoc()) {
                $stats['active_users'] = intval($row['active_count'] ?? 0);
                $stats['inactive_users'] = intval($row['inactive_count'] ?? 0);
            }
            $userStmt->close();
            
        } catch (Exception $e) {
            error_log("Error getting GN stats for '{$officeCode}': " . $e->getMessage());
        }
        
        return $stats;
    }
    
    // ============================================================================
    // HELPER METHODS
    // ============================================================================
    
    /**
     * Get user status information
     */
    private function getUserStatusInfo($userId) {
        $info = [
            'login_count' => 0,
            'last_activity' => null,
            'last_activity_formatted' => 'Never',
            'total_activities' => 0,
            'created_days_ago' => 0,
            'recent_login' => false
        ];
        
        try {
            // Get login count and last activity from audit logs
            $loginSql = "SELECT 
                        COUNT(*) as total_activities,
                        MAX(created_at) as last_activity,
                        SUM(CASE WHEN action_type IN ('login') THEN 1 ELSE 0 END) as login_count
                        FROM audit_logs 
                        WHERE user_id = ?";
            
            $loginStmt = $this->conn->prepare($loginSql);
            $loginStmt->bind_param('i', $userId);
            $loginStmt->execute();
            $loginResult = $loginStmt->get_result();
            
            if ($loginResult && $row = $loginResult->fetch_assoc()) {
                $info['total_activities'] = intval($row['total_activities'] ?? 0);
                $info['login_count'] = intval($row['login_count'] ?? 0);
                $info['last_activity'] = $row['last_activity'];
                
                if ($row['last_activity']) {
                    $info['last_activity_formatted'] = date('d M Y H:i', strtotime($row['last_activity']));
                    
                    // Check if last activity was recent (within 7 days)
                    $lastActivityTime = strtotime($row['last_activity']);
                    if ($lastActivityTime > time() - (7 * 24 * 60 * 60)) {
                        $info['recent_login'] = true;
                    }
                }
            }
            
            // Get account age
            $ageSql = "SELECT DATEDIFF(NOW(), created_at) as days_ago 
                      FROM users 
                      WHERE user_id = ?";
            
            $ageStmt = $this->conn->prepare($ageSql);
            $ageStmt->bind_param('i', $userId);
            $ageStmt->execute();
            $ageResult = $ageStmt->get_result();
            
            if ($ageResult && $row = $ageResult->fetch_assoc()) {
                $info['created_days_ago'] = intval($row['days_ago'] ?? 0);
            }
            
        } catch (Exception $e) {
            error_log("Error getting user status info: " . $e->getMessage());
        }
        
        return $info;
    }
    
    /**
     * Get user's recent activities
     */
    private function getUserRecentActivities($userId, $limit = 5) {
        $activities = [];
        
        try {
            $sql = "SELECT action_type, created_at, ip_address 
                    FROM audit_logs 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('ii', $userId, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $activities[] = [
                    'action' => $row['action_type'],
                    'time' => date('d M H:i', strtotime($row['created_at'])),
                    'ip' => $row['ip_address']
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error getting user activities: " . $e->getMessage());
        }
        
        return $activities;
    }
    
    /**
     * Get divisions by district name
     */
    private function getDivisionsByDistrictName($districtName) {
        $divisions = [];
        
        try {
            $sql = "SELECT DISTINCT Division_Name as name 
                    FROM fix_work_station 
                    WHERE District_Name = ? 
                    ORDER BY Division_Name";
            
            $stmt = $this->refConn->prepare($sql);
            $stmt->bind_param('s', $districtName);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $divisions[] = $row['name'];
            }
            
        } catch (Exception $e) {
            error_log("Error getting divisions by district: " . $e->getMessage());
        }
        
        return $divisions;
    }
    
    // ============================================================================
    // USER MANAGEMENT METHODS
    // ============================================================================
    
    /**
     * Get all users with filters (INCLUDING INACTIVE)
     */
    public function getAllUsers($filters = []) {
        $users = [];
        
        try {
            $whereClauses = ["u.user_type != 'moha'"]; // Don't show MOHA users in general list
            $params = [];
            $types = '';
            
            // Apply filters if specified, otherwise show all
            if (isset($filters['is_active'])) {
                $whereClauses[] = "u.is_active = ?";
                $params[] = $filters['is_active'];
                $types .= 'i';
            }
            
            if (isset($filters['user_type'])) {
                $whereClauses[] = "u.user_type = ?";
                $params[] = $filters['user_type'];
                $types .= 's';
            }
            
            if (isset($filters['office_code'])) {
                $whereClauses[] = "u.office_code = ?";
                $params[] = $filters['office_code'];
                $types .= 's';
            }
            
            if (isset($filters['district_name'])) {
                $whereClauses[] = "u.office_name = ?";
                $params[] = $filters['district_name'];
                $types .= 's';
            }
            
            $sql = "SELECT u.* FROM users u 
                    WHERE " . implode(' AND ', $whereClauses) . "
                    ORDER BY u.is_active DESC, u.user_type, u.office_name, u.username";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                // Add additional info
                $row['status_info'] = $this->getUserStatusInfo($row['user_id']);
                $users[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting all users: " . $e->getMessage());
        }
        
        return $users;
    }
    
    /**
     * Get user by ID
     */
    public function getUserById($userId) {
        try {
            $sql = "SELECT * FROM users WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $user = $result->fetch_assoc();
            if ($user) {
                $user['status_info'] = $this->getUserStatusInfo($userId);
            }
            
            return $user;
            
        } catch (Exception $e) {
            error_log("Error getting user by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get user by username
     */
    public function getUserByUsername($username) {
        try {
            $sql = "SELECT * FROM users WHERE username = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $user = $result->fetch_assoc();
            if ($user) {
                $user['status_info'] = $this->getUserStatusInfo($user['user_id']);
            }
            
            return $user;
            
        } catch (Exception $e) {
            error_log("Error getting user by username: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update user last login
     */
    public function updateLastLogin($userId) {
        try {
            $sql = "UPDATE users SET last_login = NOW() WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('i', $userId);
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Error updating last login: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Search users
     */
    public function searchUsers($searchTerm, $filters = []) {
        $users = [];
        
        try {
            $whereClauses = ["u.user_type != 'moha'"];
            $params = [];
            $types = '';
            
            // Add search term
            if ($searchTerm) {
                $whereClauses[] = "(u.username LIKE ? OR u.office_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.office_code LIKE ?)";
                $searchPattern = "%" . $searchTerm . "%";
                $params[] = $searchPattern;
                $params[] = $searchPattern;
                $params[] = $searchPattern;
                $params[] = $searchPattern;
                $params[] = $searchPattern;
                $types .= 'sssss';
            }
            
            // Apply filters
            if (isset($filters['user_type'])) {
                $whereClauses[] = "u.user_type = ?";
                $params[] = $filters['user_type'];
                $types .= 's';
            }
            
            if (isset($filters['office_code'])) {
                $whereClauses[] = "u.office_code = ?";
                $params[] = $filters['office_code'];
                $types .= 's';
            }
            
            if (isset($filters['is_active'])) {
                $whereClauses[] = "u.is_active = ?";
                $params[] = $filters['is_active'];
                $types .= 'i';
            }
            
            $sql = "SELECT u.* FROM users u 
                    WHERE " . implode(' AND ', $whereClauses) . "
                    ORDER BY u.is_active DESC, u.user_type, u.office_name, u.username
                    LIMIT 100";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $row['status_info'] = $this->getUserStatusInfo($row['user_id']);
                $users[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error searching users: " . $e->getMessage());
        }
        
        return $users;
    }
    
    /**
     * Get manageable users for current user
     */
    public function getManageableUsers($currentUserId) {
        $users = [];
        
        try {
            // Get current user
            $currentUser = $this->getUserById($currentUserId);
            
            if (!$currentUser) {
                return [];
            }
            
            switch ($currentUser['user_type']) {
                case 'moha':
                    // MOHA can manage all users except other MOHA
                    $sql = "SELECT u.* FROM users u 
                            WHERE u.user_type != 'moha' 
                            ORDER BY u.is_active DESC, u.user_type, u.office_name, u.username";
                    $stmt = $this->conn->prepare($sql);
                    break;
                    
                case 'district':
                    // District can manage divisions and GNs in their district
                    $sql = "SELECT u.* FROM users u 
                            WHERE u.user_type IN ('division', 'gn') 
                            AND u.office_name IN (
                                SELECT Division_Name FROM fix_work_station 
                                WHERE District_Name = ?
                            )
                            ORDER BY u.is_active DESC, u.user_type, u.office_name, u.username";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param('s', $currentUser['office_name']);
                    break;
                    
                case 'division':
                    // Division can manage GNs in their division
                    $sql = "SELECT u.* FROM users u 
                            WHERE u.user_type = 'gn' 
                            AND u.office_name IN (
                                SELECT GN_ID FROM fix_work_station 
                                WHERE Division_Name = ?
                            )
                            ORDER BY u.is_active DESC, u.office_name, u.username";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param('s', $currentUser['office_name']);
                    break;
                    
                default:
                    return [];
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $row['status_info'] = $this->getUserStatusInfo($row['user_id']);
                $users[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting manageable users: " . $e->getMessage());
        }
        
        return $users;
    }
    
    /**
     * Update user status
     */
    public function updateUserStatus($userId, $status) {
        try {
            $sql = "UPDATE users SET is_active = ?, updated_at = NOW() WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('ii', $status, $userId);
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Error updating user status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update user information
     */
    public function updateUser($userId, $data) {
        try {
            $fields = [];
            $params = [];
            $types = '';
            
            // Build dynamic update query
            if (isset($data['office_name'])) {
                $fields[] = "office_name = ?";
                $params[] = $data['office_name'];
                $types .= 's';
            }
            
            if (isset($data['email'])) {
                $fields[] = "email = ?";
                $params[] = $data['email'];
                $types .= 's';
            }
            
            if (isset($data['phone'])) {
                $fields[] = "phone = ?";
                $params[] = $data['phone'];
                $types .= 's';
            }
            
            if (isset($data['parent_division_code'])) {
                $fields[] = "parent_division_code = ?";
                $params[] = $data['parent_division_code'];
                $types .= 's';
            }
            
            $fields[] = "updated_at = NOW()";
            
            // Add user_id to params
            $params[] = $userId;
            $types .= 'i';
            
            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Error updating user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete user (soft delete by setting inactive)
     */
    public function deleteUser($userId) {
        return $this->updateUserStatus($userId, 0);
    }
    
    /**
     * Check if username exists
     */
    public function usernameExists($username, $excludeUserId = null) {
        try {
            $sql = "SELECT COUNT(*) as count FROM users WHERE username = ?";
            $params = [$username];
            $types = 's';
            
            if ($excludeUserId) {
                $sql .= " AND user_id != ?";
                $params[] = $excludeUserId;
                $types .= 'i';
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return $row['count'] > 0;
            
        } catch (Exception $e) {
            error_log("Error checking username: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if office code exists
     */
    public function officeCodeExists($officeCode, $excludeUserId = null) {
        try {
            $sql = "SELECT COUNT(*) as count FROM users WHERE office_code = ?";
            $params = [$officeCode];
            $types = 's';
            
            if ($excludeUserId) {
                $sql .= " AND user_id != ?";
                $params[] = $excludeUserId;
                $types .= 'i';
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return $row['count'] > 0;
            
        } catch (Exception $e) {
            error_log("Error checking office code: " . $e->getMessage());
            return false;
        }
    }
    
    // ============================================================================
    // ACTIVITY AND LOGGING METHODS
    // ============================================================================
    
    /**
     * Get recent activities
     */
    public function getRecentActivities($limit = 10, $districtCode = null, $divisionCode = null, $gnCode = null) {
        $activities = [];
        
        try {
            $sql = "SELECT al.*, u.username, u.office_name, u.user_type, u.is_active 
                    FROM audit_logs al
                    LEFT JOIN users u ON al.user_id = u.user_id
                    WHERE 1=1";
            
            $params = [];
            $types = "";
            
            // Apply filters
            if ($districtCode) {
                $sql .= " AND (u.office_name = ? OR EXISTS (
                          SELECT 1 FROM fix_work_station fws 
                          WHERE fws.District_Name = ? 
                          AND (fws.Division_Name = u.office_name OR fws.GN_ID = u.office_name)
                    ))";
                $params[] = $districtCode;
                $params[] = $districtCode;
                $types .= "ss";
            }
            
            if ($divisionCode) {
                $sql .= " AND (u.office_name = ? OR EXISTS (
                          SELECT 1 FROM fix_work_station fws 
                          WHERE fws.Division_Name = ? 
                          AND fws.GN_ID = u.office_name
                    ))";
                $params[] = $divisionCode;
                $params[] = $divisionCode;
                $types .= "ss";
            }
            
            if ($gnCode) {
                $sql .= " AND u.office_code = ?";
                $params[] = $gnCode;
                $types .= "s";
            }
            
            $sql .= " ORDER BY al.created_at DESC LIMIT ?";
            $params[] = $limit;
            $types .= "i";
            
            $stmt = $this->conn->prepare($sql);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $activities[] = $row;
            }
            
        } catch (Exception $e) {
            error_log("Error getting recent activities: " . $e->getMessage());
        }
        
        return $activities;
    }
    
    /**
     * Get user statistics (INCLUDES INACTIVE)
     */
    public function getUserStatistics($userId = null) {
        $stats = [
            'total_users' => 0,
            'active_users' => 0,
            'inactive_users' => 0,
            'total_districts' => 0,
            'active_districts' => 0,
            'inactive_districts' => 0,
            'by_type' => [],
            'login_stats' => [],
            'activity_stats' => []
        ];
        
        try {
            // Get overall user counts
            $sql = "SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_users
                    FROM users 
                    WHERE user_type != 'moha'"; // Exclude MOHA from general stats
            
            $result = $this->conn->query($sql);
            if ($result) {
                $row = $result->fetch_assoc();
                $stats['total_users'] = intval($row['total_users'] ?? 0);
                $stats['active_users'] = intval($row['active_users'] ?? 0);
                $stats['inactive_users'] = intval($row['inactive_users'] ?? 0);
            }
            
            // Get district-specific counts
            $districtSql = "SELECT 
                           COUNT(*) as total_districts,
                           SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_districts,
                           SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_districts
                           FROM users 
                           WHERE user_type = 'district'";
            
            $districtResult = $this->conn->query($districtSql);
            if ($districtResult) {
                $row = $districtResult->fetch_assoc();
                $stats['total_districts'] = intval($row['total_districts'] ?? 0);
                $stats['active_districts'] = intval($row['active_districts'] ?? 0);
                $stats['inactive_districts'] = intval($row['inactive_districts'] ?? 0);
            }
            
            // Get counts by user type (including inactive)
            $typeSql = "SELECT 
                       user_type, 
                       COUNT(*) as total_count,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
                       SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count
                       FROM users 
                       WHERE user_type != 'moha'
                       GROUP BY user_type 
                       ORDER BY user_type";
            
            $typeResult = $this->conn->query($typeSql);
            while ($row = $typeResult->fetch_assoc()) {
                $stats['by_type'][$row['user_type']] = [
                    'total' => intval($row['total_count'] ?? 0),
                    'active' => intval($row['active_count'] ?? 0),
                    'inactive' => intval($row['inactive_count'] ?? 0)
                ];
            }
            
            // Get login stats
            $loginSql = "SELECT 
                        COUNT(*) as count 
                        FROM users 
                        WHERE DATE(last_login) = CURDATE()";
            $loginResult = $this->conn->query($loginSql);
            if ($loginResult) {
                $row = $loginResult->fetch_assoc();
                $stats['login_stats']['today'] = intval($row['count'] ?? 0);
            }
            
            // Get login stats for last 7 days
            $weekSql = "SELECT COUNT(*) as count 
                       FROM users 
                       WHERE last_login >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            $weekResult = $this->conn->query($weekSql);
            if ($weekResult) {
                $row = $weekResult->fetch_assoc();
                $stats['login_stats']['last_7_days'] = intval($row['count'] ?? 0);
            }
            
            // Get activity stats
            $activitySql = "SELECT 
                           DATE(created_at) as date,
                           COUNT(*) as count
                           FROM audit_logs 
                           WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                           GROUP BY DATE(created_at)
                           ORDER BY date DESC
                           LIMIT 7";
            
            $activityResult = $this->conn->query($activitySql);
            while ($row = $activityResult->fetch_assoc()) {
                $stats['activity_stats'][$row['date']] = intval($row['count'] ?? 0);
            }
            
        } catch (Exception $e) {
            error_log("Error getting user statistics: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    // ============================================================================
    // FORM MANAGEMENT METHODS
    // ============================================================================
    
    /**
     * Get form submission statistics
     */
    public function getFormSubmissionStats() {
        $stats = [
            'total_submissions' => 0,
            'family_submissions' => 0,
            'member_submissions' => 0,
            'pending_reviews' => 0,
            'approved_submissions' => 0,
            'rejected_submissions' => 0,
            'draft_submissions' => 0
        ];
        
        try {
            // Get counts from both family and member submissions
            $familySql = "SELECT 
                         COUNT(*) as total,
                         SUM(CASE WHEN submission_status = 'pending_review' THEN 1 ELSE 0 END) as pending,
                         SUM(CASE WHEN submission_status = 'approved' THEN 1 ELSE 0 END) as approved,
                         SUM(CASE WHEN submission_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                         SUM(CASE WHEN submission_status = 'draft' THEN 1 ELSE 0 END) as draft
                         FROM form_submissions_family";
            
            $memberSql = "SELECT 
                         COUNT(*) as total,
                         SUM(CASE WHEN submission_status = 'pending_review' THEN 1 ELSE 0 END) as pending,
                         SUM(CASE WHEN submission_status = 'approved' THEN 1 ELSE 0 END) as approved,
                         SUM(CASE WHEN submission_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                         SUM(CASE WHEN submission_status = 'draft' THEN 1 ELSE 0 END) as draft
                         FROM form_submissions_member";
            
            $familyResult = $this->conn->query($familySql);
            $memberResult = $this->conn->query($memberSql);
            
            if ($familyResult) {
                $row = $familyResult->fetch_assoc();
                $stats['family_submissions'] = intval($row['total'] ?? 0);
                $stats['pending_reviews'] += intval($row['pending'] ?? 0);
                $stats['approved_submissions'] += intval($row['approved'] ?? 0);
                $stats['rejected_submissions'] += intval($row['rejected'] ?? 0);
                $stats['draft_submissions'] += intval($row['draft'] ?? 0);
            }
            
            if ($memberResult) {
                $row = $memberResult->fetch_assoc();
                $stats['member_submissions'] = intval($row['total'] ?? 0);
                $stats['pending_reviews'] += intval($row['pending'] ?? 0);
                $stats['approved_submissions'] += intval($row['approved'] ?? 0);
                $stats['rejected_submissions'] += intval($row['rejected'] ?? 0);
                $stats['draft_submissions'] += intval($row['draft'] ?? 0);
            }
            
            $stats['total_submissions'] = $stats['family_submissions'] + $stats['member_submissions'];
            
        } catch (Exception $e) {
            error_log("Error getting form submission stats: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Get user's form completion rate
     */
    public function getUserFormCompletionRate($userId) {
        try {
            // Get total active forms
            $formsSql = "SELECT COUNT(*) as total 
                        FROM forms 
                        WHERE is_active = 1 
                        AND (start_date IS NULL OR start_date <= NOW()) 
                        AND (end_date IS NULL OR end_date >= NOW())";
            
            $formsResult = $this->conn->query($formsSql);
            $totalForms = $formsResult ? intval($formsResult->fetch_assoc()['total'] ?? 0) : 0;
            
            if ($totalForms === 0) {
                return 0;
            }
            
            // Get submitted forms count for this user
            $submittedSql = "SELECT 
                            (SELECT COUNT(*) FROM form_submissions_family WHERE submitted_by_user_id = ? AND submission_status IN ('submitted', 'approved')) +
                            (SELECT COUNT(*) FROM form_submissions_member WHERE submitted_by_user_id = ? AND submission_status IN ('submitted', 'approved')) as submitted_count";
            
            $submittedStmt = $this->conn->prepare($submittedSql);
            $submittedStmt->bind_param('ii', $userId, $userId);
            $submittedStmt->execute();
            $submittedResult = $submittedStmt->get_result();
            
            if ($submittedResult) {
                $row = $submittedResult->fetch_assoc();
                $submittedCount = intval($row['submitted_count'] ?? 0);
                return min(100, round(($submittedCount / $totalForms) * 100));
            }
            
            return 0;
            
        } catch (Exception $e) {
            error_log("Error getting form completion rate: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get active users count (users who logged in within last 30 minutes)
     */
    public function getActiveUsersCount() {
        try {
            $sql = "SELECT COUNT(*) as count FROM users 
                    WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) 
                    AND is_active = 1";
            
            $result = $this->conn->query($sql);
            return $result ? intval($result->fetch_assoc()['count']) : 0;
            
        } catch (Exception $e) {
            error_log("Error getting active users count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get database connection
     */
  // In UserManager.php class, add this method:
public function getConnection() {
    return $this->conn;
}
    
    /**
     * Get reference database connection
     */
    public function getRefConnection() {
        return $this->refConn;
    }
    
    /**
     * Validate user data before saving
     */
    public function validateUserData($data) {
        $errors = [];
        
        // Check required fields
        $required = ['username', 'user_type', 'office_code', 'office_name'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
            }
        }
        
        // Validate username format
        if (isset($data['username']) && !preg_match('/^[a-z0-9_]{4,20}$/', $data['username'])) {
            $errors[] = "Username must be 4-20 lowercase letters, numbers, or underscores";
        }
        
        // Validate email if provided
        if (isset($data['email']) && !empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email address";
        }
        
        // Validate phone if provided
        if (isset($data['phone']) && !empty($data['phone'])) {
            if (!preg_match('/^(\+94|0)[1-9][0-9]{8}$/', $data['phone'])) {
                $errors[] = "Invalid Sri Lankan phone number format";
            }
        }
        
        // Validate user type
        $validTypes = ['moha', 'district', 'division', 'gn'];
        if (isset($data['user_type']) && !in_array($data['user_type'], $validTypes)) {
            $errors[] = "Invalid user type";
        }
        
        return $errors;
    }
    
    /**
     * Generate user report data
     */
    public function generateUserReport($filters = []) {
        $report = [
            'headers' => ['ID', 'Username', 'User Type', 'Office Code', 'Office Name', 'Email', 'Phone', 'Status', 'Last Login', 'Created At'],
            'data' => []
        ];
        
        try {
            $users = $this->getAllUsers($filters);
            
            foreach ($users as $user) {
                $report['data'][] = [
                    $user['user_id'],
                    $user['username'],
                    strtoupper($user['user_type']),
                    $user['office_code'],
                    $user['office_name'],
                    $user['email'] ?? 'N/A',
                    $user['phone'] ?? 'N/A',
                    $user['is_active'] ? 'Active' : 'Inactive',
                    $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never',
                    date('Y-m-d', strtotime($user['created_at']))
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error generating user report: " . $e->getMessage());
        }
        
        return $report;
    }
    
    /**
     * Close database connections
     */
    public function closeConnections() {
        if ($this->conn) {
            $this->conn->close();
        }
        if ($this->refConn) {
            $this->refConn->close();
        }
    }

// Add this method to your UserManager.php class, preferably in the "USER MANAGEMENT METHODS" section:

/**
 * Get all divisional secretariats under a specific district
 * This is used in district_details.php
 */
public function getDivisionalSecretariatsUnderDistrict($districtName) {
    $divisions = [];
    
    try {
        // Get divisional secretariats from users table that belong to this district
        $sql = "SELECT 
                u.user_id,
                u.username,
                u.user_type,
                u.office_code,
                u.office_name,
                u.email,
                u.phone,
                u.is_active,
                u.last_login,
                u.created_at,
                fws.District_Name,
                (SELECT COUNT(DISTINCT fws2.GN_ID) 
                 FROM fix_work_station fws2 
                 WHERE fws2.Division_Name = fws.Division_Name) as gn_count,
                (SELECT COUNT(*) 
                 FROM families f 
                 WHERE f.gn_id IN (
                     SELECT fws3.GN_ID 
                     FROM fix_work_station fws3 
                     WHERE fws3.Division_Name = fws.Division_Name
                 )) as family_count
                FROM users u
                INNER JOIN fix_work_station fws 
                ON u.office_name = fws.Division_Name
                WHERE u.user_type = 'division'
                AND fws.District_Name = ?
                AND u.is_active = 1
                GROUP BY u.user_id, u.office_name
                ORDER BY u.office_name";
        
        $stmt = $this->refConn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed for divisional secretariats: " . $this->refConn->error);
            return $divisions;
        }
        
        $stmt->bind_param('s', $districtName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Get additional statistics for each division
            $divisionStats = $this->getDivisionStats($row['office_name']);
            
            $divisions[] = [
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'user_type' => $row['user_type'],
                'office_code' => $row['office_code'],
                'office_name' => $row['office_name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'is_active' => $row['is_active'],
                'last_login' => $row['last_login'],
                'created_at' => $row['created_at'],
                'district_name' => $row['District_Name'],
                'gn_count' => $row['gn_count'] ?? 0,
                'family_count' => $row['family_count'] ?? 0,
                'stats' => $divisionStats,
                'status_info' => $this->getUserStatusInfo($row['user_id'])
            ];
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Error getting divisional secretariats for district '{$districtName}': " . $e->getMessage());
    }
    
    return $divisions;
}

/**
 * Alternative simpler version if the above query is too complex
 */
public function getDivisionalSecretariatsUnderDistrictSimple($districtName) {
    $divisions = [];
    
    try {
        // First get division names from reference database
        $sql = "SELECT DISTINCT Division_Name 
                FROM fix_work_station 
                WHERE District_Name = ? 
                ORDER BY Division_Name";
        
        $stmt = $this->refConn->prepare($sql);
        $stmt->bind_param('s', $districtName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $divisionNames = [];
        while ($row = $result->fetch_assoc()) {
            $divisionNames[] = $row['Division_Name'];
        }
        $stmt->close();
        
        // Now get user information for these divisions
        if (!empty($divisionNames)) {
            $placeholders = str_repeat('?,', count($divisionNames) - 1) . '?';
            
            $userSql = "SELECT 
                       u.user_id,
                       u.username,
                       u.user_type,
                       u.office_code,
                       u.office_name,
                       u.email,
                       u.phone,
                       u.is_active,
                       u.last_login,
                       u.created_at
                       FROM users u 
                       WHERE u.office_name IN ($placeholders) 
                       AND u.user_type = 'division'
                       ORDER BY u.office_name";
            
            $userStmt = $this->conn->prepare($userSql);
            $userStmt->bind_param(str_repeat('s', count($divisionNames)), ...$divisionNames);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            
            while ($row = $userResult->fetch_assoc()) {
                // Get statistics for this division
                $divisionStats = $this->getDivisionStats($row['office_name']);
                
                // Get GN count for this division
                $gnSql = "SELECT COUNT(DISTINCT GN_ID) as gn_count 
                         FROM fix_work_station 
                         WHERE Division_Name = ?";
                
                $gnStmt = $this->refConn->prepare($gnSql);
                $gnStmt->bind_param('s', $row['office_name']);
                $gnStmt->execute();
                $gnResult = $gnStmt->get_result();
                $gnCount = $gnResult ? $gnResult->fetch_assoc()['gn_count'] : 0;
                $gnStmt->close();
                
                $divisions[] = [
                    'user_id' => $row['user_id'],
                    'username' => $row['username'],
                    'user_type' => $row['user_type'],
                    'office_code' => $row['office_code'],
                    'office_name' => $row['office_name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'is_active' => $row['is_active'],
                    'last_login' => $row['last_login'],
                    'created_at' => $row['created_at'],
                    'district_name' => $districtName,
                    'gn_count' => $gnCount,
                    'family_count' => $divisionStats['total_families'],
                    'stats' => $divisionStats,
                    'status_info' => $this->getUserStatusInfo($row['user_id'])
                ];
            }
            
            $userStmt->close();
        }
        
    } catch (Exception $e) {
        error_log("Error getting divisional secretariats (simple) for district '{$districtName}': " . $e->getMessage());
    }
    
    return $divisions;
}

/**
 * Get users by district name
 * Used in district_details.php for "All Users in District" section
 */
public function getUsersByDistrict($districtName) {
    $users = [];
    
    try {
        // First get all division names in this district
        $divSql = "SELECT DISTINCT Division_Name 
                  FROM fix_work_station 
                  WHERE District_Name = ?";
        
        $divStmt = $this->refConn->prepare($divSql);
        $divStmt->bind_param('s', $districtName);
        $divStmt->execute();
        $divResult = $divStmt->get_result();
        
        $divisionNames = [];
        while ($row = $divResult->fetch_assoc()) {
            $divisionNames[] = $row['Division_Name'];
        }
        $divStmt->close();
        
        // Get GN IDs for this district
        $gnSql = "SELECT DISTINCT GN_ID 
                 FROM fix_work_station 
                 WHERE District_Name = ?";
        
        $gnStmt = $this->refConn->prepare($gnSql);
        $gnStmt->bind_param('s', $districtName);
        $gnStmt->execute();
        $gnResult = $gnStmt->get_result();
        
        $gnIds = [];
        while ($row = $gnResult->fetch_assoc()) {
            $gnIds[] = $row['GN_ID'];
        }
        $gnStmt->close();
        
        // Build search conditions
        $conditions = ["office_name = ?"]; // District user itself
        $params = [$districtName];
        $types = 's';
        
        // Add division names
        if (!empty($divisionNames)) {
            $conditions[] = "office_name IN (" . str_repeat('?,', count($divisionNames) - 1) . "?)";
            $params = array_merge($params, $divisionNames);
            $types .= str_repeat('s', count($divisionNames));
        }
        
        // Add GN IDs
        if (!empty($gnIds)) {
            $conditions[] = "office_code IN (" . str_repeat('?,', count($gnIds) - 1) . "?)";
            $params = array_merge($params, $gnIds);
            $types .= str_repeat('s', count($gnIds));
        }
        
        $sql = "SELECT u.* FROM users u 
                WHERE (" . implode(' OR ', $conditions) . ")
                AND user_type != 'moha'
                ORDER BY 
                    CASE user_type 
                        WHEN 'district' THEN 1
                        WHEN 'division' THEN 2
                        WHEN 'gn' THEN 3
                        ELSE 4
                    END,
                    office_name";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed for users by district: " . $this->conn->error);
            return $users;
        }
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Add status information
            $row['status_info'] = $this->getUserStatusInfo($row['user_id']);
            $users[] = $row;
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Error getting users by district '{$districtName}': " . $e->getMessage());
    }
    
    return $users;
}

// ============================================================================
// OFFICE MANAGEMENT METHODS (for assignments.php)
// ============================================================================

/**
 * Get all offices (districts, divisions, GN) - For assignments.php
 * @param array $filters Optional filters
 * @return array List of offices
 */
public function getAllOffices($filters = []) {
    $offices = [];
    
    try {
        $whereClauses = ["1=1"];
        $params = [];
        $types = "";
        
        // Base query for offices from users table
        $sql = "SELECT DISTINCT 
                u.office_code, 
                u.office_name, 
                u.user_type,
                (CASE 
                    WHEN u.user_type = 'district' THEN 'District'
                    WHEN u.user_type = 'division' THEN 'Division'
                    WHEN u.user_type = 'gn' THEN 'GN Division'
                    ELSE 'MOHA'
                END) as type_label
                FROM users u 
                WHERE u.is_active = 1 
                AND u.user_type IN ('district', 'division', 'gn')";
        
        // Apply filters
        if (!empty($filters['user_type'])) {
            $whereClauses[] = "u.user_type = ?";
            $params[] = $filters['user_type'];
            $types .= "s";
        }
        
        if (!empty($filters['office_code'])) {
            $whereClauses[] = "u.office_code = ?";
            $params[] = $filters['office_code'];
            $types .= "s";
        }
        
        if (!empty($filters['exclude_types'])) {
            $excludeTypes = (array)$filters['exclude_types'];
            $placeholders = str_repeat('?,', count($excludeTypes) - 1) . '?';
            $whereClauses[] = "u.user_type NOT IN ($placeholders)";
            $params = array_merge($params, $excludeTypes);
            $types .= str_repeat('s', count($excludeTypes));
        }
        
        if (count($whereClauses) > 0) {
            $sql .= " AND " . implode(" AND ", $whereClauses);
        }
        
        $sql .= " ORDER BY 
            CASE u.user_type 
                WHEN 'district' THEN 1
                WHEN 'division' THEN 2
                WHEN 'gn' THEN 3
                ELSE 4
            END, 
            u.office_name";
        
        $stmt = $this->conn->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $offices[] = [
                'office_code' => $row['office_code'],
                'office_name' => $row['office_name'],
                'user_type' => $row['user_type'],
                'type_label' => $row['type_label']
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error in getAllOffices: " . $e->getMessage());
    }
    
    return $offices;
}

/**
 * Get offices by district (divisions in that district) - For assignments.php
 * @param string $districtCode District office code
 * @return array List of offices
 */
public function getOfficesByDistrict($districtCode) {
    $offices = [];
    
    try {
        // Get district name from office code
        $districtName = $this->getOfficeNameByCode($districtCode);
        if (!$districtName) {
            return $offices;
        }
        
        // Get divisions in this district from reference database
        $sql = "SELECT DISTINCT Division_Name as name
                FROM fix_work_station 
                WHERE District_Name = ? 
                ORDER BY Division_Name";
        
        $stmt = $this->refConn->prepare($sql);
        $stmt->bind_param("s", $districtName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $divisionNames = [];
        while ($row = $result->fetch_assoc()) {
            $divisionNames[] = $row['name'];
        }
        $stmt->close();
        
        // Get user information for these divisions
        if (!empty($divisionNames)) {
            $placeholders = str_repeat('?,', count($divisionNames) - 1) . '?';
            
            $userSql = "SELECT 
                       u.office_code, 
                       u.office_name, 
                       u.user_type,
                       'Division' as type_label
                       FROM users u 
                       WHERE u.office_name IN ($placeholders) 
                       AND u.user_type = 'division'
                       AND u.is_active = 1
                       ORDER BY u.office_name";
            
            $userStmt = $this->conn->prepare($userSql);
            $userStmt->bind_param(str_repeat('s', count($divisionNames)), ...$divisionNames);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            
            while ($row = $userResult->fetch_assoc()) {
                $offices[] = $row;
            }
            $userStmt->close();
        }
        
    } catch (Exception $e) {
        error_log("Error in getOfficesByDistrict: " . $e->getMessage());
    }
    
    return $offices;
}

/**
 * Get offices by division (GNs in that division) - For assignments.php
 * @param string $divisionCode Division office code
 * @return array List of offices
 */
public function getOfficesByDivision($divisionCode) {
    $offices = [];
    
    try {
        // Get division name from office code
        $divisionName = $this->getOfficeNameByCode($divisionCode);
        if (!$divisionName) {
            return $offices;
        }
        
        // Get GNs in this division from reference database
        $sql = "SELECT DISTINCT GN_ID as code
                FROM fix_work_station 
                WHERE Division_Name = ? 
                ORDER BY GN_ID";
        
        $stmt = $this->refConn->prepare($sql);
        $stmt->bind_param("s", $divisionName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $gnCodes = [];
        while ($row = $result->fetch_assoc()) {
            $gnCodes[] = $row['code'];
        }
        $stmt->close();
        
        // Get user information for these GNs
        if (!empty($gnCodes)) {
            $placeholders = str_repeat('?,', count($gnCodes) - 1) . '?';
            
            $userSql = "SELECT 
                       u.office_code, 
                       u.office_name, 
                       u.user_type,
                       'GN Division' as type_label
                       FROM users u 
                       WHERE u.office_code IN ($placeholders) 
                       AND u.user_type = 'gn'
                       AND u.is_active = 1
                       ORDER BY u.office_name";
            
            $userStmt = $this->conn->prepare($userSql);
            $userStmt->bind_param(str_repeat('s', count($gnCodes)), ...$gnCodes);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            
            while ($row = $userResult->fetch_assoc()) {
                $offices[] = $row;
            }
            $userStmt->close();
        }
        
    } catch (Exception $e) {
        error_log("Error in getOfficesByDivision: " . $e->getMessage());
    }
    
    return $offices;
}

/**
 * Helper method: Get office name by code
 */
private function getOfficeNameByCode($officeCode) {
    try {
        $sql = "SELECT office_name FROM users WHERE office_code = ? LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $officeCode);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row['office_name'];
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log("Error getting office name by code: " . $e->getMessage());
        return null;
    }
}

/**
 * Get users by district for assignments - Alternative to getUsersByDistrict
 * @param string $districtCode District office code
 * @param array $filters Optional filters
 * @return array List of users
 */
public function getUsersByDistrictForAssignment($districtCode, $filters = []) {
    $users = [];
    
    try {
        // Get district name from office code
        $districtName = $this->getOfficeNameByCode($districtCode);
        if (!$districtName) {
            return $users;
        }
        
        // Use existing method
        $users = $this->getUsersByDistrict($districtName);
        
        // Apply additional filters
        if (!empty($filters['is_active'])) {
            $users = array_filter($users, function($user) use ($filters) {
                return $user['is_active'] == $filters['is_active'];
            });
        }
        
        // Reset array keys
        $users = array_values($users);
        
    } catch (Exception $e) {
        error_log("Error in getUsersByDistrictForAssignment: " . $e->getMessage());
    }
    
    return $users;
}

/**
 * Get users by division for assignments
 * @param string $divisionCode Division office code
 * @param array $filters Optional filters
 * @return array List of users
 */
public function getUsersByDivision($divisionCode, $filters = []) {
    $users = [];
    
    try {
        // Get division name from office code
        $divisionName = $this->getOfficeNameByCode($divisionCode);
        if (!$divisionName) {
            return $users;
        }
        
        // Get GN IDs for this division
        $gnSql = "SELECT DISTINCT GN_ID 
                 FROM fix_work_station 
                 WHERE Division_Name = ?";
        
        $gnStmt = $this->refConn->prepare($gnSql);
        $gnStmt->bind_param("s", $divisionName);
        $gnStmt->execute();
        $gnResult = $gnStmt->get_result();
        
        $gnCodes = [];
        while ($row = $gnResult->fetch_assoc()) {
            $gnCodes[] = $row['GN_ID'];
        }
        $gnStmt->close();
        
        // Get users for these GN divisions
        if (!empty($gnCodes)) {
            $placeholders = str_repeat('?,', count($gnCodes) - 1) . '?';
            
            $whereClauses = ["u.office_code IN ($placeholders)", "u.user_type = 'gn'"];
            $params = $gnCodes;
            $types = str_repeat('s', count($gnCodes));
            
            if (!empty($filters['is_active'])) {
                $whereClauses[] = "u.is_active = ?";
                $params[] = $filters['is_active'];
                $types .= "i";
            }
            
            $sql = "SELECT u.* 
                    FROM users u 
                    WHERE " . implode(" AND ", $whereClauses) . "
                    ORDER BY u.office_name";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $stmt->close();
        }
        
    } catch (Exception $e) {
        error_log("Error in getUsersByDivision: " . $e->getMessage());
    }
    
    return $users;
}


}


