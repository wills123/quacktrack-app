/**
 * Stock Management JavaScript
 * Handles product search, selection, and form interactions for van stock management
 * Optimized for performance and memory management
 */

// Performance utilities
function debounce(func, wait, immediate) {
    let timeout;
    return function executedFunction() {
        const args = Array.prototype.slice.call(arguments);
        const later = function() {
            timeout = null;
            if (!immediate) {
                func.apply(this, args);
            }
        };
        const callNow = immediate && !timeout;
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
        if (callNow) {
            func.apply(this, args);
        }
    };
}

function throttle(func, limit) {
    let inThrottle;
    return function() {
        const args = Array.prototype.slice.call(arguments);
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => {
                inThrottle = false;
            }, limit);
        }
    };
}

// Memory management utilities
function addEventListenerWithCleanup(element, event, handler, options) {
    if (!element) {
        return;
    }

    element.addEventListener(event, handler, options);

    // Store for cleanup
    if (!window.stockEventListeners) {
        window.stockEventListeners = [];
    }
    window.stockEventListeners.push({ element, event, handler, options });
}

function cleanupEventListeners() {
    try {
        if (window.stockEventListeners) {
            window.stockEventListeners.forEach(({ element, event, handler, options }) => {
                try {
                    if (element && element.removeEventListener) {
                        element.removeEventListener(event, handler, options);
                    }
                } catch (removeError) {
                    if (window.AppLogger) {
                        AppLogger.error('Error removing event listener:', removeError);
                    }
                }
            });
            window.stockEventListeners = [];
        }
    } catch (cleanupError) {
        if (window.AppLogger) {
            AppLogger.error('Error cleaning up event listeners:', cleanupError);
        }
    }
}

// DOM optimization utilities
function batchDOMUpdates(callback) {
    requestAnimationFrame(() => {
        callback();
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Performance optimization: Use WeakMap for element data storage
    const elementData = new WeakMap();

    // Add New Item Modal functionality
    const addItemModal = document.getElementById('addItemModal');
    const addNewItemBtn = document.getElementById('addNewItemBtn');
    const addItemForm = document.getElementById('addItemForm');
    const addItemCancel = document.getElementById('addItemCancel');
    const closeAddItemButton = addItemModal?.querySelector('.close-button');
    const productSearch = document.getElementById('productSearch');
    const productList = document.getElementById('productList');
    const selectedProductId = document.getElementById('selectedProductId');
    const addItemSubmit = document.getElementById('addItemSubmit');
    const addItemQuantity = document.getElementById('addItemQuantity');
    const vanProdDisplay = document.getElementById('vanProdDisplay');
    const hasLegacyProductUI = !!(productSearch && productList);

    // Global variables for product data
    let allProducts = [];
    let filteredProducts = [];
    let isLoadingProducts = false;

    // Track active requests for cleanup
    let activeRequests = [];

    // Adjustment Modal elements
    const adjustmentModal = document.getElementById('adjustmentModal');
    const modalProdId = document.getElementById('modalProdId');
    const modalProductName = document.getElementById('modalProductName');
    const modalChangeQty = document.getElementById('modalChangeQty');
    const modalReason = document.getElementById('modalReason');
    const modalStoreId = document.getElementById('modalStoreId');
    const modalNote = document.getElementById('modalNote');
    const adjustmentForm = document.getElementById('adjustmentForm');

    // Van Picker elements
    const vanProductPickerModal = document.getElementById('vanProductPickerModal');
    const vanPickerSearch = document.getElementById('vanPickerSearch');
    const vanPickerList = document.getElementById('vanPickerList');
    const openVanProductPickerBtn = document.getElementById('openVanProductPickerBtn');
    const openVanScannerBtnPrimary = document.getElementById('openVanScannerBtnPrimary');

    // Van Scanner elements
    const vanScannerModal = document.getElementById('vanScannerModal');
    const vanCloseScannerButton = document.getElementById('vanCloseScannerButton');
    const vanScannerVideo = document.getElementById('vanScannerVideo');
    const vanScannerResult = document.getElementById('vanScannerResult');
    const vanStopScannerButton = document.getElementById('vanStopScannerButton');
    const vanZoomInBtn = document.getElementById('vanZoomInBtn');
    const vanZoomOutBtn = document.getElementById('vanZoomOutBtn');
    const vanZoomLevelDisplay = document.getElementById('vanZoomLevel');
    const vanScannerCloseFooter = document.getElementById('vanScannerCloseFooter');

    // Van picker data
    let vanProducts = [];
    let vanCodeReader = null;
    let vanCurrentStream = null;
    let vanCurrentZoom = 1;

    // Performance optimization: Debounced functions
    const debouncedSearch = debounce(performSearch, 200);
    const debouncedResize = debounce(handleResize, 250);

    // Filters toggle (stock page layout)
    const filtersToggle = document.getElementById('filtersToggle');
    const filtersBackdrop = document.getElementById('filtersBackdrop');
    const stockLayout = document.getElementById('stockLayout');
    const stockSort = document.getElementById('stockSort');
    const stockFilterForm = document.querySelector('.stock-filter-form');
    const openScannerHeader = document.getElementById('openVanScannerHeader');
    const emptyStateAddItem = document.getElementById('emptyStateAddItem');

    const filtersStorageKey = 'van_stock_filters_open';

    function setFiltersOpen(isOpen) {
        if (!stockLayout) {
            return;
        }
        stockLayout.dataset.filtersOpen = isOpen ? 'true' : 'false';
        if (filtersToggle) {
            filtersToggle.textContent = isOpen ? 'Hide filters' : 'Filters';
        }
        try {
            localStorage.setItem(filtersStorageKey, isOpen ? '1' : '0');
        } catch (e) {
            // Ignore storage errors
        }
    }

    if (filtersToggle && stockLayout) {
        let initialOpen = true;
        try {
            const stored = localStorage.getItem(filtersStorageKey);
            if (stored === '0') {
                initialOpen = false;
            }
        } catch (e) {
            // Ignore storage errors
        }
        setFiltersOpen(initialOpen);

        filtersToggle.addEventListener('click', () => {
            const isOpen = stockLayout.dataset.filtersOpen !== 'false';
            setFiltersOpen(!isOpen);
        });
    }

    if (filtersBackdrop && stockLayout) {
        filtersBackdrop.addEventListener('click', () => {
            setFiltersOpen(false);
        });
    }

    if (stockSort && stockFilterForm) {
        stockSort.addEventListener('change', () => {
            stockFilterForm.submit();
        });
    }

    if (openScannerHeader && addNewItemBtn && openVanScannerBtnPrimary) {
        openScannerHeader.addEventListener('click', () => {
            addNewItemBtn.click();
            setTimeout(() => {
                openVanScannerBtnPrimary.click();
            }, 300);
        });
    }

    if (emptyStateAddItem && addNewItemBtn) {
        emptyStateAddItem.addEventListener('click', () => {
            addNewItemBtn.click();
        });
    }

    // Detail page transfer forms (AJAX submit)
    document.querySelectorAll('.detail-form-ajax').forEach(form => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                setButtonLoading(submitButton, 'Saving...');
            }

            const formData = new FormData(form);
            const token = resolveCsrfToken(form);
            if (token && !formData.has('csrf')) {
                formData.append('csrf', token);
            }

            const actionUrl = form.getAttribute('action')
                || (typeof form.action === 'string' ? form.action : (form.action && form.action.value));

            fetch(actionUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok || !data.success) {
                        const message = data?.message || data?.error || 'Transfer failed.';
                        notify(message, 'error');
                        return;
                    }
                    notify(data.message || 'Transfer completed.', 'success');
                    setTimeout(() => window.location.reload(), 600);
                })
                .catch(() => {
                    notify('Network error. Please try again.', 'error');
                })
                .finally(() => {
                    if (submitButton) {
                        clearButtonLoading(submitButton);
                    }
                });
        });
    });

    // Utility function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Utility function to announce to screen readers
    function announceToScreenReader(message, priority = 'polite') {
        if (window.announceToScreenReader) {
            window.announceToScreenReader(message, priority);
        } else {
            // Fallback: create a live region
            const liveRegion = document.createElement('div');
            liveRegion.setAttribute('aria-live', priority);
            liveRegion.setAttribute('aria-atomic', 'true');
            liveRegion.style.position = 'absolute';
            liveRegion.style.left = '-10000px';
            liveRegion.style.width = '1px';
            liveRegion.style.height = '1px';
            liveRegion.style.overflow = 'hidden';
            liveRegion.textContent = message;
            document.body.appendChild(liveRegion);

            setTimeout(() => {
                document.body.removeChild(liveRegion);
            }, 1000);
        }
    }

    /**
     * Resolve CSRF token from multiple sources
     * @param {HTMLFormElement} [form] - Optional form to check for CSRF field
     * @returns {string|null} The CSRF token or null if not found
     */
    function resolveCsrfToken(form) {
        // Try global function first
        if (typeof window.getCSRFToken === 'function') {
            const token = window.getCSRFToken();
            if (token) {
                return token;
            }
        }

        // Try form field
        if (form) {
            const csrfField = form.querySelector('input[name="csrf"]');
            if (csrfField?.value) {
                return csrfField.value;
            }
        }

        // Try any form on page
        const anyCSRFField = document.querySelector('input[name="csrf"]');
        if (anyCSRFField?.value) {
            return anyCSRFField.value;
        }

        return null;
    }

    /**
     * Show a modal with proper accessibility handling
     * @param {HTMLElement} modal - The modal element to show
     * @param {string} [display='block'] - CSS display value ('block' or 'flex')
     */
    function showModal(modal, display = 'block') {
        if (!modal) {
            return;
        }

        if (window.ModalManager) {
            window.ModalManager.show(modal);
        } else {
            modal.style.display = display;
            modal.removeAttribute('aria-hidden');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('role', 'dialog');
            modal.classList.add('show');

            const modalContent = modal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.focus();
            }
        }
    }

    /**
     * Hide a modal with proper accessibility handling
     * @param {HTMLElement} modal - The modal element to hide
     */
    function hideModal(modal) {
        if (!modal) {
            return;
        }

        if (window.ModalManager) {
            window.ModalManager.hide(modal);
        } else {
            modal.style.display = 'none';
            modal.classList.remove('show');
            modal.removeAttribute('aria-modal');

            // Delay aria-hidden to allow focus to move
            setTimeout(() => {
                if (!modal.contains(document.activeElement)) {
                    modal.setAttribute('aria-hidden', 'true');
                }
            }, 100);
        }
    }

    /**
     * Display a notification message with fallback to alert
     * @param {string} message - The message to display
     * @param {string} [type='info'] - Notification type: 'success', 'error', 'warning', 'info'
     */
    function notify(message, type = 'info') {
        if (window.QuackNotify) {
            switch (type) {
            case 'success': QuackNotify.success(message); break;
            case 'error': QuackNotify.error(message); break;
            case 'warning': QuackNotify.warning(message); break;
            default: QuackNotify.info(message);
            }
        } else {
            alert(message);
        }
    }

    // Open Add Item Modal with optimized performance
    if (addNewItemBtn) {
        const openModalHandler = () => {
            const modalEl = addItemModal
                || document.getElementById(addNewItemBtn.dataset.modalTarget || 'addItemModal');
            const formEl = addItemForm || modalEl?.querySelector('form');
            const submitEl = addItemSubmit || modalEl?.querySelector('#addItemSubmit');
            const productIdEl = selectedProductId || modalEl?.querySelector('#selectedProductId');

            if (!modalEl) {
                if (window.AppLogger) {
                    AppLogger.error('Add item modal not found in DOM');
                    const modalIds = Array.from(document.querySelectorAll('.modal'))
                        .map(el => el.id)
                        .filter(Boolean);
                    AppLogger.error('Available modal IDs:', modalIds);
                }
                notify('Add item modal not found. Please refresh the page.', 'error');
                return;
            }

            // Batch DOM updates for better performance
            batchDOMUpdates(() => {
                // Reset form
                if (formEl) {
                    formEl.reset();
                }
                if (productIdEl) {
                    productIdEl.value = '';
                }
                if (submitEl) {
                    submitEl.disabled = true;
                }
                if (typeof clearFormValidation === 'function') {
                    clearFormValidation();
                }

                // Clear any previous messages efficiently
                if (modalEl) {
                    const messages = modalEl.querySelectorAll('.success-message, .error-message-modal');
                    messages.forEach(msg => msg.remove());
                }

                // Load products if not already loaded (legacy picker only)
                if (hasLegacyProductUI) {
                    if (allProducts.length === 0 && !isLoadingProducts) {
                        loadProducts();
                    } else if (allProducts.length > 0) {
                        displayProducts(allProducts);
                    }
                }

                // Show modal with proper ARIA attributes
                if (window.ModalManager) {
                    window.ModalManager.show(modalEl);
                }

                document.body.classList.add('modal-open');
                modalEl.style.display = 'flex';
                modalEl.style.pointerEvents = 'auto';
                modalEl.classList.add('show');
                modalEl.removeAttribute('aria-hidden');
                modalEl.setAttribute('aria-modal', 'true');
                modalEl.setAttribute('role', 'dialog');

                // Focus the search input for better UX
                requestAnimationFrame(() => {
                    if (productSearch) {
                        productSearch.focus();
                    }
                });

                // Announce to screen readers
                announceToScreenReader('Opening add new item dialog', 'assertive');
            });
        };

        addEventListenerWithCleanup(addNewItemBtn, 'click', openModalHandler);
    }

    // Close Add Item Modal with enhanced accessibility
    function closeAddItemModal() {
        if (!addItemModal) {
            return;
        }
        // Clear any success/error messages
        const successMessage = addItemModal.querySelector('.success-message');
        const errorMessage = addItemModal.querySelector('.error-message-modal');
        if (successMessage) {
            successMessage.remove();
        }
        if (errorMessage) {
            errorMessage.remove();
        }

        // Clear button loading state and reset to disabled
        clearButtonLoading(addItemSubmit);
        if (addItemSubmit) {
            addItemSubmit.disabled = true;
        }

        if (window.ModalManager) {
            window.ModalManager.hide(addItemModal);
        } else {
            // Enhanced modal closing with proper accessibility
            addItemModal.classList.remove('show');
            addItemModal.style.pointerEvents = 'none'; // Immediately prevent interaction

            // Wait for animation before hiding
            setTimeout(() => {
                addItemModal.style.display = 'none';
                addItemModal.style.pointerEvents = 'none'; // Ensure stays non-interactive
                addItemModal.removeAttribute('aria-modal');

                // Only set aria-hidden if no element inside has focus
                if (!addItemModal.contains(document.activeElement)) {
                    addItemModal.setAttribute('aria-hidden', 'true');
                }

                // Restore body scroll
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
            }, 300);

            // Return focus to the button that opened the modal immediately
            if (addNewItemBtn) {
                addNewItemBtn.focus();
                announceToScreenReader('Add item dialog closed', 'polite');
            }
        }
    }

    // Close modal event listeners
    if (closeAddItemButton) {
        closeAddItemButton.addEventListener('click', closeAddItemModal);
    }
    if (addItemCancel) {
        addItemCancel.addEventListener('click', closeAddItemModal);
    }

    // Close modal when clicking outside
    window.addEventListener('click', (event) => {
        if (event.target === addItemModal) {
            closeAddItemModal();
        }
    });

    // Enhanced keyboard navigation for modal
    if (addItemModal) {
        addItemModal.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeAddItemModal();
            }
        });
    }

    // Product search functionality with server-side search and debouncing
    if (productSearch) {
        const searchHandler = function(e) {
            const searchTerm = e.target.value.trim();
            debouncedSearch(searchTerm);
        };

        addEventListenerWithCleanup(productSearch, 'input', searchHandler);

        // Enhanced keyboard navigation
        productSearch.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                e.target.value = '';
                // Reload products without search
                loadProducts('');
                announceToScreenReader('Search cleared', 'polite');
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                // Focus first product option
                const firstOption = productList.querySelector('.product-option');
                if (firstOption) {
                    firstOption.focus();
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                // Select first product if only one result
                if (filteredProducts.length === 1) {
                    const firstOption = productList.querySelector('.product-option');
                    if (firstOption) {
                        firstOption.click();
                        // Move focus to quantity field for better UX
                        const quantityField = document.getElementById('addItemQuantity');
                        if (quantityField) {
                            setTimeout(() => {
                                quantityField.focus();
                            }, 100);
                        }
                    }
                } else if (filteredProducts.length > 1) {
                    // Focus first product option to allow selection
                    const firstOption = productList.querySelector('.product-option');
                    if (firstOption) {
                        firstOption.focus();
                    }
                }
            }
        });

        // Add search icon and clear button functionality
        const searchContainer = productSearch.parentElement;
        if (searchContainer && !searchContainer.querySelector('.search-clear')) {
            const clearButton = document.createElement('button');
            clearButton.type = 'button';
            clearButton.className = 'search-clear';
            clearButton.textContent = 'X';
            clearButton.setAttribute('aria-label', 'Clear search');
            clearButton.style.cssText = `
                position: absolute;
                right: 8px;
                top: 50%;
                transform: translateY(-50%);
                background: none;
                border: none;
                font-size: 18px;
                color: #6c757d;
                cursor: pointer;
                padding: 0;
                width: 20px;
                height: 20px;
                display: none;
            `;

            searchContainer.style.position = 'relative';
            searchContainer.appendChild(clearButton);

            // Show/hide clear button based on input content
            productSearch.addEventListener('input', () => {
                clearButton.style.display = productSearch.value ? 'block' : 'none';
            });

            // Clear search when clear button is clicked
            clearButton.addEventListener('click', () => {
                productSearch.value = '';
                // Reload products without search
                loadProducts('');
                // Update clear button visibility
                clearButton.style.display = 'none';
                productSearch.focus();
            });
        }
    }

    // Server-side search function with debouncing
    function performSearch(searchTerm) {
        // Use server-side search for better performance with large product lists
        loadProducts(searchTerm);
    }

    // Load products from server with optional search term
    function loadProducts(searchTerm = '') {
        if (!productList) {
            isLoadingProducts = false;
            return;
        }

        // Cancel any pending product requests
        activeRequests.forEach(controller => {
            try {
                controller.abort();
            } catch (e) {
                // Ignore abort errors
            }
        });
        activeRequests = [];

        isLoadingProducts = true;

        // Enhanced loading state with better accessibility
        productList.innerHTML = `
            <div class="loading-message" role="status" aria-live="polite">
                ${searchTerm ? 'Searching...' : 'Loading products...'}
            </div>
        `;

        // Disable submit button while loading products
        if (addItemSubmit) {
            addItemSubmit.disabled = true;
        }

        // Use simple fetch with timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 15000);

        // Track this request for cleanup
        activeRequests.push(controller);

        // Build URL with search parameter if provided
        const url = searchTerm
            ? `/stock?action=get_products&search=${encodeURIComponent(searchTerm)}&limit=100`
            : '/stock?action=get_products&limit=100';

        fetch(url, {
            signal: controller.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => {
                clearTimeout(timeoutId);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Server returned non-JSON response');
                }

                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const products = data.data || [];
                    if (Array.isArray(products)) {
                        // Validate each product has required fields
                        allProducts = products.filter(product => {
                            if (!product || typeof product !== 'object') {
                                if (window.AppLogger) {
                                    AppLogger.warn('Invalid product object:', product);
                                }
                                return false;
                            }

                            const requiredFields = ['id', 'name', 'product_id'];
                            const hasRequiredFields = requiredFields.every(field =>
                                Object.prototype.hasOwnProperty.call(product, field) && product[field] !== null && product[field] !== undefined
                            );

                            if (!hasRequiredFields) {
                                if (window.AppLogger) {
                                    AppLogger.warn('Product missing required fields:', product);
                                }
                                return false;
                            }

                            return true;
                        });
                    } else {
                        if (window.AppLogger) {
                            AppLogger.warn('Products data is not an array');
                        }
                        allProducts = [];
                    }

                    filteredProducts = allProducts.slice();
                    displayProducts(allProducts);

                    const productCount = allProducts.length;
                    const successMessage = searchTerm
                        ? `${productCount} product${productCount !== 1 ? 's' : ''} found`
                        : `${productCount} product${productCount !== 1 ? 's' : ''} loaded`;
                    announceToScreenReader(successMessage, 'polite');

                    // Enable submit button if product is selected, otherwise keep disabled
                    if (addItemSubmit) {
                        if (selectedProductId.value) {
                            addItemSubmit.disabled = false;
                        } else {
                            addItemSubmit.disabled = true;
                        }
                    }
                } else {
                    throw new Error(data.error || data.message || 'Failed to load products');
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                if (window.AppLogger) {
                    AppLogger.error('Error loading products:', error);
                }

                // Re-enable submit button (it will be disabled again by validation if needed)
                if (addItemSubmit) {
                    addItemSubmit.disabled = true; // Keep disabled since no products loaded
                }

                let userMessage = 'Failed to load products';

                if (error.name === 'AbortError') {
                    userMessage = 'Request timed out. Please try again.';
                } else if (error.message.includes('fetch')) {
                    userMessage = 'Connection failed. Please check your internet connection and try again.';
                } else if (error.message) {
                    userMessage = error.message;
                }

                showProductError(userMessage);
                announceToScreenReader('Error loading products: ' + userMessage, 'assertive');
            })
            .finally(() => {
                isLoadingProducts = false;
            });
    }

    // Optimized display products function with virtual scrolling for large lists
    function displayProducts(products) {
        if (!productList) {
            return;
        }

        if (products.length === 0) {
            productList.innerHTML = '<div class="no-products-message">No products found matching your search.</div>';
            return;
        }

        // Use DocumentFragment for efficient DOM manipulation
        batchDOMUpdates(() => {
            const fragment = document.createDocumentFragment();

            // Clear existing content efficiently
            productList.innerHTML = '';

            // Create products in batches to avoid blocking UI
            const batchSize = 20;
            let currentBatch = 0;

            function createBatch() {
                const start = currentBatch * batchSize;
                const end = Math.min(start + batchSize, products.length);

                for (let i = start; i < end; i++) {
                    const product = products[i];
                    const productElement = createProductElement(product);
                    fragment.appendChild(productElement);
                }

                currentBatch++;

                if (end < products.length) {
                    // Schedule next batch
                    requestAnimationFrame(createBatch);
                } else {
                    // All products created, add to DOM
                    productList.appendChild(fragment);

                    // Add event listeners using event delegation for better performance
                    setupProductEventDelegation();
                }
            }

            createBatch();
        });
    }

    // Optimized product element creation
    function createProductElement(product) {
        const productDiv = document.createElement('div');
        productDiv.className = 'product-option';
        productDiv.tabIndex = 0;
        productDiv.setAttribute('role', 'radio');
        productDiv.setAttribute('aria-checked', 'false');
        productDiv.dataset.productId = product.id;
        productDiv.dataset.productName = product.name;
        productDiv.setAttribute('aria-labelledby', `product-${product.id}-name`);
        productDiv.setAttribute('aria-describedby', `product-${product.id}-id`);

        // Create radio input
        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'product_selection';
        radio.value = product.id;
        radio.id = `product-radio-${product.id}`;
        radio.setAttribute('aria-hidden', 'true');
        radio.tabIndex = -1;

        // Create image element
        let imageElement;
        if (product.image_path) {
            imageElement = document.createElement('img');
            imageElement.src = product.image_path;
            imageElement.alt = '';
            imageElement.className = 'product-option-image';
            imageElement.loading = 'lazy';
        } else {
            imageElement = document.createElement('div');
            imageElement.className = 'product-option-image product-option-placeholder';
            imageElement.setAttribute('aria-hidden', 'true');
            imageElement.innerHTML = '<span>No Image</span>';
        }

        // Create info container
        const infoDiv = document.createElement('div');
        infoDiv.className = 'product-option-info';

        const nameDiv = document.createElement('div');
        nameDiv.className = 'product-option-name';
        nameDiv.id = `product-${product.id}-name`;
        nameDiv.textContent = product.name;

        // Add title attribute for tooltip on long names
        if (product.name.length > 40) {
            nameDiv.setAttribute('title', product.name);
        }

        const idDiv = document.createElement('div');
        idDiv.className = 'product-option-id';
        idDiv.id = `product-${product.id}-id`;
        idDiv.textContent = `ID: ${product.product_id}`;

        // Add title attribute for tooltip on long IDs
        if (product.product_id.length > 20) {
            idDiv.setAttribute('title', `ID: ${product.product_id}`);
        }

        // Assemble elements
        infoDiv.appendChild(nameDiv);
        infoDiv.appendChild(idDiv);

        productDiv.appendChild(radio);
        productDiv.appendChild(imageElement);
        productDiv.appendChild(infoDiv);

        return productDiv;
    }

    // Event delegation for better performance
    function setupProductEventDelegation() {
        // Remove existing delegation if any
        if (productList.productDelegationHandler) {
            productList.removeEventListener('click', productList.productDelegationHandler);
            productList.removeEventListener('keydown', productList.productDelegationHandler);
        }

        // Click delegation
        productList.productDelegationHandler = (e) => {
            const productOption = e.target.closest('.product-option');
            if (productOption) {
                if (e.type === 'click') {
                    selectProduct({ currentTarget: productOption });
                } else if (e.type === 'keydown') {
                    // Create event object with currentTarget
                    const eventObj = {
                        key: e.key,
                        preventDefault: e.preventDefault.bind(e),
                        currentTarget: productOption
                    };
                    handleProductKeydown(eventObj);
                }
            }
        };

        addEventListenerWithCleanup(productList, 'click', productList.productDelegationHandler);
        addEventListenerWithCleanup(productList, 'keydown', productList.productDelegationHandler);
    }

    // Handle product selection
    function selectProduct(event) {
        const option = event.currentTarget;
        const productId = option.dataset.productId;
        const productName = option.dataset.productName;

        // Remove selection from all options
        productList.querySelectorAll('.product-option').forEach(opt => {
            opt.classList.remove('selected');
            opt.setAttribute('aria-checked', 'false');
            const radio = opt.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = false;
            }
        });

        // Select this option
        option.classList.add('selected');
        option.setAttribute('aria-checked', 'true');
        const radio = option.querySelector('input[type="radio"]');
        if (radio) {
            radio.checked = true;
        }

        // Update hidden field and enable submit button
        selectedProductId.value = productId;
        addItemSubmit.disabled = false;

        // Clear any previous validation errors for product selection
        clearFieldError('productList');

        // Announce selection to screen readers
        announceToScreenReader(`Selected ${productName}`, 'assertive');
    }

    // Handle keyboard navigation for product selection
    function handleProductKeydown(event) {
        const option = event.currentTarget;

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            selectProduct(event);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const options = Array.from(productList.querySelectorAll('.product-option'));
            const currentIndex = options.indexOf(option);
            let nextIndex;

            if (event.key === 'ArrowDown') {
                nextIndex = currentIndex < options.length - 1 ? currentIndex + 1 : 0;
            } else {
                nextIndex = currentIndex > 0 ? currentIndex - 1 : options.length - 1;
            }

            if (options[nextIndex]) {
                options[nextIndex].focus();
            }
        }
    }

    // Show product loading error
    function showProductError(message) {
        if (productList) {
            productList.innerHTML = `
                <div class="error-message" role="alert">
                    <p>${escapeHtml(message)}</p>
                    <button type="button" class="btn btn-secondary btn-sm" id="retryLoadProducts">
                        Try Again
                    </button>
                </div>
            `;

            // Add event listener to retry button
            const retryButton = document.getElementById('retryLoadProducts');
            if (retryButton) {
                retryButton.addEventListener('click', () => {
                    allProducts = [];
                    filteredProducts = [];
                    loadProducts();
                });
            }
        }
    }

    // Enhanced form validation functions with better accessibility
    function showFieldError(fieldId, message) {
        const field = document.getElementById(fieldId);
        if (!field) {
            return;
        }

        const formGroup = field.closest('.form-group') || field.parentElement;
        formGroup.classList.add('error');
        formGroup.classList.remove('success');

        // Find or create error message element
        let errorElement = formGroup.querySelector('.error-message');
        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.className = 'error-message';
            errorElement.setAttribute('role', 'alert');
            errorElement.setAttribute('aria-live', 'assertive'); // Changed to assertive for immediate announcement
            errorElement.id = fieldId + '-error';

            // Insert after the field or its help text
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

        // Ensure proper aria-describedby relationship
        const existingDescribedBy = field.getAttribute('aria-describedby') || '';
        const errorId = errorElement.id;

        if (!existingDescribedBy.includes(errorId)) {
            const newDescribedBy = existingDescribedBy ? `${existingDescribedBy} ${errorId}` : errorId;
            field.setAttribute('aria-describedby', newDescribedBy);
        }

        // Announce error to screen readers
        announceToScreenReader(`Error in ${field.name || fieldId}: ${message}`, 'assertive');
    }

    function clearFieldError(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) {
            return;
        }

        const formGroup = field.closest('.form-group') || field.parentElement;
        formGroup.classList.remove('error');

        const errorElement = formGroup.querySelector('.error-message');
        if (errorElement) {
            // Remove error ID from aria-describedby before removing element
            const existingDescribedBy = field.getAttribute('aria-describedby') || '';
            const errorId = errorElement.id;

            if (existingDescribedBy.includes(errorId)) {
                const newDescribedBy = existingDescribedBy
                    .split(' ')
                    .filter(id => id !== errorId)
                    .join(' ')
                    .trim();

                if (newDescribedBy) {
                    field.setAttribute('aria-describedby', newDescribedBy);
                } else {
                    field.removeAttribute('aria-describedby');
                }
            }

            errorElement.remove();
        }

        // Update field ARIA attributes
        field.setAttribute('aria-invalid', 'false');
    }

    function clearFormValidation() {
        if (!addItemForm) {
            return;
        }

        addItemForm.querySelectorAll('.form-group').forEach(group => {
            group.classList.remove('error', 'success');
        });

        addItemForm.querySelectorAll('.error-message').forEach(error => {
            error.remove();
        });

        addItemForm.querySelectorAll('input, select, textarea').forEach(field => {
            field.setAttribute('aria-invalid', 'false');
            field.removeAttribute('aria-describedby');
        });
    }

    // Form validation and submission
    if (addItemForm) {
        addItemForm.addEventListener('submit', (e) => {
            e.preventDefault(); // Always prevent default to handle with AJAX

            let isValid = true;

            // Clear previous validation states
            clearFormValidation();

            // Validate product selection
            if (!selectedProductId.value) {
                const productFieldId = productSearch ? 'productList' : (vanProdDisplay ? 'vanProdDisplay' : 'selectedProductId');
                showFieldError(productFieldId, 'Please select a product');
                isValid = false;

                // Focus the product search field for better UX
                if (productSearch) {
                    productSearch.focus();
                } else if (vanProdDisplay) {
                    vanProdDisplay.focus();
                }
            }

            // Validate quantity
            const quantity = document.getElementById('addItemQuantity');
            if (quantity) {
                const quantityValue = parseInt(quantity.value);
                if (!quantity.value || quantityValue < 1 || isNaN(quantityValue)) {
                    showFieldError('addItemQuantity', 'Please enter a valid quantity (1 or more)');
                    isValid = false;
                }
            }

            // Validate store selection
            const storeId = document.getElementById('addItemStoreId');
            if (storeId && !storeId.value) {
                showFieldError('addItemStoreId', 'Please select a location');
                isValid = false;
            }

            if (!isValid) {
                // Count errors and create summary
                const errorFields = addItemForm.querySelectorAll('.form-group.error');
                const errorCount = errorFields.length;

                // Focus the first error field
                const firstError = addItemForm.querySelector('.form-group.error input, .form-group.error select');
                if (firstError) {
                    firstError.focus();
                }

                // Create comprehensive error announcement
                const errorMessages = Array.from(errorFields).map(group => {
                    const errorMsg = group.querySelector('.error-message');
                    return errorMsg ? errorMsg.textContent : 'Unknown error';
                });

                const announcement = `Form has ${errorCount} error${errorCount > 1 ? 's' : ''}. ${errorMessages.join('. ')}`;
                announceToScreenReader(announcement, 'assertive');

                return false;
            }

            // Show loading state
            setButtonLoading(addItemSubmit, 'Adding Item...');

            // Submit form via AJAX with fallback
            try {
                submitAddItemForm();
            } catch (jsError) {
                if (window.AppLogger) {
                    AppLogger.error('JavaScript submission failed:', jsError);
                }
                clearButtonLoading(addItemSubmit);

                // Fallback to regular form submission
                addItemForm.removeEventListener('submit', arguments.callee);
                addItemForm.submit();
            }
        });

        // Real-time validation for quantity field
        const quantityField = document.getElementById('addItemQuantity');
        if (quantityField) {
            quantityField.addEventListener('input', function() {
                const value = parseInt(this.value);
                if (this.value && value >= 1 && !isNaN(value)) {
                    clearFieldError('addItemQuantity');
                    this.closest('.form-group').classList.add('success');
                } else if (this.value) {
                    showFieldError('addItemQuantity', 'Quantity must be 1 or more');
                }
            });
        }

        // Real-time validation for store selection
        const storeField = document.getElementById('addItemStoreId');
        if (storeField) {
            storeField.addEventListener('change', function() {
                if (this.value) {
                    clearFieldError('addItemStoreId');
                    this.closest('.form-group').classList.add('success');
                }
            });
        }
    }

    // Enhanced accessibility and user experience with proper cleanup
    if (addItemModal) {
        // Monitor modal visibility changes with optimized observer
        const modalObserver = new MutationObserver(throttle((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    const isVisible = addItemModal.style.display !== 'none';
                    if (isVisible && productSearch) {
                        // Focus search field when modal opens
                        requestAnimationFrame(() => {
                            productSearch.focus();
                        });
                    }
                }
            });
        }, 100));

        modalObserver.observe(addItemModal, { attributes: true });

        // Store observer for cleanup
        elementData.set(addItemModal, { observer: modalObserver });

        // Add keyboard shortcuts for modal
        const modalKeydownHandler = (e) => {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                if (productSearch) {
                    productSearch.focus();
                    productSearch.select();
                }
            }
        };

        addEventListenerWithCleanup(addItemModal, 'keydown', modalKeydownHandler);
    }

    // Resize handler for responsive behavior
    function handleResize() {
        // Optimize modal layout on resize
        if (addItemModal && addItemModal.style.display !== 'none') {
            // Recalculate modal positioning if needed
            const modalContent = addItemModal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.style.maxHeight = `${window.innerHeight * 0.9}px`;
            }
        }

        // Optimize product grid layout
        if (productList && productList.children.length > 0) {
            // Trigger layout recalculation for responsive grid
            productList.style.display = 'none';
            requestAnimationFrame(() => {
                productList.style.display = '';
            });
        }
    }

    addEventListenerWithCleanup(window, 'resize', debouncedResize);

    // Add global keyboard shortcuts when modal is open
    document.addEventListener('keydown', (e) => {
        if (addItemModal && addItemModal.style.display !== 'none') {
            // Alt + S to focus search
            if (e.altKey && e.key === 's') {
                e.preventDefault();
                if (productSearch) {
                    productSearch.focus();
                    productSearch.select();
                }
            } else if (e.altKey && e.key === 'q') {
                // Alt + Q to focus quantity
                e.preventDefault();
                const quantityField = document.getElementById('addItemQuantity');
                if (quantityField) {
                    quantityField.focus();
                    quantityField.select();
                }
            } else if (e.altKey && e.key === 't') {
                // Alt + T to focus store (sT)
                e.preventDefault();
                const storeField = document.getElementById('addItemStoreId');
                if (storeField) {
                    storeField.focus();
                }
            } else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                // Ctrl/Cmd + Enter to submit form (if valid)
                e.preventDefault();
                if (addItemSubmit && !addItemSubmit.disabled) {
                    addItemSubmit.click();
                }
            }
        }
    });

    // Button loading state management
    function setButtonLoading(button, loadingText = 'Loading...') {
        if (!button) {
            return;
        }

        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.textContent = loadingText;
        button.classList.add('btn-loading');
    }

    function clearButtonLoading(button) {
        if (!button) {
            return;
        }

        button.disabled = false;
        if (button.dataset.originalText) {
            button.textContent = button.dataset.originalText;
            delete button.dataset.originalText;
        }
        button.classList.remove('btn-loading');
    }

    // Cleanup function for memory management
    function cleanup() {
        try {
            // Clean up event listeners
            cleanupEventListeners();

            // Clean up observers
            if (addItemModal) {
                try {
                    const data = elementData.get(addItemModal);
                    if (data && data.observer) {
                        data.observer.disconnect();
                    }
                } catch (observerError) {
                    if (window.AppLogger) {
                        AppLogger.error('Error cleaning up observer:', observerError);
                    }
                }
            }

            // Clear timers
            if (window.stockTimers) {
                try {
                    window.stockTimers.forEach(timer => clearTimeout(timer));
                    window.stockTimers = [];
                } catch (timerError) {
                    if (window.AppLogger) {
                        AppLogger.error('Error clearing timers:', timerError);
                    }
                }
            }

            // Abort any active requests
            try {
                activeRequests.forEach(controller => {
                    if (controller && controller.abort) {
                        controller.abort();
                    }
                });
                activeRequests = [];
            } catch (requestError) {
                if (window.AppLogger) {
                    AppLogger.error('Error aborting active requests:', requestError);
                }
            }

            // Clear cached data
            try {
                allProducts = [];
                filteredProducts = [];
            } catch (dataError) {
                if (window.AppLogger) {
                    AppLogger.error('Error clearing cached data:', dataError);
                }
            }

            // Clear WeakMap data
            try {
                elementData.clear();
            } catch (weakMapError) {
                if (window.AppLogger) {
                    AppLogger.error('Error clearing WeakMap:', weakMapError);
                }
            }

        } catch (cleanupError) {
            if (window.AppLogger) {
                AppLogger.error('Error during stock page cleanup:', cleanupError);
            }
        }
    }

    // Add global error handler for production error logging
    window.addEventListener('error', (event) => {
        if (window.location.pathname.includes('stock')) {
            if (window.AppLogger) {
                AppLogger.error('Stock page error:', {
                    message: event.message,
                    filename: event.filename,
                    lineno: event.lineno,
                    colno: event.colno,
                    error: event.error
                });
            }
        }
    });

    // Add unhandled promise rejection handler
    window.addEventListener('unhandledrejection', (event) => {
        if (window.location.pathname.includes('stock')) {
            if (window.AppLogger) {
                AppLogger.error('Stock page unhandled promise rejection:', event.reason);
            }
        }
    });

    // Register cleanup on page unload with error handling
    addEventListenerWithCleanup(window, 'beforeunload', (_event) => {
        try {
            cleanup();
        } catch (error) {
            if (window.AppLogger) {
                AppLogger.error('Error during beforeunload cleanup:', error);
            }
        }
    });

    addEventListenerWithCleanup(window, 'unload', (_event) => {
        try {
            cleanup();
        } catch (error) {
            if (window.AppLogger) {
                AppLogger.error('Error during unload cleanup:', error);
            }
        }
    });

    // Performance monitoring integration
    if (window.performanceMonitor) {
        // Measure critical functions
        const originalLoadProducts = loadProducts;
        // eslint-disable-next-line no-func-assign
        loadProducts = function() {
            return window.performanceMonitor.measureFunction('loadProducts', originalLoadProducts);
        };

        const originalDisplayProducts = displayProducts;
        // eslint-disable-next-line no-func-assign
        displayProducts = function(products) {
            return window.performanceMonitor.measureFunction('displayProducts', () => originalDisplayProducts(products));
        };
    }

    // Expose cleanup function globally for debugging
    window.stockCleanup = cleanup;


    // Note: Error handling functions moved to centralized api-error-handler.js

    // Submit add item form via AJAX with enhanced error handling and CSRF protection
    function submitAddItemForm() {
        const formData = new FormData(addItemForm);

        // Ensure CSRF token is included
        const csrfToken = resolveCsrfToken(addItemForm);

        if (csrfToken && !formData.has('csrf')) {
            formData.append('csrf', csrfToken);
        } else if (!csrfToken) {
            if (window.AppLogger) {
                AppLogger.error('No CSRF token available - form submission may fail');
            }
        }

        // Use simple fetch with timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000);

        // Track this request for cleanup
        activeRequests.push(controller);

        // Add a safety timeout to prevent infinite loading
        const safetyTimeout = setTimeout(() => {

            clearButtonLoading(addItemSubmit);
            showErrorMessage('Request took too long. Please try again.');
        }, 35000); // 35 seconds safety net

        fetch('/api/stock.php', {
            method: 'POST',
            body: formData,
            signal: controller.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => {
                clearTimeout(timeoutId);

                // Always parse JSON, even for error responses
                return response.json().then(data => ({
                    ok: response.ok,
                    status: response.status,
                    data: data
                }));
            })
            .then(({ _ok, _status, data }) => {
                clearTimeout(safetyTimeout);
                clearButtonLoading(addItemSubmit);

                if (data.success) {
                    const successMsg = data.message || 'Item successfully added to van stock';
                    showSuccessMessage(successMsg);
                    announceToScreenReader(`Success: ${successMsg}`, 'assertive');

                    // Close modal and reload page after shorter delay with fade effect
                    setTimeout(() => {
                        // Add fade effect to success message
                        const successMessage = addItemModal.querySelector('.success-message');
                        if (successMessage) {
                            successMessage.style.transition = 'opacity 0.3s ease-out';
                            successMessage.style.opacity = '0.7';
                        }

                        // Close modal after brief fade
                        setTimeout(() => {
                            closeAddItemModal();
                            window.location.reload();
                        }, 200);
                    }, 600);

                } else {
                    // Error: keep modal open, show errors as toast notifications

                    // Display all validation errors as toast notifications
                    if (data.errors && Array.isArray(data.errors) && data.errors.length > 0) {
                        data.errors.forEach(error => notify(error, 'error'));
                        announceToScreenReader(`Validation error: ${data.errors[0]}`, 'assertive');
                    } else {
                        const errorMsg = data.error || data.message || 'Failed to add item to van stock';
                        notify(errorMsg, 'error');
                        announceToScreenReader('Error adding item: ' + errorMsg, 'assertive');
                    }
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                clearTimeout(safetyTimeout);
                clearButtonLoading(addItemSubmit);

                if (window.AppLogger) {
                    AppLogger.error('Error submitting form:', error);
                }

                let userMessage = 'Network error. Please check your connection and try again.';

                if (error.name === 'AbortError') {
                    userMessage = 'Request timed out. Please try again.';
                } else if (error.message.includes('fetch')) {
                    userMessage = 'Connection failed. Please check your internet connection and try again.';
                } else if (error.message.includes('TypeError')) {
                    userMessage = 'Network error. Please check your connection and try again.';
                } else if (error.message) {
                    userMessage = error.message;
                }

                notify(userMessage, 'error');
                announceToScreenReader('Error adding item: ' + userMessage, 'assertive');
            })
            .finally(() => {
                // Ensure button is always cleared from loading state
                setTimeout(() => {
                    if (addItemSubmit && addItemSubmit.classList.contains('btn-loading')) {
                        clearButtonLoading(addItemSubmit);
                    }
                }, 100);
            });
    }

    // Show success message in modal
    function showSuccessMessage(message) {
        if (!addItemModal) {
            if (window.AppLogger) {
                AppLogger.error('Modal not found for success message');
            }
            return;
        }

        const existingMessage = addItemModal.querySelector('.success-message');
        if (existingMessage) {
            existingMessage.remove();
        }

        const successDiv = document.createElement('div');
        successDiv.className = 'success-message';
        successDiv.setAttribute('role', 'alert');
        successDiv.setAttribute('aria-live', 'assertive');
        successDiv.style.cssText = `
            position: relative;
            z-index: 1000;
            margin: 0 0 20px 0;
            padding: 15px;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 6px;
            color: #155724;
            font-size: 14px;
            animation: slideDown 0.3s ease-out;
        `;
        successDiv.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="color: #28a745; font-size: 18px;">✓</span>
                <div>
                    <strong>Success!</strong><br>
                    <span style="font-size: 13px;">${escapeHtml(message)}</span>
                </div>
            </div>
        `;

        const modalContent = addItemModal.querySelector('.modal-content');
        if (!modalContent) {
            if (window.AppLogger) {
                AppLogger.error('Modal content not found for success message');
            }
            return;
        }

        const modalHeader = modalContent.querySelector('h2');

        // Insert after the header using a more reliable method
        try {
            if (modalHeader) {
                modalHeader.insertAdjacentElement('afterend', successDiv);
            } else {
                // Fallback: append to modal content
                modalContent.appendChild(successDiv);
            }
        } catch (error) {
            if (window.AppLogger) {
                AppLogger.error('Error inserting success message:', error);
            }
            // Final fallback: append to modal content
            modalContent.appendChild(successDiv);
        }

        // Add CSS animation if not already added
        if (!document.querySelector('#success-animation-styles')) {
            const style = document.createElement('style');
            style.id = 'success-animation-styles';
            style.textContent = `
                @keyframes slideDown {
                    from {
                        opacity: 0;
                        transform: translateY(-10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
                @keyframes successPulse {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.02); }
                }
                .success-message {
                    animation: slideDown 0.3s ease-out, successPulse 0.6s ease-in-out 0.3s;
                }
            `;
            document.head.appendChild(style);
        }

        announceToScreenReader('Success: ' + message, 'assertive');
    }

    // Show error message in modal
    function showErrorMessage(message) {
        if (!addItemModal) {
            if (window.AppLogger) {
                AppLogger.error('Modal not found for error message');
            }
            return;
        }

        const existingMessage = addItemModal.querySelector('.error-message-modal');
        if (existingMessage) {
            existingMessage.remove();
        }

        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-message-modal';
        errorDiv.setAttribute('role', 'alert');
        errorDiv.setAttribute('aria-live', 'assertive');
        errorDiv.style.cssText = `
            position: relative;
            z-index: 1000;
            margin: 0 0 20px 0;
            padding: 15px;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 6px;
            color: #721c24;
            font-size: 14px;
            animation: slideDown 0.3s ease-out;
        `;
        errorDiv.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="color: #dc3545; font-size: 18px;">⚠</span>
                <div>
                    <strong>Error!</strong><br>
                    <span style="font-size: 13px;">${escapeHtml(message)}</span>
                </div>
            </div>
        `;

        const modalContent = addItemModal.querySelector('.modal-content');
        if (!modalContent) {
            if (window.AppLogger) {
                AppLogger.error('Modal content not found for error message');
            }
            return;
        }

        const modalHeader = modalContent.querySelector('h2');

        // Insert after the header using a more reliable method
        try {
            if (modalHeader) {
                modalHeader.insertAdjacentElement('afterend', errorDiv);
            } else {
                // Fallback: append to modal content
                modalContent.appendChild(errorDiv);
            }
        } catch (error) {
            if (window.AppLogger) {
                AppLogger.error('Error inserting error message:', error);
            }
            // Final fallback: append to modal content
            modalContent.appendChild(errorDiv);
        }

        // Auto-remove error message after 5 seconds
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.remove();
            }
        }, 5000);
    }

    // Enhanced keyboard navigation for modal
    function trapFocus(modal) {
        const focusableElements = modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
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

    // Initialize focus trapping for modals
    if (addItemModal) {
        trapFocus(addItemModal);
    }

    // ========================================
    // ADJUSTMENT MODAL FUNCTIONALITY
    // ========================================

    /**
     * Close the adjustment modal
     */
    function closeAdjustmentModal() {
        hideModal(adjustmentModal);
    }

    /**
     * Open adjustment modal for quick actions (+/-)
     */
    function openQuickActionModal(prodId, prodName, action) {
        if (!modalProdId) {
            if (window.AppLogger) {
                AppLogger.error('Could not find modalProdId element');
            }
            return;
        }

        modalProdId.value = prodId;
        if (modalProductName) {
            modalProductName.textContent = prodName;
        }
        if (modalReason) {
            modalReason.value = action;
        }
        if (modalChangeQty) {
            modalChangeQty.value = 1;
        }
        if (modalStoreId) {
            modalStoreId.value = 1;
        }
        if (modalNote) {
            modalNote.value = '';
        }

        // Clear any existing error messages in the modal
        const existingErrors = adjustmentModal.querySelectorAll('.error-message-modal');
        existingErrors.forEach(error => error.remove());

        showModal(adjustmentModal);
        announceToScreenReader(`Opening stock adjustment dialog for ${prodName}`, 'assertive');
    }

    /**
     * Navigate to the stock item detail page
     */
    function openDetailsModal(prodId, prodName, vanUserId) {
        const params = new URLSearchParams({ product_id: prodId });
        if (vanUserId) {
            params.set('van_user_id', vanUserId);
        }
        window.location.href = `/stock/item?${params.toString()}`;
        announceToScreenReader(`Opening details for ${prodName}`, 'assertive');
    }

    // Quick action button handlers
    document.querySelectorAll('.quick-action-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            openQuickActionModal(this.dataset.prodId, this.dataset.prodName, this.dataset.action);
        });
    });

    // Details button handlers
    document.querySelectorAll('.details-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            openDetailsModal(this.dataset.prodId, this.dataset.prodName, this.dataset.vanUserId);
        });
    });

    // Adjustment modal close handlers
    if (adjustmentModal) {
        const closeAdjustmentButton = adjustmentModal.querySelector('.close-button');
        const cancelButton = adjustmentModal.querySelector('.modal-cancel-btn');

        if (closeAdjustmentButton) {
            closeAdjustmentButton.addEventListener('click', closeAdjustmentModal);
        }
        if (cancelButton) {
            cancelButton.addEventListener('click', closeAdjustmentModal);
        }

        // Close on backdrop click
        window.addEventListener('click', (event) => {
            if (event.target === adjustmentModal) {
                closeAdjustmentModal();
            }
        });
    }

    // Adjustment form AJAX submission
    if (adjustmentForm) {
        adjustmentForm.addEventListener('submit', (e) => {
            e.preventDefault();

            // Clear any existing error messages before submitting
            const existingErrors = adjustmentForm.closest('.modal').querySelectorAll('.error-message-modal');
            existingErrors.forEach(error => error.remove());

            const formData = new FormData(adjustmentForm);
            const csrfToken = resolveCsrfToken(adjustmentForm);

            if (csrfToken && !formData.has('csrf')) {
                formData.append('csrf', csrfToken);
            }

            const submitButton = adjustmentForm.querySelector('button[type="submit"]');
            if (!submitButton) {
                notify('Submit button not found', 'error');
                return;
            }

            setButtonLoading(submitButton, 'Submitting...');

            fetch('/api/stock.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => {
                // Always parse JSON, even for error responses
                    return response.json().then(data => ({
                        ok: response.ok,
                        status: response.status,
                        data: data
                    }));
                })
                .then(({ _ok, _status, data }) => {
                    if (data.success) {
                    // Success: close modal and reload
                        notify(data.message || 'Stock adjusted successfully', 'success');
                        hideModal(adjustmentModal);
                        setTimeout(() => window.location.reload(), 800);
                    } else {
                    // Error: keep modal open, show errors, re-enable form
                        clearButtonLoading(submitButton);

                        // Display all validation errors as toast notifications
                        if (data.errors && Array.isArray(data.errors) && data.errors.length > 0) {
                            data.errors.forEach(error => notify(error, 'error'));
                        } else {
                            notify(data.message || 'Failed to adjust stock', 'error');
                        }
                    }
                })
                .catch(error => {
                // Network/server error: re-enable form
                    clearButtonLoading(submitButton);
                    notify('Network error. Please check your connection and try again.', 'error');
                    if (window.AppLogger) {
                        AppLogger.error('Adjustment form error:', error);
                    }
                });
        });
    }

    // ========================================
    // TOGGLE BREAKDOWN FUNCTIONALITY
    // ========================================

    document.querySelectorAll('.toggle-breakdown').forEach(button => {
        // Store original button text on first load
        if (!button.dataset.originalText) {
            button.dataset.originalText = button.textContent.trim();
        }

        button.addEventListener('click', function(e) {
            e.preventDefault();
            const content = this.nextElementSibling;
            const isHidden = content.style.display === 'none';

            if (isHidden) {
                content.style.display = 'block';
                content.setAttribute('aria-hidden', 'false');
                this.setAttribute('aria-expanded', 'true');
                // Keep original text, don't change it
                this.setAttribute('aria-label', this.getAttribute('aria-label').replace('Show', 'Hide'));
            } else {
                content.style.display = 'none';
                content.setAttribute('aria-hidden', 'true');
                this.setAttribute('aria-expanded', 'false');
                // Restore original text if it was changed
                this.textContent = this.dataset.originalText;
                this.setAttribute('aria-label', this.getAttribute('aria-label').replace('Hide', 'Show'));
            }

            if (window.announceToScreenReader) {
                const action = isHidden ? 'Showing' : 'Hiding';
                const productName = this.getAttribute('aria-label').match(/for (.+)$/)?.[1] || 'product';
                window.announceToScreenReader(`${action} location breakdown for ${productName}`, 'polite');
            }
        });
    });

    // ========================================
    // TRANSFER MODAL FUNCTIONALITY
    // ========================================

    /**
     * Initialize transfer modal elements
     */
    function initTransferModal() {
        const transferModal = document.getElementById('transferModal');
        if (!transferModal) {
            if (window.AppLogger) {
                AppLogger.error('Transfer modal not found in DOM');
            }
            return false;
        }

        return {
            transferModal,
            transferForm: document.getElementById('transferForm'),
            transferProdId: document.getElementById('transferProdId'),
            transferFromStoreId: document.getElementById('transferFromStoreId'),
            transferProductName: document.getElementById('transferProductName'),
            transferSourceStore: document.getElementById('transferSourceStore'),
            transferQuantity: document.getElementById('transferQuantity'),
            transferAvailableQty: document.getElementById('transferAvailableQty'),
            transferToStoreId: document.getElementById('transferToStoreId')
        };
    }

    /**
     * Close the transfer modal
     */
    function closeTransferModal() {
        const transferModal = document.getElementById('transferModal');
        if (transferModal) {
            hideModal(transferModal);
            transferModal.style.pointerEvents = 'none';
            document.body.classList.remove('modal-open');
        }
    }

    // Transfer button click handler (event delegation)
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.transfer-btn');
        if (!btn) {
            return;
        }

        e.preventDefault();

        const modalElements = initTransferModal();
        if (!modalElements) {
            notify('Transfer modal not found. Please refresh the page.', 'error');
            return;
        }

        const {
            transferModal, transferProdId, transferFromStoreId,
            transferProductName, transferSourceStore, transferAvailableQty,
            transferQuantity, transferToStoreId
        } = modalElements;

        const sourceStoreId = btn.dataset.sourceStoreId;

        // Set form values
        if (transferProdId) {
            transferProdId.value = btn.dataset.prodId;
        }
        if (transferFromStoreId) {
            transferFromStoreId.value = sourceStoreId;
        }
        if (transferProductName) {
            transferProductName.textContent = btn.dataset.prodName;
        }
        if (transferSourceStore) {
            transferSourceStore.textContent = btn.dataset.sourceStoreName;
        }
        const availableQty = parseInt(btn.dataset.availableQty) || 0;
        if (transferAvailableQty) {
            transferAvailableQty.textContent = availableQty;
        }
        if (transferQuantity) {
            // Limit max to either available quantity or 10,000, whichever is smaller
            transferQuantity.max = Math.min(availableQty, 10000);
            transferQuantity.value = '';
        }
        if (transferToStoreId) {
            transferToStoreId.value = '';
        }

        const transferNoteField = document.getElementById('transferNote');
        if (transferNoteField) {
            transferNoteField.value = '';
        }

        // Clear any existing error messages in the modal
        const existingErrors = transferModal.querySelectorAll('.error-message-modal');
        existingErrors.forEach(error => error.remove());

        // Filter out source store from destination options
        if (transferToStoreId?.options) {
            Array.from(transferToStoreId.options).forEach(option => {
                const isSource = option.value === sourceStoreId;
                option.style.display = isSource ? 'none' : '';
                option.disabled = isSource;
            });
        }

        // Show modal with body scroll lock
        showModal(transferModal, 'flex');
        transferModal.style.pointerEvents = 'auto';
        document.body.classList.add('modal-open');

        if (transferQuantity) {
            setTimeout(() => transferQuantity.focus(), 100);
        }
    });

    // Transfer modal close handlers
    const transferModal = document.getElementById('transferModal');
    const transferForm = document.getElementById('transferForm');

    if (transferModal) {
        const transferCloseBtn = transferModal.querySelector('.close-button');
        const transferCancelBtn = transferModal.querySelector('.transfer-cancel-btn');

        if (transferCloseBtn) {
            transferCloseBtn.addEventListener('click', closeTransferModal);
        }
        if (transferCancelBtn) {
            transferCancelBtn.addEventListener('click', closeTransferModal);
        }

        // Close on backdrop click
        window.addEventListener('click', (e) => {
            if (e.target === transferModal) {
                closeTransferModal();
            }
        });
    }

    // Transfer form AJAX submission
    if (transferForm) {
        transferForm.addEventListener('submit', (e) => {
            e.preventDefault();

            // Clear any existing error messages before submitting
            const existingErrors = transferForm.closest('.modal').querySelectorAll('.error-message-modal');
            existingErrors.forEach(error => error.remove());

            const formData = new FormData(transferForm);
            const csrfToken = resolveCsrfToken(transferForm);

            if (csrfToken && !formData.has('csrf')) {
                formData.append('csrf', csrfToken);
            }

            const submitButton = transferForm.querySelector('button[type="submit"]');
            if (!submitButton) {
                notify('Submit button not found', 'error');
                return;
            }

            setButtonLoading(submitButton, 'Transferring...');

            fetch('/api/stock.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => {
                // Always parse JSON, even for error responses
                    return response.json().then(data => ({
                        ok: response.ok,
                        status: response.status,
                        data: data
                    }));
                })
                .then(({ _ok, _status, data }) => {
                    if (data.success) {
                    // Success: close modal and reload
                        notify(data.message || 'Stock transferred successfully', 'success');
                        closeTransferModal();
                        setTimeout(() => window.location.reload(), 800);
                    } else {
                    // Error: keep modal open, show errors, re-enable form
                        clearButtonLoading(submitButton);

                        // Display all validation errors as toast notifications
                        if (data.errors && Array.isArray(data.errors) && data.errors.length > 0) {
                            data.errors.forEach(error => notify(error, 'error'));
                        } else {
                            notify(data.message || 'Failed to transfer stock', 'error');
                        }
                    }
                })
                .catch(error => {
                // Network/server error: re-enable form
                    clearButtonLoading(submitButton);
                    notify('Network error. Please check your connection and try again.', 'error');
                    if (window.AppLogger) {
                        AppLogger.error('Transfer form error:', error);
                    }
                });
        });
    }

    // ========================================
    // VAN PRODUCT PICKER FUNCTIONALITY
    // ========================================

    /**
     * Update submit button enabled state based on product selection
     */
    function updateVanSubmitEnabled() {
        if (!addItemSubmit) {
            return;
        }
        const hasProduct = !!(selectedProductId && selectedProductId.value);
        addItemSubmit.disabled = !hasProduct;
    }

    /**
     * Render the van product picker list
     */
    function renderVanList(term = '') {
        if (!vanPickerList) {
            return;
        }
        vanPickerList.innerHTML = '';
        const t = term.toLowerCase().trim();
        const matches = vanProducts.filter(p =>
            !t ||
            (p.name && p.name.toLowerCase().includes(t)) ||
            (p.product_id && p.product_id.toLowerCase().includes(t))
        );

        if (!matches.length) {
            const empty = document.createElement('div');
            empty.style.padding = '12px';
            empty.textContent = 'No results';
            vanPickerList.appendChild(empty);
            return;
        }

        matches.forEach(p => {
            const item = document.createElement('div');
            item.className = 'picker-item';
            item.style.cssText = 'display:block; width:100%; padding:12px 14px; border-bottom:1px solid var(--color-gray-200); background:#fff; cursor:pointer;';
            item.addEventListener('mouseenter', () => {
                item.style.background = '#f9fafb';
            });
            item.addEventListener('mouseleave', () => {
                item.style.background = '#fff';
            });

            const nameEl = document.createElement('div');
            nameEl.textContent = p.name;
            nameEl.style.cssText = 'font-weight:600; white-space:normal; overflow-wrap:anywhere; word-break:break-word;';

            const idEl = document.createElement('div');
            idEl.textContent = p.product_id || '';
            idEl.style.cssText = 'color:#6b7280; font-size:12px; margin-top:4px;';

            item.appendChild(nameEl);
            item.appendChild(idEl);

            let touchMoved = false;
            let touchStartX = 0, touchStartY = 0;

            item.addEventListener('touchstart', (e) => {
                const t0 = e.touches && e.touches[0];
                if (t0) {
                    touchMoved = false; touchStartX = t0.clientX; touchStartY = t0.clientY;
                }
            }, { passive: true });

            item.addEventListener('touchmove', (e) => {
                const t0 = e.touches && e.touches[0];
                if (t0 && (Math.abs(t0.clientX - touchStartX) > 8 || Math.abs(t0.clientY - touchStartY) > 8)) {
                    touchMoved = true;
                }
            }, { passive: true });

            function chooseProduct() {
                if (selectedProductId) {
                    selectedProductId.value = p.id;
                }
                if (vanProdDisplay) {
                    vanProdDisplay.value = p.product_id ? `${p.name} (${p.product_id})` : p.name;
                }
                updateVanSubmitEnabled();
                hideModal(vanProductPickerModal);
                if (addItemQuantity) {
                    addItemQuantity.focus();
                }
            }

            item.addEventListener('touchend', (e) => {
                if (!touchMoved) {
                    e.preventDefault(); chooseProduct();
                }
            });
            item.addEventListener('click', () => {
                if (!touchMoved) {
                    chooseProduct();
                }
            });
            vanPickerList.appendChild(item);
        });
    }

    /**
     * Load products for van picker
     */
    async function loadVanProducts() {
        try {
            const resp = await fetch('/api/products.php?action=search&limit=200');
            const data = await resp.json();
            if (data && data.success && data.data && Array.isArray(data.data.products)) {
                vanProducts = data.data.products.map(p => ({
                    id: String(p.id),
                    name: p.name,
                    product_id: p.product_id
                }));
            } else {
                vanProducts = [];
            }
            renderVanList('');
        } catch (e) {
            vanProducts = [];
            renderVanList('');
        }
    }

    // Van product picker button handler
    if (openVanProductPickerBtn) {
        openVanProductPickerBtn.addEventListener('click', () => {
            if (vanProducts.length === 0) {
                renderVanList('');
                loadVanProducts();
            } else {
                renderVanList('');
            }
            showModal(vanProductPickerModal);
            setTimeout(() => vanPickerSearch?.focus(), 0);
        });
    }

    // Van picker search handler
    if (vanPickerSearch) {
        vanPickerSearch.addEventListener('input', function() {
            renderVanList(this.value);
        });
        vanPickerSearch.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                vanPickerList?.querySelector('.picker-item')?.click();
            }
        });
    }

    // Van picker close handlers
    document.querySelectorAll('#vanProductPickerModal .close-button, #vanPickerCloseBtn').forEach(btn => {
        btn.addEventListener('click', () => hideModal(vanProductPickerModal));
    });

    // ========================================
    // VAN SCANNER FUNCTIONALITY
    // ========================================

    /**
     * Apply zoom to scanner video
     */
    function vanApplyZoom() {
        if (!vanScannerVideo) {
            return;
        }
        vanScannerVideo.style.transform = `scale(${vanCurrentZoom})`;
        vanScannerVideo.style.transformOrigin = 'center center';
        if (vanZoomLevelDisplay) {
            vanZoomLevelDisplay.textContent = vanCurrentZoom.toFixed(1) + '×';
        }
        if (vanZoomInBtn) {
            vanZoomInBtn.disabled = vanCurrentZoom >= 4;
        }
        if (vanZoomOutBtn) {
            vanZoomOutBtn.disabled = vanCurrentZoom <= 1;
        }
    }

    /**
     * Stop the van scanner
     */
    function vanStopScanner() {
        try {
            if (vanCodeReader) {
                vanCodeReader.reset(); vanCodeReader = null;
            }
        } catch (e) { /* ignore */ }
        try {
            if (vanCurrentStream) {
                vanCurrentStream.getTracks().forEach(t => t.stop()); vanCurrentStream = null;
            }
        } catch (e) { /* ignore */ }
        if (vanScannerVideo) {
            vanScannerVideo.srcObject = null;
        }
        if (vanScannerResult) {
            vanScannerResult.textContent = '';
        }
        if (vanStopScannerButton) {
            vanStopScannerButton.style.display = 'none';
        }
        vanCurrentZoom = 1;
        vanApplyZoom();
    }

    /**
     * Start the van scanner with lazy-loaded ZXing library
     * Loads the ZXing library on-demand to improve initial page load performance
     */
    async function vanStartScanner() {
        try {
            if (vanScannerResult) {
                vanScannerResult.textContent = 'Loading scanner...';
            }
            if (vanStopScannerButton) {
                vanStopScannerButton.style.display = 'none';
            }

            // Check if loadScannerLibrary is available
            if (typeof window.loadScannerLibrary !== 'function') {
                if (vanScannerResult) {
                    vanScannerResult.textContent = 'Scanner library loader not available.';
                }
                AppLogger.error('loadScannerLibrary function not found');
                return;
            }

            // Lazy-load the ZXing library
            let ZXingLib;
            try {
                if (vanScannerResult) {
                    vanScannerResult.textContent = 'Loading scanner library...';
                }
                ZXingLib = await window.loadScannerLibrary();
            } catch (loadError) {
                if (vanScannerResult) {
                    vanScannerResult.textContent = 'Failed to load scanner library. Please try again.';
                }
                AppLogger.error('Scanner library load failed:', loadError);
                return;
            }

            if (vanScannerResult) {
                vanScannerResult.textContent = 'Initializing scanner...';
            }

            if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                if (vanScannerResult) {
                    vanScannerResult.textContent = 'Camera access requires HTTPS.';
                }
                return;
            }

            const hints = new Map();
            hints.set(ZXingLib.DecodeHintType.TRY_HARDER, true);
            hints.set(ZXingLib.DecodeHintType.POSSIBLE_FORMATS, [ZXingLib.BarcodeFormat.QR_CODE]);

            try {
                vanCodeReader = new ZXingLib.BrowserMultiFormatReader(hints);
            } catch (e) {
                vanCodeReader = new ZXingLib.BrowserMultiFormatReader(new Map().set(ZXingLib.DecodeHintType.TRY_HARDER, true));
            }

            vanCodeReader.decodeFromVideoDevice(undefined, vanScannerVideo, async (result, _err) => {
                if (result) {
                    if (vanScannerResult) {
                        vanScannerResult.innerHTML = '';
                        const scanDiv = document.createElement('div');
                        scanDiv.style.cssText = 'color:#28a745;font-weight:bold;';
                        scanDiv.textContent = '✅ Scanned: ' + result.text;
                        vanScannerResult.appendChild(scanDiv);
                    }
                    vanStopScanner();
                    hideModal(vanScannerModal);

                    // Lookup product via API using consolidated CSRF helper
                    try {
                        const token = resolveCsrfToken();
                        const resp = await fetch('/api/scan_product.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'barcode=' + encodeURIComponent(result.text) + (token ? '&csrf=' + encodeURIComponent(token) : '')
                        });
                        const data = await resp.json();
                        if (data?.success && data.data) {
                            const p = data.data;
                            if (selectedProductId) {
                                selectedProductId.value = String(p.id);
                            }
                            if (vanProdDisplay) {
                                vanProdDisplay.value = p.product_id ? `${p.name} (${p.product_id})` : p.name;
                            }
                            updateVanSubmitEnabled();
                            if (addItemQuantity) {
                                addItemQuantity.focus();
                            }
                        } else {
                            notify('Product not found for scanned code', 'warning');
                        }
                    } catch (e) {
                        notify('Error looking up scanned code', 'error');
                    }
                }
            });

            if (vanStopScannerButton) {
                vanStopScannerButton.style.display = 'inline-block';
            }
        } catch (e) {
            if (vanScannerResult) {
                vanScannerResult.textContent = 'Camera error occurred';
            }
            vanStopScanner();
        }
    }

    /**
     * Close the scanner modal and stop scanning
     */
    function closeScannerModal() {
        vanStopScanner();
        hideModal(vanScannerModal);
    }

    // Scanner button handlers
    if (openVanScannerBtnPrimary) {
        openVanScannerBtnPrimary.addEventListener('click', () => {
            showModal(vanScannerModal);
            setTimeout(() => vanStartScanner(), 300);
        });
    }

    if (vanCloseScannerButton) {
        vanCloseScannerButton.addEventListener('click', closeScannerModal);
    }

    if (vanScannerCloseFooter) {
        vanScannerCloseFooter.addEventListener('click', closeScannerModal);
    }

    // Zoom button handlers
    if (vanZoomInBtn) {
        vanZoomInBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (vanCurrentZoom < 4) {
                vanCurrentZoom += 0.5; vanApplyZoom();
            }
        });
    }

    if (vanZoomOutBtn) {
        vanZoomOutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (vanCurrentZoom > 1) {
                vanCurrentZoom -= 0.5; vanApplyZoom();
            }
        });
    }

    // Sync display if preselected
    (async function syncPreselectedProduct() {
        if (selectedProductId && selectedProductId.value && vanProdDisplay && (!vanProdDisplay.value || vanProdDisplay.value === '')) {
            try {
                const resp = await fetch(`/api/products.php?action=get&id=${encodeURIComponent(selectedProductId.value)}`);
                const data = await resp.json();
                if (data && data.success && data.data) {
                    const p = data.data;
                    vanProdDisplay.value = p.product_id ? `${p.name} (${p.product_id})` : p.name;
                }
            } catch (e) { /* ignore */ }
        }
    })();

    updateVanSubmitEnabled();

    // Expose functions globally for debugging and external access
    window.StockManager = {
        loadProducts,
        displayProducts,
        selectProduct,
        clearFormValidation,
        showFieldError,
        clearFieldError,
        submitAddItemForm,
        setButtonLoading,
        clearButtonLoading,
        showSuccessMessage,
        showErrorMessage

    };
});
