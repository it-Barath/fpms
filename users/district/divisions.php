<?php
// district/create_division.php
// Create division user account

session_start();
require_once '../config.php';
require_once '../classes/Auth.php';
require_once '../classes/UserManager.php';

// Check authentication
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole('district');

$currentUser = $auth->getCurrentUser();
$districtName = $currentUser['office_code'];
$districtDisplayName = $currentUser['office_name'];

// Get division from query string
$divisionName = $_GET['division'] ?? '';
$prefill = !empty($divisionName);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $divisionName = $_POST['division_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    
    // Validate
    $errors = [];
    
    if (empty($divisionName)) {
        $errors[] = "Division name is required";
    }
    
    if (empty($username)) {
        $errors[] = "Username is required";
    }
    
    // Check if division is under this district
    $conn = getRefConnection();
    $stmt = $conn->prepare("SELECT 1 FROM fix_work_station WHERE Division_Name = ? AND District_Name = ?");
    $stmt->bind_param("ss", $divisionName, $districtName);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        $errors[] = "Division is not under your jurisdiction";
    }
    
    if (empty($errors)) {
        // Create user
        $userManager = new UserManager();
        
        $userData = [
            'username' => $username,
            'user_type' => 'division',
            'office_code' => $divisionName,
            'office_name' => $divisionName . ' Division',
            'email' => $email,
            'phone' => $phone,
            'is_active' => 1,
            'password' => generateRandomPassword(12)
        ];
        
        list($success, $message, $userId) = $userManager->createUser($userData);
        
        if ($success) {
            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => "Division user created successfully! Password: " . $userData['password']
            ];
            header('Location: divisions.php');
            exit();
        } else {
            $errors[] = $message;
        }
    }
}

// Get divisions under this district
$divisions = [];
$conn = getRefConnection();
$query = "SELECT DISTINCT Division_Name FROM fix_work_station WHERE District_Name = ? ORDER BY Division_Name";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $districtName);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $divisions[] = $row['Division_Name'];
}

$pageTitle = "Create Division User";
include '../includes/header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-user-plus"></i> Create Division User</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="division_name" class="form-label">Division Name *</label>
                            <select class="form-select" id="division_name" name="division_name" required>
                                <option value="">Select Division</option>
                                <?php foreach ($divisions as $div): ?>
                                    <option value="<?php echo htmlspecialchars($div); ?>"
                                        <?php echo ($prefill && $div == $divisionName) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($div); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Select division under <?php echo htmlspecialchars($districtDisplayName); ?></small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username *</label>
                            <div class="input-group">
                                <span class="input-group-text">division_</span>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?php echo $prefill ? 'division_' . strtolower(str_replace(' ', '_', $divisionName)) : ''; ?>"
                                       required>
                            </div>
                            <small class="form-text text-muted">Format: division_divisionname (lowercase, underscores)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo $prefill ? strtolower(str_replace(' ', '.', $divisionName)) . '@fpms.lk' : ''; ?>">
                            <small class="form-text text-muted">Division officer's email</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                   pattern="[0-9+\-\s()]{10,15}">
                            <small class="form-text text-muted">Format: +94 XX XXX XXXX</small>
                        </div>
                        
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle"></i> Important Information</h6>
                            <ul class="mb-0">
                                <li>A random password will be generated automatically</li>
                                <li>User will be activated immediately</li>
                                <li>You can reset password later if needed</li>
                                <li>Division officer will receive login credentials</li>
                            </ul>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create Division User
                            </button>
                            <a href="divisions.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-generate username based on division selection
    $('#division_name').change(function() {
        var division = $(this).val();
        if (division) {
            var username = 'division_' + division.toLowerCase().replace(/ /g, '_');
            $('#username').val(username);
            $('#email').val(division.toLowerCase().replace(/ /g, '.') + '@fpms.lk');
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>