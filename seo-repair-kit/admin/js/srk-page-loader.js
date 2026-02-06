/**
 * SEO Repair Kit - Smart Page Loader
 * 
 * Intelligently shows a loader only when page loading takes time.
 * Detects when styles and scripts are loaded before hiding.
 * 
 * @since 2.1.0
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        MIN_SHOW_TIME: 500,        // Minimum time to show loader (ms) - prevents flicker
        LOAD_THRESHOLD: 400,       // Time threshold before showing loader (ms) - prevents blinking on fast loads
        FADE_OUT_DURATION: 300     // Fade out animation duration (ms)
    };

    // State
    let loaderShown = false;
    // Use global start time if set by early script, otherwise use current timing
    let startTime = window.srkLoaderStartTime || (performance && performance.timing ? performance.timing.navigationStart : Date.now());
    let pageReady = false;
    let showTimeout = null;
    let hideTimeout = null;

    /**
     * Check if we're on an SRK admin page
     */
    function isSRKPage() {
        const page = new URLSearchParams(window.location.search).get('page');
        const srkPages = [
            'seo-repair-kit-dashboard',
            'seo-repair-kit-link-scanner',
            'seo-repair-kit-keytrack',
            'srk-schema-manager',
            'srk-ai-chatbot',
            'alt-image-missing',
            'seo-repair-kit-redirection',
            'seo-repair-kit-settings',
            'seo-repair-kit-upgrade-pro'
        ];
        return page && srkPages.includes(page);
    }

    /**
     * Show the loader
     */
    function showLoader() {
        if (loaderShown) {
            return;
        }

        let overlay = document.getElementById('srk-page-loader-overlay');
        if (!overlay) {
            // ✅ FIX: Try to create it if it doesn't exist
            const body = document.body || document.getElementsByTagName('body')[0];
            if (body) {
                const newOverlay = document.createElement('div');
                newOverlay.id = 'srk-page-loader-overlay';
                newOverlay.className = 'srk-page-loader-overlay';
                newOverlay.setAttribute('aria-hidden', 'true');
                newOverlay.innerHTML = '<div class="srk-page-loader-container"><div class="srk-page-loader-spinner"></div><p class="srk-page-loader-text">Loading...</p></div>';
                body.appendChild(newOverlay);
                overlay = newOverlay;
            } else {
                // ✅ FIX: If body not available, wait for it
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function() {
                        showLoader();
                    });
                }
                return;
            }
        }

        overlay.classList.add('active');
        overlay.style.display = 'flex'; // Ensure it's visible
        loaderShown = true;
    }

    /**
     * Hide the loader
     */
    function hideLoader() {
        if (!loaderShown) {
            return;
        }

        const overlay = document.getElementById('srk-page-loader-overlay');
        if (!overlay) {
            return;
        }

        // Ensure minimum show time to prevent flicker
        const elapsed = Date.now() - startTime;
        const remaining = Math.max(0, CONFIG.MIN_SHOW_TIME - elapsed);

        setTimeout(function() {
            overlay.classList.remove('active');
            document.body.classList.add('srk-page-loaded');
            
            // Remove overlay from DOM after fade out
            setTimeout(function() {
                if (overlay && overlay.parentNode) {
                    overlay.style.display = 'none';
                }
            }, CONFIG.FADE_OUT_DURATION);
        }, remaining);
    }

    /**
     * Hide loader when page is ready
     */
    function handlePageReady() {
        if (pageReady) {
            return; // Already handled
        }
        
        pageReady = true;
        
        // Cancel showing loader if page loaded quickly
        if (showTimeout) {
            clearTimeout(showTimeout);
            showTimeout = null;
        }
        
        // Only hide if loader was actually shown
        // Check if loader was shown for a meaningful duration
        if (loaderShown) {
            const elapsed = Date.now() - startTime;
            // If page loaded very quickly after showing loader, don't show it at all
            if (elapsed < CONFIG.LOAD_THRESHOLD + 200) {
                // Page loaded too quickly, hide immediately without animation
                const overlay = document.getElementById('srk-page-loader-overlay');
                if (overlay) {
                    overlay.style.display = 'none';
                    overlay.classList.remove('active');
                }
                loaderShown = false;
            } else {
                // Normal hide with animation
                hideLoader();
            }
        }
    }

    /**
     * Initialize the loader system
     */
    function init() {
        // Only run on SRK pages
        if (!isSRKPage()) {
            return;
        }

        // Use global start time if available (set by early script), otherwise calculate
        if (window.srkLoaderStartTime) {
            startTime = window.srkLoaderStartTime;
        } else if (performance && performance.timing) {
            startTime = performance.timing.navigationStart;
        } else {
            startTime = Date.now();
        }

        // Function to check and show loader if needed
        function checkAndShowLoader() {
            const elapsed = Date.now() - startTime;
            
            // If threshold exceeded and page not ready, show loader
            if (elapsed > CONFIG.LOAD_THRESHOLD && !pageReady) {
                showLoader();
            }
        }

        // Wait for loader HTML to be available in DOM
        function waitForLoader() {
            const overlay = document.getElementById('srk-page-loader-overlay');
            if (!overlay) {
                // Retry after a short delay if loader HTML not yet in DOM
                setTimeout(waitForLoader, 10);
                return;
            }

            // Don't check immediately - wait for threshold to avoid blinking
            // Schedule showing loader if threshold is exceeded
            showTimeout = setTimeout(function() {
                // Double-check that page is still loading and not ready
                if (!pageReady && document.readyState !== 'complete') {
                    const elapsed = Date.now() - startTime;
                    // Only show if we've actually exceeded the threshold
                    if (elapsed >= CONFIG.LOAD_THRESHOLD) {
                        showLoader();
                    }
                }
            }, CONFIG.LOAD_THRESHOLD);

            // Hide loader when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', handlePageReady);
            } else if (document.readyState === 'interactive' || document.readyState === 'complete') {
                // DOM already ready, but wait for window load
                // Don't hide immediately, wait for full load
            }

            // Also hide when window fully loads (all resources)
            if (document.readyState === 'complete') {
                handlePageReady();
            } else {
                window.addEventListener('load', handlePageReady);
            }

            // Fallback: hide loader after maximum time (5 seconds)
            hideTimeout = setTimeout(function() {
                if (loaderShown) {
                    hideLoader();
                }
                if (showTimeout) {
                    clearTimeout(showTimeout);
                }
            }, 5000);
        }

        // Start waiting for loader HTML
        waitForLoader();
    }

    // Initialize immediately - don't wait for DOMContentLoaded
    // This ensures we catch slow loading pages early
    if (document.readyState === 'complete') {
        // Page already loaded, check if we should have shown loader
        init();
    } else {
        // Initialize immediately
        init();
        
        // Check if page is already loaded or loading very quickly
        if (document.readyState === 'complete') {
            // Page already fully loaded, don't show loader
            pageReady = true;
            if (showTimeout) {
                clearTimeout(showTimeout);
                showTimeout = null;
            }
        } else if (document.readyState === 'interactive') {
            // DOM is ready but resources might still be loading
            // Check if it loaded quickly - if so, don't show loader
            const elapsed = Date.now() - startTime;
            if (elapsed < CONFIG.LOAD_THRESHOLD) {
                // Page loaded too quickly, mark as ready to prevent loader
                pageReady = true;
                if (showTimeout) {
                    clearTimeout(showTimeout);
                    showTimeout = null;
                }
            }
        }
    }

})();