/**
 * Performance Monitoring and Optimization Utilities
 * Monitors JavaScript performance and provides optimization tools
 */

// Console output is gated behind the app's debug flag: window.AppLogger.isProduction
// (set from APP_ENV in views/header.php). Silent in production or without AppLogger.
/* eslint-disable no-console */
const debugConsole = ['log', 'warn', 'group', 'groupEnd', 'table'].reduce((api, method) => {
    api[method] = (...args) => {
        if (window.AppLogger && !window.AppLogger.isProduction) {
            console[method](...args);
        }
    };
    return api;
}, {});
/* eslint-enable no-console */

class PerformanceMonitor {
    constructor() {
        this.metrics = new Map();
        this.observers = new Set();
        this.timers = new Set();
        this.memoryUsage = [];
        this.isMonitoring = false;

        this.init();
    }

    init() {
        // Monitor memory usage if available
        if ('memory' in performance) {
            this.startMemoryMonitoring();
        }

        // Monitor long tasks
        if ('PerformanceObserver' in window) {
            this.monitorLongTasks();
            this.monitorLayoutShifts();
        }

        // Monitor resource loading
        this.monitorResourceLoading();

        // Setup cleanup
        this.setupCleanup();
    }

    startMemoryMonitoring() {
        const checkMemory = () => {
            if (performance.memory) {
                const memory = {
                    used: performance.memory.usedJSHeapSize,
                    total: performance.memory.totalJSHeapSize,
                    limit: performance.memory.jsHeapSizeLimit,
                    timestamp: Date.now()
                };

                this.memoryUsage.push(memory);

                // Keep only last 100 measurements
                if (this.memoryUsage.length > 100) {
                    this.memoryUsage.shift();
                }

                // Warn if memory usage is high
                const usagePercent = (memory.used / memory.limit) * 100;
                if (usagePercent > 80) {
                    debugConsole.warn('High memory usage detected:', usagePercent.toFixed(2) + '%');
                    this.suggestCleanup();
                }
            }
        };

        // Check every 30 seconds
        const memoryTimer = setInterval(checkMemory, 30000);
        this.timers.add(memoryTimer);

        // Initial check
        checkMemory();
    }

    monitorLongTasks() {
        try {
            const longTaskObserver = new PerformanceObserver((list) => {
                list.getEntries().forEach((entry) => {
                    debugConsole.warn('Long task detected:', {
                        duration: entry.duration,
                        startTime: entry.startTime,
                        name: entry.name
                    });

                    // Suggest optimization if task is very long
                    if (entry.duration > 100) {
                        this.suggestOptimization('long-task', entry);
                    }
                });
            });

            longTaskObserver.observe({ entryTypes: ['longtask'] });
            this.observers.add(longTaskObserver);
        } catch (e) {
            // Long task monitoring not supported
        }
    }

    monitorLayoutShifts() {
        try {
            const clsObserver = new PerformanceObserver((list) => {
                let clsValue = 0;

                list.getEntries().forEach((entry) => {
                    if (!entry.hadRecentInput) {
                        clsValue += entry.value;
                    }
                });

                if (clsValue > 0.1) {
                    debugConsole.warn('Layout shift detected:', clsValue);
                    this.suggestOptimization('layout-shift', { value: clsValue });
                }
            });

            clsObserver.observe({ entryTypes: ['layout-shift'] });
            this.observers.add(clsObserver);
        } catch (e) {
            // Layout shift monitoring not supported
        }
    }

    monitorResourceLoading() {
        // Monitor slow loading resources
        const resourceObserver = new PerformanceObserver((list) => {
            list.getEntries().forEach((entry) => {
                if (entry.duration > 1000) { // Slower than 1 second
                    debugConsole.warn('Slow resource loading:', {
                        name: entry.name,
                        duration: entry.duration,
                        type: entry.initiatorType
                    });
                }
            });
        });

        try {
            resourceObserver.observe({ entryTypes: ['resource'] });
            this.observers.add(resourceObserver);
        } catch (e) {
            debugConsole.log('Resource monitoring not supported');
        }
    }

    measureFunction(name, fn) {
        const start = performance.now();
        const result = fn();
        const end = performance.now();
        const duration = end - start;

        this.metrics.set(name, {
            duration,
            timestamp: Date.now(),
            calls: (this.metrics.get(name)?.calls || 0) + 1
        });

        if (duration > 16) { // Longer than one frame
            debugConsole.warn(`Slow function detected: ${name} took ${duration.toFixed(2)}ms`);
        }

        return result;
    }

    async measureAsyncFunction(name, fn) {
        const start = performance.now();
        const result = await fn();
        const end = performance.now();
        const duration = end - start;

        this.metrics.set(name, {
            duration,
            timestamp: Date.now(),
            calls: (this.metrics.get(name)?.calls || 0) + 1
        });

        return result;
    }

    suggestCleanup() {
        debugConsole.group('Performance Optimization Suggestions');

        // Check for potential memory leaks
        const suggestions = [];

        // Check event listeners
        if (window.stockEventListeners && window.stockEventListeners.length > 50) {
            suggestions.push('Consider cleaning up event listeners - found ' + window.stockEventListeners.length);
        }

        // Check DOM nodes
        const allElements = document.querySelectorAll('*');
        if (allElements.length > 1000) {
            suggestions.push('Large DOM detected - consider virtual scrolling or pagination');
        }

        // Check timers
        if (this.timers.size > 10) {
            suggestions.push('Many active timers detected - consider cleanup');
        }

        suggestions.forEach(suggestion => debugConsole.log('💡', suggestion));

        if (suggestions.length === 0) {
            debugConsole.log('✅ No immediate optimization suggestions');
        }

        debugConsole.groupEnd();
    }

    suggestOptimization(type, data) {
        const suggestions = {
            'long-task': [
                'Consider breaking up long-running operations',
                'Use requestAnimationFrame for DOM updates',
                'Implement debouncing for frequent operations',
                'Consider using Web Workers for heavy computations'
            ],
            'layout-shift': [
                'Set explicit dimensions for images and videos',
                'Reserve space for dynamic content',
                'Avoid inserting content above existing content',
                'Use CSS transforms instead of changing layout properties'
            ]
        };

        debugConsole.group(`Performance Issue: ${type}`);
        debugConsole.log('Data:', data);
        debugConsole.log('Suggestions:');
        suggestions[type]?.forEach(suggestion => debugConsole.log('💡', suggestion));
        debugConsole.groupEnd();
    }

    getMetrics() {
        return {
            functions: Object.fromEntries(this.metrics),
            memory: this.memoryUsage.slice(-10), // Last 10 measurements
            timestamp: Date.now()
        };
    }

    logMetrics() {
        debugConsole.table(this.getMetrics().functions);

        if (this.memoryUsage.length > 0) {
            const latest = this.memoryUsage[this.memoryUsage.length - 1];
            debugConsole.log('Memory Usage:', {
                used: (latest.used / 1024 / 1024).toFixed(2) + ' MB',
                total: (latest.total / 1024 / 1024).toFixed(2) + ' MB',
                percentage: ((latest.used / latest.limit) * 100).toFixed(2) + '%'
            });
        }
    }

    setupCleanup() {
        const cleanup = () => {
            // Disconnect observers
            this.observers.forEach(observer => {
                try {
                    observer.disconnect();
                } catch (e) {
                    debugConsole.warn('Error disconnecting observer:', e);
                }
            });
            this.observers.clear();

            // Clear timers
            this.timers.forEach(timer => clearInterval(timer));
            this.timers.clear();

            // Clear metrics
            this.metrics.clear();
            this.memoryUsage = [];

            debugConsole.log('Performance monitor cleaned up');
        };

        window.addEventListener('beforeunload', cleanup);
        window.addEventListener('unload', cleanup);

        // Also provide manual cleanup
        this.cleanup = cleanup;
    }

    // Utility methods for common optimizations
    static debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func.apply(this, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    static throttle(func, limit) {
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
    }

    static batchDOMUpdates(callback) {
        requestAnimationFrame(callback);
    }

    static measureDOMOperation(name, operation) {
        const start = performance.now();
        const result = operation();
        const end = performance.now();

        if (end - start > 16) {
            debugConsole.warn(`Slow DOM operation: ${name} took ${(end - start).toFixed(2)}ms`);
        }

        return result;
    }
}

// Initialize performance monitor
const performanceMonitor = new PerformanceMonitor();

// Make utilities globally available
window.PerformanceMonitor = PerformanceMonitor;
window.performanceMonitor = performanceMonitor;

// Add keyboard shortcut to view metrics (Ctrl+Shift+P)
document.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.shiftKey && e.key === 'P') {
        e.preventDefault();
        performanceMonitor.logMetrics();
    }
});

// Export for module systems (Node.js/CommonJS)
// eslint-disable-next-line no-undef
if (typeof module !== 'undefined' && typeof module.exports !== 'undefined') {
    // eslint-disable-next-line no-undef
    module.exports = PerformanceMonitor;
}