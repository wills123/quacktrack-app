/**
 * Quack Track - Login Page Client-Side Enhancement
 * Provides real-time validation and better UX
 */

(function() {
    'use strict';

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLoginPage);
    } else {
        initLoginPage();
    }

    function initLoginPage() {
        const form = document.querySelector('.login-form');
        if (!form) {
            return;
        }

        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const submitButton = form.querySelector('.btn-submit');

        // Initialize real-time validation
        if (emailInput) {
            emailInput.addEventListener('blur', () => validateEmail(emailInput));
            emailInput.addEventListener('input', () => clearFieldError(emailInput));
        }

        if (passwordInput) {
            passwordInput.addEventListener('input', () => clearFieldError(passwordInput));
        }

        // Form submission validation
        form.addEventListener('submit', (e) => {
            if (!validateForm(emailInput, passwordInput)) {
                e.preventDefault();
                return false;
            }

            // Show loading state
            showLoadingState(submitButton);
        });
    }

    /**
   * Validate email format
   */
    function validateEmail(emailInput) {
        const email = emailInput.value.trim();

        if (!email) {
            showFieldError(emailInput, 'Email is required');
            return false;
        }

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            showFieldError(emailInput, 'Please enter a valid email address');
            return false;
        }

        markFieldValid(emailInput);
        return true;
    }

    /**
   * Validate entire form
   */
    function validateForm(emailInput, passwordInput) {
        let isValid = true;

        // Validate email
        if (emailInput && !validateEmail(emailInput)) {
            isValid = false;
        }

        // Validate password
        if (passwordInput) {
            const password = passwordInput.value;
            if (!password) {
                showFieldError(passwordInput, 'Password is required');
                isValid = false;
            }
        }

        return isValid;
    }

    /**
   * Show error message for a field
   */
    function showFieldError(input, message) {
        if (!input) {
            return;
        }

        input.classList.remove('valid');
        input.classList.add('invalid');

        // Remove existing error message
        const existingError = input.parentElement.querySelector('.form-error');
        if (existingError) {
            existingError.remove();
        }

        // Add new error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'form-error';
        errorDiv.textContent = message;
        errorDiv.setAttribute('role', 'alert');

        const helpText = input.parentElement.querySelector('.form-help');
        if (helpText) {
            helpText.insertAdjacentElement('afterend', errorDiv);
        } else {
            input.insertAdjacentElement('afterend', errorDiv);
        }

        // Set aria-invalid
        input.setAttribute('aria-invalid', 'true');
    }

    /**
   * Mark field as valid
   */
    function markFieldValid(input) {
        if (!input) {
            return;
        }

        input.classList.remove('invalid');
        input.classList.add('valid');
        input.setAttribute('aria-invalid', 'false');

        // Remove error message
        const errorDiv = input.parentElement.querySelector('.form-error');
        if (errorDiv) {
            errorDiv.remove();
        }
    }

    /**
   * Clear field error state
   */
    function clearFieldError(input) {
        if (!input) {
            return;
        }

        input.classList.remove('invalid', 'valid');
        input.removeAttribute('aria-invalid');

        const errorDiv = input.parentElement.querySelector('.form-error');
        if (errorDiv) {
            errorDiv.remove();
        }
    }

    /**
   * Show loading state on submit button
   */
    function showLoadingState(button) {
        if (!button) {
            return;
        }

        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.textContent = 'Signing in...';
    }

})();
