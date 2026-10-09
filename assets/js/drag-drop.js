/* Drag & Drop Upload Controller */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $dropZone = $('#wso-drag-drop-zone');
        if (!$dropZone.length) return;

        var $input = $('#wso-drag-drop-input');
        var $progress = $('#wso-upload-progress');
        var $results = $('#wso-upload-results');

        // Drag events
        $dropZone.on('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('drag-over');
        });

        $dropZone.on('dragleave', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('drag-over');
        });

        $dropZone.on('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('drag-over');

            var files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                handleFiles(files);
            }
        });

        // Click to browse
        $dropZone.on('click', function () {
            $input.click();
        });

        $input.on('change', function () {
            if (this.files.length > 0) {
                handleFiles(this.files);
            }
        });

        function handleFiles(files) {
            $.each(files, function (i, file) {
                uploadFile(file);
            });
        }

        function uploadFile(file) {
            var formData = new FormData();
            formData.append('action', 'wso_drag_drop_upload');
            formData.append('nonce', wsoData.nonce);
            formData.append('file', file);

            // Show progress
            var progressId = 'upload-' + Date.now();
            $progress.append('<div class="wso-upload-item" id="' + progressId + '">' +
                '<span class="wso-upload-name">' + escHtml(file.name) + '</span>' +
                '<span class="wso-upload-status">در حال آپلود...</span>' +
                '</div>');

            $.ajax({
                url: wsoData.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (res.success) {
                        var optInfo = '';
                        if (res.data.optimization) {
                            optInfo = '<div class="wso-upload-optimization">' +
                                '<span>✅ ' + res.data.optimization.savings_percent + '% صرفه‌جویی (' +
                                res.data.optimization.original_size + ' ← ' + res.data.optimization.optimized_size + ')</span>' +
                                '</div>';
                        }
                        $('#' + progressId).html(
                            '<span class="wso-upload-name">' + escHtml(res.data.attachment.name) + '</span>' +
                            '<span class="wso-upload-status success">✓ آپلود شد (' + res.data.attachment.size + ')</span>' +
                            optInfo
                        );
                    } else {
                        $('#' + progressId).html(
                            '<span class="wso-upload-name">' + escHtml(file.name) + '</span>' +
                            '<span class="wso-upload-status error">✗ ' + (res.data.message || 'خطا') + '</span>'
                        );
                    }
                },
                error: function () {
                    $('#' + progressId).html(
                        '<span class="wso-upload-name">' + escHtml(file.name) + '</span>' +
                        '<span class="wso-upload-status error">✗ خطای ارتباط</span>'
                    );
                }
            });
        }

        function escHtml(str) {
            return String(str).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
            });
        }
    });

})(jQuery);
