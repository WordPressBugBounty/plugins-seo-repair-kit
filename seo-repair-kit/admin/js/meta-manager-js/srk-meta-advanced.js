/*
 * SEO Repair Kit - Advanced Settings JavaScript (Robots Meta Only)
 * Version: 2.1.3
 *  @author     TorontoDigits <support@torontodigits.com>
 */

(function ($) {
    'use strict';
    const srkData = window.srkAdvancedData || {};
    const DEFAULT_ROBOTS_META = srkData.default_robots_meta || {};

    // Generate robots meta preview - safe defaults, mutual exclusivity
    function generateRobotsMetaPreview(robotsMeta) {
        const DEFAULTS = { noindex: '0', nofollow: '0', noarchive: '0', notranslate: '0', noimageindex: '0', nosnippet: '0', noodp: '0', max_snippet: -1, max_video_preview: -1, max_image_preview: 'large' };
        robotsMeta = Object.assign({}, DEFAULTS, robotsMeta || {});

        const directives = [];
        directives.push((robotsMeta.noindex === '1' || robotsMeta.noindex === 1) ? 'noindex' : 'index');
        directives.push((robotsMeta.nofollow === '1' || robotsMeta.nofollow === 1) ? 'nofollow' : 'follow');

        if (robotsMeta.noarchive === '1' || robotsMeta.noarchive === 1) directives.push('noarchive');
        if (robotsMeta.notranslate === '1' || robotsMeta.notranslate === 1) directives.push('notranslate');
        if (robotsMeta.noimageindex === '1' || robotsMeta.noimageindex === 1) {
            directives.push('noimageindex');
        } else {
            const mip = robotsMeta.max_image_preview;
            directives.push(mip === 'none' || mip === 'standard' ? `max-image-preview:${mip}` : 'max-image-preview:large');
        }
        if (robotsMeta.nosnippet === '1' || robotsMeta.nosnippet === 1) {
            directives.push('nosnippet');
        } else if (robotsMeta.max_snippet && String(robotsMeta.max_snippet) !== '-1') {
            directives.push(`max-snippet:${robotsMeta.max_snippet}`);
        }
        if (robotsMeta.noodp === '1' || robotsMeta.noodp === 1) directives.push('noodp');
        if (robotsMeta.max_video_preview && String(robotsMeta.max_video_preview) !== '-1') {
            directives.push(`max-video-preview:${robotsMeta.max_video_preview}`);
        }

        return directives.join(', ');
    }

    // Update robots meta preview
    function updateRobotsMetaPreview() {
        const useDefault = $('#srk_use_default_settings').is(':checked');
        let robotsMeta = {};

        if (useDefault) {
            robotsMeta = { ...DEFAULT_ROBOTS_META };
        } else {
            const nosnippet = $('#srk_robots_nosnippet').is(':checked');
            const noimageindex = $('#srk_robots_noimageindex').is(':checked');
            robotsMeta = {
                noindex: $('#srk_robots_noindex').is(':checked') ? '1' : '0',
                nofollow: $('#srk_robots_nofollow').is(':checked') ? '1' : '0',
                noarchive: $('#srk_robots_noarchive').is(':checked') ? '1' : '0',
                notranslate: $('#srk_robots_notranslate').is(':checked') ? '1' : '0',
                noimageindex: noimageindex ? '1' : '0',
                nosnippet: nosnippet ? '1' : '0',
                noodp: $('#srk_robots_noodp').is(':checked') ? '1' : '0',
                max_snippet: nosnippet ? -1 : ($('#srk_max_snippet').val() || -1),
                max_video_preview: $('#srk_max_video_preview').val() || -1,
                max_image_preview: noimageindex ? '' : ($('#srk_max_image_preview').val() || 'large')
            };
        }

        const preview = generateRobotsMetaPreview(robotsMeta);
        $('.srk-robots-preview code, #srk-current-robots-preview').text(preview);
    }

    // Handle default settings toggle
    function handleDefaultSettingsToggle() {
        const useDefault = $('#srk_use_default_settings').is(':checked');
        const $robotsContainer = $('#srk-robots-meta-container');
        const $defaultMessage = $('.srk-default-mode-message');
        const $toggleState = $('.srk-toggle-state');

        if (useDefault) {
            $robotsContainer.slideUp(300);
            $defaultMessage.addClass('show').slideDown(300);
            $toggleState
                .removeClass('off')
                .addClass('on')
                .text('ON');
        } else {
            $defaultMessage.removeClass('show').slideUp(300, function () {
                $robotsContainer.slideDown(400);
            });
            $toggleState
                .removeClass('on')
                .addClass('off')
                .text('OFF');
        }

        updateRobotsMetaPreview();
    }

    // Handle conditional field visibility
    function updateConditionalFields() {
        const nosnippet = $('#srk_robots_nosnippet').is(':checked');
        const noimageindex = $('#srk_robots_noimageindex').is(':checked');

        if (nosnippet) {
            $('#srk-max-snippet-field').slideUp(200);
        } else {
            $('#srk-max-snippet-field').slideDown(200);
        }

        if (noimageindex) {
            $('#srk-max-image-preview-field').slideUp(200);
        } else {
            $('#srk-max-image-preview-field').slideDown(200);
        }
    }


    // FIX: Function to sync form with current settings
    function syncFormWithCurrentSettings() {
        const useDefault = srkData.use_default_settings === '1';

        if (useDefault) {

            // All "no" checkboxes should be UNCHECKED in default mode
            $('#srk_robots_noindex').prop('checked', false);
            $('#srk_robots_nofollow').prop('checked', false);
            $('#srk_robots_noarchive').prop('checked', false);
            $('#srk_robots_notranslate').prop('checked', false);
            $('#srk_robots_noimageindex').prop('checked', false);
            $('#srk_robots_nosnippet').prop('checked', false);
            $('#srk_robots_noodp').prop('checked', false);

            // Set default values from data
            $('#srk_max_snippet').val(srkData.default_robots_meta.max_snippet || '-1');
            $('#srk_max_video_preview').val(srkData.default_robots_meta.max_video_preview || '-1');
            $('#srk_max_image_preview').val(srkData.default_robots_meta.max_image_preview || 'large');
        } else {
            // When default is OFF, show current custom values from srkData
            if (srkData.current_robots_meta) {
                const meta = srkData.current_robots_meta;

                // Handle index/noindex
                if (meta.noindex === '1' || meta.noindex === 1) {
                    $('#srk_robots_index').prop('checked', false);
                    $('#srk_robots_noindex').prop('checked', true);
                } else {
                    $('#srk_robots_noindex').prop('checked', false);
                }

                // Handle follow/nofollow
                if (meta.nofollow === '1' || meta.nofollow === 1) {
                    $('#srk_robots_follow').prop('checked', false);
                    $('#srk_robots_nofollow').prop('checked', true);
                } else {
                    $('#srk_robots_nofollow').prop('checked', false);
                }

                // Other checkboxes - only check if true
                $('#srk_robots_noarchive').prop('checked', meta.noarchive === '1' || meta.noarchive === 1);
                $('#srk_robots_notranslate').prop('checked', meta.notranslate === '1' || meta.notranslate === 1);
                $('#srk_robots_noimageindex').prop('checked', meta.noimageindex === '1' || meta.noimageindex === 1);
                $('#srk_robots_nosnippet').prop('checked', meta.nosnippet === '1' || meta.nosnippet === 1);
                $('#srk_robots_noodp').prop('checked', meta.noodp === '1' || meta.noodp === 1);

                // Numeric fields
                $('#srk_max_snippet').val(meta.max_snippet || '-1');
                $('#srk_max_video_preview').val(meta.max_video_preview || '-1');
                $('#srk_max_image_preview').val(meta.max_image_preview || 'large');
            }
        }
    }

    // Call this on DOM ready (add to your $(document).ready function)
    syncFormWithCurrentSettings();

    // Reset to defaults
    function resetToDefaults() {
        if (confirm(srkData.strings?.confirm_reset || 'Are you sure you want to reset all settings to defaults?')) {

            Object.keys(DEFAULT_ROBOTS_META).forEach(key => {
                if (key.startsWith('no')) {
                    $(`#srk_robots_${key}`).prop('checked', DEFAULT_ROBOTS_META[key] === '1');
                }
            });

            $('#srk_max_snippet').val(DEFAULT_ROBOTS_META.max_snippet);
            $('#srk_max_video_preview').val(DEFAULT_ROBOTS_META.max_video_preview);
            $('#srk_max_image_preview').val(DEFAULT_ROBOTS_META.max_image_preview);

            // Turn ON default settings
            $('#srk_use_default_settings').prop('checked', true).trigger('change');

            alert('Settings have been reset to defaults!');
        }
    }

    // DOM Ready
    $(document).ready(function () {

        // Detect WordPress discourage search engines setting
        if (srkData.discourage_search === true) {

            // Disable all robots UI
            $('#srk-robots-meta-container input, #srk-robots-meta-container select').prop('disabled', true);
            $('#srk_use_default_settings').prop('disabled', true);

            // Show warning message
            if (!$('.srk-discourage-warning').length) {
                $('.srk-advanced-settings').prepend(
                    '<div class="notice notice-warning srk-discourage-warning">' +
                    '<p><strong>WordPress "Discourage Search Engines" is enabled.</strong><br>' +
                    'SEO Repair Kit will not override WordPress robots directives. All pages will output: <code>&lt;meta name="robots" content="noindex, nofollow"&gt;</code></p>' +
                    '</div>'
                );
            }

        }

        // Default settings toggle
        $('#srk_use_default_settings').on('change', handleDefaultSettingsToggle);

        // Conditional field visibility
        $('#srk_robots_nosnippet, #srk_robots_noimageindex').on('change', updateConditionalFields);

        // Update preview on settings change
        $('#srk-robots-meta-container input, #srk-robots-meta-container select').on('change input', function () {
            if (!$('#srk_use_default_settings').is(':checked')) {
                updateRobotsMetaPreview();
                updateConditionalFields();
            }
        });

        // Initial setup
        handleDefaultSettingsToggle();
        updateConditionalFields();

        // Reset button
        $('.srk-reset-button').on('click', resetToDefaults);

        // Form validation
        $('#srk-advanced-form').on('submit', function (e) {
            // Ensure form is properly prepared before submission
            if (!$('#srk_use_default_settings').is(':checked')) {
                // Get current state of checkboxes
                const indexChecked = $('#srk_robots_index').is(':checked');
                const noindexChecked = $('#srk_robots_noindex').is(':checked');
                const followChecked = $('#srk_robots_follow').is(':checked');
                const nofollowChecked = $('#srk_robots_nofollow').is(':checked');


                // Update preview before submission
                updateRobotsMetaPreview();
            }

            return true; // Allow form submission
        });

        // Section toggle
        $('.srk-section-toggle').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
    
            $(this).closest('.srk-section').toggleClass('collapsed');
        });
        // Initialize form with correct values
        syncFormWithCurrentSettings();

        // Update preview immediately
        updateRobotsMetaPreview();
        updateConditionalFields();
    });

})(jQuery);