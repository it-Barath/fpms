<?php
/**
 * Unauthorized Access Page
 */
require_once 'config.php';

$title = "Unauthorized Access";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> - FPMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .unauthorized-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 40px;
            text-align: center;
            max-width: 500px;
            width: 90%;
        }
        .unauthorized-icon {
            font-size: 5rem;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .btn-group {
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="unauthorized-card">
        <div class="unauthorized-icon">
            <i class="bi bi-shield-lock"></i>
        </div>
        <h1 class="text-danger mb-3">Access Denied</h1>
        <p class="lead mb-4">You don't have permission to access this page. This action has been logged for security purposes.</p>
        
        <?php if (isset($_GET['reason'])): ?>
            <div class="alert alert-warning">
                <strong>Reason:</strong> <?php echo htmlspecialchars($_GET['reason']); ?>
            </div>
        <?php endif; ?>
        
        <div class="btn-group">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="index.php" class="btn btn-primary">
                    <i class="bi bi-house-door"></i> Return to Dashboard
                </a>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </a>
            <?php endif; ?>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Go Back
            </a>
        </div>
        
        <hr class="my-4">
        
        <div class="text-muted small">
            <p>If you believe this is an error, please contact your system administrator.</p>
            <p>Logged: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>    