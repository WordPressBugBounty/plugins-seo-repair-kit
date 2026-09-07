/**
 * Enhanced WordPress JavaScript for Advanced Redirection Management
 *
 * This script handles the advanced redirection interface with multiple features:
 * - Dynamic form handling
 * - AJAX operations
 * - Bulk actions
 * - Import/Export functionality
 * - Real-time testing
 * - Advanced condition management
 *
 * @since 2.1.0
 */

jQuery(document).ready(function ($) {
    'use strict';
  
    // Initialize the redirection interface
    initRedirectionInterface();
  
    /**
     * Initialize the redirection interface
     */
    function initRedirectionInterface() {
        setupEventHandlers();
        setupBulkActions();
        setupAdvancedOptions();
        setupImportExport();
        setupSortableTable();
        setupTabFromURL();
    }
    
    /**
     * Setup tab activation from URL parameter
     */
    function setupTabFromURL() {
        var urlParams = new URLSearchParams(window.location.search);
        var tab = urlParams.get('tab');
        if (tab) {
            // Map tab name to tab ID
            var tabMap = {
                'logs': 'srk-tab-logs',
                'import-export': 'srk-tab-import-export',
                'settings': 'srk-tab-settings'
            };
            
            var tabId = tabMap[tab];
            if (tabId) {
                // Hide all tabs
                $('.srk-tab-panel').hide();
                $('.srk-tab-btn').removeClass('active');
                
                // Show selected tab
                $('#' + tabId).show();
                $('.srk-tab-btn[data-tab="' + tabId + '"]').addClass('active');
            }
        }
    }
    
    /**
     * Reload page while preserving current tab
     */
    function reloadPreservingTab() {
        // Get currently active tab
        var activeTab = $('.srk-tab-btn.active').attr('data-tab');
        var currentUrl = new URL(window.location.href);
        
        // Map tab ID to tab name
        var tabIdMap = {
            'srk-tab-logs': 'logs',
            'srk-tab-import-export': 'import-export',
            'srk-tab-settings': 'settings'
        };
        
        // If there's an active tab (not the default redirections tab), preserve it
        if (activeTab && tabIdMap[activeTab]) {
            currentUrl.searchParams.set('tab', tabIdMap[activeTab]);
        } else {
            // Remove tab parameter if on default tab
            currentUrl.searchParams.delete('tab');
        }
        
        // Reload with preserved tab
        window.location.href = currentUrl.toString();
    }
  
    /**
     * Setup event handlers
     */
    function setupEventHandlers() {
        // Save redirection
        $("#srk_save_redirection").on("click", handleSaveRedirection);
        
        // Delete redirection
        $(document).on("click", ".srk-delete-redirection", handleDeleteRedirection);
        
        // Edit redirection
        $(document).on("click", ".srk-edit-redirection", handleEditRedirection);
        
        // Toggle advanced options
        $("#srk_toggle_advanced").on("click", toggleAdvancedOptions);
        
        // Add condition
        $(document).on("click", ".srk-add-condition", addCondition);
        
        // Remove condition
        $(document).on("click", ".srk-remove-condition", removeCondition);
        
        // Create redirect from 404
        $(document).on("click", ".srk-create-redirect", createRedirectFrom404);
        
        // Clear logs
        $("#srk_clear_logs").on("click", clearLogs);
        
        // Reset hits
        $(document).on("click", ".srk-reset-hits", resetHits);
        
        // Get hit statistics
        $(document).on("click", ".srk-refresh-stats", refreshHitStats);
        
        // Export redirections - use off() first to remove any existing handlers, then attach
        $("#srk_export_redirections").off("click").on("click", exportRedirections);
        
        // Import redirections
        $("#srk_import_redirections").on("click", importRedirections);
        
        // Apply bulk actions
        $("#srk_apply_bulk").on("click", applyBulkAction);
        
        // Select all checkboxes
        $(document).on("change", "#select_all_redirections", toggleSelectAll);
        $(document).on("change", "#select_all_404s", toggleSelectAll404s);
        
        // Handle redirect type change to show/hide target URL for 410
        $(document).on("change", "#redirect_type", handleRedirectTypeChange);
        
        // Initialize form state on page load
        handleRedirectTypeChange();
        
        // Handle per page select change
        $(document).on("change", "#srk_per_page_select", handlePerPageChange);
        
        // Handle logs per page select change
        $(document).on("change", "#srk_logs_per_page_select", handleLogsPerPageChange);
        
        // Handle manual migration button
        $(document).on("click", "#srk_manual_migrate", handleManualMigration);
    }
    
    /**
     * Handle per page select change
     */
    function handlePerPageChange() {
        var perPage = $(this).val();
        var currentUrl = new URL(window.location.href);
        
        // Update or add per_page parameter
        currentUrl.searchParams.set('srk_per_page', perPage);
        
        // Reset to page 1 when changing per page
        currentUrl.searchParams.set('srk_paged', '1');
        
        // Redirect to new URL
        window.location.href = currentUrl.toString();
    }
  
    /**
     * Handle logs per page select change
     */
    function handleLogsPerPageChange() {
        var perPage = $(this).val();
        var currentUrl = new URL(window.location.href);
        
        // Update or add logs_per_page parameter
        currentUrl.searchParams.set('srk_logs_per_page', perPage);
        
        // Reset to page 1 when changing per page
        currentUrl.searchParams.set('srk_logs_paged', '1');
        
        // Ensure we stay on the logs tab
        currentUrl.searchParams.set('tab', 'logs');
        
        // Redirect to new URL
        window.location.href = currentUrl.toString();
    }
  
    /**
     * Handle manual migration
     */
    function handleManualMigration() {
        if (!confirm('Are you sure you want to migrate redirection records? This will update your database structure.')) {
            return;
        }
        
        var $button = $('#srk_manual_migrate');
        var $spinner = $('#srk_migration_spinner');
        var $result = $('#srk_migration_result');
        var $statusText = $('#srk_migration_status_text');
        
        $button.prop('disabled', true);
        $spinner.css('display', 'inline-block');
        $result.hide().empty();
        $statusText.text('Migration in progress...');
        
        $.ajax({
            url: srk_ajax_obj.srkit_redirection_ajax,
            type: 'POST',
            data: {
                action: 'srk_migrate_redirections',
                srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce
            },
            success: function(response) {
                $spinner.hide();
                $button.prop('disabled', false);
                
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>').show();
                    $statusText.text(response.data.message);
                    
                    // Reload page after 2 seconds to show migrated records
                    setTimeout(function() {
                        reloadPreservingTab();
                    }, 2000);
                } else {
                    var errorMsg = response.data && response.data.message ? response.data.message : 'Migration failed';
                    $result.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>').show();
                    $statusText.text(errorMsg);
                }
            },
            error: function() {
                $spinner.hide();
                $button.prop('disabled', false);
                $result.html('<div class="notice notice-error"><p>Migration request failed. Please try again.</p></div>').show();
                $statusText.text('Migration failed - please try again');
            }
        });
    }
  
    /**
     * Handle save redirection
     */
    function handleSaveRedirection() {
        var formData = collectFormData();
        
        if (!validateFormData(formData)) {
      return;
    }
  
        showLoading(true);
        
    $.ajax({
      url: srk_ajax_obj.srkit_redirection_ajax,
      type: "POST",
      data: {
                action: "srk_save_redirection",
                ...formData,
                srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce
      },
      success: function (response) {
                showLoading(false);
                if (response.success) {
                    showMessage(response.data, 'success');
                    resetForm();
                    reloadPreservingTab();
                } else {
                    showMessage(response.data || srk_ajax_obj.srkit_redirection_messages.srkit_redirection_save_error, 'error');
                }
            },
            error: function () {
                showLoading(false);
                showMessage(srk_ajax_obj.srkit_redirection_messages.srkit_redirection_save_error, 'error');
            }
        });
    }
  
    /**
     * Collect form data
     */
    function collectFormData() {
        var conditions = [];
        $('.srk-condition-item').each(function() {
            var type = $(this).find('.srk-condition-type').val();
            var value = $(this).find('.srk-condition-value').val();
            var operator = $(this).find('.srk-condition-operator').val() || 'equals';
            
            if (type && value) {
                conditions.push({
                    type: type,
                    value: value,
                    operator: operator
                });
            }
        });
  
        // Get redirection_id - check multiple ways to ensure we get it
        var redirectionId = '';
        var redirectionIdElement = $('#redirection_id');
        if (redirectionIdElement.length) {
            redirectionId = redirectionIdElement.val() || '';
        }
        
        // Convert to integer for proper validation
        var redirectionIdInt = parseInt(redirectionId, 10);
        if (isNaN(redirectionIdInt)) {
            redirectionIdInt = 0;
        }
        
        // Ensure redirection_id is included if it exists (even if empty, we want to send it for updates)
        // Status defaults to 'active' - check if status checkbox exists, otherwise default to active
        var statusValue = 'active'; // Default to active for all redirections
        var statusCheckbox = $("#status");
        if (statusCheckbox.length) {
            // Status checkbox exists, use its value
            statusValue = statusCheckbox.is(':checked') ? 'active' : 'inactive';
        }
        // If checkbox doesn't exist, statusValue remains 'active' (default)
        
        // For new redirections (no redirection_id), always set status to 'active'
        if (redirectionIdInt <= 0) {
            statusValue = 'active';
        }
        
        var formData = {
            source_url: $("#source_url").val(),
            target_url: $("#target_url").val(),
            redirect_type: $("#redirect_type").val(),
            status: statusValue,
            is_regex: $("#is_regex").is(':checked') ? 1 : 0,
            group_id: $("#group_id").val() || '1',
            conditions: conditions
        };
        
        // Always include redirection_id if it's a valid number > 0 (for updates)
        // This ensures the PHP backend knows this is an update operation
        if (redirectionIdInt > 0) {
            formData.redirection_id = redirectionIdInt;
        }
  
        return formData;
    }
  
    /**
     * Validate form data
     */
    function validateFormData(data) {
        // Source URL is always required
        if (!data.source_url || data.source_url.trim() === '') {
            showMessage(srk_ajax_obj.srkit_redirection_messages.srk_fill_fields, 'error');
            return false;
        }
  
        // Target URL is required for all redirect types except 410 (Gone)
        var redirectType = parseInt(data.redirect_type) || 301;
        if (redirectType !== 410 && (!data.target_url || data.target_url.trim() === '')) {
            showMessage(srk_ajax_obj.srkit_redirection_messages.srk_fill_fields, 'error');
            return false;
        }
  
        // Validate regex if enabled
        if (data.is_regex) {
            try {
                new RegExp(data.source_url);
            } catch (e) {
                showMessage('Invalid regular expression pattern', 'error');
                return false;
            }
        }
  
        return true;
    }
  
    /**
     * Handle delete redirection
     */
    function handleDeleteRedirection() {
        var redirectionId = $(this).data('id');
        
    if (confirm(srk_ajax_obj.srkit_redirection_messages.srk_confirm_delete)) {
            showLoading(true);
            
            $.ajax({
                url: srk_ajax_obj.srkit_redirection_ajax,
                type: "POST",
                data: {
                    action: "srk_delete_redirection",
                    redirection_id: redirectionId,
                    srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce
                },
                success: function (response) {
                    showLoading(false);
                    if (response.success) {
                        showMessage(response.data, 'success');
                        reloadPreservingTab();
                    } else {
                        showMessage(response.data || srk_ajax_obj.srkit_redirection_messages.srk_delete_error, 'error');
                    }
                },
                error: function () {
                    showLoading(false);
                    showMessage(srk_ajax_obj.srkit_redirection_messages.srk_delete_error, 'error');
                }
            });
        }
    }
  
    /**
     * Handle edit redirection
     */
    function handleEditRedirection() {
        var redirectionId = $(this).data('id');
        var row = $(this).closest('tr');
        
        // Validate redirection ID - also try to get it from row data attribute as fallback
        if (!redirectionId || redirectionId === '' || redirectionId === '0') {
            redirectionId = row.data('redirection-id') || row.attr('data-redirection-id');
        }
        
        // Convert to integer to ensure it's a valid number
        redirectionId = parseInt(redirectionId, 10);
        if (isNaN(redirectionId) || redirectionId <= 0) {
            showMessage('Invalid redirection ID. Cannot edit this redirection.', 'error');
            return;
        }
        
        // Populate form with existing data using data attributes
        $("#source_url").val(row.data('source-url') || row.attr('data-source-url') || '');
        $("#target_url").val(row.data('target-url') || row.attr('data-target-url') || '');
        $("#redirect_type").val(row.data('redirect-type') || row.attr('data-redirect-type') || '301');
        
        // Set regex status
        var isRegex = row.data('is-regex') || row.attr('data-is-regex');
        $("#is_regex").prop('checked', isRegex == '1' || isRegex === 1);
        
        // Set status - check if status field exists, default to active
        var status = row.data('status') || row.attr('data-status') || 'active';
        var statusCheckbox = $("#status");
        if (statusCheckbox.length) {
            statusCheckbox.prop('checked', status === 'active');
        }
        
        // Update form state based on redirect type
        handleRedirectTypeChange();
        
        // Scroll to form - use the correct form container
        var formContainer = $('.srk-redirection-form-card');
        if (formContainer.length) {
            $('html, body').animate({
                scrollTop: formContainer.offset().top - 20
            }, 500);
        }
        
        // Add or update hidden field for update - ensure it's always set correctly
        // Remove any existing redirection_id field first to avoid duplicates
        $('#redirection_id').remove();
        
        // Append to form body
        var formBody = $('.srk-redirection-form-body');
        if (formBody.length) {
            $('<input>').attr({
                type: 'hidden',
                id: 'redirection_id',
                name: 'redirection_id',
                value: redirectionId
            }).appendTo(formBody);
        } else {
            // Fallback: try to find any form container
            var fallbackContainer = $('.srk-redirection-form-card, .srk-form-actions').first();
            if (fallbackContainer.length) {
                $('<input>').attr({
                    type: 'hidden',
                    id: 'redirection_id',
                    name: 'redirection_id',
                    value: redirectionId
                }).appendTo(fallbackContainer);
            } else {
                // Last resort: append to the form card itself
                if (formContainer.length) {
                    $('<input>').attr({
                        type: 'hidden',
                        id: 'redirection_id',
                        name: 'redirection_id',
                        value: redirectionId
                    }).appendTo(formContainer);
                }
            }
        }
        
        // Change button text
        $("#srk_save_redirection").text('Update Redirection');
    }
  
  
    /**
     * Toggle advanced options
     */
    function toggleAdvancedOptions() {
        $('.srk-advanced-conditions').slideToggle();
        var text = $('.srk-advanced-conditions').is(':visible') ? 'Hide Advanced Options' : 'Advanced Options';
        $("#srk_toggle_advanced").text(text);
    }
  
    /**
     * Add condition
     */
    function addCondition() {
        var type = $('.srk-condition-type').val();
        var value = $('.srk-condition-value').val();
        
        if (!type || !value) {
            showMessage('Please select condition type and enter value', 'error');
            return;
        }
  
        var conditionHtml = '<div class="srk-condition-item">' +
            '<select class="srk-condition-type" disabled>' +
            '<option value="' + type + '">' + srk_ajax_obj.match_types[type] + '</option>' +
            '</select>' +
            '<input type="text" class="srk-condition-value" value="' + value + '" disabled />' +
            '<select class="srk-condition-operator" disabled>' +
            '<option value="equals">Equals</option>' +
            '<option value="not_equals">Not Equals</option>' +
            '<option value="contains">Contains</option>' +
            '<option value="not_contains">Not Contains</option>' +
            '<option value="starts_with">Starts With</option>' +
            '<option value="ends_with">Ends With</option>' +
            '<option value="regex">Regex</option>' +
            '</select>' +
            '<button type="button" class="srk-btn srk-btn-small srk-remove-condition">Remove</button>' +
            '</div>';
  
        $('.srk-conditions-list').append(conditionHtml);
        
        // Clear form
        $('.srk-condition-type').val('');
        $('.srk-condition-value').val('');
    }
  
    /**
     * Remove condition
     */
    function removeCondition() {
        $(this).closest('.srk-condition-item').remove();
    }
  
    /**
     * Create redirect from 404
     */
    function createRedirectFrom404() {
        var url = $(this).data('url');
        $("#source_url").val(url);
        
        // Scroll to form
        $('html, body').animate({
            scrollTop: $('.srk-quick-add-form').offset().top
        }, 500);
    }
  
    /**
     * Clear logs
     */
    function clearLogs() {
        if (confirm('Are you sure you want to clear all logs? This action cannot be undone.')) {
            showLoading(true);
            
            $.ajax({
                url: srk_ajax_obj.srkit_redirection_ajax,
                type: "POST",
                data: {
                    action: "srk_clear_logs",
                    srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce
                },
                success: function (response) {
                    showLoading(false);
                    if (response.success) {
                        showMessage(response.data, 'success');
                        reloadPreservingTab();
                    } else {
                        showMessage('Failed to clear logs', 'error');
                    }
                },
                error: function () {
                    showLoading(false);
                    showMessage('Failed to clear logs', 'error');
                }
            });
        }
    }
  
  
    /**
     * Reset hits
     */
    function resetHits() {
        var redirectionId = $(this).data('id');
        var message = redirectionId ? 
            'Are you sure you want to reset hits for this redirection?' : 
            'Are you sure you want to reset all hit counts? This action cannot be undone.';
            
        if (confirm(message)) {
            showLoading(true);
            
      var data = {
                action: "srk_reset_hits",
                srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce
            };
            
            if (redirectionId) {
                data.redirection_id = redirectionId;
            }
            
            $.ajax({
                url: srk_ajax_obj.srkit_redirection_ajax,
                type: "POST",
                data: data,
                success: function (response) {
                    showLoading(false);
                    if (response.success) {
                        showMessage(response.data, 'success');
                        reloadPreservingTab();
                    } else {
                        showMessage(response.data || 'Failed to reset hits', 'error');
                    }
                },
                error: function () {
                    showLoading(false);
                    showMessage('Failed to reset hits', 'error');
                }
            });
        }
    }
  
    /**
     * Refresh hit statistics
     */
    function refreshHitStats() {
        showLoading(true);
        
        $.ajax({
            url: srk_ajax_obj.srkit_redirection_ajax,
            type: "POST",
            data: {
                action: "srk_get_hit_stats",
                srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce
            },
            success: function (response) {
                showLoading(false);
                if (response.success) {
                    updateHitStatsDisplay(response.data);
                    showMessage('Statistics updated', 'success');
                } else {
                    showMessage('Failed to get statistics', 'error');
                }
            },
            error: function () {
                showLoading(false);
                showMessage('Failed to get statistics', 'error');
            }
        });
    }
  
    /**
     * Update hit statistics display
     */
    function updateHitStatsDisplay(stats) {
        // Update total hits
        $('.srk-stat-card:first .srk-stat-number').text(numberFormat(stats.total_hits));
        
        // Update total redirections
        $('.srk-stat-card:nth-child(2) .srk-stat-number').text(numberFormat(stats.total_redirections));
        
        // Update active redirections
        $('.srk-stat-card:nth-child(3) .srk-stat-number').text(numberFormat(stats.active_redirections));
        
        // Update most hit redirect
        if (stats.most_hit) {
            $('.srk-stat-card:nth-child(4) .srk-stat-number').html(
                numberFormat(stats.most_hit.hits) + 
                '<small>' + stats.most_hit.source_url + '</small>'
            );
        } else {
            $('.srk-stat-card:nth-child(4) .srk-stat-number').text('0');
        }
        
        // Update recent hits
        if (stats.recent_hits && stats.recent_hits.length > 0) {
            var recentHtml = '';
            stats.recent_hits.forEach(function(hit) {
                recentHtml += '<div class="srk-recent-hit-item">' +
                    '<span class="srk-hit-url">' + hit.source_url + '</span>' +
                    '<span class="srk-hit-count">' + numberFormat(hit.hits) + ' hits</span>' +
                    '<span class="srk-hit-date">' + formatDate(hit.last_hit) + '</span>' +
                    '</div>';
            });
            $('.srk-recent-hits-list').html(recentHtml);
        }
    }
  
    /**
     * Format number with commas
     */
    function numberFormat(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
  
    /**
     * Format date
     */
    function formatDate(dateString) {
        var date = new Date(dateString);
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                     'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
    }
  
    /**
     * Export redirections
     */
    function exportRedirections(e) {
        // Prevent double-clicking and multiple executions
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        var $button = $("#srk_export_redirections");
        
        // Check if export is already in progress
        if ($button.data('exporting') === true) {
            return false;
        }
        
        // Mark as exporting
        $button.data('exporting', true).prop('disabled', true);
        
        var format = $("#export_format").val() || $('input[name="export_format"]:checked').val() || 'csv';
        
        if (!format) {
            showMessage('Please select an export format', 'error');
            $button.data('exporting', false).prop('disabled', false);
            return false;
        }
        
        showLoading(true);
        
        $.ajax({
            url: srk_ajax_obj.srkit_redirection_ajax,
            type: "POST",
            data: {
                action: "srk_export_redirections",
                format: format,
                srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce
            },
            success: function (response) {
                showLoading(false);
                $button.data('exporting', false).prop('disabled', false);
                
                if (response.success && response.data) {
                    showMessage(srk_ajax_obj.srkit_redirection_messages.srk_export_success, 'success');
                    
                    // Download file directly from response data (works better on live servers)
                    if (response.data.file_content && response.data.filename) {
                        try {
                            // Decode base64 content
                            var fileContent = atob(response.data.file_content);
                            
                            // Create blob based on format
                            var blob;
                            var mimeType;
                            
                            if (response.data.format === 'json') {
                                mimeType = 'application/json;charset=utf-8';
                                blob = new Blob([fileContent], { type: mimeType });
                            } else {
                                mimeType = 'text/csv;charset=utf-8';
                                // Add BOM for Excel compatibility
                                var BOM = '\uFEFF';
                                blob = new Blob([BOM + fileContent], { type: mimeType });
                            }
                            
                            // Create download link
                            var url = window.URL.createObjectURL(blob);
                            var link = document.createElement('a');
                            link.href = url;
                            link.download = response.data.filename;
                            link.style.display = 'none';
                            
                            // Trigger download
                            document.body.appendChild(link);
                            link.click();
                            
                            // Cleanup
                            setTimeout(function() {
                                document.body.removeChild(link);
                                window.URL.revokeObjectURL(url);
                            }, 100);
                        } catch (e) {
                            showMessage('Export succeeded but download failed. Please try again.', 'error');
                        }
                    } else if (response.data && response.data.download_url) {
                        // Fallback to old method if file_content is not available
                        window.location.href = response.data.download_url;
                    }
                } else {
                    var errorMsg = response.data && typeof response.data === 'string' ? response.data : 'Export failed';
                    showMessage(errorMsg, 'error');
                }
            },
            error: function (xhr, status, error) {
                showLoading(false);
                $button.data('exporting', false).prop('disabled', false);
                var errorMsg = 'Export failed';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMsg = xhr.responseJSON.data;
                }
                showMessage(errorMsg, 'error');
            }
        });
        
        return false;
    }
  
    /**
     * Import redirections
     */
    function importRedirections() {
        var fileInput = document.getElementById('import_file');
        var files = fileInput.files;
        
        if (!files || files.length === 0) {
            showMessage('Please select at least one file to import', 'error');
            return;
        }
  
        var formData = new FormData();
        formData.append('action', 'srk_import_redirections');
        
        // Append all files
        for (var i = 0; i < files.length; i++) {
            formData.append('import_file[]', files[i]);
        }
        
        formData.append('import_overwrite', $("#import_overwrite").is(':checked') ? 1 : 0);
        formData.append('srkit_redirection_nonce', srk_ajax_obj.srk_save_url_nonce);
  
        showLoading(true);
        
        $.ajax({
            url: srk_ajax_obj.srkit_redirection_ajax,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                showLoading(false);
                if (response.success) {
                    var message = response.data.message || 'Import completed successfully!';
                    var stats = response.data.stats || {};
                    
                    // Use the message from server (already formatted properly)
                    // Don't rebuild it - the server message is already correct
                    
                    // Show actual errors if any - with detailed error display
                    var actualErrors = response.data.error_details || [];
                    if (actualErrors && actualErrors.length > 0) {
                        var errorCount = actualErrors.length;
                        
                        // Show error category breakdown if available
                        if (response.data.error_categories) {
                            var categories = response.data.error_categories;
                            var categorySummary = [];
                            if (categories.missing_source > 0) {
                                categorySummary.push(categories.missing_source + ' missing source URLs');
                            }
                            if (categories.missing_target > 0) {
                                categorySummary.push(categories.missing_target + ' missing target URLs');
                            }
                            if (categories.column_mismatch > 0) {
                                categorySummary.push(categories.column_mismatch + ' column name mismatches');
                            }
                            if (categories.invalid_regex > 0) {
                                categorySummary.push(categories.invalid_regex + ' invalid regex patterns');
                            }
                            if (categories.database_errors > 0) {
                                categorySummary.push(categories.database_errors + ' database errors');
                            }
                            if (categories.other > 0) {
                                categorySummary.push(categories.other + ' other errors');
                            }
                            
                            if (categorySummary.length > 0) {
                                message += '\n\nError Breakdown:\n' + categorySummary.join('\n');
                            }
                        }
                        
                        // Show first 20 errors in alert for debugging
                        if (errorCount > 0) {
                            var errorDetails = actualErrors;
                            var errorPreview = errorDetails.slice(0, 20).join('\n');
                            if (errorCount > 20) {
                                errorPreview += '\n... and ' + (errorCount - 20) + ' more errors';
                            }
                            
                            // Show errors in alert if there are many
                            if (errorCount > 0) {
                                var errorSummary = 'Import completed with ' + errorCount + ' actual error' + (errorCount > 1 ? 's' : '');
                                
                                // Add duplicate info if available
                                if (response.data.skipped_duplicates && response.data.skipped_duplicates > 0) {
                                    errorSummary += ' and ' + response.data.skipped_duplicates + ' duplicate' + (response.data.skipped_duplicates > 1 ? 's' : '') + ' automatically skipped';
                                }
                                errorSummary += '.\n\n';
                                
                                // Add category breakdown to alert
                                if (response.data.error_categories) {
                                    var categories = response.data.error_categories;
                                    errorSummary += 'Error Breakdown:\n';
                                    if (categories.missing_source > 0) {
                                        errorSummary += '- ' + categories.missing_source + ' missing source URLs\n';
                                    }
                                    if (categories.missing_target > 0) {
                                        errorSummary += '- ' + categories.missing_target + ' missing target URLs\n';
                                    }
                                    if (categories.column_mismatch > 0) {
                                        errorSummary += '- ' + categories.column_mismatch + ' column name mismatches\n';
                                    }
                                    if (categories.invalid_regex > 0) {
                                        errorSummary += '- ' + categories.invalid_regex + ' invalid regex patterns\n';
                                    }
                                    if (categories.database_errors > 0) {
                                        errorSummary += '- ' + categories.database_errors + ' database errors\n';
                                    }
                                    if (categories.other > 0) {
                                        errorSummary += '- ' + categories.other + ' other errors\n';
                                    }
                                    errorSummary += '\n';
                                }
                                
                                // Add duplicate info separately
                                if (response.data.skipped_duplicates && response.data.skipped_duplicates > 0) {
                                    errorSummary += 'Duplicates:\n';
                                    errorSummary += '- ' + response.data.skipped_duplicates + ' duplicate' + (response.data.skipped_duplicates > 1 ? 's' : '') + ' automatically skipped (only first occurrence kept)\n';
                                    if (!$("#import_overwrite").is(':checked')) {
                                        errorSummary += '  Note: Enable "Update Existing Redirections" to update existing database records\n';
                                    }
                                    errorSummary += '\n';
                                }
                                
                                errorSummary += 'First 20 errors:\n' + errorPreview;
                                errorSummary += '\n\nFull error list is available in browser console (F12).';
                                
                                // Create detailed error message
                                message += '\n\n=== ERROR DETAILS ===\n';
                                message += errorPreview;
                                
                                // Show alert with errors
                                alert(errorSummary);
                            }
                        }
                    } else if (response.data.skipped_duplicates && response.data.skipped_duplicates > 0) {
                        // If only duplicates were skipped (no actual errors), show info message
                        if (stats.imported > 0 || stats.updated > 0) {
                            message += '\n\nNote: ' + response.data.skipped_duplicates + ' duplicate' + (response.data.skipped_duplicates > 1 ? 's were' : ' was') + ' automatically skipped. Only unique redirects were imported.';
                        }
                    }
                    
                    // Determine message type based on results
                    var hasActualErrors = actualErrors && actualErrors.length > 0;
                    var hasImports = stats.imported > 0 || stats.updated > 0;
                    var hasDuplicates = response.data.skipped_duplicates && response.data.skipped_duplicates > 0;
                    
                    if (hasImports && !hasActualErrors) {
                        // Success - something was imported and no real errors
                        showMessage(message, 'success');
                        setTimeout(function() { reloadPreservingTab(); }, 3000);
                    } else if (hasActualErrors) {
                        // Has errors - show as error or warning depending on whether anything was imported
                        showMessage(message, hasImports ? 'warning' : 'error');
                        setTimeout(function() { reloadPreservingTab(); }, 3000);
                    } else if (hasDuplicates && !hasImports) {
                        // Only duplicates, nothing imported - might mean all were duplicates
                        showMessage(message, 'info');
                        setTimeout(function() { reloadPreservingTab(); }, 2000);
                    } else {
                        // Default success
                        showMessage(message, 'success');
                        setTimeout(function() { reloadPreservingTab(); }, 2000);
                    }
                } else {
                    var errorMsg = 'Import failed';
                    if (response.data) {
                        if (typeof response.data === 'string') {
                            errorMsg = response.data;
                        } else if (typeof response.data === 'object' && response.data.message) {
                            errorMsg = response.data.message;
                        } else if (typeof response.data === 'object') {
                            errorMsg = JSON.stringify(response.data);
                        }
                    }
                    showMessage(errorMsg, 'error');
                }
            },
            error: function () {
                showLoading(false);
                showMessage('Import failed', 'error');
            }
        });
    }
  
    /**
     * Setup bulk actions
     */
    function setupBulkActions() {
        // Bulk action handling is already set up in event handlers
    }
  
    /**
     * Apply bulk action
     */
    function applyBulkAction() {
        var action = $("#bulk_action").val();
        var checkedItems = $('.srk-redirection-checkbox:checked');
        
        if (!action) {
            showMessage('Please select a bulk action', 'error');
            return;
        }
        
        if (checkedItems.length === 0) {
            showMessage('Please select at least one redirection', 'error');
            return;
        }
  
        var redirectionIds = [];
        checkedItems.each(function() {
            redirectionIds.push($(this).val());
        });
  
        if (confirm('Are you sure you want to perform this bulk action?')) {
            showLoading(true);
            
            $.ajax({
                url: srk_ajax_obj.srkit_redirection_ajax,
                type: "POST",
                data: {
                    action: "srk_bulk_action",
                    bulk_action: action,
                    redirection_ids: redirectionIds,
                    srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce
                },
                success: function (response) {
                    showLoading(false);
                    if (response.success) {
                        showMessage(response.data, 'success');
                        reloadPreservingTab();
                    } else {
                        showMessage(response.data || 'Bulk action failed', 'error');
                    }
                },
                error: function () {
                    showLoading(false);
                    showMessage('Bulk action failed', 'error');
                }
            });
        }
    }
  
    /**
     * Toggle select all redirections
     */
    function toggleSelectAll() {
        var isChecked = $(this).is(':checked');
        $('.srk-redirection-checkbox').prop('checked', isChecked);
    }
  
    /**
     * Toggle select all 404s
     */
    function toggleSelectAll404s() {
        var isChecked = $(this).is(':checked');
        $('.srk-404-checkbox').prop('checked', isChecked);
    }
  
    /**
     * Setup advanced options
     */
    function setupAdvancedOptions() {
        // Advanced options are handled in toggleAdvancedOptions
    }
  
    /**
     * Setup import/export
     */
    function setupImportExport() {
        // Import/export is handled in respective functions
    }
  
    /**
     * Setup sortable table
     */
    function setupSortableTable() {
        $('.srk-redirections-table tbody').sortable({
            handle: 'td',
            update: function(event, ui) {
                // Handle position updates if needed
            }
        });
    }
  
    /**
     * Show loading state
     */
    function showLoading(show) {
        if (show) {
            $('body').append('<div id="srk-loading-overlay"><div class="srk-loading-spinner"></div></div>');
        } else {
            $('#srk-loading-overlay').remove();
        }
    }
  
    /**
     * Show message
     */
    function showMessage(message, type) {
        var messageClass = type === 'success' ? 'notice-success' : 'notice-error';
        var messageHtml = '<div class="notice ' + messageClass + ' is-dismissible"><p>' + message + '</p></div>';
        
        $('.seo-repair-kit-redirection').prepend(messageHtml);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $('.notice').fadeOut();
        }, 5000);
    }
  
    /**
     * Handle redirect type change
     */
    function handleRedirectTypeChange() {
        var redirectType = parseInt($("#redirect_type").val()) || 301;
        var targetUrlGroup = $("#target_url").closest('.srk-form-group');
        var targetUrlLabel = targetUrlGroup.find('label');
        var targetUrlInput = $("#target_url");
        var targetUrlHelp = targetUrlGroup.find('.srk-help-text');
        
        if (redirectType === 410) {
            // 410 Gone - target URL is optional
            targetUrlInput.prop('required', false);
            targetUrlInput.attr('placeholder', 'Optional - not used for 410 Gone');
            if (targetUrlHelp.length) {
                targetUrlHelp.text('Optional: 410 Gone does not redirect, just returns 410 status');
            } else {
                targetUrlGroup.append('<small class="srk-help-text">Optional: 410 Gone does not redirect, just returns 410 status</small>');
            }
            targetUrlGroup.addClass('srk-optional-field');
        } else if (redirectType === 304) {
            // 304 Not Modified - target URL is optional
            targetUrlInput.prop('required', false);
            targetUrlInput.attr('placeholder', 'Optional - not used for 304 Not Modified');
            if (targetUrlHelp.length) {
                targetUrlHelp.text('Optional: 304 Not Modified does not redirect, just returns 304 status');
            } else {
                targetUrlGroup.append('<small class="srk-help-text">Optional: 304 Not Modified does not redirect, just returns 304 status</small>');
            }
            targetUrlGroup.addClass('srk-optional-field');
        } else {
            // All other redirect types require target URL
            targetUrlInput.prop('required', true);
            targetUrlInput.attr('placeholder', '/new-page/');
            if (targetUrlHelp.length) {
                targetUrlHelp.text('Enter the URL to redirect to');
            } else {
                targetUrlGroup.append('<small class="srk-help-text">Enter the URL to redirect to</small>');
            }
            targetUrlGroup.removeClass('srk-optional-field');
        }
    }
  
    /**
     * Reset form
     */
    function resetForm() {
        $('#source_url, #target_url').val('');
        $('#redirect_type').val('301');
        $('#status').prop('checked', true);
        $('#is_regex').prop('checked', false);
        $('#group_id').val('1');
        $('.srk-conditions-list').empty();
        $('#redirection_id').remove();
        $("#srk_save_redirection").text('Add Redirection');
        handleRedirectTypeChange(); // Reset form state
    }
  
    // Legacy support for old redirection methods
    $("#srk_save_new_url").on("click", function () {
        // Convert old format to new format
        $("#source_url").val($("#old_url").val());
        $("#target_url").val($("#new_url").val());
        handleSaveRedirection();
    });
  
    $(".srk-delete-record").on("click", function () {
        var recordId = $(this).data("record-id");
        
        if (confirm(srk_ajax_obj.srkit_redirection_messages.srk_confirm_delete)) {
            showLoading(true);
            
            $.ajax({
                url: srk_ajax_obj.srkit_redirection_ajax,
                type: "POST",
                data: {
                    action: "srk_delete_redirection_record",
                    record_id: recordId,
                    srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce
                },
                success: function (response) {
                    showLoading(false);
                    if (response === "success") {
                        showMessage('Record deleted successfully', 'success');
                        reloadPreservingTab();
                    } else {
                        showMessage(srk_ajax_obj.srkit_redirection_messages.srk_delete_error, 'error');
                    }
                },
                error: function () {
                    showLoading(false);
                    showMessage(srk_ajax_obj.srkit_redirection_messages.srk_delete_error, 'error');
        }
      });
    }
  
  });
});