<?php
// logout_confirm.php - Confirm logout before proceeding
session_start();

// If not logged in, redirect to login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Get user information for display
$username = $_SESSION['username'] ?? 'User';
$userType = $_SESSION['user_type'] ?? 'unknown';
$officeName = $_SESSION['office_name'] ?? 'Unknown Office';

// Check if forced logout
$forced = isset($_GET['forced']) && $_GET['forced'] == 1;
$forcedMessage = $forced ? '<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i> This is a forced logout request.
</div>' : '';

// Set page title
$pageTitle = "Confirm Logout - Family Profile Management System";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px;
        }
        
        .logout-container {
            max-width: 500px;
            width: 100%;
            margin: 0 auto;
        }
        
        .logout-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logout-header {
            background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .logout-header .icon {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #e74c3c;
        }
        
        .logout-header h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .logout-body {
            padding: 30px;
        }
        
        .user-info-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid #3498db;
        }
        
        .user-info-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .user-info-item i {
            width: 25px;
            color: #666;
        }
        
        .btn-custom {
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            min-width: 120px;
        }
        
        .btn-logout {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            border: none;
            color: white;
        }
        
        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.4);
        }
        
        .btn-cancel {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            color: #495057;
        }
        
        .btn-cancel:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }
        
        .session-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .session-info h6 {
            color: #856404;
            margin-bottom: 10px;
        }
        
        .security-tip {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-size: 0.9rem;
        }
        
        @media (max-width: 576px) {
            .logout-container {
                max-width: 100%;
            }
            
            .logout-body {
                padding: 20px;
            }
            
            .btn-custom {
                width: 100%;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logout-card">
            <!-- Header -->
            <div class="logout-header">
                <div class="icon">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <h1>Confirm Logout</h1>
                <p>Please confirm that you want to sign out of the system</p>
            </div>
            
            <!-- Body -->
            <div class="logout-body">
                <!-- Forced logout warning -->
                <?php echo $forcedMessage; ?>
                
                <!-- User Information -->
                <div class="user-info-box">
                    <div class="user-info-item">
                        <i class="fas fa-user"></i>
                        <div>
                            <strong>Username:</strong> <?php echo htmlspecialchars($username); ?>
                        </div>
                    </div>
                    <div class="user-info-item">
                        <i class="fas fa-user-tag"></i>
                        <div>
                            <strong>User Role:</strong> 
                            <span class="badge bg-primary"><?php echo strtoupper(htmlspecialchars($userType)); ?></span>
                        </div>
                    </div>
                    <div class="user-info-item">
                        <i class="fas fa-building"></i>
                        <div>
                            <strong>Office:</strong> <?php echo htmlspecialchars($officeName); ?>
                        </div>
                    </div>
                    <div class="user-info-item">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong>Session Started:</strong> 
                            <?php 
                            echo isset($_SESSION['login_time']) 
                                ? date('h:i A', strtotime($_SESSION['login_time'])) 
                                : 'Unknown'; 
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Session Information -->
                <div class="session-info">
                    <h6><i class="fas fa-exclamation-circle"></i> Before You Logout</h6>
                    <ul class="mb-0 small">
                        <li>Any unsaved work will be lost</li>
                        <li>You will need to login again to access the system</li>
                        <li>Your session data will be cleared</li>
                        <li>Remember me tokens will be deleted</li>
                    </ul>
                </div>
                
                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-md-6">
                        <a href="index.php" class="btn btn-cancel btn-custom w-100">
                            <i class="fas fa-arrow-left me-2"></i>Cancel & Return
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="logout.php?confirmed=1<?php echo $forced ? '&forced=1' : ''; ?>" 
                           class="btn btn-logout btn-custom w-100"
                           onclick="showLogoutLoader()">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout Now
                        </a>
                    </div>
                </div>
                
                <!-- Security Tip -->
                <div class="security-tip">
                    <i class="fas fa-shield-alt me-2"></i>
                    <strong>Security Tip:</strong> Always logout when leaving your computer unattended to protect sensitive data.
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loading Modal -->
    <div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 bg-transparent">
                <div class="modal-body text-center">
                    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                        <span class="visually-hidden">Logging out...</span>
                    </div>
                    <div class="mt-3 text-white">
                        <h5>Logging out...</h5>
                        <p class="mb-0">Please wait while we secure your session</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Show logout loader
        function showLogoutLoader() {
            const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));
            loadingModal.show();
            
            // Auto-redirect after 3 seconds if logout fails
            setTimeout(() => {
                window.location.href = 'index.php?logout_timeout=1';
            }, 3000);
        }
        
        // Auto-focus cancel button for accessibility
        document.addEventListener('DOMContentLoaded', function() {
            // Add confirmation dialog for forced logout
            const forcedLinks = document.querySelectorAll('a[href*="forced=1"]');
            forcedLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!confirm('Are you sure you want to force logout this user?')) {
                        e.preventDefault();
                        return false;
                    }
                });
            });
            
            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Escape key - cancel logout
                if (e.key === 'Escape') {
                    e.preventDefault();
                    window.location.href = 'index.php';
                }
                // L key - logout
                if (e.key === 'l' || e.key === 'L') {
                    if (e.ctrlKey) {
                        e.preventDefault();
                        document.querySelector('.btn-logout').click();
                    }
                }
            });
            
            // Show session duration
            const loginTime = '<?php echo $_SESSION['login_time'] ?? ''; ?>';
            if (loginTime) {
                const duration = calculateSessionDuration(loginTime);
                if (duration) {
                    const durationElement = document.createElement('div');
                    durationElement.className = 'user-info-item';
                    durationElement.innerHTML = `
                        <i class="fas fa-hourglass-half"></i>
                        <div>
                            <strong>Session Duration:</strong> ${duration}
                        </div>
                    `;
                    document.querySelector('.user-info-box').appendChild(durationElement);
                }
            }
        });
        
        function calculateSessionDuration(loginTimeStr) {
            try {
                const loginTime = new Date(loginTimeStr);
                const now = new Date();
                const diffMs = now - loginTime;
                
                // Convert to hours, minutes, seconds
                const hours = Math.floor(diffMs / (1000 * 60 * 60));
                const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((diffMs % (1000 * 60)) / 1000);
                
                let result = '';
                if (hours > 0) result += `${hours}h `;
                if (minutes > 0) result += `${minutes}m `;
                if (seconds > 0 || result === '') result += `${seconds}s`;
                
                return result.trim();
            } catch (e) {
                return '';
            }
        }
        
        // Check for inactivity
        let logoutTimer;
        function resetLogoutTimer() {
            clearTimeout(logoutTimer);
            // Logout after 1 minute of inactivity on this page
            logoutTimer = setTimeout(() => {
                alert('Logout confirmation expired due to inactivity. Redirecting to dashboard.');
                window.location.href = 'index.php';
            }, 60000); // 1 minute
        }
        
        // Reset timer on user activity
        document.addEventListener('mousemove', resetLogoutTimer);
        document.addEventListener('keypress', resetLogoutTimer);
        document.addEventListener('click', resetLogoutTimer);
        
        // Start the timer
        resetLogoutTimer();
    </script>
</body>
</html>