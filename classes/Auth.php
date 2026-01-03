<?php
/**
 * Auth.php
 * Authentication and Authorization Class for FPMS
 * Handles user login, logout, session management, and role-based access control
 */

class Auth {
    private $conn;
    private $loginAttempts = [];
    private $lockoutDuration = 900; // 15 minutes in seconds
    
    public function __construct() {
        $this->conn = getMainConnection();
        
        // Initialize login attempts from session
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }
        $this->loginAttempts = &$_SESSION['login_attempts'];
    }
    
    /**
     * Authenticate user with username and password
     * @param string $username
     * @param string $password
     * @param bool $remember Remember me option
     * @return bool
     */
    public function login($username, $password, $remember = false) {
        // Check if user is locked out
        if ($this->isLockedOut($username)) {
            $this->logActivity('login_attempt', 'Account locked out: ' . $username, null);
            return false;
        }
        
        // Validate inputs
        if (empty($username) || empty($password)) {
            $this->incrementLoginAttempt($username, 'empty_credentials');
            return false;
        }
        
        // Sanitize username
        $username = $this->sanitizeUsername($username);
        
        // Get user from database
        $user = $this->getUserByUsername($username);
        
        if (!$user) {
            $this->incrementLoginAttempt($username, 'user_not_found');
            $this->logActivity('login_attempt', 'User not found: ' . $username, null);
            return false;
        }
        
        // Check if account is active
        if (!$user['is_active']) {
            $this->incrementLoginAttempt($username, 'account_inactive');
            $this->logActivity('login_attempt', 'Inactive account attempt: ' . $username, $user['user_id']);
            return false;
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $this->incrementLoginAttempt($username, 'wrong_password');
            $this->logActivity('login_attempt', 'Wrong password for: ' . $username, $user['user_id']);
            return false;
        }
        
        // Check if password needs rehash (for upgrading hash algorithm)
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $this->rehashPassword($user['user_id'], $password);
        }
        
        // Successful login - reset attempts
        $this->resetLoginAttempts($username);
        
        // Update last login
        $this->updateLastLogin($user['user_id']);
        
        // Set session variables
        $this->setUserSession($user);
        
        // Set remember me cookie if requested
        if ($remember) {
            $this->setRememberMeCookie($user['user_id']);
        }
        
        // Log successful login
        $this->logActivity('login', 'User logged in successfully', $user['user_id']);
        
        return true;
    }
    
    /**
     * Set user session after successful login
     * @param array $user User data from database
     */
    private function setUserSession($user) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['office_code'] = $user['office_code'];
        $_SESSION['office_name'] = $user['office_name'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['session_id'] = session_id();
        
        // Set permissions based on user type
        $this->setUserPermissions($user['user_type']);
    }
    
    /**
     * Set user permissions based on role
     * @param string $userType
     */
    private function setUserPermissions($userType) {
        $permissions = [
            'moha' => [
                'manage_users' => true,
                'manage_districts' => true,
                'view_all_data' => true,
                'reset_passwords' => true,
                'system_settings' => true,
                'audit_logs' => true,
                'reports_all' => true
            ],
            'district' => [
                'manage_divisions' => true,
                'view_district_data' => true,
                'reset_passwords' => true,
                'reports_district' => true,
                'approve_transfers' => false
            ],
            'division' => [
                'manage_gn' => true,
                'view_division_data' => true,
                'reset_passwords' => true,
                'reports_division' => true,
                'approve_transfers' => true
            ],
            'gn' => [
                'add_citizens' => true,
                'edit_citizens' => true,
                'view_gn_data' => true,
                'request_transfers' => true,
                'reports_gn' => true
            ]
        ];
        
        $_SESSION['permissions'] = $permissions[$userType] ?? [];
    }
    
    /**
     * Check if user is logged in
     * @return bool
     */
    public function isLoggedIn() {
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            // Check session timeout (8 hours)
            if (time() - $_SESSION['login_time'] > 28800) {
                $this->logout();
                return false;
            }
            
            // Update login time for active session
            $_SESSION['login_time'] = time();
            return true;
        }
        
        // Check remember me cookie
        if ($this->checkRememberMeCookie()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Logout user
     * @param bool $redirect Redirect to login page after logout
     * @param bool $confirm Whether to show confirmation
     * @return void
     */
    public function logout($redirect = true, $confirm = false) {
        // Get user info before clearing session
        $userId = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'unknown';
        $userType = $_SESSION['user_type'] ?? 'unknown';
        
        // Log logout activity
        if ($userId) {
            $this->logActivity('logout', 'User logged out: ' . $username, $userId);
        }
        
        // Clear remember me tokens for this user
        if ($userId) {
            $this->clearRememberTokens($userId);
        }
        
        // Clear all session variables
        session_unset();
        
        // Delete session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), 
                '', 
                time() - 42000,
                $params["path"], 
                $params["domain"],
                $params["secure"], 
                $params["httponly"]
            );
        }
        
        // Destroy the session
        session_destroy();
        
        // Delete remember me cookie
        $this->deleteRememberMeCookie();
        
        // Update last logout time in database
        if ($userId) {
            $stmt = $this->conn->prepare("UPDATE users SET last_logout = NOW() WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $userId);
                $stmt->execute();
            }
        }
        
        // Start new clean session
        session_start();
        
        // Clear any session leftovers
        $_SESSION = array();
        
        if ($redirect) {
            if ($confirm) {
                // Store logout info in new session for confirmation page
                $_SESSION['logout_info'] = [
                    'username' => $username,
                    'user_type' => $userType,
                    'time' => time()
                ];
                header('Location: logout_confirm.php');
                exit();
            } else {
                header('Location: login.php?loggedout=1&user=' . urlencode($username));
                exit();
            }
        }
    }
    
    /**
     * Require user to be logged in
     */
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
            header('Location: login.php');
            exit();
        }
    }
    
    /**
     * Require specific user role
     * @param string|array $roles Required role(s)
     */
    public function requireRole($roles) {
        $this->requireLogin();
        
        if (is_string($roles)) {
            $roles = [$roles];
        }
        
        if (!in_array($_SESSION['user_type'], $roles)) {
            $this->logActivity('unauthorized_access', 
                'Attempted access to role-restricted page: ' . $_SERVER['REQUEST_URI'], 
                $_SESSION['user_id']);
            header('Location: unauthorized.php');
            exit();
        }
    }
    
    /**
     * Check if user has specific permission
     * @param string $permission Permission to check
     * @return bool
     */
    public function hasPermission($permission) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        return isset($_SESSION['permissions'][$permission]) && 
               $_SESSION['permissions'][$permission] === true;
    }
    
    /**
     * Check if user can access resource based on hierarchy
     * @param string $targetType Target user type (moha, district, division, gn)
     * @param string $targetOfficeCode Target office code
     * @return bool
     */
    public function canManage($targetType, $targetOfficeCode = null) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $userType = $_SESSION['user_type'];
        $userOfficeCode = $_SESSION['office_code'];
        
        // Define hierarchy levels
        $hierarchy = ['moha' => 1, 'district' => 2, 'division' => 3, 'gn' => 4];
        
        // Check if user is higher in hierarchy
        if (!isset($hierarchy[$userType]) || !isset($hierarchy[$targetType])) {
            return false;
        }
        
        if ($hierarchy[$userType] >= $hierarchy[$targetType]) {
            return false; // Cannot manage same or lower level
        }
        
        // Check office jurisdiction based on hierarchy
        if ($targetOfficeCode !== null) {
            return $this->isUnderJurisdiction($userType, $userOfficeCode, $targetType, $targetOfficeCode);
        }
        
        return true;
    }
    
    /**
     * Check if target office is under user's jurisdiction
     * @param string $userType
     * @param string $userOfficeCode
     * @param string $targetType
     * @param string $targetOfficeCode
     * @return bool
     */
    private function isUnderJurisdiction($userType, $userOfficeCode, $targetType, $targetOfficeCode) {
        $refConn = getRefConnection();
        
        switch ($userType) {
            case 'moha':
                // MOHA can manage all districts
                if ($targetType === 'district') {
                    $stmt = $refConn->prepare("SELECT 1 FROM fix_work_station WHERE District_Name = ? LIMIT 1");
                    $stmt->bind_param("s", $targetOfficeCode);
                    $stmt->execute();
                    return $stmt->get_result()->num_rows > 0;
                }
                break;
                
            case 'district':
                // District can manage divisions under it
                if ($targetType === 'division') {
                    $stmt = $refConn->prepare("SELECT 1 FROM fix_work_station WHERE Division_Name = ? AND District_Name = ? LIMIT 1");
                    $stmt->bind_param("ss", $targetOfficeCode, $userOfficeCode);
                    $stmt->execute();
                    return $stmt->get_result()->num_rows > 0;
                }
                break;
                
            case 'division':
                // Division can manage GN divisions under it
                if ($targetType === 'gn') {
                    $stmt = $refConn->prepare("SELECT 1 FROM fix_work_station WHERE GN_ID = ? AND Division_Name = ? LIMIT 1");
                    $stmt->bind_param("ss", $targetOfficeCode, $userOfficeCode);
                    $stmt->execute();
                    return $stmt->get_result()->num_rows > 0;
                }
                break;
        }
        
        return false;
    }
    
    /**
     * Get user by username
     * @param string $username
     * @return array|null
     */
    private function getUserByUsername($username) {
        $stmt = $this->conn->prepare("
            SELECT user_id, username, password_hash, user_type, office_code, office_name, 
                   email, phone, is_active, last_login, created_at
            FROM users 
            WHERE username = ? AND is_active = 1
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    /**
     * Get user by ID
     * @param int $userId
     * @return array|null
     */
    public function getUserById($userId) {
        $stmt = $this->conn->prepare("
            SELECT user_id, username, user_type, office_code, office_name, 
                   email, phone, is_active, last_login, created_at
            FROM users 
            WHERE user_id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    /**
     * Update user's last login time
     * @param int $userId
     */
    private function updateLastLogin($userId) {
        $stmt = $this->conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
    }
    
    /**
     * Rehash password with updated algorithm
     * @param int $userId
     * @param string $password
     */
    private function rehashPassword($userId, $password) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $stmt = $this->conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $stmt->bind_param("si", $newHash, $userId);
        $stmt->execute();
    }
    
    /**
     * Change user password
     * @param int $userId
     * @param string $currentPassword
     * @param string $newPassword
     * @return array [success, message]
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        // Get user current hash
        $stmt = $this->conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows !== 1) {
            return [false, "User not found"];
        }
        
        $user = $result->fetch_assoc();
        
        // Verify current password
        if (!password_verify($currentPassword, $user['password_hash'])) {
            $this->logActivity('password_change_attempt', 'Wrong current password', $userId);
            return [false, "Current password is incorrect"];
        }
        
        // Validate new password
        $valid = $this->validatePasswordPolicy($newPassword);
        if (!$valid['success']) {
            return [false, $valid['message']];
        }
        
        // Hash new password
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Update password
        $stmt = $this->conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->bind_param("si", $newHash, $userId);
        
        if ($stmt->execute()) {
            $this->logActivity('password_change', 'Password changed successfully', $userId);
            return [true, "Password changed successfully"];
        }
        
        return [false, "Failed to update password"];
    }
    
    /**
     * Validate password against policy
     * @param string $password
     * @return array
     */
    private function validatePasswordPolicy($password) {
        $minLength = 8;
        
        if (strlen($password) < $minLength) {
            return ['success' => false, 'message' => "Password must be at least {$minLength} characters"];
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            return ['success' => false, 'message' => "Password must contain at least one uppercase letter"];
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            return ['success' => false, 'message' => "Password must contain at least one lowercase letter"];
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            return ['success' => false, 'message' => "Password must contain at least one number"];
        }
        
        if (!preg_match('/[\W_]/', $password)) {
            return ['success' => false, 'message' => "Password must contain at least one special character"];
        }
        
        return ['success' => true, 'message' => 'Password is valid'];
    }
    
    /**
     * Reset user password (admin function)
     * @param int $targetUserId User ID to reset
     * @param int $requesterUserId User ID requesting reset
     * @return array [success, message, new_password]
     */
    public function resetPassword($targetUserId, $requesterUserId) {
        // Check if requester has permission
        $requester = $this->getUserById($requesterUserId);
        $target = $this->getUserById($targetUserId);
        
        if (!$requester || !$target) {
            return [false, "User not found", null];
        }
        
        // Check jurisdiction
        if (!$this->canManage($target['user_type'], $target['office_code'])) {
            $this->logActivity('unauthorized_password_reset', 
                'Attempted to reset password for user ' . $targetUserId, $requesterUserId);
            return [false, "You are not authorized to reset this user's password", null];
        }
        
        // Generate new password
        $newPassword = $this->generateRandomPassword(12);
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Update password
        $stmt = $this->conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->bind_param("si", $newHash, $targetUserId);
        
        if ($stmt->execute()) {
            $this->logActivity('password_reset', 
                'Password reset by ' . $requester['username'] . ' for ' . $target['username'], 
                $requesterUserId);
            
            return [true, "Password reset successfully", $newPassword];
        }
        
        return [false, "Failed to reset password", null];
    }
    
    /**
     * Generate random password
     * @param int $length
     * @return string
     */
    private function generateRandomPassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';
        $charsLength = strlen($chars);
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $charsLength - 1)];
        }
        
        return $password;
    }
    
    /**
     * Create new user account
     * @param array $userData User data
     * @return array [success, message, user_id]
     */
    public function createUser($userData) {
        // Validate required fields
        $required = ['username', 'user_type', 'office_code', 'office_name'];
        foreach ($required as $field) {
            if (empty($userData[$field])) {
                return [false, "Missing required field: $field", null];
            }
        }
        
        // Check if username exists
        if ($this->usernameExists($userData['username'])) {
            return [false, "Username already exists", null];
        }
        
        // Generate password if not provided
        if (empty($userData['password'])) {
            $userData['password'] = $this->generateRandomPassword(12);
        }
        
        // Validate password
        $valid = $this->validatePasswordPolicy($userData['password']);
        if (!$valid['success']) {
            return [false, $valid['message'], null];
        }
        
        // Hash password
        $passwordHash = password_hash($userData['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Prepare SQL
        $sql = "INSERT INTO users (username, password_hash, user_type, office_code, office_name, 
                email, phone, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sssssssi", 
            $userData['username'],
            $passwordHash,
            $userData['user_type'],
            $userData['office_code'],
            $userData['office_name'],
            $userData['email'] ?? null,
            $userData['phone'] ?? null,
            $userData['is_active'] ?? 1
        );
        
        if ($stmt->execute()) {
            $userId = $stmt->insert_id;
            
            $this->logActivity('user_created', 
                'New user created: ' . $userData['username'] . ' (' . $userData['user_type'] . ')', 
                $_SESSION['user_id'] ?? null);
            
            return [true, "User created successfully", $userId];
        }
        
        return [false, "Failed to create user: " . $stmt->error, null];
    }
    
    /**
     * Check if username exists
     * @param string $username
     * @return bool
     */
    private function usernameExists($username) {
        $stmt = $this->conn->prepare("SELECT 1 FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
    
    /**
     * Sanitize username
     * @param string $username
     * @return string
     */
    private function sanitizeUsername($username) {
        return strtolower(trim($username));
    }
    
    /**
     * Login attempt tracking
     */
    private function incrementLoginAttempt($username, $reason) {
        $ip = $this->getUserIP();
        $now = time();
        
        if (!isset($this->loginAttempts[$username])) {
            $this->loginAttempts[$username] = [
                'count' => 0,
                'first_attempt' => $now,
                'last_attempt' => $now,
                'ip' => $ip,
                'reasons' => []
            ];
        }
        
        $this->loginAttempts[$username]['count']++;
        $this->loginAttempts[$username]['last_attempt'] = $now;
        $this->loginAttempts[$username]['reasons'][] = [
            'time' => $now,
            'reason' => $reason,
            'ip' => $ip
        ];
        
        // Keep only last 10 reasons
        if (count($this->loginAttempts[$username]['reasons']) > 10) {
            array_shift($this->loginAttempts[$username]['reasons']);
        }
    }
    
    private function resetLoginAttempts($username) {
        if (isset($this->loginAttempts[$username])) {
            unset($this->loginAttempts[$username]);
        }
    }
    
    private function isLockedOut($username) {
        if (!isset($this->loginAttempts[$username])) {
            return false;
        }
        
        $attempts = $this->loginAttempts[$username];
        
        // Check if max attempts reached (5 attempts)
        if ($attempts['count'] >= 5) {
            $timeSinceFirst = time() - $attempts['first_attempt'];
            
            // Check if lockout period has passed
            if ($timeSinceFirst < $this->lockoutDuration) {
                return true;
            } else {
                // Reset attempts after lockout period
                $this->resetLoginAttempts($username);
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Get user IP address
     * @return string
     */
    private function getUserIP() {
        $ip = '';
        
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return $ip;
    }
    
    /**
     * Remember me functionality
     */
    private function setRememberMeCookie($userId) {
        $token = bin2hex(random_bytes(32));
        $selector = bin2hex(random_bytes(16));
        $expires = time() + 2592000; // 30 days
        
        // Hash token for storage
        $tokenHash = hash('sha256', $token);
        
        // Check if remember_me_tokens table exists
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'remember_me_tokens'");
        
        if ($checkTable && $checkTable->num_rows > 0) {
            // Store in database
            $stmt = $this->conn->prepare("
                INSERT INTO remember_me_tokens (user_id, selector, token_hash, expires)
                VALUES (?, ?, ?, FROM_UNIXTIME(?))
            ");
            $stmt->bind_param("issi", $userId, $selector, $tokenHash, $expires);
            $stmt->execute();
        }
        
        // Set cookie
        $cookieValue = $selector . ':' . $token;
        setcookie('remember_me', $cookieValue, $expires, '/', '', true, true);
    }
    
    private function checkRememberMeCookie() {
        if (!isset($_COOKIE['remember_me'])) {
            return false;
        }
        
        list($selector, $token) = explode(':', $_COOKIE['remember_me']);
        
        if (empty($selector) || empty($token)) {
            $this->deleteRememberMeCookie();
            return false;
        }
        
        // Check if remember_me_tokens table exists
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'remember_me_tokens'");
        if (!$checkTable || $checkTable->num_rows === 0) {
            $this->deleteRememberMeCookie();
            return false;
        }
        
        // Get token from database
        $stmt = $this->conn->prepare("
            SELECT rt.user_id, rt.token_hash, rt.expires, u.username, u.user_type, 
                   u.office_code, u.office_name, u.is_active
            FROM remember_me_tokens rt
            JOIN users u ON rt.user_id = u.user_id
            WHERE rt.selector = ? AND rt.expires > NOW()
        ");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows !== 1) {
            $this->deleteRememberMeCookie();
            return false;
        }
        
        $row = $result->fetch_assoc();
        
        // Verify token
        if (!hash_equals($row['token_hash'], hash('sha256', $token))) {
            // Invalid token - delete it
            $this->deleteRememberToken($selector);
            $this->deleteRememberMeCookie();
            return false;
        }
        
        // Check if user is active
        if (!$row['is_active']) {
            $this->deleteRememberToken($selector);
            $this->deleteRememberMeCookie();
            return false;
        }
        
        // Set user session
        $_SESSION['user_id'] = $row['user_id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['user_type'] = $row['user_type'];
        $_SESSION['office_code'] = $row['office_code'];
        $_SESSION['office_name'] = $row['office_name'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['session_id'] = session_id();
        
        // Set permissions
        $this->setUserPermissions($row['user_type']);
        
        // Update last login
        $this->updateLastLogin($row['user_id']);
        
        // Log activity
        $this->logActivity('login', 'User logged in via remember me cookie', $row['user_id']);
        
        return true;
    }
    
    private function deleteRememberMeCookie() {
        if (isset($_COOKIE['remember_me'])) {
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
        }
    }
    
    private function deleteRememberToken($selector) {
        $stmt = $this->conn->prepare("DELETE FROM remember_me_tokens WHERE selector = ?");
        if ($stmt) {
            $stmt->bind_param("s", $selector);
            $stmt->execute();
        }
    }
    
    /**
     * Clear remember me tokens for user
     * @param int $userId
     * @return bool
     */
    public function clearRememberTokens($userId) {
        try {
            // Check if remember_me_tokens table exists
            $checkTable = $this->conn->query("SHOW TABLES LIKE 'remember_me_tokens'");
            
            if ($checkTable && $checkTable->num_rows > 0) {
                // Clear remember me tokens from database
                $stmt = $this->conn->prepare("DELETE FROM remember_me_tokens WHERE user_id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
            }
            return true;
        } catch (Exception $e) {
            // If table doesn't exist or error, just return true
            error_log("Error clearing remember tokens: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Force logout a specific user (admin function)
     * @param int $targetUserId User ID to force logout
     * @param int $requesterUserId Admin user ID
     * @return array [success, message]
     */
    public function forceLogoutUser($targetUserId, $requesterUserId) {
        try {
            // Get user info
            $stmt = $this->conn->prepare("SELECT username, user_type FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $targetUserId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows !== 1) {
                return [false, "User not found"];
            }
            
            $targetUser = $result->fetch_assoc();
            
            // Get requester info
            $requester = $this->getUserById($requesterUserId);
            
            // Check if requester can force logout (must be higher in hierarchy)
            if (!$this->canManage($targetUser['user_type'], null)) {
                return [false, "You are not authorized to force logout this user"];
            }
            
            // Log the forced logout
            $this->logActivity('admin_force_logout', 
                "Admin {$requester['username']} forced logout for user: {$targetUser['username']} ({$targetUser['user_type']})",
                $requesterUserId
            );
            
            // Clear remember tokens for target user
            $this->clearRememberTokens($targetUserId);
            
            return [true, "User {$targetUser['username']} has been logged out from all devices"];
            
        } catch (Exception $e) {
            error_log("Force logout error: " . $e->getMessage());
            return [false, "Error forcing logout: " . $e->getMessage()];
        }
    }
    
    /**
     * Activity logging
     */
    private function logActivity($action, $details = '', $userId = null) {
        // This should call the global logActivity function
        if (function_exists('logActivity')) {
            logActivity($action, $details, $userId);
        }
    }
    
    /**
     * Get current user information
     * @return array|null
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'user_type' => $_SESSION['user_type'],
            'office_code' => $_SESSION['office_code'],
            'office_name' => $_SESSION['office_name'],
            'permissions' => $_SESSION['permissions'] ?? []
        ];
    }
    
    /**
     * Get user's jurisdiction information
     * @return array
     */
    public function getUserJurisdiction() {
        if (!$this->isLoggedIn()) {
            return [];
        }
        
        $userType = $_SESSION['user_type'];
        $officeCode = $_SESSION['office_code'];
        
        $jurisdiction = [
            'user_type' => $userType,
            'office_code' => $officeCode,
            'office_name' => $_SESSION['office_name'],
            'managed_offices' => []
        ];
        
        // For now, return empty managed offices
        // In a real implementation, you would query the reference database
        
        return $jurisdiction;
    }
    
    /**
     * Validate user session
     * @return bool
     */
    public function validateSession() {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        // Check if session ID matches
        if ($_SESSION['session_id'] !== session_id()) {
            $this->logout(false);
            return false;
        }
        
        return true;
    }
    
    /**
     * Get login attempts for a user
     * @param string $username
     * @return array
     */
    public function getLoginAttempts($username) {
        return $this->loginAttempts[$username] ?? [];
    }
    
    /**
     * Get all locked out users
     * @return array
     */
    public function getLockedOutUsers() {
        $lockedOut = [];
        $now = time();
        
        foreach ($this->loginAttempts as $username => $attempts) {
            if ($attempts['count'] >= 5) {
                $timeSinceFirst = $now - $attempts['first_attempt'];
                if ($timeSinceFirst < $this->lockoutDuration) {
                    $lockedOut[$username] = [
                        'attempts' => $attempts['count'],
                        'locked_until' => $attempts['first_attempt'] + $this->lockoutDuration,
                        'time_left' => $this->lockoutDuration - $timeSinceFirst,
                        'last_ip' => $attempts['ip']
                    ];
                }
            }
        }
        
        return $lockedOut;
    }
    
    /**
     * Unlock a user account
     * @param string $username
     * @return bool
     */
    public function unlockUser($username) {
        if (isset($this->loginAttempts[$username])) {
            unset($this->loginAttempts[$username]);
            $this->logActivity('user_unlocked', 'User account unlocked: ' . $username, $_SESSION['user_id'] ?? null);
            return true;
        }
        return false;
    }
    
    /**
     * Create remember_me_tokens table if not exists
     * This should be called during installation
     */
    public function createRememberMeTable() {
        $sql = "CREATE TABLE IF NOT EXISTS remember_me_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector VARCHAR(32) NOT NULL UNIQUE,
            token_hash VARCHAR(64) NOT NULL,
            expires DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_expires (expires),
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->conn->query($sql);
    }
}