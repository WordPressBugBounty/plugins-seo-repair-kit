/**
 * Robots.txt & LLMs.txt Manager JavaScript
 * Bot Manager JavaScript
 * 
 * @since    2.1.1
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Tab Switching
        $('.srk-tab-button').on('click', function() {
            const tab = $(this).data('tab');
            
            // Update buttons
            $('.srk-tab-button').removeClass('active');
            $(this).addClass('active');
            
            // Update content
            $('.srk-tab-content').removeClass('active');
            $('#robots-tab, #llms-tab').removeClass('active');
            $('#' + tab + '-tab').addClass('active');
        });

        // Save Robots.txt
        $('#srk-save-robots').on('click', function() {
            const $btn = $(this);
            const content = $('#srk-robots-editor').val();
            
            $btn.addClass('loading').prop('disabled', true);
            
            $.ajax({
                url: srkRobotsLLMs.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'srk_save_robots_txt',
                    nonce: srkRobotsLLMs.nonce,
                    content: content
                },
                success: function(response) {
                    if (response.success) {
                        showMessage('success', response.data.message);
                    } else {
                        showMessage('error', response.data.message || srkRobotsLLMs.strings.error);
                    }
                },
                error: function() {
                    showMessage('error', srkRobotsLLMs.strings.error);
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });

        // Validate Robots.txt
        $('#srk-validate-robots').on('click', function() {
            const $btn = $(this);
            const content = $('#srk-robots-editor').val();
            const $status = $('#srk-robots-validation-status');
            
            $btn.addClass('loading').prop('disabled', true);
            $status.removeClass('show valid invalid warning');
            
            $.ajax({
                url: srkRobotsLLMs.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'srk_validate_robots_txt',
                    nonce: srkRobotsLLMs.nonce,
                    content: content
                },
                success: function(response) {
                    if (response.success) {
                        const result = response.data;
                        let html = '';
                        
                        if (result.valid) {
                            $status.addClass('show valid');
                            html = '<strong>' + srkRobotsLLMs.strings.valid + '</strong>';
                            if (result.warnings && result.warnings.length > 0) {
                                html += '<ul><li>' + result.warnings.join('</li><li>') + '</li></ul>';
                            }
                        } else {
                            $status.addClass('show invalid');
                            html = '<strong>' + srkRobotsLLMs.strings.invalid + '</strong>';
                            if (result.errors && result.errors.length > 0) {
                                html += '<ul><li>' + result.errors.join('</li><li>') + '</li></ul>';
                            }
                            if (result.warnings && result.warnings.length > 0) {
                                html += '<ul><li>' + result.warnings.join('</li><li>') + '</li></ul>';
                            }
                        }
                        
                        $status.html(html);
                    } else {
                        showMessage('error', response.data.message || srkRobotsLLMs.strings.error);
                    }
                },
                error: function() {
                    showMessage('error', srkRobotsLLMs.strings.error);
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });

        // Reset Robots.txt to Default
        $('#srk-reset-robots').on('click', function() {
            if (confirm('Are you sure you want to reset to default? This will overwrite your current content.')) {
                // WordPress recommended default robots.txt
                const defaultContent = 'User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /wp-includes/\n\nSitemap: ' + window.location.origin + '/sitemap.xml';
                $('#srk-robots-editor').val(defaultContent);
            }
        });

        // Delete Custom Robots.txt
        $('#srk-delete-robots').on('click', function() {
            if (!confirm('Are you sure you want to delete your custom robots.txt? This will remove all custom content and WordPress will use its default robots.txt behavior. This action cannot be undone.')) {
                return;
            }

            const $btn = $(this);
            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: srkRobotsLLMs.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'srk_delete_robots_txt',
                    nonce: srkRobotsLLMs.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Clear the editor and show default content
                        if (response.data.default_content) {
                            $('#srk-robots-editor').val(response.data.default_content);
                        } else {
                            $('#srk-robots-editor').val('');
                        }
                        showMessage('success', response.data.message);
                        // Clear validation status
                        $('#srk-robots-validation-status').removeClass('show valid invalid warning').html('');
                        // Hide display section since custom robots.txt was deleted
                        $('.srk-current-robots-section').hide();
                    } else {
                        showMessage('error', response.data.message || srkRobotsLLMs.strings.error);
                    }
                },
                error: function() {
                    showMessage('error', srkRobotsLLMs.strings.error);
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });

        // Apply Enhanced Robots.txt
        $('#srk-enhanced-robots').on('click', function() {
            if (!confirm('This will replace your current robots.txt with an enhanced version that includes additional security and SEO rules. Continue?')) {
                return;
            }

            const $btn = $(this);
            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: srkRobotsLLMs.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'srk_apply_enhanced_robots',
                    nonce: srkRobotsLLMs.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#srk-robots-editor').val(response.data.content);
                        showMessage('success', response.data.message);
                        // Clear validation status
                        $('#srk-robots-validation-status').removeClass('show valid invalid warning').html('');
                        // Update display section
                        if (response.data.default_content) {
                            updateRobotsDisplay(response.data.default_content, response.data.last_updated || '');
                        }
                    } else {
                        showMessage('error', response.data.message || srkRobotsLLMs.strings.error);
                    }
                },
                error: function() {
                    showMessage('error', srkRobotsLLMs.strings.error);
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });

        // Real-time Validation (debounced)
        let validationTimeout;
        $('#srk-robots-editor').on('input', function() {
            clearTimeout(validationTimeout);
            validationTimeout = setTimeout(function() {
                // Auto-validate after 2 seconds of no typing
                // $('#srk-validate-robots').trigger('click');
            }, 2000);
        });

        // Toggle Post Types (Select/Deselect All)
        $('#srk-toggle-post-types').on('click', function() {
            const $checkboxes = $('.srk-post-type-checkbox');
            const allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;
            
            if (allChecked) {
                // Deselect all
                $checkboxes.prop('checked', false);
                $(this).text('Select / Deselect All');
            } else {
                // Select all
                $checkboxes.prop('checked', true);
                $(this).text('Deselect All');
            }
        });

        // Toggle Taxonomies (Select/Deselect All)
        $('#srk-toggle-taxonomies').on('click', function() {
            const $checkboxes = $('.srk-taxonomy-checkbox');
            const allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;
            
            if (allChecked) {
                // Deselect all
                $checkboxes.prop('checked', false);
                $(this).text('Select / Deselect All');
            } else {
                // Select all
                $checkboxes.prop('checked', true);
                $(this).text('Deselect All');
            }
        });

        // Update toggle button text based on current state
        function updateToggleButtons() {
            const postTypesChecked = $('.srk-post-type-checkbox:checked').length;
            const postTypesTotal = $('.srk-post-type-checkbox').length;
            if (postTypesChecked === postTypesTotal && postTypesTotal > 0) {
                $('#srk-toggle-post-types').text('Deselect All');
            } else {
                $('#srk-toggle-post-types').text('Select / Deselect All');
            }

            const taxonomiesChecked = $('.srk-taxonomy-checkbox:checked').length;
            const taxonomiesTotal = $('.srk-taxonomy-checkbox').length;
            if (taxonomiesChecked === taxonomiesTotal && taxonomiesTotal > 0) {
                $('#srk-toggle-taxonomies').text('Deselect All');
            } else {
                $('#srk-toggle-taxonomies').text('Select / Deselect All');
            }

            const aiBotsChecked = $('.srk-ai-bot-checkbox:checked').length;
            const aiBotsTotal = $('.srk-ai-bot-checkbox').length;
            if (aiBotsChecked === aiBotsTotal && aiBotsTotal > 0) {
                $('#srk-toggle-ai-bots').text('Deselect All');
            } else {
                $('#srk-toggle-ai-bots').text('Select / Deselect All');
            }
        }

        // Toggle AI Bots Select/Deselect All
        $('#srk-toggle-ai-bots').on('click', function() {
            const $checkboxes = $('.srk-ai-bot-checkbox');
            const allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;
            
            $checkboxes.prop('checked', !allChecked);
            updateToggleButtons();
        });

        // Update on checkbox change
        $(document).on('change', '.srk-post-type-checkbox, .srk-taxonomy-checkbox, .srk-ai-bot-checkbox', function() {
            updateToggleButtons();
        });

        // Initialize toggle buttons
        updateToggleButtons();

        // Generate LLMs.txt
        $('#srk-generate-llms').on('click', function() {
            const $btn = $(this);
            
            // Get selected post types
            const postTypes = [];
            $('.srk-post-type-checkbox:checked').each(function() {
                postTypes.push($(this).val());
            });
            
            // Get selected taxonomies
            const taxonomies = [];
            $('.srk-taxonomy-checkbox:checked').each(function() {
                taxonomies.push($(this).val());
            });
            
            // Get posts limit
            const postsLimit = parseInt($('#srk-posts-limit').val()) || 50;
            
            // Get additional content
            const additionalContent = $('#srk-additional-content').val() || '';
            
            // Get selected AI bots
            const allowedBots = [];
            $('.srk-ai-bot-checkbox:checked').each(function() {
                allowedBots.push($(this).val());
            });
            
            if (postTypes.length === 0 && taxonomies.length === 0) {
                showMessage('error', 'Please select at least one post type or taxonomy to include.');
                return;
            }
            
            $btn.addClass('loading').prop('disabled', true);
            
            $.ajax({
                url: srkRobotsLLMs.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'srk_generate_llms_txt',
                    nonce: srkRobotsLLMs.nonce,
                    post_types: postTypes,
                    taxonomies: taxonomies,
                    posts_limit: postsLimit,
                    additional_content: additionalContent,
                    allowed_bots: allowedBots
                },
                success: function(response) {
                    if (response.success) {
                        $('#srk-llms-editor').val(response.data.content);
                        showMessage('success', response.data.message);
                        // Update display section if it exists
                        if (response.data.content) {
                            updateLLMsDisplay(response.data.content, response.data.last_updated || '');
                        }
                    } else {
                        showMessage('error', response.data.message || srkRobotsLLMs.strings.error);
                    }
                },
                error: function() {
                    showMessage('error', srkRobotsLLMs.strings.error);
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });

        // Save LLMs.txt Settings
        $('#srk-save-llms-settings').on('click', function() {
            const $btn = $(this);
            
            // Get selected post types
            const postTypes = [];
            $('.srk-post-type-checkbox:checked').each(function() {
                postTypes.push($(this).val());
            });
            
            // Get selected taxonomies
            const taxonomies = [];
            $('.srk-taxonomy-checkbox:checked').each(function() {
                taxonomies.push($(this).val());
            });
            
            // Get posts limit
            const postsLimit = parseInt($('#srk-posts-limit').val()) || 50;
            
            // Get additional content
            const additionalContent = $('#srk-additional-content').val() || '';
            
            // Get selected AI bots
            const allowedBots = [];
            $('.srk-ai-bot-checkbox:checked').each(function() {
                allowedBots.push($(this).val());
            });
            
            $btn.addClass('loading').prop('disabled', true);
            
            $.ajax({
                url: srkRobotsLLMs.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'srk_save_llms_settings',
                    nonce: srkRobotsLLMs.nonce,
                    post_types: postTypes,
                    taxonomies: taxonomies,
                    posts_limit: postsLimit,
                    additional_content: additionalContent,
                    allowed_bots: allowedBots
                },
                success: function(response) {
                    if (response.success) {
                        showMessage('success', response.data.message);
                    } else {
                        showMessage('error', response.data.message || srkRobotsLLMs.strings.error);
                    }
                },
                error: function() {
                    showMessage('error', srkRobotsLLMs.strings.error);
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });

        // Reset LLMs.txt Options
        $('#srk-reset-llms-options').on('click', function() {
            if (!confirm('Are you sure you want to reset all options to defaults? This will clear your current selections.')) {
                return;
            }

            const $btn = $(this);
            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: srkRobotsLLMs.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'srk_reset_llms_options',
                    nonce: srkRobotsLLMs.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Reset UI to defaults
                        $('.srk-post-type-checkbox').prop('checked', false);
                        $('.srk-post-type-checkbox[value="post"]').prop('checked', true);
                        $('.srk-post-type-checkbox[value="page"]').prop('checked', true);
                        $('.srk-taxonomy-checkbox').prop('checked', false);
                        $('#srk-posts-limit').val(50);
                        $('#srk-additional-content').val('');
                        updateToggleButtons();
                        showMessage('success', response.data.message);
                    } else {
                        showMessage('error', response.data.message || srkRobotsLLMs.strings.error);
                    }
                },
                error: function() {
                    showMessage('error', srkRobotsLLMs.strings.error);
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });

        // Preview LLMs.txt
        $('#srk-preview-llms').on('click', function() {
            const content = $('#srk-llms-editor').val();
            if (!content) {
                showMessage('error', 'Please generate or enter LLMs.txt content first.');
                return;
            }
            
            // Open preview in new window
            const previewWindow = window.open('', 'llms_preview', 'width=800,height=600');
            previewWindow.document.write('<pre style="padding:20px;font-family:monospace;white-space:pre-wrap;">' + escapeHtml(content) + '</pre>');
        });

        // Save LLMs.txt
        $('#srk-save-llms').on('click', function() {
            const $btn = $(this);
            const content = $('#srk-llms-editor').val();
            
            $btn.addClass('loading').prop('disabled', true);
            
            $.ajax({
                url: srkRobotsLLMs.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'srk_save_llms_txt',
                    nonce: srkRobotsLLMs.nonce,
                    content: content
                },
                success: function(response) {
                    if (response.success) {
                        showMessage('success', response.data.message);
                        // Update display section if it exists
                        if (response.data.last_updated) {
                            updateLLMsDisplay(content, response.data.last_updated);
                        }
                    } else {
                        showMessage('error', response.data.message || srkRobotsLLMs.strings.error);
                    }
                },
                error: function() {
                    showMessage('error', srkRobotsLLMs.strings.error);
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });

        // Delete LLMs.txt
        $('#srk-delete-llms').on('click', function() {
            if (!confirm('Are you sure you want to delete your LLMs.txt file? This will remove all content and the /llms.txt URL will return a 404. This action cannot be undone.')) {
                return;
            }

            const $btn = $(this);
            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: srkRobotsLLMs.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'srk_delete_llms_txt',
                    nonce: srkRobotsLLMs.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Clear the editor
                        $('#srk-llms-editor').val('');
                        showMessage('success', response.data.message);
                        // Hide display section since LLMs.txt was deleted
                        $('.srk-current-llms-section').hide();
                    } else {
                        showMessage('error', response.data.message || srkRobotsLLMs.strings.error);
                    }
                },
                error: function() {
                    showMessage('error', srkRobotsLLMs.strings.error);
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });


        // Update LLMs.txt display section
        function updateLLMsDisplay(content, lastUpdated) {
            // Check if display section exists, if not create it
            let $section = $('.srk-current-llms-section');
            
            if (!$section.length && content && content.trim() !== '') {
                // Create the section if it doesn't exist
                const sectionHtml = `
                    <div class="srk-current-llms-section">
                        <div class="srk-current-llms-message">
                            <div class="srk-notice srk-notice-info">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <p>You have a custom LLMs.txt configured. The content below is what AI models will see when they discover your site.</p>
                            </div>
                        </div>
                        <div class="srk-current-llms-content">
                            <div class="srk-llms-preview-header">
                                <h4>Current Configuration</h4>
                                <div class="srk-last-updated">
                                    <span class="dashicons dashicons-clock"></span>
                                    <span>Last updated: <strong>${escapeHtml(lastUpdated)}</strong></span>
                                </div>
                            </div>
                            <pre class="srk-llms-preview"><code>${escapeHtml(content)}</code></pre>
                        </div>
                    </div>
                `;
                $('.srk-llms-editor-section').before(sectionHtml);
                $section = $('.srk-current-llms-section');
            }
            
            // Update existing section
            if ($section.length) {
                const $display = $section.find('pre.srk-llms-preview code');
                if ($display.length && content && content.trim() !== '') {
                    $display.text(content);
                }
                
                const $lastUpdatedEl = $section.find('.srk-last-updated strong');
                if ($lastUpdatedEl.length && lastUpdated) {
                    $lastUpdatedEl.text(lastUpdated);
                    $lastUpdatedEl.closest('.srk-last-updated').show();
                }
                
                // Show/hide section based on content
                if (content && content.trim() !== '') {
                    $section.show();
                } else {
                    $section.hide();
                }
            }
        }

        // Update robots.txt display section
        function updateRobotsDisplay(content, lastUpdated) {
            // Check if display section exists, if not create it
            let $section = $('.srk-current-robots-section');
            
            if (!$section.length && content && content.trim() !== '') {
                // Create the section if it doesn't exist
                const sectionHtml = `
                    <div class="srk-current-robots-section">
                        <div class="srk-current-robots-message">
                            <div class="srk-notice srk-notice-info">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <p>You have a custom robots.txt configured. The content below is what search engines will see when they crawl your site.</p>
                            </div>
                        </div>
                        <div class="srk-current-robots-content">
                            <div class="srk-robots-preview-header">
                                <h4>Current Configuration</h4>
                                <div class="srk-last-updated">
                                    <span class="dashicons dashicons-clock"></span>
                                    <span>Last updated: <strong>${escapeHtml(lastUpdated)}</strong></span>
                                </div>
                            </div>
                            <pre class="srk-robots-preview"><code>${escapeHtml(content)}</code></pre>
                        </div>
                    </div>
                `;
                $('.srk-llms-url-banner').after(sectionHtml);
                $section = $('.srk-current-robots-section');
            }
            
            // Update existing section
            if ($section.length) {
                const $display = $section.find('pre.srk-robots-preview code');
                if ($display.length && content && content.trim() !== '') {
                    $display.text(content);
                }
                
                const $lastUpdatedEl = $section.find('.srk-last-updated strong');
                if ($lastUpdatedEl.length && lastUpdated) {
                    $lastUpdatedEl.text(lastUpdated);
                    $lastUpdatedEl.closest('.srk-last-updated').show();
                }
                
                // Show/hide section based on content
                if (content && content.trim() !== '') {
                    $section.show();
                } else {
                    $section.hide();
                }
            }
        }

        // Helper Functions
        function showMessage(type, message) {
            // Remove existing messages
            $('.srk-message').remove();
            
            // Create new message
            const $msg = $('<div class="srk-message ' + type + ' show">' + escapeHtml(message) + '</div>');
            
            // Insert at top of schema selection or tab content
            const $container = $('.srk-schema-selection').first();
            if ($container.length) {
                $container.prepend($msg);
            } else {
                // Fallback to tab content
                $('.srk-tab-content.active').prepend($msg);
            }
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                $msg.removeClass('show');
                setTimeout(function() {
                    $msg.remove();
                }, 300);
            }, 5000);
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    });

})(jQuery);

