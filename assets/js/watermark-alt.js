/* Watermark uploader + Auto Alt bulk filler (Persian, lightweight, settings page only) */

(function ($) {
    'use strict';

    $(document).ready(function () {
        initWatermarkUploader();
        initAltBulk();
    });

    function initWatermarkUploader() {
        var $input = $('#wso_watermark_image');
        if (!$input.length) return;

        $('#wso-wm-select').on('click', function (e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) {
                alert('کتابخانه رسانه وردپرس در دسترس نیست.');
                return;
            }
            var frame = wp.media({
                title: 'انتخاب تصویر واترمارک',
                button: { text: 'استفاده به‌عنوان واترمارک' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                $input.val(att.id);
                var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                $('#wso-wm-preview').html('<img src="' + url + '" alt="پیش‌نمایش واترمارک" style="max-width:180px;max-height:100px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;padding:4px;" />');
            });
            frame.open();
        });

        $('#wso-wm-remove').on('click', function (e) {
            e.preventDefault();
            $input.val(0);
            $('#wso-wm-preview').html('<span class="wso-text-muted" style="font-size:12px;">هنوز تصویری انتخاب نشده است.</span>');
        });
    }

    function initAltBulk() {
        var $btn = $('#wso-fill-missing-alts');
        if (!$btn.length) return;

        // NOTE: server query returns only images WITHOUT alt, so filled
        // items drop out of the result set. Offset must stay 0, otherwise
        // we skip over unprocessed images. We track total separately.
        var totalFilled = 0;

        $btn.on('click', function (e) {
            e.preventDefault();
            var $status = $('#wso-alt-bulk-status');
            $btn.prop('disabled', true).text('در حال تکمیل...');
            $status.text('');

            $.post(wsoData.ajax_url, {
                action: 'wso_fill_missing_alts',
                nonce: wsoData.nonce,
                limit: 20,
                offset: 0
            }).done(function (res) {
                $btn.prop('disabled', false).text('تکمیل Alt تصاویر بدون Alt (۲۰ تایی)');
                if (res.success) {
                    totalFilled += (res.data.filled || 0);
                    var msg = res.data.message + ' (مجموع تکمیل‌شده در این نشست: ' + totalFilled + ')';
                    $status.text(msg);
                    if (window.wsoToast) {
                        window.wsoToast(res.data.message, 'success');
                    }
                    if (res.data.processed === 0 || res.data.has_more === false) {
                        if (res.data.processed === 0) {
                            $status.text('همه تصاویر موجود دارای Alt هستند.');
                        }
                    }
                } else {
                    $status.text(res.data.message || 'خطا در تکمیل Alt.');
                    if (window.wsoToast) {
                        window.wsoToast(res.data.message || 'خطا در تکمیل Alt.', 'error');
                    }
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('تکمیل Alt تصاویر بدون Alt (۲۰ تایی)');
                $status.text('خطای ارتباط با سرور.');
            });
        });
    }

})(jQuery);
