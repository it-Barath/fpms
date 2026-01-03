<?php
// classes/DivisionReport.php

class DivisionReport {
    private $db;
    private $division_code;
    private $year;
    private $month;
    private $quarter;
    private $gn_filter;
    private $gn_divisions_cache;
    
    public function __construct($db_connection, $division_code = null) {
        $this->db = $db_connection;
        $this->division_code = $division_code;
        $this->gn_divisions_cache = null;
    }
    
    /**
     * Set filters for report generation
     */
    public function setFilters($year = null, $month = null, $quarter = null, $gn_filter = 'all') {
        $this->year = $year ?: date('Y');
        $this->month = $month ?: date('n');
        $this->quarter = $quarter ?: ceil(date('n') / 3);
        $this->gn_filter = $gn_filter;
    }
    
    /**
     * Get all GN divisions under this division
     */
 public function getGNDivisions() {
    if ($this->gn_divisions_cache !== null) {
        return $this->gn_divisions_cache;
    }
    
    $gn_divisions = [];
    
    // Method 1: Get ALL GN divisions (ignore hierarchy since codes don't match)
    $sql = "SELECT 
                user_id,
                office_code,
                office_name,
                username,
                email,
                phone
            FROM users 
            WHERE user_type = 'gn' 
            AND is_active = 1
            ORDER BY office_name";
    
    $stmt = $this->db->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $gn_code = $row['office_code'];
        
        // Get family count
        $family_sql = "SELECT COUNT(*) as families FROM families WHERE gn_id = ?";
        $family_stmt = $this->db->prepare($family_sql);
        $family_stmt->bind_param("s", $gn_code);
        $family_stmt->execute();
        $family_result = $family_stmt->get_result();
        $family_row = $family_result->fetch_assoc();
        
        // Get population count
        $pop_sql = "SELECT COUNT(*) as population FROM citizens c 
                   INNER JOIN families f ON c.family_id = f.family_id 
                   WHERE f.gn_id = ?";
        $pop_stmt = $this->db->prepare($pop_sql);
        $pop_stmt->bind_param("s", $gn_code);
        $pop_stmt->execute();
        $pop_result = $pop_stmt->get_result();
        $pop_row = $pop_result->fetch_assoc();
        
        // Calculate data completeness
        $completeness = $this->calculateGNCompleteness($gn_code);
        
        $gn_divisions[] = [
            'gn_id' => $gn_code,
            'office_code' => $gn_code,
            'office_name' => $row['office_name'],
            'officer_name' => $row['username'],
            'officer_email' => $row['email'],
            'officer_phone' => $row['phone'],
            'families' => $family_row['families'] ?? 0,
            'population' => $pop_row['population'] ?? 0,
            'completeness' => $completeness
        ];
    }
    
    $this->gn_divisions_cache = $gn_divisions;
    return $gn_divisions;
}
    
    /**
     * Get division overview statistics
     */
public function getDivisionOverview() {
    $stats = [];
    
    // Get ALL GN divisions (not filtered by division)
    $gn_divisions = $this->getGNDivisions();
    $gn_count = count($gn_divisions);
    
    $stats['Total GN Divisions'] = [
        'count' => $gn_count,
        'percentage' => 100,
        'avg_per_gn' => 1
    ];
    
    // Calculate totals from ALL GN divisions
    $total_families = 0;
    $total_population = 0;
    $total_completeness = 0;
    
    foreach ($gn_divisions as $gn) {
        $total_families += $gn['families'];
        $total_population += $gn['population'];
        $total_completeness += $gn['completeness'];
    }
    
    $stats['Total Families'] = [
        'count' => $total_families,
        'percentage' => 100,
        'avg_per_gn' => $gn_count > 0 ? round($total_families / $gn_count, 1) : 0
    ];
    
    $stats['Total Population'] = [
        'count' => $total_population,
        'percentage' => 100,
        'avg_per_gn' => $gn_count > 0 ? round($total_population / $gn_count, 1) : 0
    ];
    
    // Get average family size
    $avg_family_size = $total_families > 0 ? $total_population / $total_families : 0;
    $stats['Average Family Size'] = [
        'count' => round($avg_family_size, 1),
        'percentage' => null,
        'avg_per_gn' => round($avg_family_size, 1)
    ];
    
    // Get data completeness average
    $avg_completeness = $gn_count > 0 ? $total_completeness / $gn_count : 0;
    $stats['Data Completeness'] = [
        'count' => round($avg_completeness, 1) . '%',
        'percentage' => round($avg_completeness, 1),
        'avg_per_gn' => round($avg_completeness, 1)
    ];
    
    // Get gender distribution (from ALL data)
    $gender_stats = $this->getGenderDistribution();
    if (!empty($gender_stats)) {
        $stats = array_merge($stats, $gender_stats);
    }
    
    return $stats;
}
    
    /**
     * Get division population statistics
     */
 public function getDivisionPopulationStats() {
    $stats = [];
    
    if ($this->gn_filter === 'all') {
        // Get population from ALL GN divisions
        $sql = "SELECT COUNT(*) as total FROM citizens";
        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();
        
        $total_population = $row['total'] ?? 0;
        
        // Get gender distribution
        $gender_sql = "SELECT 
                        c.gender,
                        COUNT(*) as count
                    FROM citizens c 
                    GROUP BY c.gender";
        
        $result = $this->db->query($gender_sql);
        
        while ($row = $result->fetch_assoc()) {
            $gender_name = ucfirst($row['gender']);
            $stats[$gender_name] = [
                'count' => $row['count'],
                'percentage' => $total_population > 0 ? round(($row['count'] / $total_population) * 100, 1) : 0,
                'avg_per_gn' => 0
            ];
        }
        
        // Calculate average per GN
        $gn_divisions = $this->getGNDivisions();
        $gn_count = count($gn_divisions);
        foreach ($stats as $gender => &$data) {
            $data['avg_per_gn'] = $gn_count > 0 ? round($data['count'] / $gn_count, 1) : 0;
        }
        
    } else {
        // Get population for specific GN
        $sql = "SELECT 
                    c.gender,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY c.gender";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $this->gn_filter);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $gender_name = ucfirst($row['gender']);
            $stats[$gender_name] = [
                'count' => $row['count'],
                'percentage' => 0,
                'avg_per_gn' => $row['count']
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
    }
    
    return $stats;
}
    
    /**
     * Get division family statistics
     */
    public function getDivisionFamilyStats() {
        $stats = [];
        
        if ($this->gn_filter === 'all') {
            // Get family size distribution across division
            $sql = "SELECT 
                        f.total_members as size,
                        COUNT(*) as count
                    FROM families f 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ?
                    GROUP BY f.total_members
                    ORDER BY f.total_members";
            
            $like_code = $this->division_code . '%';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $like_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $size_name = "Family Size " . $row['size'];
                $stats[$size_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => 0
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages and averages
            $gn_divisions = $this->getGNDivisions();
            $gn_count = count($gn_divisions);
            
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
                $data['avg_per_gn'] = $gn_count > 0 ? round($data['count'] / $gn_count, 1) : 0;
            }
            
        } else {
            // Get family size distribution for specific GN
            $sql = "SELECT 
                        total_members as size,
                        COUNT(*) as count
                    FROM families 
                    WHERE gn_id = ?
                    GROUP BY total_members
                    ORDER BY total_members";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $this->gn_filter);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $size_name = "Family Size " . $row['size'];
                $stats[$size_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => $row['count']
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get division demographic statistics
     */
    public function getDivisionDemographicStats() {
        $stats = [];
        
        if ($this->gn_filter === 'all') {
            // Get religion distribution across division
            $sql = "SELECT 
                        COALESCE(c.religion, 'Not specified') as religion,
                        COUNT(*) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ?
                    GROUP BY c.religion
                    ORDER BY count DESC
                    LIMIT 10";
            
            $like_code = $this->division_code . '%';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $like_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $religion_name = "Religion: " . $row['religion'];
                $stats[$religion_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => 0
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages and averages
            $gn_divisions = $this->getGNDivisions();
            $gn_count = count($gn_divisions);
            
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
                $data['avg_per_gn'] = $gn_count > 0 ? round($data['count'] / $gn_count, 1) : 0;
            }
            
        } else {
            // Get religion distribution for specific GN
            $sql = "SELECT 
                        COALESCE(c.religion, 'Not specified') as religion,
                        COUNT(*) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    WHERE f.gn_id = ?
                    GROUP BY c.religion
                    ORDER BY count DESC
                    LIMIT 10";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $this->gn_filter);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $religion_name = "Religion: " . $row['religion'];
                $stats[$religion_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => $row['count']
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get division education statistics
     */
    public function getDivisionEducationStats() {
        $stats = [];
        
        if ($this->gn_filter === 'all') {
            // Get education levels across division
            $sql = "SELECT 
                        COALESCE(e.education_level, 'No education recorded') as level,
                        COUNT(DISTINCT e.citizen_id) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    LEFT JOIN education e ON c.citizen_id = e.citizen_id
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ?
                    GROUP BY e.education_level";
            
            $like_code = $this->division_code . '%';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $like_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $level_name = $this->getEducationLevelName($row['level']);
                $stats[$level_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => 0
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages and averages
            $gn_divisions = $this->getGNDivisions();
            $gn_count = count($gn_divisions);
            
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
                $data['avg_per_gn'] = $gn_count > 0 ? round($data['count'] / $gn_count, 1) : 0;
            }
            
        } else {
            // Get education levels for specific GN
            $sql = "SELECT 
                        COALESCE(e.education_level, 'No education recorded') as level,
                        COUNT(DISTINCT e.citizen_id) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    LEFT JOIN education e ON c.citizen_id = e.citizen_id
                    WHERE f.gn_id = ?
                    GROUP BY e.education_level";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $this->gn_filter);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $level_name = $this->getEducationLevelName($row['level']);
                $stats[$level_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => $row['count']
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get division employment statistics
     */
    public function getDivisionEmploymentStats() {
        $stats = [];
        
        if ($this->gn_filter === 'all') {
            // Get employment types across division
            $sql = "SELECT 
                        COALESCE(e.employment_type, 'Not employed') as type,
                        COUNT(DISTINCT e.citizen_id) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    LEFT JOIN employment e ON c.citizen_id = e.citizen_id AND e.is_current_job = 1
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ?
                    GROUP BY e.employment_type";
            
            $like_code = $this->division_code . '%';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $like_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $type_name = $this->getEmploymentTypeName($row['type']);
                $stats[$type_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => 0
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages and averages
            $gn_divisions = $this->getGNDivisions();
            $gn_count = count($gn_divisions);
            
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
                $data['avg_per_gn'] = $gn_count > 0 ? round($data['count'] / $gn_count, 1) : 0;
            }
            
        } else {
            // Get employment types for specific GN
            $sql = "SELECT 
                        COALESCE(e.employment_type, 'Not employed') as type,
                        COUNT(DISTINCT e.citizen_id) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    LEFT JOIN employment e ON c.citizen_id = e.citizen_id AND e.is_current_job = 1
                    WHERE f.gn_id = ?
                    GROUP BY e.employment_type";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $this->gn_filter);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $type_name = $this->getEmploymentTypeName($row['type']);
                $stats[$type_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => $row['count']
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get division health statistics
     */
    public function getDivisionHealthStats() {
        $stats = [];
        
        if ($this->gn_filter === 'all') {
            // Get health conditions across division
            $sql = "SELECT 
                        COALESCE(h.condition_type, 'No conditions') as type,
                        COUNT(DISTINCT h.citizen_id) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    LEFT JOIN health_conditions h ON c.citizen_id = h.citizen_id
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ?
                    GROUP BY h.condition_type";
            
            $like_code = $this->division_code . '%';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $like_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $type_name = $this->getHealthConditionTypeName($row['type']);
                $stats[$type_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => 0
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages and averages
            $gn_divisions = $this->getGNDivisions();
            $gn_count = count($gn_divisions);
            
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
                $data['avg_per_gn'] = $gn_count > 0 ? round($data['count'] / $gn_count, 1) : 0;
            }
            
        } else {
            // Get health conditions for specific GN
            $sql = "SELECT 
                        COALESCE(h.condition_type, 'No conditions') as type,
                        COUNT(DISTINCT h.citizen_id) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    LEFT JOIN health_conditions h ON c.citizen_id = h.citizen_id
                    WHERE f.gn_id = ?
                    GROUP BY h.condition_type";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $this->gn_filter);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $type_name = $this->getHealthConditionTypeName($row['type']);
                $stats[$type_name] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => $row['count']
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get division age statistics
     */
    public function getDivisionAgeStats() {
        $stats = [];
        
        if ($this->gn_filter === 'all') {
            // Get age groups across division
            $sql = "SELECT 
                        CASE
                            WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 18 THEN 'Children (0-17)'
                            WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Youth (18-35)'
                            WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) BETWEEN 36 AND 60 THEN 'Adults (36-60)'
                            ELSE 'Seniors (60+)'
                        END as age_group,
                        COUNT(*) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ?
                    GROUP BY age_group
                    ORDER BY 
                        CASE age_group
                            WHEN 'Children (0-17)' THEN 1
                            WHEN 'Youth (18-35)' THEN 2
                            WHEN 'Adults (36-60)' THEN 3
                            ELSE 4
                        END";
            
            $like_code = $this->division_code . '%';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $like_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $stats[$row['age_group']] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => 0
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages and averages
            $gn_divisions = $this->getGNDivisions();
            $gn_count = count($gn_divisions);
            
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
                $data['avg_per_gn'] = $gn_count > 0 ? round($data['count'] / $gn_count, 1) : 0;
            }
            
        } else {
            // Get age groups for specific GN
            $sql = "SELECT 
                        CASE
                            WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) < 18 THEN 'Children (0-17)'
                            WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Youth (18-35)'
                            WHEN TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) BETWEEN 36 AND 60 THEN 'Adults (36-60)'
                            ELSE 'Seniors (60+)'
                        END as age_group,
                        COUNT(*) as count
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    WHERE f.gn_id = ?
                    GROUP BY age_group
                    ORDER BY 
                        CASE age_group
                            WHEN 'Children (0-17)' THEN 1
                            WHEN 'Youth (18-35)' THEN 2
                            WHEN 'Adults (36-60)' THEN 3
                            ELSE 4
                        END";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $this->gn_filter);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $stats[$row['age_group']] = [
                    'count' => $row['count'],
                    'percentage' => 0,
                    'avg_per_gn' => $row['count']
                ];
                $total += $row['count'];
            }
            
            // Calculate percentages
            foreach ($stats as &$data) {
                $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get division gender statistics
     */
    public function getDivisionGenderStats() {
        return $this->getDivisionPopulationStats();
    }
    
    /**
     * Get GN comparison data
     */
    public function getGNComparison() {
        $comparison_data = [];
        
        $gn_divisions = $this->getGNDivisions();
        
        // Get detailed stats for each GN
        foreach ($gn_divisions as $index => $gn) {
            $gn_id = $gn['office_code'];
            
            // Get gender distribution
            $gender_sql = "SELECT 
                            c.gender,
                            COUNT(*) as count
                        FROM citizens c 
                        INNER JOIN families f ON c.family_id = f.family_id 
                        WHERE f.gn_id = ?
                        GROUP BY c.gender";
            
            $stmt = $this->db->prepare($gender_sql);
            $stmt->bind_param("s", $gn_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $male_count = 0;
            $female_count = 0;
            $other_count = 0;
            $total_population = $gn['population'];
            
            while ($row = $result->fetch_assoc()) {
                if ($row['gender'] === 'male') {
                    $male_count = $row['count'];
                } elseif ($row['gender'] === 'female') {
                    $female_count = $row['count'];
                } else {
                    $other_count = $row['count'];
                }
            }
            
            $male_percent = $total_population > 0 ? ($male_count / $total_population) * 100 : 0;
            $female_percent = $total_population > 0 ? ($female_count / $total_population) * 100 : 0;
            
            $comparison_data[] = [
                'gn_id' => $gn_id,
                'office_code' => $gn_id,
                'office_name' => $gn['office_name'],
                'families' => $gn['families'],
                'population' => $total_population,
                'avg_family_size' => $gn['families'] > 0 ? round($total_population / $gn['families'], 1) : 0,
                'male_count' => $male_count,
                'female_count' => $female_count,
                'other_count' => $other_count,
                'male_percent' => round($male_percent, 1),
                'female_percent' => round($female_percent, 1),
                'completeness' => $gn['completeness'],
                'rank' => $index + 1
            ];
        }
        
        // Sort by population (descending)
        usort($comparison_data, function($a, $b) {
            return $b['population'] <=> $a['population'];
        });
        
        // Update ranks after sorting
        foreach ($comparison_data as $index => &$gn) {
            $gn['rank'] = $index + 1;
        }
        
        return $comparison_data;
    }
    
    /**
     * Get trend analysis
     */
    public function getTrendAnalysis() {
        $trends = [];
        
        // Get monthly registration trend for the year
        for ($month = 1; $month <= 12; $month++) {
            $sql = "SELECT COUNT(*) as families 
                    FROM families f 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ? 
                    AND YEAR(f.created_at) = ? AND MONTH(f.created_at) = ?";
            
            $like_code = $this->division_code . '%';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("sii", $like_code, $this->year, $month);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $month_name = date('M', mktime(0, 0, 0, $month, 1));
            $trends[$month_name] = [
                'count' => $row['families'] ?? 0,
                'percentage' => 0,
                'trend' => 0
            ];
        }
        
        // Calculate percentages
        $total = array_sum(array_column($trends, 'count'));
        foreach ($trends as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
        
        return $trends;
    }
    
    /**
     * Get monthly division report
     */
    public function getMonthlyDivisionReport() {
        $report = [];
        
        if ($this->gn_filter === 'all') {
            // Get new families this month
            $sql = "SELECT COUNT(*) as families 
                    FROM families f 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ? 
                    AND YEAR(f.created_at) = ? AND MONTH(f.created_at) = ?";
            
            $like_code = $this->division_code . '%';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("sii", $like_code, $this->year, $this->month);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $report['New Families'] = [
                'count' => $row['families'] ?? 0,
                'percentage' => null,
                'avg_per_gn' => 0
            ];
            
            // Get new citizens this month
            $sql = "SELECT COUNT(*) as citizens 
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ? 
                    AND YEAR(c.created_at) = ? AND MONTH(c.created_at) = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("sii", $like_code, $this->year, $this->month);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $report['New Citizens'] = [
                'count' => $row['citizens'] ?? 0,
                'percentage' => null,
                'avg_per_gn' => 0
            ];
            
            // Calculate averages per GN
            $gn_divisions = $this->getGNDivisions();
            $gn_count = count($gn_divisions);
            
            foreach ($report as &$data) {
                $data['avg_per_gn'] = $gn_count > 0 ? round($data['count'] / $gn_count, 1) : 0;
            }
            
        } else {
            // Get data for specific GN
            $sql = "SELECT COUNT(*) as families 
                    FROM families 
                    WHERE gn_id = ? 
                    AND YEAR(created_at) = ? AND MONTH(created_at) = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("sii", $this->gn_filter, $this->year, $this->month);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $report['New Families'] = [
                'count' => $row['families'] ?? 0,
                'percentage' => null,
                'avg_per_gn' => $row['families'] ?? 0
            ];
            
            $sql = "SELECT COUNT(*) as citizens 
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    WHERE f.gn_id = ? 
                    AND YEAR(c.created_at) = ? AND MONTH(c.created_at) = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("sii", $this->gn_filter, $this->year, $this->month);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $report['New Citizens'] = [
                'count' => $row['citizens'] ?? 0,
                'percentage' => null,
                'avg_per_gn' => $row['citizens'] ?? 0
            ];
        }
        
        return $report;
    }
    
    /**
     * Get division quick stats
     */
    public function getDivisionQuickStats() {
        $stats = [];
        
        // Get GN divisions
        $gn_divisions = $this->getGNDivisions();
        $stats['total_gn'] = count($gn_divisions);
        
        // Calculate totals
        $total_families = 0;
        $total_population = 0;
        $total_completeness = 0;
        
        foreach ($gn_divisions as $gn) {
            $total_families += $gn['families'];
            $total_population += $gn['population'];
            $total_completeness += $gn['completeness'];
        }
        
        $stats['total_families'] = $total_families;
        $stats['total_population'] = $total_population;
        $stats['avg_family_size'] = $total_families > 0 ? round($total_population / $total_families, 1) : 0;
        $stats['data_completeness'] = count($gn_divisions) > 0 ? round($total_completeness / count($gn_divisions), 1) : 0;
        
        // Get top 5 GN divisions by population
        usort($gn_divisions, function($a, $b) {
            return $b['population'] <=> $a['population'];
        });
        
        $stats['top_gn'] = array_slice($gn_divisions, 0, 5);
        
        return $stats;
    }
    
    /**
     * Get division activities
     */
    public function getDivisionActivities($limit = 10) {
        $activities = [];
        
        // Get recent family registrations across division
        $sql = "SELECT 
                    f.family_id,
                    f.created_at,
                    f.gn_id,
                    c.full_name as head_name,
                    u.office_name as gn_name,
                    'New Family Registration' as title,
                    'Family registered in system' as description
                FROM families f 
                LEFT JOIN citizens c ON f.family_id = c.family_id AND c.relation_to_head = 'Self'
                INNER JOIN users u ON f.gn_id = u.office_code 
                WHERE u.user_type = 'gn' AND u.office_code LIKE ?
                ORDER BY f.created_at DESC 
                LIMIT ?";
        
        $like_code = $this->division_code . '%';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("si", $like_code, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $time_ago = $this->getTimeAgo($row['created_at']);
            $activities[] = [
                'title' => $row['title'],
                'description' => $row['description'],
                'time_ago' => $time_ago,
                'gn_name' => $row['gn_name'],
                'details' => "Family ID: " . $row['family_id']
            ];
        }
        
        return $activities;
    }
    
    /**
     * Get GN performance chart data
     */
    public function getGNPerformanceChart() {
        $gn_divisions = $this->getGNDivisions();
        
        $data = [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'Families',
                    'data' => [],
                    'backgroundColor' => '#36A2EB',
                    'borderColor' => '#1E88E5',
                    'borderWidth' => 1
                ],
                [
                    'label' => 'Population',
                    'data' => [],
                    'backgroundColor' => '#FF6384',
                    'borderColor' => '#D81B60',
                    'borderWidth' => 1
                ]
            ]
        ];
        
        foreach ($gn_divisions as $gn) {
            $data['labels'][] = substr($gn['office_name'], 0, 15) . '...';
            $data['datasets'][0]['data'][] = $gn['families'];
            $data['datasets'][1]['data'][] = $gn['population'] / 10; // Scale down for better visualization
        }
        
        return $data;
    }
    
    /**
     * Get population distribution chart
     */
    public function getPopulationDistributionChart() {
        if ($this->gn_filter === 'all') {
            // Get population by GN
            $gn_divisions = $this->getGNDivisions();
            
            $data = [
                'labels' => [],
                'datasets' => [[
                    'label' => 'Population',
                    'data' => [],
                    'backgroundColor' => []
                ]]
            ];
            
            $colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#8AC926', '#1982C4'];
            
            foreach ($gn_divisions as $index => $gn) {
                $data['labels'][] = substr($gn['office_name'], 0, 15) . '...';
                $data['datasets'][0]['data'][] = $gn['population'];
                $data['datasets'][0]['backgroundColor'][] = $colors[$index % count($colors)];
            }
            
        } else {
            // Get gender distribution for specific GN
            $stats = $this->getDivisionGenderStats();
            
            $data = [
                'labels' => [],
                'datasets' => [[
                    'label' => 'Population',
                    'data' => [],
                    'backgroundColor' => ['#36A2EB', '#FF6384', '#FFCE56']
                ]]
            ];
            
            foreach ($stats as $gender => $stat) {
                $data['labels'][] = $gender;
                $data['datasets'][0]['data'][] = $stat['count'];
            }
        }
        
        return $data;
    }
    
    /**
     * Get family size comparison chart
     */
    public function getFamilySizeComparisonChart() {
        $gn_divisions = $this->getGNDivisions();
        
        $data = [
            'type' => 'bar',
            'data' => [
                'labels' => [],
                'datasets' => [[
                    'label' => 'Average Family Size',
                    'data' => [],
                    'backgroundColor' => '#4BC0C0',
                    'borderColor' => '#00ACC1',
                    'borderWidth' => 1
                ]]
            ],
            'options' => [
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'title' => [
                            'display' => true,
                            'text' => 'Family Size'
                        ]
                    ]
                ]
            ]
        ];
        
        foreach ($gn_divisions as $gn) {
            $avg_size = $gn['families'] > 0 ? round($gn['population'] / $gn['families'], 1) : 0;
            $data['data']['labels'][] = substr($gn['office_name'], 0, 12) . '...';
            $data['data']['datasets'][0]['data'][] = $avg_size;
        }
        
        return $data;
    }
    
    /**
     * Get ethnicity distribution chart
     */
    public function getEthnicityDistributionChart() {
        $sql = "SELECT 
                    COALESCE(c.ethnicity, 'Not specified') as ethnicity,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                INNER JOIN users u ON f.gn_id = u.office_code 
                WHERE u.user_type = 'gn' AND u.office_code LIKE ?
                GROUP BY c.ethnicity
                ORDER BY count DESC
                LIMIT 8";
        
        $like_code = $this->division_code . '%';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $like_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => 'Population',
                'data' => [],
                'backgroundColor' => [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                    '#9966FF', '#FF9F40', '#8AC926', '#1982C4'
                ]
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['ethnicity'];
            $data['datasets'][0]['data'][] = $row['count'];
        }
        
        return $data;
    }
    
    /**
     * Get education comparison chart
     */
    public function getEducationComparisonChart() {
        $stats = $this->getDivisionEducationStats();
        
        $data = [
            'type' => 'bar',
            'data' => [
                'labels' => array_keys($stats),
                'datasets' => [[
                    'label' => 'Education Level Distribution',
                    'data' => array_column($stats, 'count'),
                    'backgroundColor' => '#8AC926',
                    'borderColor' => '#6A994E',
                    'borderWidth' => 1
                ]]
            ],
            'options' => [
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'title' => [
                            'display' => true,
                            'text' => 'Number of People'
                        ]
                    ]
                ]
            ]
        ];
        
        return $data;
    }
    
    /**
     * Get employment comparison chart
     */
    public function getEmploymentComparisonChart() {
        $stats = $this->getDivisionEmploymentStats();
        
        $data = [
            'type' => 'pie',
            'data' => [
                'labels' => array_keys($stats),
                'datasets' => [[
                    'label' => 'Employment Types',
                    'data' => array_column($stats, 'count'),
                    'backgroundColor' => [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                        '#9966FF', '#FF9F40', '#8AC926'
                    ]
                ]]
            ]
        ];
        
        return $data;
    }
    
    /**
     * Get health comparison chart
     */
    public function getHealthComparisonChart() {
        $stats = $this->getDivisionHealthStats();
        
        $data = [
            'type' => 'bar',
            'data' => [
                'labels' => array_keys($stats),
                'datasets' => [[
                    'label' => 'Health Conditions',
                    'data' => array_column($stats, 'count'),
                    'backgroundColor' => '#FF6384',
                    'borderColor' => '#D81B60',
                    'borderWidth' => 1
                ]]
            ]
        ];
        
        return $data;
    }
    
    /**
     * Get age group comparison chart
     */
    public function getAgeGroupComparisonChart() {
        $stats = $this->getDivisionAgeStats();
        
        $data = [
            'type' => 'pie',
            'data' => [
                'labels' => array_keys($stats),
                'datasets' => [[
                    'label' => 'Age Groups',
                    'data' => array_column($stats, 'count'),
                    'backgroundColor' => [
                        '#36A2EB', '#FF6384', '#FFCE56', '#4BC0C0'
                    ]
                ]]
            ]
        ];
        
        return $data;
    }
    
    /**
     * Get gender ratio comparison chart
     */
    public function getGenderRatioComparisonChart() {
        $gn_divisions = $this->getGNDivisions();
        
        $data = [
            'type' => 'bar',
            'data' => [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Male',
                        'data' => [],
                        'backgroundColor' => '#36A2EB'
                    ],
                    [
                        'label' => 'Female',
                        'data' => [],
                        'backgroundColor' => '#FF6384'
                    ]
                ]
            ],
            'options' => [
                'scales' => [
                    'x' => [
                        'stacked' => true
                    ],
                    'y' => [
                        'stacked' => true,
                        'beginAtZero' => true
                    ]
                ]
            ]
        ];
        
        foreach ($gn_divisions as $gn) {
            $gn_id = $gn['office_code'];
            
            // Get gender counts
            $sql = "SELECT 
                        SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) as male,
                        SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) as female
                    FROM citizens c 
                    INNER JOIN families f ON c.family_id = f.family_id 
                    WHERE f.gn_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $gn_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $data['data']['labels'][] = substr($gn['office_name'], 0, 10) . '...';
            $data['data']['datasets'][0]['data'][] = $row['male'] ?? 0;
            $data['data']['datasets'][1]['data'][] = $row['female'] ?? 0;
        }
        
        return $data;
    }
    
    /**
     * Get GN comparison chart
     */
    public function getGNComparisonChart() {
        $comparison_data = $this->getGNComparison();
        
        $data = [
            'type' => 'radar',
            'data' => [
                'labels' => ['Families', 'Population', 'Family Size', 'Completeness'],
                'datasets' => []
            ],
            'options' => [
                'scales' => [
                    'r' => [
                        'beginAtZero' => true
                    ]
                ]
            ]
        ];
        
        $colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF'];
        
        foreach ($comparison_data as $index => $gn) {
            if ($index >= 5) break; // Limit to top 5
            
            $dataset = [
                'label' => $gn['office_name'],
                'data' => [
                    $gn['families'] / 100, // Normalize
                    $gn['population'] / 1000, // Normalize
                    $gn['avg_family_size'] * 10, // Scale up
                    $gn['completeness']
                ],
                'backgroundColor' => $this->hexToRgba($colors[$index], 0.2),
                'borderColor' => $colors[$index],
                'borderWidth' => 2
            ];
            
            $data['data']['datasets'][] = $dataset;
        }
        
        return $data;
    }
    
    /**
     * Get registration trend chart
     */
    public function getRegistrationTrendChart() {
        $data = [
            'type' => 'line',
            'data' => [
                'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                'datasets' => [
                    [
                        'label' => 'New Families',
                        'data' => array_fill(0, 12, 0),
                        'borderColor' => '#36A2EB',
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'fill' => true,
                        'tension' => 0.4
                    ]
                ]
            ],
            'options' => [
                'scales' => [
                    'y' => [
                        'beginAtZero' => true
                    ]
                ]
            ]
        ];
        
        // Get monthly data
        for ($month = 1; $month <= 12; $month++) {
            $sql = "SELECT COUNT(*) as families 
                    FROM families f 
                    INNER JOIN users u ON f.gn_id = u.office_code 
                    WHERE u.user_type = 'gn' AND u.office_code LIKE ? 
                    AND YEAR(f.created_at) = ? AND MONTH(f.created_at) = ?";
            
            $like_code = $this->division_code . '%';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("sii", $like_code, $this->year, $month);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $data['data']['datasets'][0]['data'][$month - 1] = $row['families'] ?? 0;
        }
        
        return $data;
    }
    
    /**
     * Get monthly comparison chart
     */
    public function getMonthlyComparisonChart() {
        // Get current and previous month data
        $current_month = $this->getMonthlyDivisionReport();
        
        // Temporarily set month to previous month
        $prev_month = $this->month - 1;
        $prev_year = $this->year;
        if ($prev_month < 1) {
            $prev_month = 12;
            $prev_year--;
        }
        
        $temp_month = $this->month;
        $temp_year = $this->year;
        
        $this->month = $prev_month;
        $this->year = $prev_year;
        $previous_month = $this->getMonthlyDivisionReport();
        
        // Restore original values
        $this->month = $temp_month;
        $this->year = $temp_year;
        
        $data = [
            'type' => 'bar',
            'data' => [
                'labels' => ['New Families', 'New Citizens'],
                'datasets' => [
                    [
                        'label' => 'Current Month',
                        'data' => [
                            $current_month['New Families']['count'] ?? 0,
                            $current_month['New Citizens']['count'] ?? 0
                        ],
                        'backgroundColor' => '#36A2EB'
                    ],
                    [
                        'label' => 'Previous Month',
                        'data' => [
                            $previous_month['New Families']['count'] ?? 0,
                            $previous_month['New Citizens']['count'] ?? 0
                        ],
                        'backgroundColor' => '#FF6384'
                    ]
                ]
            ]
        ];
        
        return $data;
    }
    
    /**
     * Helper: Get gender distribution
     */
private function getGenderDistribution() {
    $stats = [];
    
    if ($this->gn_filter === 'all') {
        // Get gender distribution from ALL citizens
        $sql = "SELECT 
                    c.gender,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                GROUP BY c.gender";
        
        $result = $this->db->query($sql);
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $gender_name = ucfirst($row['gender']);
            $stats[$gender_name] = [
                'count' => $row['count'],
                'percentage' => 0,
                'avg_per_gn' => 0
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        $gn_divisions = $this->getGNDivisions();
        $gn_count = count($gn_divisions);
        
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
            $data['avg_per_gn'] = $gn_count > 0 ? round($data['count'] / $gn_count, 1) : 0;
        }
    }
    
    return $stats;
}
    
    /**
     * Helper: Calculate GN data completeness
     */
    private function calculateGNCompleteness($gn_id) {
        $completeness = 0;
        
        // Check if GN has any families
        $sql = "SELECT COUNT(*) as families FROM families WHERE gn_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['families'] > 0) {
            $completeness += 30;
        }
        
        // Check citizen data completeness
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN identification_number IS NOT NULL AND identification_number != '' THEN 1 ELSE 0 END) as with_id,
                    SUM(CASE WHEN mobile_phone IS NOT NULL AND mobile_phone != '' THEN 1 ELSE 0 END) as with_phone,
                    SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as with_email
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['total'] > 0) {
            $id_completeness = ($row['with_id'] / $row['total']) * 100;
            $phone_completeness = ($row['with_phone'] / $row['total']) * 100;
            $email_completeness = ($row['with_email'] / $row['total']) * 100;
            
            $completeness += ($id_completeness * 0.3) + ($phone_completeness * 0.2) + ($email_completeness * 0.2);
        }
        
        return min(100, round($completeness, 1));
    }
    
    /**
     * Helper: Get education level name
     */
    private function getEducationLevelName($level) {
        $levels = [
            '1' => 'Grade 1', '2' => 'Grade 2', '3' => 'Grade 3',
            '4' => 'Grade 4', '5' => 'Grade 5', '6' => 'Grade 6',
            '7' => 'Grade 7', '8' => 'Grade 8', '9' => 'Grade 9',
            '10' => 'Grade 10', 'ol' => 'O/L', 'al' => 'A/L',
            'diploma' => 'Diploma', 'degree' => 'Degree',
            'masters' => "Master's", 'mphil' => 'MPhil', 'phd' => 'PhD',
            'No education recorded' => 'No Education',
            'No education' => 'No Education'
        ];
        
        return $levels[$level] ?? ucfirst($level);
    }
    
    /**
     * Helper: Get employment type name
     */
    private function getEmploymentTypeName($type) {
        $types = [
            'government' => 'Government',
            'private' => 'Private Sector',
            'self' => 'Self-employed',
            'labor' => 'Labor',
            'unemployed' => 'Unemployed',
            'student' => 'Student',
            'retired' => 'Retired',
            'Not employed' => 'Not Employed',
            'Unemployed' => 'Unemployed'
        ];
        
        return $types[$type] ?? ucfirst($type);
    }
    
    /**
     * Helper: Get health condition type name
     */
    private function getHealthConditionTypeName($type) {
        $types = [
            'disability' => 'Disability',
            'chronic_disease' => 'Chronic Disease',
            'mental_health' => 'Mental Health',
            'other' => 'Other Conditions',
            'No conditions' => 'No Conditions',
            'Healthy' => 'Healthy'
        ];
        
        return $types[$type] ?? ucfirst($type);
    }
    
    /**
     * Helper: Get time ago string
     */
    private function getTimeAgo($datetime) {
        $time = strtotime($datetime);
        $time_difference = time() - $time;
        
        if ($time_difference < 1) {
            return 'just now';
        }
        
        $condition = [
            12 * 30 * 24 * 60 * 60 => 'year',
            30 * 24 * 60 * 60 => 'month',
            24 * 60 * 60 => 'day',
            60 * 60 => 'hour',
            60 => 'minute',
            1 => 'second'
        ];
        
        foreach ($condition as $secs => $str) {
            $d = $time_difference / $secs;
            if ($d >= 1) {
                $t = round($d);
                return $t . ' ' . $str . ($t > 1 ? 's' : '') . ' ago';
            }
        }
    }
    
    /**
     * Helper: Convert hex to rgba
     */
    private function hexToRgba($hex, $alpha) {
        list($r, $g, $b) = sscanf($hex, "#%02x%02x%02x");
        return "rgba($r, $g, $b, $alpha)";
    }
}