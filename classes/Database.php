<?php
/**
 * Database Connection Class for FPMS
 */
class Database {
    private $mainConn = null;
    private $refConn = null;
    
    /**
     * Get main database connection
     * @return mysqli
     * @throws Exception
     */
    public function getMainConnection() {
        if ($this->mainConn === null) {
            // Use the existing getMainConnection() function from config.php
            $this->mainConn = getMainConnection();
            
            if ($this->mainConn->connect_error) {
                throw new Exception("Main database connection failed: " . $this->mainConn->connect_error);
            }
        }
        
        return $this->mainConn;
    }
    
    /**
     * Get reference database connection
     * @return mysqli
     * @throws Exception
     */
    public function getRefConnection() {
        if ($this->refConn === null) {
            // Use the existing getRefConnection() function from config.php
            $this->refConn = getRefConnection();
            
            if ($this->refConn->connect_error) {
                throw new Exception("Reference database connection failed: " . $this->refConn->connect_error);
            }
        }
        
        return $this->refConn;
    }
    
    /**
     * Execute a query with parameters
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return mysqli_result|bool
     */
    public function executeQuery($sql, $params = []) {
        $conn = $this->getMainConnection();
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $bindParams = [];
            
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
                $bindParams[] = $param;
            }
            
            array_unshift($bindParams, $types);
            call_user_func_array([$stmt, 'bind_param'], $this->refValues($bindParams));
        }
        
        $stmt->execute();
        
        if ($stmt->error) {
            throw new Exception("SQL execute failed: " . $stmt->error);
        }
        
        return $stmt->get_result();
    }
    
    /**
     * Execute an INSERT query and return the last insert ID
     * @param string $sql INSERT SQL query
     * @param array $params Parameters to bind
     * @return int Last insert ID
     */
    public function insert($sql, $params = []) {
        $conn = $this->getMainConnection();
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $bindParams = [];
            
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
                $bindParams[] = $param;
            }
            
            array_unshift($bindParams, $types);
            call_user_func_array([$stmt, 'bind_param'], $this->refValues($bindParams));
        }
        
        $stmt->execute();
        
        if ($stmt->error) {
            throw new Exception("SQL execute failed: " . $stmt->error);
        }
        
        return $stmt->insert_id;
    }
    
    /**
     * Execute an UPDATE query
     * @param string $sql UPDATE SQL query
     * @param array $params Parameters to bind
     * @return int Number of affected rows
     */
    public function update($sql, $params = []) {
        $conn = $this->getMainConnection();
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $bindParams = [];
            
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
                $bindParams[] = $param;
            }
            
            array_unshift($bindParams, $types);
            call_user_func_array([$stmt, 'bind_param'], $this->refValues($bindParams));
        }
        
        $stmt->execute();
        
        if ($stmt->error) {
            throw new Exception("SQL execute failed: " . $stmt->error);
        }
        
        return $stmt->affected_rows;
    }
    
    /**
     * Execute a DELETE query
     * @param string $sql DELETE SQL query
     * @param array $params Parameters to bind
     * @return int Number of affected rows
     */
    public function delete($sql, $params = []) {
        return $this->update($sql, $params);
    }
    
    /**
     * Execute a SELECT query and return all results
     * @param string $sql SELECT SQL query
     * @param array $params Parameters to bind
     * @return array Result set
     */
    public function selectAll($sql, $params = []) {
        $result = $this->executeQuery($sql, $params);
        $rows = [];
        
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        
        return $rows;
    }
    
    /**
     * Execute a SELECT query and return first result
     * @param string $sql SELECT SQL query
     * @param array $params Parameters to bind
     * @return array|null Single row or null
     */
    public function selectOne($sql, $params = []) {
        $result = $this->executeQuery($sql, $params);
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    /**
     * Execute a COUNT query
     * @param string $sql COUNT SQL query
     * @param array $params Parameters to bind
     * @return int Count result
     */
    public function count($sql, $params = []) {
        $result = $this->executeQuery($sql, $params);
        $row = $result->fetch_array();
        return $row[0] ?? 0;
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        $this->getMainConnection()->begin_transaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        $this->getMainConnection()->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        $this->getMainConnection()->rollback();
    }
    
    /**
     * Escape string for safe SQL use
     * @param string $string
     * @return string
     */
    public function escape($string) {
        return $this->getMainConnection()->real_escape_string($string);
    }
    
    /**
     * Get last insert ID
     * @return int
     */
    public function getLastInsertId() {
        return $this->getMainConnection()->insert_id;
    }
    
    /**
     * Get last error
     * @return string
     */
    public function getLastError() {
        return $this->getMainConnection()->error;
    }
    
    /**
     * Check if table exists
     * @param string $tableName
     * @return bool
     */
    public function tableExists($tableName) {
        $sql = "SHOW TABLES LIKE ?";
        $result = $this->executeQuery($sql, [$tableName]);
        return $result->num_rows > 0;
    }
    
    /**
     * Get table columns
     * @param string $tableName
     * @return array
     */
    public function getTableColumns($tableName) {
        $sql = "SHOW COLUMNS FROM $tableName";
        $result = $this->executeQuery($sql);
        $columns = [];
        
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        
        return $columns;
    }
    
    /**
     * Helper function for binding parameters by reference
     * @param array $arr
     * @return array
     */
    private function refValues($arr) {
        $refs = [];
        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    }
    
    /**
     * Close all connections
     */
    public function close() {
        if ($this->mainConn && $this->mainConn->ping()) {
            $this->mainConn->close();
            $this->mainConn = null;
        }
        
        if ($this->refConn && $this->refConn->ping()) {
            $this->refConn->close();
            $this->refConn = null;
        }
    }
    
    /**
     * Destructor - close connections safely
     */
    public function __destruct() {
        $this->close();
    }
}