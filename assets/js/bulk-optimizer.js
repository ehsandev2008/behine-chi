/* Bulk Optimization Queue Controller in Persian */

(function ($) {
    'use strict';

    var isProcessing = false;
    var totalItems = 0;
    var processedItems = 0;
    var consecutiveFailures = 0;
    var maxFailures = 8;
    var currentRequest = null;

    $(document).ready(function () {
        $('#wso-start-bulk').on('click', startBulkOptimization);
        $('#wso-pause-bulk').on('click', pauseBulkOptimization);
        $('#wso-reset-bulk').on('click', resetBulkQueue);
    });

    function startBulkOptimization() {
        if (isProcessing) return;

        $('#wso-start-bulk').hide();
        $('#wso-pause-bulk').show();
        $('#wso-progress-box').show();

        addLogEntry('در حال ایجاد صف تصاویر جهت بهینه‌سازی...', 'info');

        $.ajax({
            url: wsoData.ajax_url,
            method: 'POST',
            data: {
                action: 'wso_build_queue',
                nonce: wsoData.nonce
            },
            timeout: 60000
        }).done(function (response) {
            if (response.success) {
                totalItems = parseInt(response.data.total_pending || response.data.count || 0, 10) || 0;
                processedItems = 0;
                consecutiveFailures = 0;
                updateProgressBar(0, totalItems);

                if (totalItems === 0) {
                    addLogEntry('هیچ تصویر جدیدی جهت بهینه‌سازی یافت نشد.', 'warning');
                    stopProcessing();
                    return;
                }

                addLogEntry('تعداد ' + totalItems + ' تصویر در صف قرار گرفت. شروع بهینه‌سازی دسته‌ای...', 'success');
                isProcessing = true;
                processNextBatch();
            } else {
                addLogEntry('ایجاد صف با خطا مواجه شد: ' + ((response.data && response.data.message) || ''), 'error');
                stopProcessing();
            }
        }).fail(function (jqXHR, textStatus) {
            addLogEntry('خطای ارتباط با سرور در ایجاد صف (' + textStatus + ').', 'error');
            stopProcessing();
        });
    }

    function processNextBatch() {
        if (!isProcessing) return;

        currentRequest = $.ajax({
            url: wsoData.ajax_url,
            method: 'POST',
            data: {
                action: 'wso_process_batch',
                nonce: wsoData.nonce,
                batch_size: 3
            },
            timeout: 120000
        }).done(function (response) {
            consecutiveFailures = 0;
            if (response.success) {
                if (response.data.completed) {
                    // Sync final counts from server stats when available.
                    if (response.data.stats) {
                        var s = response.data.stats;
                        var done = (s.completed || 0) + (s.failed || 0) + (s.skipped || 0);
                        if (done > 0) {
                            processedItems = done;
                            totalItems = Math.max(totalItems, s.total || done);
                            updateProgressBar(processedItems, totalItems);
                        }
                    }
                    addLogEntry('عملیات بهینه‌سازی همگانی با موفقیت به پایان رسید!', 'success');
                    stopProcessing();
                    return;
                }

                processedItems += (response.data.processed || 0);
                // Server stats are authoritative for the total.
                if (response.data.stats && response.data.stats.total) {
                    totalItems = Math.max(totalItems, response.data.stats.total);
                }
                updateProgressBar(processedItems, totalItems);

                $.each(response.data.results || [], function (i, item) {
                    var type = (item.status === 'success' || item.status === 'completed' || item.status === 'skipped') ? 'success' : 'error';
                    addLogEntry('[' + item.file + '] ' + item.message, type);
                });

                if (isProcessing) {
                    setTimeout(processNextBatch, 400);
                }
            } else {
                consecutiveFailures++;
                addLogEntry('خطایی در پردازش دسته رخ داد: ' + ((response.data && response.data.message) || ''), 'error');
                if (consecutiveFailures >= maxFailures) {
                    addLogEntry('تعداد خطاهای پیاپی زیاد شد؛ عملیات متوقف شد. لطفا دوباره تلاش کنید.', 'error');
                    stopProcessing();
                    return;
                }
                if (isProcessing) {
                    setTimeout(processNextBatch, 2000);
                }
            }
        }).fail(function (jqXHR, textStatus) {
            if (!isProcessing) return;
            consecutiveFailures++;
            if (consecutiveFailures >= maxFailures) {
                addLogEntry('ارتباط با سرور قطع شد و تلاش مجدد بی‌نتیجه ماند. عملیات متوقف شد.', 'error');
                stopProcessing();
                return;
            }
            var wait = Math.min(15000, 2000 * consecutiveFailures);
            addLogEntry('اختلال در ارتباط شبکه؛ تلاش مجدد (' + consecutiveFailures + '/' + maxFailures + ')...', 'warning');
            setTimeout(processNextBatch, wait);
        });
    }

    function pauseBulkOptimization() {
        isProcessing = false;
        if (currentRequest) {
            try { currentRequest.abort(); } catch (e) {}
            currentRequest = null;
        }
        $('#wso-start-bulk').show().find('span').text('ادامه بهینه‌سازی');
        $('#wso-pause-bulk').hide();
        addLogEntry('عملیات صف توسط کاربر متوقف شد.', 'warning');
    }

    function resetBulkQueue() {
        isProcessing = false;
        if (currentRequest) {
            try { currentRequest.abort(); } catch (e) {}
            currentRequest = null;
        }
        $.post(wsoData.ajax_url, {
            action: 'wso_reset_queue',
            nonce: wsoData.nonce
        }).done(function (res) {
            addLogEntry('صف بهینه‌سازی با موفقیت بازنشانی شد.', 'info');
            processedItems = 0;
            totalItems = 0;
            consecutiveFailures = 0;
            stopProcessing();
            updateProgressBar(0, 0);
        }).fail(function () {
            addLogEntry('بازنشانی صف با خطای شبکه مواجه شد.', 'error');
        });
    }

    function stopProcessing() {
        isProcessing = false;
        currentRequest = null;
        $('#wso-start-bulk').show().find('span').text('شروع بهینه‌سازی همگانی');
        $('#wso-pause-bulk').hide();
    }

    function updateProgressBar(processed, total) {
        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
        $('#wso-progress-fill').css('width', pct + '%');
        $('#wso-progress-text').text(processed + ' / ' + total);
        $('#wso-progress-percent').text('٪' + pct);
    }

    function addLogEntry(msg, type) {
        var $feed = $('#wso-log-feed');
        if (!$feed.length) return;
        var safe = String(msg).replace(/[<>&"']/g, function (c) {
            return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#039;' }[c];
        });
        var entry = '<div class="wso-log-entry ' + (type || 'info') + '">[' + new Date().toLocaleTimeString() + '] ' + safe + '</div>';
        $feed.append(entry);
        try { $feed.scrollTop($feed[0].scrollHeight); } catch (e) {}
    }

})(jQuery);
