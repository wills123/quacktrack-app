/**
 * API Response Validation and Fallback Mechanisms
 *
 * This file provides enhanced utilities for validating API responses,
 * handling malformed data, implementing fallback mechanisms, and
 * creating retry patterns with exponential backoff.
 *
 * Features:
 * - Client-side validation for API response structure and required fields
 * - Fallback behavior for malformed or unexpected API responses
 * - Retry mechanisms for transient network errors with exponential backoff
 * - Proper timeout handling for all API calls
 * - Circuit breaker pattern for preventing cascading failures
 * - Response caching for offline fallback support
 *
 * @version 1.0.0
 * @requires api-error-handler.js
 */

(function() {
    'use strict';

    /**
     * Enhanced API client with comprehensive validation and fallback mechanisms
     */
    class EnhancedAPIClient {
        constructor(config = {}) {
            this.config = {
                baseURL: '',
                timeout: 30000,
                retries: 2,
                validateResponses: true,
                enableCache: true,
                enableCircuitBreaker: true,
                ...config
            };

            // Initialize cache if enabled
            if (this.config.enableCache) {
                this.cache = new Map();
                this.cacheConfig = {
                    ttl: 300000, // 5 minutes
                    maxSize: 100,
                    ...config.cacheConfig
                };
            }

            // Initialize circuit breaker if enabled
            if (this.config.enableCircuitBreaker) {
                this.circuitBreaker = {
                    state: 'CLOSED',
                    failureCount: 0,
                    lastFailureTime: null,
                    successCount: 0,
                    ...config.circuitBreakerConfig
                };
            }
        }

        /**
         * Makes an API call with comprehensive validation and error handling
         * @param {string} endpoint - API endpoint
         * @param {Object} options - Request options
         * @param {Object} validationConfig - Validation configuration
         * @returns {Promise} Enhanced API response
         */
        async call(endpoint, options = {}, validationConfig = {}) {
            const url = this.config.baseURL + endpoint;
            const requestConfig = {
                timeout: this.config.timeout,
                retries: this.config.retries,
                validateStructure: this.config.validateResponses,
                ...validationConfig
            };

            // Check circuit breaker
            if (this.config.enableCircuitBreaker && !this.isCircuitClosed()) {
                throw new APIError('Service temporarily unavailable (circuit breaker open)');
            }

            // Check cache first
            if (this.config.enableCache && options.method === 'GET') {
                const cachedResponse = this.getCachedResponse(url, options);
                if (cachedResponse) {
                    return cachedResponse;
                }
            }

            try {
                const response = await makeAPICall(url, options, requestConfig);

                // Update circuit breaker on success
                if (this.config.enableCircuitBreaker) {
                    this.recordSuccess();
                }

                // Cache successful GET responses
                if (this.config.enableCache && options.method === 'GET') {
                    this.cacheResponse(url, options, response);
                }

                return response;
            } catch (error) {
                // Update circuit breaker on failure
                if (this.config.enableCircuitBreaker) {
                    this.recordFailure();
                }

                // Try to return cached data as fallback
                if (this.config.enableCache && options.method === 'GET') {
                    const staleResponse = this.getStaleResponse(url, options);
                    if (staleResponse) {
                        console.warn('API call failed, using stale cached data:', error.message);
                        return {
                            ...staleResponse,
                            stale: true,
                            fallback: true,
                            error: error.message
                        };
                    }
                }

                throw error;
            }
        }

        /**
         * Validates API response structure and content
         * @param {Object} response - API response to validate
         * @param {Object} schema - Validation schema
         * @returns {Object} Validation result
         */
        validateResponse(response, schema = {}) {
            const {
                requiredFields = [],
                optionalFields = [],
                dataType = 'object',
                customValidators = []
            } = schema;

            const errors = [];
            const warnings = [];

            // Basic type validation
            if (typeof response !== dataType) {
                errors.push(`Expected response type ${dataType}, got ${typeof response}`);
                return { isValid: false, errors, warnings };
            }

            // Required fields validation
            requiredFields.forEach(field => {
                if (!Object.prototype.hasOwnProperty.call(response, field)) {
                    errors.push(`Missing required field: ${field}`);
                } else if (response[field] === null || response[field] === undefined) {
                    errors.push(`Required field ${field} is null or undefined`);
                }
            });

            // Optional fields validation (warn if missing but don't fail)
            optionalFields.forEach(field => {
                if (!Object.prototype.hasOwnProperty.call(response, field)) {
                    warnings.push(`Optional field ${field} is missing`);
                }
            });

            // Custom validators
            customValidators.forEach(validator => {
                try {
                    const result = validator(response);
                    if (result !== true) {
                        if (typeof result === 'string') {
                            errors.push(result);
                        } else {
                            errors.push('Custom validation failed');
                        }
                    }
                } catch (validatorError) {
                    errors.push(`Validator error: ${validatorError.message}`);
                }
            });

            return {
                isValid: errors.length === 0,
                errors,
                warnings
            };
        }

        /**
         * Creates a fallback response when API calls fail
         * @param {*} fallbackData - Data to use as fallback
         * @param {string} reason - Reason for fallback
         * @returns {Object} Standardized fallback response
         */
        createFallbackResponse(fallbackData, reason = 'API call failed') {
            return {
                success: true,
                data: fallbackData,
                message: 'Using fallback data',
                fallback: true,
                fallbackReason: reason,
                timestamp: new Date().toISOString()
            };
        }

        /**
         * Circuit breaker methods
         */
        isCircuitClosed() {
            if (!this.circuitBreaker) {
                return true;
            }

            const { state, lastFailureTime, resetTimeout = 60000 } = this.circuitBreaker;
            const now = Date.now();

            if (state === 'OPEN') {
                if (lastFailureTime && (now - lastFailureTime) > resetTimeout) {
                    this.circuitBreaker.state = 'HALF_OPEN';
                    return true;
                }
                return false;
            }

            return true;
        }

        recordSuccess() {
            if (!this.circuitBreaker) {
                return;
            }

            if (this.circuitBreaker.state === 'HALF_OPEN') {
                this.circuitBreaker.successCount++;
                if (this.circuitBreaker.successCount >= 2) {
                    this.circuitBreaker.state = 'CLOSED';
                    this.circuitBreaker.failureCount = 0;
                    this.circuitBreaker.successCount = 0;
                }
            }
        }

        recordFailure() {
            if (!this.circuitBreaker) {
                return;
            }

            this.circuitBreaker.failureCount++;
            this.circuitBreaker.lastFailureTime = Date.now();
            this.circuitBreaker.successCount = 0;

            const failureThreshold = this.circuitBreaker.failureThreshold || 5;
            if (this.circuitBreaker.failureCount >= failureThreshold) {
                this.circuitBreaker.state = 'OPEN';
            }
        }

        /**
         * Cache methods
         */
        getCacheKey(url, options) {
            return `${url}:${JSON.stringify(options)}`;
        }

        getCachedResponse(url, options) {
            if (!this.cache) {
                return null;
            }

            const key = this.getCacheKey(url, options);
            const cached = this.cache.get(key);

            if (cached) {
                const now = Date.now();
                if (now - cached.timestamp < this.cacheConfig.ttl) {
                    return {
                        ...cached.data,
                        cached: true,
                        cacheAge: now - cached.timestamp
                    };
                }
            }

            return null;
        }

        getStaleResponse(url, options) {
            if (!this.cache) {
                return null;
            }

            const key = this.getCacheKey(url, options);
            const cached = this.cache.get(key);

            return cached ? cached.data : null;
        }

        cacheResponse(url, options, response) {
            if (!this.cache) {
                return;
            }

            const key = this.getCacheKey(url, options);

            // Remove oldest entries if cache is full
            if (this.cache.size >= this.cacheConfig.maxSize) {
                const firstKey = this.cache.keys().next().value;
                this.cache.delete(firstKey);
            }

            this.cache.set(key, {
                data: response,
                timestamp: Date.now()
            });
        }

        /**
         * Clears the cache
         */
        clearCache() {
            if (this.cache) {
                this.cache.clear();
            }
        }

        /**
         * Gets cache statistics
         */
        getCacheStats() {
            if (!this.cache) {
                return null;
            }

            return {
                size: this.cache.size,
                maxSize: this.cacheConfig.maxSize,
                ttl: this.cacheConfig.ttl
            };
        }
    }

    /**
     * Predefined validation schemas for common API responses
     */
    const ValidationSchemas = {
        // Standard success response
        standardResponse: {
            requiredFields: ['success'],
            optionalFields: ['data', 'message', 'error'],
            customValidators: [
                (response) => typeof response.success === 'boolean' || 'success field must be boolean'
            ]
        },

        // Product list response
        productList: {
            requiredFields: ['success', 'data'],
            customValidators: [
                (response) => Array.isArray(response.data) || 'data field must be an array',
                (response) => response.data.every(item =>
                    item.id && item.name && item.product_id
                ) || 'All products must have id, name, and product_id fields'
            ]
        },

        // Stock operation response
        stockOperation: {
            requiredFields: ['success', 'message'],
            optionalFields: ['data'],
            customValidators: [
                (response) => typeof response.message === 'string' || 'message field must be string'
            ]
        },

        // User authentication response
        authResponse: {
            requiredFields: ['success'],
            optionalFields: ['user', 'token', 'redirect'],
            customValidators: [
                (response) => {
                    if (response.success && response.user) {
                        return response.user.id && response.user.username || 'User object must have id and username';
                    }
                    return true;
                }
            ]
        }
    };

    /**
     * Utility functions for common API patterns
     */
    const APIUtils = {
        /**
         * Creates a standardized error response
         */
        createErrorResponse(message, code = null, fields = null) {
            return {
                success: false,
                error: message,
                code,
                fields,
                timestamp: new Date().toISOString()
            };
        },

        /**
         * Creates a standardized success response
         */
        createSuccessResponse(data = null, message = null) {
            return {
                success: true,
                data,
                message,
                timestamp: new Date().toISOString()
            };
        },

        /**
         * Sanitizes API response data
         */
        sanitizeResponse(response) {
            if (typeof response !== 'object' || response === null) {
                return response;
            }

            const sanitized = {};
            for (const [key, value] of Object.entries(response)) {
                if (typeof value === 'string') {
                    // Basic XSS prevention
                    sanitized[key] = value.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
                } else if (typeof value === 'object' && value !== null) {
                    sanitized[key] = this.sanitizeResponse(value);
                } else {
                    sanitized[key] = value;
                }
            }
            return sanitized;
        },

        /**
         * Validates required environment for API calls
         */
        validateEnvironment() {
            const required = ['makeAPICall', 'handleAPIResponse', 'fetchWithTimeout'];
            const missing = required.filter(fn => typeof window[fn] !== 'function');

            if (missing.length > 0) {
                throw new Error(`Missing required functions: ${missing.join(', ')}. Please include api-error-handler.js`);
            }

            return true;
        }
    };

    // Initialize environment validation
    try {
        APIUtils.validateEnvironment();
    } catch (error) {
        console.error('API Response Validation initialization failed:', error.message);
    }

    // Export to global scope
    window.EnhancedAPIClient = EnhancedAPIClient;
    window.ValidationSchemas = ValidationSchemas;
    window.APIUtils = APIUtils;

    // Create default client instance
    window.apiClient = new EnhancedAPIClient({
        timeout: 30000,
        retries: 2,
        validateResponses: true,
        enableCache: true,
        enableCircuitBreaker: true
    });
})();