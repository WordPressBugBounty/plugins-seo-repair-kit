/**
 * SEO Repair Kit - 404 Error Monitor JavaScript
 *
 * @since 2.1.0
 */
(function($) {
    'use strict';

    // Initialize 404 Manager functionality
    function init404Manager() {
        // Get srk404Ajax from window object (localized by WordPress)
        const srk404Ajax = typeof window.srk404Ajax !== 'undefined' ? window.srk404Ajax : {};
        
        // Get AJAX URL - use localized or fallback to WordPress default
        const ajaxUrl = srk404Ajax.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
        const nonce = srk404Ajax.nonce || '';
        
        // Validate required variables
        if (!ajaxUrl) {
            console.error('SEO Repair Kit 404 Manager: AJAX URL not found');
            return false;
        }
        
        if (!nonce) {
            console.error('SEO Repair Kit 404 Manager: Security nonce not found');
            return false;
        }

        // Reset filters on page reload (not on form submission)
        // Check if we're on the 404 monitor tab
        const urlParams = new URLSearchParams(window.location.search);
        const currentTab = urlParams.get('tab') || (window.location.hash === '#404-monitor' ? '404-monitor' : '');
        
        if (currentTab === '404-monitor' || window.location.hash === '#404-monitor') {
            // Check if filter parameters exist
            const hasFilterParams = urlParams.has('srk_filter_url') || 
                                   urlParams.has('srk_filter_ip') || 
                                   urlParams.has('srk_orderby') || 
                                   urlParams.has('srk_order') ||
                                   urlParams.has('srk_404_paged') ||
                                   urlParams.has('srk_404_per_page');
            
            // Check if form was submitted (has srk_filter_submitted parameter)
            const formSubmitted = urlParams.has('srk_filter_submitted');
            
            // Detect page reload using Navigation Timing API
            let isReload = false;
            if (window.performance && window.performance.navigation) {
                // Legacy API
                isReload = window.performance.navigation.type === window.performance.navigation.TYPE_RELOAD;
            } else if (window.performance && window.performance.getEntriesByType) {
                // Navigation Timing API 2
                const navEntries = window.performance.getEntriesByType('navigation');
                if (navEntries.length > 0 && navEntries[0].type === 'reload') {
                    isReload = true;
                }
            }
            
            // Reset filters if:
            // 1. Page was reloaded (F5, refresh button, etc.) - reset regardless of form submission flag
            // 2. OR filters exist but form was NOT submitted (direct URL access with filters)
            // Note: On reload, even if srk_filter_submitted exists from previous submission, we reset
            if (hasFilterParams && (isReload || !formSubmitted)) {
                // Build clean URL without filter parameters
                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('srk_filter_url');
                cleanUrl.searchParams.delete('srk_filter_ip');
                cleanUrl.searchParams.delete('srk_orderby');
                cleanUrl.searchParams.delete('srk_order');
                cleanUrl.searchParams.delete('srk_404_paged');
                cleanUrl.searchParams.delete('srk_404_per_page');
                cleanUrl.searchParams.delete('srk_filter_submitted');
                
                // Preserve page and tab parameters
                if (!cleanUrl.searchParams.has('page')) {
                    cleanUrl.searchParams.set('page', 'seo-repair-kit-link-scanner');
                }
                if (!cleanUrl.searchParams.has('tab')) {
                    cleanUrl.searchParams.set('tab', '404-monitor');
                }
                
                // Redirect to clean URL
                window.location.replace(cleanUrl.toString());
                return false; // Stop execution to prevent other code from running
            }
        }
        
        return { ajaxUrl, nonce, srk404Ajax };
    }

    $(document).ready(function() {
        // Initialize and get configuration
        const config = init404Manager();
        if (!config) {
            return; // Initialization failed or redirect occurred
        }
        
        const { ajaxUrl, nonce, srk404Ajax } = config;

        // Select all checkbox (event delegation)
        $(document).on('change', '#srk-select-all-404', function() {
            $('.srk-404-checkbox').prop('checked', $(this).prop('checked'));
        });

        // Individual checkbox change (event delegation)
        $(document).on('change', '.srk-404-checkbox', function() {
            const total = $('.srk-404-checkbox').length;
            const checked = $('.srk-404-checkbox:checked').length;
            $('#srk-select-all-404').prop('checked', total === checked);
        });

        // Delete single 404 (event delegation)
        $(document).on('click', '.srk-btn-delete', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const logId = button.data('log-id');
            
            if (!logId) {
                return;
            }
            
            if (!confirm(srk404Ajax.messages?.confirm_delete || 'Are you sure you want to delete this 404 log?')) {
                return;
            }

            button.prop('disabled', true).addClass('srk-loading');

            // Check nonce
            if (!nonce) {
                showNotice('Error: Security token missing. Please refresh the page.', 'error');
                button.prop('disabled', false).removeClass('srk-loading');
                return;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'srk_delete_404',
                    nonce: nonce,
                    'log_id': logId
                },
                success: function(response) {
                    if (response && response.success) {
                        button.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                            checkEmptyTable();
                        });
                        showNotice(response.data?.message || '404 log deleted successfully.', 'success');
                    } else {
                        const errorMsg = (response && response.data && response.data.message) 
                            ? response.data.message 
                            : 'Error deleting 404 log.';
                        showNotice(errorMsg, 'error');
                        button.prop('disabled', false).removeClass('srk-loading');
                    }
                },
                error: function(xhr, status, error) {
                    let errorMessage = 'Network error. Please try again.';
                    if (xhr.responseText) {
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMessage = errorResponse.data.message;
                            }
                        } catch (e) {
                            // Not JSON, use default
                            if (xhr.status === 0) {
                                errorMessage = 'Connection error. Please check your internet connection.';
                            } else if (xhr.status === 403) {
                                errorMessage = 'Permission denied. Please refresh the page.';
                            } else if (xhr.status === 500) {
                                errorMessage = 'Server error. Please check the error logs.';
                            }
                        }
                    }
                    
                    showNotice(errorMessage, 'error');
                    button.prop('disabled', false).removeClass('srk-loading');
                }
            });
        });

        // Bulk actions (event delegation)
        $(document).on('click', '#srk_apply_bulk_404', function(e) {
            e.preventDefault();

            const action = $('#srk_bulk_action_404').val();
            if (!action) {
                alert('Please select an action.');
                return;
            }

            const checkedIds = $('.srk-404-checkbox:checked').map(function() {
                return $(this).val();
            }).get();

            if (checkedIds.length === 0) {
                alert('Please select at least one 404 log.');
                return;
            }

            const confirmMessage = action === 'delete' 
                ? (srk404Ajax.messages?.confirm_bulk_delete || 'Are you sure you want to delete selected 404 logs?')
                : 'Are you sure you want to perform this action?';

            if (!confirm(confirmMessage)) {
                return;
            }

            const button = $(this);
            button.prop('disabled', true).addClass('srk-loading');

            // Check nonce
            if (!nonce) {
                showNotice('Error: Security token missing. Please refresh the page.', 'error');
                button.prop('disabled', false).removeClass('srk-loading');
                return;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'srk_bulk_action_404',
                    nonce: nonce,
                    bulk_action: action,
                    'log_ids': checkedIds
                },
                success: function(response) {
                    if (response && response.success) {
                        checkedIds.forEach(function(id) {
                            $('tr[data-log-id="' + id + '"]').fadeOut(300, function() {
                                $(this).remove();
                                checkEmptyTable();
                            });
                        });
                        showNotice(response.data?.message || 'Action completed successfully.', 'success');
                        
                        // Reload page after a short delay to refresh stats
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showNotice(response.data?.message || 'Error performing action.', 'error');
                        button.prop('disabled', false).removeClass('srk-loading');
                    }
                },
                error: function(xhr, status, error) {
                    let errorMessage = 'Network error. Please try again.';
                    if (xhr.responseText) {
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMessage = errorResponse.data.message;
                            }
                        } catch (e) {
                            // Not JSON, use default
                            if (xhr.status === 0) {
                                errorMessage = 'Connection error. Please check your internet connection.';
                            } else if (xhr.status === 403) {
                                errorMessage = 'Permission denied. Please refresh the page.';
                            } else if (xhr.status === 500) {
                                errorMessage = 'Server error. Please check the error logs.';
                            }
                        }
                    }
                    showNotice(errorMessage, 'error');
                    button.prop('disabled', false).removeClass('srk-loading');
                }
            });
        });

        // Clear all logs (event delegation)
        $(document).on('click', '#srk_clear_all_404', function(e) {
            e.preventDefault();

            if (!confirm(srk404Ajax.messages?.confirm_clear || 'Are you sure you want to clear all 404 logs? This cannot be undone.')) {
                return;
            }

            const button = $(this);
            button.prop('disabled', true).addClass('srk-loading');

            // Check nonce
            if (!nonce) {
                showNotice('Error: Security token missing. Please refresh the page.', 'error');
                button.prop('disabled', false).removeClass('srk-loading');
                return;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'srk_clear_404_logs',
                    nonce: nonce,
                    days: 0 // 0 = delete all
                },
                success: function(response) {
                    if (response && response.success) {
                        showNotice(response.data?.message || 'All 404 logs cleared successfully.', 'success');
                        
                        // Reload page after a short delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showNotice(response.data?.message || 'Error clearing logs.', 'error');
                        button.prop('disabled', false).removeClass('srk-loading');
                    }
                },
                error: function(xhr, status, error) {
                    let errorMessage = 'Network error. Please try again.';
                    if (xhr.responseText) {
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMessage = errorResponse.data.message;
                            }
                        } catch (e) {
                            // Not JSON, use default
                            if (xhr.status === 0) {
                                errorMessage = 'Connection error. Please check your internet connection.';
                            } else if (xhr.status === 403) {
                                errorMessage = 'Permission denied. Please refresh the page.';
                            } else if (xhr.status === 500) {
                                errorMessage = 'Server error. Please check the error logs.';
                            }
                        }
                    }
                    showNotice(errorMessage, 'error');
                    button.prop('disabled', false).removeClass('srk-loading');
                }
            });
        });

        // Export logs (event delegation)
        $(document).on('click', '#srk_export_404', function(e) {
            e.preventDefault();

            const button = $(this);
            button.prop('disabled', true).addClass('srk-loading');

            // Check nonce
            if (!nonce) {
                showNotice('Error: Security token missing. Please refresh the page.', 'error');
                button.prop('disabled', false).removeClass('srk-loading');
                return;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'srk_export_404_logs',
                    nonce: nonce
                },
                success: function(response) {
                    if (response && response.success && response.data) {
                        try {
                            // Decode base64 content
                            const content = atob(response.data.file_content);
                            const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
                            const link = document.createElement('a');
                            const url = URL.createObjectURL(blob);
                            
                            link.setAttribute('href', url);
                            link.setAttribute('download', response.data.filename || 'srk-404-logs.csv');
                            link.style.visibility = 'hidden';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            
                            // Clean up the object URL
                            setTimeout(function() {
                                URL.revokeObjectURL(url);
                            }, 100);
                            
                            showNotice(srk404Ajax.messages?.export_success || 'Export generated successfully.', 'success');
                        } catch (error) {
                            showNotice('Error processing export file.', 'error');
                        }
                    } else {
                        showNotice(response.data?.message || 'Error generating export.', 'error');
                    }
                    button.prop('disabled', false).removeClass('srk-loading');
                },
                error: function(xhr, status, error) {
                    let errorMessage = 'Network error. Please try again.';
                    if (xhr.responseText) {
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMessage = errorResponse.data.message;
                            }
                        } catch (e) {
                            // Not JSON, use default
                            if (xhr.status === 0) {
                                errorMessage = 'Connection error. Please check your internet connection.';
                            } else if (xhr.status === 403) {
                                errorMessage = 'Permission denied. Please refresh the page.';
                            } else if (xhr.status === 500) {
                                errorMessage = 'Server error. Please check the error logs.';
                            }
                        }
                    }
                    showNotice(errorMessage, 'error');
                    button.prop('disabled', false).removeClass('srk-loading');
                }
            });
        });

        // Refresh stats (event delegation)
        $(document).on('click', '#srk_refresh_stats', function(e) {
            e.preventDefault();
            window.location.reload();
        });

        // Handle 404 per page select change
        $('#srk_404_per_page_select').on('change', function() {
            const perPage = $(this).val();
            const currentUrl = new URL(window.location.href);
            
            // Update or add per_page parameter
            currentUrl.searchParams.set('srk_404_per_page', perPage);
            
            // Reset to page 1 when changing per page
            currentUrl.searchParams.set('srk_404_paged', '1');
            
            // Ensure we stay on the 404 monitor tab
            currentUrl.searchParams.set('tab', '404-monitor');
            
            // Add hash to ensure tab is active
            currentUrl.hash = '404-monitor';
            
            // Redirect to new URL
            window.location.href = currentUrl.toString();
        });

        // Convert to redirect - redirect directly to redirection page (event delegation)
        $(document).on('click', '.srk-btn-convert', function(e) {
            e.preventDefault();

            const button = $(this);
            const url = button.data('log-url');

            // Construct admin URL from ajaxurl or current location
            let adminBase = '';
            if (ajaxUrl) {
                adminBase = ajaxUrl.replace('/admin-ajax.php', '/admin.php');
            } else {
                // Fallback: construct from current location
                const currentUrl = new URL(window.location.href);
                adminBase = currentUrl.origin + currentUrl.pathname.replace(/\/[^\/]*$/, '/admin.php');
            }
            
            const redirectUrl = adminBase + '?page=seo-repair-kit-redirection';
            const sourceUrlParam = url ? '&source_url=' + encodeURIComponent(url) : '';
            window.location.href = redirectUrl + sourceUrlParam;
        });

        // Check if table is empty
        function checkEmptyTable() {
            const tbody = $('.srk-404-table tbody');
            if (tbody.find('tr').length === 0) {
                tbody.html('<tr><td colspan="8" class="srk-empty-state">No 404 errors found.</td></tr>');
            }
        }

        // Show notice
        function showNotice(message, type) {
            type = type || 'info';
            const notice = $('<div class="srk-notice srk-notice-' + type + '">' + message + '</div>');
            
            // Remove existing notices
            $('.srk-notice').remove();
            
            // Add notice - try multiple selectors to find the right location
            let target = $('#srk-tab-404-monitor').first();
            if (target.length === 0) {
                target = $('.srk-404-stats-dashboard').first();
            }
            if (target.length === 0) {
                target = $('.seo-repair-kit-404-manager h1').first();
            }
            if (target.length === 0) {
                target = $('.wrap').first();
            }
            
            if (target.length > 0) {
                target.prepend(notice);
            } else {
                $('body').prepend(notice);
            }
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }

        // All event handlers are now set up using event delegation
        // They will work on both the standalone 404 page and the tab content
    });
})(jQuery);