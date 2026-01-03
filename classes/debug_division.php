<?php
// debug_division.php
require_once '../config.php';
session_start();   

// Get database connection
$db = getMainConnection();
$division_code = $_SESSION['office_code'] ?? 'D001'; // Replace with your actual division code

echo "<h2>Debug Division: $division_code</h2>";

// 1. Check what GN divisions exist for this division
echo "<h3>1. GN Divisions under division $division_code</h3>";
$sql = "SELECT user_id, office_code, office_name, user_type FROM users 
        WHERE user_type = 'gn' AND office_code LIKE ?";
$like_code = $division_code . '%';
$stmt = $db->prepare($sql);
$stmt->bind_param("s", $like_code);
$stmt->execute();
$result = $stmt->get_result();

echo "Found GN divisions:<br>";
while ($row = $result->fetch_assoc()) {
    echo "Code: {$row['office_code']}, Name: {$row['office_name']}, Type: {$row['user_type']}<br>";
}

// 2. Check families in the first GN division
echo "<h3>2. Families in this division</h3>";
$gn_sql = "SELECT office_code FROM users WHERE user_type = 'gn' AND office_code LIKE ? LIMIT 1";
$stmt = $db->prepare($gn_sql);
$stmt->bind_param("s", $like_code);
$stmt->execute();
$gn_result = $stmt->get_result();
$gn_row = $gn_result->fetch_assoc();

if ($gn_row) {
    $gn_code = $gn_row['office_code'];
    echo "Checking families for GN: $gn_code<br>";
    
    $family_sql = "SELECT COUNT(*) as family_count FROM families WHERE gn_id = ?";
    $stmt = $db->prepare($family_sql);
    $stmt->bind_param("s", $gn_code);
    $stmt->execute();
    $family_result = $stmt->get_result();
    $family_row = $family_result->fetch_assoc();
    
    echo "Families in this GN: " . ($family_row['family_count'] ?? 0) . "<br>";
    
    // Check all families in division
    $all_families_sql = "SELECT COUNT(*) as total_families 
                         FROM families f 
                         INNER JOIN users u ON f.gn_id = u.office_code 
                         WHERE u.user_type = 'gn' AND u.office_code LIKE ?";
    $stmt = $db->prepare($all_families_sql);
    $stmt->bind_param("s", $like_code);
    $stmt->execute();
    $all_result = $stmt->get_result();
    $all_row = $all_result->fetch_assoc();
    
    echo "Total families in division: " . ($all_row['total_families'] ?? 0) . "<br>";
}

// 3. Check session data
echo "<h3>3. Session Data</h3>";
echo "Division Code: " . ($_SESSION['office_code'] ?? 'NOT SET') . "<br>";
echo "User Type: " . ($_SESSION['user_type'] ?? 'NOT SET') . "<br>";
echo "Office Name: " . ($_SESSION['office_name'] ?? 'NOT SET') . "<br>";

// 4. Check sample data
echo "<h3>4. Sample Data from Database</h3>";
$sample_sql = "SELECT f.family_id, f.gn_id, u.office_name, f.total_members 
               FROM families f 
               INNER JOIN users u ON f.gn_id = u.office_code 
               WHERE u.user_type = 'gn' 
               LIMIT 5";
$result = $db->query($sample_sql);
echo "Sample families:<br>";
while ($row = $result->fetch_assoc()) {
    echo "Family: {$row['family_id']}, GN: {$row['gn_id']} ({$row['office_name']}), Members: {$row['total_members']}<br>";
}
?>