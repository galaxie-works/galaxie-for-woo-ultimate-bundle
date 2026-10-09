/**
 * AI Chat Dark Mode - Auto Detection
 * Only loaded when color scheme is set to 'auto'
 */
(function() {
    'use strict';

    function applyDarkMode(isDark) {
        var wrappers = document.querySelectorAll('.aicwp-chat-wrapper');
        var widget = document.getElementById('aicwp-floating-chat-widget');

        wrappers.forEach(function(el) {
            if (isDark) {
                el.classList.add('dark-mode');
            } else {
                el.classList.remove('dark-mode');
            }
        });

        if (widget) {
            if (isDark) {
                widget.classList.add('dark-mode');
            } else {
                widget.classList.remove('dark-mode');
            }
        }

        // Update animated background colors for dark/light mode
        if (typeof AicwpSilkWave !== 'undefined') {
            AicwpSilkWave.setDarkMode(isDark);
        }
    }

    function init() {
        // Check system preference
        var isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyDarkMode(isDark);

        // Listen for system preference changes
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
                applyDarkMode(e.matches);
            });
        }
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
