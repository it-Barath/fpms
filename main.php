
<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay"></div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-xl-2 sidebar-column">
            <?php include 'includes/sidebar.php'; ?>
        </div>
        
        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-xl-10 px-md-4 main-content">
            <!-- Top Navigation -->              
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div class="d-flex align-items-center">
                    <!-- Mobile menu toggle button -->
                    <button class="btn btn-outline-secondary me-3 d-md-none" id="sidebarToggle" type="button">
                        <i class="bi bi-list"></i>
                    </button>
                    <h1 class="h2">
                        <i class="bi bi-speedometer2"></i> Dashboard
                        <small class="text-muted fs-6"><?php echo $_SESSION['office_name']; ?></small>
                    </h1>
                </div>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <span class="badge bg-<?php 
                            switch($_SESSION['user_type']) {
                                case 'moha': echo 'danger'; break;
                                case 'district': echo 'warning'; break;
                                case 'division': echo 'info'; break;
                                case 'gn': echo 'success'; break;
                            }
                        ?> fs-6 p-2">
                            <?php echo strtoupper($_SESSION['user_type']); ?>
                        </span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                </div>
            </div>
            
            <!-- Dashboard Cards -->
            <div class="row">
                <?php if ($_SESSION['user_type'] === 'moha'): ?>
                <!-- MOHA Dashboard -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Total Districts</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['total_districts'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-building fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="admin/districts.php" class="text-white small">View Details</a>
                            <span class="small"><?php echo date('M Y'); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Total Divisions</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['total_divisions'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-diagram-2 fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="#" class="text-white small">View Details</a>
                            <span class="small">Nationwide</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">GN Divisions</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['total_gn_divisions'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-map fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="#" class="text-white small">View Map</a>
                            <span class="small">All GN</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-warning h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Total Families</h6>
                                    <h2 class="card-text mb-0"><?php echo number_format($stats['total_families'] ?? 0); ?></h2>
                                </div>
                                <i class="bi bi-people fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="reports/index.php" class="text-white small">View Report</a>
                            <span class="small">Registered</span>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($_SESSION['user_type'] === 'district'): ?>
                <!-- District Dashboard -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Divisions</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['total_divisions'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-diagram-2 fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="district/divisions.php" class="text-white small">Manage</a>
                            <span class="small">Under District</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">GN Divisions</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['total_gn_divisions'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-map fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="#" class="text-white small">View All</a>
                            <span class="small">In District</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Total Families</h6>
                                    <h2 class="card-text mb-0"><?php echo number_format($stats['total_families'] ?? 0); ?></h2>
                                </div>
                                <i class="bi bi-people fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="district/view_families.php" class="text-white small">View Families</a>
                            <span class="small">Registered</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-warning h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Population</h6>
                                    <h2 class="card-text mb-0"><?php echo number_format($stats['total_population'] ?? 0); ?></h2>
                                </div>
                                <i class="bi bi-person-badge fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="district/statistics.php" class="text-white small">Statistics</a>
                            <span class="small">Citizens</span>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($_SESSION['user_type'] === 'division'): ?>
                <!-- Division Dashboard -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">GN Divisions</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['total_gn_divisions'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-map fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="division/gn_divisions.php" class="text-white small">Manage</a>
                            <span class="small">Under Division</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Total Families</h6>
                                    <h2 class="card-text mb-0"><?php echo number_format($stats['total_families'] ?? 0); ?></h2>
                                </div>
                                <i class="bi bi-people fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="division/view_families.php" class="text-white small">View Families</a>
                            <span class="small">Registered</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Population</h6>
                                    <h2 class="card-text mb-0"><?php echo number_format($stats['total_population'] ?? 0); ?></h2>
                                </div>
                                <i class="bi bi-person-badge fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="division/reports_division.php" class="text-white small">Reports</a>
                            <span class="small">Citizens</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-warning h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Pending Transfers</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['pending_transfers'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-arrow-left-right fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="division/transfer_requests.php" class="text-white small">Review</a>
                            <span class="small">Approval Needed</span>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($_SESSION['user_type'] === 'gn'): ?>
                <!-- GN Division Dashboard -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-primary h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Total Families</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['total_families'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-people fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="gn/families/manage.php" class="text-white small">Manage Families</a>
                            <span class="small">Registered</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-success h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Population</h6>
                                    <h2 class="card-text mb-0"><?php echo number_format($stats['total_population'] ?? 0); ?></h2>
                                </div>
                                <i class="bi bi-person-badge fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="gn/citizens/search.php" class="text-white small">View Citizens</a>
                            <span class="small">Total Citizens</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-info h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">This Month</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['families_this_month'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-calendar-plus fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="users/gn/citizens/add_family.php" class="text-white small">Add New</a>
                            <span class="small">New Families</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card text-white bg-warning h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-title mb-1">Pending Tasks</h6>
                                    <h2 class="card-text mb-0"><?php echo $stats['pending_tasks'] ?? 0; ?></h2>
                                </div>
                                <i class="bi bi-clock-history fs-1 opacity-75"></i>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between">
                            <a href="gn/profile/settings.php" class="text-white small">View All</a>
                            <span class="small">Actions Required</span>
                        </div>
                    </div>
                </div>
                
                <?php endif; ?>
            </div>
            
            <!-- Quick Actions Row -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="bi bi-lightning"></i> Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php if ($_SESSION['user_type'] === 'gn'): ?>
                                <div class="col-md-2 col-6">
                                    <a href="users/gn/citizens/add_family.php" class="btn btn-primary w-100">
                                        <i class="bi bi-plus-lg"></i> <span class="d-none d-md-inline">Add Family</span>
                                        <span class="d-inline d-md-none">Add</span>
                                    </a>
                                </div>
                                <div class="col-md-2 col-6">
                                    <a href="gn/citizens/search.php" class="btn btn-success w-100">
                                        <i class="bi bi-search"></i> <span class="d-none d-md-inline">Search Citizen</span>
                                        <span class="d-inline d-md-none">Search</span>
                                    </a>
                                </div>
                                <div class="col-md-2 col-6">
                                    <a href="gn/reports/family_report.php" class="btn btn-info w-100">
                                        <i class="bi bi-file-earmark-text"></i> <span class="d-none d-md-inline">Generate Report</span>
                                        <span class="d-inline d-md-none">Report</span>
                                    </a>
                                </div>
                                <div class="col-md-2 col-6">
                                    <a href="gn/families/transfer.php" class="btn btn-warning w-100">
                                        <i class="bi bi-arrow-left-right"></i> <span class="d-none d-md-inline">Transfer</span>
                                        <span class="d-inline d-md-none">Transfer</span>
                                    </a>
                                </div>
                                <div class="col-md-2 col-6">
                                    <a href="gn/profile/change_password.php" class="btn btn-secondary w-100">
                                        <i class="bi bi-key"></i> <span class="d-none d-md-inline">Change Password</span>
                                        <span class="d-inline d-md-none">Password</span>
                                    </a>
                                </div>
                                <?php elseif ($_SESSION['user_type'] === 'division'): ?>
                                <div class="col-md-3 col-6">
                                    <a href="division/gn_divisions.php" class="btn btn-primary w-100">
                                        <i class="bi bi-gear"></i> <span class="d-none d-md-inline">Manage GN Divisions</span>
                                        <span class="d-inline d-md-none">Manage GN</span>
                                    </a>
                                </div>
                                <div class="col-md-3 col-6">
                                    <a href="division/view_families.php" class="btn btn-success w-100">
                                        <i class="bi bi-eye"></i> <span class="d-none d-md-inline">View Families</span>
                                        <span class="d-inline d-md-none">Families</span>
                                    </a>
                                </div>
                                <div class="col-md-3 col-6">
                                    <a href="division/transfer_requests.php" class="btn btn-warning w-100">
                                        <i class="bi bi-check-circle"></i> <span class="d-none d-md-inline">Approve Transfers</span>
                                        <span class="d-inline d-md-none">Transfers</span>
                                    </a>
                                </div>
                                <div class="col-md-3 col-6">
                                    <a href="division/reports_division.php" class="btn btn-info w-100">
                                        <i class="bi bi-graph-up"></i> <span class="d-none d-md-inline">View Reports</span>
                                        <span class="d-inline d-md-none">Reports</span>
                                    </a>
                                </div>
                                <?php elseif ($_SESSION['user_type'] === 'district'): ?>
                                <div class="col-md-4 col-6">
                                    <a href="district/divisions.php" class="btn btn-primary w-100">
                                        <i class="bi bi-gear"></i> <span class="d-none d-md-inline">Manage Divisions</span>
                                        <span class="d-inline d-md-none">Divisions</span>
                                    </a>
                                </div>
                                <div class="col-md-4 col-6">
                                    <a href="district/reports_district.php" class="btn btn-success w-100">
                                        <i class="bi bi-file-earmark-text"></i> <span class="d-none d-md-inline">District Reports</span>
                                        <span class="d-inline d-md-none">Reports</span>
                                    </a>
                                </div>
                                <div class="col-md-4 col-12">
                                    <a href="district/statistics.php" class="btn btn-info w-100">
                                        <i class="bi bi-bar-chart"></i> <span class="d-none d-md-inline">Statistics</span>
                                        <span class="d-inline d-md-none">Stats</span>
                                    </a>
                                </div>
                                <?php elseif ($_SESSION['user_type'] === 'moha'): ?>
                                <div class="col-md-3 col-6">
                                    <a href="admin/districts.php" class="btn btn-primary w-100">
                                        <i class="bi bi-building"></i> <span class="d-none d-md-inline">Manage Districts</span>
                                        <span class="d-inline d-md-none">Districts</span>
                                    </a>
                                </div>
                                <div class="col-md-3 col-6">
                                    <a href="users/manage_passwords.php" class="btn btn-warning w-100">
                                        <i class="bi bi-key"></i> <span class="d-none d-md-inline">Reset Passwords</span>
                                        <span class="d-inline d-md-none">Passwords</span>
                                    </a>
                                </div>
                                <div class="col-md-3 col-6">
                                    <a href="admin/audit_logs.php" class="btn btn-secondary w-100">
                                        <i class="bi bi-clock-history"></i> <span class="d-none d-md-inline">Audit Logs</span>
                                        <span class="d-inline d-md-none">Audit</span>
                                    </a>
                                </div>
                                <div class="col-md-3 col-6">
                                    <a href="admin/system_settings.php" class="btn btn-dark w-100">
                                        <i class="bi bi-gear"></i> <span class="d-none d-md-inline">System Settings</span>
                                        <span class="d-inline d-md-none">Settings</span>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity Section -->
            <div class="row mt-4">
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="bi bi-activity"></i> Recent Activity
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recentActivities)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Action</th>
                                            <th>Details</th>
                                            <th>User</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentActivities as $activity): ?>
                                        <tr>
                                            <td class="text-nowrap">
                                                <small><?php echo date('H:i', strtotime($activity['created_at'])); ?></small><br>
                                                <small class="text-muted"><?php echo date('M d', strtotime($activity['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    switch($activity['action_type']) {
                                                        case 'login': echo 'success'; break;
                                                        case 'create': echo 'primary'; break;
                                                        case 'update': echo 'warning'; break;
                                                        case 'delete': echo 'danger'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($activity['action_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($activity['details'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($activity['username'] ?? 'System'); ?></small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end mt-3">
                                <a href="admin/audit_logs.php" class="btn btn-sm btn-outline-primary">View All Activity</a>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-activity fs-1 text-muted"></i>
                                <p class="text-muted mt-2">No recent activity found</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- System Status / Notifications -->
                <div class="col-lg-4 mt-4 mt-lg-0">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="bi bi-bell"></i> Notifications
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $notifications = $userManager->getNotifications($_SESSION['user_id']);
                            if (!empty($notifications)):
                            ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($notifications as $notification): ?>
                                <a href="#" class="list-group-item list-group-item-action border-0">
                                    <div class="d-flex w-100 justify-content-between">
                                        <small class="text-primary"><?php echo htmlspecialchars($notification['title']); ?></small>
                                        <small class="text-muted"><?php echo $notification['time_ago']; ?></small>
                                    </div>
                                    <small class="text-muted"><?php echo htmlspecialchars($notification['message']); ?></small>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-check-circle fs-1 text-success"></i>
                                <p class="text-muted mt-2">No new notifications</p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer text-center">
                            <a href="#" class="btn btn-sm btn-outline-secondary">Mark All as Read</a>
                        </div>
                    </div>
                    
                    <!-- System Status -->
                    <div class="card mt-3 h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="bi bi-heart-pulse"></i> System Status
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item d-flex justify-content-between align-items-center border-0">
                                    <span>Database</span>
                                    <span class="badge bg-success">Online</span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center border-0">
                                    <span>Last Backup</span>
                                    <small class="text-muted"><?php echo date('Y-m-d H:i'); ?></small>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center border-0">
                                    <span>System Uptime</span>
                                    <small class="text-muted">99.8%</small>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center border-0">
                                    <span>Active Users</span>
                                    <span class="badge bg-info"><?php echo $userManager->getActiveUsersCount(); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Footer with stats -->
            <footer class="pt-3 mt-4 text-muted border-top">
                <div class="row">
                    <div class="col-md-6">
                        <small>
                            <i class="bi bi-clock"></i> Last refresh: <span class="last-refresh-time"><?php echo date('H:i:s'); ?></span> |
                            <i class="bi bi-calendar"></i> <?php echo date('l, F j, Y'); ?>
                        </small>
                    </div>
                    <div class="col-md-6 text-end">
                        <small>
                            System Version: <?php echo SITE_VERSION; ?> |
                            Database: <?php echo MAIN_DB_NAME; ?> |
                            User: <?php echo htmlspecialchars($_SESSION['username']); ?>
                        </small>
                    </div>
                </div>
            </footer>
        </main>
    </div>
</div>