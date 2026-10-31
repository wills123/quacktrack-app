/**
 * Quack Track - Register Page Client-Side Enhancement
 * Provides real-time validation, password strength indicator, and better UX
 */

(function() {
    'use strict';

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRegisterPage);
    } else {
        initRegisterPage();
    }

    function initRegisterPage() {
        const form = document.querySelector('.register-form');
        if (!form) {
            return;
        }

        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm');

        // Initialize password strength indicator
        initPasswordStrength(passwordInput);

        // Initialize real-time validation
        if (emailInput) {
            emailInput.addEventListener('blur', () => validateEmail(emailInput));
            emailInput.addEventListener('input', () => clearFieldError(emailInput));
        }

        if (passwordInput) {
            passwordInput.addEventListener('input', () => {
                updatePasswordStrength(passwordInput);
                clearFieldError(passwordInput);
            });
        }

        if (confirmInput) {
            confirmInput.addEventListener('blur', () => validatePasswordMatch(passwordInput, confirmInput));
            confirmInput.addEventListener('input', () => clearFieldError(confirmInput));
        }

        // Form submission validation
        form.addEventListener('submit', (e) => {
            if (!validateForm(emailInput, passwordInput, confirmInput)) {
                e.preventDefault();
                return false;
            }
        });
    }

    /**
   * Initialize password strength indicator
   */
    function initPasswordStrength(passwordInput) {
        if (!passwordInput) {
            return;
        }

        const container = passwordInput.closest('.form-group');
        if (!container) {
            return;
        }

        // Create strength indicator
        const strengthHTML = `
      <div class="password-strength" role="status" aria-live="polite">
        <div class="password-strength-bar">
          <div class="password-strength-fill"></div>
        </div>
        <div class="password-strength-text"></div>
      </div>
      <div class="password-requirements">
        <ul>
          <li id="req-length">At least 8 characters</li>
          <li id="req-uppercase">One uppercase letter</li>
          <li id="req-lowercase">One lowercase letter</li>
          <li id="req-number">One number</li>
          <li id="req-special">One special character</li>
        </ul>
      </div>
    `;

        // Insert after password input
        const helpText = container.querySelector('.form-help');
        if (helpText) {
            helpText.insertAdjacentHTML('afterend', strengthHTML);
        }
    }

    /**
   * Update password strength indicator
   */
    function updatePasswordStrength(passwordInput) {
        const password = passwordInput.value;
        const strengthFill = document.querySelector('.password-strength-fill');
        const strengthText = document.querySelector('.password-strength-text');

        if (!strengthFill || !strengthText) {
            return;
        }

        // Check password requirements
        const requirements = {
            length: password.length >= 8,
            uppercase: /[A-Z]/.test(password),
            lowercase: /[a-z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password)
        };

        // Update requirement checkmarks
        updateRequirement('req-length', requirements.length);
        updateRequirement('req-uppercase', requirements.uppercase);
        updateRequirement('req-lowercase', requirements.lowercase);
        updateRequirement('req-number', requirements.number);
        updateRequirement('req-special', requirements.special);

        // Calculate strength
        const metRequirements = Object.values(requirements).filter(Boolean).length;
        let strength = 'weak';
        let strengthLabel = 'Weak';

        if (password.length === 0) {
            strengthFill.className = 'password-strength-fill';
            strengthFill.style.width = '0%';
            strengthText.textContent = '';
            return;
        }

        if (metRequirements >= 5) {
            strength = 'strong';
            strengthLabel = 'Strong password';
        } else if (metRequirements >= 3) {
            strength = 'medium';
            strengthLabel = 'Medium password';
        } else {
            strength = 'weak';
            strengthLabel = 'Weak password';
        }

        strengthFill.className = `password-strength-fill ${strength}`;
        strengthText.className = `password-strength-text ${strength}`;
        strengthText.textContent = strengthLabel;
    }

    /**
   * Update individual requirement status
   */
    function updateRequirement(id, met) {
        const element = document.getElementById(id);
        if (!element) {
            return;
        }

        if (met) {
            element.classList.add('met');
        } else {
            element.classList.remove('met');
        }
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
   * Validate password match
   */
    function validatePasswordMatch(passwordInput, confirmInput) {
        const password = passwordInput.value;
        const confirm = confirmInput.value;

        if (!confirm) {
            showFieldError(confirmInput, 'Please confirm your password');
            return false;
        }

        if (password !== confirm) {
            showFieldError(confirmInput, 'Passwords do not match');
            return false;
        }

        markFieldValid(confirmInput);
        return true;
    }

    /**
   * Validate entire form
   */
    function validateForm(emailInput, passwordInput, confirmInput) {
        let isValid = true;

        // Validate email
        if (emailInput && !validateEmail(emailInput)) {
            isValid = false;
        }

        // Validate password length
        if (passwordInput) {
            const password = passwordInput.value;
            if (!password) {
                showFieldError(passwordInput, 'Password is required');
                isValid = false;
            } else if (password.length < 8) {
                showFieldError(passwordInput, 'Password must be at least 8 characters');
                isValid = false;
            }
        }

        // Validate password match
        if (confirmInput && !validatePasswordMatch(passwordInput, confirmInput)) {
            isValid = false;
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

})();
