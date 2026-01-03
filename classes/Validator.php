<?php
/**
 * Input Validator Class for FPMS
 */
class Validator {
    
    /**
     * Sanitize input string
     * @param mixed $input
     * @return mixed
     */
    public function sanitize($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitize'], $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate required field
     * @param string $input
     * @return bool
     */
    public function validateRequired($input) {
        return !empty(trim($input));
    }
    
    /**
     * Validate email
     * @param string $email
     * @return bool
     */
    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate phone number (Sri Lankan format)
     * @param string $phone
     * @return bool
     */
    public function validatePhone($phone) {
        // Sri Lankan phone format: +94 or 0 followed by 9 digits
        return preg_match('/^(\+94|0)[1-9][0-9]{8}$/', $phone);
    }
    
    /**
     * Validate NIC (Sri Lankan National Identity Card)
     * @param string $nic
     * @return bool
     */
    public function validateNIC($nic) {
        // Old format: 9 digits with optional V or X at end
        // New format: 12 digits
        $nic = strtoupper($nic);
        return preg_match('/^[0-9]{9}[VX]?$/', $nic) || preg_match('/^[0-9]{12}$/', $nic);
    }
    
    /**
     * Validate date format
     * @param string $date
     * @param string $format
     * @return bool
     */
    public function validateDate($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
    
    /**
     * Validate age range
     * @param string $dateOfBirth
     * @param int $minAge
     * @param int $maxAge
     * @return bool
     */
    public function validateAge($dateOfBirth, $minAge = 0, $maxAge = 120) {
        $dob = new DateTime($dateOfBirth);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
        return $age >= $minAge && $age <= $maxAge;
    }
    
    /**
     * Validate number range
     * @param mixed $number
     * @param float $min
     * @param float $max
     * @return bool
     */
    public function validateNumberRange($number, $min, $max) {
        $num = floatval($number);
        return $num >= $min && $num <= $max;
    }
    
    /**
     * Validate text length
     * @param string $text
     * @param int $min
     * @param int $max
     * @return bool
     */
    public function validateLength($text, $min, $max) {
        $length = strlen(trim($text));
        return $length >= $min && $length <= $max;
    }
    
    /**
     * Validate alphanumeric with spaces
     * @param string $text
     * @return bool
     */
    public function validateAlphaNumSpace($text) {
        return preg_match('/^[a-zA-Z0-9\s]+$/', $text);
    }
    
    /**
     * Validate alphabetic with spaces
     * @param string $text
     * @return bool
     */
    public function validateAlphaSpace($text) {
        return preg_match('/^[a-zA-Z\s]+$/', $text);
    }
    
    /**
     * Validate file upload
     * @param array $file
     * @param array $allowedTypes
     * @param int $maxSize
     * @return array [success, message]
     */
    public function validateFile($file, $allowedTypes = [], $maxSize = 5242880) {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return [true, 'No file uploaded'];
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [false, 'File upload error'];
        }
        
        if ($file['size'] > $maxSize) {
            return [false, 'File size exceeds limit'];
        }
        
        if (!empty($allowedTypes)) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedTypes)) {
                return [false, 'File type not allowed'];
            }
        }
        
        return [true, 'File is valid'];
    }
    
    /**
     * Clean input array
     * @param array $data
     * @return array
     */
    public function cleanArray($data) {
        $cleaned = [];
        foreach ($data as $key => $value) {
            $cleaned[$key] = $this->sanitize($value);
        }
        return $cleaned;
    }
    
    /**
     * Validate strong password
     * @param string $password
     * @param int $minLength
     * @return array [success, message]
     */
    public function validatePassword($password, $minLength = 8) {
        if (strlen($password) < $minLength) {
            return [false, "Password must be at least {$minLength} characters"];
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            return [false, "Password must contain at least one uppercase letter"];
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            return [false, "Password must contain at least one lowercase letter"];
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            return [false, "Password must contain at least one number"];
        }
        
        if (!preg_match('/[\W_]/', $password)) {
            return [false, "Password must contain at least one special character"];
        }
        
        return [true, "Password is valid"];
    }
    
    /**
     * Validate family ID format
     * @param string $familyId
     * @return bool
     */
    public function validateFamilyId($familyId) {
        // Format: GN_ID-XXXX (e.g., GN12345-0001)
        return preg_match('/^GN[A-Z0-9]{5,10}-\d{4}$/', $familyId);
    }
    
    /**
     * Validate GN ID format
     * @param string $gnId
     * @return bool
     */
    public function validateGNId($gnId) {
        // Format: GN followed by 5-10 alphanumeric characters
        return preg_match('/^GN[A-Z0-9]{5,10}$/', $gnId);
    }
}