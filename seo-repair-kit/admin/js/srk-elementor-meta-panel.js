/**
 * SEO Repair Kit - Elementor Integration JavaScript
 * 
 * @package SEO_Repair_Kit
 * @since 2.1.3 - Added proper comments, optimized code structure
 * @version 2.1.3
 */

(function ($) {
    "use strict";

    let srkData = window.srkElementorData || {};
    let modalBound = false;

    // store previous values for cancel
    let srkPrev = {
        title: "",
        desc: ""
    };

    $(window).on("elementor:init", function () {
        elementor.on("panel:init", function () {
            const panel = elementor.panel.$el;
            if (!panel) return;

            if (!modalBound) {
                modalBound = true;

                panel.on("click", ".srk-edit-snippet-btn", function (e) {
                    e.preventDefault();
                    openSnippetModal();
                });

                // Initialize character counters
                initCharacterCounters();

                // Initialize reset buttons
                initResetButtons();

                // Initialize advanced settings
                initAdvancedSettings();

                // Load saved settings
                setTimeout(loadAdvancedSettings, 500);

                // Initialize SERP preview
                setTimeout(initializeSERPreview, 500);

            }
        });
    });

    /**
     * Load advanced settings from saved data
     */
    function loadAdvancedSettings() {
        const model = elementor.settings.page.model;

        if (!srkData.advanced_settings) return;

        // Set the "Use Default Settings" toggle
        const useDefault = srkData.advanced_settings.use_default_settings === '1';
        $('input[name="srk_use_default_settings"]')
            .prop('checked', useDefault)
            .trigger('change');

        if (!srkData.advanced_settings || !srkData.advanced_settings.robots_meta) {
            srkData.advanced_settings = {
                use_default_settings: '1',
                robots_meta: {
                    noindex: '0',
                    nofollow: '0',
                    noarchive: '0',
                    notranslate: '0',
                    noimageindex: '0',
                    nosnippet: '0',
                    noodp: '0',
                    max_snippet: -1,
                    max_video_preview: -1,
                    max_image_preview: 'large'
                }
            };
        }

    }

    /**
     * Initialize advanced settings functionality
     */
    function initAdvancedSettings() {
        // Nosnippet disables max-snippet
        $('input[name="srk_robots_nosnippet"]').on('change', function () {
            if ($(this).is(':checked')) {
                $('input[name="srk_max_snippet"]').val(-1);
                $('input[name="srk_max_snippet"]').prop('disabled', true);
            } else {
                $('input[name="srk_max_snippet"]').prop('disabled', false);
            }
            updateRobotsPreviewFromControls();
            updateAdvancedSettings();
        });

        // Toggle handler for Use Default Settings
        $('input[name="srk_use_default_settings"]').on('change', function () {
            const isDefault = $(this).is(':checked');

            if (isDefault) {
                // Show default preview, hide custom controls
                $('.srk-default-robots-preview').closest('.elementor-control').show();
                $('.srk-custom-robots-heading').closest('.elementor-control').hide();
                $('.srk-robots-preview-box').closest('.elementor-control').hide();

                // Hide all robots controls
                $('input[name="srk_robots_noindex"]').closest('.elementor-control').hide();
                $('input[name="srk_robots_nofollow"]').closest('.elementor-control').hide();
                $('input[name="srk_robots_noarchive"]').closest('.elementor-control').hide();
                $('input[name="srk_robots_notranslate"]').closest('.elementor-control').hide();
                $('input[name="srk_robots_noimageindex"]').closest('.elementor-control').hide();
                $('input[name="srk_robots_nosnippet"]').closest('.elementor-control').hide();
                $('input[name="srk_robots_noodp"]').closest('.elementor-control').hide();
                $('input[name="srk_max_snippet"]').closest('.elementor-control').hide();
                $('input[name="srk_max_video_preview"]').closest('.elementor-control').hide();
                $('select[name="srk_max_image_preview"]').closest('.elementor-control').hide();

                // ✅ ALWAYS set to index, follow, max-image-preview:large for default
                $('.srk-robots-preview').text('index, follow, max-image-preview:large');
            } else {
                // Hide default preview, show custom controls
                $('.srk-default-robots-preview').closest('.elementor-control').hide();
                $('.srk-custom-robots-heading').closest('.elementor-control').show();
                $('.srk-robots-preview-box').closest('.elementor-control').show();

                // Show all robots controls
                $('input[name="srk_robots_noindex"]').closest('.elementor-control').show();
                $('input[name="srk_robots_nofollow"]').closest('.elementor-control').show();
                $('input[name="srk_robots_noarchive"]').closest('.elementor-control').show();
                $('input[name="srk_robots_notranslate"]').closest('.elementor-control').show();
                $('input[name="srk_robots_noimageindex"]').closest('.elementor-control').show();
                $('input[name="srk_robots_nosnippet"]').closest('.elementor-control').show();
                $('input[name="srk_robots_noodp"]').closest('.elementor-control').show();
                $('input[name="srk_max_snippet"]').closest('.elementor-control').show();
                $('input[name="srk_max_video_preview"]').closest('.elementor-control').show();
                $('select[name="srk_max_image_preview"]').closest('.elementor-control').show();

                updateRobotsPreviewFromControls();
            }

            updateAdvancedSettings();
        });

        // Any change inside robots = update preview and save
        $(document).on(
            'change input',
            'input[name="srk_robots_noindex"], input[name="srk_robots_nofollow"], input[name="srk_robots_noarchive"], input[name="srk_robots_notranslate"], input[name="srk_robots_noimageindex"], input[name="srk_robots_nosnippet"], input[name="srk_robots_noodp"], input[name="srk_max_snippet"], input[name="srk_max_video_preview"], select[name="srk_max_image_preview"]',
            function () {
                // Only update if not using default settings
                if (!$('input[name="srk_use_default_settings"]').is(':checked')) {
                    updateRobotsPreviewFromControls();
                    updateAdvancedSettings();
                }
            }
        );
    }

    /**
     * Update robots preview from current control values
     */
    function updateRobotsPreviewFromControls() {
        const directives = [];

        // Check if we're using default settings
        const useDefault = $('input[name="srk_use_default_settings"]').is(':checked');

        if (useDefault) {
            // ✅ ALWAYS show index, follow, max-image-preview:large for default settings
            $('.srk-robots-preview').text('index, follow, max-image-preview:large');
            return;
        }

        // Custom settings - build from controls
        directives.push(
            $('input[name="srk_robots_noindex"]').is(':checked') ? 'noindex' : 'index'
        );

        directives.push(
            $('input[name="srk_robots_nofollow"]').is(':checked') ? 'nofollow' : 'follow'
        );

        if ($('input[name="srk_robots_noarchive"]').is(':checked')) directives.push('noarchive');
        if ($('input[name="srk_robots_notranslate"]').is(':checked')) directives.push('notranslate');
        if ($('input[name="srk_robots_noimageindex"]').is(':checked')) directives.push('noimageindex');
        if ($('input[name="srk_robots_nosnippet"]').is(':checked')) directives.push('nosnippet');
        if ($('input[name="srk_robots_noodp"]').is(':checked')) directives.push('noodp');

        const maxSnippet = $('input[name="srk_max_snippet"]').val();
        if (maxSnippet && maxSnippet !== '-1') {
            directives.push('max-snippet:' + maxSnippet);
        }

        const maxVideo = $('input[name="srk_max_video_preview"]').val();
        if (maxVideo && maxVideo !== '-1') {
            directives.push('max-video-preview:' + maxVideo);
        }

        // ✅ ALWAYS add max-image-preview for custom settings
        let maxImage = $('select[name="srk_max_image_preview"]').val();

        if (!maxImage || maxImage === '') {
            maxImage = 'large';
        }

        directives.push('max-image-preview:' + maxImage);


        $('.srk-robots-preview').text(directives.join(', '));
    }

    /**
     * Update advanced settings in Elementor model
     */
    function updateAdvancedSettings() {
        const model = elementor.settings.page.model;
        if (!model) return;

        const useDefault = $('input[name="srk_use_default_settings"]').is(':checked');

        const data = {
            use_default_settings: useDefault ? '1' : '0',
            robots_meta: {
                noindex: $('input[name="srk_robots_noindex"]').is(':checked') ? '1' : '0',
                nofollow: $('input[name="srk_robots_nofollow"]').is(':checked') ? '1' : '0',
                noarchive: $('input[name="srk_robots_noarchive"]').is(':checked') ? '1' : '0',
                notranslate: $('input[name="srk_robots_notranslate"]').is(':checked') ? '1' : '0',
                noimageindex: $('input[name="srk_robots_noimageindex"]').is(':checked') ? '1' : '0',
                nosnippet: $('input[name="srk_robots_nosnippet"]').is(':checked') ? '1' : '0',
                noodp: $('input[name="srk_robots_noodp"]').is(':checked') ? '1' : '0',
                max_snippet: parseInt($('input[name="srk_max_snippet"]').val()) || -1,
                max_video_preview: parseInt($('input[name="srk_max_video_preview"]').val()) || -1,
                max_image_preview: $('select[name="srk_max_image_preview"]').val() || 'large'
            }
        };

        // Set the JSON field
        model.set('srk_advanced_settings_json', JSON.stringify(data));

        // Also update the hidden field directly
        $('input[name="srk_advanced_settings_json"]').val(JSON.stringify(data));
    }

    /**
     * Initialize SERP preview with current values
     */
    function initializeSERPreview() {
        const model = elementor.settings.page.model;
        const title = model.get('srk_meta_title') || srkData.default_title || '';
        const desc = model.get('srk_meta_description') || srkData.default_desc || '';

        const processedTitle = processTags(title, 'title');
        const processedDesc = processTags(desc, 'desc');

        $('.srk-serp-title').text(processedTitle.substring(0, 60));
        $('.srk-serp-desc').text(processedDesc.substring(0, 160));
    }

    /**
     * Process template tags
     */
    function processTags(text, type) {
        if (!text) return '';

        let processed = text;

        const replacements = {
            '%site_title%': srkData.site_name || '',
            '%title%': srkData.post_title || '',
            '%sep%': srkData.separator || '-',
            '%sitedesc%': srkData.site_desc || '',
            '%excerpt%': srkData.post_excerpt || '',
            '%content%': srkData.post_content || '',
            '%author_name%': srkData.author_name || srkData.site_name || '',
            '%author_first_name%': srkData.author_first_name || '',
            '%author_last_name%': srkData.author_last_name || '',
            '%categories%': srkData.categories || 'Uncategorized',
            '%term_title%': srkData.primary_category || 'Uncategorized',
            '%date%': srkData.current_date || new Date().toLocaleDateString(),
            '%year%': srkData.current_year || new Date().getFullYear().toString(),
            '%post_date%': srkData.post_date || new Date().toLocaleDateString(),
            '%permalink%': srkData.permalink || window.location.href
        };

        Object.keys(replacements).forEach(tag => {
            const regex = new RegExp(tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
            processed = processed.replace(regex, replacements[tag]);
        });

        return processed;
    }

    /**
     * Open snippet editor modal
     */
    function openSnippetModal() {
        if (!$("#srk-snippet-modal").length) {
            console.error("❌ Modal not found in DOM.");
            return;
        }

        const model = elementor.settings.page.model;

        // Save previous state for cancel
        srkPrev.title = model.get("srk_meta_title") || "";
        srkPrev.desc = model.get("srk_meta_description") || "";

        $("#srk-modal-title").val(srkPrev.title || srkData.default_title || "");
        $("#srk-modal-desc").val(srkPrev.desc || srkData.default_desc || "");

        $("#srk-snippet-modal").css("display", "flex");

        bindModalEvents();
        updateModalPreview();

        // Update character counts
        updateCharCount($("#srk-modal-title"), 60);
        updateCharCount($("#srk-modal-desc"), 160);
    }

    /**
     * Bind modal events
     */
    function bindModalEvents() {
        // Close & Cancel
        $("#srk-snippet-modal .srk-modal-close, #srk-snippet-modal .srk-modal-cancel")
            .off("click")
            .on("click", function () {
                $("#srk-modal-title").val(srkPrev.title || srkData.default_title || "");
                $("#srk-modal-desc").val(srkPrev.desc || srkData.default_desc || "");
                updateModalPreview();
                syncPanelPreview();
                $("#srk-snippet-modal").hide();
                $(".srk-all-tags-container").hide();
            });

        // Reset All
        // Reset All - UPDATED
        $("#srk-snippet-modal .srk-modal-reset")
            .off("click")
            .on("click", function () {
                // Confirm reset
                if (!confirm('This will reset ALL SEO settings to content type defaults. Continue?')) {
                    return;
                }

                resetToContentTypeDefaults('all');
            });

        // Save Changes
        $("#srk-snippet-modal .srk-modal-save")
            .off("click")
            .on("click", function () {
                const title = $("#srk-modal-title").val();
                const desc = $("#srk-modal-desc").val();

                const model = elementor.settings.page.model;

                // Update actual controls
                $('input[name="srk_meta_title"]').val(title).trigger('input');
                $('textarea[name="srk_meta_description"]').val(desc).trigger('input');

                // Update Elementor model
                model.set("srk_meta_title", title);
                model.set("srk_meta_description", desc);

                // Force save document
                if (typeof $e !== "undefined") {
                    $e.run('document/save/update');
                }

                $("#srk-snippet-modal").hide();
                srkToast("✅ Snippet saved successfully!", "success");
            });

        // Reset section buttons - UPDATED
        $(".srk-reset-section-btn").off("click").on("click", function () {
            const section = $(this).data("section");

            // Confirm reset
            if (!confirm('This will reset SEO settings to content type defaults. Continue?')) {
                return;
            }

            resetToContentTypeDefaults(section);
        });

        // Live typing with character count
        $("#srk-modal-title, #srk-modal-desc")
            .off("input")
            .on("input", function () {
                const $this = $(this);
                const max = $this.is("#srk-modal-title") ? 60 : 160;
                updateCharCount($this, max);
                updateModalPreview();
            });

        // Initialize view all tags
        initViewAllTags();

        // Initialize search functionality
        initTagsSearch();
    }

    /**
     * Update modal preview
     */
    function updateModalPreview() {
        let title = $("#srk-modal-title").val() || srkData.default_title || "";
        let desc = $("#srk-modal-desc").val() || srkData.default_desc || "";

        title = processTags(title, 'title');
        desc = processTags(desc, 'desc');

        if (title.length > 60) {
            title = title.substring(0, 57) + '...';
        }
        if (desc.length > 160) {
            desc = desc.substring(0, 157) + '...';
        }

        $(".srk-preview-title").text(title);
        $(".srk-preview-desc").text(desc);
    }

    /**
     * Sync panel preview from modal
     */
    function syncPanelPreview() {
        let title = $('input[name="srk_meta_title"]').val() || srkData.default_title || "";
        let desc = $('textarea[name="srk_meta_description"]').val() || srkData.default_desc || "";

        title = processTags(title, 'title');
        desc = processTags(desc, 'desc');

        $(".srk-serp-title").text(title.substring(0, 60));
        $(".srk-serp-desc").text(desc.substring(0, 160));

        updateCharacterCounter($('input[name="srk_meta_title"]'));
        updateCharacterCounter($('textarea[name="srk_meta_description"]'));
    }

    /**
     * Update character count
     */
    function updateCharCount($input, max) {
        const count = $input.val().length;
        const $counter = $input.closest(".srk-title-section, .srk-desc-section").find(".srk-char-count");
        $counter.text(count + "/" + max);

        $counter.css("color",
            count === 0 ? "#646970" :
                count > max ? "#d63638" :
                    count > max * 0.9 ? "#dba617" :
                        "#1d2327"
        );
    }

    /**
     * Update character counter
     */
    function updateCharacterCounter($field) {
        if (!$field.length) return;
        const value = $field.val() || '';
        const charCount = value.length;
        const fieldName = $field.attr('name') || '';

        const $counter = $(`.srk-counter[data-target="${fieldName}"] .srk-char-count`);
        if ($counter.length) {
            $counter.text(charCount);
        }
    }

    /**
     * Initialize character counters
     */
    function initCharacterCounters() {
        $('.srk-snippet-field').on('input', function () {
            updateCharacterCounter($(this));
        });

        $('.srk-snippet-field').each(function () {
            updateCharacterCounter($(this));
        });
    }

    /**
 * Initialize reset buttons
 */
    function initResetButtons() {
        $('.srk-reset-btn').off('click').on('click', function () {
            const type = $(this).data('type');

            // Confirm reset
            if (!confirm('This will reset SEO settings to content type defaults. Continue?')) {
                return;
            }

            // Delete all meta and reset to content type defaults
            resetToContentTypeDefaults(type);
        });
    }

    /**
     * Reset to content type defaults - deletes meta and applies templates
     */
    function resetToContentTypeDefaults(type) {
        const model = elementor.settings.page.model;
        if (!model) return;

        // Get content type defaults from srkData
        const postType = srkData.post_type || 'post';
        const contentTypeSettings = srkData.content_type_settings || {};

        // Determine which template to use based on post type
        let defaultTitle = '%title% %sep% %site_title%';
        let defaultDesc = '%excerpt%';

        if (contentTypeSettings && contentTypeSettings.title) {
            defaultTitle = contentTypeSettings.title;
        }
        if (contentTypeSettings && contentTypeSettings.desc) {
            defaultDesc = contentTypeSettings.desc;
        }

        // Handle 'all' or specific type
        if (type === 'all' || type === 'title') {
            // Clear title meta
            model.set('srk_meta_title', '');
            $('input[name="srk_meta_title"]').val('').trigger('input');
            $("#srk-modal-title").val('');

            // Update preview with content type template
            const processedTitle = processTags(defaultTitle, 'title');
            $('.srk-serp-title').text(processedTitle.substring(0, 60));
            $(".srk-preview-title").text(processedTitle.substring(0, 60));
        }

        if (type === 'all' || type === 'desc') {
            // Clear description meta
            model.set('srk_meta_description', '');
            $('textarea[name="srk_meta_description"]').val('').trigger('input');
            $("#srk-modal-desc").val('');

            // Update preview with content type template
            const processedDesc = processTags(defaultDesc, 'desc');
            $('.srk-serp-desc').text(processedDesc.substring(0, 160));
            $(".srk-preview-desc").text(processedDesc.substring(0, 160));
        }

        updateCharCount($("#srk-modal-title"), 60);
        updateCharCount($("#srk-modal-desc"), 160);

        // Trigger save to persist the empty values (which will fall back to content type)
        if (typeof $e !== "undefined") {
            $e.run('document/save/update');
        }

        // Close modal if open
        if (type === 'all') {
            $("#srk-snippet-modal").hide();
        }

        // Call AJAX to delete post meta from database
        $.ajax({
            url: srkData.ajax_url,
            type: 'POST',
            data: {
                action: 'srk_reset_elementor_meta',
                nonce: srkData.nonce,
                post_id: srkData.post_id,
                field: type // 'title', 'desc', or 'all'
            },
            success: function (response) {
                if (response.success) {
                    srkToast("🔄 Reset to content type defaults!", "warning");
                } else {
                    console.error('❌ Meta reset failed:', response.data);
                }
            },
            error: function (xhr, status, error) {
                console.error('❌ AJAX error:', error);
            }
        });
    }

    /**
     * Initialize view all tags functionality
     */
    function initViewAllTags() {
        $(".srk-view-all-tags-btn").off("click").on("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            const section = $(this).data("section");
            const $container = section === "title" ? $("#srk-all-title-tags") : $("#srk-all-desc-tags");

            $(".srk-all-tags-container").slideUp(200);

            if ($container.is(":visible")) {
                $container.slideUp(200);
            } else {
                $container.slideDown(200);
                $container.find(".srk-tags-search").focus();
            }
        });

        $(".srk-hide-tags-btn").off("click").on("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            const section = $(this).data("section");
            const $container = section === "title" ? $("#srk-all-title-tags") : $("#srk-all-desc-tags");
            $container.slideUp(200);
        });

        $(".srk-modal-tag").off("click").on("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            const tag = $(this).data("tag");
            const section = $(this).data("section");
            const $input = section === "title" ? $("#srk-modal-title") : $("#srk-modal-desc");

            insertTagAtCursor($input[0], tag);
            $input.trigger("input");
        });

        $(document).on("click", ".srk-all-tag-btn", function (e) {
            e.preventDefault();
            e.stopPropagation();
            const tag = $(this).data("tag");
            const section = $(this).data("section");
            const $input = section === "title" ? $("#srk-modal-title") : $("#srk-modal-desc");

            insertTagAtCursor($input[0], tag);
            $input.trigger("input");
        });
    }

    /**
     * Initialize tags search
     */
    function initTagsSearch() {
        $(".srk-tags-search").off("input").on("input", function () {
            const section = $(this).data("section");
            const searchTerm = $(this).val().toLowerCase().trim();
            const $container = section === "title" ? $("#srk-all-title-tags") : $("#srk-all-desc-tags");

            let visibleCount = 0;

            $container.find(".srk-all-tag-btn").each(function () {
                const $btn = $(this);
                const tagName = $btn.find("div div:first-child").text().toLowerCase();
                const tagDesc = $btn.find("div div:nth-child(2)").text().toLowerCase();
                const tagValue = $btn.find("div div:last-child").text().toLowerCase();

                if (tagName.includes(searchTerm) ||
                    tagDesc.includes(searchTerm) ||
                    tagValue.includes(searchTerm) ||
                    searchTerm === "") {
                    $btn.show();
                    visibleCount++;
                } else {
                    $btn.hide();
                }
            });

            const $noResults = $container.find(".srk-no-results");

            if (visibleCount === 0 && searchTerm !== "") {
                if (!$noResults.length) {
                    $container.find(".srk-tags-list").append(`
                        <div class="srk-no-results" style="text-align: center; padding: 20px; color: #646970; font-size: 13px; font-style: italic;">
                            No tags found matching "${searchTerm}"
                        </div>
                    `);
                }
            } else {
                $noResults.remove();
            }

            if (searchTerm === "") {
                $container.find(".srk-all-tag-btn").show();
            }
        });

        $(".srk-tags-search").off("keyup").on("keyup", function (e) {
            if (e.key === "Escape") {
                $(this).val("").trigger("input");
                $(this).blur();
            }
        });
    }

    /**
     * Insert tag at cursor position
     */
    function insertTagAtCursor(input, tag) {
        if (!input) return;

        if (input.setSelectionRange) {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            const value = input.value;

            const beforeText = value.substring(0, start);
            const afterText = value.substring(end);
            const needsSpaceBefore = start > 0 && !/\s$/.test(beforeText);
            const needsSpaceAfter = !/^\s/.test(afterText);

            const spaceBefore = needsSpaceBefore ? " " : "";
            const spaceAfter = needsSpaceAfter ? " " : "";

            input.value = beforeText + spaceBefore + tag + spaceAfter + afterText;
            input.selectionStart = input.selectionEnd = start + tag.length + spaceBefore.length + spaceAfter.length;
        } else {
            input.value += " " + tag + " ";
        }
        input.focus();

        $('.srk-modal-tag[data-tag="' + tag + '"]').css({
            'background': '#d1ecf1',
            'border-color': '#bee5eb',
            'color': '#0c5460'
        }).animate({
            'background': '#f0f6fc',
            'border-color': '#c5d9ed',
            'color': '#2271b1'
        }, 500);
    }

    /**
     * Show toast notification
     */
    function srkToast(message, type = "success") {
        $("#srk-toast").remove();
        const toast = $(`<div id="srk-toast" class="srk-toast ${type}">${message}</div>`);
        $("body").append(toast);
        setTimeout(() => toast.addClass("show"), 20);
        setTimeout(() => {
            toast.removeClass("show");
            setTimeout(() => toast.remove(), 250);
        }, 2200);
    }

    // Update preview on any change
    $(window).on("elementor:init", function () {
        elementor.on("panel:init", function () {
            elementor.settings.page.model.on('change', function (model) {
                if (model.changed.srk_meta_title || model.changed.srk_meta_description) {
                    setTimeout(initializeSERPreview, 100);
                }
            });

            $(document).on('input', '#srk-modal-title, #srk-modal-desc', function () {
                updateModalPreview();
            });
        });
    });

})(jQuery);