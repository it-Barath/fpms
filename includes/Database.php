<?php
/**
 * Database Singleton Class for FPMS
 * Handles connections to both main and reference databases
 */
class Database {
    private static $instance = null;
    private $mainConnection = null;
    private $refConnection = null;
    
    private function __construct() {
        // Private constructor to prevent direct instantiation
    }
    
    /**
     * Get singleton instance
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    /**
     * Get main database connection (fpms)
     * @return mysqli
     */
    public function getConnection() {
        if ($this->mainConnection === null) {
            $this->mainConnection = new mysqli(
                MAIN_DB_HOST,
                MAIN_DB_USER,
                MAIN_DB_PASS,
                MAIN_DB_NAME,
                defined('MAIN_DB_PORT') ? MAIN_DB_PORT : 3306
            );
            
            if ($this->mainConnection->connect_error) {
                error_log("Main DB Connection Error: " . $this->mainConnection->connect_error);
                throw new Exception("Database connection failed: " . $this->mainConnection->connect_error);
            }
            
            $this->mainConnection->set_charset(MAIN_DB_CHARSET);
        }
        
        return $this->mainConnection;
    }
    
    /**
     * Get reference database connection (mobile_service)
     * @return mysqli
     */
    public function getRefConnection() {
        if ($this->refConnection === null) {
            $this->refConnection = new mysqli(
                REF_DB_HOST,
                REF_DB_USER,
                REF_DB_PASS,
                REF_DB_NAME,
                defined('REF_DB_PORT') ? REF_DB_PORT : 3306
            );
            
            if ($this->refConnection->connect_error) {
                error_log("Reference DB Connection Error: " . $this->refConnection->connect_error);
                throw new Exception("Reference database connection failed: " . $this->refConnection->connect_error);
            }
            
            $this->refConnection->set_charset(REF_DB_CHARSET);
        }
        
        return $this->refConnection;
    }
    
    /**
     * Execute query on main database
     * @param string $sql
     * @param array $params
     * @return mysqli_result|bool
     */
    public function query($sql, $params = []) {
        $conn = $this->getConnection();
        return $this->executeQuery($conn, $sql, $params);
    }
    
    /**
     * Execute query on reference database
     * @param string $sql
     * @param array $params
     * @return mysqli_result|bool
     */
    public function queryRef($sql, $params = []) {
        $conn = $this->getRefConnection();
        return $this->executeQuery($conn, $sql, $params);
    }
    
    /**
     * Execute prepared statement
     * @param mysqli $conn
     * @param string $sql
     * @param array $params
     * @return mysqli_result|bool
     */
    private function executeQuery($conn, $sql, $params) {
        if (empty($params)) {
            return $conn->query($sql);
        }
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_string($param)) {
                $types .= 's';
            } else {
                $types .= 'b'; // blob
            }
        }
        
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        return $result;
    }
    
    /**
     * Get single row
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public function getRow($sql, $params = []) {
        $result = $this->query($sql, $params);
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    }
    
    /**
     * Get all rows
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function getAll($sql, $params = []) {
        $result = $this->query($sql, $params);
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
    
    /**
     * Insert data into table
     * @param string $table
     * @param array $data
     * @return int|bool Insert ID or false on failure
     */
    public function insert($table, $data) {
        if (empty($data)) {
            return false;
        }
        
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $values = array_values($data);
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $result = $this->query($sql, $values);
        
        if ($result) {
            return $this->getConnection()->insert_id;
        }
        
        return false;
    }
    
    /**
     * Update data in table
     * @param string $table
     * @param array $data
     * @param string $where
     * @param array $whereParams
     * @return bool
     */
    public function update($table, $data, $where, $whereParams = []) {
        if (empty($data)) {
            return false;
        }
        
        $setParts = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "$column = ?";
            $values[] = $value;
        }
        
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE $table SET $setClause WHERE $where";
        
        // Merge data values with where parameters
        $params = array_merge($values, $whereParams);
        
        return $this->query($sql, $params);
    }
    
    /**
     * Begin transaction
     * @return bool
     */
    public function beginTransaction() {
        return $this->getConnection()->begin_transaction();
    }
    
    /**
     * Commit transaction
     * @return bool
     */
    public function commit() {
        return $this->getConnection()->commit();
    }
    
    /**
     * Rollback transaction
     * @return bool
     */
    public function rollback() {
        return $this->getConnection()->rollback();
    }
    
    /**
     * Escape string
     * @param string $value
     * @return string
     */
    public function escape($value) {
        return $this->getConnection()->real_escape_string($value);
    }
    
    /**
     * Close all connections
     */
    public function close() {
        if ($this->mainConnection) {
            $this->mainConnection->close();
            $this->mainConnection = null;
        }
        if ($this->refConnection) {
            $this->refConnection->close();
            $this->refConnection = null;
        }
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() { }
    
    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
?>