/**
 * Centralized CSRF Token Management for API Calls
 *
 * Purpose: Provides secure API call functionality with automatic CSRF token inclusion
 * for all state-changing requests (POST, PUT, DELETE, PATCH).
 *
 * Features:
 * - Centralized CSRF token retrieval from meta tags or form fields
 * - Automatic CSRF token inclusion for state-changing requests
 * - Enhanced error handling with user-friendly messages
 * - Network error detection and retry mechanisms
 * - Consistent API response processing
 *
 * Dependencies: api-error-handler.js (for error classes)
 *
 * @version 1.0.0
 */

(function() {
    'use strict';

    /**
     * Centralized CSRF token retrieval function
     * Retrieves CSRF tokens from meta tags or form fields with fallback
     * @returns {string|null} The CSRF token or null if not found
     */
    function getCSRFToken() {
        // First try to get from meta tag (preferred method)
        const metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken && metaToken.content) {
            return metaToken.content;
        }

        // Fallback to hidden form field (existing forms)
        const formToken = document.querySelector('input[name="csrf"]');
        if (formToken && formToken.value) {
            return formToken.value;
        }

        // Log warning in development if no token found
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.warn('CSRF token not found in meta tag or form fields');
        }

        return null;
    }

    /**
     * Enhanced fetch wrapper with automatic CSRF protection
     * Automatically includes CSRF tokens for state-changing requests
     * @param {string} url - The URL to fetch
     * @param {Object} options - Fetch options
     * @returns {Promise<Response>} Enhanced fetch promise
     */
    function secureFetch(url, options = {}) {
        // Ensure options object exists
        options = { ...options };

        // Ensure headers object exists
        if (!options.headers) {
            options.headers = {};
        }

        // Convert headers to object if it's a Headers instance
        if (options.headers instanceof Headers) {
            const headersObj = {};
            for (const [key, value] of options.headers.entries()) {
                headersObj[key] = value;
            }
            options.headers = headersObj;
        }

        // Get the HTTP method (default to GET)
        const method = (options.method || 'GET').toUpperCase();

        // Add CSRF token for state-changing requests
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
            const token = getCSRFToken();
            if (token) {
                options.headers['X-CSRF-Token'] = token;

                // Also add to form data if it's FormData
                if (options.body instanceof FormData && !options.body.has('csrf')) {
                    options.body.append('csrf', token);
                }
            } else {
                console.error(`CSRF token missing for ${method} request:`, url);
                // Don't block the request, let the server handle the error
            }
        }

        // Add standard headers for AJAX requests
        options.headers['X-Requested-With'] = 'XMLHttpRequest';

        // Set default content type for JSON requests if not specified
        if (options.body && typeof options.body === 'string' && !options.headers['Content-Type']) {
            try {
                JSON.parse(options.body);
                options.headers['Content-Type'] = 'application/json';
            } catch (e) {
                // Not JSON, leave content type as is
            }
        }

        // Return enhanced fetch with error handling
        return fetch(url, options)
            .then(response => {
                // Handle authentication errors
                if (response.status === 401) {
                    handleAuthenticationError();
                    throw new AuthenticationError('Your session has expired. Please log in again.');
                }

                // Handle CSRF errors
                if (response.status === 403) {
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        return response.json().then(data => {
                            if (data.error && data.error.includes('CSRF')) {
                                handleCSRFError();
                                throw new AuthorizationError('Security token expired. Please refresh the page.');
                            }
                            throw new AuthorizationError(data.error || 'Access denied');
                        });
                    }
                    throw new AuthorizationError('Access denied');
                }

                return response;
            })
            .catch(error => {
                // Handle network errors
                if (error.name === 'TypeError' && error.message.includes('fetch')) {
                    throw new NetworkError('Network connection failed. Please check your internet connection.');
                }

                // Re-throw other errors (including our custom error types)
                throw error;
            });
    }

    /**
     * Standardized API response handler
     * Processes API responses and handles common error scenarios
     * @param {Response} response - The fetch response object
     * @returns {Promise<Object>} Parsed JSON response
     */
    function handleAPIResponse(response) {
        // Check if response is ok
        if (!response.ok) {
            // Handle different HTTP status codes
            switch (response.status) {
            case 400:
                throw new ValidationError('Invalid request. Please check your input.');
            case 401:
                throw new AuthenticationError('Authentication required. Please log in.');
            case 403:
                throw new AuthorizationError('Access denied. You do not have permission to perform this action.');
            case 404:
                throw new NotFoundError('The requested resource was not found.');
            case 422:
                throw new ValidationError('Validation failed. Please check your input.');
            case 429:
                throw new RateLimitError('Too many requests. Please try again later.');
            case 500:
            case 502:
            case 503:
            case 504:
                throw new ServerError('Server error. Please try again later.');
            default:
                throw new APIError(`Request failed with status ${response.status}`);
            }
        }

        // Parse JSON response
        return response.json().then(data => {
            // Validate response structure
            if (typeof data !== 'object' || data === null) {
                throw new APIError('Invalid response format');
            }

            // Handle API-level errors (when server returns 200 but with error in JSON)
            if (data.success === false) {
                const errorMessage = data.error || data.message || 'An error occurred';
                throw new APIError(errorMessage);
            }

            return data;
        }).catch(jsonError => {
            // Handle JSON parsing errors
            if (jsonError instanceof SyntaxError) {
                throw new APIError('Invalid response format from server');
            }
            throw jsonError;
        });
    }

    /**
     * Custom error classes for better error handling
     */
    class APIError extends Error {
        constructor(message) {
            super(message);
            this.name = 'APIError';
        }
    }

    class NetworkError extends Error {
        constructor(message) {
            super(message);
            this.name = 'NetworkError';
        }
    }

    class ValidationError extends Error {
        constructor(message) {
            super(message);
            this.name = 'ValidationError';
        }
    }

    class AuthenticationError extends Error {
        constructor(message) {
            super(message);
            this.name = 'AuthenticationError';
        }
    }

    class AuthorizationError extends Error {
        constructor(message) {
            super(message);
            this.name = 'AuthorizationError';
        }
    }

    class NotFoundError extends Error {
        constructor(message) {
            super(message);
            this.name = 'NotFoundError';
        }
    }

    class RateLimitError extends Error {
        constructor(message) {
            super(message);
            this.name = 'RateLimitError';
        }
    }

    class ServerError extends Error {
        constructor(message) {
            super(message);
            this.name = 'ServerError';
        }
    }

    /**
     * Handle authentication errors by redirecting to login
     */
    function handleAuthenticationError() {
        // Show user-friendly message
        if (window.showFormMessage) {
            window.showFormMessage('Your session has expired. Please log in again.', 'error');
        }

        // Redirect to login after a short delay
        setTimeout(() => {
            window.location.href = '/login.php';
        }, 2000);
    }

    /**
     * Handle CSRF errors by refreshing the page to get new token
     */
    function handleCSRFError() {
        // Show user-friendly message
        if (window.showFormMessage) {
            window.showFormMessage('Security token expired. Refreshing page...', 'error');
        }

        // Refresh page to get new CSRF token
        setTimeout(() => {
            window.location.reload();
        }, 2000);
    }

    /**
     * Utility function to create form data with CSRF token
     * @param {Object} data - Data object to convert to FormData
     * @returns {FormData} FormData with CSRF token included
     */
    function createSecureFormData(data = {}) {
        const formData = new FormData();

        // Add CSRF token
        const token = getCSRFToken();
        if (token) {
            formData.append('csrf', token);
        }

        // Add other data
        for (const [key, value] of Object.entries(data)) {
            if (value instanceof File) {
                formData.append(key, value);
            } else if (Array.isArray(value)) {
                value.forEach((item, index) => {
                    formData.append(`${key}[${index}]`, item);
                });
            } else {
                formData.append(key, String(value));
            }
        }

        return formData;
    }

    /**
     * Utility function to create JSON data with CSRF token
     * @param {Object} data - Data object to include CSRF token in
     * @returns {string} JSON string with CSRF token included
     */
    function createSecureJSONData(data = {}) {
        const token = getCSRFToken();
        if (token) {
            data.csrf = token;
        }
        return JSON.stringify(data);
    }

    /**
     * Enhanced error message display with user-friendly translations
     * @param {Error} error - The error to display
     * @param {string} context - Context for the error (optional)
     */
    function displayAPIError(error, context = '') {
        let message = 'An unexpected error occurred. Please try again.';
        const type = 'error';

        // Translate technical errors to user-friendly messages
        switch (error.name) {
        case 'NetworkError':
            message = 'Connection failed. Please check your internet connection and try again.';
            break;
        case 'ValidationError':
            message = error.message;
            break;
        case 'AuthenticationError':
            message = 'Please log in to continue.';
            break;
        case 'AuthorizationError':
            message = 'You do not have permission to perform this action.';
            break;
        case 'NotFoundError':
            message = 'The requested item was not found.';
            break;
        case 'RateLimitError':
            message = 'Too many requests. Please wait a moment and try again.';
            break;
        case 'ServerError':
            message = 'Server is temporarily unavailable. Please try again in a few minutes.';
            break;
        case 'APIError':
            message = error.message;
            break;
        default:
            message = error.message || message;
        }

        // Add context if provided
        if (context) {
            message = `${context}: ${message}`;
        }

        // Display the error message
        if (window.showFormMessage) {
            window.showFormMessage(message, type);
        } else {
            // Use QuackNotify if available, otherwise fallback to alert
            if (window.QuackNotify) {
                QuackNotify.error(message);
            } else {
                alert(message);
            }
        }

        // Log error details in development
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.error('API Error:', error);
        }
    }

    // Export functions to global scope
    window.getCSRFToken = getCSRFToken;
    window.secureFetch = secureFetch;
    window.handleAPIResponse = handleAPIResponse;
    window.createSecureFormData = createSecureFormData;
    window.createSecureJSONData = createSecureJSONData;
    window.displayAPIError = displayAPIError;

    // Export error classes
    window.APIError = APIError;
    window.NetworkError = NetworkError;
    window.ValidationError = ValidationError;
    window.AuthenticationError = AuthenticationError;
    window.AuthorizationError = AuthorizationError;
    window.NotFoundError = NotFoundError;
    window.RateLimitError = RateLimitError;
    window.ServerError = ServerError;

})();