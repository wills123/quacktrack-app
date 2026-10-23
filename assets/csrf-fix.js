/**
 * CSRF Token Protection Enhancement
 *
 * Purpose: Ensures CSRF tokens are properly included in all form submissions
 * to prevent Cross-Site Request Forgery attacks.
 *
 * Features:
 * - Validates CSRF tokens before form submission
 * - Adds missing CSRF tokens from session if available
 * - Provides user-friendly error messages
 * - Logs debugging information to console
 * - Monitors dynamically added forms
 *
 * @version 1.0.0
 */

document.addEventListener('DOMContentLoaded', () => {
    // Function to check if a form has a CSRF token
    function hasCSRFToken(form) {
        const csrfField = form.querySelector('input[name="csrf"]');
        return csrfField && csrfField.value && csrfField.value.length > 0;
    }

    // Function to add CSRF token to form if missing
    function ensureCSRFToken(form) {
        if (!hasCSRFToken(form)) {
            // Try to get CSRF token from any existing form on the page
            const existingCSRFField = document.querySelector('input[name="csrf"]');
            if (existingCSRFField && existingCSRFField.value) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf';
                csrfInput.value = existingCSRFField.value;
                form.appendChild(csrfInput);
            } else {
                console.error('No CSRF token found on page for form:', form.action);
            }
        }
    }

    // Fix for forms that might lose their CSRF token
    document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(form => {
        // Skip forms that explicitly don't need CSRF (like search forms)
        if (form.classList.contains('no-csrf')) {
            return;
        }

        // Skip forms on pages that typically don't use CSRF
        const currentPath = window.location.pathname;
        const skipPaths = [
            '/migrations/',
            '/install/',
            '/setup/',
            '/login.php',
            '/register.php',
            '/forgot-password.php'
        ];

        if (skipPaths.some(path => currentPath.includes(path))) {
            return;
        }

        // Add a submit handler to ensure CSRF token is present
        form.addEventListener('submit', (e) => {
            // Don't interfere with other validation, just ensure CSRF is present
            if (!hasCSRFToken(form)) {
                console.error('Form submission blocked: No CSRF token', form.action);

                // Try to add it
                ensureCSRFToken(form);

                // If still no token, prevent submission
                if (!hasCSRFToken(form)) {
                    e.preventDefault();
                    if (window.QuackNotify) {
                        QuackNotify.error('Security error: Unable to submit form. Please refresh the page and try again.');
                    } else {
                        alert('Security error: Unable to submit form. Please refresh the page and try again.');
                    }
                    return false;
                }
            }
        });

        // Ensure CSRF token is present on page load
        ensureCSRFToken(form);
    });

    // Monitor for dynamically added forms
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.tagName === 'FORM' && (node.method === 'post' || node.method === 'POST')) {
                    ensureCSRFToken(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
});