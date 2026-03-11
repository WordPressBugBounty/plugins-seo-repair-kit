/*
 * SEO Repair Kit - content type Settings
 * Complete implementation following All in One SEO design
 * Version: 2.1.3
 *  @author     TorontoDigits <support@torontodigits.com>
 */

jQuery(function ($) {
    var sep = srkMetaData.separator || '-';
    var currentTarget = 'title';
    var currentPostWrapper = null;
    var editingTag = null;
    var tagStartPos = -1;
    var tagEndPos = -1;

    // Reset function me refreshDisplayForInput hata dein:
    $('.srk-content-meta-manager').on('click', '.srk-reset-content-types-button', function (e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to reset all content type settings?')) {
            return;
        }

        $('.srk-title-input[type="text"], .srk-desc-input[type="text"]').each(function () {
            var postType = $(this).data('post-type');

            if ($(this).hasClass('srk-title-input')) {
                $(this).val('%title% %sep% %site_title%');
            } else {
                $(this).val('%excerpt%');
            }

            // Trigger input for preview update
            $(this).trigger('input');
            updatePreview($(this).closest('.srk-post-type-wrapper'));
        });

        $('[id^="srk_robots_"]').prop('checked', false);
        alert('Content Type settings reset to defaults.');
    });

    /* Open Tag Modal for inserting new tag - WITH POST TYPE SPECIFIC TAGS */
    $('.srk-content-meta-manager').on('click', '.srk-view-all-tags', function (e) {
        e.preventDefault();

        currentTarget = $(this).data('target') || 'title';
        currentPostWrapper = $(this).closest('.srk-post-type-wrapper');
        editingTag = null; // Reset editing mode

        // Get the post type
        var postTypeKey = currentPostWrapper.data('post-type');

        // Update modal title
        var postTypeLabel = currentPostWrapper.find('h3').first().text();
        var modalTitle = currentTarget === 'title'
            ? 'Select a Tag for ' + postTypeLabel + ' Title'
            : 'Select a Tag for ' + postTypeLabel + ' Description';
        $('#srk-tag-modal .srk-modal-title').text(modalTitle);

        // Hide delete button in insert mode
        $('.srk-modal-delete').addClass('hidden');

        // Load tags specific to this post type
        loadTagsForPostType(postTypeKey);

        // Show modal
        $('#srk-tag-modal').addClass('active');

        // Focus search input
        setTimeout(function () {
            $('#srk-tag-modal .srk-search-input').focus();
        }, 100);
    });

    /* Function to load tags for specific post type - WITH PROPER FILTERING */
    function loadTagsForPostType(postTypeKey) {
        var tagList = $('#srk-tag-modal .srk-tag-list');
        tagList.empty();

        // Get tags from hidden div
        $('#srk-tags-' + postTypeKey + ' .srk-tag-data').each(function () {
            var $this = $(this);
            var tag = $this.data('tag');
            var name = $this.data('name');
            var label = $this.data('label');
            var description = $this.data('description');

            // Skip problematic plural tags
            var problematicTags = ['Posts Title', 'Posts Excerpt', 'Pages Title', 'Pages Excerpt'];
            if (problematicTags.includes(label)) {
                return;
            }

            // Apply filtering based on post type
            var shouldSkip = false;

            // For POSTS: Show only post-specific and global tags
            if (postTypeKey === 'post') {
                if (label.indexOf('Page') !== -1) {
                    shouldSkip = true;
                }
            }

            // For PAGES: Show only page-specific and global tags
            if (postTypeKey === 'page') {
                if (label.indexOf('Post') !== -1) {
                    shouldSkip = true;
                }
            }

            // For CPTs: Show only CPT-specific and global tags
            if (postTypeKey !== 'post' && postTypeKey !== 'page') {
                if (label.indexOf('Post') !== -1 || label.indexOf('Page') !== -1) {
                    shouldSkip = true;
                }
            }

            if (shouldSkip) {
                return;
            }

            var li = $('<li class="srk-tag-item"></li>');
            li.attr('data-tag', tag);
            li.attr('data-name', name);

            li.html(
                '<div class="srk-tag-icon-wrapper">+</div>' +
                '<div class="srk-tag-info">' +
                '<h4 class="srk-tag-name">' + label + '</h4>' +
                '<div class="srk-tag-code">' + tag + '</div>' +
                '<p class="srk-tag-description">' + description + '</p>' +
                '</div>'
            );

            tagList.append(li);
        });
    }


    /* Close Modal */
    function closeModal() {
        $('#srk-tag-modal').removeClass('active');
        $('#srk-tag-modal .srk-search-input').val('');
        $('.srk-tag-item').removeClass('hidden').removeClass('selected');
        // Remove active class from all tag chips
        $('.srk-input-tag-chip').removeClass('active');
        editingTag = null;
        tagStartPos = -1;
        tagEndPos = -1;
    }

    $('.srk-modal-close').on('click', closeModal);

    /* Delete Tag */
    $('.srk-modal-delete').on('click', function () {
        if (editingTag && currentPostWrapper && tagStartPos >= 0) {
            var input = currentTarget === 'title'
                ? currentPostWrapper.find('.srk-title-input')
                : currentPostWrapper.find('.srk-desc-input');
            var value = input.val();
            var newValue = value.substring(0, tagStartPos) + value.substring(tagEndPos);
            newValue = newValue.replace(/\s+/g, ' ').trim();
            input.val(newValue);
            refreshDisplayForInput(input);
            updatePreview(currentPostWrapper);
        }
        closeModal();
    });

    // Close on overlay click
    $('.srk-tag-modal-overlay').on('click', function (e) {
        if ($(e.target).hasClass('srk-tag-modal-overlay')) {
            closeModal();
        }
    });

    // Close on ESC key
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#srk-tag-modal').hasClass('active')) {
            closeModal();
        }
    });

    /* Search Tags */
    $('#srk-tag-modal .srk-search-input').on('input', function () {
        var searchTerm = $(this).val().toLowerCase();

        $('.srk-tag-item').each(function () {
            var tagName = $(this).data('name');
            var tagDescription = $(this).find('.srk-tag-description').text().toLowerCase();

            if (tagName.indexOf(searchTerm) !== -1 || tagDescription.indexOf(searchTerm) !== -1) {
                $(this).removeClass('hidden');
            } else {
                $(this).addClass('hidden');
            }
        });
    });

    /* Select Tag from Modal */
    $('#srk-tag-modal').on('click', '.srk-tag-item', function () {
        var tag = $(this).data('tag');
        if (currentPostWrapper) {
            var input = currentTarget === 'title'
                ? currentPostWrapper.find('.srk-title-input')
                : currentPostWrapper.find('.srk-desc-input');
            if (editingTag && tagStartPos >= 0) {
                var value = input.val();
                var newValue = value.substring(0, tagStartPos) + tag + ' ' + value.substring(tagEndPos);
                newValue = newValue.replace(/\s+/g, ' ');
                input.val(newValue);
            } else {
                var val = input.val() || '';
                if (val && !val.endsWith(' ')) val += ' ';
                input.val(val + tag + ' ');
            }
            refreshDisplayForInput(input);
            updatePreview(currentPostWrapper);
        }
        closeModal();
    });

    // YE FUNCTION UPDATE KAR DEIN - plain input me value insert kare:
    $('.srk-content-meta-manager').on('click', '.srk-tag', function () {
        var tag = $(this).data('tag');
        var postWrapper = $(this).closest('.srk-post-type-wrapper');
        var tagWrapper = $(this).closest('.srk-tag-input-wrapper');

        // Find the actual text input (not hidden)
        var input = tagWrapper.find('.srk-title-input[type="text"], .srk-desc-input[type="text"]');

        if (!input.length) return;

        var val = input.val() || '';
        if (val && !val.endsWith(' ')) val += ' ';
        input.val(val + tag + ' ');

        // Trigger input event for preview update
        input.trigger('input');
    });

    // Modal se tag select karne wala function update karein:
    $('#srk-tag-modal').on('click', '.srk-tag-item', function () {
        var tag = $(this).data('tag');
        if (currentPostWrapper) {
            // Find the actual text input directly
            var inputSelector = currentTarget === 'title' ? '.srk-title-input[type="text"]' : '.srk-desc-input[type="text"]';
            var input = currentPostWrapper.find(inputSelector);

            if (editingTag && tagStartPos >= 0) {
                var value = input.val();
                var newValue = value.substring(0, tagStartPos) + tag + ' ' + value.substring(tagEndPos);
                newValue = newValue.replace(/\s+/g, ' ');
                input.val(newValue);
            } else {
                var val = input.val() || '';
                if (val && !val.endsWith(' ')) val += ' ';
                input.val(val + tag + ' ');
            }

            // Trigger input event for preview update
            input.trigger('input');
            updatePreview(currentPostWrapper);
        }
        closeModal();
    });

    /* Update preview function */
    function updatePreview(postWrapper) {
        var titleInput = postWrapper.find('.srk-title-input');
        var descInput = postWrapper.find('.srk-desc-input');
        var postType = titleInput.data('post-type');

        var titleValue = titleInput.val() || '%title% %sep% %site_title%';
        var descValue = descInput.val() || '%excerpt%';

        var siteUrl = srkMetaData.siteUrl;
        var siteName = srkMetaData.siteName;
        var siteDesc = srkMetaData.siteDesc;

        var postTitle = 'Sample ' + (postType === 'post' ? 'Post' : (postType === 'page' ? 'Page' : postType)) + ' Title';
        var postExcerpt = 'Sample excerpt from a ' + (postType === 'post' ? 'post' : (postType === 'page' ? 'page' : postType)) + '.';
        var pageTitle = 'Sample Page Title';

        // Get data from localized script
        var authorFirstName = srkMetaData.authorFirstName || 'John';
        var authorLastName = srkMetaData.authorLastName || 'Doe';
        var authorName = srkMetaData.authorName || 'John Doe';
        var categories = srkMetaData.categories || 'Technology, SEO';
        var categoryTitle = srkMetaData.categoryTitle || 'Technology';
        var currentDate = srkMetaData.currentDate || new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        var currentDay = srkMetaData.currentDay || new Date().getDate();
        var currentMonth = srkMetaData.currentMonth || new Date().toLocaleDateString('en-US', { month: 'long' });
        var currentYear = srkMetaData.currentYear || new Date().getFullYear();
        var customField = srkMetaData.customField || 'Custom Field Value';
        var permalink = srkMetaData.permalink || (siteUrl + '/sample-post/');
        var postContent = srkMetaData.postContent || 'Sample post content text...';
        var postDate = srkMetaData.postDate || new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        var postDay = srkMetaData.postDay || new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).getDate();

        var titlePreview = titleValue
            .replace(/%sep%/g, sep)
            .replace(/%site_title%/g, siteName)
            .replace(/%title%/g, pageTitle)
            .replace(/%sitedesc%/g, siteDesc)
            .replace(/%title%/g, postTitle)
            // Add dynamic post type tags
            .replace(new RegExp('%title%', 'g'), postTitle)
            .replace(new RegExp('%excerpt%', 'g'), postExcerpt)
            .replace(new RegExp('%' + postType + '_date%', 'g'), postDate)
            .replace(new RegExp('%' + postType + '_day%', 'g'), postDay)
            .replace(new RegExp('%content%', 'g'), postContent)
            .replace(/%excerpt%/g, postExcerpt)
            .replace(/%author_first_name%/g, authorFirstName)
            .replace(/%author_last_name%/g, authorLastName)
            .replace(/%author_name%/g, authorName)
            .replace(/%categories%/g, categories)
            .replace(/%term_title%/g, categoryTitle)
            .replace(/%date%/g, currentDate)
            .replace(/%day%/g, currentDay)
            .replace(/%month%/g, currentMonth)
            .replace(/%year%/g, currentYear)
            .replace(/%custom_field%/g, customField)
            .replace(/%permalink%/g, permalink)
            .replace(/%content%/g, postContent)
            .replace(/%post_date%/g, postDate)
            .replace(/%post_day%/g, postDay);

        var descPreview = descValue
            .replace(/%sep%/g, sep)
            .replace(/%site_title%/g, siteName)
            .replace(/%title%/g, pageTitle)
            .replace(/%sitedesc%/g, siteDesc)
            .replace(/%title%/g, postTitle)
            // Add dynamic post type tags
            .replace(new RegExp('%title%', 'g'), postTitle)
            .replace(new RegExp('%excerpt%', 'g'), postExcerpt)
            .replace(new RegExp('%' + postType + '_date%', 'g'), postDate)
            .replace(new RegExp('%' + postType + '_day%', 'g'), postDay)
            .replace(new RegExp('%content%', 'g'), postContent)
            .replace(/%excerpt%/g, postExcerpt)
            .replace(/%author_first_name%/g, authorFirstName)
            .replace(/%author_last_name%/g, authorLastName)
            .replace(/%author_name%/g, authorName)
            .replace(/%categories%/g, categories)
            .replace(/%term_title%/g, categoryTitle)
            .replace(/%date%/g, currentDate)
            .replace(/%day%/g, currentDay)
            .replace(/%month%/g, currentMonth)
            .replace(/%year%/g, currentYear)
            .replace(/%custom_field%/g, customField)
            .replace(/%permalink%/g, permalink)
            .replace(/%content%/g, postContent)
            .replace(/%post_date%/g, postDate)
            .replace(/%post_day%/g, postDay);

        $('#preview-' + postType).html(`
        <div style="padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
            <div style="color:#70757a;font-size:12px;margin-bottom:5px;">${siteUrl}</div>
            <div style="color:#1a0dab;font-size:16px;margin-bottom:3px;">${titlePreview}</div>
            <div style="color:#3c4043;font-size:13px;">${descPreview}</div>
        </div>
    `);
    }

    /* Initial preview load */
    $('.srk-post-type-wrapper').each(function () {
        updatePreview($(this));
    });

    /* Tab Switching */
    $('.srk-content-tabs-nav a').on('click', function (e) {
        e.preventDefault();
        var tab = $(this);
        var postWrapper = tab.closest('.srk-post-type-wrapper');

        // Extract tab name from href or data attribute
        var href = tab.attr('href') || '';
        var targetTab = '';
        if (href.indexOf('post_type_tab_') > -1) {
            var match = href.match(/post_type_tab_[^=]+=([^&]+)/);
            if (match) {
                targetTab = match[1];
            }
        }

        // If no match, try to get from data attribute or text
        if (!targetTab) {
            var tabText = tab.text().toLowerCase().trim();
            if (tabText.indexOf('title') > -1 || tabText.indexOf('description') > -1) {
                targetTab = 'title-description';
            } else if (tabText.indexOf('advanced') > -1) {
                targetTab = 'advanced';
            }
        }

        // Update active tab
        postWrapper.find('.srk-content-tab').removeClass('active');
        tab.addClass('active');

        // Update active pane
        postWrapper.find('.srk-tab-pane').removeClass('active');
        var targetPane = postWrapper.find('.srk-tab-pane[data-tab="' + targetTab + '"]');
        if (targetPane.length) {
            targetPane.addClass('active');
        }
    });

    /* Toggle Default Settings */
    $('.srk-content-meta-manager').on('change', '[id^="srk_use_default_"]', function () {
        var checkbox = $(this);
        var postTypeKey = checkbox.attr('id').replace('srk_use_default_', '');
        var robotsContainer = $('#srk-robots-meta-' + postTypeKey);

        if (checkbox.is(':checked')) {
            robotsContainer.slideUp();
        } else {
            robotsContainer.slideDown();
        }
    });

    /* Update Robots Meta Preview */
    function updateRobotsPreview(postTypeKey) {
        var container = $('#srk-robots-meta-' + postTypeKey);
        var useDefault = $('#srk_use_default_' + postTypeKey).is(':checked');

        if (useDefault) {
            return; // Don't show preview when using defaults
        }

        var robotsMeta = {
            noindex: $('#srk_robots_noindex_' + postTypeKey).is(':checked') ? '1' : '0',
            nofollow: $('#srk_robots_nofollow_' + postTypeKey).is(':checked') ? '1' : '0',
            noarchive: $('#srk_robots_noarchive_' + postTypeKey).is(':checked') ? '1' : '0',
            notranslate: $('#srk_robots_notranslate_' + postTypeKey).is(':checked') ? '1' : '0',
            noimageindex: $('#srk_robots_noimageindex_' + postTypeKey).is(':checked') ? '1' : '0',
            nosnippet: $('#srk_robots_nosnippet_' + postTypeKey).is(':checked') ? '1' : '0',
            noodp: $('#srk_robots_noodp_' + postTypeKey).is(':checked') ? '1' : '0',
            max_snippet: $('#srk_robots_nosnippet_' + postTypeKey).is(':checked') ? '-1' : ($('#srk_max_snippet_' + postTypeKey).val() || '-1'),
            max_video_preview: $('#srk_max_video_preview_' + postTypeKey).val() || '-1',
            max_image_preview: $('#srk_robots_noimageindex_' + postTypeKey).is(':checked') ? 'none' : ($('#srk_max_image_preview_' + postTypeKey).val() || 'large')
        };

        var directives = [];
        if (robotsMeta.noindex === '1') {
            directives.push('noindex');
        } else {
            directives.push('index');
        }
        if (robotsMeta.nofollow === '1') {
            directives.push('nofollow');
        } else {
            directives.push('follow');
        }
        if (robotsMeta.noarchive === '1') directives.push('noarchive');
        if (robotsMeta.notranslate === '1') directives.push('notranslate');
        if (robotsMeta.noimageindex === '1') directives.push('noimageindex');
        if (robotsMeta.nosnippet === '1') directives.push('nosnippet');
        if (robotsMeta.noodp === '1') directives.push('noodp');

        directives.push('max-snippet:' + robotsMeta.max_snippet);
        directives.push('max-video-preview:' + robotsMeta.max_video_preview);
        directives.push('max-image-preview:' + robotsMeta.max_image_preview);

        var preview = directives.join(', ');
        // You can add a preview element if needed
        // container.find('.srk-robots-preview-code').text(preview);
    }

    /* Update robots preview on change */
    $('.srk-content-meta-manager').on('change', '[id^="srk_robots_"], [id^="srk_max_"]', function () {
        var id = $(this).attr('id');
        var postTypeKey = '';

        // Try to extract from the full ID (format: srk_robots_noindex_post or srk_max_snippet_page)
        var match = id.match(/_(post|page)$/);
        if (match) {
            postTypeKey = match[1];
        } else {
            // Fallback: try to find the closest post type wrapper
            var wrapper = $(this).closest('.srk-post-type-wrapper');
            if (wrapper.length) {
                var defaultCheckbox = wrapper.find('[id^="srk_use_default_"]');
                if (defaultCheckbox.length) {
                    var wrapperId = defaultCheckbox.attr('id');
                    postTypeKey = wrapperId.replace('srk_use_default_', '');
                }
            }
        }

        if (postTypeKey) {
            updateRobotsPreview(postTypeKey);
        }
    });

    /* Advanced Settings Conditional Fields */
    function toggleAdvancedFields(postTypeKey) {
        var wrapper = $('#srk-robots-meta-' + postTypeKey);

        // Toggle Max Snippet field based on No Snippet checkbox
        var noSnippetCheckbox = $('#srk_robots_nosnippet_' + postTypeKey);
        var maxSnippetField = wrapper.find('.srk-preview-field:has(#srk_max_snippet_' + postTypeKey + ')');

        if (noSnippetCheckbox.is(':checked')) {
            maxSnippetField.hide();
        } else {
            maxSnippetField.show();
        }

        // Toggle Max Image Preview field based on No Image Index checkbox
        var noImageIndexCheckbox = $('#srk_robots_noimageindex_' + postTypeKey);
        var maxImagePreviewField = wrapper.find('.srk-preview-field:has(#srk_max_image_preview_' + postTypeKey + ')');

        if (noImageIndexCheckbox.is(':checked')) {
            maxImagePreviewField.hide();
        } else {
            maxImagePreviewField.show();
        }
    }

    // Initialize on page load
    $('.srk-post-type-wrapper').each(function () {
        var postTypeKey = $(this).data('post-type');
        toggleAdvancedFields(postTypeKey);
    });

    // When No Snippet checkbox changes
    $('.srk-content-meta-manager').on('change', '[id^="srk_robots_nosnippet_"]', function () {
        var checkbox = $(this);
        var postTypeKey = checkbox.attr('id').replace('srk_robots_nosnippet_', '');
        toggleAdvancedFields(postTypeKey);
    });

    // When No Image Index checkbox changes
    $('.srk-content-meta-manager').on('change', '[id^="srk_robots_noimageindex_"]', function () {
        var checkbox = $(this);
        var postTypeKey = checkbox.attr('id').replace('srk_robots_noimageindex_', '');
        toggleAdvancedFields(postTypeKey);
    });

    // Also update when Use Default Settings toggles
    $('.srk-content-meta-manager').on('change', '[id^="srk_use_default_"]', function () {
        var checkbox = $(this);
        var postTypeKey = checkbox.attr('id').replace('srk_use_default_', '');

        // Only update if not using default settings (fields are visible)
        if (!checkbox.is(':checked')) {
            toggleAdvancedFields(postTypeKey);
        }
    });
    /* Force correct toggle state on page load */
    $('.srk-post-type-wrapper').each(function () {

        var wrapper = $(this);
        var checkbox = wrapper.find('[id^="srk_use_default_"]');
        var postTypeKey = checkbox.attr('id').replace('srk_use_default_', '');
        var robotsContainer = $('#srk-robots-meta-' + postTypeKey);

        if (checkbox.is(':checked')) {
            robotsContainer.hide();
        } else {
            robotsContainer.show();
        }

    });
});