<?php
/**
 * nav.php
 * Top navigation bar
 * This file is included in header.php, but can also be used separately
 */

// Check if required objects are available
if (!isset($auth)) {
    require_once '../config.php';
    $auth = new Auth();
}

$currentUser = $auth->getCurrentUser();
$currentUrl = basename($_SERVER['PHP_SELF']);
?>
<!-- Top Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm fixed-top" id="mainNav">
    <div class="container-fluid">
        <!-- Sidebar Toggle (for mobile) -->
        <button class="navbar-toggler me-2" type="button" id="sidebarToggle" data-bs-toggle="collapse" data-bs-target="#sidebar" aria-controls="sidebar" aria-expanded="false" aria-label="Toggle sidebar">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Brand/Logo -->
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <div class="brand-icon me-2">
                <i class="fas fa-users fs-4"></i>
            </div>
            <div>
                <span class="d-none d-md-inline fw-bold"><?php echo SITE_NAME; ?></span>
                <span class="d-md-none fw-bold"><?php echo SITE_SHORT_NAME; ?></span>
                <small class="d-block text-white-50 small">Family Profile Management</small>
            </div>
        </a>
        
        <!-- Mobile Toggle Button -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCollapse">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Links -->
        <div class="collapse navbar-collapse" id="navbarCollapse">
            <!-- Left Navigation -->
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentUrl == 'index.php') ? 'active' : ''; ?>" href="index.php">
                        <i class="fas fa-home me-1"></i> Dashboard
                    </a>
                </li>
                
                <?php if ($currentUser && $currentUser['user_type'] === 'gn'): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo (strpos($currentUrl, 'citizens') !== false || strpos($currentUrl, 'families') !== false) ? 'active' : ''; ?>" 
                       href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-users me-1"></i> Citizens
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="gn/citizens/add_family.php"><i class="fas fa-plus-circle me-2"></i> Add Family</a></li>
                        <li><a class="dropdown-item" href="gn/citizens/add_member.php"><i class="fas fa-user-plus me-2"></i> Add Member</a></li>
                        <li><a class="dropdown-item" href="gn/citizens/search.php"><i class="fas fa-search me-2"></i> Search</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="gn/families/manage.php"><i class="fas fa-list me-2"></i> Manage Families</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                
                <?php if ($currentUser && in_array($currentUser['user_type'], ['district', 'division', 'moha'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($currentUrl, 'reports') !== false) ? 'active' : ''; ?>" href="reports/index.php">
                        <i class="fas fa-chart-bar me-1"></i> Reports
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($currentUser && $auth->hasPermission('manage_users')): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($currentUrl, 'users/manage_passwords.php') !== false) ? 'active' : ''; ?>" href="users/manage_passwords.php">
                        <i class="fas fa-user-cog me-1"></i> User Management
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($currentUser && $currentUser['user_type'] === 'division'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentUrl == 'division/transfer_requests.php') ? 'active' : ''; ?>" href="division/transfer_requests.php">
                        <i class="fas fa-exchange-alt me-1"></i> Transfers
                        <span class="badge bg-danger rounded-pill" id="transferBadge">0</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            
            <!-- Right Navigation -->
            <ul class="navbar-nav ms-auto">
                <!-- Search (Desktop Only) -->
                <li class="nav-item d-none d-lg-block">
                    <form class="d-flex" action="search.php" method="GET">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" placeholder="Search..." name="q" style="width: 200px;">
                            <button class="btn btn-light" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </form>
                </li>
                
                <!-- System Status Indicator -->
                <li class="nav-item">
                    <a class="nav-link" href="#" data-bs-toggle="tooltip" title="System Status">
                        <i class="fas fa-circle text-success"></i>
                        <span class="d-none d-md-inline">Online</span>
                    </a>
                </li>
                
                <!-- Notifications -->
                <?php if ($currentUser): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-bell"></i>
                        <span class="badge bg-danger rounded-pill notification-count" id="topNotificationCount">0</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end shadow" style="min-width: 320px;">
                        <div class="dropdown-header d-flex justify-content-between align-items-center">
                            <span>Notifications</span>
                            <a href="#" class="small text-primary" id="markAllNotificationsRead">Mark all read</a>
                        </div>
                        <div class="dropdown-divider"></div>
                        <div id="notificationDropdown" style="max-height: 300px; overflow-y: auto;">
                            <div class="text-center py-3">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="small text-muted mt-2 mb-0">Loading notifications...</p>
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center small" href="notifications.php">
                            View All Notifications
                        </a>
                    </div>
                </li>
                <?php endif; ?>
                
                <!-- User Profile -->
                <?php if ($currentUser): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                        <div class="me-2">
                            <div class="avatar-sm bg-white rounded-circle d-flex align-items-center justify-content-center">
                                <i class="fas fa-user text-primary"></i>
                            </div>
                        </div>
                        <div class="d-none d-lg-block">
                            <div class="fw-bold small"><?php echo htmlspecialchars($currentUser['username']); ?></div>
                            <div class="text-white-50 small">
                                <?php 
                                $roleBadge = '';
                                switch($currentUser['user_type']) {
                                    case 'moha': $roleBadge = 'bg-danger'; break;
                                    case 'district': $roleBadge = 'bg-warning'; break;
                                    case 'division': $roleBadge = 'bg-info'; break;
                                    case 'gn': $roleBadge = 'bg-success'; break;
                                }
                                ?>
                                <span class="badge <?php echo $roleBadge; ?>">
                                    <?php echo strtoupper($currentUser['user_type']); ?>
                                </span>
                            </div>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li class="dropdown-header">
                            <div class="fw-bold"><?php echo htmlspecialchars($currentUser['username']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($currentUser['office_name']); ?></small>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="users/my_profile.php">
                                <i class="fas fa-user-circle me-2"></i> My Profile
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="users/change_password.php">
                                <i class="fas fa-key me-2"></i> Change Password
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="users/activity_log.php">
                                <i class="fas fa-history me-2"></i> Activity Log
                            </a>
                        </li>
                        <?php if ($currentUser['user_type'] === 'gn'): ?>
                        <li>
                            <a class="dropdown-item" href="gn/profile/settings.php">
                                <i class="fas fa-cog me-2"></i> GN Settings
                            </a>
                        </li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="help.php">
                                <i class="fas fa-question-circle me-2"></i> Help & Support
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="about.php">
                                <i class="fas fa-info-circle me-2"></i> About
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a>
                        </li>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link" href="login.php">
                        <i class="fas fa-sign-in-alt me-1"></i> Login
                    </a>
                </li>
                <?php endif; ?>
                
                <!-- Quick Actions Menu -->
                <?php if ($currentUser): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link" href="#" role="button" data-bs-toggle="dropdown" title="Quick Actions">
                        <i class="fas fa-bolt"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end shadow">
                        <div class="dropdown-header">Quick Actions</div>
                        <?php if ($currentUser['user_type'] === 'gn'): ?>
                        <a class="dropdown-item" href="gn/citizens/add_family.php">
                            <i class="fas fa-plus-circle text-success me-2"></i> Add New Family
                        </a>
                        <a class="dropdown-item" href="gn/citizens/quick_add.php">
                            <i class="fas fa-user-plus text-primary me-2"></i> Quick Add Member
                        </a>
                        <a class="dropdown-item" href="gn/families/transfer.php">
                            <i class="fas fa-exchange-alt text-warning me-2"></i> Transfer Family
                        </a>
                        <?php elseif ($currentUser['user_type'] === 'division'): ?>
                        <a class="dropdown-item" href="division/gn_divisions.php">
                            <i class="fas fa-map text-primary me-2"></i> Manage GN Divisions
                        </a>
                        <a class="dropdown-item" href="division/transfer_requests.php">
                            <i class="fas fa-check-circle text-success me-2"></i> Approve Transfers
                        </a>
                        <?php elseif ($currentUser['user_type'] === 'district'): ?>
                        <a class="dropdown-item" href="district/divisions.php">
                            <i class="fas fa-sitemap text-primary me-2"></i> Manage Divisions
                        </a>
                        <a class="dropdown-item" href="district/reports_district.php">
                            <i class="fas fa-file-alt text-info me-2"></i> Generate Report
                        </a>
                        <?php elseif ($currentUser['user_type'] === 'moha'): ?>
                        <a class="dropdown-item" href="admin/districts.php">
                            <i class="fas fa-building text-primary me-2"></i> Manage Districts
                        </a>
                        <a class="dropdown-item" href="users/manage_passwords.php">
                            <i class="fas fa-key text-warning me-2"></i> Reset Passwords
                        </a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="reports/generate.php">
                            <i class="fas fa-chart-bar text-info me-2"></i> Generate Report
                        </a>
                        <a class="dropdown-item" onclick="window.print()">
                            <i class="fas fa-print text-secondary me-2"></i> Print Current Page
                        </a>
                    </div>
                </li>
                <?php endif; ?>
                
                <!-- Theme Toggle -->
                <li class="nav-item">
                    <button class="nav-link btn btn-link" id="themeToggleBtn" title="Toggle theme">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Backdrop for mobile sidebar -->
<div class="sidebar-backdrop fade" id="sidebarBackdrop"></div>

<!-- Notification sound (optional) -->
<audio id="notificationSound" preload="auto">
    <source src="<?php echo SITE_URL; ?>assets/sounds/notification.mp3" type="audio/mpeg">
</audio>

<!-- JavaScript for navigation -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            sidebarBackdrop.classList.toggle('show');
        });
        
        sidebarBackdrop.addEventListener('click', function() {
            sidebar.classList.remove('show');
            this.classList.remove('show');
        });
    }
    
    // Theme toggle
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeIcon = document.getElementById('themeIcon');
    
    if (themeToggleBtn) {
        // Load saved theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
        themeIcon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        
        themeToggleBtn.addEventListener('click', function() {
            const currentTheme = document.documentElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            document.documentElement.setAttribute('data-bs-theme', newTheme);
            themeIcon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            localStorage.setItem('theme', newTheme);
        });
    }
    
    // Load notifications
    function loadNotifications() {
        if (!CURRENT_USER) return;
        
        fetch('ajax/get_notifications.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateNotificationDisplay(data);
                }
            })
            .catch(error => console.error('Error loading notifications:', error));
    }
    
    function updateNotificationDisplay(data) {
        const count = data.unread_count || 0;
        const notifications = data.notifications || [];
        
        // Update badge count
        const badgeElements = document.querySelectorAll('.notification-count');
        badgeElements.forEach(el => {
            el.textContent = count;
            el.style.display = count > 0 ? 'inline' : 'none';
        });
        
        // Update transfer badge if exists
        const transferBadge = document.getElementById('transferBadge');
        if (transferBadge && data.pending_transfers) {
            transferBadge.textContent = data.pending_transfers;
            transferBadge.style.display = data.pending_transfers > 0 ? 'inline' : 'none';
        }
        
        // Update dropdown content
        const dropdown = document.getElementById('notificationDropdown');
        if (dropdown) {
            if (notifications.length === 0) {
                dropdown.innerHTML = '<div class="text-center py-3"><small class="text-muted">No notifications</small></div>';
            } else {
                let html = '';
                notifications.forEach(notif => {
                    const isUnread = notif.read === '0' || notif.read === false;
                    html += `
                        <a href="${notif.link || '#'}" class="dropdown-item notification-item ${isUnread ? 'fw-bold' : ''}" data-id="${notif.id}">
                            <div class="d-flex">
                                <div class="me-2">
                                    <i class="fas fa-${notif.icon || 'bell'} text-${notif.type || 'primary'}"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="small">${notif.title || 'Notification'}</div>
                                    <div class="text-muted smaller">${notif.message || ''}</div>
                                    <div class="text-muted smaller">${notif.time_ago || ''}</div>
                                </div>
                                ${isUnread ? '<div class="ms-2"><span class="badge bg-danger rounded-pill">New</span></div>' : ''}
                            </div>
                        </a>
                        <div class="dropdown-divider my-1"></div>
                    `;
                });
                dropdown.innerHTML = html;
            }
        }
    }
    
    // Mark all notifications as read
    const markAllBtn = document.getElementById('markAllNotificationsRead');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function(e) {
            e.preventDefault();
            fetch('ajax/mark_all_notifications_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                }
            })
            .then(() => loadNotifications())
            .catch(error => console.error('Error marking notifications as read:', error));
        });
    }
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Auto-hide dropdowns on click outside
    document.addEventListener('click', function(event) {
        if (!event.target.matches('.dropdown-toggle')) {
            const dropdowns = document.querySelectorAll('.dropdown-menu.show');
            dropdowns.forEach(dropdown => {
                dropdown.classList.remove('show');
            });
        }
    });
    
    // Load initial notifications
    loadNotifications();
    
    // Refresh notifications every 60 seconds
    setInterval(loadNotifications, 60000);
    
    // Play notification sound for new notifications
    let lastNotificationCount = 0;
    setInterval(() => {
        const currentCount = parseInt(document.querySelector('.notification-count')?.textContent || 0);
        if (currentCount > lastNotificationCount) {
            const sound = document.getElementById('notificationSound');
            if (sound) {
                sound.currentTime = 0;
                sound.play().catch(e => console.log('Audio play failed:', e));
            }
        }
        lastNotificationCount = currentCount;
    }, 5000);
});
</script>

<!-- CSS for navigation -->
<style>
#mainNav {
    padding: 0.5rem 0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    z-index: 1030;
}

#mainNav .navbar-brand {
    padding: 0;
}

#mainNav .brand-icon {
    width: 40px;
    height: 40px;
    background: rgba(255,255,255,0.1);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

#mainNav .navbar-nav .nav-link {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    margin: 0 2px;
    transition: all 0.2s;
}

#mainNav .navbar-nav .nav-link:hover,
#mainNav .navbar-nav .nav-link.active {
    background: rgba(255,255,255,0.15);
}

#mainNav .navbar-nav .nav-link .badge {
    font-size: 0.6rem;
    padding: 2px 5px;
    margin-left: 3px;
}

#mainNav .avatar-sm {
    width: 32px;
    height: 32px;
}

#mainNav .dropdown-menu {
    border: none;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    border-radius: 8px;
    margin-top: 5px;
}

#mainNav .dropdown-item {
    padding: 0.5rem 1rem;
    border-radius: 4px;
    margin: 2px 0;
}

#mainNav .dropdown-item:hover {
    background-color: #f8f9fa;
}

#mainNav .dropdown-divider {
    margin: 0.25rem 0;
}

/* Mobile sidebar backdrop */
.sidebar-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1040;
    display: none;
}

.sidebar-backdrop.show {
    display: block;
}

/* Notification dropdown styling */
#notificationDropdown {
    padding: 0;
}

.notification-item {
    white-space: normal;
    min-width: 300px;
}

.notification-item:hover {
    background-color: #f8f9fa;
}

.notification-item .smaller {
    font-size: 0.8rem;
}

/* Quick actions menu */
.dropdown-menu .dropdown-header {
    font-weight: 600;
    color: #495057;
    padding: 0.5rem 1rem;
}

/* Search box animation */
#mainNav .input-group {
    transition: all 0.3s;
}

#mainNav .input-group:focus-within {
    width: 250px;
}

/* Theme toggle button */
#themeToggleBtn {
    color: white;
    border: none;
    background: transparent;
    padding: 0.5rem;
}

#themeToggleBtn:hover {
    color: #ffc107;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    #mainNav .navbar-nav .nav-link span:not(.badge) {
        display: none;
    }
    
    #mainNav .navbar-nav .nav-link i {
        margin-right: 0;
    }
    
    #mainNav .navbar-brand .d-none.d-md-inline {
        display: none !important;
    }
    
    #mainNav .navbar-brand .d-md-none {
        display: inline !important;
    }
}

@media (max-width: 768px) {
    #mainNav .navbar-collapse {
        background: var(--primary);
        padding: 1rem;
        border-radius: 0 0 8px 8px;
        margin-top: 8px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    #mainNav .input-group {
        margin-bottom: 1rem;
    }
}
</style>