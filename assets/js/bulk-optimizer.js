/* Bulk Optimization Queue Controller in Persian */

(function ($) {
    'use strict';

    var isProcessing = false;
    var totalItems = 0;
    var processedItems = 0;

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

        $.post(wsoData.ajax_url, {
            action: 'wso_build_queue',
            nonce: wsoData.nonce
        }).done(function (response) {
            if (response.success) {
                totalItems = response.data.count;
                processedItems = 0;
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
                addLogEntry('ایجاد صف با خطا مواجه شد.', 'error');
                stopProcessing();
            }
        }).fail(function () {
            addLogEntry('خطای ارتباط با سرور در ایجاد صف.', 'error');
            stopProcessing();
        });
    }

    function processNextBatch() {
        if (!isProcessing) return;

        $.post(wsoData.ajax_url, {
            action: 'wso_process_batch',
            nonce: wsoData.nonce,
            batch_size: 5
        }).done(function (response) {
            if (response.success) {
                if (response.data.completed) {
                    addLogEntry('عملیات بهینه‌سازی همگانی با موفقیت به پایان رسید!', 'success');
                    stopProcessing();
                    return;
                }

                processedItems += response.data.processed;
                updateProgressBar(processedItems, totalItems);

                $.each(response.data.results, function (i, item) {
                    var type = item.status === 'success' ? 'success' : 'error';
                    addLogEntry('[' + item.file + '] ' + item.message, type);
                });

                if (isProcessing) {
                    setTimeout(processNextBatch, 300);
                }
            } else {
                addLogEntry('خطایی در پردازش دسته رخ داد.', 'error');
                stopProcessing();
            }
        }).fail(function () {
            addLogEntry('اختلال در ارتباط شبکه؛ تلاش مجدد...', 'warning');
            setTimeout(processNextBatch, 2000);
        });
    }

    function pauseBulkOptimization() {
        isProcessing = false;
        $('#wso-start-bulk').show().find('span').text('ادامه بهینه‌سازی');
        $('#wso-pause-bulk').hide();
        addLogEntry('عملیات صف توسط کاربر متوقف شد.', 'warning');
    }

    function resetBulkQueue() {
        isProcessing = false;
        $.post(wsoData.ajax_url, {
            action: 'wso_reset_queue',
            nonce: wsoData.nonce
        }).done(function (res) {
            addLogEntry('صف بهینه‌سازی با موفقیت بازنشانی شد.', 'info');
            stopProcessing();
            updateProgressBar(0, 0);
        });
    }

    function stopProcessing() {
        isProcessing = false;
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
        var entry = '<div class="wso-log-entry ' + (type || 'info') + '">[' + new Date().toLocaleTimeString() + '] ' + msg + '</div>';
        $feed.append(entry);
        $feed.scrollTop($feed[0].scrollHeight);
    }

})(jQuery);
