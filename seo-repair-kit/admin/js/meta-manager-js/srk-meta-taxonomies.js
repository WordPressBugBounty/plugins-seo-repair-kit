/*
 * SEO Repair Kit - Taxonomies Meta Manager
 * Version: 2.1.3
 *  @author     TorontoDigits <support@torontodigits.com>
 */
(function ($) {
    'use strict';

    const isTermEditScreen = document.body.classList.contains('term-php') ||
        (document.body.classList.contains('edit-tags-php') && $('.srk-term-seo-wrapper').length);
    const currentTab = $('.nav-tab-active').attr('href')?.split('tab=')[1];
    const isTaxonomiesTab = currentTab === 'taxonomies';

    if (isTermEditScreen) {
        initSrkTermUI();
        return;
    }
    if (!isTaxonomiesTab) return;

    function initSrkTermUI() {
        const $wrap = $('.srk-term-seo-wrapper');
        if (!$wrap.length) return;

        // ✅ Get data from PHP data attributes
        const termData = {
            name: $wrap.data('term-name') || 'Sample Term',
            description: $wrap.data('term-description') || '',
            count: $wrap.data('term-count') || '0',
            taxonomy: $wrap.data('taxonomy') || 'category',
            taxonomyLabel: $wrap.data('taxonomy-label') || 'Category', // Add this line
            parentTerm: $wrap.data('parent-term') || '',
            link: $wrap.data('term-link') || '',
            separator: $wrap.data('separator') || '-',
            siteName: $wrap.data('site-name') || 'My Website',
            siteUrl: $wrap.data('site-url') || 'https://example.com',
            tagline: $wrap.data('tagline') || '',
            currentDate: $wrap.data('current-date') || new Date().toLocaleDateString(),
            currentDay: $wrap.data('current-day') || new Date().getDate(),
            currentMonth: $wrap.data('current-month') || new Date().toLocaleString('default', { month: 'long' }),
            currentYear: $wrap.data('current-year') || new Date().getFullYear()
        };

        // Tab switching
        $wrap.find('.srk-tab-btn').on('click', function () {
            const tab = $(this).data('tab');
            $wrap.find('.srk-tab-btn').removeClass('active').filter('[data-tab="' + tab + '"]').addClass('active');
            $wrap.find('.srk-tab-pane').hide().removeClass('active').filter('[data-tab="' + tab + '"]').show().addClass('active');
        });

        // ✅ Toggle handler - ONLY handles toggle UI, no preview updates
        initTermToggleHandler($wrap);

        // ✅ Live preview with complete data - ONLY this updates preview
        $wrap.find('#srk_term_title, #srk_term_description').on('input', function () {
            updateTermPreviewRealtime($wrap, termData);
        });

        // Initialize preview on load
        updateTermPreviewRealtime($wrap, termData);

        // Initialize smart tags
        initTermSmartTags($wrap, termData);
    }

    /**
     * Update term preview with real-time tag replacement
     * FIXED: Proper tag replacement order to prevent conflicts
     */
    function updateTermPreviewRealtime($wrap, termData) {
        let title = ($('#srk_term_title').val() || '').trim();
        let desc = ($('#srk_term_description').val() || '').trim();

        // If title is empty, use default template with actual term data
        if (!title) {
            title = termData.name + ' ' + termData.separator + ' ' + termData.siteName;
        }

        // If description is empty, use actual term description
        if (!desc) {
            desc = termData.description || '';
        }

        // CRITICAL FIX: Order replacements from longest to shortest
        const replacements = [
            // Long tags first (to prevent partial matches)
            { tag: '%term_description%', value: termData.description },
            { tag: '%parent_categories%', value: termData.parentTerm },
            { tag: '%parent_term%', value: termData.parentTerm },
            { tag: '%current_date%', value: termData.currentDate },
            { tag: '%site_title%', value: termData.siteName },
            { tag: '%post_count%', value: termData.count },
            { tag: '%taxonomy%', value: termData.taxonomyLabel || termData.taxonomy },
            { tag: '%permalink%', value: termData.link },
            { tag: '%tagline%', value: termData.tagline },
            { tag: '%sitedesc%', value: termData.tagline },
            { tag: '%date%', value: termData.currentDate },
            { tag: '%year%', value: termData.currentYear || new Date().getFullYear() },
            { tag: '%month%', value: termData.currentYear || new Date().getFullYear(), },
            { tag: '%term%', value: termData.name },
            { tag: '%sep%', value: termData.separator },
            { tag: '%custom_field%', value: '' },
            { tag: '%page%', value: '' }
        ];

        // Apply replacements in order
        replacements.forEach(function (item) {
            // Use regex with global flag to replace all occurrences
            const regex = new RegExp(item.tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
            title = title.replace(regex, item.value);
            desc = desc.replace(regex, item.value);
        });

        // Clean up multiple spaces and trim
        title = title.replace(/\s+/g, ' ').trim();
        desc = desc.replace(/\s+/g, ' ').trim();

        // Truncate description for preview (Google shows ~160 chars)
        if (desc.length > 160) {
            desc = desc.substring(0, 157) + '...';
        }

        // Update preview elements with actual values
        $wrap.find('.srk-term-preview-title').text(title || termData.name);
        $wrap.find('.srk-term-preview-desc').text(desc || termData.description);

        // Also update URL preview if exists
        const cleanUrl = termData.link.replace(/^https?:\/\//, '');
        $wrap.find('.srk-preview-url').text(cleanUrl);
    }

    /**
     * Initialize Smart Tags for Single Term Edit Page
     */
    function initTermSmartTags($wrap, termData) {

        // Track last focused field
        let lastFocusedField = null;
        let lastCursorPosition = null;

        // Track focus and cursor position
        $(document).on('focus', '#srk_term_title, #srk_term_description', function () {
            lastFocusedField = $(this).attr('id');
        });

        $(document).on('keyup click', '#srk_term_title, #srk_term_description', function () {
            lastFocusedField = $(this).attr('id');
            lastCursorPosition = this.selectionStart;
        });

        // Handle "View all tags" button
        $wrap.on('click', '.srk-view-all-tags', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const fieldId = $(this).data('field-id');
            const taxonomy = $(this).data('taxonomy');
            const fieldType = $(this).data('field-type');

            showTermTagsModal(fieldId, taxonomy, fieldType);
        });

        // Handle modal tag item click
        $wrap.on('click', '.srk-modal-tag-item', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $item = $(this);
            const tag = $item.data('tag');
            const fieldId = $item.closest('.srk-all-tags-modal').data('field-id');

            if (!tag || !fieldId) return;

            insertTagAtCursorTerm(tag, fieldId, lastCursorPosition);
            hideTermTagsModal(fieldId);

            // Update preview
            const title = ($('#srk_term_title').val() || '').trim();
            const desc = ($('#srk_term_description').val() || '').trim();
            const termName = $wrap.closest('form').find('input[name="name"]').val() || '';
            $wrap.find('.srk-term-preview-title').text(title || termName || '(No title)');
            $wrap.find('.srk-term-preview-desc').text(desc || '(No description)');
        });

        // Search in modal
        $wrap.on('keyup', '.srk-tag-search', function () {
            const searchTerm = $(this).val().toLowerCase();
            const $modal = $(this).closest('.srk-all-tags-modal');

            $modal.find('.srk-modal-tag-item').each(function () {
                const $item = $(this);
                const tagLabel = $item.find('strong').text().toLowerCase();
                const tagDesc = $item.find('.srk-tag-description').text().toLowerCase();
                const tagCode = $item.find('.srk-tag-code').text().toLowerCase();

                if (tagLabel.includes(searchTerm) ||
                    tagDesc.includes(searchTerm) ||
                    tagCode.includes(searchTerm)) {
                    $item.show();
                } else {
                    $item.hide();
                }
            });
        });

        // Close modal button
        $wrap.on('click', '.srk-modal-close', function () {
            const $modal = $(this).closest('.srk-all-tags-modal');
            const fieldId = $modal.data('field-id');
            hideTermTagsModal(fieldId);
        });

        // Close modal when clicking outside (on overlay)
        $(document).on('click', '#srk-modal-overlay', function () {
            $('.srk-all-tags-modal').hide();
            $('#srk-modal-overlay').remove();
        });

        // Learn more link
        $wrap.on('click', '.srk-learn-more', function (e) {
            e.preventDefault();
            alert('Smart Tags help you dynamically insert variables into your SEO titles and descriptions. Each tag will be replaced with actual content when displayed on your site.');
        });

        // Handle quick tag buttons (the 3 default buttons)
        $wrap.on('click', '.srk-insert-tag', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const tag = $(this).data('tag');
            const fieldId = $(this).data('field-id');

            if (!tag || !fieldId) return;

            insertTagAtCursorTerm(tag, fieldId, lastCursorPosition);

            // Update preview
            const title = ($('#srk_term_title').val() || '').trim();
            const desc = ($('#srk_term_description').val() || '').trim();
            const termName = $wrap.closest('form').find('input[name="name"]').val() || '';
            $wrap.find('.srk-term-preview-title').text(title || termName || '(No title)');
            $wrap.find('.srk-term-preview-desc').text(desc || '(No description)');
        });
        // FIXED: Live preview on input with termData
        $wrap.find('#srk_term_title, #srk_term_description').on('input', function () {
            updateTermPreviewRealtime($wrap, termData);
        });

        // Initialize preview on load
        updateTermPreviewRealtime($wrap, termData);

        // Initialize toggle handler
        initTermToggleHandler($wrap);
    }
    /**
     * Initialize Toggle Handler for Term Edit Page
     * FIXED: Only handles toggle UI, does NOT touch preview (preview handled separately)
     */
    /**
     * Initialize Toggle Handler for Term Edit Page
     * FIXED: Properly handles default ON state for fresh installs
     */
    function initTermToggleHandler($wrap) {
        const $toggle = $wrap.find('#srk_term_use_default');
        const $robotsRow = $wrap.find('.srk-term-robots-row');
        const $toggleDescription = $wrap.find('.srk-toggle-description');

        // Check if elements exist
        if (!$toggle.length || !$robotsRow.length) {
            return;
        }

        function updateToggleUI(isChecked) {
            // ✅ FIX: Update description text based on state
            if ($toggleDescription.length) {
                if (isChecked) {
                    $toggleDescription.addClass('srk-default-active');
                    // Add active indicator if not present
                    if (!$toggleDescription.find('.srk-active-indicator').length) {
                        $toggleDescription.prepend('<strong class="srk-active-indicator" style="color: #2271b1; display: block; margin-bottom: 2px;">✓ Currently Active</strong>');
                    }
                } else {
                    $toggleDescription.removeClass('srk-default-active');
                    $toggleDescription.find('.srk-active-indicator').remove();
                }
            }

            if (isChecked) {
                // DEFAULT MODE - Hide robots row with animation
                $robotsRow.slideUp(200);
                // Disable checkboxes so they don't submit
                $robotsRow.find('input[type="checkbox"]').prop('disabled', true);

            } else {
                // CUSTOM MODE - Show robots row with animation
                $robotsRow.slideDown(200);
                // Enable checkboxes
                $robotsRow.find('input[type="checkbox"]').prop('disabled', false);
            }
        }

        // ✅ FIX: Read actual checkbox state on load (don't assume false)
        const initialState = $toggle.is(':checked');

        // Handle toggle change
        $toggle.on('change', function () {
            updateToggleUI($(this).is(':checked'));
        });

        // ✅ FIX: Initialize on load with actual state
        updateToggleUI(initialState);
    }


    /**
     * Show Tags Modal for Term Edit
     */
    function showTermTagsModal(fieldId, taxonomy, fieldType) {
        // Hide all other modals first
        $('.srk-all-tags-modal').hide();
        $('#srk-modal-overlay').remove();

        const $modal = $('#srk-tags-modal-' + fieldId);
        if (!$modal.length) return;

        // Store current field info on modal
        $modal.data('field-id', fieldId);
        $modal.data('taxonomy', taxonomy);
        $modal.data('field-type', fieldType);

        // Position modal centered on screen
        $modal.css({
            'position': 'fixed',
            'top': '50%',
            'left': '50%',
            'transform': 'translate(-50%, -50%)',
            'z-index': '10000',
            'background': 'white',
            'border': '1px solid #ddd',
            'border-radius': '8px',
            'box-shadow': '0 5px 30px rgba(0,0,0,0.3)',
            'width': '500px',
            'max-height': '80vh',
            'overflow': 'hidden',
            'display': 'block'
        });

        // Clear search
        $modal.find('.srk-tag-search').val('');
        $modal.find('.srk-modal-tag-item').show();

        // Add overlay
        $('<div id="srk-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;"></div>')
            .appendTo('body');

        // Focus search input
        setTimeout(function () {
            $modal.find('.srk-tag-search').focus();
        }, 100);
    }

    /**
     * Hide Tags Modal for Term Edit
     */
    function hideTermTagsModal(fieldId) {
        $('#srk-tags-modal-' + fieldId).hide();
        $('#srk-modal-overlay').remove();
    }

    /**
     * Insert tag at cursor position (for term edit)
     */
    function insertTagAtCursorTerm(tag, fieldId, cursorPos) {
        const $field = $('#' + fieldId);
        if (!$field.length) return;

        const field = $field[0];
        const currentValue = field.value;

        // Get position (use provided cursorPos or current selection)
        let startPos = cursorPos !== null ? cursorPos : field.selectionStart;
        let endPos = cursorPos !== null ? cursorPos : field.selectionEnd;

        // Default to end if no position
        if (startPos === undefined || startPos === null) {
            startPos = currentValue.length;
            endPos = currentValue.length;
        }

        // Intelligently add spaces
        let insertText = tag;

        // Add leading space if needed
        if (startPos > 0 && currentValue.charAt(startPos - 1) !== ' ' &&
            currentValue.charAt(startPos - 1) !== '') {
            insertText = ' ' + insertText;
        }

        // Add trailing space if needed
        if (endPos < currentValue.length && currentValue.charAt(endPos) !== ' ') {
            insertText = insertText + ' ';
        }

        // Insert the tag
        const newValue = currentValue.substring(0, startPos) +
            insertText +
            currentValue.substring(endPos);

        field.value = newValue;

        // Move cursor after inserted text
        const newCursorPos = startPos + insertText.length;
        field.selectionStart = field.selectionEnd = newCursorPos;

        // Focus the field
        field.focus();

        // Trigger input event
        $field.trigger('input');
    }
    // Initialize Taxonomy Manager
    const SRK_Taxonomy_Manager = {

        // Data
        data: {
            currentTaxonomy: 'category',
            previewData: {
                term: 'Sample Category',
                term_description: 'Sample description for this category with detailed content about the topic.',
                site_name: typeof srkTaxonomyData !== 'undefined' ? srkTaxonomyData.siteName : 'My Website',
                site_url: typeof srkTaxonomyData !== 'undefined' ? srkTaxonomyData.siteUrl : 'https://example.com'
            }
        },

        // Cache DOM elements
        cacheElements: function () {
            this.elements = {
                // Tabs
                taxonomyTabs: $('.srk-taxonomy-tab'),
                taxonomyContents: $('.srk-taxonomy-content'),

                // REMOVED: visibilityToggles

                // Title Inputs
                titleInputs: $('.srk-taxonomy-title-input'),

                // Description Inputs
                descInputs: $('.srk-taxonomy-desc-input'),

                // Robots Checkboxes
                robotCheckboxes: $('.srk-robot-checkbox'),
                indexCheckbox: $('.srk-index-checkbox'),
                noindexCheckbox: $('.srk-noindex-checkbox'),

                // Tag Buttons
                tagButtons: $('.srk-taxonomy-tag'),

                // Preview
                previewBoxes: $('.srk-taxonomy-preview'),
                previewTitles: $('.srk-preview-title'),
                previewDescriptions: $('.srk-preview-desc'),

                // Buttons
                resetButtons: $('.srk-reset-taxonomy'),
                saveButton: $('#submit'),

                // Inner Tabs
                innerTabs: $('.srk-inner-tab'),
                innerTabContents: $('.srk-inner-tab-content'),

                // Advanced Section
                advancedToggles: $('.srk-use-default-advanced'),
                advancedSections: $('.srk-advanced-section'),
                robotsMetaContainers: $('.srk-robots-meta-container'),
                defaultModeMessages: $('.srk-default-mode-message'),

                // Toggle Labels
                toggleLabels: $('.srk-toggle-label')
            };
        },

        // Initialize
        init: function () {
            this.cacheElements();
            this.bindEvents();
            this.initTabs();
            this.updateAllPreviews();
            this.validateRobots();

            // Initialize Smart Tags
            this.initSmartTags();

            // Initialize advanced sections
            this.initAdvancedSections();

            // REMOVED: initVisibilityToggles

            // 🔥 INITIAL SYNC (page load)
            this.elements.taxonomyContents.each((i, el) => {
                this.syncSnippetAndImageLimits($(el));
            });
        },

        // Initialize Smart Tags System
        initSmartTags: function () {
            // Bind smart tag events
            this.bindSmartTagEvents();
        },

        // Add this at the top of SRK_Taxonomy_Manager object, after data property
        lastFocusedField: null,
        lastCursorPosition: null,

        // Bind smart tag events
        bindSmartTagEvents: function () {
            const self = this;

            // ✅ FIX: Track last focused field and cursor position before clicking buttons
            // Naya code:
            $(document).on('focus', '.srk-taxonomy-title-input, .srk-taxonomy-desc-input', function () {
                self.lastFocusedField = $(this).attr('id');
            });

            // Naya code:
            $(document).on('keyup click', '.srk-taxonomy-title-input, .srk-taxonomy-desc-input', function () {
                self.lastFocusedField = $(this).attr('id');
                self.lastCursorPosition = this.selectionStart;
            });

            // Remove any existing handlers first to prevent duplicates
            $(document).off('click.srkQuickTag');
            $(document).off('click.srkModalTagItem');
            $(document).off('click.srkViewAllTags');

            // Handle quick tag buttons (outside modal)
            $(document).on('click.srkQuickTag', '.srk-quick-tag-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const $btn = $(this);
                const tag = $btn.data('tag');
                const fieldId = $btn.data('field-id');

                if (!tag || !fieldId) {
                    return;
                }

                self.insertTagAtCursor(tag, fieldId);
            });

            // Handle modal tag items
            $(document).on('click.srkModalTagItem', '.srk-modal-tag-item', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const $item = $(this);
                const tag = $item.data('tag');
                const fieldId = $item.data('field-id');

                if (!tag || !fieldId) {
                    return;
                }

                // Insert the tag
                self.insertTagAtCursor(tag, fieldId);

                // Hide the modal
                self.hideModal(fieldId);
            });

            // Handle View all tags button
            $(document).on('click.srkViewAllTags', '.srk-view-all-tags', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const fieldId = $(this).data('field-id');
                self.showAllTagsModal(fieldId);
            });

            // Search in tags modal
            $(document).on('keyup', '.srk-tag-search', function () {
                const searchTerm = $(this).val().toLowerCase();
                const $modal = $(this).closest('.srk-all-tags-modal');
                self.filterTags($modal, searchTerm);
            });

            // Close modal
            $(document).on('click', '.srk-modal-close', function () {
                const $modal = $(this).closest('.srk-all-tags-modal');
                const modalId = $modal.attr('id') || '';
                const fieldId = modalId.replace('srk-tags-modal-', '');
                self.hideModal(fieldId);
            });

            // Close modal when clicking outside
            $(document).on('click', function (e) {
                if ($(e.target).hasClass('srk-all-tags-modal')) {
                    const $modal = $(e.target);
                    const modalId = $modal.attr('id') || '';
                    const fieldId = modalId.replace('srk-tags-modal-', '');
                    self.hideModal(fieldId);
                }
            });

            // Learn more link
            $(document).on('click', '.srk-learn-more', function (e) {
                e.preventDefault();
                alert('Smart Tags help you dynamically insert variables into your SEO titles and descriptions. Each tag will be replaced with actual content when displayed on your site.');
            });
        },

        // Insert tag at cursor position
        insertTagAtCursor: function (tag, fieldId) {
            const $field = $('#' + fieldId);
            if (!$field.length) return;

            const field = $field[0];

            // ✅ FIX: Focus the field first to ensure proper selection handling
            $field.focus();

            // Get cursor position - use stored position if available, otherwise use current
            let startPos = this.lastCursorPosition;
            let endPos = this.lastCursorPosition;

            // If we have a stored position for this field, use it
            if (this.lastFocusedField === fieldId && startPos !== null) {
                // Use stored position
            } else {
                // Default to end of content if no position stored or different field
                startPos = field.value.length;
                endPos = field.value.length;
            }

            const currentValue = field.value;

            // ✅ FIX: Intelligently add spaces around tag
            let insertText = tag;

            // Check if we need a leading space
            if (startPos > 0 && currentValue.charAt(startPos - 1) !== ' ') {
                insertText = ' ' + insertText;
            }

            // Check if we need a trailing space
            if (endPos < currentValue.length && currentValue.charAt(endPos) !== ' ') {
                insertText = insertText + ' ';
            }

            // Insert tag at cursor position
            field.value = currentValue.substring(0, startPos) +
                insertText +
                currentValue.substring(endPos);

            // ✅ FIX: Move cursor after inserted tag and store new position
            const newCursorPos = startPos + insertText.length;
            field.selectionStart = field.selectionEnd = newCursorPos;
            this.lastCursorPosition = newCursorPos;

            // Keep focus
            field.focus();

            // Trigger input event for preview update
            $field.trigger('input');
        },     // Show all tags modal
        showAllTagsModal: function (fieldId) {
            // Hide all modals first
            $('.srk-all-tags-modal').hide();

            // Remove any existing overlay
            $('#srk-modal-overlay').remove();

            // Show specific modal
            const $modal = $('#srk-tags-modal-' + fieldId);

            if (!$modal.length) {
                console.warn('Modal not found for field:', fieldId);
                return;
            }

            $modal.show().css({
                'position': 'fixed',
                'top': '50%',
                'left': '50%',
                'transform': 'translate(-50%, -50%)',
                'z-index': '10000',
                'background': 'white',
                'border': '1px solid #ddd',
                'border-radius': '8px',
                'box-shadow': '0 5px 30px rgba(0,0,0,0.3)',
                'width': '500px',
                'max-height': '80vh',
                'overflow': 'hidden'
            });

            // Clear search
            $modal.find('.srk-tag-search').val('');
            $modal.find('.srk-tag-item').show();

            // Add overlay
            $('<div id="srk-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;"></div>').appendTo('body');
        },
        // Hide modal
        hideModal: function (fieldId) {
            $('#srk-tags-modal-' + fieldId).hide();
            $('#srk-modal-overlay').remove();
        },

        // Filter tags in modal
        // Filter tags in modal
        filterTags: function ($modal, searchTerm) {
            $modal.find('.srk-modal-tag-item').each(function () {
                const $item = $(this);
                const tagLabel = $item.find('strong').text().toLowerCase();
                const tagDesc = $item.find('.srk-tag-description').text().toLowerCase();

                if (tagLabel.includes(searchTerm) || tagDesc.includes(searchTerm)) {
                    $item.show();
                } else {
                    $item.hide();
                }
            });
        },

        // Initialize advanced sections
        initAdvancedSections: function () {
            const self = this;

            // ✅ FIX: Init on page load - check actual checkbox state for EACH taxonomy separately
            this.elements.taxonomyContents.each(function () {
                const $content = $(this);
                const $toggle = $content.find('.srk-use-default-advanced');
                const $section = $content.find('.srk-advanced-section');
                const $robotsBox = $section.find('.srk-robots-meta-container');
                const $defaultMsg = $section.find('.srk-default-mode-message');

                if ($toggle.is(':checked')) {
                    $robotsBox.hide();
                    $defaultMsg.show();
                    $robotsBox.find('input, select').prop('disabled', true);
                } else {
                    $robotsBox.show();
                    $defaultMsg.hide();
                    $robotsBox.find('input, select').prop('disabled', false);
                }
            });

            // ✅ FIX: Check if any custom robots are set that should force toggle off (per taxonomy)
            this.elements.taxonomyContents.each(function () {
                const $content = $(this);
                const $container = $content.find('.srk-robots-meta-container');
                const $checkboxes = $container.find('input[type="checkbox"]:checked');
                const $previewFields = $container.find('input[type="number"]').filter(function () {
                    return $(this).val() !== '-1';
                });

                if ($checkboxes.length > 0 || $previewFields.length > 0) {
                    const $toggle = $content.find('.srk-use-default-advanced');
                    if ($toggle.is(':checked')) {
                        $toggle.prop('checked', false).trigger('change');
                    }
                }
            });
        },
        // Toggle taxonomy advanced section - FIXED VERSION
        toggleTaxonomyAdvanced: function ($toggle) {
            const isDefault = $toggle.is(':checked');
            // ✅ FIX: Find the closest taxonomy content container first
            const $tabContent = $toggle.closest('.srk-taxonomy-content');
            const $section = $toggle.closest('.srk-advanced-section');
            const $robotsBox = $section.find('.srk-robots-meta-container');
            const $defaultMsg = $section.find('.srk-default-mode-message');

            if (isDefault) {
                $robotsBox.hide();
                $defaultMsg.show();
                // ✅ FIX: Only disable inputs within THIS taxonomy's container
                $robotsBox.find('input, select').prop('disabled', true);
            } else {
                $defaultMsg.hide();
                $robotsBox.show();
                // ✅ FIX: Only enable inputs within THIS taxonomy's container
                $robotsBox.find('input, select').prop('disabled', false);
            }

            // ✅ FIX: Update preview only for THIS taxonomy
            this.updatePreview($tabContent);
        },

        // REMOVED: toggleAdvancedSection function

        // REMOVED: updateToggleLabel function

        // Switch inner tabs
        switchInnerTab: function ($btn) {
            const tab = $btn.data('tab');
            const $container = $btn.closest('.srk-taxonomy-content');

            $container.find('.srk-inner-tab').removeClass('active');
            $btn.addClass('active');

            $container.find('.srk-inner-tab-content').removeClass('active');
            $container.find('.srk-inner-tab-content[data-tab="' + tab + '"]').addClass('active');
        },

        // Bind events
        bindEvents: function () {
            const self = this;

            // ============ TAB SWITCHING ============
            this.elements.taxonomyTabs.on('click', function (e) {
                e.preventDefault();
                const taxonomy = $(this).data('taxonomy');
                self.switchTaxonomyTab(taxonomy);
            });

            // ============ INNER TAB SWITCHING ============
            this.elements.innerTabs.on('click', function () {
                self.switchInnerTab($(this));
            });

            // ============ ADVANCED TOGGLE ============
            this.elements.advancedToggles.on('change', function () {
                // ✅ FIX: Pass the specific toggle element, let toggleTaxonomyAdvanced find the context
                self.toggleTaxonomyAdvanced($(this));
            });
            // ============ TITLE INPUT ============
            this.elements.titleInputs.on('input', function () {
                const tabContent = $(this).closest('.srk-taxonomy-content');
                self.updatePreview(tabContent);
            });

            // ============ DESCRIPTION INPUT ============
            this.elements.descInputs.on('input', function () {
                const tabContent = $(this).closest('.srk-taxonomy-content');
                self.updatePreview(tabContent);
            });

            // ============ ROBOTS CHECKBOXES ============
            this.elements.robotCheckboxes.on('change', function () {
                self.validateRobots();

                // Update preview if needed
                const tabContent = $(this).closest('.srk-taxonomy-content');
                // new logic
                self.syncSnippetAndImageLimits(tabContent);

                self.updatePreview(tabContent);
            });



            // Index/Noindex mutual exclusivity
            $('.srk-index-checkbox, .srk-noindex-checkbox').on('change', function () {
                const isIndex = $(this).hasClass('srk-index-checkbox');
                const otherClass = isIndex ? 'srk-noindex-checkbox' : 'srk-index-checkbox';

                if ($(this).is(':checked')) {
                    $(this).closest('.srk-taxonomy-content')
                        .find('.' + otherClass)
                        .prop('checked', false);
                }

                self.validateRobots();
            });
            // REAL-TIME UI SYNC FOR nosnippet & noimageindex
            $(document).on(
                'change',
                '.srk-noimageindex-checkbox, .srk-nosnippet-checkbox',
                function () {
                    const $tabContent = $(this).closest('.srk-taxonomy-content');
                    SRK_Taxonomy_Manager.syncSnippetAndImageLimits($tabContent);
                }
            );

            // ============ FORM SUBMIT ============
            $('form').on('submit', function (e) {
                return self.validateBeforeSubmit();
            });
        },

        // Initialize tabs
        initTabs: function () {
            // Show first tab by default
            const firstTab = this.elements.taxonomyTabs.first();
            if (firstTab.length) {
                this.switchTaxonomyTab(firstTab.data('taxonomy'));
            }
        },

        // Switch taxonomy tab
        switchTaxonomyTab: function (taxonomy) {
            // Update active tab
            this.elements.taxonomyTabs.removeClass('nav-tab-active');
            this.elements.taxonomyTabs.filter('[data-taxonomy="' + taxonomy + '"]').addClass('nav-tab-active');

            // Show corresponding content
            this.elements.taxonomyContents.removeClass('srk-active-content').addClass('srk-hidden-content');
            this.elements.taxonomyContents.filter('[data-taxonomy="' + taxonomy + '"]')
                .removeClass('srk-hidden-content')
                .addClass('srk-active-content');

            this.data.currentTaxonomy = taxonomy;
        },

        // Update preview for a specific taxonomy
        updatePreview: function ($tabContent) {
            const taxonomy = $tabContent.data('taxonomy');
            const $previewBox = $tabContent.find('.srk-taxonomy-preview');

            if (!$previewBox.length) return;

            // REMOVED: visibilityEnabled check

            // Get values
            const titleTemplate = $tabContent.find('.srk-taxonomy-title-input').val() || this.getDefaultTitle(taxonomy);
            const descTemplate = $tabContent.find('.srk-taxonomy-desc-input').val() || this.getDefaultDescription(taxonomy);

            // Get robots status
            const indexChecked = $tabContent.find('.srk-index-checkbox').is(':checked');
            const noindexChecked = $tabContent.find('.srk-noindex-checkbox').is(':checked');

            // Prepare data for replacement
            const previewData = {
                term: taxonomy === 'category' ? 'Sample Category' : taxonomy === 'post_tag' ? 'Sample Tag' : 'Sample Term',
                term_description: 'Sample description for this ' + taxonomy + ' with detailed content.',
                site_title: this.data.previewData.site_name,
                sitename: this.data.previewData.site_name, // keep for backwards compatibility
                sep: typeof srkTaxonomyData !== 'undefined' ? srkTaxonomyData.separator : '|',
                page: '2',
                taxonomy: taxonomy,
                post_count: '15',
                current_date: new Date().toLocaleDateString(),
                year: new Date().getFullYear(),
                month: new Date().toLocaleString('default', { month: 'long' }),
                day: new Date().getDate(),
                tagline: 'Your site tagline here',
                permalink: this.data.previewData.site_url + '/' + taxonomy + '/sample-term/',
                parent_categories: 'Parent ' + taxonomy.charAt(0).toUpperCase() + taxonomy.slice(1),
                parent_term: 'Parent ' + taxonomy.charAt(0).toUpperCase() + taxonomy.slice(1),
                custom_field: 'Custom Field Value'
            };

            // Replace variables in title
            let titlePreview = titleTemplate;
            Object.keys(previewData).forEach(key => {
                const placeholder = '%' + key + '%';
                titlePreview = titlePreview.replace(new RegExp(placeholder, 'g'), previewData[key]);
            });

            // Replace variables in description
            let descPreview = descTemplate;
            Object.keys(previewData).forEach(key => {
                const placeholder = '%' + key + '%';
                descPreview = descPreview.replace(new RegExp(placeholder, 'g'), previewData[key]);
            });

            // Truncate description
            if (descPreview.length > 160) {
                descPreview = descPreview.substring(0, 157) + '...';
            }

            // Build preview HTML
            const previewHTML = `
                <div class="srk-google-preview">
                    <div class="srk-preview-url">${this.data.previewData.site_url}/<span class="srk-taxonomy-slug">${taxonomy}</span>/sample-${taxonomy}/</div>
                    <div class="srk-preview-title">${titlePreview}</div>
                    <div class="srk-preview-desc">${descPreview}</div>
                </div>
            `;

            $previewBox.html(previewHTML);
        },

        // Update all previews
        updateAllPreviews: function () {
            this.elements.taxonomyContents.each((index, content) => {
                this.updatePreview($(content));
            });
        },

        // Get default title template for taxonomy
        getDefaultTitle: function (taxonomy) {
            const defaults = {
                'category': '%term% %sep% %site_title%',
                'post_tag': '%term% %sep% %site_title%'
            };
            return defaults[taxonomy] || '%term% %sep% %site_title%';
        },

        // Get default description template for taxonomy
        getDefaultDescription: function (taxonomy) {
            return '%term_description%';
        },

        // Validate robots checkboxes
        validateRobots: function () {
            // REMOVED: visibility check

            // Show warning if both index and noindex are checked (shouldn't happen with our logic)
            this.elements.taxonomyContents.each((index, content) => {
                const $content = $(content);
                const indexChecked = $content.find('.srk-index-checkbox').is(':checked');
                const noindexChecked = $content.find('.srk-noindex-checkbox').is(':checked');

                if (indexChecked && noindexChecked) {
                    $content.find('.srk-robots-warning').show();
                } else {
                    $content.find('.srk-robots-warning').hide();
                }
            });
        },
        /**
         * - noimageindex → hide max image preview
         * - nosnippet → hide max snippet
         */
        syncSnippetAndImageLimits: function ($content) {

            // ===== No Image Index =====
            const noImageIndexChecked = $content
                .find('input[name*="[noimageindex]"]')
                .is(':checked');

            const $maxImagePreviewField = $content
                .find('select[name*="[max_image_preview]"]')
                .closest('.srk-preview-field');

            if (noImageIndexChecked) {
                // UI hide
                $maxImagePreviewField.hide();

                // force safe value (PHP ignore karega)
                $content.find('select[name*="[max_image_preview]"]').val('none');
            } else {
                $maxImagePreviewField.show();
            }

            // ===== No Snippet =====
            const noSnippetChecked = $content
                .find('input[name*="[nosnippet]"]')
                .is(':checked');

            const $maxSnippetField = $content
                .find('input[name*="[max_snippet]"]')
                .closest('.srk-preview-field');

            if (noSnippetChecked) {
                // UI hide
                $maxSnippetField.hide();

                // force disabled value
                $content.find('input[name*="[max_snippet]"]').val('-1');
            } else {
                $maxSnippetField.show();
            }
        },

        // Reset taxonomy settings
        resetTaxonomySettings: function (taxonomy) {
            const $content = this.elements.taxonomyContents.filter('[data-taxonomy="' + taxonomy + '"]');

            // REMOVED: visibility toggle reset

            // Reset to defaults
            $content.find('.srk-taxonomy-title-input').val(this.getDefaultTitle(taxonomy));
            $content.find('.srk-taxonomy-desc-input').val(this.getDefaultDescription(taxonomy));

            // Reset robots to defaults
            $content.find('.srk-index-checkbox').prop('checked', true);
            $content.find('.srk-noindex-checkbox').prop('checked', false);
            $content.find('.srk-follow-checkbox').prop('checked', true);
            $content.find('.srk-nofollow-checkbox').prop('checked', false);
            $content.find('.srk-noarchive-checkbox').prop('checked', false);
            $content.find('.srk-noimageindex-checkbox').prop('checked', false);
            $content.find('.srk-nosnippet-checkbox').prop('checked', false);
            $content.find('.srk-max-snippet').val('-1');
            $content.find('.srk-max-image-preview').val('large');

            // Reset advanced toggle
            $content.find('.srk-use-default-advanced').prop('checked', true);

            // Update UI
            this.updatePreview($content);
            this.validateRobots();
            this.toggleTaxonomyAdvanced($content.find('.srk-use-default-advanced'));

            // 🔥 Re-sync snippet & image limits after reset
            this.syncSnippetAndImageLimits($content);

            // Show success message
            this.showMessage(taxonomy + ' settings reset to defaults.', 'success');
        },

        // Validate before form submit
        validateBeforeSubmit: function () {
            let isValid = true;
            let messages = [];

            // Check for conflicting robots settings
            this.elements.taxonomyContents.each((index, content) => {
                const $content = $(content);
                const indexChecked = $content.find('.srk-index-checkbox').is(':checked');
                const noindexChecked = $content.find('.srk-noindex-checkbox').is(':checked');

                if (indexChecked && noindexChecked) {
                    const taxonomy = $content.data('taxonomy');
                    messages.push(taxonomy + ': Cannot have both Index and No Index selected');
                    isValid = false;
                }
            });

            if (!isValid) {
                alert('Please fix the following issues:\n\n' + messages.join('\n'));
                return false;
            }

            return true;
        },

        // Show message
        showMessage: function (message, type) {
            // Create or get message container
            let $messageBox = $('.srk-taxonomy-message');
            if (!$messageBox.length) {
                $messageBox = $('<div class="srk-taxonomy-message notice"></div>').insertBefore('form');
            }

            // Set message
            $messageBox
                .removeClass('notice-success notice-error notice-warning')
                .addClass('notice-' + (type === 'success' ? 'success' : type === 'error' ? 'error' : 'warning'))
                .html('<p>' + message + '</p>')
                .show();

            // Auto-hide after 3 seconds
            setTimeout(() => {
                $messageBox.fadeOut();
            }, 3000);
        }
    };

    // Initialize when document is ready
    $(document).ready(function () {
        SRK_Taxonomy_Manager.init();

    });
    /* ============================================
   GLOBAL RESET TAXONOMIES (LIKE GLOBAL TAB)
     ============================================ */

    $('.srk-taxonomy-meta-manager').on('click', '.srk-reset-taxonomies', function (e) {

        e.preventDefault();

        if (!confirm('Are you sure you want to reset all taxonomy settings?')) {
            return;
        }

        $('.srk-taxonomy-content').each(function () {

            const $box = $(this);
            const taxonomy = $box.data('taxonomy');

            // REMOVED: visibility toggle reset

            //  2. Reset title template
            if (taxonomy === 'post_tag') {
                $box.find('.srk-taxonomy-title-input')
                    .val('%term% %sep% %site_title%');
            } else {
                $box.find('.srk-taxonomy-title-input')
                    .val('%term% %sep% %site_title%');
            }

            //  3. Reset description
            $box.find('.srk-taxonomy-desc-input')
                .val('%term_description%');

            //  4. Reset robots checkboxes ONLY
            $box.find('input[name*="[robots]["]')
                .prop('checked', false);

            //  5. Reset numeric robots fields
            $box.find('input[name*="[max_snippet]"]').val('-1');
            $box.find('input[name*="[max_video_preview]"]').val('-1');

            //  6. Reset select
            $box.find('select[name*="[max_image_preview]"]').val('large');

            //  7. Reset advanced toggle
            $box.find('.srk-use-default-advanced')
                .prop('checked', true)
                .trigger('change');

            //  8. Sync UI again
            SRK_Taxonomy_Manager.syncSnippetAndImageLimits($box);
            SRK_Taxonomy_Manager.updatePreview($box);

        });

        alert('All taxonomy settings reset to defaults.');

    });

})(jQuery);