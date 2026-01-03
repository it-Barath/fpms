<?php
// users/gn/citizens/download_transfer_slip.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config.php';
require_once '../../classes/Auth.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize Auth
$auth = new Auth();
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'gn') {
    header('Location: ../../login.php');
    exit();
}

// Get database connection
$db = getMainConnection();
if (!$db) {
    die("Database connection failed");
}

// Get reference database connection
$ref_db = getRefConnection();

// Get parameters
$transfer_id = $_GET['transfer_id'] ?? '';
$family_id = $_GET['family_id'] ?? '';

if (empty($transfer_id) || empty($family_id)) {
    die("Missing required parameters");
}

// Get user info
$user_id = $_SESSION['user_id'];
$office_name = $_SESSION['office_name'];
$from_gn_id = $_SESSION['office_code'];
if (strpos($from_gn_id, 'gn_') === 0) {
    $from_gn_id = substr($from_gn_id, 3);
}

// Get transfer details
$transfer_query = "SELECT th.*, f.family_head_nic, f.address,
                          (SELECT full_name FROM citizens 
                           WHERE identification_number = f.family_head_nic 
                           AND identification_type = 'nic' 
                           LIMIT 1) as head_name,
                          (SELECT COUNT(*) FROM citizens WHERE family_id = f.family_id) as actual_members
                   FROM transfer_history th
                   JOIN families f ON th.family_id = f.family_id
                   WHERE th.transfer_id = ? AND th.family_id = ?";
$transfer_stmt = $db->prepare($transfer_query);
$transfer_stmt->bind_param("ss", $transfer_id, $family_id);
$transfer_stmt->execute();
$transfer_result = $transfer_stmt->get_result();
$transfer_details = $transfer_result->fetch_assoc();

if (!$transfer_details) {
    die("Transfer not found");
}

// Get family members
$members_query = "SELECT * FROM citizens WHERE family_id = ? ORDER BY 
                  CASE WHEN relation_to_head = 'Self' THEN 1
                       WHEN relation_to_head = 'Spouse' THEN 2
                       ELSE 3 END";
$members_stmt = $db->prepare($members_query);
$members_stmt->bind_param("s", $family_id);
$members_stmt->execute();
$members_result = $members_stmt->get_result();
$family_members = [];
while ($member = $members_result->fetch_assoc()) {
    $family_members[] = $member;
}

// Get land details
$land_query = "SELECT * FROM land_details WHERE family_id = ?";
$land_stmt = $db->prepare($land_query);
$land_stmt->bind_param("s", $family_id);
$land_stmt->execute();
$land_result = $land_stmt->get_result();
$land_details = $land_result->fetch_all(MYSQLI_ASSOC);

// Get GN details
$from_gn_query = "SELECT GN, District_Name, Division_Name, Province_Name 
                  FROM mobile_service.fix_work_station 
                  WHERE GN_ID = ?";
$from_gn_stmt = $ref_db->prepare($from_gn_query);
$from_gn_stmt->bind_param("s", $from_gn_id);
$from_gn_stmt->execute();
$from_gn_result = $from_gn_stmt->get_result();
$from_gn = $from_gn_result->fetch_assoc();

$to_gn_query = "SELECT GN, District_Name, Division_Name, Province_Name 
                FROM mobile_service.fix_work_station 
                WHERE GN_ID = ?";
$to_gn_stmt = $ref_db->prepare($to_gn_query);
$to_gn_stmt->bind_param("s", $transfer_details['to_gn_id']);
$to_gn_stmt->execute();
$to_gn_result = $to_gn_stmt->get_result();
$to_gn = $to_gn_result->fetch_assoc();

// Include TCPDF library
require_once '../../tcpdf/tcpdf.php';

// Create new PDF document
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('FPMS - Ministry of Home Affairs');
$pdf->SetAuthor('Family Profile Management System');
$pdf->SetTitle('Transfer Slip - ' . $transfer_id);
$pdf->SetSubject('Family Transfer Documentation');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', '', 10);

// Logo and Header
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'REPUBLIC OF SRI LANKA', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'MINISTRY OF HOME AFFAIRS', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 10, 'Family Profile Management System', 0, 1, 'C');

// Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 15, 'FAMILY TRANSFER SLIP', 0, 1, 'C');
$pdf->Line(15, $pdf->GetY() - 5, 195, $pdf->GetY() - 5);

// Transfer Details Table
$pdf->SetFont('helvetica', '', 10);
$pdf->Ln(5);

// Create table with transfer details
$html = '
<style>
    table { width: 100%; border-collapse: collapse; }
    th { background-color: #f2f2f2; font-weight: bold; padding: 5px; border: 1px solid #666; }
    td { padding: 5px; border: 1px solid #666; }
    .label { font-weight: bold; width: 40%; }
</style>

<table>
    <tr>
        <th colspan="4" style="background-color: #d9edf7;">TRANSFER DETAILS</th>
    </tr>
    <tr>
        <td class="label">Transfer ID:</td>
        <td>' . htmlspecialchars($transfer_details['transfer_id']) . '</td>
        <td class="label">Transfer Date:</td>
        <td>' . date('d/m/Y H:i:s', strtotime($transfer_details['request_date'])) . '</td>
    </tr>
    <tr>
        <td class="label">Family ID:</td>
        <td>' . htmlspecialchars($transfer_details['family_id']) . '</td>
        <td class="label">Status:</td>
        <td><strong>' . strtoupper($transfer_details['current_status']) . '</strong></td>
    </tr>
    <tr>
        <td class="label">Transfer Reason:</td>
        <td colspan="3">' . htmlspecialchars($transfer_details['transfer_reason']) . '</td>
    </tr>
    ' . (!empty($transfer_details['transfer_notes']) ? '
    <tr>
        <td class="label">Notes:</td>
        <td colspan="3">' . htmlspecialchars($transfer_details['transfer_notes']) . '</td>
    </tr>' : '') . '
</table>

<br>

<table>
    <tr>
        <th colspan="2" style="background-color: #f2dede;">TRANSFERRING FROM</th>
        <th colspan="2" style="background-color: #dff0d8;">TRANSFERRING TO</th>
    </tr>
    <tr>
        <td class="label">GN Division:</td>
        <td>' . htmlspecialchars($from_gn['GN']) . '</td>
        <td class="label">GN Division:</td>
        <td>' . htmlspecialchars($to_gn['GN']) . '</td>
    </tr>
    <tr>
        <td class="label">GN ID:</td>
        <td>' . htmlspecialchars($from_gn_id) . '</td>
        <td class="label">GN ID:</td>
        <td>' . htmlspecialchars($transfer_details['to_gn_id']) . '</td>
    </tr>
    <tr>
        <td class="label">Division:</td>
        <td>' . htmlspecialchars($from_gn['Division_Name']) . '</td>
        <td class="label">Division:</td>
        <td>' . htmlspecialchars($to_gn['Division_Name']) . '</td>
    </tr>
    <tr>
        <td class="label">District:</td>
        <td>' . htmlspecialchars($from_gn['District_Name']) . '</td>
        <td class="label">District:</td>
        <td>' . htmlspecialchars($to_gn['District_Name']) . '</td>
    </tr>
    <tr>
        <td class="label">Province:</td>
        <td>' . htmlspecialchars($from_gn['Province_Name']) . '</td>
        <td class="label">Province:</td>
        <td>' . htmlspecialchars($to_gn['Province_Name']) . '</td>
    </tr>
</table>

<br>

<table>
    <tr>
        <th colspan="4" style="background-color: #d9edf7;">FAMILY DETAILS</th>
    </tr>
    <tr>
        <td class="label">Head of Family:</td>
        <td>' . htmlspecialchars($transfer_details['head_name'] ?? 'N/A') . '</td>
        <td class="label">Head NIC:</td>
        <td>' . htmlspecialchars($transfer_details['family_head_nic'] ?? 'N/A') . '</td>
    </tr>
    <tr>
        <td class="label">Total Members:</td>
        <td>' . ($transfer_details['actual_members'] ?? count($family_members)) . '</td>
        <td class="label">Current Address:</td>
        <td>' . nl2br(htmlspecialchars($transfer_details['address'] ?? '')) . '</td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Family Members Table
if (!empty($family_members)) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'FAMILY MEMBERS', 0, 1, 'C');
    
    $members_html = '
    <table>
        <tr>
            <th width="5%">#</th>
            <th width="25%">Name</th>
            <th width="15%">NIC/ID</th>
            <th width="15%">Relation</th>
            <th width="10%">Gender</th>
            <th width="15%">Date of Birth</th>
            <th width="15%">Contact</th>
        </tr>';
    
    foreach ($family_members as $index => $member) {
        $members_html .= '
        <tr>
            <td>' . ($index + 1) . '</td>
            <td>' . htmlspecialchars($member['full_name']) . '</td>
            <td>' . htmlspecialchars($member['identification_number']) . '</td>
            <td>' . htmlspecialchars($member['relation_to_head'] ?? 'Self') . '</td>
            <td>' . ucfirst($member['gender']) . '</td>
            <td>' . date('d/m/Y', strtotime($member['date_of_birth'])) . '</td>
            <td>' . htmlspecialchars($member['mobile_phone'] ?? 'N/A') . '</td>
        </tr>';
    }
    
    $members_html .= '</table>';
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->writeHTML($members_html, true, false, true, false, '');
}

// Land Details (if exists)
if (!empty($land_details)) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'LAND DETAILS', 0, 1, 'C');
    
    $land_html = '
    <table>
        <tr>
            <th width="5%">#</th>
            <th width="20%">Land Type</th>
            <th width="15%">Size (Perches)</th>
            <th width="25%">Deed Number</th>
            <th width="35%">Address</th>
        </tr>';
    
    foreach ($land_details as $index => $land) {
        $land_html .= '
        <tr>
            <td>' . ($index + 1) . '</td>
            <td>' . ucfirst($land['land_type']) . '</td>
            <td>' . $land['land_size_perches'] . '</td>
            <td>' . htmlspecialchars($land['deed_number'] ?? 'N/A') . '</td>
            <td>' . htmlspecialchars($land['land_address'] ?? 'N/A') . '</td>
        </tr>';
    }
    
    $land_html .= '</table>';
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->writeHTML($land_html, true, false, true, false, '');
}

// Add page for signatures and notes
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'IMPORTANT INSTRUCTIONS', 0, 1, 'C');

$instructions = '
<ol>
    <li>This transfer slip must be presented to the receiving GN office</li>
    <li>The receiving office must update the family\'s GN ID in their system</li>
    <li>Family ID and member IDs remain the same after transfer</li>
    <li>Original documents should be verified at the receiving office</li>
    <li>This transfer requires approval from divisional secretariat</li>
    <li>Both offices should keep a copy of this transfer slip</li>
    <li>Family members must inform the new GN office of any changes</li>
</ol>';

$pdf->SetFont('helvetica', '', 10);
$pdf->writeHTML($instructions, true, false, true, false, '');

// Signatures section
$pdf->Ln(20);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(95, 10, 'REQUESTING OFFICER', 0, 0, 'C');
$pdf->Cell(95, 10, 'RECEIVING OFFICER', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(95, 30, '', 'B', 0, 'C'); // Signature line for requesting officer
$pdf->Cell(95, 30, '', 'B', 1, 'C'); // Signature line for receiving officer

$pdf->Cell(95, 5, 'Name: ' . htmlspecialchars($office_name), 0, 0, 'C');
$pdf->Cell(95, 5, 'Name: _________________________', 0, 1, 'C');

$pdf->Cell(95, 5, 'Date: ' . date('d/m/Y'), 0, 0, 'C');
$pdf->Cell(95, 5, 'Date: _________________________', 0, 1, 'C');

// Footer note
$pdf->SetY(-30);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 5, 'Generated by FPMS - Family Profile Management System', 0, 1, 'C');
$pdf->Cell(0, 5, 'Document ID: ' . $transfer_id . ' | Generated on: ' . date('d/m/Y H:i:s'), 0, 1, 'C');

// Output the PDF
$pdf->Output($transfer_id . '.pdf', 'D'); // 'D' for download with transfer ID as filename
?>