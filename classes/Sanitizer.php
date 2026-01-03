<?php
// classes/Sanitizer.php

class Sanitizer {
    
    /**
     * Sanitize string input - Alias for sanitize() method
     * 
     * @param string $input The input string to sanitize
     * @param int $max_length Maximum length of string (0 for no limit)
     * @return string Sanitized string
     */
    public function sanitizeString($input, $max_length = 0) {
        // Call the existing sanitize method with allow_html = false
        return $this->sanitize($input, false, $max_length);
    }
    
    /**
     * Sanitize string input
     * 
     * @param string $input The input string to sanitize
     * @param bool $allow_html Whether to allow HTML (default: false)
     * @param int $max_length Maximum length of string (0 for no limit)
     * @return string Sanitized string
     */
    public function sanitize($input, $allow_html = false, $max_length = 0) {
        if (is_null($input)) {
            return '';
        }
        
        // Convert to string if not already
        if (!is_string($input)) {
            $input = strval($input);
        }
        
        // Trim whitespace
        $input = trim($input);
        
        // Apply length limit if specified
        if ($max_length > 0 && strlen($input) > $max_length) {
            $input = substr($input, 0, $max_length);
        }
        
        if (!$allow_html) {
            // Strip HTML tags and encode special characters
            $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Remove any remaining HTML entities that might be malicious
            $input = strip_tags($input);
        } else {
            // For allowing some HTML, use a more permissive approach
            // But still strip potentially dangerous tags
            $allowed_tags = '<p><br><b><strong><i><em><u><ul><ol><li><span><div><a><img>';
            $input = strip_tags($input, $allowed_tags);
        }
        
        // Remove null bytes and other control characters
        $input = str_replace("\0", '', $input);
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
        
        return $input;
    }
    
    /**
     * Sanitize email address
     * 
     * @param string $email Email address to sanitize
     * @return string Sanitized email or empty string if invalid
     */
    public function sanitizeEmail($email) {
        $email = $this->sanitize($email, false, 100);
        
        // Remove whitespace and convert to lowercase
        $email = strtolower(trim($email));
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        
        // Additional security: check for suspicious patterns
        if (preg_match('/[\r\n]/', $email)) {
            return '';
        }
        
        return $email;
    }
    
    /**
     * Sanitize phone number
     * 
     * @param string $phone Phone number to sanitize
     * @return string Sanitized phone number
     */
    public function sanitizePhone($phone) {
        $phone = $this->sanitize($phone, false, 15);
        
        // Remove all non-numeric characters except plus sign for international numbers
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // For Sri Lankan numbers, ensure they start with 0 or +94
        if (preg_match('/^(0|\+94)/', $phone)) {
            // Remove +94 prefix and replace with 0
            $phone = preg_replace('/^\+94/', '0', $phone);
            
            // Ensure proper format: 0XXXXXXXXX (10 digits total)
            if (preg_match('/^0\d{9}$/', $phone)) {
                return $phone;
            }
        }
        
        // Return cleaned number even if format doesn't match exactly
        return $phone;
    }
    
    /**
     * Sanitize NIC number (Sri Lankan National ID Card)
     * 
     * @param string $nic NIC number to sanitize
     * @return string Sanitized NIC number
     */
    public function sanitizeNIC($nic) {
        $nic = $this->sanitize($nic, false, 12);
        
        // Remove spaces, dots, and dashes
        $nic = strtoupper(preg_replace('/[-\s.]/', '', $nic));
        
        // Validate format: 9 digits with V/X or 12 digits
        if (preg_match('/^\d{9}[VX]$/', $nic) || preg_match('/^\d{12}$/', $nic)) {
            return $nic;
        }
        
        return ''; // Invalid format
    }
    
    /**
     * Sanitize date string (YYYY-MM-DD format)
     * 
     * @param string $date Date string to sanitize
     * @return string Sanitized date or empty string if invalid
     */
    public function sanitizeDate($date) {
        $date = $this->sanitize($date, false, 10);
        
        // Validate date format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            // Check if date is valid
            list($year, $month, $day) = explode('-', $date);
            
            // Basic date validation
            if (checkdate($month, $day, $year)) {
                // Ensure date is not in the future (for birth dates)
                $current_year = date('Y');
                if ($year > ($current_year - 5) && $year <= ($current_year + 1)) {
                    // Allow recent births and future dates up to 1 year (for planning)
                    return $date;
                } elseif ($year >= 1900 && $year <= ($current_year + 1)) {
                    return $date;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Sanitize numeric input
     * 
     * @param mixed $number Number to sanitize
     * @param int $min Minimum value (null for no minimum)
     * @param int $max Maximum value (null for no maximum)
     * @param int $decimal Decimal places (0 for integer)
     * @return mixed Sanitized number or 0 if invalid
     */
    public function sanitizeNumber($number, $min = null, $max = null, $decimal = 0) {
        // Convert to string and sanitize
        $number_str = $this->sanitize(strval($number), false, 20);
        
        // Remove any non-numeric characters except decimal point and minus sign
        $number_str = preg_replace('/[^0-9.-]/', '', $number_str);
        
        // Convert to float
        $number = floatval($number_str);
        
        // Apply decimal rounding
        if ($decimal > 0) {
            $number = round($number, $decimal);
        } else {
            $number = intval($number);
        }
        
        // Apply min/max constraints
        if (!is_null($min) && $number < $min) {
            $number = $min;
        }
        
        if (!is_null($max) && $number > $max) {
            $number = $max;
        }
        
        return $number;
    }
    
    /**
     * Sanitize array of inputs recursively
     * 
     * @param array $array Array to sanitize
     * @param bool $allow_html Whether to allow HTML
     * @return array Sanitized array
     */
    public function sanitizeArray($array, $allow_html = false) {
        if (!is_array($array)) {
            return $this->sanitize($array, $allow_html);
        }
        
        $sanitized = [];
        foreach ($array as $key => $value) {
            $sanitized_key = $this->sanitize($key, false);
            if (is_array($value)) {
                $sanitized[$sanitized_key] = $this->sanitizeArray($value, $allow_html);
            } else {
                $sanitized[$sanitized_key] = $this->sanitize($value, $allow_html);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize for SQL LIKE parameter
     * Escapes wildcards for safe LIKE usage
     * 
     * @param string $input String to sanitize for LIKE
     * @return string Safe string for LIKE query
     */
    public function sanitizeForLike($input) {
        $input = $this->sanitize($input, false);
        
        // Escape SQL wildcards: %, _, [, ], ^, -
        $input = str_replace(
            ['%', '_', '[', ']', '^', '-'],
            ['\%', '\_', '\[', '\]', '\^', '\-'],
            $input
        );
        
        return $input;
    }
    
    /**
     * Sanitize file name
     * 
     * @param string $filename File name to sanitize
     * @return string Sanitized file name
     */
    public function sanitizeFilename($filename) {
        $filename = $this->sanitize($filename, false, 255);
        
        // Remove directory traversal attempts
        $filename = str_replace(['../', './', '/', '\\'], '', $filename);
        
        // Remove null bytes
        $filename = str_replace("\0", '', $filename);
        
        // Replace spaces with underscores
        $filename = preg_replace('/\s+/', '_', $filename);
        
        // Remove special characters except dots, hyphens, and underscores
        $filename = preg_replace('/[^\w\.-]/', '', $filename);
        
        return $filename;
    }
    
    /**
     * Sanitize URL
     * 
     * @param string $url URL to sanitize
     * @return string Sanitized URL or empty string if invalid
     */
    public function sanitizeUrl($url) {
        $url = $this->sanitize($url, false, 2000);
        
        // Decode URL encoded characters
        $url = urldecode($url);
        
        // Validate URL
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            // Ensure it's a safe protocol (http, https, ftp)
            $protocol = parse_url($url, PHP_URL_SCHEME);
            $allowed_protocols = ['http', 'https', 'ftp'];
            
            if (in_array(strtolower($protocol), $allowed_protocols)) {
                return $url;
            }
        }
        
        return '';
    }
    
    /**
     * Sanitize text for display (allows basic HTML)
     * 
     * @param string $text Text to sanitize
     * @return string Sanitized text
     */
    public function sanitizeText($text) {
        return $this->sanitize($text, true, 5000);
    }
    
    /**
     * Sanitize boolean input
     * 
     * @param mixed $value Value to convert to boolean
     * @return bool Sanitized boolean
     */
    public function sanitizeBoolean($value) {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_numeric($value)) {
            return $value != 0;
        }
        
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', 'yes', '1', 'on']);
        }
        
        return false;
    }
    
    /**
     * Sanitize family ID (14 digits)
     * 
     * @param string $familyId Family ID to sanitize
     * @return string Sanitized family ID
     */
    public function sanitizeFamilyId($familyId) {
        $familyId = $this->sanitize($familyId, false, 20);
        
        // Remove non-numeric characters
        $familyId = preg_replace('/[^0-9]/', '', $familyId);
        
        // Should be 14 digits
        if (strlen($familyId) === 14) {
            return $familyId;
        }
        
        return ''; // Invalid format
    }
    
    /**
     * Sanitize GN ID (13 characters - Sri Lanka GN code)
     * 
     * @param string $gnId GN ID to sanitize
     * @return string Sanitized GN ID
     */
    public function sanitizeGnId($gnId) {
        $gnId = $this->sanitize($gnId, false, 13);
        
        // Should be exactly 13 characters, alphanumeric
        if (preg_match('/^[A-Z0-9]{13}$/', strtoupper($gnId))) {
            return strtoupper($gnId);
        }
        
        return '';
    }
    
    /**
     * Validate and sanitize user input based on field type
     * 
     * @param string $field Field name/type
     * @param mixed $value Input value
     * @return mixed Sanitized value
     */
    public function sanitizeByFieldType($field, $value) {
        switch ($field) {
            case 'email':
                return $this->sanitizeEmail($value);
                
            case 'phone':
            case 'mobile_phone':
            case 'home_phone':
                return $this->sanitizePhone($value);
                
            case 'nic':
            case 'identification_number':
                return $this->sanitizeNIC($value);
                
            case 'date':
            case 'date_of_birth':
            case 'start_date':
            case 'end_date':
            case 'diagnosis_date':
                return $this->sanitizeDate($value);
                
            case 'amount':
            case 'income':
            case 'monthly_income':
            case 'annual_tax':
                return $this->sanitizeNumber($value, 0, 9999999999, 2);
                
            case 'percentage':
            case 'ownership_percentage':
                return $this->sanitizeNumber($value, 0, 100, 2);
                
            case 'land_size':
            case 'land_size_perches':
                return $this->sanitizeNumber($value, 0, 999999, 2);
                
            case 'family_id':
                return $this->sanitizeFamilyId($value);
                
            case 'gn_id':
            case 'office_code':
                return $this->sanitizeGnId($value);
                
            case 'url':
            case 'website':
                return $this->sanitizeUrl($value);
                
            case 'boolean':
            case 'is_active':
            case 'is_alive':
            case 'is_current':
            case 'is_permanent':
                return $this->sanitizeBoolean($value);
                
            case 'filename':
            case 'file_name':
                return $this->sanitizeFilename($value);
                
            case 'text':
            case 'description':
            case 'notes':
            case 'address':
            case 'treatment_details':
                return $this->sanitizeText($value);
                
            default:
                return $this->sanitize($value);
        }
    }
    
    /**
     * Clean input data from $_GET, $_POST, $_REQUEST
     * 
     * @param string $method Input method ('get', 'post', 'request', or 'all')
     * @param bool $allow_html Whether to allow HTML
     * @return array Cleaned input data
     */
    public function cleanInput($method = 'all', $allow_html = false) {
        $data = [];
        
        switch (strtolower($method)) {
            case 'get':
                $input = $_GET;
                break;
                
            case 'post':
                $input = $_POST;
                break;
                
            case 'request':
                $input = $_REQUEST;
                break;
                
            case 'all':
            default:
                $input = array_merge($_GET, $_POST);
                break;
        }
        
        foreach ($input as $key => $value) {
            $clean_key = $this->sanitize($key, false);
            if (is_array($value)) {
                $data[$clean_key] = $this->sanitizeArray($value, $allow_html);
            } else {
                $data[$clean_key] = $this->sanitize($value, $allow_html);
            }
        }
        
        return $data;
    }
    
    /**
     * Prevent XSS attacks by escaping output
     * 
     * @param string $output String to escape for output
     * @return string Escaped string
     */
    public function escapeOutput($output) {
        return htmlspecialchars($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Generate CSRF token
     * 
     * @return string CSRF token
     */
    public function generateCsrfToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        
        return $token;
    }
    
    /**
     * Validate CSRF token
     * 
     * @param string $token Token to validate
     * @param int $timeout Timeout in seconds (default: 3600 = 1 hour)
     * @return bool True if valid
     */
    public function validateCsrfToken($token, $timeout = 3600) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return false;
        }
        
        // Check token match
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            return false;
        }
        
        // Check timeout
        if (time() - $_SESSION['csrf_token_time'] > $timeout) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }
        
        return true;  
    }
}