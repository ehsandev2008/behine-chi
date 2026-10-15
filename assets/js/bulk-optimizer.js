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
        $('#wso-estimate-bulk').on('click', estimateBulk);
        $('#wso-start-convert').on('click', startConvertFromTab);
        // Segmented controls (conversion tab) <-> hidden selects sync.
        $(document).on('change', '#wso-bulk-mode-seg input', function () {
            $('#wso-bulk-mode-seg .wso-seg-opt').removeClass('active');
            $(this).closest('.wso-seg-opt').addClass('active');
            $('#wso-bulk-mode').val(this.value).trigger('change');
            updateBulkHint();
        });
        $(document).on('change', '#wso-bulk-format-seg input', function () {
            $('#wso-bulk-format-seg .wso-seg-opt').removeClass('active');
            $(this).closest('.wso-seg-opt').addClass('active');
            $('#wso-bulk-format').val(this.value);
            updateBulkHint();
        });
        $(document).on('input change', '#wso-bulk-quality-range', function () {
            $('#wso-bulk-quality').val(this.value);
            $('#wso-bulk-quality-val').text(this.value);
        });
        $('#wso-bulk-mode').on('change', updateBulkHint);
        $('#wso-bulk-format').on('change', updateBulkHint);
        updateBulkHint();
    });

    function updateBulkHint() {
        var mode = getBulkMode();
        var fmt = getTargetFormat().toUpperCase();
        var $h = $('#wso-bulk-mode-hint');
        if (!$h.length) return;
        $h.text(mode === 'convert' ? ('حالت فعلی: تبدیل به ' + fmt) : 'حالت فعلی: بهینه‌سازی');
    }

    function startConvertFromTab(e) {
        if (e) e.preventDefault();
        // Force convert mode, then jump to bulk tab for live progress.
        $('#wso-bulk-mode').val('convert');
        $('#wso-bulk-mode-seg input[value="convert"]').prop('checked', true);
        $('#wso-bulk-mode-seg .wso-seg-opt').removeClass('active');
        $('#wso-bulk-mode-seg input:checked').closest('.wso-seg-opt').addClass('active');
        updateBulkHint();
        $('a[data-tab=tab-bulk]').click();
        setTimeout(startBulkOptimization, 150);
    }

    function estimateBulk() {
        var $box = $('#wso-estimate-box');
        $box.text('در حال محاسبه تخمین...');
        $.post(wsoData.ajax_url, { action: 'wso_estimate_bulk', nonce: wsoData.nonce }).done(function (res) {
            $box.text(res.success ? res.data.message : 'خطا در تخمین');
        }).fail(function () { $box.text('خطای شبکه'); });
    }

    function getBulkMode() {
        var mode = $('#wso-bulk-mode').length ? $('#wso-bulk-mode').val() : 'optimize';
        return (mode === 'convert') ? 'convert' : 'optimize';
    }

    function getTargetFormat() {
        var fmt = $('#wso-bulk-format').length ? $('#wso-bulk-format').val() : 'webp';
        fmt = String(fmt).toLowerCase();
        return (fmt === 'avif') ? 'avif' : 'webp';
    }

    function getBulkQuality() {
        var q = $('#wso-bulk-quality').length ? parseInt($('#wso-bulk-quality').val(), 10) : 82;
        if (isNaN(q)) q = 82;
        return Math.max(10, Math.min(100, q));
    }

    function startBulkOptimization() {
        if (isProcessing) return;

        var bulkMode = getBulkMode();
        var targetFormat = getTargetFormat();
        var quality = getBulkQuality();

        $('#wso-start-bulk').hide();
        $('#wso-pause-bulk').show();
        $('#wso-progress-box').show();

        if (bulkMode === 'convert') {
            addLogEntry('در حال ایجاد صف تبدیل همگانی به ' + targetFormat.toUpperCase() + '...', 'info');
        } else {
            addLogEntry('در حال ایجاد صف تصاویر جهت بهینه‌سازی...', 'info');
        }

        $.ajax({
            url: wsoData.ajax_url,
            method: 'POST',
            data: {
                action: 'wso_build_queue',
                nonce: wsoData.nonce,
                bulk_mode: bulkMode,
                target_format: targetFormat
            },
            timeout: 60000
        }).done(function (response) {
            if (response.success) {
                totalItems = parseInt(response.data.total_pending || response.data.count || 0, 10) || 0;
                processedItems = 0;
                consecutiveFailures = 0;
                updateProgressBar(0, totalItems);

                if (totalItems === 0) {
                    addLogEntry(bulkMode === 'convert' ? 'تصویری برای تبدیل به ' + targetFormat.toUpperCase() + ' یافت نشد (همه قبلا تبدیل شده‌اند).' : 'هیچ تصویر جدیدی جهت بهینه‌سازی یافت نشد.', 'warning');
                    stopProcessing();
                    return;
                }

                if (bulkMode === 'convert') {
                    addLogEntry('تعداد ' + totalItems + ' تصویر در صف تبدیل به ' + targetFormat.toUpperCase() + ' قرار گرفت...', 'success');
                } else {
                    addLogEntry('تعداد ' + totalItems + ' تصویر در صف قرار گرفت. شروع بهینه‌سازی دسته‌ای...', 'success');
                }
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

        var bulkMode = getBulkMode();
        var targetFormat = getTargetFormat();
        var quality = getBulkQuality();
        // AVIF is CPU-heavy: keep batches small to avoid timeouts.
        var batchSize = (bulkMode === 'convert' && targetFormat === 'avif') ? 2 : 3;

        currentRequest = $.ajax({
            url: wsoData.ajax_url,
            method: 'POST',
            data: {
                action: 'wso_process_batch',
                nonce: wsoData.nonce,
                batch_size: batchSize,
                bulk_mode: bulkMode,
                target_format: targetFormat,
                quality: quality
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
            var srvMsg = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message;
            // Off-peak / throttle (429): pause retries longer but keep queue.
            if (jqXHR && 429 === jqXHR.status) {
                addLogEntry('توقف موقت توسط زمان‌بند: ' + (srvMsg || 'خارج از پنجره مجاز') + ' — تلاش مجدد در ۳۰ ثانیه.', 'warning');
                setTimeout(processNextBatch, 30000);
                return;
            }
            consecutiveFailures++;
            if (consecutiveFailures >= maxFailures) {
                addLogEntry('ارتباط با سرور قطع شد و تلاش مجدد بی‌نتیجه ماند. عملیات متوقف شد.' + (srvMsg ? ' (' + srvMsg + ')' : ''), 'error');
                stopProcessing();
                return;
            }
            var wait = Math.min(15000, 2000 * consecutiveFailures);
            addLogEntry('اختلال در ارتباط شبکه؛ تلاش مجدد (' + consecutiveFailures + '/' + maxFailures + ')' + (srvMsg ? ': ' + srvMsg : '') + '...', 'warning');
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
