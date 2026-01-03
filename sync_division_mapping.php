<?php
/**
 * TEMPORARY: One-time script to sync Division-GN relationships
 * DELETE THIS FILE AFTER RUNNING!
 */

// Temporary authentication bypass - REMOVE AFTER USE
$TEMP_ACCESS_KEY = 'sync_2024_delete_after'; // Change this to something unique
if (!isset($_GET['key']) || $_GET['key'] !== $TEMP_ACCESS_KEY) {
    die("Access denied. Provide correct access key in URL: ?key=YOUR_KEY");
}

require_once 'config.php';

// Output as HTML for browser viewing
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Division-GN Mapping Sync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 20px; }
        .log-output { background: #000; color: #0f0; padding: 20px; border-radius: 5px; font-family: monospace; max-height: 600px; overflow-y: auto; }
        .success { color: #0f0; }
        .warning { color: #ff0; }
        .error { color: #f00; }
        .info { color: #0ff; }
    </style>
</head>
<body>
<div class="container">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h3 class="mb-0">Division-GN Mapping Synchronization</h3>
        </div>
        <div class="card-body">
            <div class="log-output">
<?php

echo "<span class='info'>========================================\n";
echo "Division-GN Mapping Synchronization\n";
echo "========================================</span>\n\n";

try {
    // Get both database connections
    $fpmsConn = getMainConnection();
    $refConn = getRefConnection();
    
    echo "<span class='info'>✓ Database connections established</span>\n\n";
    
    // Step 1: Get all divisions from fpms.users
    echo "<span class='info'>Step 1: Fetching divisions from FPMS database...</span>\n";
    $divQuery = "SELECT user_id, office_code, office_name FROM users WHERE user_type = 'division' ORDER BY office_name";
    $divResult = $fpmsConn->query($divQuery);
    
    if (!$divResult) {
        throw new Exception("Failed to fetch divisions: " . $fpmsConn->error);
    }
    
    $divisions = [];
    while ($row = $divResult->fetch_assoc()) {
        $divisions[$row['office_name']] = $row['office_code'];
        echo "  <span class='success'>✓</span> Found: {$row['office_name']} (Code: {$row['office_code']})\n";
    }
    echo "Total divisions: <span class='warning'>" . count($divisions) . "</span>\n\n";
    
    // Step 2: Get all GN offices from fpms.users
    echo "<span class='info'>Step 2: Fetching GN offices from FPMS database...</span>\n";
    $gnQuery = "SELECT user_id, office_code, office_name FROM users WHERE user_type = 'gn' ORDER BY office_name";
    $gnResult = $fpmsConn->query($gnQuery);
    
    if (!$gnResult) {
        throw new Exception("Failed to fetch GN offices: " . $fpmsConn->error);
    }
    
    $gnOffices = [];
    while ($row = $gnResult->fetch_assoc()) {
        $gnOffices[$row['office_code']] = [
            'user_id' => $row['user_id'],
            'office_name' => $row['office_name']
        ];
    }
    echo "Total GN offices: <span class='warning'>" . count($gnOffices) . "</span>\n\n";
    
    // Step 3: Get mapping from mobile_service.fix_work_station
    echo "<span class='info'>Step 3: Fetching mapping from reference database...</span>\n";
    $mappingQuery = "SELECT DISTINCT Division_Name, GN_ID, GN FROM fix_work_station WHERE GN_ID IS NOT NULL AND Division_Name IS NOT NULL ORDER BY Division_Name, GN";
    $mappingResult = $refConn->query($mappingQuery);
    
    if (!$mappingResult) {
        throw new Exception("Failed to fetch mapping: " . $refConn->error);
    }
    
    $mappings = [];
    $unmatchedDivisions = [];
    $rowCount = 0;
    
    while ($row = $mappingResult->fetch_assoc()) {
        $rowCount++;
        $divisionName = trim($row['Division_Name']);
        $gnId = trim($row['GN_ID']);
        $gnName = trim($row['GN']);
        
        // Try to match division name (case-insensitive)
        $divisionCode = null;
        foreach ($divisions as $divName => $divCode) {
            if (strcasecmp($divName, $divisionName) === 0) {
                $divisionCode = $divCode;
                break;
            }
        }
        
        if ($divisionCode) {
            if (!isset($mappings[$divisionCode])) {
                $mappings[$divisionCode] = [];
            }
            $mappings[$divisionCode][] = [
                'gn_id' => $gnId,
                'gn_name' => $gnName
            ];
        } else {
            $unmatchedDivisions[$divisionName] = true;
        }
    }
    
    echo "Processed <span class='warning'>$rowCount</span> mapping records\n";
    echo "Created mappings for <span class='success'>" . count($mappings) . "</span> divisions\n";
    
    if (!empty($unmatchedDivisions)) {
        echo "<span class='warning'>⚠ " . count($unmatchedDivisions) . " divisions from reference DB not matched:</span>\n";
        foreach (array_keys($unmatchedDivisions) as $unmatchedDiv) {
            echo "  <span class='warning'>-</span> $unmatchedDiv\n";
        }
    }
    echo "\n";
    
    // Step 4: Update users table with parent_division_code
    echo "<span class='info'>Step 4: Updating GN offices with parent division codes...</span>\n";
    $updateCount = 0;
    $notFoundCount = 0;
    $notFoundGNs = [];
    
    $updateStmt = $fpmsConn->prepare("UPDATE users SET parent_division_code = ? WHERE office_code = ? AND user_type = 'gn'");
    
    if (!$updateStmt) {
        throw new Exception("Failed to prepare update statement: " . $fpmsConn->error);
    }
    
    foreach ($mappings as $divisionCode => $gnList) {
        echo "\n<span class='info'>Processing division: $divisionCode</span>\n";
        foreach ($gnList as $gn) {
            $gnId = $gn['gn_id'];
            
            // Check if this GN exists in fpms.users
            if (isset($gnOffices[$gnId])) {
                $updateStmt->bind_param("ss", $divisionCode, $gnId);
                if ($updateStmt->execute()) {
                    if ($updateStmt->affected_rows > 0) {
                        $updateCount++;
                        echo "  <span class='success'>✓</span> {$gn['gn_name']} ({$gnId})\n";
                    }
                } else {
                    echo "  <span class='error'>✗ Failed to update {$gnId}: " . $updateStmt->error . "</span>\n";
                }
            } else {
                $notFoundCount++;
                $notFoundGNs[] = "{$gn['gn_name']} ({$gnId})";
            }
        }
    }
    
    $updateStmt->close();
    
    echo "\n<span class='info'>========================================\n";
    echo "Update Summary\n";
    echo "========================================</span>\n";
    echo "<span class='success'>✓ Successfully updated: $updateCount GN offices</span>\n";
    echo "<span class='warning'>⚠ Not found in FPMS: $notFoundCount GN offices</span>\n";
    
    if (!empty($notFoundGNs) && count($notFoundGNs) <= 20) {
        echo "\n<span class='warning'>GN offices in reference DB but not in FPMS (first 20):</span>\n";
        foreach (array_slice($notFoundGNs, 0, 20) as $notFound) {
            echo "  - $notFound\n";
        }
    }
    
    // Step 5: Verification
    echo "\n<span class='info'>Step 5: Verification...</span>\n";
    $verifyQuery = "
        SELECT 
            COUNT(*) as total_gn,
            SUM(CASE WHEN parent_division_code IS NOT NULL THEN 1 ELSE 0 END) as mapped_gn,
            SUM(CASE WHEN parent_division_code IS NULL THEN 1 ELSE 0 END) as unmapped_gn
        FROM users 
        WHERE user_type = 'gn'
    ";
    $verifyResult = $fpmsConn->query($verifyQuery);
    
    if (!$verifyResult) {
        throw new Exception("Verification query failed: " . $fpmsConn->error);
    }
    
    $stats = $verifyResult->fetch_assoc();
    
    echo "  Total GN offices: <span class='warning'>{$stats['total_gn']}</span>\n";
    echo "  <span class='success'>✓ Mapped: {$stats['mapped_gn']}</span>\n";
    echo "  <span class='warning'>⚠ Unmapped: {$stats['unmapped_gn']}</span>\n";
    
    if ($stats['unmapped_gn'] > 0) {
        echo "\n<span class='warning'>Unmapped GN offices (first 10):</span>\n";
        $unmappedQuery = "SELECT office_code, office_name FROM users WHERE user_type = 'gn' AND parent_division_code IS NULL LIMIT 10";
        $unmappedResult = $fpmsConn->query($unmappedQuery);
        while ($row = $unmappedResult->fetch_assoc()) {
            echo "  <span class='warning'>-</span> {$row['office_name']} ({$row['office_code']})\n";
        }
    }
    
    echo "\n<span class='success'>========================================\n";
    echo "✓ Synchronization Complete!\n";
    echo "========================================</span>\n";
    echo "<span class='info'>You can now view the updated divisions.php page.</span>\n";
    
} catch (Exception $e) {
    echo "\n<span class='error'>========================================\n";
    echo "✗ ERROR OCCURRED\n";
    echo "========================================</span>\n";
    echo "<span class='error'>" . htmlspecialchars($e->getMessage()) . "</span>\n";
    error_log("Division mapping sync error: " . $e->getMessage());
}

// Close connections
if (isset($fpmsConn)) $fpmsConn->close();
if (isset($refConn)) $refConn->close();

?>
            </div>
        </div>
        <div class="card-footer">
            <div class="alert alert-warning mb-0">
                <strong>⚠ Security Notice:</strong> Delete this file immediately after running!
            </div>
        </div>
    </div>
</div>
</body>
</html>