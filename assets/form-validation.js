/**
 * Enhanced Form Validation and User Feedback System
 * Provides real-time validation, accessibility support, and user-friendly feedback
 * Optimized for performance and memory management
 */

// Performance utilities for form validation
const formValidationUtils = {
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
    },

    batchDOMUpdates: function(callback) {
        requestAnimationFrame(callback);
    }
};

// Global cleanup registry
const formValidationCleanup = {
    timers: new Set(),
    observers: new Set(),
    eventListeners: new Map(),

    addTimer: function(timer) {
        this.timers.add(timer);
    },

    addObserver: function(observer) {
        this.observers.add(observer);
    },

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
        // Clear timers
        this.timers.forEach(timer => clearTimeout(timer));
        this.timers.clear();

        // Disconnect observers
        this.observers.forEach(observer => observer.disconnect());
        this.observers.clear();

        // Remove event listeners
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
    // Form validation configuration
    const validationConfig = {
        email: {
            pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
            message: 'Please enter a valid email address'
        },
        product_id: {
            pattern: /^[a-zA-Z0-9:_-]{1,50}$/,
            message: 'Product ID can only contain letters, numbers, dashes, underscores, and colons (max 50 characters)'
        },
        password: {
            minLength: 8,
            message: 'Password must be at least 8 characters long'
        },
        required: {
            message: 'This field is required'
        },
        number: {
            pattern: /^\d+$/,
            message: 'Please enter a valid number'
        },
        positiveNumber: {
            pattern: /^[1-9]\d*$/,
            message: 'Please enter a positive number'
        }
    };

    // Enhanced form validator class
    class FormValidator {
        constructor(form) {
            this.form = form;
            this.errors = new Map();
            this.isSubmitting = false;
            this.init();
        }

        init() {
            // Add real-time validation to form fields
            this.form.querySelectorAll('input, select, textarea').forEach(field => {
                this.setupFieldValidation(field);
            });

            // Handle form submission
            this.form.addEventListener('submit', (e) => this.handleSubmit(e));

            // Add form-level ARIA attributes
            this.form.setAttribute('novalidate', 'true'); // Disable browser validation
            this.form.setAttribute('aria-live', 'polite');
        }

        setupFieldValidation(field) {
            // Add ARIA attributes
            field.setAttribute('aria-invalid', 'false');

            // Debounced validation handlers for better performance
            const debouncedValidation = formValidationUtils.debounce(() => {
                this.validateField(field);
            }, 300);

            const debouncedClearError = formValidationUtils.debounce(() => {
                if (this.errors.has(field.name)) {
                    this.clearFieldError(field);
                }
            }, 150);

            // Real-time validation on blur
            const blurHandler = () => {
                this.validateField(field);
            };
            formValidationCleanup.addEventListener(field, 'blur', blurHandler);

            // Clear errors on input (but don't validate until blur)
            const inputHandler = () => {
                debouncedClearError();

                // Add visual feedback for typing
                this.addTypingFeedback(field);
            };
            formValidationCleanup.addEventListener(field, 'input', inputHandler);

            // Special handling for password confirmation with debouncing
            if (field.name === 'confirm' || field.name === 'password_confirm') {
                const passwordField = this.form.querySelector('input[name="password"]');
                if (passwordField) {
                    const passwordInputHandler = () => {
                        if (field.value) {
                            debouncedValidation();
                        }
                    };
                    formValidationCleanup.addEventListener(passwordField, 'input', passwordInputHandler);
                }
            }
        }

        validateField(field) {
            // Skip validation for hidden fields (including CSRF tokens)
            if (field.type === 'hidden') {
                return true;
            }

            const value = field.value.trim();
            const fieldName = field.name;
            const fieldType = field.type;
            const isRequired = field.hasAttribute('required');

            // Clear previous errors
            this.clearFieldError(field);

            // Required field validation
            if (isRequired && !value) {
                this.showFieldError(field, validationConfig.required.message);
                return false;
            }

            // Skip further validation if field is empty and not required
            if (!value && !isRequired) {
                return true;
            }

            // Email validation
            if (fieldType === 'email' || fieldName === 'email') {
                if (!validationConfig.email.pattern.test(value)) {
                    this.showFieldError(field, validationConfig.email.message);
                    return false;
                }
            }

            // Password validation
            if (fieldName === 'password') {
                if (value.length < validationConfig.password.minLength) {
                    this.showFieldError(field, validationConfig.password.message);
                    return false;
                }
            }

            // Password confirmation validation
            if (fieldName === 'confirm' || fieldName === 'password_confirm') {
                const passwordField = this.form.querySelector('input[name="password"]');
                if (passwordField && value !== passwordField.value) {
                    this.showFieldError(field, 'Passwords do not match');
                    return false;
                }
            }

            // Product ID validation
            if (fieldName === 'product_id') {
                if (!validationConfig.product_id.pattern.test(value)) {
                    this.showFieldError(field, validationConfig.product_id.message);
                    return false;
                }
            }

            // Number validation
            if (fieldType === 'number' || field.classList.contains('number-input')) {
                const numValue = parseInt(value);
                if (isNaN(numValue)) {
                    this.showFieldError(field, validationConfig.number.message);
                    return false;
                }

                // Positive number validation
                if (field.classList.contains('positive-number') && numValue <= 0) {
                    this.showFieldError(field, validationConfig.positiveNumber.message);
                    return false;
                }

                // Min/max validation
                const min = field.getAttribute('min');
                const max = field.getAttribute('max');

                if (min && numValue < parseInt(min)) {
                    this.showFieldError(field, `Value must be at least ${min}`);
                    return false;
                }

                if (max && numValue > parseInt(max)) {
                    this.showFieldError(field, `Value must be no more than ${max}`);
                    return false;
                }
            }

            // Custom validation patterns
            const pattern = field.getAttribute('data-pattern');
            if (pattern) {
                const regex = new RegExp(pattern);
                if (!regex.test(value)) {
                    const message = field.getAttribute('data-pattern-message') || 'Invalid format';
                    this.showFieldError(field, message);
                    return false;
                }
            }

            // Field is valid
            this.showFieldSuccess(field);
            return true;
        }

        showFieldError(field, message) {
            const formGroup = field.closest('.form-group') || field.parentElement;

            // Add error class
            formGroup.classList.add('error');
            formGroup.classList.remove('success');

            // Create or update error message
            let errorElement = formGroup.querySelector('.error-message');
            if (!errorElement) {
                errorElement = document.createElement('div');
                errorElement.className = 'error-message';
                errorElement.setAttribute('role', 'alert');
                errorElement.setAttribute('aria-live', 'assertive');
                errorElement.id = field.name + '-error';

                // Insert after field or help text
                const helpText = formGroup.querySelector('.form-help');
                if (helpText) {
                    helpText.parentNode.insertBefore(errorElement, helpText.nextSibling);
                } else {
                    formGroup.appendChild(errorElement);
                }
            }

            errorElement.textContent = message;

            // Update field ARIA attributes
            field.setAttribute('aria-invalid', 'true');
            const describedBy = field.getAttribute('aria-describedby') || '';
            if (!describedBy.includes(errorElement.id)) {
                field.setAttribute('aria-describedby', describedBy ? `${describedBy} ${errorElement.id}` : errorElement.id);
            }

            // Store error
            this.errors.set(field.name, message);

            // Announce error to screen readers
            this.announceToScreenReader(`Error in ${this.getFieldLabel(field)}: ${message}`, 'assertive');
        }

        showFieldSuccess(field) {
            const formGroup = field.closest('.form-group') || field.parentElement;

            // Add success class
            formGroup.classList.remove('success'); // avoid green/success styling
            formGroup.classList.remove('error');

            // Update field ARIA attributes
            field.setAttribute('aria-invalid', 'false');

            // Remove error from storage
            this.errors.delete(field.name);
        }

        clearFieldError(field) {
            const formGroup = field.closest('.form-group') || field.parentElement;

            // Remove error classes
            formGroup.classList.remove('error');

            // Remove error message
            const errorElement = formGroup.querySelector('.error-message');
            if (errorElement) {
                // Clean up aria-describedby
                const describedBy = field.getAttribute('aria-describedby') || '';
                const newDescribedBy = describedBy
                    .split(' ')
                    .filter(id => id !== errorElement.id)
                    .join(' ')
                    .trim();

                if (newDescribedBy) {
                    field.setAttribute('aria-describedby', newDescribedBy);
                } else {
                    field.removeAttribute('aria-describedby');
                }

                errorElement.remove();
            }

            // Update field ARIA attributes
            field.setAttribute('aria-invalid', 'false');

            // Remove error from storage
            this.errors.delete(field.name);
        }

        addTypingFeedback(field) {
            // Add visual feedback while typing with optimized timer management
            field.classList.add('typing');

            if (field.typingTimer) {
                clearTimeout(field.typingTimer);
            }

            field.typingTimer = setTimeout(() => {
                field.classList.remove('typing');
                field.typingTimer = null;
            }, 500);

            // Track timer for cleanup
            formValidationCleanup.addTimer(field.typingTimer);
        }

        handleSubmit(e) {
            if (this.isSubmitting) {
                e.preventDefault();
                return false;
            }

            // Validate all fields
            let isValid = true;
            const fields = this.form.querySelectorAll('input, select, textarea');

            fields.forEach(field => {
                if (!this.validateField(field)) {
                    isValid = false;
                }
            });

            if (!isValid) {
                e.preventDefault();
                this.handleValidationErrors();
                return false;
            }

            // Form is valid, show loading state
            this.isSubmitting = true;
            this.showLoadingState();

            // IMPORTANT: Allow the form to submit normally
            // The form submission will proceed after this function returns
            return true;
        }

        handleValidationErrors() {
            const errorCount = this.errors.size;
            const firstErrorField = this.form.querySelector('.form-group.error input, .form-group.error select, .form-group.error textarea');

            // Focus first error field
            if (firstErrorField) {
                firstErrorField.focus();
                firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            // Create error summary
            this.showErrorSummary();

            // Announce errors
            const errorMessages = Array.from(this.errors.values());
            const announcement = `Form has ${errorCount} error${errorCount > 1 ? 's' : ''}. ${errorMessages.join('. ')}`;
            this.announceToScreenReader(announcement, 'assertive');
        }

        showErrorSummary() {
            // Remove existing summary
            const existingSummary = this.form.querySelector('.error-summary');
            if (existingSummary) {
                existingSummary.remove();
            }

            if (this.errors.size === 0) {
                return;
            }

            // Create error summary
            const summary = document.createElement('div');
            summary.className = 'error-summary';
            summary.setAttribute('role', 'alert');
            summary.setAttribute('aria-live', 'assertive');
            summary.setAttribute('tabindex', '-1');

            const heading = document.createElement('h3');
            heading.textContent = `Please fix the following ${this.errors.size} error${this.errors.size > 1 ? 's' : ''}:`;
            summary.appendChild(heading);

            const list = document.createElement('ul');
            this.errors.forEach((message, fieldName) => {
                const field = this.form.querySelector(`[name="${fieldName}"]`);
                const label = this.getFieldLabel(field);

                const listItem = document.createElement('li');
                const link = document.createElement('a');
                link.href = `#${field.id || fieldName}`;
                link.textContent = `${label}: ${message}`;
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    field.focus();
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });

                listItem.appendChild(link);
                list.appendChild(listItem);
            });

            summary.appendChild(list);

            // Insert at top of form
            this.form.insertBefore(summary, this.form.firstChild);

            // Focus the summary
            summary.focus();
            summary.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        showLoadingState() {
            const submitButton = this.form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitButton && window.ButtonLoadingManager) {
                window.ButtonLoadingManager.setLoading(submitButton, 'Submitting...');
            }

            // Add loading class to form
            this.form.classList.add('form-loading');

            // Don't disable fields immediately - this prevents their values from being submitted!
            // The form will navigate away anyway once submitted
            // Only disable the submit button to prevent double submission
            if (submitButton) {
                submitButton.disabled = true;
            }
        }

        getFieldLabel(field) {
            if (!field) {
                return 'Field';
            }

            // Try to find associated label
            const label = this.form.querySelector(`label[for="${field.id}"]`) ||
                         field.closest('label') ||
                         field.closest('.form-group')?.querySelector('label');

            if (label) {
                return label.textContent.replace('*', '').trim();
            }

            // Fallback to field name or placeholder
            return field.getAttribute('placeholder') ||
                   field.name.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) ||
                   'Field';
        }

        announceToScreenReader(message, priority = 'polite') {
            if (window.announceToScreenReader) {
                window.announceToScreenReader(message, priority);
            } else {
                // Fallback implementation
                const announcement = document.createElement('div');
                announcement.setAttribute('aria-live', priority);
                announcement.setAttribute('aria-atomic', 'true');
                announcement.className = 'sr-only';
                announcement.textContent = message;
                document.body.appendChild(announcement);

                setTimeout(() => {
                    document.body.removeChild(announcement);
                }, 1000);
            }
        }
    }

    // Initialize form validation for all forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        // Skip forms that already have validation or are marked to skip
        if (form.classList.contains('no-validation') || form.hasAttribute('data-validator')) {
            return;
        }

        const validator = new FormValidator(form);
        form.setAttribute('data-validator', 'true');

        // Store validator instance for external access
        form.validator = validator;
    });

    // Enhanced success/error message display using QuackNotify
    function showFormMessage(message, type = 'info', duration = 5000) {
        // Use QuackNotify if available, otherwise fallback to original implementation
        if (window.QuackNotify) {
            const options = {};

            // Set duration if specified (0 means no auto-dismiss)
            if (duration !== 5000) {
                options.duration = duration;
            }

            // Map type to QuackNotify methods
            switch (type) {
            case 'success':
                window.QuackNotify.success(message, options);
                break;
            case 'error':
                window.QuackNotify.error(message, options);
                break;
            case 'warning':
                window.QuackNotify.warning(message, options);
                break;
            case 'info':
            default:
                window.QuackNotify.info(message, options);
                break;
            }
            return;
        }

        // Fallback implementation for backward compatibility
        // Remove existing messages
        const existingMessages = document.querySelectorAll('.form-message');
        existingMessages.forEach(msg => msg.remove());

        // Create message element
        const messageDiv = document.createElement('div');
        messageDiv.className = `form-message form-message-${type}`;
        messageDiv.setAttribute('role', 'alert');
        messageDiv.setAttribute('aria-live', 'assertive');

        const icon = type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ';
        messageDiv.innerHTML = `
            <div class="form-message-content">
                <span class="form-message-icon" aria-hidden="true">${icon}</span>
                <span class="form-message-text">${message}</span>
                <button class="form-message-close" aria-label="Close message" onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;

        // Add styles
        messageDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            max-width: 400px;
            padding: 15px;
            border-radius: 4px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 10000;
            font-size: 14px;
            line-height: 1.4;
            background: ${type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1'};
            color: ${type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#0c5460'};
            border: 1px solid ${type === 'success' ? '#c3e6cb' : type === 'error' ? '#f5c6cb' : '#bee5eb'};
        `;

        document.body.appendChild(messageDiv);

        // Auto-remove after duration
        if (duration > 0) {
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.remove();
                }
            }, duration);
        }

        // Announce to screen readers
        if (window.announceToScreenReader) {
            window.announceToScreenReader(`${type}: ${message}`, 'assertive');
        }
    }

    // Make showFormMessage globally available
    window.showFormMessage = showFormMessage;

    // Enhanced flash message handling with auto-dismiss
    function enhanceFlashMessages() {
        const flashMessages = document.querySelectorAll('.flash');

        flashMessages.forEach(flash => {
            // Add close button if not present
            if (!flash.querySelector('.flash-close')) {
                const closeButton = document.createElement('button');
                closeButton.className = 'flash-close';
                closeButton.innerHTML = '×';
                closeButton.setAttribute('aria-label', 'Close message');
                closeButton.addEventListener('click', () => {
                    flash.style.animation = 'slideOutRight 0.3s ease-in';
                    setTimeout(() => flash.remove(), 300);
                });
                flash.appendChild(closeButton);
            }

            // Add ARIA attributes
            flash.setAttribute('role', 'alert');
            flash.setAttribute('aria-live', 'assertive');

            // Auto-dismiss after 5 seconds for success messages
            if (flash.classList.contains('success')) {
                setTimeout(() => {
                    if (flash.parentNode) {
                        flash.style.animation = 'slideOutRight 0.3s ease-in';
                        setTimeout(() => flash.remove(), 300);
                    }
                }, 5000);
            }

            // Add slide-in animation
            flash.style.animation = 'slideInRight 0.3s ease-out';
        });
    }

    // Enhance flash messages on page load
    enhanceFlashMessages();

    // Also enhance any dynamically added flash messages with throttling
    const flashObserver = new MutationObserver(formValidationUtils.throttle((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === 1 && node.classList && node.classList.contains('flash')) {
                    enhanceFlashMessages();
                }
            });
        });
    }, 100));

    flashObserver.observe(document.body, { childList: true, subtree: true });
    formValidationCleanup.addObserver(flashObserver);

    // Global cleanup on page unload
    formValidationCleanup.addEventListener(window, 'beforeunload', () => {
        formValidationCleanup.cleanup();
    });

    formValidationCleanup.addEventListener(window, 'unload', () => {
        formValidationCleanup.cleanup();
    });

    // Enhanced file input validation and preview
    document.querySelectorAll('input[type="file"]').forEach(fileInput => {
        fileInput.addEventListener('change', function() {
            const files = this.files;
            const formGroup = this.closest('.form-group');

            // Clear previous errors
            const errorElement = formGroup?.querySelector('.error-message');
            if (errorElement) {
                errorElement.remove();
            }
            formGroup?.classList.remove('error');

            if (files.length === 0) {
                return;
            }

            const file = files[0];
            const maxSize = parseInt(this.getAttribute('data-max-size')) || 10485760; // 10MB default
            const allowedTypes = this.getAttribute('accept')?.split(',').map(t => t.trim()) || [];

            // Validate file size
            if (file.size > maxSize) {
                this.showFileError(`File is too large. Maximum size is ${(maxSize / 1024 / 1024).toFixed(1)}MB`);
                return;
            }

            // Validate file type
            if (allowedTypes.length > 0 && !allowedTypes.some(type => file.type.match(type))) {
                this.showFileError(`Invalid file type. Allowed types: ${allowedTypes.join(', ')}`);
                return;
            }

            // Show file preview for images
            if (file.type.startsWith('image/')) {
                this.showImagePreview(file);
            }

            // Show success state
            formGroup?.classList.remove('success');
        });

        // Add file error display method
        fileInput.showFileError = function(message) {
            const formGroup = this.closest('.form-group');
            if (!formGroup) {
                return;
            }

            formGroup.classList.add('error');

            const errorElement = document.createElement('div');
            errorElement.className = 'error-message';
            errorElement.setAttribute('role', 'alert');
            errorElement.textContent = message;

            formGroup.appendChild(errorElement);

            // Clear the file input
            this.value = '';
        };

        // Add image preview method
        fileInput.showImagePreview = function(file) {
            const formGroup = this.closest('.form-group');
            if (!formGroup) {
                return;
            }

            // Remove existing preview
            const existingPreview = formGroup.querySelector('.image-preview');
            if (existingPreview) {
                existingPreview.remove();
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.createElement('div');
                preview.className = 'image-preview';
                preview.innerHTML = `
                    <img src="${e.target.result}" alt="Image preview" style="max-width: 200px; max-height: 200px; border-radius: 4px; margin-top: 10px;">
                    <button type="button" class="remove-preview" onclick="this.parentElement.remove()" style="margin-left: 10px;">Remove</button>
                `;
                formGroup.appendChild(preview);
            };
            reader.readAsDataURL(file);
        };
    });
});
