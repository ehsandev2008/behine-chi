/* Gutenberg Block: Optimized Image */
(function (wp) {
    'use strict';

    var registerBlockType = wp.blocks.registerBlockType;
    var _element = wp.element;
    var Fragment = _element.Fragment;
    var InspectorControls = wp.editor.InspectorControls;
    var _components = wp.components;
    var PanelBody = _components.PanelBody;
    var SelectControl = _components.SelectControl;
    var ToggleControl = _components.ToggleControl;
    var MediaUpload = _components.MediaUpload;
    var MediaUploadCheck = _components.MediaUploadCheck;
    var Button = _components.Button;
    var Spinner = _components.Spinner;

    registerBlockType('wso/optimized-image', {
        title: 'تصویر بهینه‌شده (بهینه چی)',
        icon: 'format-image',
        category: 'media',
        attributes: {
            attachmentId: { type: 'number', default: 0 },
            size: { type: 'string', default: 'full' },
            format: { type: 'string', default: 'auto' },
            lazy: { type: 'boolean', default: true },
        },

        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            var onSelectImage = function (media) {
                setAttributes({ attachmentId: media.id });
            };

            return wp.element.createElement(
                Fragment,
                null,
                wp.element.createElement(
                    InspectorControls,
                    null,
                    wp.element.createElement(
                        PanelBody,
                        { title: 'تنظیمات تصویر' },
                        wp.element.createElement(SelectControl, {
                            label: 'سایز تصویر',
                            value: attributes.size,
                            options: [
                                { label: 'بندانگشتی', value: 'thumbnail' },
                                { label: 'متوسط', value: 'medium' },
                                { label: 'بزرگ', value: 'large' },
                                { label: 'کامل', value: 'full' },
                            ],
                            onChange: function (val) { setAttributes({ size: val }); },
                        }),
                        wp.element.createElement(SelectControl, {
                            label: 'فرمت خروجی',
                            value: attributes.format,
                            options: [
                                { label: 'خودکار', value: 'auto' },
                                { label: 'WebP', value: 'webp' },
                                { label: 'AVIF', value: 'avif' },
                                { label: 'اصلی', value: 'original' },
                            ],
                            onChange: function (val) { setAttributes({ format: val }); },
                        }),
                        wp.element.createElement(ToggleControl, {
                            label: 'بارگذاری تنبل (Lazy Load)',
                            checked: attributes.lazy,
                            onChange: function (val) { setAttributes({ lazy: val }); },
                        })
                    )
                ),
                wp.element.createElement(
                    MediaUploadCheck,
                    null,
                    wp.element.createElement(
                        MediaUpload,
                        {
                            onSelect: onSelectImage,
                            allowedTypes: ['image'],
                            value: attributes.attachmentId,
                            render: function (obj) {
                                return wp.element.createElement(
                                    Button,
                                    {
                                        onClick: obj.open,
                                        className: 'wso-block-upload-btn',
                                    },
                                    attributes.attachmentId ? 'تغییر تصویر' : 'انتخاب تصویر'
                                );
                            },
                        }
                    )
                ),
                attributes.attachmentId > 0 &&
                    wp.element.createElement('img', {
                        src: wp.blockEditor ? '' : '',
                        alt: 'پیش‌نمایش',
                        style: { maxWidth: '100%', marginTop: '10px' },
                    })
            );
        },

        save: function () {
            return null; // Server-side render
        },
    });

})(window.wp);
