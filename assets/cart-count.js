/**
 * Global cart count updater
 * This script updates the cart count in the header navigation on all pages
 */

(function() {
    'use strict';

    // Function to update cart count with enhanced validation and fallback
    function updateHeaderCartCount() {
        makeAPICall('/api/cart.php?action=count', {}, {
            timeout: 10000,
            retries: 2,
            context: 'Updating cart count',
            showErrors: false, // Don't show errors for cart count updates
            expectedFields: ['success', 'data'],
            fallbackData: { count: 0 }, // Fallback to 0 items if API fails
            validateStructure: true,
            retryConfig: {
                baseDelay: 500,
                maxDelay: 2000,
                backoffMultiplier: 2,
                jitter: true
            },
            onRetry: (_error, _url, _retriesLeft) => {
                // Retry silently
            },
            onFallback: (_data, _url) => {
                console.warn('Using fallback cart count (0)');
            }
        })
            .then(data => {
                // Validate response structure
                if (!data || typeof data !== 'object') {
                    console.warn('Invalid cart count response structure, using fallback');
                    updateCartDisplay(0, true);
                    return;
                }

                if (data.success) {
                    // Validate count data structure
                    let count = 0;

                    if (data.data && typeof data.data === 'object') {
                        if (typeof data.data.count === 'number' && data.data.count >= 0) {
                            count = data.data.count;
                        } else if (typeof data.data.count === 'string' && !isNaN(parseInt(data.data.count))) {
                            count = parseInt(data.data.count);
                            console.warn('Cart count was string, converted to number:', count);
                        } else {
                            console.warn('Invalid cart count format:', data.data.count);
                        }
                    } else {
                        console.warn('Cart count data missing or invalid structure');
                    }

                    updateCartDisplay(count, data.fallback || data.cached);
                } else {
                    // API returned error, use fallback
                    console.warn('Cart count API returned error:', data.error || data.message);
                    updateCartDisplay(0, true);
                }
            })
            .catch(error => {
                // Handle different error types gracefully
                if (error.name === 'AuthenticationError') {
                    console.warn('User not authenticated for cart count');
                    updateCartDisplay(0, true);
                    return;
                }

                if (error.name === 'NetworkError') {
                    console.warn('Network error updating cart count, using fallback');
                    updateCartDisplay(0, true);
                    return;
                }

                if (error.name === 'TimeoutError') {
                    console.warn('Cart count request timed out, using fallback');
                    updateCartDisplay(0, true);
                    return;
                }

                // Log other errors but don't disrupt the user experience
                console.error('Error updating cart count:', error);
                updateCartDisplay(0, true);
            });
    }

    // Helper function to update cart display with validation
    function updateCartDisplay(count, isFallback = false) {
    // Validate count parameter
        if (typeof count !== 'number' || count < 0 || !Number.isInteger(count)) {
            console.warn('Invalid cart count value:', count, 'using 0');
            count = 0;
        }

        // Update header cart count
        const headerCartElement = document.getElementById('headerCartCount');
        if (headerCartElement) {
            headerCartElement.textContent = count.toString();

            // Add visual indicator for fallback data
            if (isFallback) {
                headerCartElement.setAttribute('title', 'Cart count may not be current');
                headerCartElement.style.opacity = '0.7';
            } else {
                headerCartElement.removeAttribute('title');
                headerCartElement.style.opacity = '1';
            }
        }

        const sidebarCartElement = document.getElementById('sidebarCartCount');
        if (sidebarCartElement) {
            sidebarCartElement.textContent = count.toString();
            if (isFallback) {
                sidebarCartElement.setAttribute('title', 'Cart count may not be current');
                sidebarCartElement.style.opacity = '0.75';
            } else {
                sidebarCartElement.removeAttribute('title');
                sidebarCartElement.style.opacity = '1';
            }
        }

        // Also update the products page cart count if it exists
        const cartCountElement = document.getElementById('cartCount');
        if (cartCountElement) {
            cartCountElement.textContent = count.toString();

            // Add visual indicator for fallback data
            if (isFallback) {
                cartCountElement.setAttribute('title', 'Cart count may not be current');
                cartCountElement.style.opacity = '0.7';
            } else {
                cartCountElement.removeAttribute('title');
                cartCountElement.style.opacity = '1';
            }
        }

        // Announce significant changes to screen readers (but not fallback updates)
        if (!isFallback && count > 0) {
            if (window.announceToScreenReader) {
                window.announceToScreenReader(`Cart updated: ${count} item${count !== 1 ? 's' : ''}`, 'polite');
            }
        }
    }

    // Update cart count when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateHeaderCartCount);
    } else {
        updateHeaderCartCount();
    }

    // Export function for use in other scripts if needed
    window.updateHeaderCartCount = updateHeaderCartCount;
})();
