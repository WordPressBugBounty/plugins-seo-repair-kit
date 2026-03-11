/*
 * SEO Repair Kit - Archive Settings
 * Complete implementation following All in One SEO design
 * Version: 2.1.3
 *  @author     TorontoDigits <support@torontodigits.com>
 */
(function ($) {
    'use strict';

    // Get data from PHP
    const srkData = window.srkArchivesData || {};

    /**
     * Generate preview text
     */
    function generatePreview(template, archiveType, type, separator) {
        if (!template || typeof template !== 'string') {
            return '';
        }

        const srkData = window.srkArchivesData;

        if (!srkData) {
            console.error('srkArchivesData is not defined!');
            return template;
        }

        // Create replacements object
        const replacements = {
            '%sep%': srkData.separator || '-',
            '%site_title%': srkData.site_name || 'Site Name',
            '%sitedesc%': srkData.site_description || 'Site Description',
            '%author%': srkData.author_name || 'Admin',
            '%author_bio%': srkData.author_bio || 'Author biography text...',
            '%author_first_name%': 'John',
            '%author_last_name%': 'Doe',
            '%current_date%': new Date().toLocaleDateString(),
            '%day%': new Date().getDate(),
            '%month%': new Date().toLocaleString('default', { month: 'long' }),
            '%year%': new Date().getFullYear(),
            '%search%': 'example search'
        };

        // Special cases for different archive types
        if (archiveType === 'author') {
            replacements['%archive_title%'] = srkData.author_name || 'Admin';
            replacements['%archive_description%'] = 'Posts by ' + (srkData.author_name || 'Admin');
            replacements['%date%'] = '';
        } else if (archiveType === 'date') {
            // Use full date for archive_title, month-year for date
            replacements['%date%'] = new Date().toLocaleString('default', {
                month: 'long',
                year: 'numeric'
            });
            replacements['%archive_title%'] = srkData.full_date || 'February 15, 2026'; // Full date
            replacements['%archive_description%'] = 'Archive for ' + (srkData.full_date || 'February 15, 2026');

            // Additional date tags
            const now = new Date();
            replacements['%day%'] = now.getDate();
            replacements['%month%'] = now.toLocaleString('default', { month: 'long' });
            replacements['%year%'] = now.getFullYear();
            replacements['%current_date%'] = srkData.full_date || 'February 15, 2026';
            replacements['%author%'] = '';
            replacements['%author_bio%'] = '';
        } else if (archiveType === 'search') {
            replacements['%archive_title%'] = 'Search Results';
            replacements['%archive_description%'] = 'Search results for "example search"';
            replacements['%date%'] = '';
        }

        // Replace all tags in the template
        let preview = template;

        for (const [tag, value] of Object.entries(replacements)) {
            if (preview.includes(tag)) {
                preview = preview.split(tag).join(value);
            }
        }

        // Clean up: remove any leftover % tags
        preview = preview.replace(/%[a-z_]+%/g, '');

        // Clean up extra spaces and remove duplicates
        preview = preview.replace(/\s+/g, ' ').trim();
        preview = preview.replace(/\b(\w+)(?:\s+\1\b)+/gi, '$1');

        return preview;
    }

    /**
     * Generate Google-style preview HTML
     */
    function generateGooglePreview(title, description, archiveType, separator) {
        const siteUrl = window.location.origin;

        // Get previews
        const previewTitle = generatePreview(title, archiveType, 'title', separator);
        const previewDesc = generatePreview(description, archiveType, 'description', separator);

        // Create HTML
        let html = '<div class="srk-google-preview">';
        html += '<div class="srk-preview-url">' + siteUrl + '</div>';
        html += '<div class="srk-preview-title">' + (previewTitle || srkData.site_name || 'Site Name') + '</div>';

        if (previewDesc) {
            html += '<div class="srk-preview-description">' + previewDesc + '</div>';
        }

        html += '</div>';

        return html;
    }

    /**
     * Update preview
     */
    function updatePreview($card, archiveType) {
        const $titleInput = $card.find('.srk-title-input');
        const $descInput = $card.find('.srk-desc-input');

        const title = $titleInput.val() || '';
        const description = $descInput.val() || '';

        const previewHtml = generateGooglePreview(title, description, archiveType, srkData.separator);
        $card.find('.srk-google-preview-box').html(previewHtml);
    }

    /**
     * Insert tag at cursor position
     */
    function insertTag($input, tag) {
        const value = $input.val() || '';
        const inputEl = $input[0];
        const start = inputEl.selectionStart || value.length;
        const end = inputEl.selectionEnd || value.length;

        // Check if tag already exists at cursor position
        const textBeforeCursor = value.substring(0, start);
        const textAfterCursor = value.substring(end);

        // Don't insert if same tag is immediately before cursor
        if (textBeforeCursor.endsWith(tag)) {
            return;
        }

        const newValue = textBeforeCursor + tag + textAfterCursor;
        $input.val(newValue);

        const newPos = start + tag.length;
        inputEl.setSelectionRange(newPos, newPos);
        $input.focus();
    }

    /**
     * Toggle Advanced settings based on Use Default checkbox
     */
    function toggleAdvancedSettings($card, useDefault) {
        const $advancedSettings = $card.find('.srk-advanced-settings');
        const $toggleState = $card.find('.srk-toggle-state');

        if (useDefault) {
            // Hide advanced settings and add disabled state
            $advancedSettings.addClass('hidden');
            $toggleState
                .removeClass('disabled')
                .addClass('enabled')
                .text('Enabled');

            // Disable all inputs in advanced settings
            $advancedSettings.find('input, select').prop('disabled', true);
        } else {
            // Show advanced settings
            $advancedSettings.removeClass('hidden');
            $toggleState
                .removeClass('enabled')
                .addClass('disabled')
                .text('Disabled');

            // Enable all inputs in advanced settings
            $advancedSettings.find('input, select').prop('disabled', false);
        }
    }

    /**
     * Update checkbox states when toggle changes
     */
    function updateCheckboxStates($card, useDefault) {
        const $checkboxes = $card.find('.srk-advanced-settings input[type="checkbox"]');
        const $selects = $card.find('.srk-advanced-settings select');

        if (useDefault) {
            $checkboxes.prop('disabled', true);
            $selects.prop('disabled', true);
        } else {
            $checkboxes.prop('disabled', false);
            $selects.prop('disabled', false);
        }
    }

    /**
     * Reset Archives to Defaults
     */
    function resetArchivesToDefaults() {
        if (!confirm('Are you sure you want to reset all Archive settings to defaults? This cannot be undone.')) {
            return;
        }

        // resetArchivesToDefaults() function mein:
        const defaults = {
            'author': {
                title: '%author% %sep% %site_title%',
                description: '',
                noindex: '1',
                nofollow: '0',
                noarchive: '0',
                nosnippet: '0',
                noimageindex: '0',
                max_snippet: '-1',
                max_image_preview: 'large',
                max_video_preview: '-1',
                use_default: '0' 
            },
            'date': {
                title: '%archive_title% %sep% %site_title%',
                description: '',
                noindex: '1',
                nofollow: '0',
                noarchive: '0',
                nosnippet: '0',
                noimageindex: '0',
                max_snippet: '-1',
                max_image_preview: 'large',
                max_video_preview: '-1',
                use_default: '0'  
            },
            'search': {
                title: '%search% %sep% %site_title%',
                description: '',
                noindex: '1',          // Enable noindex
                nofollow: '0',         // Follow links
                noarchive: '0',
                nosnippet: '0',
                noimageindex: '0',
                max_snippet: '-1',
                max_image_preview: 'large',
                max_video_preview: '-1',
                use_default: '0'       // Advanced Robots Meta OFF by default
            }
        };

        // Reset each archive type
        $('.srk-archive-card').each(function () {
            const $card = $(this);
            const archiveType = $card.data('archive-type');

            if (archiveType && defaults[archiveType]) {
                const def = defaults[archiveType];

                // Reset title
                $card.find(`input[name="srk_archives[${archiveType}][title]"]`).val(def.title);

                // Reset description
                $card.find(`textarea[name="srk_archives[${archiveType}][description]"]`).val(def.description);

                // Reset advanced checkboxes
                $card.find(`input[name="srk_archives[${archiveType}][noindex]"]`).prop('checked', def.noindex === '1');
                $card.find(`input[name="srk_archives[${archiveType}][nofollow]"]`).prop('checked', def.nofollow === '1');
                $card.find(`input[name="srk_archives[${archiveType}][noarchive]"]`).prop('checked', def.noarchive === '1');
                $card.find(`input[name="srk_archives[${archiveType}][nosnippet]"]`).prop('checked', def.nosnippet === '1');
                $card.find(`input[name="srk_archives[${archiveType}][noimageindex]"]`).prop('checked', def.noimageindex === '1');

                // Reset dropdowns
                $card.find(`select[name="srk_archives[${archiveType}][max_snippet]"]`).val(def.max_snippet);
                $card.find(`select[name="srk_archives[${archiveType}][max_image_preview]"]`).val(def.max_image_preview);
                $card.find(`select[name="srk_archives[${archiveType}][max_video_preview]"]`).val(def.max_video_preview);

                // Reset use default
                $card.find(`input[name="srk_archives[${archiveType}][use_default]"]`).prop('checked', def.use_default === '1');

                // Update preview
                updatePreview($card, archiveType);

                // Toggle advanced settings
                toggleAdvancedSettings($card, def.use_default === '1');
            }
        });

        // Show success message
        alert('Archive settings have been reset to defaults! Click "Save Changes" to apply.');
    }

    /**
     * Initialize when DOM is ready
     */
    $(document).ready(function () {

        /* ============================================
           TAB FUNCTIONALITY
           ============================================ */
        $('.srk-archive-tabs').on('click', '.srk-tab-button', function () {
            const $button = $(this);
            const $card = $button.closest('.srk-archive-card');
            const tabName = $button.data('tab');

            // Update button states
            $card.find('.srk-tab-button').removeClass('active');
            $button.addClass('active');

            // Update tab content visibility
            $card.find('.srk-tab-content').hide();
            $card.find('.srk-tab-content[data-tab-content="' + tabName + '"]').show();
        });

        /* ============================================
           USE DEFAULT SETTINGS TOGGLE
           ============================================ */
        $('.srk-archives-settings').on('change', '.srk-use-default-toggle', function () {
            const $checkbox = $(this);
            const $card = $checkbox.closest('.srk-archive-card');
            const useDefault = $checkbox.is(':checked');

            // Toggle advanced settings visibility
            toggleAdvancedSettings($card, useDefault);
        });

        /* ============================================
           TAG INSERTION
           ============================================ */
        $('.srk-archives-settings').on('click', '.srk-tag-btn', function () {
            const $btn = $(this);
            const tag = $btn.data('tag');
            const target = $btn.data('target');
            const $card = $btn.closest('.srk-archive-card');

            // Find the appropriate input
            const $input = target === 'title'
                ? $card.find('.srk-title-input')
                : $card.find('.srk-desc-input');

            if ($input.length) {
                insertTag($input, tag);

                // Update preview
                const archiveType = $card.data('archive-type');
                updatePreview($card, archiveType);
            }
        });

        /* ============================================
           LIVE PREVIEW UPDATES
           ============================================ */
        $('.srk-archives-settings').on('input', '.srk-title-input, .srk-desc-input', function () {
            const $card = $(this).closest('.srk-archive-card');
            const archiveType = $card.data('archive-type');
            updatePreview($card, archiveType);
        });


        /* ============================================
           RESET TO DEFAULTS BUTTON
           ============================================ */
        $('.srk-reset-archives-button').on('click', function (e) {
            e.preventDefault();
            resetArchivesToDefaults();
        });

        /* ============================================
           FORM SUBMISSION
           ============================================ */
        $('#srk-archives-form').on('submit', function (e) {
            // Form will submit normally
        });

        /* ============================================
           VIEW ALL TAGS MODAL
           ============================================ */

        $('.srk-archives-settings').on('click', '.srk-view-all-tags', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $card = $(this).closest('.srk-archive-card');
            const archiveType = $card.data('archive-type');
            const target = $(this).data('target');

            const $modal = $('#srk-archive-tags-modal-' + archiveType);

            if ($modal.length) {

                // STORE target in modal
                $modal.attr('data-current-target', target);

                $modal.fadeIn(150);

                if (!$('#srk-modal-overlay').length) {
                    $('<div id="srk-modal-overlay"></div>').appendTo('body');
                }
            }
        });

        $(document).on('click', '.srk-all-tags-modal', function (e) {
            e.stopPropagation();
        });


        // Close modal
        $(document).on('click', '.srk-modal-close', function () {
            $('.srk-all-tags-modal').hide();
            $('#srk-modal-overlay').remove();
        });

        // Insert tag from modal
        $('.srk-archives-settings').on('click', '.srk-tag-item', function () {

            const tag = $(this).data('tag');
            const archiveType = $(this).data('archive');

            const $modal = $('#srk-archive-tags-modal-' + archiveType);
            const target = $modal.attr('data-current-target');

            const $card = $('.srk-archive-card[data-archive-type="' + archiveType + '"]');

            const $input = target === 'description'
                ? $card.find('.srk-desc-input')
                : $card.find('.srk-title-input');

            if ($input.length) {

                const inputEl = $input[0];
                const value = $input.val() || '';
                const start = inputEl.selectionStart || value.length;
                const end = inputEl.selectionEnd || value.length;

                const newValue =
                    value.substring(0, start) +
                    tag +
                    value.substring(end);

                $input.val(newValue);
                inputEl.setSelectionRange(start + tag.length, start + tag.length);
                $input.focus();

                updatePreview($card, archiveType);
            }

            $('.srk-all-tags-modal').hide();
            $('#srk-modal-overlay').remove();
        });

        // Search filter inside modal
        $('.srk-archives-settings').on('keyup', '.srk-tags-search', function () {

            const search = $(this).val().toLowerCase();
            const $modal = $(this).closest('.srk-all-tags-modal');

            $modal.find('.srk-tag-item').each(function () {

                const text = $(this).text().toLowerCase();

                if (text.includes(search)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }

            });
        });

        // Close when clicking overlay
        $(document).on('click', '#srk-modal-overlay', function () {
            $('.srk-all-tags-modal').hide();
            $(this).remove();
        });

        /* ============================================
           INITIALIZE ON PAGE LOAD
           ============================================ */
        $('.srk-archive-card').each(function () {
            const $card = $(this);
            const archiveType = $card.data('archive-type');

            if (archiveType) {
                // Check if use default is checked
                const $useDefault = $card.find('.srk-use-default-toggle');
                if ($useDefault.length) {
                    const useDefault = $useDefault.is(':checked');
                    toggleAdvancedSettings($card, useDefault);
                }

                // Initialize preview
                updatePreview($card, archiveType);
            }
        });

    });

})(jQuery);