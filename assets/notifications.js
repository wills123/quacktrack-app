/*!
 * Quack Track Notification System - JavaScript API
 * Version: 1.0
 * Description: Custom notification and confirmation system
 */

(function(window) {
    'use strict';

    /**
     * QuackNotify - Main notification system object
     * Provides API for creating, managing, and dismissing notifications
     */
    const QuackNotify = {
        // Configuration defaults
        config: {
            maxNotifications: 5,
            defaultDuration: 5000,
            animationDuration: 300, // Increased for smoother animations
            fadeOutDuration: 500, // Separate duration for fade-out animations
            position: 'top-right',
            stackDirection: 'down'
        },

        // Internal state
        notifications: new Map(),
        container: null,
        nextId: 1,

        /**
         * Initialize the notification system
         * Creates the container and sets up event listeners
         */
        init() {
            if (this.container) {
                return;
            } // Already initialized

            // Create notification container
            this.container = document.createElement('div');
            this.container.className = 'notification-container';
            this.container.setAttribute('aria-live', 'polite');
            this.container.setAttribute('aria-label', 'Notifications');
            document.body.appendChild(this.container);

            // Set up global event listeners
            this.setupEventListeners();

            // Set up enhanced interaction behaviors
            this.setupInteractionBehaviors();

            // Set up confirmation handlers for data-confirm elements
            this.bindConfirmHandlers();
        },

        /**
         * Set up global event listeners for keyboard navigation
         */
        setupEventListeners() {
            // Handle escape key for dismissing notifications
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    // Check if there's an active confirmation modal first
                    const activeModal = document.querySelector('.confirmation-modal.show');
                    if (activeModal) {
                        // Let the modal handle its own escape key
                        return;
                    }

                    // Dismiss the topmost notification
                    if (this.notifications.size > 0) {
                        e.preventDefault();
                        this.dismissTopmost();
                    }
                }
            });

            // Handle page visibility changes to pause/resume auto-dismiss
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.pauseAllTimers();
                } else {
                    this.resumeAllTimers();
                }
            });

            // Monitor accessibility preference changes
            if (window.matchMedia) {
                // Monitor reduced motion preference
                const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
                reducedMotionQuery.addEventListener('change', () => {
                    this.updateAccessibilityPreferences();
                });

                // Monitor high contrast preference
                const highContrastQuery = window.matchMedia('(prefers-contrast: high)');
                highContrastQuery.addEventListener('change', () => {
                    this.updateAccessibilityPreferences();
                });
            }

            // Set initial accessibility preferences
            this.updateAccessibilityPreferences();
        },

        /**
         * Show a notification
         * @param {string} message - The notification message
         * @param {string} type - Notification type (success, error, warning, info)
         * @param {Object} options - Additional options
         * @returns {string} - Notification ID
         */
        show(message, type = 'info', options = {}) {
            this.init(); // Ensure system is initialized

            // Validate parameters
            if (!message || typeof message !== 'string') {
                console.warn('QuackNotify: Message is required and must be a string');
                return null;
            }

            const validTypes = ['success', 'error', 'warning', 'info'];
            if (!validTypes.includes(type)) {
                console.warn(`QuackNotify: Invalid type "${type}". Using "info" instead.`);
                type = 'info';
            }

            // Merge options with defaults
            const config = {
                duration: options.duration !== undefined ? options.duration : this.config.defaultDuration,
                persistent: options.persistent || false,
                actions: options.actions || [],
                onDismiss: options.onDismiss || null,
                onAction: options.onAction || null,
                ...options
            };

            // Create notification object
            const notification = {
                id: this.generateId(),
                type,
                message,
                config,
                element: null,
                timer: null,
                dismissed: false,
                timestamp: new Date()
            };

            // Check notification limit
            if (this.notifications.size >= this.config.maxNotifications) {
                this.dismissOldest();
            }

            // Create and show notification element
            this.createNotificationElement(notification);
            this.notifications.set(notification.id, notification);

            // Set up auto-dismiss timer if not persistent
            if (!config.persistent && config.duration > 0) {
                this.setDismissTimer(notification);
            }

            // Announce to screen readers
            this.announceToScreenReader(message, type);

            return notification.id;
        },

        /**
         * Create the DOM element for a notification
         * @param {Object} notification - Notification object
         */
        createNotificationElement(notification) {
            const element = document.createElement('div');
            element.className = `notification notification--${notification.type}`;
            element.setAttribute('role', 'alert');
            element.setAttribute('aria-live', 'assertive');
            element.setAttribute('data-notification-id', notification.id);

            // Create notification HTML structure
            element.innerHTML = `
                <div class="notification__icon">
                    ${this.getIconSVG(notification.type)}
                </div>
                <div class="notification__content">
                    <div class="notification__message">${this.escapeHtml(notification.message)}</div>
                    ${notification.config.actions.length > 0 ? this.createActionsHTML(notification) : ''}
                </div>
                <button class="notification__close" aria-label="Close notification" type="button">
                    ${this.getCloseSVG()}
                </button>
            `;

            // Set up event listeners
            this.setupNotificationEvents(element, notification);

            // Add to container with animation
            this.container.appendChild(element);
            notification.element = element;

            // Trigger show animation
            requestAnimationFrame(() => {
                element.classList.add('show');
            });
        },

        /**
         * Create HTML for notification actions
         * @param {Object} notification - Notification object
         * @returns {string} - Actions HTML
         */
        createActionsHTML(notification) {
            if (!notification.config.actions.length) {
                return '';
            }

            const actionsHTML = notification.config.actions.map((action, index) => {
                const btnClass = action.style ? `btn btn-${action.style}` : 'btn btn-secondary';
                return `<button class="notification__action ${btnClass}" data-action-index="${index}" type="button">
                    ${this.escapeHtml(action.text)}
                </button>`;
            }).join('');

            return `<div class="notification__actions">${actionsHTML}</div>`;
        },

        /**
         * Set up enhanced interaction behaviors
         */
        setupInteractionBehaviors() {
            // Add global click handler for notification interactions
            document.addEventListener('click', (e) => {
                // Check if click is on a notification or its children
                const notification = e.target.closest('.notification');
                if (notification) {
                    // Add visual feedback for notification clicks
                    this.addClickFeedback(notification);
                }
            });

            // Add global mouse movement handler for subtle hover effects
            document.addEventListener('mousemove', (e) => {
                const notification = e.target.closest('.notification');
                if (notification && !notification.classList.contains('mouse-tracking')) {
                    this.addMouseTracking(notification);
                }
            });
        },

        /**
         * Add visual click feedback to notification
         * @param {HTMLElement} element - Notification element
         */
        addClickFeedback(element) {
            element.style.transform = 'scale(0.98)';
            setTimeout(() => {
                element.style.transform = '';
            }, 100);
        },

        /**
         * Add subtle mouse tracking effect
         * @param {HTMLElement} element - Notification element
         */
        addMouseTracking(element) {
            element.classList.add('mouse-tracking');

            const handleMouseMove = (e) => {
                const rect = element.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;

                const centerX = rect.width / 2;
                const centerY = rect.height / 2;

                const rotateX = (y - centerY) / 20;
                const rotateY = (centerX - x) / 20;

                element.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateZ(5px)`;
            };

            const handleMouseLeave = () => {
                element.style.transform = '';
                element.classList.remove('mouse-tracking');
                element.removeEventListener('mousemove', handleMouseMove);
                element.removeEventListener('mouseleave', handleMouseLeave);
            };

            element.addEventListener('mousemove', handleMouseMove);
            element.addEventListener('mouseleave', handleMouseLeave);
        },

        /**
         * Set up event listeners for a notification element
         * @param {HTMLElement} element - Notification element
         * @param {Object} notification - Notification object
         */
        setupNotificationEvents(element, notification) {
            // Close button click
            const closeBtn = element.querySelector('.notification__close');
            closeBtn.addEventListener('click', () => {
                this.dismiss(notification.id);
            });

            // Action button clicks
            const actionBtns = element.querySelectorAll('.notification__action');
            actionBtns.forEach((btn, index) => {
                btn.addEventListener('click', () => {
                    const action = notification.config.actions[index];
                    if (action && typeof action.callback === 'function') {
                        action.callback(notification.id);
                    }
                    if (notification.config.onAction) {
                        notification.config.onAction(action, notification.id);
                    }
                    // Auto-dismiss after action unless persistent
                    if (!notification.config.persistent) {
                        this.dismiss(notification.id);
                    }
                });
            });

            // Click outside to dismiss (for non-critical notifications)
            if (!notification.config.persistent && notification.type !== 'error') {
                // Add click-outside dismissal with proper event handling
                const handleClickOutside = (e) => {
                    // Check if click is outside the notification element
                    if (!element.contains(e.target)) {
                        this.dismiss(notification.id);
                        document.removeEventListener('click', handleClickOutside);
                    }
                };

                // Add slight delay to prevent immediate dismissal on creation
                setTimeout(() => {
                    document.addEventListener('click', handleClickOutside);
                }, 100);

                // Store reference for cleanup
                element._clickOutsideHandler = handleClickOutside;
            }

            // Enhanced keyboard navigation for notifications
            element.addEventListener('keydown', (e) => {
                switch (e.key) {
                case 'Escape':
                    e.preventDefault();
                    this.dismiss(notification.id);
                    break;

                case 'Enter':
                case ' ':
                    // If focused on close button, dismiss
                    if (document.activeElement === closeBtn) {
                        e.preventDefault();
                        this.dismiss(notification.id);
                    } else if (document.activeElement === element) {
                        // If focused on notification itself, focus first interactive element
                        e.preventDefault();
                        const firstInteractive = element.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                        if (firstInteractive) {
                            firstInteractive.focus();
                        }
                    }
                    break;

                case 'Tab':
                    // Handle tab navigation within notification
                    this.handleNotificationTabNavigation(element, e);
                    break;

                case 'ArrowUp':
                case 'ArrowDown':
                    // Navigate between notifications
                    e.preventDefault();
                    this.navigateNotifications(notification.id, e.key === 'ArrowUp' ? 'up' : 'down');
                    break;

                case 'Home':
                    // Focus first notification
                    e.preventDefault();
                    this.focusFirstNotification();
                    break;

                case 'End':
                    // Focus last notification
                    e.preventDefault();
                    this.focusLastNotification();
                    break;
                }
            });

            // Make notification focusable for keyboard navigation
            element.setAttribute('tabindex', '0');

            // Enhanced focus and blur handlers for accessibility
            element.addEventListener('focus', () => {
                this.pauseTimer(notification);
                element.setAttribute('aria-expanded', 'true');
                element.setAttribute('data-keyboard-focused', 'true');

                // Announce focus to screen readers
                this.announceNotificationFocus(notification);
            });

            element.addEventListener('blur', () => {
                this.resumeTimer(notification);
                element.setAttribute('aria-expanded', 'false');
                element.removeAttribute('data-keyboard-focused');
            });

            // Pause timer on hover
            element.addEventListener('mouseenter', () => {
                this.pauseTimer(notification);
            });

            element.addEventListener('mouseleave', () => {
                this.resumeTimer(notification);
            });

            // Set up accessibility attributes
            this.setupNotificationAccessibility(element, notification);
        },

        /**
         * Set up accessibility attributes for notification
         * @param {HTMLElement} element - Notification element
         * @param {Object} notification - Notification object
         */
        setupNotificationAccessibility(element, notification) {
            // Set ARIA attributes
            element.setAttribute('aria-label', `${notification.type} notification: ${notification.message}`);
            element.setAttribute('aria-describedby', `notification-content-${notification.id}`);

            // Add ID to content for aria-describedby
            const content = element.querySelector('.notification__content');
            if (content) {
                content.id = `notification-content-${notification.id}`;
            }

            // Set role based on notification type
            const role = notification.type === 'error' ? 'alert' : 'status';
            element.setAttribute('role', role);

            // Set aria-live based on notification type
            const ariaLive = notification.type === 'error' ? 'assertive' : 'polite';
            element.setAttribute('aria-live', ariaLive);

            // Add aria-atomic for complete message reading
            element.setAttribute('aria-atomic', 'true');

            // Set up close button accessibility
            const closeBtn = element.querySelector('.notification__close');
            if (closeBtn) {
                closeBtn.setAttribute('aria-label', `Close ${notification.type} notification`);
                closeBtn.setAttribute('title', 'Close notification (Escape key)');
            }

            // Set up action button accessibility
            const actionBtns = element.querySelectorAll('.notification__action');
            actionBtns.forEach((btn, index) => {
                const action = notification.config.actions[index];
                if (action) {
                    btn.setAttribute('aria-describedby', `notification-content-${notification.id}`);
                    if (action.description) {
                        btn.setAttribute('title', action.description);
                    }
                }
            });
        },

        /**
         * Navigate between notifications using arrow keys
         * @param {string} currentId - Current notification ID
         * @param {string} direction - 'up' or 'down'
         */
        navigateNotifications(currentId, direction) {
            const notificationElements = Array.from(this.container.querySelectorAll('.notification'));
            const currentIndex = notificationElements.findIndex(el =>
                el.getAttribute('data-notification-id') === currentId
            );

            if (currentIndex === -1) {
                return;
            }

            let targetIndex;
            if (direction === 'up') {
                targetIndex = currentIndex > 0 ? currentIndex - 1 : notificationElements.length - 1;
            } else {
                targetIndex = currentIndex < notificationElements.length - 1 ? currentIndex + 1 : 0;
            }

            const targetElement = notificationElements[targetIndex];
            if (targetElement) {
                targetElement.focus();
            }
        },

        /**
         * Focus the first notification
         */
        focusFirstNotification() {
            const firstNotification = this.container.querySelector('.notification');
            if (firstNotification) {
                firstNotification.focus();
            }
        },

        /**
         * Focus the last notification
         */
        focusLastNotification() {
            const notifications = this.container.querySelectorAll('.notification');
            const lastNotification = notifications[notifications.length - 1];
            if (lastNotification) {
                lastNotification.focus();
            }
        },

        /**
         * Handle tab navigation within notification
         * @param {HTMLElement} element - Notification element
         * @param {Event} e - Keyboard event
         */
        handleNotificationTabNavigation(element, e) {
            const focusableElements = element.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );

            if (focusableElements.length === 0) {
                return;
            }

            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];

            if (e.shiftKey) {
                // Shift + Tab - moving backwards
                if (document.activeElement === firstElement) {
                    e.preventDefault();
                    lastElement.focus();
                }
            } else {
                // Tab - moving forwards
                if (document.activeElement === lastElement) {
                    e.preventDefault();
                    firstElement.focus();
                }
            }
        },

        /**
         * Dismiss a specific notification
         * @param {string} id - Notification ID
         * @param {boolean} isAutoDissmiss - Whether this is an auto-dismiss
         */
        dismiss(id, isAutoDissmiss = false) {
            const notification = this.notifications.get(id);
            if (!notification || notification.dismissed) {
                return;
            }

            notification.dismissed = true;

            // Clear timer
            if (notification.timer) {
                clearTimeout(notification.timer);
                notification.timer = null;
            }

            // Animate out with appropriate animation
            if (notification.element) {
                // Clean up click-outside event listener
                if (notification.element._clickOutsideHandler) {
                    document.removeEventListener('click', notification.element._clickOutsideHandler);
                }

                notification.element.classList.remove('show');

                // Use different animations based on dismiss type
                if (isAutoDissmiss) {
                    notification.element.classList.add('fade-out');
                } else {
                    notification.element.classList.add('hide');
                }

                // Determine animation duration based on reduced motion preference
                const animationDuration = this.prefersReducedMotion() ? 0 :
                    (isAutoDissmiss ? 500 : this.config.animationDuration);

                // Remove from DOM after animation
                setTimeout(() => {
                    if (notification.element && notification.element.parentNode) {
                        notification.element.parentNode.removeChild(notification.element);
                    }
                }, animationDuration);
            }

            // Call dismiss callback
            if (notification.config.onDismiss) {
                notification.config.onDismiss(id);
            }

            // Remove from notifications map
            this.notifications.delete(id);
        },

        /**
         * Dismiss all notifications
         */
        dismissAll() {
            const ids = Array.from(this.notifications.keys());
            ids.forEach(id => this.dismiss(id));
        },

        /**
         * Dismiss the topmost (most recent) notification
         */
        dismissTopmost() {
            const notifications = Array.from(this.notifications.values());
            if (notifications.length === 0) {
                return;
            }

            // Find the most recent notification
            const latest = notifications.reduce((latest, current) => {
                return current.timestamp > latest.timestamp ? current : latest;
            });

            this.dismiss(latest.id);
        },

        /**
         * Dismiss the oldest notification
         */
        dismissOldest() {
            const notifications = Array.from(this.notifications.values());
            if (notifications.length === 0) {
                return;
            }

            // Find the oldest notification
            const oldest = notifications.reduce((oldest, current) => {
                return current.timestamp < oldest.timestamp ? current : oldest;
            });

            this.dismiss(oldest.id);
        },

        /**
         * Set auto-dismiss timer for a notification
         * @param {Object} notification - Notification object
         */
        setDismissTimer(notification) {
            if (notification.timer) {
                clearTimeout(notification.timer);
            }

            notification.timer = setTimeout(() => {
                this.dismiss(notification.id, true); // Mark as auto-dismiss
            }, notification.config.duration);
        },

        /**
         * Pause timer for a notification
         * @param {Object} notification - Notification object
         */
        pauseTimer(notification) {
            if (notification.timer && !notification.config.persistent) {
                clearTimeout(notification.timer);
                notification.timer = null;
                notification.pausedAt = Date.now();
            }
        },

        /**
         * Resume timer for a notification
         * @param {Object} notification - Notification object
         */
        resumeTimer(notification) {
            if (notification.pausedAt && !notification.config.persistent && notification.config.duration > 0) {
                const elapsed = Date.now() - notification.pausedAt;
                const remaining = Math.max(0, notification.config.duration - elapsed);

                if (remaining > 0) {
                    notification.timer = setTimeout(() => {
                        this.dismiss(notification.id, true); // Mark as auto-dismiss
                    }, remaining);
                } else {
                    this.dismiss(notification.id, true); // Mark as auto-dismiss
                }

                delete notification.pausedAt;
            }
        },

        /**
         * Pause all notification timers
         */
        pauseAllTimers() {
            this.notifications.forEach(notification => {
                this.pauseTimer(notification);
            });
        },

        /**
         * Resume all notification timers
         */
        resumeAllTimers() {
            this.notifications.forEach(notification => {
                this.resumeTimer(notification);
            });
        },

        /**
         * Generate unique notification ID
         * @returns {string} - Unique ID
         */
        generateId() {
            return `notification-${this.nextId++}-${Date.now()}`;
        },

        /**
         * Announce notification focus to screen readers
         * @param {Object} notification - Notification object
         */
        announceNotificationFocus(notification) {
            const announcement = document.createElement('div');
            announcement.setAttribute('aria-live', 'polite');
            announcement.setAttribute('aria-atomic', 'true');
            announcement.className = 'sr-only';

            const position = Array.from(this.notifications.keys()).indexOf(notification.id) + 1;
            const total = this.notifications.size;

            announcement.textContent = `Focused on ${notification.type} notification ${position} of ${total}. ${notification.message}. Press Escape to dismiss, arrow keys to navigate.`;

            document.body.appendChild(announcement);

            setTimeout(() => {
                if (announcement.parentNode) {
                    announcement.parentNode.removeChild(announcement);
                }
            }, 1000);
        },

        /**
         * Announce notification to screen readers
         * @param {string} message - Message to announce
         * @param {string} type - Notification type
         */
        announceToScreenReader(message, type) {
            // Create announcement element
            const announcement = document.createElement('div');
            const ariaLive = type === 'error' ? 'assertive' : 'polite';

            announcement.setAttribute('aria-live', ariaLive);
            announcement.setAttribute('aria-atomic', 'true');
            announcement.setAttribute('role', type === 'error' ? 'alert' : 'status');
            announcement.className = 'sr-only';

            const typeText = {
                success: 'Success notification',
                error: 'Error alert',
                warning: 'Warning notification',
                info: 'Information notification'
            }[type] || 'Notification';

            // Create comprehensive announcement
            const fullMessage = `${typeText}: ${message}. ${this.getAccessibilityInstructions(type)}`;
            announcement.textContent = fullMessage;

            document.body.appendChild(announcement);

            // Remove after announcement with longer delay for screen readers
            setTimeout(() => {
                if (announcement.parentNode) {
                    announcement.parentNode.removeChild(announcement);
                }
            }, 2000);
        },

        /**
         * Get accessibility instructions for notification type
         * @param {string} type - Notification type
         * @returns {string} - Accessibility instructions
         */
        getAccessibilityInstructions(type) {
            const instructions = {
                success: 'Press Escape to dismiss.',
                error: 'This notification will remain visible until dismissed. Press Escape to close.',
                warning: 'Press Escape to dismiss.',
                info: 'Press Escape to dismiss.'
            };

            return instructions[type] || 'Press Escape to dismiss.';
        },

        /**
         * Manage focus for accessibility
         * @param {HTMLElement} element - Element to focus
         */
        manageFocus(element) {
            // Store current focus
            this.previousFocus = document.activeElement;

            // Focus the element
            if (element && typeof element.focus === 'function') {
                element.focus();
            }
        },

        /**
         * Restore previous focus for accessibility
         */
        restoreFocus() {
            if (this.previousFocus && typeof this.previousFocus.focus === 'function') {
                try {
                    this.previousFocus.focus();
                } catch (e) {
                    // Focus restoration failed, focus body as fallback
                    document.body.focus();
                }
            }
        },

        /**
         * Check if user prefers reduced motion
         * @returns {boolean} - True if reduced motion is preferred
         */
        prefersReducedMotion() {
            return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },

        /**
         * Check if high contrast mode is enabled
         * @returns {boolean} - True if high contrast is enabled
         */
        prefersHighContrast() {
            return window.matchMedia && window.matchMedia('(prefers-contrast: high)').matches;
        },

        /**
         * Update notification accessibility based on user preferences
         */
        updateAccessibilityPreferences() {
            if (this.prefersReducedMotion()) {
                // Disable animations for reduced motion
                this.config.animationDuration = 0;

                // Add class to container for CSS targeting
                if (this.container) {
                    this.container.classList.add('reduced-motion');
                }
            }

            if (this.prefersHighContrast()) {
                // Add class for high contrast styling
                if (this.container) {
                    this.container.classList.add('high-contrast');
                }
            }
        },

        /**
         * Show confirmation dialog
         * @param {string} message - Confirmation message
         * @param {Object} options - Configuration options
         * @returns {Promise} - Resolves with true/false based on user choice
         */
        confirm(message, options = {}) {
            return new Promise((resolve) => {
                this.init(); // Ensure system is initialized

                // Validate message
                if (!message || typeof message !== 'string') {
                    console.warn('QuackNotify: Confirmation message is required and must be a string');
                    resolve(false);
                    return;
                }

                // Merge options with defaults
                const config = {
                    title: options.title || 'Confirm Action',
                    confirmText: options.confirmText || 'Confirm',
                    cancelText: options.cancelText || 'Cancel',
                    confirmStyle: options.confirmStyle || 'danger',
                    cancelStyle: options.cancelStyle || 'secondary',
                    onConfirm: options.onConfirm || null,
                    onCancel: options.onCancel || null,
                    ...options
                };

                // Create confirmation modal
                const modal = this.createConfirmationModal(message, config, resolve);
                document.body.appendChild(modal);

                // Show modal with animation
                requestAnimationFrame(() => {
                    modal.classList.add('show');
                    this.focusModal(modal);
                });
            });
        },

        /**
         * Get confirmation options from element data attributes
         * @param {HTMLElement} element - Element with data-confirm attributes
         * @returns {Object} - Options object for confirm dialog
         */
        getConfirmOptions(element) {
            const options = {};
            if (!element || !element.dataset) {
                return options;
            }

            if (element.dataset.confirmTitle) {
                options.title = element.dataset.confirmTitle;
            }
            if (element.dataset.confirmConfirmText) {
                options.confirmText = element.dataset.confirmConfirmText;
            }
            if (element.dataset.confirmCancelText) {
                options.cancelText = element.dataset.confirmCancelText;
            }
            if (element.dataset.confirmStyle) {
                options.confirmStyle = element.dataset.confirmStyle;
            }
            if (element.dataset.confirmCancelStyle) {
                options.cancelStyle = element.dataset.confirmCancelStyle;
            }

            return options;
        },

        /**
         * Reset form loading/validation state after a cancelled confirmation.
         * @param {HTMLFormElement} form - form to reset
         */
        resetFormLoading(form) {
            if (!form) {
                return;
            }

            form.classList.remove('form-loading');

            const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            submitButtons.forEach((button) => {
                if (window.ButtonLoadingManager && button.classList.contains('btn-loading')) {
                    window.ButtonLoadingManager.removeLoading(button);
                    return;
                }

                if (button.dataset.originalDisabled !== undefined) {
                    button.disabled = button.dataset.originalDisabled === 'true';
                    delete button.dataset.originalDisabled;
                } else if (button.disabled && button.classList.contains('btn-loading')) {
                    button.disabled = false;
                    button.classList.remove('btn-loading', 'loading-dots');
                }
            });

            if (form.validator && typeof form.validator === 'object') {
                form.validator.isSubmitting = false;
            }
        },

        /**
         * Bind handlers for elements using data-confirm attributes
         */
        bindConfirmHandlers() {
            if (this.confirmHandlersBound) {
                return;
            }

            this.confirmHandlersBound = true;

            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (!form || !form.getAttribute) {
                    return;
                }

                const message = form.getAttribute('data-confirm');
                if (!message) {
                    return;
                }

                event.preventDefault();
                const options = this.getConfirmOptions(form);

                this.confirm(message, options).then((confirmed) => {
                    if (confirmed) {
                        form.submit();
                        return;
                    }

                    this.resetFormLoading(form);
                });
            });

            document.addEventListener('click', (event) => {
                const target = event.target.closest('[data-confirm]');
                if (!target || target.tagName === 'FORM') {
                    return;
                }

                const form = target.closest('form');
                const isSubmitControl = form && (target.type === 'submit' || target.tagName === 'BUTTON' || target.tagName === 'INPUT');
                if (isSubmitControl) {
                    return;
                }

                const message = target.getAttribute('data-confirm');
                if (!message) {
                    return;
                }

                event.preventDefault();
                const options = this.getConfirmOptions(target);

                this.confirm(message, options).then((confirmed) => {
                    if (!confirmed) {
                        return;
                    }

                    if (target.tagName === 'A' && target.href) {
                        window.location.href = target.href;
                        return;
                    }

                    if (form) {
                        form.submit();
                    }
                });
            });
        },

        /**
         * Create confirmation modal element
         * @param {string} message - Confirmation message
         * @param {Object} config - Configuration options
         * @param {Function} resolve - Promise resolve function
         * @returns {HTMLElement} - Modal element
         */
        createConfirmationModal(message, config, resolve) {
            const modal = document.createElement('div');
            modal.className = 'confirmation-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('aria-labelledby', 'confirmation-title');
            modal.setAttribute('aria-describedby', 'confirmation-message');

            modal.innerHTML = `
                <div class="confirmation-modal__backdrop"></div>
                <div class="confirmation-modal__content">
                    <div class="confirmation-modal__header">
                        <h2 class="confirmation-modal__title" id="confirmation-title">${this.escapeHtml(config.title)}</h2>
                    </div>
                    <div class="confirmation-modal__body">
                        <p class="confirmation-modal__message" id="confirmation-message">${this.escapeHtml(message)}</p>
                    </div>
                    <div class="confirmation-modal__actions">
                        <button class="btn btn-${config.confirmStyle} confirmation-modal__confirm" type="button">
                            ${this.escapeHtml(config.confirmText)}
                        </button>
                        <button class="btn btn-${config.cancelStyle} confirmation-modal__cancel" type="button">
                            ${this.escapeHtml(config.cancelText)}
                        </button>
                    </div>
                </div>
            `;

            // Set up event listeners
            this.setupConfirmationEvents(modal, config, resolve);

            return modal;
        },

        /**
         * Set up event listeners for confirmation modal
         * @param {HTMLElement} modal - Modal element
         * @param {Object} config - Configuration options
         * @param {Function} resolve - Promise resolve function
         */
        setupConfirmationEvents(modal, config, resolve) {
            const confirmBtn = modal.querySelector('.confirmation-modal__confirm');
            const cancelBtn = modal.querySelector('.confirmation-modal__cancel');
            const backdrop = modal.querySelector('.confirmation-modal__backdrop');

            // Confirm button click
            confirmBtn.addEventListener('click', () => {
                if (config.onConfirm) {
                    config.onConfirm();
                }
                this.closeConfirmationModal(modal, true, resolve);
            });

            // Cancel button click
            cancelBtn.addEventListener('click', () => {
                if (config.onCancel) {
                    config.onCancel();
                }
                this.closeConfirmationModal(modal, false, resolve);
            });

            // Backdrop click to cancel
            backdrop.addEventListener('click', () => {
                if (config.onCancel) {
                    config.onCancel();
                }
                this.closeConfirmationModal(modal, false, resolve);
            });

            // Keyboard navigation
            modal.addEventListener('keydown', (e) => {
                switch (e.key) {
                case 'Escape':
                    e.preventDefault();
                    if (config.onCancel) {
                        config.onCancel();
                    }
                    this.closeConfirmationModal(modal, false, resolve);
                    break;

                case 'Enter':
                    // If focus is on cancel button, treat as cancel
                    if (document.activeElement === cancelBtn) {
                        e.preventDefault();
                        if (config.onCancel) {
                            config.onCancel();
                        }
                        this.closeConfirmationModal(modal, false, resolve);
                    } else {
                        // Otherwise treat as confirm
                        e.preventDefault();
                        if (config.onConfirm) {
                            config.onConfirm();
                        }
                        this.closeConfirmationModal(modal, true, resolve);
                    }
                    break;

                case 'Tab':
                    // Trap focus within modal
                    this.trapFocus(modal, e);
                    break;
                }
            });
        },

        /**
         * Close confirmation modal with animation
         * @param {HTMLElement} modal - Modal element
         * @param {boolean} confirmed - Whether user confirmed
         * @param {Function} resolve - Promise resolve function
         */
        closeConfirmationModal(modal, confirmed, resolve) {
            // Announce result to screen readers
            this.announceModalResult(confirmed);

            modal.classList.remove('show');

            // Restore focus to previously focused element
            if (modal._previousFocus && typeof modal._previousFocus.focus === 'function') {
                try {
                    modal._previousFocus.focus();
                } catch (e) {
                    // Focus restoration failed, focus body as fallback
                    document.body.focus();
                }
            }

            setTimeout(() => {
                if (modal.parentNode) {
                    modal.parentNode.removeChild(modal);
                }
                resolve(confirmed);
            }, this.config.animationDuration);
        },

        /**
         * Announce modal result to screen readers
         * @param {boolean} confirmed - Whether user confirmed
         */
        announceModalResult(confirmed) {
            const announcement = document.createElement('div');
            announcement.setAttribute('aria-live', 'assertive');
            announcement.setAttribute('aria-atomic', 'true');
            announcement.className = 'sr-only';

            announcement.textContent = confirmed ? 'Action confirmed.' : 'Action cancelled.';

            document.body.appendChild(announcement);

            setTimeout(() => {
                if (announcement.parentNode) {
                    announcement.parentNode.removeChild(announcement);
                }
            }, 1000);
        },

        /**
         * Focus management for modal
         * @param {HTMLElement} modal - Modal element
         */
        focusModal(modal) {
            // Store the previously focused element for restoration
            modal._previousFocus = document.activeElement;

            // Announce modal to screen readers
            this.announceModalToScreenReader(modal);

            // Focus the modal content first for screen readers
            const modalContent = modal.querySelector('.confirmation-modal__content');
            if (modalContent) {
                modalContent.setAttribute('tabindex', '-1');
                modalContent.focus();
            }

            // Then focus the first interactive element
            setTimeout(() => {
                const focusableElements = modal.querySelectorAll(
                    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
                );

                if (focusableElements.length > 0) {
                    // Focus the confirm button by default for better UX
                    const confirmBtn = modal.querySelector('.confirmation-modal__confirm');
                    if (confirmBtn) {
                        confirmBtn.focus();
                    } else {
                        focusableElements[0].focus();
                    }
                }
            }, 100);
        },

        /**
         * Announce modal opening to screen readers
         * @param {HTMLElement} modal - Modal element
         */
        announceModalToScreenReader(modal) {
            const title = modal.querySelector('.confirmation-modal__title');
            const message = modal.querySelector('.confirmation-modal__message');

            if (title && message) {
                const announcement = document.createElement('div');
                announcement.setAttribute('aria-live', 'assertive');
                announcement.setAttribute('aria-atomic', 'true');
                announcement.className = 'sr-only';

                announcement.textContent = `Dialog opened: ${title.textContent}. ${message.textContent}. Use Tab to navigate, Enter to confirm, or Escape to cancel.`;

                document.body.appendChild(announcement);

                setTimeout(() => {
                    if (announcement.parentNode) {
                        announcement.parentNode.removeChild(announcement);
                    }
                }, 2000);
            }
        },

        /**
         * Trap focus within modal for accessibility
         * @param {HTMLElement} modal - Modal element
         * @param {Event} e - Keyboard event
         */
        trapFocus(modal, e) {
            const focusableElements = modal.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );

            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];

            if (e.shiftKey) {
                // Shift + Tab
                if (document.activeElement === firstElement) {
                    e.preventDefault();
                    lastElement.focus();
                }
            } else {
                // Tab
                if (document.activeElement === lastElement) {
                    e.preventDefault();
                    firstElement.focus();
                }
            }
        },

        /**
         * Get SVG icon for notification type
         * @param {string} type - Notification type
         * @returns {string} - SVG HTML
         */
        getIconSVG(type) {
            const icons = {
                success: `<svg viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>`,
                error: `<svg viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>`,
                warning: `<svg viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>`,
                info: `<svg viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                </svg>`
            };
            return icons[type] || icons.info;
        },

        /**
         * Get close button SVG
         * @returns {string} - SVG HTML
         */
        getCloseSVG() {
            return `<svg viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>`;
        },

        /**
         * Escape HTML to prevent XSS
         * @param {string} text - Text to escape
         * @returns {string} - Escaped text
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Convenience methods for different notification types
    QuackNotify.success = function(message, options = {}) {
        return this.show(message, 'success', options);
    };

    QuackNotify.error = function(message, options = {}) {
        return this.show(message, 'error', { persistent: true, ...options });
    };

    QuackNotify.warning = function(message, options = {}) {
        return this.show(message, 'warning', options);
    };

    QuackNotify.info = function(message, options = {}) {
        return this.show(message, 'info', options);
    };

    // Expose QuackNotify to global scope
    window.QuackNotify = QuackNotify;

    // Auto-initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            QuackNotify.init();
            convertFlashesToToasts();
        });
    } else {
        QuackNotify.init();
        // Wait a tick for DOM to settle if called synchronously
        setTimeout(convertFlashesToToasts, 0);
    }

    /**
     * Flash-to-toast converter for redesigned pages.
     * On pages using the new masthead layout (.stock-page, .products-masthead,
     * .dash-page), inline .flash divs would push content down.
     * This converts them to QuackNotify toasts and removes them from the DOM.
     */
    function convertFlashesToToasts() {
        // Only run on redesigned pages that use the new layout
        const isRedesignedPage = document.querySelector(
            '.stock-page, .products-masthead, .dash-page'
        );
        if (!isRedesignedPage) {
            return;
        }

        const flashEls = document.querySelectorAll('.flash');
        flashEls.forEach((el) => {
            const msg = el.textContent.trim();
            if (!msg) {
                el.remove(); return;
            }

            // Map CSS class to QuackNotify type
            let type = 'info';
            if (el.classList.contains('error')) {
                type = 'error';
            } else if (el.classList.contains('success')) {
                type = 'success';
            } else if (el.classList.contains('warning')) {
                type = 'warning';
            }

            // Show as toast (errors persist, others auto-dismiss in 6s)
            QuackNotify.show(msg, type, {
                persistent: type === 'error',
                duration: 6000
            });

            // Remove from DOM so it doesn't affect layout
            el.remove();
        });

        // Also remove any empty flash wrapper divs that were left behind
        document.querySelectorAll('[style*="padding: 0 40px"]').forEach((wrapper) => {
            if (wrapper.children.length === 0 && wrapper.textContent.trim() === '') {
                wrapper.remove();
            }
        });
    }

    // Expose converter so page scripts can call it too if needed
    window.convertFlashesToToasts = convertFlashesToToasts;

})(window);
