<?php
/**
 * footer.php
 * Common footer for all pages
 */
?>
                </div><!-- End of content-area -->
                
                <!-- Footer -->
                <footer class="footer mt-5 py-3 border-top no-print">
                    <div class="container-fluid">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <small class="text-muted">
                                    &copy; <?php echo date('Y'); ?> <?php echo ORGANIZATION_NAME; ?> 
                                    - <?php echo SITE_NAME; ?> v<?php echo SITE_VERSION; ?>
                                </small>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    <i class="fas fa-user"></i> 
                                    <?php 
                                    if (isset($currentUser)) {
                                        echo htmlspecialchars($currentUser['username']) . ' (' . strtoupper($currentUser['user_type']) . ')';
                                    } else {
                                        echo 'Not logged in';
                                    }
                                    ?>
                                    | <i class="fas fa-clock"></i> <?php echo date('H:i:s'); ?>
                                    | <i class="fas fa-server"></i> <?php echo MAIN_DB_NAME; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </footer>
            </main>
        </div>
    </div>
    
    <!-- Required JavaScript Libraries -->
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- DataTables -->
    <script type="text/javascript" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Custom JavaScript -->
    <script src="<?php echo SITE_URL; ?>assets/js/main.js"></script>
    
    <!-- Page-specific JavaScript -->
    <?php if (isset($pageJs)): ?>
    <script><?php echo $pageJs; ?></script>
    <?php endif; ?>
    
    <!-- Page-specific JavaScript files -->
    <?php if (isset($jsFiles) && is_array($jsFiles)): ?>
        <?php foreach ($jsFiles as $jsFile): ?>
        <script src="<?php echo htmlspecialchars($jsFile); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Inline JavaScript -->
    <script>
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize popovers
            var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
            
            // Sidebar toggle
            document.getElementById('sidebarToggle')?.addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('show');
            });
            
            // Theme toggle
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    const currentTheme = document.documentElement.getAttribute('data-bs-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    
                    document.documentElement.setAttribute('data-bs-theme', newTheme);
                    themeIcon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
                    
                    // Save preference to localStorage
                    localStorage.setItem('theme', newTheme);
                });
                
                // Load saved theme
                const savedTheme = localStorage.getItem('theme') || 'light';
                document.documentElement.setAttribute('data-bs-theme', savedTheme);
                themeIcon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            }
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                var alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
                alerts.forEach(function(alert) {
                    var bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Handle AJAX CSRF token
            $.ajaxSetup({
                headers: {
                    'X-CSRF-Token': CSRF_TOKEN
                }
            });
            
            // Global error handling for AJAX
            $(document).ajaxError(function(event, jqxhr, settings, thrownError) {
                if (jqxhr.status === 401) {
                    // Unauthorized - redirect to login
                    window.location.href = SITE_URL + 'login.php?session_expired=1';
                } else if (jqxhr.status === 403) {
                    // Forbidden
                    alert('You do not have permission to perform this action.');
                } else if (jqxhr.status === 500) {
                    // Server error
                    alert('Server error occurred. Please try again.');
                }
            });
            
            // Loading overlay
            $(document).ajaxStart(function() {
                $('#loading-overlay').fadeIn();
            }).ajaxStop(function() {
                $('#loading-overlay').fadeOut();
            });
            
            // Load notifications
            if (CURRENT_USER) {
                loadNotifications();
                // Refresh notifications every 60 seconds
                setInterval(loadNotifications, 60000);
            }
        });
        
        // Notification functions
        function loadNotifications() {
            $.ajax({
                url: SITE_URL + 'ajax/get_notifications.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        updateNotificationUI(response.data);
                    }
                }
            });
        }
        
        function updateNotificationUI(data) {
            const count = data.unread_count || 0;
            const notifications = data.notifications || [];
            
            // Update badge count
            $('#notificationCount').text(count).toggle(count > 0);
            
            // Update notification list
            const list = $('#notificationList');
            if (notifications.length === 0) {
                list.html('<div class="text-center py-3"><small class="text-muted">No notifications</small></div>');
            } else {
                let html = '';
                notifications.forEach(function(notif) {
                    html += `
                        <a href="#" class="dropdown-item notification-item ${notif.read ? '' : 'fw-bold'}" data-id="${notif.id}">
                            <div class="small">${notif.title}</div>
                            <div class="text-muted smaller">${notif.message}</div>
                            <div class="text-muted smaller">${notif.time_ago}</div>
                        </a>
                        <div class="dropdown-divider"></div>
                    `;
                });
                list.html(html);
            }
        }
        
        // Mark notification as read
        $(document).on('click', '.notification-item', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            
            $.ajax({
                url: SITE_URL + 'ajax/mark_notification_read.php',
                method: 'POST',
                data: { id: id },
                success: function() {
                    loadNotifications();
                }
            });
        });
        
        // Mark all notifications as read
        $('#markAllRead').click(function(e) {
            e.preventDefault();
            
            $.ajax({
                url: SITE_URL + 'ajax/mark_all_notifications_read.php',
                method: 'POST',
                success: function() {
                    loadNotifications();
                }
            });
        });
        
        // Form validation helper
        function validateForm(formId) {
            const form = document.getElementById(formId);
            if (!form) return true;
            
            let isValid = true;
            const requiredFields = form.querySelectorAll('[required]');
            
            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            return isValid;
        }
        
        // Confirm before action
        function confirmAction(message, callback) {
            if (confirm(message)) {
                if (typeof callback === 'function') {
                    callback();
                }
                return true;
            }
            return false;
        }
        
        // Copy to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                alert('Copied to clipboard!');
            }).catch(function(err) {
                console.error('Failed to copy: ', err);
            });
        }
        
        // Format date for display
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
    </script>
    
    <!-- Google Analytics (optional) -->
    <?php if (defined('GOOGLE_ANALYTICS_ID') && GOOGLE_ANALYTICS_ID): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo GOOGLE_ANALYTICS_ID; ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?php echo GOOGLE_ANALYTICS_ID; ?>');
    </script>
    <?php endif; ?>
    
</body>
</html>
<?php
// End output buffering and flush
ob_end_flush();
?>