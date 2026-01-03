<?php
// classes/ReportGenerator.php

class ReportGenerator {
    private $db;
    private $gn_id;
    
    public function __construct($db_connection) {
        $this->db = $db_connection;
    }
    
    /**
     * Set GN ID for report generation
     */
    public function setGnId($gn_id) {
        $this->gn_id = $gn_id;
    }
    
    /**
     * Get overview statistics for GN division
     */
    public function getOverviewStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        // Total families
        $sql = "SELECT COUNT(*) as total FROM families WHERE gn_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_families'] = [
            'count' => $row['total'],
            'percentage' => 100,
            'trend' => $this->getGrowthRate('families', $gn_id)
        ];
        
        // Total population
        $sql = "SELECT COUNT(*) as total FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_population'] = [
            'count' => $row['total'],
            'percentage' => 100,
            'trend' => $this->getGrowthRate('population', $gn_id)
        ];
        
        // Average family size
        $sql = "SELECT AVG(total_members) as avg_size FROM families WHERE gn_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['avg_family_size'] = [
            'count' => round($row['avg_size'], 1),
            'percentage' => null,
            'trend' => $this->getGrowthRate('family_size', $gn_id)
        ];
        
        // Gender distribution
        $sql = "SELECT 
                    SUM(CASE WHEN c.gender = 'male' THEN 1 ELSE 0 END) as male,
                    SUM(CASE WHEN c.gender = 'female' THEN 1 ELSE 0 END) as female,
                    SUM(CASE WHEN c.gender = 'other' THEN 1 ELSE 0 END) as other
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $total = $row['male'] + $row['female'] + $row['other'];
        
        $stats['male_population'] = [
            'count' => $row['male'],
            'percentage' => $total > 0 ? round(($row['male'] / $total) * 100, 1) : 0,
            'trend' => null
        ];
        
        $stats['female_population'] = [
            'count' => $row['female'],
            'percentage' => $total > 0 ? round(($row['female'] / $total) * 100, 1) : 0,
            'trend' => null
        ];
        
        // This month registrations
        $sql = "SELECT COUNT(*) as total FROM families 
                WHERE gn_id = ? AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
                AND YEAR(created_at) = YEAR(CURRENT_DATE())";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['this_month_registrations'] = [
            'count' => $row['total'],
            'percentage' => null,
            'trend' => null
        ];
        
        return $stats;
    }
    
    /**
     * Get population statistics
     */
    public function getPopulationStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        // Total population by gender
        $sql = "SELECT 
                    c.gender,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY c.gender";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $stats[$row['gender']] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as $gender => &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
        
        // Population by age groups
        $age_groups = $this->getAgeGroupStats($gn_id);
        $stats = array_merge($stats, $age_groups);
        
        return $stats;
    }
    
    /**
     * Get age group statistics
     */
    public function getAgeGroupStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        $sql = "SELECT 
                    CASE
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 1 THEN 'Infant (0)'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 1 AND 5 THEN 'Toddler (1-5)'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 6 AND 12 THEN 'Child (6-12)'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 13 AND 19 THEN 'Teenager (13-19)'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 20 AND 35 THEN 'Young Adult (20-35)'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 60 THEN 'Adult (36-60)'
                        ELSE 'Senior (60+)'
                    END as age_group,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY age_group
                ORDER BY 
                    CASE age_group
                        WHEN 'Infant (0)' THEN 1
                        WHEN 'Toddler (1-5)' THEN 2
                        WHEN 'Child (6-12)' THEN 3
                        WHEN 'Teenager (13-19)' THEN 4
                        WHEN 'Young Adult (20-35)' THEN 5
                        WHEN 'Adult (36-60)' THEN 6
                        ELSE 7
                    END";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $stats[$row['age_group']] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
        
        return $stats;
    }
    
    /**
     * Get family statistics
     */
    public function getFamilyStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        // Family size distribution
        $sql = "SELECT 
                    total_members as size,
                    COUNT(*) as count
                FROM families 
                WHERE gn_id = ?
                GROUP BY total_members
                ORDER BY total_members";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $stats["Family Size {$row['size']}"] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
        
        // Marital status distribution
        $sql = "SELECT 
                    COALESCE(c.marital_status, 'Not specified') as status,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY c.marital_status";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total_status = 0;
        while ($row = $result->fetch_assoc()) {
            $stats["Marital: " . ucfirst($row['status'])] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total_status += $row['count'];
        }
        
        // Calculate percentages for marital status
        foreach ($stats as $key => &$data) {
            if (strpos($key, 'Marital: ') === 0) {
                $data['percentage'] = $total_status > 0 ? round(($data['count'] / $total_status) * 100, 1) : 0;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get demographic statistics
     */
    public function getDemographicStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        // Religion distribution
        $sql = "SELECT 
                    COALESCE(c.religion, 'Not specified') as religion,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY c.religion
                ORDER BY count DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $stats["Religion: " . $row['religion']] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
        
        // Ethnicity distribution
        $sql = "SELECT 
                    COALESCE(c.ethnicity, 'Not specified') as ethnicity,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY c.ethnicity
                ORDER BY count DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total_ethnicity = 0;
        while ($row = $result->fetch_assoc()) {
            $stats["Ethnicity: " . $row['ethnicity']] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total_ethnicity += $row['count'];
        }
        
        // Calculate percentages for ethnicity
        foreach ($stats as $key => &$data) {
            if (strpos($key, 'Ethnicity: ') === 0) {
                $data['percentage'] = $total_ethnicity > 0 ? round(($data['count'] / $total_ethnicity) * 100, 1) : 0;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get education statistics
     */
    public function getEducationStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        // Education level distribution
        $sql = "SELECT 
                    COALESCE(e.education_level, 'No education recorded') as level,
                    COUNT(DISTINCT e.citizen_id) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                LEFT JOIN education e ON c.citizen_id = e.citizen_id
                WHERE f.gn_id = ?
                GROUP BY e.education_level
                ORDER BY 
                    CASE level
                        WHEN 'phd' THEN 1 WHEN 'mphil' THEN 2 WHEN 'degree' THEN 3 
                        WHEN 'diploma' THEN 4 WHEN 'al' THEN 5 WHEN 'ol' THEN 6
                        WHEN '10' THEN 7 WHEN '9' THEN 8 WHEN '8' THEN 9
                        WHEN '7' THEN 10 WHEN '6' THEN 11 WHEN '5' THEN 12
                        WHEN '4' THEN 13 WHEN '3' THEN 14 WHEN '2' THEN 15
                        WHEN '1' THEN 16 ELSE 17
                    END";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $level_name = $this->getEducationLevelName($row['level']);
            $stats[$level_name] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
        
        // Current students
        $sql = "SELECT COUNT(*) as count FROM education e 
                INNER JOIN citizens c ON e.citizen_id = c.citizen_id
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ? AND e.is_current = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $stats['Current Students'] = [
            'count' => $row['count'],
            'percentage' => $total > 0 ? round(($row['count'] / $total) * 100, 1) : 0,
            'trend' => null
        ];
        
        return $stats;
    }
    
    /**
     * Get employment statistics
     */
    public function getEmploymentStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        // Employment type distribution
        $sql = "SELECT 
                    COALESCE(e.employment_type, 'Not employed') as type,
                    COUNT(DISTINCT e.citizen_id) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                LEFT JOIN employment e ON c.citizen_id = e.citizen_id AND e.is_current_job = 1
                WHERE f.gn_id = ?
                GROUP BY e.employment_type";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $type_name = $this->getEmploymentTypeName($row['type']);
            $stats[$type_name] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
        
        // Average income
        $sql = "SELECT AVG(e.monthly_income) as avg_income 
                FROM employment e 
                INNER JOIN citizens c ON e.citizen_id = c.citizen_id
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ? AND e.is_current_job = 1 AND e.monthly_income > 0";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $stats['Average Monthly Income'] = [
            'count' => round($row['avg_income'] ?? 0, 2),
            'percentage' => null,
            'trend' => $this->getGrowthRate('income', $gn_id)
        ];
        
        return $stats;
    }
    
    /**
     * Get health statistics
     */
    public function getHealthStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        // Health condition types
        $sql = "SELECT 
                    COALESCE(h.condition_type, 'No conditions') as type,
                    COUNT(DISTINCT h.citizen_id) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                LEFT JOIN health_conditions h ON c.citizen_id = h.citizen_id
                WHERE f.gn_id = ?
                GROUP BY h.condition_type";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $type_name = $this->getHealthConditionTypeName($row['type']);
            $stats[$type_name] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
        
        // Permanent conditions
        $sql = "SELECT COUNT(*) as count FROM health_conditions h 
                INNER JOIN citizens c ON h.citizen_id = c.citizen_id
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ? AND h.is_permanent = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $stats['Permanent Conditions'] = [
            'count' => $row['count'],
            'percentage' => $total > 0 ? round(($row['count'] / $total) * 100, 1) : 0,
            'trend' => null
        ];
        
        return $stats;
    }
    
    /**
     * Get gender statistics
     */
    public function getGenderStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        // Gender ratio
        $sql = "SELECT 
                    gender,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY gender";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $gender_name = ucfirst($row['gender']);
            $stats[$gender_name] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total += $row['count'];
        }
        
        // Calculate percentages
        foreach ($stats as &$data) {
            $data['percentage'] = $total > 0 ? round(($data['count'] / $total) * 100, 1) : 0;
        }
        
        // Gender ratio among family heads
        $sql = "SELECT 
                    c.gender,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ? AND c.relation_to_head = 'Self'
                GROUP BY c.gender";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total_heads = 0;
        while ($row = $result->fetch_assoc()) {
            $gender_name = ucfirst($row['gender']) . " Heads";
            $stats[$gender_name] = [
                'count' => $row['count'],
                'percentage' => 0,
                'trend' => null
            ];
            $total_heads += $row['count'];
        }
        
        // Calculate percentages for heads
        foreach ($stats as $key => &$data) {
            if (strpos($key, 'Heads') !== false) {
                $data['percentage'] = $total_heads > 0 ? round(($data['count'] / $total_heads) * 100, 1) : 0;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get monthly report
     */
    public function getMonthlyReport($gn_id = null, $year = null, $month = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        $year = $year ?: date('Y');
        $month = $month ?: date('n');
        
        $stats = [];
        
        // New families this month
        $sql = "SELECT COUNT(*) as count FROM families 
                WHERE gn_id = ? AND YEAR(created_at) = ? AND MONTH(created_at) = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("sii", $gn_id, $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['New Families'] = [
            'count' => $row['count'],
            'percentage' => null,
            'trend' => $this->getMonthlyTrend('families', $gn_id, $year, $month)
        ];
        
        // New citizens this month
        $sql = "SELECT COUNT(*) as count FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ? AND YEAR(c.created_at) = ? AND MONTH(c.created_at) = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("sii", $gn_id, $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['New Citizens'] = [
            'count' => $row['count'],
            'percentage' => null,
            'trend' => $this->getMonthlyTrend('citizens', $gn_id, $year, $month)
        ];
        
        // Updated records this month
        $sql = "SELECT COUNT(*) as count FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ? AND YEAR(c.updated_at) = ? AND MONTH(c.updated_at) = ?
                AND DATE(c.updated_at) > DATE(c.created_at)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("sii", $gn_id, $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['Updated Records'] = [
            'count' => $row['count'],
            'percentage' => null,
            'trend' => null
        ];
        
        return $stats;
    }
    
    /**
     * Get recent activities
     */
    public function getRecentActivities($gn_id = null, $limit = 10) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $activities = [];
        
        // Recent family registrations
        $sql = "SELECT 
                    f.family_id,
                    f.created_at,
                    c.full_name as head_name,
                    'New Family Registration' as title,
                    'Family registered in system' as description
                FROM families f 
                LEFT JOIN citizens c ON f.family_id = c.family_id AND c.relation_to_head = 'Self'
                WHERE f.gn_id = ?
                ORDER BY f.created_at DESC 
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("si", $gn_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $time_ago = $this->getTimeAgo($row['created_at']);
            $activities[] = [
                'title' => $row['title'],
                'description' => $row['description'],
                'time_ago' => $time_ago,
                'details' => "Family ID: " . $row['family_id'] . ($row['head_name'] ? " | Head: " . $row['head_name'] : "")
            ];
        }
        
        // Recent citizen additions
        $sql = "SELECT 
                    c.full_name,
                    c.created_at,
                    f.family_id,
                    'New Citizen Added' as title,
                    'Citizen added to family' as description
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                ORDER BY c.created_at DESC 
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("si", $gn_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $time_ago = $this->getTimeAgo($row['created_at']);
            $activities[] = [
                'title' => $row['title'],
                'description' => $row['description'],
                'time_ago' => $time_ago,
                'details' => "Name: " . $row['full_name'] . " | Family ID: " . $row['family_id']
            ];
        }
        
        // Sort by date and limit
        usort($activities, function($a, $b) {
            return strtotime($b['time_ago']) - strtotime($a['time_ago']);
        });
        
        return array_slice($activities, 0, $limit);
    }
    
    /**
     * Get quick stats for dashboard
     */
    public function getQuickStats($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $stats = [];
        
        // Total families
        $sql = "SELECT COUNT(*) as total FROM families WHERE gn_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_families'] = $row['total'];
        
        // Total population
        $sql = "SELECT COUNT(*) as total FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_population'] = $row['total'];
        
        // Average family size
        $sql = "SELECT AVG(total_members) as avg_size FROM families WHERE gn_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['avg_family_size'] = round($row['avg_size'], 1);
        
        // This month registrations
        $sql = "SELECT COUNT(*) as total FROM families 
                WHERE gn_id = ? AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
                AND YEAR(created_at) = YEAR(CURRENT_DATE())";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['this_month_registrations'] = $row['total'];
        
        return $stats;
    }
    
    /**
     * Get chart data for population pyramid
     */
    public function getPopulationPyramid($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $data = [
            'labels' => [],
            'datasets' => []
        ];
        
        // Get male population by age groups
        $sql = "SELECT 
                    CASE
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 0 AND 4 THEN '0-4'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 5 AND 9 THEN '5-9'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 10 AND 14 THEN '10-14'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 15 AND 19 THEN '15-19'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 20 AND 24 THEN '20-24'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 25 AND 29 THEN '25-29'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 30 AND 34 THEN '30-34'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 35 AND 39 THEN '35-39'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 40 AND 44 THEN '40-44'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 45 AND 49 THEN '45-49'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 50 AND 54 THEN '50-54'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 55 AND 59 THEN '55-59'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 60 THEN '60+'
                    END as age_group,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ? AND c.gender = 'male'
                GROUP BY age_group
                ORDER BY 
                    CASE age_group
                        WHEN '0-4' THEN 1 WHEN '5-9' THEN 2 WHEN '10-14' THEN 3
                        WHEN '15-19' THEN 4 WHEN '20-24' THEN 5 WHEN '25-29' THEN 6
                        WHEN '30-34' THEN 7 WHEN '35-39' THEN 8 WHEN '40-44' THEN 9
                        WHEN '45-49' THEN 10 WHEN '50-54' THEN 11 WHEN '55-59' THEN 12
                        ELSE 13
                    END";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $male_data = [];
        $labels = [];
        while ($row = $result->fetch_assoc()) {
            $labels[] = $row['age_group'];
            $male_data[] = $row['count'];
        }
        
        // Get female population by age groups
        $sql = "SELECT 
                    CASE
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 0 AND 4 THEN '0-4'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 5 AND 9 THEN '5-9'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 10 AND 14 THEN '10-14'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 15 AND 19 THEN '15-19'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 20 AND 24 THEN '20-24'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 25 AND 29 THEN '25-29'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 30 AND 34 THEN '30-34'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 35 AND 39 THEN '35-39'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 40 AND 44 THEN '40-44'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 45 AND 49 THEN '45-49'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 50 AND 54 THEN '50-54'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 55 AND 59 THEN '55-59'
                        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 60 THEN '60+'
                    END as age_group,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ? AND c.gender = 'female'
                GROUP BY age_group
                ORDER BY 
                    CASE age_group
                        WHEN '0-4' THEN 1 WHEN '5-9' THEN 2 WHEN '10-14' THEN 3
                        WHEN '15-19' THEN 4 WHEN '20-24' THEN 5 WHEN '25-29' THEN 6
                        WHEN '30-34' THEN 7 WHEN '35-39' THEN 8 WHEN '40-44' THEN 9
                        WHEN '45-49' THEN 10 WHEN '50-54' THEN 11 WHEN '55-59' THEN 12
                        ELSE 13
                    END";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $female_data = [];
        while ($row = $result->fetch_assoc()) {
            $female_data[] = $row['count'];
        }
        
        // Make male data negative for pyramid effect
        $male_data_negative = array_map(function($value) {
            return -$value;
        }, $male_data);
        
        $data = [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Male',
                    'data' => $male_data_negative,
                    'backgroundColor' => '#36A2EB',
                    'borderColor' => '#1E88E5',
                    'borderWidth' => 1
                ],
                [
                    'label' => 'Female',
                    'data' => $female_data,
                    'backgroundColor' => '#FF6384',
                    'borderColor' => '#D81B60',
                    'borderWidth' => 1
                ]
            ]
        ];
        
        return $data;
    }
    
    /**
     * Get chart data for family size distribution
     */
    public function getFamilySizeDistribution($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $sql = "SELECT 
                    total_members as size,
                    COUNT(*) as count
                FROM families 
                WHERE gn_id = ?
                GROUP BY total_members
                ORDER BY total_members";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => 'Number of Families',
                'data' => [],
                'backgroundColor' => '#4BC0C0',
                'borderColor' => '#00ACC1',
                'borderWidth' => 1
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['size'] . ' members';
            $data['datasets'][0]['data'][] = $row['count'];
        }
        
        return $data;
    }
    
    /**
     * Get chart data for religion distribution
     */
    public function getReligionDistribution($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $sql = "SELECT 
                    COALESCE(religion, 'Not specified') as religion,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY religion
                ORDER BY count DESC
                LIMIT 10";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => 'Population',
                'data' => [],
                'backgroundColor' => [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                    '#9966FF', '#FF9F40', '#8AC926', '#1982C4',
                    '#6A0572', '#3A7D44'
                ]
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['religion'];
            $data['datasets'][0]['data'][] = $row['count'];
        }
        
        return $data;
    }
    
    /**
     * Get chart data for education level distribution
     */
    public function getEducationLevelDistribution($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $sql = "SELECT 
                    COALESCE(e.education_level, 'No education') as level,
                    COUNT(DISTINCT e.citizen_id) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                LEFT JOIN education e ON c.citizen_id = e.citizen_id
                WHERE f.gn_id = ?
                GROUP BY e.education_level
                HAVING level IS NOT NULL
                ORDER BY 
                    CASE level
                        WHEN 'phd' THEN 1 WHEN 'mphil' THEN 2 WHEN 'degree' THEN 3 
                        WHEN 'diploma' THEN 4 WHEN 'al' THEN 5 WHEN 'ol' THEN 6
                        WHEN '10' THEN 7 WHEN '9' THEN 8 WHEN '8' THEN 9
                        WHEN '7' THEN 10 WHEN '6' THEN 11 WHEN '5' THEN 12
                        WHEN '4' THEN 13 WHEN '3' THEN 14 WHEN '2' THEN 15
                        WHEN '1' THEN 16 ELSE 17
                    END";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => 'Number of People',
                'data' => [],
                'backgroundColor' => '#FF9F40',
                'borderColor' => '#F57C00',
                'borderWidth' => 1
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $this->getEducationLevelName($row['level']);
            $data['datasets'][0]['data'][] = $row['count'];
        }
        
        return $data;
    }
    
    /**
     * Get chart data for employment type distribution
     */
    public function getEmploymentTypeDistribution($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $sql = "SELECT 
                    COALESCE(e.employment_type, 'Unemployed') as type,
                    COUNT(DISTINCT e.citizen_id) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                LEFT JOIN employment e ON c.citizen_id = e.citizen_id AND e.is_current_job = 1
                WHERE f.gn_id = ?
                GROUP BY e.employment_type";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => 'Number of People',
                'data' => [],
                'backgroundColor' => [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                    '#9966FF', '#FF9F40', '#8AC926'
                ]
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $this->getEmploymentTypeName($row['type']);
            $data['datasets'][0]['data'][] = $row['count'];
        }
        
        return $data;
    }
    
    /**
     * Get chart data for health condition distribution
     */
    public function getHealthConditionDistribution($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $sql = "SELECT 
                    COALESCE(h.condition_type, 'Healthy') as type,
                    COUNT(DISTINCT h.citizen_id) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                LEFT JOIN health_conditions h ON c.citizen_id = h.citizen_id
                WHERE f.gn_id = ?
                GROUP BY h.condition_type";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => 'Number of People',
                'data' => [],
                'backgroundColor' => [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0'
                ]
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $this->getHealthConditionTypeName($row['type']);
            $data['datasets'][0]['data'][] = $row['count'];
        }
        
        return $data;
    }
    
    /**
     * Get chart data for age group distribution
     */
    public function getAgeGroupDistribution($gn_id = null) {
        return $this->getPopulationPyramid($gn_id);
    }
    
    /**
     * Get chart data for gender ratio
     */
    public function getGenderRatioChart($gn_id = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        
        $sql = "SELECT 
                    gender,
                    COUNT(*) as count
                FROM citizens c 
                INNER JOIN families f ON c.family_id = f.family_id 
                WHERE f.gn_id = ?
                GROUP BY gender";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'data' => [],
                'backgroundColor' => ['#36A2EB', '#FF6384', '#FFCE56']
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = ucfirst($row['gender']);
            $data['datasets'][0]['data'][] = $row['count'];
        }
        
        return $data;
    }
    
    /**
     * Get monthly registration trend
     */
    public function getMonthlyRegistrationTrend($gn_id = null, $year = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        $year = $year ?: date('Y');
        
        $sql = "SELECT 
                    MONTH(created_at) as month,
                    COUNT(*) as count
                FROM families 
                WHERE gn_id = ? AND YEAR(created_at) = ?
                GROUP BY MONTH(created_at)
                ORDER BY month";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("si", $gn_id, $year);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [
            'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'datasets' => [[
                'label' => 'New Families',
                'data' => array_fill(0, 12, 0),
                'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                'borderColor' => '#36A2EB',
                'borderWidth' => 2,
                'fill' => true
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['datasets'][0]['data'][$row['month'] - 1] = $row['count'];
        }
        
        return $data;
    }
    
    /**
     * Get monthly comparison data
     */
    public function getMonthlyComparison($gn_id = null, $year = null, $month = null) {
        $gn_id = $gn_id ?: $this->gn_id;
        $year = $year ?: date('Y');
        $month = $month ?: date('n');
        
        // Get current month data
        $current_data = $this->getMonthlyReport($gn_id, $year, $month);
        
        // Get previous month data
        $prev_month = $month - 1;
        $prev_year = $year;
        if ($prev_month < 1) {
            $prev_month = 12;
            $prev_year = $year - 1;
        }
        $prev_data = $this->getMonthlyReport($gn_id, $prev_year, $prev_month);
        
        $data = [
            'labels' => ['Current Month', 'Previous Month'],
            'datasets' => []
        ];
        
        foreach ($current_data as $key => $current) {
            $prev = $prev_data[$key] ?? ['count' => 0];
            
            $data['datasets'][] = [
                'label' => $key,
                'data' => [$current['count'], $prev['count']],
                'backgroundColor' => ['#4BC0C0', '#FF6384'],
                'borderColor' => ['#00ACC1', '#D81B60'],
                'borderWidth' => 1
            ];
        }
        
        return $data;
    }
    
    /**
     * Helper function to get growth rate
     */
    private function getGrowthRate($type, $gn_id) {
        // This is a simplified version - you might want to implement more sophisticated trend analysis
        $current = $this->getCurrentCount($type, $gn_id);
        $previous = $this->getPreviousCount($type, $gn_id);
        
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        
        return round((($current - $previous) / $previous) * 100, 1);
    }
    
    /**
     * Helper function to get monthly trend
     */
    private function getMonthlyTrend($type, $gn_id, $year, $month) {
        $current = $this->getCountForMonth($type, $gn_id, $year, $month);
        
        // Get previous month
        $prev_month = $month - 1;
        $prev_year = $year;
        if ($prev_month < 1) {
            $prev_month = 12;
            $prev_year = $year - 1;
        }
        
        $previous = $this->getCountForMonth($type, $gn_id, $prev_year, $prev_month);
        
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        
        return round((($current - $previous) / $previous) * 100, 1);
    }
    
    /**
     * Helper function to get current count
     */
    private function getCurrentCount($type, $gn_id) {
        switch ($type) {
            case 'families':
                $sql = "SELECT COUNT(*) as count FROM families WHERE gn_id = ?";
                break;
            case 'population':
                $sql = "SELECT COUNT(*) as count FROM citizens c 
                        INNER JOIN families f ON c.family_id = f.family_id 
                        WHERE f.gn_id = ?";
                break;
            case 'family_size':
                $sql = "SELECT AVG(total_members) as count FROM families WHERE gn_id = ?";
                break;
            case 'income':
                $sql = "SELECT AVG(monthly_income) as count FROM employment e 
                        INNER JOIN citizens c ON e.citizen_id = c.citizen_id
                        INNER JOIN families f ON c.family_id = f.family_id 
                        WHERE f.gn_id = ? AND e.is_current_job = 1";
                break;
            default:
                return 0;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $gn_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Helper function to get previous count (e.g., last month)
     */
    private function getPreviousCount($type, $gn_id) {
        // For simplicity, using last month's data
        $last_month = date('n', strtotime('-1 month'));
        $year = date('Y', strtotime('-1 month'));
        
        return $this->getCountForMonth($type, $gn_id, $year, $last_month);
    }
    
    /**
     * Helper function to get count for specific month
     */
    private function getCountForMonth($type, $gn_id, $year, $month) {
        switch ($type) {
            case 'families':
                $sql = "SELECT COUNT(*) as count FROM families 
                        WHERE gn_id = ? AND YEAR(created_at) = ? AND MONTH(created_at) = ?";
                break;
            case 'citizens':
                $sql = "SELECT COUNT(*) as count FROM citizens c 
                        INNER JOIN families f ON c.family_id = f.family_id 
                        WHERE f.gn_id = ? AND YEAR(c.created_at) = ? AND MONTH(c.created_at) = ?";
                break;
            default:
                return 0;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("sii", $gn_id, $year, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Helper function to get education level name
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
     * Helper function to get employment type name
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
     * Helper function to get health condition type name
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
     * Helper function to get time ago string
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
     * Generate PDF report
     */
    public function generatePdfReport($gn_id, $report_type, $year = null, $month = null) {
        // This would require a PDF library like TCPDF or DomPDF
        // For now, return array data that can be used by PDF generator
        $data = [
            'report_type' => $report_type,
            'gn_id' => $gn_id,
            'year' => $year,
            'month' => $month,
            'data' => $this->getReportData($report_type, $gn_id, $year, $month),
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        return $data;
    }
    
    /**
     * Generate Excel report
     */
    public function generateExcelReport($gn_id, $report_type, $year = null, $month = null) {
        // This would require a library like PhpSpreadsheet
        // For now, return array data that can be used by Excel generator
        $data = [
            'report_type' => $report_type,
            'gn_id' => $gn_id,
            'year' => $year,
            'month' => $month,
            'data' => $this->getReportData($report_type, $gn_id, $year, $month),
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        return $data;
    }
    
    /**
     * Get report data by type
     */
    private function getReportData($type, $gn_id, $year = null, $month = null) {
        switch ($type) {
            case 'overview':
                return $this->getOverviewStats($gn_id);
            case 'population':
                return $this->getPopulationStats($gn_id);
            case 'family':
                return $this->getFamilyStats($gn_id);
            case 'demographic':
                return $this->getDemographicStats($gn_id);
            case 'education':
                return $this->getEducationStats($gn_id);
            case 'employment':
                return $this->getEmploymentStats($gn_id);
            case 'health':
                return $this->getHealthStats($gn_id);
            case 'age':
                return $this->getAgeGroupStats($gn_id);
            case 'gender':
                return $this->getGenderStats($gn_id);
            case 'monthly':
                return $this->getMonthlyReport($gn_id, $year, $month);
            default:
                return $this->getOverviewStats($gn_id);
        }
    }
}