/*
 * SEO Repair Kit - Meta Manager Global Settings
 * Complete implementation following All in One SEO design
 * Version: 2.1.3
 * @author     TorontoDigits <support@torontodigits.com>
 */
(function ($) {
    'use strict';

    var SRKGlobalSettings = {

        /* ===============================
           STATE
        =============================== */
        data: {
            separator: '-',
            siteTitle: '',
            siteDescription: '',
            homeTitle: '',
            homeDesc: ''
        },

        elements: {},
        l10n: {},
        mediaUploader: null,

        /* ===============================
           INIT
        =============================== */
        init: function () {
            this.cacheElements();
            this.loadLocalizedData();
            this.loadInitialValues();
            this.bindEvents();
            this.initSmartTags();
            this.renderAll();
            this.initCustomSeparator();
            this.initCharacterCounters();

        },

        /* ===============================
           CACHE DOM
        =============================== */
        cacheElements: function () {
            this.elements = {
                // Separator elements
                separatorRadios: $('input[name="srk_title_separator"]'),
                separatorBtns: $('.srk-sep-btn'),
                showMoreBtn: $('#srk-show-more'),
                moreSeparators: $('#srk-more-separators'),
                customSepInput: $('#srk_custom_sep_input'),
                customInputWrap: $('#srk-custom-input'),

                // Input fields
                homeTitleInput: $('#srk_home_title'),
                homeDescInput: $('#srk_home_desc'),

                // Preview elements
                homeTitlePreview: $('#srk-home-title-preview'),
                homeDescPreview: $('#srk-home-desc-preview'),
                templatePreview: $('#srk-preview-title-template'),

                // Tag buttons
                tagButtons: $('.srk-tag-btn'),

                // Reset button
                resetBtn: $('.srk-reset-global-button'),

                // Character counters
                homeTitleCount: $('#srk-home-title-count'),
                homeDescCount: $('#srk-home-desc-count'),
                homeTitleStatus: $('#srk-home-title-status'),
                homeDescStatus: $('#srk-home-desc-status')
            };
        },

        /* ===============================
           LOAD LOCALIZED DATA
        =============================== */
        loadLocalizedData: function () {

            if (typeof srkMetaPreview !== 'undefined') {

                this.l10n = srkMetaPreview;

                this.data.separator = srkMetaPreview.initial_separator || '-';
                this.data.siteTitle = srkMetaPreview.site_name || '';
                this.data.siteDescription = srkMetaPreview.site_description || '';

                // FIX: Ensure author object always exists
                this.data.author = {
                    first_name: '',
                    last_name: '',
                    display_name: ''
                };

                if (srkMetaPreview.site_author) {
                    this.data.author.first_name = srkMetaPreview.site_author.first_name || '';
                    this.data.author.last_name = srkMetaPreview.site_author.last_name || '';
                    this.data.author.display_name = srkMetaPreview.site_author.display_name || '';
                }

            }

        },

        /* ===============================
           LOAD INITIAL INPUT VALUES
        =============================== */
        loadInitialValues: function () {
            this.data.homeTitle = this.elements.homeTitleInput.val() || '';
            this.data.homeDesc = this.elements.homeDescInput.val() || '';
        },

        /* ===============================
        EVENTS - FIXED INPUT HANDLERS
        =============================== */
        bindEvents: function () {
            var self = this;

            // Separator change
            this.elements.separatorRadios.on('change', function () {
                self.handleSeparatorChange($(this));
            });

            // Custom separator input
            this.elements.customSepInput.on('input', function () {
                self.data.separator = $(this).val().substring(0, 3) || '-';
                self.renderAll();
                // Re-validate counts with new separator
                self.refreshCharacterCounts();
            });

            // Home title input with FIXED character counter
            this.elements.homeTitleInput.on('input', function () {
                var rawValue = $(this).val();
                
                // Preserve spaces but clean up multiple spaces
                rawValue = rawValue.replace(/\s+/g, ' ').trim();

                self.data.homeTitle = rawValue;
                self.renderHomeTitle();
                
                // PARSE tags before counting for accurate length
                var parsedValue = self.parseTags(rawValue);
                self.updateCharacterCount('title', rawValue, parsedValue);
            });

            // Home description input with FIXED character counter
            this.elements.homeDescInput.on('input', function () {
                var rawValue = $(this).val();
                
                // Preserve spaces but clean up multiple spaces
                rawValue = rawValue.replace(/\s+/g, ' ').trim();

                self.data.homeDesc = rawValue;
                self.renderHomeDesc();
                
                // PARSE tags before counting for accurate length
                var parsedValue = self.parseTags(rawValue);
                self.updateCharacterCount('desc', rawValue, parsedValue);
            });

            // Tag buttons - already trigger 'input' event, so counters will auto-update
            $(document).on('click', '.srk-tag-btn', function (e) {
                e.preventDefault();
                self.insertTag($(this));
                
                // Force preview and count refresh
                setTimeout(function () {
                    self.renderAll();
                    self.refreshCharacterCounts();
                }, 10);
            });

            // Show more separators
            this.elements.showMoreBtn.on('click', function (e) {
                e.preventDefault();
                self.toggleMoreSeparators($(this));
            });

            // Reset button
            this.elements.resetBtn.on('click', function (e) {
                e.preventDefault();
                self.resetDefaults();
            });
        },

        /**
         * Refresh character counts for both fields
         * Used when separator changes or tags are inserted
         */
        refreshCharacterCounts: function () {
            var rawTitle = this.elements.homeTitleInput.val() || '';
            var rawDesc = this.elements.homeDescInput.val() || '';
            
            var parsedTitle = this.parseTags(rawTitle);
            var parsedDesc = this.parseTags(rawDesc);
            
            this.updateCharacterCount('title', rawTitle, parsedTitle);
            this.updateCharacterCount('desc', rawDesc, parsedDesc);
        },

        /* ===============================
        CHARACTER COUNTERS - FIXED
        =============================== */
        initCharacterCounters: function () {
            // Parse templates first, then count
            var parsedTitle = this.parseTags(this.data.homeTitle || '%site_title% %sep% %tagline%');
            var parsedDesc = this.parseTags(this.data.homeDesc || '%tagline%');
            
            this.updateCharacterCount('title', this.data.homeTitle, parsedTitle);
            this.updateCharacterCount('desc', this.data.homeDesc, parsedDesc);
        },

        /**
         * Update character count with PROPERLY PARSED template values
         * 
         * @param {string} type - 'title' or 'desc'
         * @param {string} rawValue - Original template with tags (for display purposes)
         * @param {string} parsedValue - Parsed value with tags replaced (for accurate counting)
         */
        updateCharacterCount: function (type, rawValue, parsedValue) {
            // Use PARSED value for accurate character counting
            var count = parsedValue ? parsedValue.length : 0;
            var $countEl, $statusEl;
            var maxLength = (type === 'title') ? 60 : 160;

            if (type === 'title') {
                $countEl = this.elements.homeTitleCount;
                $statusEl = this.elements.homeTitleStatus;
            } else {
                $countEl = this.elements.homeDescCount;
                $statusEl = this.elements.homeDescStatus;
            }

            // Update count display
            $countEl.text(count);

            // Update status with warning/error indicators
            if (count === 0) {
                $statusEl.html('').removeClass('warning error good');
            } else if (count > maxLength) {
                $statusEl.html('⚠️ ' + (count - maxLength) + ' characters over limit')
                    .removeClass('warning good')
                    .addClass('error');
            } else if (count > maxLength - 20) {
                $statusEl.html('⚠️ Getting close to limit')
                    .removeClass('error good')
                    .addClass('warning');
            } else {
                $statusEl.html('✓ Good length')
                    .removeClass('warning error')
                    .addClass('good');
            }
        },

        /* ===============================
           SEPARATOR HANDLING
        =============================== */
        handleSeparatorChange: function ($radio) {
            var value = $radio.val();

            // Remove active class from all
            this.elements.separatorBtns.removeClass('active');

            // Add active class to selected one
            $radio.next('.srk-sep-btn').addClass('active');

            if (value === 'custom') {
                this.elements.customInputWrap.show();
                value = this.elements.customSepInput.val() || '-';
            } else {
                this.elements.customInputWrap.hide();
            }

            this.data.separator = value;
            this.renderAll();
        },

        toggleMoreSeparators: function ($btn) {
            var expanded = $btn.hasClass('expanded');
            this.elements.moreSeparators.slideToggle(200);
            $btn.toggleClass('expanded')
                .text(expanded ? 'Show More...' : 'Show Less');
        },

        /* ===============================
           RENDERING
        =============================== */
        renderAll: function () {
            this.renderHomeTitle();
            this.renderHomeDesc();
            this.renderTemplatePreview();
        },

        renderHomeTitle: function () {

            if (!this.elements.homeTitlePreview.length) return;

            // Always read latest value from input
            var template = this.elements.homeTitleInput.val() || '%site_title% %sep% %tagline%';

            this.data.homeTitle = template;

            this.elements.homeTitlePreview.text(
                this.parseTags(template)
            );

        },

        renderHomeDesc: function () {

            if (!this.elements.homeDescPreview.length) return;

            // Always read latest value from input
            var template = this.elements.homeDescInput.val() || '%tagline%';

            this.data.homeDesc = template;

            this.elements.homeDescPreview.text(
                this.parseTags(template)
            );

        },

        renderTemplatePreview: function () {
            if (!this.elements.templatePreview.length) return;

            var template = '%site_title% %sep% %tagline%';
            this.elements.templatePreview.text(this.parseTags(template));
        },
        parseTags: function (template) {

            if (!template) return '';

            var now = new Date();

            var replacements = {

                '%site_title%': this.data.siteTitle || '',
                '%tagline%': this.data.siteDescription || '',
                '%sitedesc%': this.data.siteDescription || '',

                '%sep%': this.data.separator || '-',

                '%author_first_name%': this.data.author.first_name || '',
                '%author_last_name%': this.data.author.last_name || '',
                '%author_name%': this.data.author.display_name || '',

                '%current_date%': now.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                }),

                '%current_date%': now.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                }),
              '%current_day%': now.toLocaleDateString('en-US', {
                            weekday: 'long'
                        }),

                '%month%': now.toLocaleDateString('en-US', {
                    month: 'long'
                }),

                '%year%': String(now.getFullYear()),

                '%title%': '',
                '%excerpt%': '',
                '%content%': '',
                '%permalink%': '',
                '%parent_title%': '',
                '%taxonomy_name%': '',
                '%categories%': '',
                '%term_title%': '',
                '%custom_field%': ''
            };

            // Replace known tags
            Object.keys(replacements).forEach(function (tag) {
                template = template.split(tag).join(replacements[tag]);
            });

            // Remove any unknown tags AFTER replacements
            template = template.replace(/%[a-zA-Z0-9_]+%/g, '');

            // Cleanup whitespace
            template = template.replace(/\s+/g, ' ').trim();

            return template;
        },

        /* ===============================
           TAG INSERTION
        =============================== */
        insertTag: function ($btn) {
            var tag = $btn.data('tag');
            var fieldId = $btn.data('field-id');

            if (!fieldId) return;

            var $field = $('#' + fieldId);
            if (!$field.length) return;

            var input = $field[0];
            var start = input.selectionStart || 0;
            var end = input.selectionEnd || 0;

            // Insert tag at cursor position
            input.value =
                input.value.substring(0, start) +
                tag +
                input.value.substring(end);

            // Add space after tag if needed
            if (input.value.substring(start + tag.length, start + tag.length + 1) !== ' ') {
                input.value =
                    input.value.substring(0, start + tag.length) +
                    ' ' +
                    input.value.substring(start + tag.length);
            }

            // Set cursor position after the inserted tag and space
            input.selectionStart = input.selectionEnd = start + tag.length + 1;

            input.focus();
            $field.trigger('input');
        },

        /* ===============================
           SMART TAG MODAL
        =============================== */
        initSmartTags: function () {
            var self = this;

            // Open modal
            $(document).on('click', '.srk-view-all-tags', function (e) {
                e.preventDefault();
                var fieldId = $(this).data('field-id');
                self.showTagsModal(fieldId);
            });

            // Search inside modal
            $(document).on('keyup', '.srk-tag-search', function () {
                var search = $(this).val().toLowerCase();
                var $modal = $(this).closest('.srk-all-tags-modal');

                $modal.find('.srk-tag-item').each(function () {
                    var text = $(this).text().toLowerCase();
                    $(this).toggle(text.includes(search));
                });
            });

            // Insert tag from modal
            $(document).on('click', '.srk-tag-item', function () {
                var tag = $(this).data('tag');
                var fieldId = $(this).data('field-id');

                var $field = $('#' + fieldId);
                if (!$field.length) return;

                var input = $field[0];
                var start = input.selectionStart;
                var end = input.selectionEnd;

                // Insert tag at cursor position
                input.value =
                    input.value.substring(0, start) +
                    tag +
                    input.value.substring(end);

                // Add space after tag if needed
                if (input.value.substring(start + tag.length, start + tag.length + 1) !== ' ') {
                    input.value =
                        input.value.substring(0, start + tag.length) +
                        ' ' +
                        input.value.substring(start + tag.length);
                }

                // Set cursor position
                input.selectionStart = input.selectionEnd = start + tag.length + 1;

                input.focus();
                $field.trigger('input');

                self.hideModal(fieldId);
            });

            // Close modal with close button
            $(document).on('click', '.srk-modal-close', function () {
                var $modal = $(this).closest('.srk-all-tags-modal');
                self.hideModal($modal.attr('id').replace('srk-tags-modal-', ''));
            });

            // Close modal on overlay click
            $(document).on('click', '#srk-modal-overlay', function () {
                $('.srk-all-tags-modal').hide();
                $('#srk-modal-overlay').remove();
            });

            // Prevent modal close when clicking inside modal
            $(document).on('click', '.srk-all-tags-modal', function (e) {
                e.stopPropagation();
            });
        },

        showTagsModal: function (fieldId) {
            // Hide any open modals
            $('.srk-all-tags-modal').hide();

            // Show the specific modal
            var $modal = $('#srk-tags-modal-' + fieldId);
            $modal.show();

            // Add overlay if not exists
            if (!$('#srk-modal-overlay').length) {
                $('<div id="srk-modal-overlay"></div>').appendTo('body');
            }

            // Clear search
            $modal.find('.srk-tag-search').val('').trigger('keyup');
        },

        hideModal: function (fieldId) {
            $('#srk-tags-modal-' + fieldId).hide();
            $('#srk-modal-overlay').remove();
        },

        /* ===============================
           CUSTOM SEPARATOR INIT
        =============================== */
        initCustomSeparator: function () {
            var predefined = ['-', '|', '>', '•', ':', '~', '»', '–', '—', '·', '*', '«', '→', '/'];

            if (!predefined.includes(this.data.separator)) {
                $('input[name="srk_title_separator"][value="custom"]').prop('checked', true);
                this.elements.customSepInput.val(this.data.separator);
                this.elements.customInputWrap.show();
            }
        },

        /* ===============================
        RESET TO DEFAULTS - FIXED
        =============================== */
        resetDefaults: function () {
            if (!confirm('Are you sure you want to reset all global settings to defaults?')) return;

            this.data.separator = '-';
            this.data.homeTitle = '%site_title% %sep% %tagline%';
            this.data.homeDesc = '%tagline%';

            this.elements.homeTitleInput.val(this.data.homeTitle);
            this.elements.homeDescInput.val(this.data.homeDesc);

            $('input[name="srk_title_separator"][value="-"]')
                .prop('checked', true)
                .trigger('change');

            this.renderAll();
            
            // Use parsed values for accurate reset counting
            var parsedTitle = this.parseTags(this.data.homeTitle);
            var parsedDesc = this.parseTags(this.data.homeDesc);
            
            this.updateCharacterCount('title', this.data.homeTitle, parsedTitle);
            this.updateCharacterCount('desc', this.data.homeDesc, parsedDesc);
        }
    };

    /**
     * Simple accordion manager used across Meta Manager tabs.
     * Collapses large sections by default and expands on click,
     * keeping only one section open per group.
     */
    var SRKAccordion = {
        init: function () {
            this.setupGroup($('.srk-global-settings'), '.srk-section', '.srk-section-title');
            this.setupGroup($('.srk-content-meta-manager'), '.srk-post-type-wrapper', '.srk-post-type-header');
            this.setupGroup($('.srk-taxonomy-meta-manager'), '.srk-taxonomy-content', '.srk-taxonomy-header');
            this.setupGroup($('.srk-archives-settings'), '.srk-archive-card', '.srk-archive-header');
            this.setupGroup($('.srk-advanced-settings'), '.srk-section', '.srk-section-title');
        },

        setupGroup: function ($container, itemSelector, headerSelector) {
            if (!$container.length) return;

            var self = this;
            var $items = $container.find(itemSelector);
            if (!$items.length) return;

            $items.each(function (index) {
                var $item = $(this);
                if ($item.data('srkAccordionInit')) {
                    return;
                }
                $item.data('srkAccordionInit', true);

                var $header = $item.find(headerSelector).first();
                if (!$header.length) {
                    return;
                }

                // Wrap everything after the header into a body container once.
                var $bodyParts = $header.nextAll();
                if ($bodyParts.length && !$item.find('.srk-accordion-body').length) {
                    $bodyParts.wrapAll('<div class="srk-accordion-body"></div>');
                }

                var $body = $item.find('.srk-accordion-body').first();
                if (!$body.length) {
                    return;
                }

                if (index === 0) {
                    $item.addClass('srk-accordion-open');
                    $body.show();
                } else {
                    $item.addClass('srk-accordion-closed');
                    $body.hide();
                }

                // Make header interactive.
                $header
                    .css('cursor', 'pointer')
                    .attr('tabindex', '0')
                    .attr('role', 'button');

                $header.on('click', function (e) {
                    e.preventDefault();
                    self.toggleItem($items, $item);
                });

                $header.on('keydown', function (e) {
                    if (e.key !== 'Enter' && e.key !== ' ') return;
                    e.preventDefault();
                    self.toggleItem($items, $item);
                });
            });
        },

        toggleItem: function ($groupItems, $activeItem) {
            if ($activeItem.hasClass('srk-accordion-open')) {
                return;
            }

            $groupItems.each(function () {
                var $item = $(this);
                var $body = $item.find('.srk-accordion-body').first();
                if (!$body.length) return;

                if ($item.is($activeItem)) {
                    $item.removeClass('srk-accordion-closed')
                        .addClass('srk-accordion-open');
                    $body.stop(true, true).slideDown(180);
                } else {
                    $item.removeClass('srk-accordion-open')
                        .addClass('srk-accordion-closed');
                    $body.stop(true, true).slideUp(180);
                }
            });
        }
    };

    $(document).ready(function () {
        SRKGlobalSettings.init();
        SRKAccordion.init();
    });
})(jQuery);