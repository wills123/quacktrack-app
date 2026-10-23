// Enhanced Navigation and Mobile Menu Functionality
// Optimized for performance and memory management

// Performance utilities
const enhancementUtils = {
    debounce: function(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func.apply(this, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    throttle: function(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => {
                    inThrottle = false;
                }, limit);
            }
        };
    }
};

// Global cleanup for enhancements
const enhancementCleanup = {
    eventListeners: new Map(),
    timers: new Set(),
    observers: new Set(),

    addEventListener: function(element, event, handler, options) {
        if (!element) {
            return;
        }
        element.addEventListener(event, handler, options);

        if (!this.eventListeners.has(element)) {
            this.eventListeners.set(element, []);
        }
        this.eventListeners.get(element).push({ event, handler, options });
    },

    cleanup: function() {
        this.timers.forEach(timer => clearTimeout(timer));
        this.timers.clear();

        this.observers.forEach(observer => observer.disconnect());
        this.observers.clear();

        this.eventListeners.forEach((listeners, element) => {
            listeners.forEach(({ event, handler, options }) => {
                if (element && element.removeEventListener) {
                    element.removeEventListener(event, handler, options);
                }
            });
        });
        this.eventListeners.clear();
    }
};

document.addEventListener('DOMContentLoaded', () => {
    // Mobile/desktop sidebar toggle
    const hamburger = document.querySelector('.hamburger');
    const nav = document.querySelector('#main-navigation');
    const sidebar = document.querySelector('.app-sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const body = document.body;

    const setSidebarState = (isOpen) => {
        if (!sidebar) {
            return;
        }

        const isMobileShell = window.innerWidth <= 1024;
        body.classList.toggle('sidebar-open', isOpen);
        sidebar.classList.toggle('open', isOpen);
        sidebar.setAttribute('aria-hidden', isMobileShell && !isOpen ? 'true' : 'false');

        if (hamburger) {
            hamburger.classList.toggle('active', isOpen);
            hamburger.setAttribute('aria-expanded', isOpen);
        }

        if (nav) {
            const shouldHide = isMobileShell && !isOpen;
            nav.setAttribute('aria-hidden', shouldHide ? 'true' : 'false');
        }

        if (overlay) {
            overlay.classList.toggle('active', isOpen);
            overlay.setAttribute('aria-hidden', (!isOpen).toString());
        }
    };

    if (hamburger && sidebar) {
        // Create hamburger icon spans if they don't exist
        if (!hamburger.querySelector('span')) {
            hamburger.innerHTML = '<span></span>';
        }

        const hamburgerClickHandler = function() {
            const isCurrentlyOpen = body.classList.contains('sidebar-open');
            setSidebarState(!isCurrentlyOpen);
        };

        enhancementCleanup.addEventListener(hamburger, 'click', hamburgerClickHandler);

        const documentClickHandler = function(event) {
            if (!body.classList.contains('sidebar-open')) {
                return;
            }

            if (sidebar.contains(event.target) || (hamburger && hamburger.contains(event.target))) {
                return;
            }

            setSidebarState(false);
            if (hamburger) {
                hamburger.focus();
            }
        };
        enhancementCleanup.addEventListener(document, 'click', documentClickHandler);

        // Close sidebar on escape
        const documentKeydownHandler = function(event) {
            if (event.key === 'Escape' && body.classList.contains('sidebar-open')) {
                setSidebarState(false);
                if (hamburger) {
                    hamburger.focus();
                }
            }
        };
        enhancementCleanup.addEventListener(document, 'keydown', documentKeydownHandler);

        // Close menu when window is resized to desktop with debouncing
        const resizeHandler = enhancementUtils.debounce(() => {
            if (window.innerWidth > 1024) {
                setSidebarState(false);
                if (nav) {
                    nav.setAttribute('aria-hidden', 'false');
                }
                sidebar.setAttribute('aria-hidden', 'false');
            } else if (!body.classList.contains('sidebar-open')) {
                setSidebarState(false);
            }
        }, 250);
        enhancementCleanup.addEventListener(window, 'resize', resizeHandler);

        if (overlay) {
            enhancementCleanup.addEventListener(overlay, 'click', () => setSidebarState(false));
        }

        // Synchronize the initial ARIA state with the current viewport.
        setSidebarState(false);
    }

    // Add active navigation indicators
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('#main-navigation a');

    navLinks.forEach(link => {
        const linkPath = new URL(link.href).pathname;
        if (linkPath === currentPath) {
            link.setAttribute('aria-current', 'page');
            link.classList.add('active');
        }
    });

    // Add user region data to mobile nav for display
    const userInfo = document.querySelector('.sidebar-meta .meta-value');
    if (userInfo && nav) {
        const regionText = userInfo.textContent;
        nav.setAttribute('data-user-region', regionText);
    }

    // Set navigation ID for accessibility
    if (nav) {
        nav.id = 'main-navigation';
    }

    // Enhanced focus management for navigation
    navLinks.forEach((link, index) => {
        link.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
                event.preventDefault();
                const nextIndex = (index + 1) % navLinks.length;
                navLinks[nextIndex].focus();
            } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
                event.preventDefault();
                const prevIndex = (index - 1 + navLinks.length) % navLinks.length;
                navLinks[prevIndex].focus();
            }
        });
    });

    // Add smooth scroll behavior for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
});

// Enhanced Modal Functionality
document.addEventListener('DOMContentLoaded', () => {
    // Modal animation and interaction utilities
    const ModalManager = {
        // Show modal with animation
        show: function(modal) {
            if (!modal) {
                return;
            }

            // Prevent body scroll
            document.body.style.overflow = 'hidden';
            document.body.classList.add('modal-open');

            // Show modal with flex for centering
            modal.style.display = 'flex';

            // IMPORTANT: Remove aria-hidden to make modal accessible
            modal.removeAttribute('aria-hidden');

            // Set proper ARIA attributes for accessibility
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('role', 'dialog');

            // Ensure modal is interactive
            modal.style.pointerEvents = 'auto';

            // Force reflow to ensure display change is applied
            modal.offsetHeight;

            // Add show class for animation
            modal.classList.add('show');

            // Focus management
            this.trapFocus(modal);

            // Set initial focus to close button or first focusable element
            const closeButton = modal.querySelector('.close-button');
            const firstFocusable = modal.querySelector('input, select, textarea, button, [tabindex]:not([tabindex="-1"])');

            if (closeButton) {
                closeButton.focus();
            } else if (firstFocusable) {
                firstFocusable.focus();
            }
        },

        // Hide modal with animation
        hide: function(modal) {
            if (!modal) {
                return;
            }

            // Remove show class for animation
            modal.classList.remove('show');

            // Immediately prevent interaction
            modal.style.pointerEvents = 'none';

            // Return focus to trigger element first
            const triggerElement = modal.dataset.triggerElement;
            if (triggerElement) {
                const element = document.querySelector(triggerElement);
                if (element) {
                    element.focus();
                }
            }

            // Wait for animation to complete before hiding
            setTimeout(() => {
                modal.style.display = 'none';
                document.body.style.overflow = '';
                document.body.classList.remove('modal-open');

                // Ensure modal stays non-interactive
                modal.style.pointerEvents = 'none';

                // Safely set aria-hidden after focus has moved away
                if (window.AccessibilityManager) {
                    window.AccessibilityManager.safeSetAriaHidden(modal, true);
                } else {
                    // Fallback: only set if no focused descendants
                    if (!modal.contains(document.activeElement)) {
                        modal.setAttribute('aria-hidden', 'true');
                    }
                }
            }, 300); // Match CSS transition duration
        },

        // Trap focus within modal
        trapFocus: function(modal) {
            const focusableElements = modal.querySelectorAll(
                'input, select, textarea, button, [tabindex]:not([tabindex="-1"])'
            );

            if (focusableElements.length === 0) {
                return;
            }

            const firstFocusable = focusableElements[0];
            const lastFocusable = focusableElements[focusableElements.length - 1];

            modal.addEventListener('keydown', (e) => {
                if (e.key === 'Tab') {
                    if (e.shiftKey) {
                        // Shift + Tab
                        if (document.activeElement === firstFocusable) {
                            e.preventDefault();
                            lastFocusable.focus();
                        }
                    } else {
                        // Tab
                        if (document.activeElement === lastFocusable) {
                            e.preventDefault();
                            firstFocusable.focus();
                        }
                    }
                }
            });
        }
    };

    // Initialize all modals
    const modals = document.querySelectorAll('.modal');

    modals.forEach(modal => {
        // Initialize modal with proper accessibility attributes
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        // Ensure hidden modals don't block interactions
        if (modal.style.display === 'none' || !modal.style.display || !modal.classList.contains('show')) {
            modal.style.pointerEvents = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }

        // Close button functionality
        const closeButton = modal.querySelector('.close-button');
        if (closeButton) {
            closeButton.addEventListener('click', () => {
                ModalManager.hide(modal);
            });
        }

        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                ModalManager.hide(modal);
            }
        });

        // Close on Escape key
        modal.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                ModalManager.hide(modal);
            }
        });

        // Monitor focus changes to prevent aria-hidden violations
        modal.addEventListener('focusin', () => {
            // If any element within modal receives focus, remove aria-hidden
            modal.removeAttribute('aria-hidden');
        });

        modal.addEventListener('focusout', (_e) => {
            // Only set aria-hidden if focus is moving completely outside the modal
            // and the modal is not visible
            setTimeout(() => {
                if (!modal.contains(document.activeElement) &&
                    (modal.style.display === 'none' || !modal.classList.contains('show'))) {
                    modal.setAttribute('aria-hidden', 'true');
                }
            }, 100);
        });
    });

    // Accessibility utility to safely manage aria-hidden
    const AccessibilityManager = {
        // Safely set aria-hidden, checking for focused descendants first
        safeSetAriaHidden: function(element, hidden = true) {
            if (!element) {
                return;
            }

            if (hidden) {
                // Check if element or any descendant has focus
                if (element.contains(document.activeElement)) {
                    console.warn('Cannot set aria-hidden="true" on element with focused descendant:', element);
                    return false;
                }
                element.setAttribute('aria-hidden', 'true');
            } else {
                element.removeAttribute('aria-hidden');
            }
            return true;
        },

        // Monitor and fix aria-hidden violations with enhanced detection
        monitorAriaHidden: function() {
            // Use MutationObserver for more efficient monitoring
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'aria-hidden') {
                        const element = mutation.target;
                        if (element.getAttribute('aria-hidden') === 'true' && element.contains(document.activeElement)) {
                            console.warn('Fixing aria-hidden violation on:', element);
                            element.removeAttribute('aria-hidden');
                        }
                    }
                });
            });

            // Observe all elements for aria-hidden changes
            observer.observe(document.body, {
                attributes: true,
                attributeFilter: ['aria-hidden'],
                subtree: true
            });

            // Also check periodically as a fallback
            setInterval(() => {
                const hiddenElements = document.querySelectorAll('[aria-hidden="true"]');
                hiddenElements.forEach(element => {
                    if (element.contains(document.activeElement)) {
                        console.warn('Fixing aria-hidden violation on:', element);
                        element.removeAttribute('aria-hidden');
                    }
                });
            }, 1000); // Reduced frequency since MutationObserver handles most cases
        },

        // Override setAttribute to prevent aria-hidden violations
        preventAriaHiddenViolations: function() {
            const originalSetAttribute = Element.prototype.setAttribute;
            Element.prototype.setAttribute = function(name, value) {
                if (name === 'aria-hidden' && value === 'true') {
                    // Check if this element or any descendant has focus
                    if (this.contains(document.activeElement)) {
                        console.warn('Prevented aria-hidden="true" on element with focused descendant:', this);
                        return; // Don't set the attribute
                    }
                }
                return originalSetAttribute.call(this, name, value);
            };
        }
    };

    // Start monitoring for aria-hidden violations
    AccessibilityManager.monitorAriaHidden();

    // Prevent aria-hidden violations at the DOM level
    AccessibilityManager.preventAriaHiddenViolations();

    // Immediate fix for any existing violations on page load
    setTimeout(() => {
        const hiddenElements = document.querySelectorAll('[aria-hidden="true"]');
        hiddenElements.forEach(element => {
            if (element.contains(document.activeElement)) {
                console.warn('Fixed aria-hidden violation on page load:', element);
                element.removeAttribute('aria-hidden');
            }
        });
    }, 100);

    // Make both managers globally available for existing code
    window.ModalManager = ModalManager;
    window.AccessibilityManager = AccessibilityManager;

    // Enhance existing modal triggers
    document.querySelectorAll('[data-modal-target]').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            const targetModal = document.querySelector(trigger.dataset.modalTarget);
            if (targetModal) {
                // Store trigger element for focus return
                targetModal.dataset.triggerElement = `#${trigger.id}` || trigger.tagName.toLowerCase();
                ModalManager.show(targetModal);
            }
        });
    });

    // Auto-enhance modals that don't have proper structure
    modals.forEach(modal => {
        const content = modal.querySelector('.modal-content');
        if (!content) {
            return;
        }

        // Check if modal has proper header structure
        const hasHeader = content.querySelector('.modal-header');
        const hasBody = content.querySelector('.modal-body');
        const hasFooter = content.querySelector('.modal-footer');

        // Only auto-enhance if modal doesn't have the new structure
        if (!hasHeader && !hasBody && !hasFooter) {
            // Wrap existing content in modal-body, but preserve close button
            const closeButton = content.querySelector('.close-button');
            let closeButtonHTML = '';
            if (closeButton) {
                closeButtonHTML = closeButton.outerHTML;
                closeButton.remove();
            }

            const existingContent = content.innerHTML;
            content.innerHTML = `<div class="modal-body">${existingContent}</div>`;

            // Re-add close button if it existed
            if (closeButtonHTML) {
                const newCloseButton = document.createElement('div');
                newCloseButton.innerHTML = closeButtonHTML;
                const closeButtonElement = newCloseButton.firstChild;
                closeButtonElement.style.position = 'absolute';
                closeButtonElement.style.top = 'var(--space-lg)';
                closeButtonElement.style.right = 'var(--space-lg)';
                content.appendChild(closeButtonElement);
            }
        }
    });
});

// Enhanced Micro-Interactions and Animations
document.addEventListener('DOMContentLoaded', () => {
    // Button Loading State Manager
    const ButtonLoadingManager = {
        // Set button to loading state
        setLoading: function(button, loadingText = 'Loading...') {
            if (!button) {
                return;
            }

            // Store original content
            button.dataset.originalText = button.innerHTML;
            button.dataset.originalDisabled = button.disabled;

            // Set loading state
            button.classList.add('btn-loading');
            button.disabled = true;
            button.innerHTML = loadingText;

            // Add loading dots animation
            if (loadingText.includes('...')) {
                button.classList.add('loading-dots');
            }
        },

        // Remove loading state
        removeLoading: function(button) {
            if (!button) {
                return;
            }

            // Restore original state
            button.classList.remove('btn-loading', 'loading-dots');
            button.disabled = button.dataset.originalDisabled === 'true';
            button.innerHTML = button.dataset.originalText || button.innerHTML;

            // Clean up data attributes
            delete button.dataset.originalText;
            delete button.dataset.originalDisabled;
        },

        // Set success state with animation
        setSuccess: function(button, successText = 'Success!', duration = 2000) {
            if (!button) {
                return;
            }

            this.removeLoading(button);
            button.classList.add('btn-bounce');
            const originalText = button.innerHTML;
            button.innerHTML = successText;

            setTimeout(() => {
                button.classList.remove('btn-bounce');
                button.innerHTML = originalText;
            }, duration);
        },

        // Set error state with animation
        setError: function(button, errorText = 'Error!', duration = 2000) {
            if (!button) {
                return;
            }

            this.removeLoading(button);
            button.classList.add('btn-shake');
            const originalText = button.innerHTML;
            button.innerHTML = errorText;

            setTimeout(() => {
                button.classList.remove('btn-shake');
                button.innerHTML = originalText;
            }, duration);
        }
    };

    // Make ButtonLoadingManager globally available
    window.ButtonLoadingManager = ButtonLoadingManager;

    // Enhanced Form Interactions
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        // Add loading state to form submissions
        form.addEventListener('submit', (_e) => {
            // Skip forms that need special handling
            if (form.classList.contains('no-loading')) {
                return;
            }

            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitButton && !submitButton.classList.contains('btn-loading')) {
                ButtonLoadingManager.setLoading(submitButton, 'Submitting...');

                // Add form loading class
                form.classList.add('form-loading');

                // If this is a demo or test, remove loading after 2 seconds
                if (window.location.pathname.includes('demo') || form.id === 'demoForm') {
                    setTimeout(() => {
                        ButtonLoadingManager.setSuccess(submitButton, 'Submitted!');
                        form.classList.remove('form-loading');
                    }, 2000);
                }
            }
        });

        // Enhanced input interactions
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            // Add focus glow effect
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focus-within');
            });

            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('focus-within');
            });

            // Add typing animation for text inputs
            if (input.type === 'text' || input.type === 'email' || input.tagName === 'TEXTAREA') {
                let typingTimer;
                input.addEventListener('input', function() {
                    this.classList.add('typing');
                    clearTimeout(typingTimer);
                    typingTimer = setTimeout(() => {
                        this.classList.remove('typing');
                    }, 500);
                });
            }
        });
    });

    // Enhanced Card Interactions
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        // Add staggered animation on page load
        card.style.animationDelay = `${index * 100}ms`;

        // Add intersection observer for scroll animations with optimization
        if (!window.cardAnimationObserver) {
            window.cardAnimationObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate-in');
                        // Unobserve after animation to improve performance
                        window.cardAnimationObserver.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            });

            enhancementCleanup.observers.add(window.cardAnimationObserver);
        }

        window.cardAnimationObserver.observe(card);
    });

    // Enhanced Button Interactions
    const buttons = document.querySelectorAll('button, .btn');
    buttons.forEach(button => {
        // Add pulse animation for important buttons
        if (button.classList.contains('btn-primary') && button.textContent.toLowerCase().includes('submit')) {
            button.classList.add('btn-pulse');
        }

        // Add click animation
        button.addEventListener('click', function(e) {
            // Don't animate if already loading
            if (this.classList.contains('btn-loading')) {
                e.preventDefault();
                return;
            }

            // Add click animation
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = '';
            }, 150);
        });

        // Add keyboard interaction
        button.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                this.style.transform = 'scale(0.95)';
            }
        });

        button.addEventListener('keyup', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                this.style.transform = '';
            }
        });
    });

    // Enhanced Table Row Interactions
    const tableRows = document.querySelectorAll('.table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('click', function() {
            // Add selection animation
            this.style.transform = 'scale(1.02)';
            setTimeout(() => {
                this.style.transform = '';
            }, 200);
        });
    });

    // Enhanced Navigation Interactions
    const navLinks = document.querySelectorAll('#main-navigation a');
    navLinks.forEach(link => {
        link.addEventListener('click', function(_e) {
            // Add loading state for navigation
            if (!this.href.includes('#')) {
                this.classList.add('loading');
                this.innerHTML += ' <span class="loading-dots"></span>';
            }
        });
    });

    // Tooltip functionality
    const tooltipElements = document.querySelectorAll('[data-tooltip]');
    tooltipElements.forEach(element => {
        element.addEventListener('mouseenter', function() {
            this.classList.add('tooltip-active');
        });

        element.addEventListener('mouseleave', function() {
            this.classList.remove('tooltip-active');
        });
    });

    // Progress bar animation for page loading
    function showProgressBar() {
        const progressBar = document.createElement('div');
        progressBar.className = 'progress-bar';
        progressBar.style.position = 'fixed';
        progressBar.style.top = '0';
        progressBar.style.left = '0';
        progressBar.style.zIndex = '9999';
        document.body.appendChild(progressBar);

        setTimeout(() => {
            progressBar.remove();
        }, 2000);
    }

    // Show progress bar on page navigation
    window.addEventListener('beforeunload', showProgressBar);

    // Enhanced scroll animations with performance optimization
    const animateOnScroll = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-fade-in');
                // Unobserve after animation to improve performance
                animateOnScroll.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1
    });

    enhancementCleanup.observers.add(animateOnScroll);

    // Apply scroll animations to various elements with batching
    requestAnimationFrame(() => {
        document.querySelectorAll('h1, h2, h3, .form-section, .summary-card').forEach(el => {
            animateOnScroll.observe(el);
        });
    });

    // Global cleanup on page unload
    enhancementCleanup.addEventListener(window, 'beforeunload', () => {
        enhancementCleanup.cleanup();
    });

    enhancementCleanup.addEventListener(window, 'unload', () => {
        enhancementCleanup.cleanup();
    });
});

// Global error handling for unhandled promise rejections and JavaScript errors
window.addEventListener('unhandledrejection', (event) => {
    console.error('Unhandled promise rejection:', event.reason);

    // Prevent the default browser behavior
    event.preventDefault();

    // Show user-friendly error message
    const errorMessage = 'An unexpected error occurred. Please refresh the page and try again.';

    // Try to show error using existing notification system
    if (window.announceToScreenReader) {
        window.announceToScreenReader(errorMessage, 'assertive');
    }

    // Show visual error message
    showGlobalError(errorMessage);
});

window.addEventListener('error', (event) => {
    console.error('JavaScript error:', event.error);

    // Don't show errors for script loading failures or cross-origin issues
    if (event.message.includes('Script error') || event.message.includes('Non-Error promise rejection')) {
        return;
    }

    // Show user-friendly error message for critical errors
    const errorMessage = 'A technical error occurred. Please refresh the page and try again.';

    if (window.announceToScreenReader) {
        window.announceToScreenReader(errorMessage, 'assertive');
    }

    showGlobalError(errorMessage);
});

// Function to show global error messages
function showGlobalError(message) {
    // Remove any existing global error
    const existingError = document.querySelector('.global-error-message');
    if (existingError) {
        existingError.remove();
    }

    // Create error message element
    const errorDiv = document.createElement('div');
    errorDiv.className = 'global-error-message';
    errorDiv.setAttribute('role', 'alert');
    errorDiv.setAttribute('aria-live', 'assertive');
    errorDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #dc3545;
        color: white;
        padding: 15px 20px;
        border-radius: 4px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        z-index: 10000;
        max-width: 400px;
        font-size: 14px;
        line-height: 1.4;
    `;

    errorDiv.innerHTML = `
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" 
                    style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; margin-left: 10px;"
                    aria-label="Close error message">×</button>
        </div>
    `;

    document.body.appendChild(errorDiv);

    // Auto-remove after 10 seconds
    setTimeout(() => {
        if (errorDiv.parentNode) {
            errorDiv.remove();
        }
    }, 10000);
}
