/**
 * Lifetime Deal Countdown Timer
 * Creates urgency by showing time remaining for the deal
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        const $countdownTimer = $('.srk-countdown-timer');
        
        if ($countdownTimer.length === 0) {
            return;
        }

        // Get initial countdown data from data attributes or default to 15 days
        const daysAttr = $countdownTimer.data('days');
        const hoursAttr = $countdownTimer.data('hours');
        const minutesAttr = $countdownTimer.data('minutes');
        const secondsAttr = $countdownTimer.data('seconds');

        // Get stored countdown from localStorage or use defaults
        const storageKey = 'srk_lifetime_deal_countdown';
        let countdown = null;

        try {
            const stored = localStorage.getItem(storageKey);
            if (stored) {
                const parsed = JSON.parse(stored);
                const storedTime = new Date(parsed.endTime).getTime();
                const now = new Date().getTime();
                
                // Only use stored if it's still valid (not expired)
                if (storedTime > now) {
                    countdown = parsed;
                }
            }
        } catch (e) {
            // If localStorage fails or data is invalid, use defaults
        }

        // Initialize countdown if not stored or expired
        if (!countdown) {
            const now = new Date();
            const endTime = new Date(now);
            
            // Set to 15 days from now (or use data attributes if provided)
            const days = daysAttr !== undefined ? parseInt(daysAttr, 10) : 15;
            const hours = hoursAttr !== undefined ? parseInt(hoursAttr, 10) : 0;
            const minutes = minutesAttr !== undefined ? parseInt(minutesAttr, 10) : 0;
            const seconds = secondsAttr !== undefined ? parseInt(secondsAttr, 10) : 0;
            
            endTime.setDate(now.getDate() + days);
            endTime.setHours(now.getHours() + hours);
            endTime.setMinutes(now.getMinutes() + minutes);
            endTime.setSeconds(now.getSeconds() + seconds);
            
            countdown = {
                endTime: endTime.toISOString()
            };
            
            // Store in localStorage
            try {
                localStorage.setItem(storageKey, JSON.stringify(countdown));
            } catch (e) {
                // localStorage might not be available
            }
        }

        const endTime = new Date(countdown.endTime).getTime();

        // Update countdown every second
        function updateCountdown() {
            const now = new Date().getTime();
            const distance = endTime - now;

            if (distance < 0) {
                // Countdown expired - reset to 15 days
                const newEndTime = new Date();
                newEndTime.setDate(newEndTime.getDate() + 15);
                
                const newCountdown = {
                    endTime: newEndTime.toISOString()
                };
                
                try {
                    localStorage.setItem(storageKey, JSON.stringify(newCountdown));
                } catch (e) {
                    // localStorage might not be available
                }
                
                // Restart with new time
                setTimeout(updateCountdown, 1000);
                return;
            }

            // Calculate time units
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            // Update DOM
            $('#srk-countdown-days').text(days);
            $('#srk-countdown-hours').text(String(hours).padStart(2, '0'));
            $('#srk-countdown-minutes').text(String(minutes).padStart(2, '0'));
            $('#srk-countdown-seconds').text(String(seconds).padStart(2, '0'));

            // Add pulse animation when seconds change
            if (seconds % 2 === 0) {
                $countdownTimer.find('.srk-countdown-value').addClass('srk-countdown-pulse');
                setTimeout(function() {
                    $countdownTimer.find('.srk-countdown-value').removeClass('srk-countdown-pulse');
                }, 300);
            }
        }

        // Initial update
        updateCountdown();

        // Update every second
        setInterval(updateCountdown, 1000);
    });

})(jQuery);
