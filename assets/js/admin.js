/* Admin JavaScript Controller for WebP Smart Optimizer Pro */

(function ($) {
    'use strict';

    $(document).ready(function () {
        initThemeToggle();
        initTabs();
        initSettingsSearch();
        initFormSubmission();
        initLogsTab();
        initToolsAndImport();
        initBackupRestore();
        initCustomizer();
        initFontPreview();
        initHealthCheck();
        initNotificationsCenter();
        
        // Expose helper functions globally
        window.wsoEscapeHtml = escapeHtml;
        window.wsoToast = showToast;
        window.wsoConfirm = showConfirm;
        
        // Load initial notifications counts
        loadNotifications();
    });

    // HTML Entity escaping helper to prevent XSS injection
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Dark/Light Mode Theme Controller
    function initThemeToggle() {
        var $btn = $('#wso-theme-toggle');
        if (!$btn.length) return;

        // Restore user's local preference if previously saved
        var localTheme = localStorage.getItem('wso_dark_mode');
        if (localTheme !== null) {
            if (localTheme === '1') {
                $('#wso-app').addClass('wso-dark-mode');
                $('#wso_dark_mode_input').val('1');
            } else {
                $('#wso-app').removeClass('wso-dark-mode');
                $('#wso_dark_mode_input').val('0');
            }
        }

        $btn.on('click', function (e) {
            e.preventDefault();
            var $app = $('#wso-app');
            var isDark = $app.hasClass('wso-dark-mode');
            if (isDark) {
                $app.removeClass('wso-dark-mode');
                $('#wso_dark_mode_input').val('0');
                localStorage.setItem('wso_dark_mode', '0');
            } else {
                $app.addClass('wso-dark-mode');
                $('#wso_dark_mode_input').val('1');
                localStorage.setItem('wso_dark_mode', '1');
            }
        });
    }

    // Custom Modal Confirm overlay dialog replacement
    function showConfirm(title, message, callback) {
        var $modal = $('#wso-confirm-modal');
        if (!$modal.length) {
            // Fallback to native confirm if modal template isn't present
            var ok = confirm(message);
            callback(ok);
            return;
        }

        $('#wso-confirm-title').text(title);
        $('#wso-confirm-message').text(message);
        $modal.fadeIn(150);

        $('#wso-confirm-yes').off('click').on('click', function () {
            $modal.fadeOut(150);
            callback(true);
        });

        $('#wso-confirm-no').off('click').on('click', function () {
            $modal.fadeOut(150);
            callback(false);
        });
    }

    // Custom Toast Alert notification system
    function showToast(message, type) {
        type = type || 'info';
        var $container = $('#wso-toast-container');
        if (!$container.length) {
            alert(message);
            return;
        }

        var id = 'toast-' + Math.random().toString(36).substr(2, 9);
        var icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
        
        if (type === 'success') {
            icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        } else if (type === 'error') {
            icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
        } else if (type === 'warning') {
            icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
        }

        var html = '<div id="' + id + '" class="wso-toast ' + escapeHtml(type) + '">' +
            '<div class="wso-toast-icon">' + icon + '</div>' +
            '<div class="wso-toast-message">' + escapeHtml(message) + '</div>' +
            '</div>';

        $container.append(html);

        setTimeout(function () {
            var $toast = $('#' + id);
            $toast.addClass('hide');
            setTimeout(function () {
                $toast.remove();
            }, 300);
        }, 4000);
    }

    // Dynamic Live Color & Style Customizer preview
    function initCustomizer() {
        $('.wso-color-picker, .wso-input-small, .wso-input-medium').on('input change', function () {
            var id = $(this).attr('id');
            var val = $(this).val();
            var $app = $('#wso-app');

            if (id === 'wso_color_primary') { $app.css('--wso-primary', val); }
            if (id === 'wso_color_primary_hover') { $app.css('--wso-primary-hover', val); }
            if (id === 'wso_color_secondary') { $app.css('--wso-secondary', val); }
            if (id === 'wso_color_secondary_text') { $app.css('--wso-secondary-text', val); }
            if (id === 'wso_color_success') { $app.css('--wso-success', val); }
            if (id === 'wso_color_warning') { $app.css('--wso-warning', val); }
            if (id === 'wso_color_error') { $app.css('--wso-danger', val); }

            if (!$app.hasClass('wso-dark-mode')) {
                if (id === 'wso_color_bg') { $app.css('--wso-bg-main', val); }
                if (id === 'wso_color_card') { $app.css('--wso-bg-card', val); $app.css('--wso-bg-header', val); }
            } else {
                if (id === 'wso_color_bg_dark') { $app.css('--wso-bg-main', val); }
                if (id === 'wso_color_card_dark') { $app.css('--wso-bg-card', val); $app.css('--wso-bg-header', val); }
            }

            if (id === 'wso_border_radius') { $app.css('--wso-radius', val); }
            if (id === 'wso_font_size') { $app.css('--wso-font-size', val); }
            if (id === 'wso_spacing') { $app.css('--wso-spacing', val); }
            if (id === 'wso_shadow') { $app.css('--wso-card-shadow', val); }
        });
    }

    // Dynamic Font preview without page reload
    function initFontPreview() {
        $('#wso_admin_font').on('change', function () {
            var family = $(this).val();
            $('#wso-font-preview-box').css('font-family', "'" + family + "', sans-serif");
            $('#wso-app').css('--wso-font-family', "'" + family + "', sans-serif");
        });
    }

    // Health Check dashboard validation & actions
    function initHealthCheck() {
        loadHealthCheck();

        $('#wso-btn-recheck-health').on('click', function (e) {
            e.preventDefault();
            loadHealthCheck();
        });

        $(document).on('click', '.wso-btn-fix', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var action = $btn.data('action');
            var originalText = $btn.text();

            showConfirm('تایید فرآیند خودکار', 'آیا مایلید عملیات اصلاح و بازسازی خودکار را آغاز کنید؟', function (confirmed) {
                if (confirmed) {
                    $btn.prop('disabled', true).text('در حال تعمیر...');
                    $.post(wsoData.ajax_url, {
                        action: 'wso_run_health_fix',
                        nonce: wsoData.nonce,
                        fix_action: action
                    }).done(function (res) {
                        if (res.success) {
                            showToast(res.data.message, 'success');
                            loadHealthCheck();
                            loadNotifications();
                        } else {
                            showToast(res.data.message, 'error');
                        }
                        $btn.prop('disabled', false).text(originalText);
                    }).fail(function () {
                        showToast(wsoData.i18n.error, 'error');
                        $btn.prop('disabled', false).text(originalText);
                    });
                }
            });
        });
    }

    function loadHealthCheck() {
        var $report = $('#wso-health-report-box');
        if (!$report.length) return;

        $report.html('<div class="wso-loading-skeleton" style="height:200px; border-radius:8px;"></div>');

        $.post(wsoData.ajax_url, {
            action: 'wso_run_health_check',
            nonce: wsoData.nonce
        }).done(function (res) {
            if (res.success) {
                var score = res.data.score;
                var status = res.data.status;
                var status_text = res.data.status_text;

                $('#wso-health-score-num').text(score + '%');
                $('#wso-health-score-label').text('وضعیت: ' + status_text);

                // Update radial progress SVG dasharray
                $('#wso-health-circle').attr('stroke-dasharray', score + ', 100');

                var colorClass = 'wso-badge-success';
                var strokeColor = 'var(--wso-success)';
                if (status === 'error') {
                    colorClass = 'wso-badge-danger';
                    strokeColor = 'var(--wso-danger)';
                } else if (status === 'warning') {
                    colorClass = 'wso-badge-warning';
                    strokeColor = 'var(--wso-warning)';
                } else if (status === 'info') {
                    colorClass = 'wso-badge-primary';
                    strokeColor = 'var(--wso-primary)';
                }

                $('#wso-health-score-label').removeClass('wso-badge-success wso-badge-warning wso-badge-danger wso-badge-primary').addClass(colorClass);
                $('#wso-health-circle').attr('stroke', strokeColor);

                // Render dynamic checks elements
                var html = '';
                var catMapping = {
                    'server': 'خصوصیات سرور و فایل‌ها',
                    'extensions': 'افزونه‌های ضروری PHP',
                    'formats': 'وضعیت فشرده‌سازی فرمت‌ها',
                    'wordpress': 'تنظیمات بستر وردپرس',
                    'integrity': 'سلامت ساختار و پوشه‌ها',
                    'engine': 'وضعیت ماژول‌های بهینه‌ساز'
                };

                $.each(catMapping, function (catKey, catLabel) {
                    html += '<div class="wso-health-group-title">' + catLabel + '</div>';
                    html += '<div class="wso-grid wso-grid-2">';

                    $.each(res.data[catKey], function (checkKey, check) {
                        var statusBadge = '';
                        if (check.status === 'success') {
                            statusBadge = '<span class="wso-badge wso-badge-success">سالم</span>';
                        } else if (check.status === 'warning') {
                            statusBadge = '<span class="wso-badge wso-badge-warning">هشدار</span>';
                        } else if (check.status === 'error') {
                            statusBadge = '<span class="wso-badge wso-badge-danger">خطا</span>';
                        }

                        html += '<div class="wso-health-item">' +
                            '<div class="wso-health-item-left">' +
                                '<span class="wso-health-item-label">' + check.label + ': <strong style="color:var(--wso-primary);">' + check.value + '</strong></span>' +
                                '<span class="wso-health-item-desc">' + check.desc + '</span>' +
                            '</div>' +
                            '<div class="wso-health-item-right">' + statusBadge + '</div>' +
                            '</div>';
                    });

                    html += '</div>';
                });

                $report.html(html);
            } else {
                $report.html('<div class="wso-text-center py-4">' + wsoData.i18n.error + '</div>');
            }
        });
    }

    // Notifications Center interactions and lists fetching
    function initNotificationsCenter() {
        $('#wso-notify-filter-type, #wso-notify-search').on('change keyup', function () {
            loadNotifications();
        });

        $('#wso-btn-notify-mark-read').on('click', function (e) {
            e.preventDefault();
            $.post(wsoData.ajax_url, {
                action: 'wso_mark_all_notifications_read',
                nonce: wsoData.nonce
            }).done(function (res) {
                if (res.success) {
                    loadNotifications();
                    showToast(res.data.message, 'success');
                }
            });
        });

        $('#wso-btn-notify-clear').on('click', function (e) {
            e.preventDefault();
            showConfirm('پاکسازی اعلان‌ها', 'آیا از حذف کامل تاریخچه اعلان‌ها اطمینان دارید؟', function (confirmed) {
                if (confirmed) {
                    $.post(wsoData.ajax_url, {
                        action: 'wso_clear_notifications',
                        nonce: wsoData.nonce
                    }).done(function (res) {
                        if (res.success) {
                            loadNotifications();
                            showToast(res.data.message, 'success');
                        }
                    });
                }
            });
        });

        $(document).on('click', '.wso-btn-notify-dismiss', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            $.post(wsoData.ajax_url, {
                action: 'wso_mark_notification_read',
                nonce: wsoData.nonce,
                id: id
            }).done(function () {
                loadNotifications();
            });
        });
    }

    function loadNotifications() {
        var $feed = $('#wso-notifications-feed');
        if (!$feed.length) return;

        var type = $('#wso-notify-filter-type').val();
        var search = $('#wso-notify-search').val();

        $.post(wsoData.ajax_url, {
            action: 'wso_get_notifications',
            nonce: wsoData.nonce,
            type: type,
            search: search
        }).done(function (res) {
            if (res.success) {
                // Update badge triggers
                var count = res.data.unread_count;
                if (count > 0) {
                    $('#wso-badge-count').text(count).show();
                    $('.ab-item-notification-badge').text(count).show();
                } else {
                    $('#wso-badge-count').hide();
                    $('.ab-item-notification-badge').hide();
                }

                if (res.data.notifications.length > 0) {
                    var html = '';
                    $.each(res.data.notifications, function (i, item) {
                        var unreadClass = item.is_read == 0 ? 'unread' : '';
                        var dismissBtn = item.is_read == 0 ? '<button type="button" class="wso-btn-notify-dismiss" data-id="' + item.id + '" title="علامت به عنوان خوانده شده"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg></button>' : '';

                        var icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
                        if (item.type === 'success') {
                            icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                        } else if (item.type === 'error') {
                            icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
                        } else if (item.type === 'warning') {
                            icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
                        }

                        html += '<div class="wso-notification-card ' + unreadClass + ' ' + escapeHtml(item.type) + '">' +
                            '<div class="wso-notification-icon">' + icon + '</div>' +
                            '<div class="wso-notification-body">' +
                                '<p class="wso-notification-text">' + escapeHtml(item.message) + '</p>' +
                                '<span class="wso-notification-time">' + escapeHtml(item.created_at) + '</span>' +
                            '</div>' +
                            dismissBtn +
                            '</div>';
                    });
                    $feed.html(html);
                } else {
                    $feed.html('<div class="wso-text-center py-4">هیچ اعلانی ثبت نشده است.</div>');
                }
            }
        });
    }

    // Tab Navigation
    function initTabs() {
        $('.wso-nav-item').on('click', function (e) {
            e.preventDefault();
            var targetTab = $(this).data('tab');
            $('.wso-nav-item').removeClass('active');
            $(this).addClass('active');

            $('.wso-tab-pane').removeClass('active');
            $('#' + targetTab).addClass('active');

            if (targetTab === 'tab-logs') {
                loadLogs(1);
            }
        });
        
        // Handle URL hash mapping tabs directly
        var hash = window.location.hash;
        if (hash) {
            var cleanHash = hash.replace('#', '');
            var $item = $('.wso-nav-item[data-tab="' + cleanHash + '"]');
            if ($item.length) {
                $item.click();
            }
        }
    }

    // Live Settings Search
    function initSettingsSearch() {
        $('#wso-settings-search').on('keyup', function () {
            var query = $(this).val().toLowerCase();
            if (query.length === 0) {
                $('.wso-card, .wso-form-group, .wso-field-row').show();
                return;
            }

            $('.wso-tab-pane.active .wso-card').each(function () {
                var text = $(this).text().toLowerCase();
                if (text.indexOf(query) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });
    }

    // Save Settings via AJAX
    function initFormSubmission() {
        $('#wso-save-btn').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var originalText = $btn.find('span').text();

            $btn.prop('disabled', true).find('span').text(wsoData.i18n.saving);

            var formData = $('#wso-settings-form').serialize();

            $.post(wsoData.ajax_url, {
                action: 'wso_save_settings',
                nonce: wsoData.nonce,
                settings: formData
            }).done(function (response) {
                if (response.success) {
                    showToast(response.data.message || wsoData.i18n.saved, 'success');
                    loadNotifications();
                    setTimeout(function () {
                        $btn.prop('disabled', false).find('span').text(originalText);
                    }, 1000);
                } else {
                    showToast(response.data.message || wsoData.i18n.error, 'error');
                    $btn.prop('disabled', false).find('span').text(originalText);
                }
            }).fail(function () {
                showToast(wsoData.i18n.error, 'error');
                $btn.prop('disabled', false).find('span').text(originalText);
            });
        });
    }

    // Optimization Logs Loader
    function initLogsTab() {
        $('#wso-log-filter-status, #wso-log-search').on('change keyup', function () {
            loadLogs(1);
        });

        $('#wso-clear-logs-btn').on('click', function (e) {
            e.preventDefault();
            showConfirm('پاکسازی گزارشات', wsoData.i18n.confirm_clear_logs, function (confirmed) {
                if (confirmed) {
                    $.post(wsoData.ajax_url, {
                        action: 'wso_clear_logs',
                        nonce: wsoData.nonce
                    }).done(function (res) {
                        loadLogs(1);
                        showToast(res.data.message, 'success');
                    });
                }
            });
        });
    }

    function formatBytes(bytes) {
        var b = parseInt(bytes, 10);
        if (isNaN(b) || b <= 0) return '۰ بایت';
        if (b >= 1048576) {
            return (b / 1048576).toFixed(2) + ' مگابایت';
        }
        if (b >= 1024) {
            return (b / 1024).toFixed(1) + ' کیلوبایت';
        }
        return b + ' بایت';
    }

    function loadLogs(page) {
        var $body = $('#wso-logs-table-body');
        if (!$body.length) return;

        var status = $('#wso-log-filter-status').val();
        var search = $('#wso-log-search').val();

        $body.html('<tr><td colspan="8" class="wso-text-center">در حال دریافت اطلاعات گزارش‌ها...</td></tr>');

        $.post(wsoData.ajax_url, {
            action: 'wso_get_logs',
            nonce: wsoData.nonce,
            page: page,
            status: status,
            search: search
        }).done(function (response) {
            if (response.success && response.data.logs.length > 0) {
                var html = '';
                $.each(response.data.logs, function (i, log) {
                    var badgeClass = 'wso-badge-neutral';
                    var statusText = log.status;

                    if (log.status === 'success') { badgeClass = 'wso-badge-success'; statusText = 'موفقیت‌آمیز'; }
                    if (log.status === 'warning') { badgeClass = 'wso-badge-warning'; statusText = 'هشدار'; }
                    if (log.status === 'error') { badgeClass = 'wso-badge-danger'; statusText = 'خطا'; }
                    if (log.status === 'skipped') { badgeClass = 'wso-badge-neutral'; statusText = 'نادیده گرفته‌شده'; }

                    html += '<tr>' +
                        '<td>' + parseInt(log.id, 10) + '</td>' +
                        '<td><strong>' + escapeHtml(log.file_name) + '</strong></td>' +
                        '<td>' + formatBytes(log.original_size) + '</td>' +
                        '<td>' + formatBytes(log.optimized_size) + '</td>' +
                        '<td>٪' + escapeHtml(log.savings_percent || 0) + '</td>' +
                        '<td><span class="wso-badge ' + badgeClass + '">' + escapeHtml(statusText) + '</span></td>' +
                        '<td>' + escapeHtml(log.message) + '</td>' +
                        '<td>' + escapeHtml(log.created_at) + '</td>' +
                        '</tr>';
                });
                $body.html(html);
            } else {
                $body.html('<tr><td colspan="8" class="wso-text-center">هیچ گزارشی یافت نشد.</td></tr>');
            }
        });
    }

    // Tools & Export/Import
    function initToolsAndImport() {
        $('#wso-btn-clear-cache').on('click', function (e) {
            e.preventDefault();
            $.post(wsoData.ajax_url, { action: 'wso_clear_generated_cache', nonce: wsoData.nonce }).done(function (res) {
                showToast(res.data.message, 'success');
                loadNotifications();
            });
        });

        $('#wso-btn-clear-backups').on('click', function (e) {
            e.preventDefault();
            showConfirm('پاکسازی پشتیبان‌ها', 'آیا مایلید تمام نسخه‌های پشتیبان اصلی را برای همیشه حذف کنید؟ این عمل غیرقابل بازگشت است.', function (confirmed) {
                if (confirmed) {
                    $.post(wsoData.ajax_url, { action: 'wso_clear_backups', nonce: wsoData.nonce }).done(function (res) {
                        showToast(res.data.message, 'success');
                        loadNotifications();
                    });
                }
            });
        });

        $('#wso-btn-export-settings').on('click', function (e) {
            e.preventDefault();
            $.post(wsoData.ajax_url, { action: 'wso_export_settings', nonce: wsoData.nonce }).done(function (res) {
                if (res.success) {
                    var blob = new Blob([res.data.json], { type: 'application/json' });
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = res.data.filename;
                    link.click();
                    showToast('فایل تنظیمات با موفقیت آماده و دانلود شد.', 'success');
                }
            });
        });

        $('#wso-btn-import-settings').on('click', function (e) {
            e.preventDefault();
            var json = $('#wso-import-json-text').val();
            if (!json) return;
            $.post(wsoData.ajax_url, { action: 'wso_import_settings', nonce: wsoData.nonce, json_data: json }).done(function (res) {
                if (res.success) {
                    showToast(res.data.message, 'success');
                    loadNotifications();
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                } else {
                    showToast(res.data.message, 'error');
                }
            });
        });

        $('#wso-btn-reset-settings').on('click', function (e) {
            e.preventDefault();
            showConfirm('تنظیمات کارخانه', wsoData.i18n.confirm_reset, function (confirmed) {
                if (confirmed) {
                    $.post(wsoData.ajax_url, { action: 'wso_reset_settings', nonce: wsoData.nonce }).done(function (res) {
                        if (res.success) {
                            showToast(res.data.message, 'success');
                            loadNotifications();
                            setTimeout(function () {
                                location.reload();
                            }, 1000);
                        }
                    });
                }
            });
        });
    }

    // Backup Restore All
    function initBackupRestore() {
        $('#wso-btn-restore-all-backups').on('click', function (e) {
            e.preventDefault();
            showConfirm('بازگردانی کلی تصاویر', wsoData.i18n.confirm_restore_all, function (confirmed) {
                if (confirmed) {
                    var $btn = $(this);
                    $btn.prop('disabled', true).find('span').text(wsoData.i18n.restoring_all);

                    $.post(wsoData.ajax_url, {
                        action: 'wso_restore_all_backups',
                        nonce: wsoData.nonce
                    }).done(function (res) {
                        if (res.success) {
                            showToast(res.data.message, 'success');
                            loadNotifications();
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        } else {
                            showToast(res.data.message, 'error');
                            $btn.prop('disabled', false).find('span').text('بازگردانی تمام تصاویر به حالت اولیه');
                        }
                    }).fail(function () {
                        showToast(wsoData.i18n.error, 'error');
                        $btn.prop('disabled', false).find('span').text('بازگردانی تمام تصاویر به حالت اولیه');
                    });
                }
            });
        });
    }

})(jQuery);
