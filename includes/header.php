<?php
/**
 * header.php
 * Common header for all pages
 * Includes navigation, CSS, and meta tags
 */

// Start output buffering
ob_start();

// Check if config is loaded, if not load it
if (!defined('SITE_NAME')) {
    require_once '../config.php';
}

// Create Auth instance if not already created
if (!isset($auth)) {
    $auth = new Auth();
}

// Get current user info
$currentUser = $auth->getCurrentUser();

// Set default page title if not set
if (!isset($pageTitle)) {
    $pageTitle = SITE_NAME;
}

// Get current URL for active menu highlighting
$currentUrl = basename($_SERVER['PHP_SELF']);
$currentDir = dirname($_SERVER['PHP_SELF']);
$currentDir = str_replace('\\', '/', $currentDir);
$currentDir = trim($currentDir, '/');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- DataTables CSS (for tables) -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    
    <!-- Select2 CSS (for advanced dropdowns) -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>assets/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo SITE_URL; ?>assets/images/favicon.ico">
    
    <!-- Theme Color -->
    <meta name="theme-color" content="#0d6efd">
    
    <!-- CSRF Token for AJAX requests -->
    <meta name="csrf-token" content="<?php echo generateCsrfToken(); ?>">
    
    <!-- JavaScript variables for global use -->
    <script>
        // Global JavaScript variables
        var SITE_URL = '<?php echo SITE_URL; ?>';
        var CURRENT_USER = <?php echo json_encode($currentUser); ?>;
        var DEBUG_MODE = <?php echo DEBUG_MODE ? 'true' : 'false'; ?>;
        var CSRF_TOKEN = '<?php echo generateCsrfToken(); ?>';
    </script>
    
    <!-- Custom styles for this page -->
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #198754;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #0dcaf0;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --sidebar-width: 280px;
            --header-height: 60px;
        }
        
        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
        }
        
        /* Layout fixes */
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .main-wrapper {
            display: flex;
            flex: 1;
            min-height: calc(100vh - var(--header-height));
        }
        
        .main-content-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
            min-width: 0;
        }
        
        @media (max-width: 767.98px) {
            .main-content-wrapper {
                margin-left: 0 !important;
            }
            
            body.sidebar-open .main-content-wrapper {
                margin-left: 0 !important;
            }
        }
        
        @media (min-width: 768px) and (max-width: 991.98px) {
            .main-content-wrapper {
                margin-left: 250px;
            }
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .loading-overlay.show {
            display: flex;
        }
    </style>
    
    <!-- Page-specific CSS -->
    <?php if (isset($pageCss)): ?>
    <style><?php echo $pageCss; ?></style>
    <?php endif; ?>
    
    <!-- Page-specific CSS files -->
    <?php if (isset($cssFiles) && is_array($cssFiles)): ?>
        <?php foreach ($cssFiles as $cssFile): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($cssFile); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body class="<?php echo isset($bodyClass) ? $bodyClass : ''; ?>">
    <!-- Skip to main content link for accessibility -->
    <a href="#main-content" class="visually-hidden-focusable">Skip to main content</a>
    
    <!-- Loading overlay -->
    <div id="loading-overlay" class="loading-overlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>  
    </div>
    
    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay"></div>
    
    <!-- Top Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow-sm no-print" id="main-nav" style="z-index: 1030;">
        <div class="container-fluid">
            <!-- Sidebar Toggle Button -->
            <button class="navbar-toggler me-2" type="button" id="sidebarToggle">
                <span class="navbar-toggler-icon"></span>
            </button> 
            
            <!-- Brand/Logo -->
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-users me-2"></i>
                <span class="d-none d-md-inline"><?php echo SITE_SHORT_NAME; ?></span>
                <span class="badge bg-light text-primary ms-2">v<?php echo SITE_VERSION; ?></span>
            </a>
            
            <!-- Mobile Toggle -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <!-- Main Navbar Content -->
            <div class="collapse navbar-collapse" id="navbarMain">
                <!-- Navigation Menu -->
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($currentUrl == 'index.php') ? 'active' : ''; ?>" href="https://dsd.samurdhi.gov.lk/fpms/index.php">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </li>
                    
                    <?php if ($currentUser && $auth->hasPermission('reports_all')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo (strpos($currentDir, 'reports') !== false) ? 'active' : ''; ?>" href="reports/index.php">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($currentUser && $auth->hasPermission('manage_users')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo (strpos($currentDir, 'users') !== false || strpos($currentDir, 'admin') !== false) ? 'active' : ''; ?>" href="users/manage_passwords.php">
                            <i class="fas fa-user-cog"></i> User Management
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <!-- Right side items -->
                <div class="navbar-nav ms-auto align-items-center">
                    <!-- System Status -->
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-server text-success"></i>
                            <span class="d-none d-md-inline">System</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            <h6 class="dropdown-header">System Status</h6>
                            <div class="px-3 py-2">
                                <small class="text-muted d-block">
                                    <i class="fas fa-database"></i> 
                                    DB: <?php echo MAIN_DB_NAME; ?>
                                </small>
                                <small class="text-muted d-block mt-1">
                                    <i class="fas fa-clock"></i> 
                                    <?php echo date('h:i A'); ?>
                                </small>
                                <small class="text-muted d-block mt-1">
                                    <i class="fas fa-calendar"></i> 
                                    <?php echo date('d M Y'); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notifications -->
                    <?php if ($currentUser): ?>
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-bell"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notificationCount">
                                0
                            </span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end" style="min-width: 300px;">
                            <h6 class="dropdown-header">Notifications</h6>
                            <div id="notificationList" class="px-2" style="max-height: 300px; overflow-y: auto;">
                                <div class="text-center py-3">
                                    <div class="spinner-border spinner-border-sm" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <small class="text-muted d-block mt-2">Loading notifications...</small>
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-center" href="#" id="markAllRead">
                                <small>Mark all as read</small>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- User Profile -->
                    <?php if ($currentUser): ?>
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <div class="me-2">
                                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                    <i class="fas fa-user text-primary"></i>
                                </div>
                            </div>
                            <div class="d-none d-md-block">
                                <div class="fw-bold small"><?php echo htmlspecialchars($currentUser['username']); ?></div>
                                <div class="text-white-50 small">
                                    <?php 
                                    $badgeClass = '';
                                    switch($currentUser['user_type']) {
                                        case 'moha': $badgeClass = 'bg-danger'; break;
                                        case 'district': $badgeClass = 'bg-warning'; break;
                                        case 'division': $badgeClass = 'bg-info'; break;
                                        case 'gn': $badgeClass = 'bg-success'; break;
                                    }
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>">
                                        <?php echo strtoupper($currentUser['user_type']); ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            <h6 class="dropdown-header">Signed in as</h6>
                            <div class="px-3 mb-2">
                                <div class="fw-bold"><?php echo htmlspecialchars($currentUser['username']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($currentUser['office_name']); ?></small>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="https://dsd.samurdhi.gov.lk/fpms/users/my_profile.php">
                                <i class="fas fa-user-circle me-2"></i> My Profile
                            </a>
                            <a class="dropdown-item" href="https://dsd.samurdhi.gov.lk/fpms/users/change_password.php">
                                <i class="fas fa-key me-2"></i> Change Password
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="https://dsd.samurdhi.gov.lk/fpms/users/activity_log.php">
                                <i class="fas fa-history me-2"></i> Activity Log
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-danger" href="https://dsd.samurdhi.gov.lk/fpms/logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="nav-item">
                        <a class="nav-link" href="https://dsd.samurdhi.gov.lk/fpms/login.php">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Theme Toggle -->
                    <div class="nav-item ms-2">
                        <button class="btn btn-outline-light btn-sm" id="themeToggle" title="Toggle theme">
                            <i class="fas fa-moon" id="themeIcon"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Wrapper -->
    <div class="main-wrapper">
   
        
        <!-- Main Content Area -->
        <div class="main-content-wrapper" id="main-content">
            <!-- Page Header/Breadcrumb -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom no-print">
                <div>
                    <h1 class="h2">
                        <?php if (isset($pageIcon)): ?>
                        <i class="<?php echo $pageIcon; ?> me-2"></i>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($pageTitle); ?>
                    </h1>
                    <?php if (isset($pageDescription)): ?>
                    <p class="lead mb-0"><?php echo htmlspecialchars($pageDescription); ?></p>
                    <?php endif; ?>
                </div>
                
                <!-- Page Actions -->
                <div class="btn-toolbar mb-2 mb-md-0">
                    <?php if (isset($pageActions)): ?>
                        <?php echo $pageActions; ?>
                    <?php endif; ?>
                    
                    <!-- Print Button -->
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                    
                    <!-- Help Button -->
                    <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#helpModal">
                        <i class="fas fa-question-circle"></i> Help
                    </button>
                </div>
            </div>
            
            <!-- Flash Messages -->
            <div id="flash-messages">
                <?php displayFlashMessage(); ?>
            </div>
            
            <!-- Breadcrumb -->
            <?php if (isset($breadcrumbs) && is_array($breadcrumbs)): ?>
            <nav aria-label="breadcrumb" class="mb-3 no-print">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php"><i class="fas fa-home"></i></a></li>
                    <?php foreach ($breadcrumbs as $crumb): ?>
                        <?php if (isset($crumb['url'])): ?>
                        <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($crumb['url']); ?>"><?php echo htmlspecialchars($crumb['title']); ?></a></li>
                        <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($crumb['title']); ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <?php endif; ?>
            
            <!-- Help Modal -->
            <div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-question-circle me-2"></i>Help & Support
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-info-circle text-primary"></i> About This Page</h6>
                                    <p class="small"><?php echo isset($pageHelp) ? $pageHelp : 'This page is part of the Family Profile Management System.'; ?></p>
                                    
                                    <h6 class="mt-3"><i class="fas fa-book text-success"></i> Documentation</h6>
                                    <ul class="small">
                                        <li><a href="<?php echo SITE_URL; ?>docs/user_guide.pdf" target="_blank">User Guide</a></li>
                                        <li><a href="<?php echo SITE_URL; ?>docs/faq.html" target="_blank">FAQ</a></li>
                                        <li><a href="<?php echo SITE_URL; ?>docs/tutorials.html" target="_blank">Video Tutorials</a></li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6><i class="fas fa-headset text-warning"></i> Support</h6>
                                    <ul class="small">
                                        <li><strong>Email:</strong> <?php echo SUPPORT_EMAIL; ?></li>
                                        <li><strong>Phone:</strong> <?php echo SUPPORT_PHONE; ?></li>
                                        <li><strong>Hours:</strong> Mon-Fri, 8:30 AM - 4:30 PM</li>
                                    </ul>
                                    
                                    <h6 class="mt-3"><i class="fas fa-bug text-danger"></i> Report Issue</h6>
                                    <p class="small">Found a bug or issue? <a href="report_issue.php">Click here to report</a>.</p>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <a href="contact_support.php" class="btn btn-primary">Contact Support</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <div class="content-area">