/*!
 * Media Elements Overflow Fix - JavaScript Enforcement
 * Ensures overflow: hidden is applied to all media elements
 * Addresses browser warning about overflow: visible on img, video, and canvas
 */

(function() {
    'use strict';

    // Function to fix media element overflow
    function fixMediaOverflow() {
    // Get all media elements
        const mediaElements = document.querySelectorAll('img, video, canvas');

        mediaElements.forEach(element => {
            // Force overflow hidden with high priority
            element.style.setProperty('overflow', 'hidden', 'important');
            element.style.setProperty('contain', 'layout style paint', 'important');

            // Add specific containment for video and canvas
            if (element.tagName === 'VIDEO' || element.tagName === 'CANVAS') {
                element.style.setProperty('contain', 'layout style paint size', 'important');
                element.style.setProperty('max-width', '100%', 'important');
                element.style.setProperty('max-height', '100%', 'important');
            }
        });

    // Media overflow fix applied silently
    }

    // Function to observe for new media elements
    function observeMediaElements() {
        const observer = new MutationObserver((mutations) => {
            let hasNewMedia = false;

            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) { // Element node
                        // Check if the added node is a media element
                        if (node.matches && node.matches('img, video, canvas')) {
                            hasNewMedia = true;
                        }
                        // Check if the added node contains media elements
                        if (node.querySelectorAll && node.querySelectorAll('img, video, canvas').length > 0) {
                            hasNewMedia = true;
                        }
                    }
                });
            });

            if (hasNewMedia) {
                // Small delay to ensure elements are fully rendered
                setTimeout(fixMediaOverflow, 10);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        return observer;
    }

    // Initial fix when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            fixMediaOverflow();
            observeMediaElements();
        });
    } else {
        fixMediaOverflow();
        observeMediaElements();
    }

    // Additional fix after window load (for any lazy-loaded content)
    window.addEventListener('load', () => {
        setTimeout(fixMediaOverflow, 100);
    });

    // Fix on modal show events (if ModalManager exists)
    document.addEventListener('modalShow', () => {
        setTimeout(fixMediaOverflow, 50);
    });

    // Fix when modals are opened (common event)
    document.addEventListener('click', (e) => {
        if (e.target.matches('[data-modal], [data-toggle="modal"], .modal-trigger')) {
            setTimeout(fixMediaOverflow, 100);
        }
    });

    // Additional fix for view transitions
    function disableViewTransitionsOnMedia() {
        const mediaElements = document.querySelectorAll('img, video, canvas');
        mediaElements.forEach(element => {
            element.style.setProperty('view-transition-name', 'none', 'important');
            element.style.setProperty('clip-path', 'inset(0)', 'important');
        });
    }

    // Apply view transition fix
    disableViewTransitionsOnMedia();

    // Periodic check to ensure styles remain applied
    setInterval(() => {
        const problematicElements = document.querySelectorAll('img, video, canvas');
        let needsFix = false;

        problematicElements.forEach(element => {
            const computedStyle = window.getComputedStyle(element);
            if (computedStyle.overflow === 'visible') {
                needsFix = true;
            }
        });

        if (needsFix) {
            fixMediaOverflow();
            disableViewTransitionsOnMedia();
        }
    }, 2000); // Check every 2 seconds

})();