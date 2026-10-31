/**
 * Centralized API Error Handling System
 *
 * Purpose: Provides standardized error handling for all API calls across the application.
 * This system consolidates error handling patterns and provides user-friendly error messages
 * with proper accessibility support and retry mechanisms.
 *
 * Features:
 * - Standardized handleAPIResponse() function for processing API responses
 * - User-friendly error message translation for different HTTP status codes
 * - Network error detection and handling with retry mechanisms
 * - Timeout handling for all API calls
 * - Accessibility-compliant error announcements
 * - Integration with existing CSRF and authentication systems
 *
 * @version 1.0.0
 */

(function() {
    'use strict';

    /**
     * Custom error classes for better error categorization
     */
    class APIError extends Error {
        constructor(message, status = null, code = null) {
            super(message);
            this.name = 'APIError';
            this.status = status;
            this.code = code;
        }
    }

    class NetworkError extends Error {
        constructor(message, originalError = null) {
            super(message);
            this.name = 'NetworkError';
            this.originalError = originalError;
            this.retryable = true;
        }
    }

    class ValidationError extends Error {
        constructor(message, fields = null) {
            super(message);
            this.name = 'ValidationError';
            this.fields = fields;
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

    class TimeoutError extends Error {
        constructor(message, timeout = null) {
            super(message);
            this.name = 'TimeoutError';
            this.timeout = timeout;
            this.retryable = true;
        }
    }

    class ServerError extends Error {
        constructor(message, status = null) {
            super(message);
            this.name = 'ServerError';
            this.status = status;
            this.retryable = true;
        }
    }

    /**
     * Standardized API response handler with enhanced validation and fallback mechanisms
     * Processes API responses and handles common error scenarios with user-friendly messages
     * @param {Response} response - The fetch response object
     * @param {Object} options - Validation options
     * @returns {Promise<Object>} Parsed JSON response
     * @throws {APIError|NetworkError|ValidationError|AuthenticationError|AuthorizationError|ServerError}
     */
    function handleAPIResponse(response, options = {}) {
        const {
            expectedFields = [],
            fallbackData = null,
            validateStructure = true,
            allowEmptyResponse = false
        } = options;

        // Check if response is ok
        if (!response.ok) {
            // Handle different HTTP status codes with user-friendly messages
            switch (response.status) {
            case 400:
                throw new ValidationError('Invalid request. Please check your input and try again.');
            case 401:
                throw new AuthenticationError('Your session has expired. Please log in again.');
            case 403:
                throw new AuthorizationError('Access denied. You do not have permission to perform this action.');
            case 404:
                throw new APIError('The requested resource was not found.', 404);
            case 409:
                throw new ValidationError('Conflict detected. The resource may have been modified by another user.');
            case 422:
                throw new ValidationError('Validation failed. Please check your input and try again.');
            case 429:
                throw new APIError('Too many requests. Please wait a moment and try again.', 429);
            case 500:
                throw new ServerError('Server error occurred. Please try again in a few moments.', 500);
            case 502:
                throw new ServerError('Service temporarily unavailable. Please try again later.', 502);
            case 503:
                throw new ServerError('Service temporarily unavailable. Please try again later.', 503);
            case 504:
                throw new TimeoutError('Request timed out. Please try again.');
            default:
                throw new APIError(`Request failed with status ${response.status}`, response.status);
            }
        }

        // Check content type
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            if (allowEmptyResponse && response.status === 204) {
                return { success: true, data: null };
            }
            throw new APIError('Server returned non-JSON response');
        }

        // Parse JSON response with enhanced error handling and validation
        return response.json().then(data => {
            // Validate basic response structure
            if (validateStructure) {
                const validationResult = validateAPIResponseStructure(data, expectedFields);
                if (!validationResult.isValid) {
                    if (fallbackData) {
                        console.warn('API response validation failed, using fallback data:', validationResult.errors);
                        return createFallbackResponse(fallbackData);
                    }
                    throw new APIError(`Invalid response structure: ${validationResult.errors.join(', ')}`);
                }
            }

            // Handle API-level errors (when server returns 200 but with error in JSON)
            if (data.success === false) {
                const errorMessage = data.error || data.message || 'An error occurred';

                // Check for specific error types in the response
                if (data.code === 'VALIDATION_ERROR' || data.type === 'validation') {
                    throw new ValidationError(errorMessage, data.fields);
                } else if (data.code === 'AUTH_ERROR' || data.type === 'authentication') {
                    throw new AuthenticationError(errorMessage);
                } else if (data.code === 'PERMISSION_ERROR' || data.type === 'authorization') {
                    throw new AuthorizationError(errorMessage);
                } else {
                    throw new APIError(errorMessage, null, data.code);
                }
            }

            return data;
        }).catch(jsonError => {
            // Handle JSON parsing errors with fallback
            if (jsonError instanceof SyntaxError) {
                if (fallbackData) {
                    console.warn('JSON parsing failed, using fallback data:', jsonError.message);
                    return createFallbackResponse(fallbackData);
                }
                throw new APIError('Invalid response format from server');
            }
            // Re-throw our custom errors
            throw jsonError;
        });
    }

    /**
     * Validates API response structure and required fields
     * @param {Object} data - The response data to validate
     * @param {Array} expectedFields - Array of required field names
     * @returns {Object} Validation result with isValid boolean and errors array
     */
    function validateAPIResponseStructure(data, expectedFields = []) {
        const errors = [];

        // Basic structure validation
        if (typeof data !== 'object' || data === null) {
            errors.push('Response must be an object');
            return { isValid: false, errors };
        }

        // Check for success field (common pattern)
        if (!Object.prototype.hasOwnProperty.call(data, 'success') && !Object.prototype.hasOwnProperty.call(data, 'data')) {
            errors.push('Response missing success or data field');
        }

        // Validate expected fields
        expectedFields.forEach(field => {
            if (!Object.prototype.hasOwnProperty.call(data, field)) {
                errors.push(`Missing required field: ${field}`);
            }
        });

        // Validate data field if present
        if (Object.prototype.hasOwnProperty.call(data, 'data')) {
            if (data.data === undefined) {
                errors.push('Data field is undefined');
            }
        }

        // Check for common malformed patterns
        if (typeof data.success === 'string' && data.success !== 'true' && data.success !== 'false') {
            // Convert string boolean to actual boolean
            data.success = data.success === 'true';
        }

        return {
            isValid: errors.length === 0,
            errors
        };
    }

    /**
     * Creates a standardized fallback response
     * @param {*} fallbackData - The fallback data to use
     * @returns {Object} Standardized response object
     */
    function createFallbackResponse(fallbackData) {
        return {
            success: true,
            data: fallbackData,
            message: 'Using cached or fallback data',
            fallback: true
        };
    }

    /**
     * Enhanced network error handler
     * Detects different types of network errors and provides appropriate user messages
     * @param {Error} error - The original error
     * @returns {string} User-friendly error message
     */
    function handleNetworkError(error) {
        let errorMessage = 'An unexpected error occurred. Please try again.';

        // Handle different types of network errors
        if (error.name === 'TypeError' && error.message.includes('fetch')) {
            errorMessage = 'Network connection failed. Please check your internet connection and try again.';
        } else if (error.name === 'AbortError') {
            errorMessage = 'Request was cancelled. Please try again.';
        } else if (error.name === 'TimeoutError' || error.message.includes('timeout')) {
            errorMessage = 'Request timed out. Please check your connection and try again.';
        } else if (error.message.includes('NetworkError')) {
            errorMessage = 'Network error occurred. Please check your internet connection.';
        } else if (error.message.includes('CORS')) {
            errorMessage = 'Security error occurred. Please refresh the page and try again.';
        } else if (error.message) {
            // Use the error message if it's user-friendly
            errorMessage = error.message;
        }

        return errorMessage;
    }

    /**
     * Enhanced fetch wrapper with timeout, retry capabilities, and exponential backoff
     * @param {string} url - The URL to fetch
     * @param {Object} options - Fetch options
     * @param {number} timeout - Timeout in milliseconds (default: 30000)
     * @param {number} retries - Number of retry attempts (default: 0)
     * @param {Object} retryConfig - Retry configuration
     * @returns {Promise<Response>} Enhanced fetch promise
     */
    function fetchWithTimeout(url, options = {}, timeout = 30000, retries = 0, retryConfig = {}) {
        const {
            baseDelay = 1000,
            maxDelay = 10000,
            backoffMultiplier = 2,
            jitter = true
        } = retryConfig;

        // Create abort controller for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        // Add abort signal to options (but don't override existing signal)
        if (!options.signal) {
            options.signal = controller.signal;
        } else {
            // Combine signals if one already exists
            const existingSignal = options.signal;
            const combinedController = new AbortController();

            const abortHandler = () => combinedController.abort();
            controller.signal.addEventListener('abort', abortHandler);
            existingSignal.addEventListener('abort', abortHandler);

            options.signal = combinedController.signal;
        }

        return fetch(url, options)
            .then(response => {
                clearTimeout(timeoutId);
                return response;
            })
            .catch(error => {
                clearTimeout(timeoutId);

                // Handle timeout errors
                if (error.name === 'AbortError') {
                    throw new TimeoutError(`Request timed out after ${timeout}ms`, timeout);
                }

                // Handle network errors with enhanced retry logic
                if (retries > 0 && isRetryableError(error)) {
                    // Calculate delay with exponential backoff and optional jitter
                    let delay = Math.min(baseDelay * Math.pow(backoffMultiplier, 3 - retries), maxDelay);

                    // Add jitter to prevent thundering herd
                    if (jitter) {
                        delay = delay * (0.5 + Math.random() * 0.5);
                    }

                    return new Promise(resolve => setTimeout(resolve, delay))
                        .then(() => fetchWithTimeout(url, options, timeout, retries - 1, retryConfig));
                }

                // Convert to NetworkError for consistent handling
                throw new NetworkError(handleNetworkError(error), error);
            });
    }

    /**
     * Determines if an error is retryable
     * @param {Error} error - The error to check
     * @returns {boolean} True if the error is retryable
     */
    function isRetryableError(error) {
        // Network errors are generally retryable
        if (error.name === 'TypeError' && error.message.includes('fetch')) {
            return true;
        }

        // Timeout errors are retryable
        if (error.name === 'AbortError' || error.name === 'TimeoutError') {
            return true;
        }

        // Some HTTP status codes are retryable
        if (error.status) {
            const retryableStatuses = [408, 429, 500, 502, 503, 504];
            return retryableStatuses.includes(error.status);
        }

        return false;
    }

    /**
     * Enhanced error display with user-friendly translations and accessibility
     * @param {Error} error - The error to display
     * @param {string} context - Context for the error (optional)
     * @param {HTMLElement} container - Container to display error in (optional)
     */
    function displayAPIError(error, context = '', container = null) {
        let message = 'An unexpected error occurred. Please try again.';
        let type = 'error';
        let canRetry = false;

        // Translate technical errors to user-friendly messages
        switch (error.name) {
        case 'NetworkError':
            message = 'Connection failed. Please check your internet connection and try again.';
            canRetry = true;
            break;
        case 'TimeoutError':
            message = 'Request timed out. Please try again.';
            canRetry = true;
            break;
        case 'ValidationError':
            message = error.message;
            type = 'warning';
            break;
        case 'AuthenticationError':
            message = 'Your session has expired. Please log in again.';
            type = 'warning';
            // Redirect to login after showing message
            setTimeout(() => {
                window.location.href = '/login.php';
            }, 3000);
            break;
        case 'AuthorizationError':
            message = 'You do not have permission to perform this action.';
            type = 'warning';
            break;
        case 'ServerError':
            message = 'Server is temporarily unavailable. Please try again in a few minutes.';
            canRetry = true;
            break;
        case 'APIError':
            message = error.message;
            canRetry = error.status >= 500; // Server errors are retryable
            break;
        default:
            message = error.message || message;
        }

        // Add context if provided
        if (context) {
            message = `${context}: ${message}`;
        }

        // Display the error message
        if (container) {
            displayErrorInContainer(container, message, type, canRetry, error);
        } else if (window.QuackNotify) {
            // Use QuackNotify for better user experience
            const options = {};

            // For retryable errors, make them persistent so user can see retry option
            if (canRetry) {
                options.persistent = true;
                options.actions = [{
                    text: 'Try Again',
                    callback: () => {
                        // Emit custom event for retry handling
                        const retryEvent = new CustomEvent('apiErrorRetry', {
                            detail: { error: error, context: context }
                        });
                        document.dispatchEvent(retryEvent);
                    },
                    style: 'secondary'
                }];
            }

            // Map type to QuackNotify methods
            switch (type) {
            case 'error':
                window.QuackNotify.error(message, options);
                break;
            case 'warning':
                window.QuackNotify.warning(message, options);
                break;
            case 'info':
                window.QuackNotify.info(message, options);
                break;
            default:
                window.QuackNotify.error(message, options);
                break;
            }
        } else if (window.showFormMessage) {
            // Fallback to showFormMessage for backward compatibility
            window.showFormMessage(message, type);
        } else {
            // Use QuackNotify if available, otherwise fallback to alert
            if (window.QuackNotify) {
                QuackNotify.error(message);
            } else {
                alert(message);
            }
        }

        // Announce to screen readers
        announceToScreenReader(message, 'assertive');

        // Log error details in development
        if (isDevelopmentMode()) {
            console.error('API Error:', error);
        }
    }

    /**
     * Display error in a specific container with retry option
     * @param {HTMLElement} container - Container to display error in
     * @param {string} message - Error message
     * @param {string} type - Error type (error, warning, info)
     * @param {boolean} canRetry - Whether retry option should be shown
     * @param {Error} originalError - Original error for retry context
     */
    function displayErrorInContainer(container, message, type = 'error', canRetry = false, originalError = null) {
        // Remove existing error messages
        const existingErrors = container.querySelectorAll('.api-error-message');
        existingErrors.forEach(error => error.remove());

        // Create error element
        const errorDiv = document.createElement('div');
        errorDiv.className = `api-error-message api-error-${type}`;
        errorDiv.setAttribute('role', 'alert');
        errorDiv.setAttribute('aria-live', 'assertive');

        // Create error content
        const errorContent = document.createElement('div');
        errorContent.className = 'api-error-content';

        const errorIcon = document.createElement('span');
        errorIcon.className = 'api-error-icon';
        errorIcon.setAttribute('aria-hidden', 'true');
        errorIcon.textContent = type === 'error' ? '⚠️' : type === 'warning' ? '⚠️' : 'ℹ️';

        const errorText = document.createElement('span');
        errorText.className = 'api-error-text';
        errorText.textContent = message;

        errorContent.appendChild(errorIcon);
        errorContent.appendChild(errorText);
        errorDiv.appendChild(errorContent);

        // Add retry button if applicable
        if (canRetry && originalError) {
            const retryButton = document.createElement('button');
            retryButton.type = 'button';
            retryButton.className = 'api-error-retry btn btn-sm btn-secondary';
            retryButton.textContent = 'Try Again';
            retryButton.setAttribute('aria-label', 'Retry the failed operation');

            retryButton.addEventListener('click', () => {
                // Emit custom event for retry handling
                const retryEvent = new CustomEvent('apiErrorRetry', {
                    detail: { error: originalError, container: container }
                });
                container.dispatchEvent(retryEvent);
            });

            errorDiv.appendChild(retryButton);
        }

        // Add close button
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'api-error-close';
        closeButton.innerHTML = '×';
        closeButton.setAttribute('aria-label', 'Close error message');
        closeButton.addEventListener('click', () => {
            errorDiv.remove();
        });

        errorDiv.appendChild(closeButton);

        // Add styles
        errorDiv.style.cssText = `
            margin: 10px 0;
            padding: 12px;
            border-radius: 4px;
            border: 1px solid;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: ${type === 'error' ? '#f8d7da' : type === 'warning' ? '#fff3cd' : '#d1ecf1'};
            color: ${type === 'error' ? '#721c24' : type === 'warning' ? '#856404' : '#0c5460'};
            border-color: ${type === 'error' ? '#f5c6cb' : type === 'warning' ? '#ffeaa7' : '#bee5eb'};
        `;

        // Insert at the top of the container
        container.insertBefore(errorDiv, container.firstChild);

        // Auto-remove after 10 seconds for non-critical errors
        if (type !== 'error') {
            setTimeout(() => {
                if (errorDiv.parentNode) {
                    errorDiv.remove();
                }
            }, 10000);
        }
    }

    /**
     * Announce message to screen readers
     * @param {string} message - Message to announce
     * @param {string} priority - Announcement priority (polite, assertive)
     */
    function announceToScreenReader(message, priority = 'polite') {
        if (window.announceToScreenReader && window.announceToScreenReader !== announceToScreenReader) {
            window.announceToScreenReader(message, priority);
        } else {
            // Fallback implementation
            const announcement = document.createElement('div');
            announcement.setAttribute('aria-live', priority);
            announcement.setAttribute('aria-atomic', 'true');
            announcement.style.cssText = `
                position: absolute;
                left: -10000px;
                width: 1px;
                height: 1px;
                overflow: hidden;
            `;
            announcement.textContent = message;
            document.body.appendChild(announcement);

            setTimeout(() => {
                document.body.removeChild(announcement);
            }, 1000);
        }
    }

    /**
     * Check if we're in development mode
     * @returns {boolean} True if in development mode
     */
    function isDevelopmentMode() {
        return window.location.hostname === 'localhost' ||
               window.location.hostname === '127.0.0.1' ||
               window.location.hostname.includes('dev') ||
               window.location.search.includes('debug=1');
    }

    /**
     * Utility function to create a standardized API call with full error handling, validation, and fallback
     * @param {string} url - The URL to call
     * @param {Object} options - Fetch options
     * @param {Object} config - Configuration options
     * @returns {Promise} Promise that resolves with data or rejects with user-friendly error
     */
    function makeAPICall(url, options = {}, config = {}) {
        const {
            timeout = 30000,
            retries = 1,
            context = '',
            container = null,
            showErrors = true,
            expectedFields = [],
            fallbackData = null,
            validateStructure = true,
            allowEmptyResponse = false,
            retryConfig = {},
            onRetry = null,
            onFallback = null
        } = config;

        const retryOptions = {
            baseDelay: 1000,
            maxDelay: 10000,
            backoffMultiplier: 2,
            jitter: true,
            ...retryConfig
        };

        const validationOptions = {
            expectedFields,
            fallbackData,
            validateStructure,
            allowEmptyResponse
        };

        return fetchWithTimeout(url, options, timeout, retries, retryOptions)
            .then(response => {
                return handleAPIResponse(response, validationOptions);
            })
            .then(data => {
                // Check if fallback data was used
                if (data.fallback && onFallback) {
                    onFallback(data, url);
                }
                return data;
            })
            .catch(error => {
                // Call retry callback if provided
                if (onRetry && isRetryableError(error)) {
                    onRetry(error, url, retries);
                }

                if (showErrors) {
                    displayAPIError(error, context, container);
                }
                throw error; // Re-throw for caller to handle if needed
            });
    }

    /**
     * Creates a retry mechanism for failed API calls with exponential backoff
     * @param {Function} apiCallFunction - The API call function to retry
     * @param {number} maxRetries - Maximum number of retry attempts
     * @param {Object} retryConfig - Retry configuration
     * @returns {Promise} Promise that resolves with successful response or rejects after all retries
     */
    function createRetryMechanism(apiCallFunction, maxRetries = 3, retryConfig = {}) {
        const {
            baseDelay = 1000,
            maxDelay = 10000,
            backoffMultiplier = 2,
            jitter = true,
            retryCondition = isRetryableError
        } = retryConfig;

        return function retryWrapper(...args) {
            let attempt = 0;

            function attemptCall() {
                return apiCallFunction(...args)
                    .catch(error => {
                        attempt++;

                        if (attempt >= maxRetries || !retryCondition(error)) {
                            throw error;
                        }

                        // Calculate delay with exponential backoff
                        let delay = Math.min(baseDelay * Math.pow(backoffMultiplier, attempt - 1), maxDelay);

                        // Add jitter to prevent thundering herd
                        if (jitter) {
                            delay = delay * (0.5 + Math.random() * 0.5);
                        }

                        return new Promise(resolve => setTimeout(resolve, delay))
                            .then(attemptCall);
                    });
            }

            return attemptCall();
        };
    }

    /**
     * Creates a circuit breaker pattern for API calls to prevent cascading failures
     * @param {Function} apiCallFunction - The API call function to wrap
     * @param {Object} circuitConfig - Circuit breaker configuration
     * @returns {Function} Wrapped function with circuit breaker logic
     */
    function createCircuitBreaker(apiCallFunction, circuitConfig = {}) {
        const {
            failureThreshold = 5,
            resetTimeout = 60000,
            monitoringPeriod = 60000
        } = circuitConfig;

        let state = 'CLOSED'; // CLOSED, OPEN, HALF_OPEN
        let failureCount = 0;
        let lastFailureTime = null;
        let successCount = 0;

        return function circuitBreakerWrapper(...args) {
            const now = Date.now();

            // Reset failure count if monitoring period has passed
            if (lastFailureTime && (now - lastFailureTime) > monitoringPeriod) {
                failureCount = 0;
                if (state === 'OPEN') {
                    state = 'HALF_OPEN';
                }
            }

            // If circuit is open, check if we should try again
            if (state === 'OPEN') {
                if (lastFailureTime && (now - lastFailureTime) < resetTimeout) {
                    throw new APIError('Service temporarily unavailable (circuit breaker open)');
                } else {
                    state = 'HALF_OPEN';
                }
            }

            return apiCallFunction(...args)
                .then(result => {
                    // Success - reset failure count and close circuit
                    if (state === 'HALF_OPEN') {
                        successCount++;
                        if (successCount >= 2) {
                            state = 'CLOSED';
                            failureCount = 0;
                            successCount = 0;
                        }
                    }
                    return result;
                })
                .catch(error => {
                    // Failure - increment failure count
                    failureCount++;
                    lastFailureTime = now;
                    successCount = 0;

                    // Open circuit if threshold reached
                    if (failureCount >= failureThreshold) {
                        state = 'OPEN';
                    }

                    throw error;
                });
        };
    }

    /**
     * Creates a cache mechanism for API responses to provide fallback data
     * @param {Function} apiCallFunction - The API call function to cache
     * @param {Object} cacheConfig - Cache configuration
     * @returns {Function} Wrapped function with caching logic
     */
    function createAPICache(apiCallFunction, cacheConfig = {}) {
        const {
            ttl = 300000, // 5 minutes default TTL
            maxSize = 100,
            keyGenerator = (args) => JSON.stringify(args)
        } = cacheConfig;

        const cache = new Map();

        return function cachedWrapper(...args) {
            const key = keyGenerator(args);
            const now = Date.now();

            // Check if we have cached data
            if (cache.has(key)) {
                const cached = cache.get(key);
                if (now - cached.timestamp < ttl) {
                    // Return cached data with fallback flag
                    return Promise.resolve({
                        ...cached.data,
                        cached: true,
                        cacheAge: now - cached.timestamp
                    });
                } else {
                    // Remove expired entry
                    cache.delete(key);
                }
            }

            return apiCallFunction(...args)
                .then(result => {
                    // Cache successful result
                    if (cache.size >= maxSize) {
                        // Remove oldest entry
                        const firstKey = cache.keys().next().value;
                        cache.delete(firstKey);
                    }

                    cache.set(key, {
                        data: result,
                        timestamp: now
                    });

                    return result;
                })
                .catch(error => {
                    // If we have stale cached data, use it as fallback
                    if (cache.has(key)) {
                        const cached = cache.get(key);
                        console.warn('API call failed, using stale cached data:', error.message);
                        return {
                            ...cached.data,
                            cached: true,
                            stale: true,
                            cacheAge: now - cached.timestamp,
                            fallback: true
                        };
                    }
                    throw error;
                });
        };
    }

    // Export functions to global scope
    window.handleAPIResponse = handleAPIResponse;
    window.handleNetworkError = handleNetworkError;
    window.fetchWithTimeout = fetchWithTimeout;
    window.displayAPIError = displayAPIError;
    window.makeAPICall = makeAPICall;
    window.announceToScreenReader = announceToScreenReader;
    window.validateAPIResponseStructure = validateAPIResponseStructure;
    window.createFallbackResponse = createFallbackResponse;
    window.createRetryMechanism = createRetryMechanism;
    window.createCircuitBreaker = createCircuitBreaker;
    window.createAPICache = createAPICache;

    // Export error classes
    window.APIError = APIError;
    window.NetworkError = NetworkError;
    window.ValidationError = ValidationError;
    window.AuthenticationError = AuthenticationError;
    window.AuthorizationError = AuthorizationError;
    window.TimeoutError = TimeoutError;
    window.ServerError = ServerError;
})();