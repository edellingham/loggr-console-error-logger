/**
 * Console Error Logger - Frontend JavaScript
 * Captures and logs browser console errors, JavaScript errors, and AJAX failures
 * @version 1.0.0
 */

(function($) {
    'use strict';
    
    // Early exit if required objects are not available
    if (typeof $ === 'undefined' || typeof cel_ajax === 'undefined') {
        console.warn('Console Error Logger: Required dependencies not loaded');
        return;
    }
    
    // Configuration
    const CEL = {
        sessionId: null,
        loginFormSubmitted: false,
        loginTimeoutTimer: null,
        errorQueue: [],
        isProcessing: false,
        maxQueueSize: 50,
        batchTimeout: null,
        rateLimitCounter: 0,
        rateLimitResetTime: 0,
        initialized: false
    };
    
    // Generate or retrieve session ID with improved randomness
    function getSessionId() {
        if (!CEL.sessionId) {
            // Use crypto.getRandomValues for better randomness when available
            let randomPart;
            if (window.crypto && window.crypto.getRandomValues) {
                const array = new Uint32Array(2);
                window.crypto.getRandomValues(array);
                randomPart = array[0].toString(36) + array[1].toString(36);
            } else {
                // Fallback for older browsers - use substring instead of deprecated substr
                randomPart = Math.random().toString(36).substring(2, 11);
            }
            CEL.sessionId = 'cel_' + Date.now() + '_' + randomPart;
        }
        return CEL.sessionId;
    }
    
    // Check rate limiting
    function isRateLimited() {
        const now = Date.now();
        
        // Reset counter if time window has passed (1 minute)
        if (now > CEL.rateLimitResetTime) {
            CEL.rateLimitCounter = 0;
            CEL.rateLimitResetTime = now + 60000; // 1 minute
        }
        
        // Check if we've exceeded the limit (10 errors per minute)
        if (CEL.rateLimitCounter >= 10) {
            return true;
        }
        
        CEL.rateLimitCounter++;
        return false;
    }
    
    // Log error to server with improved validation
    function logError(errorData) {
        console.log('CEL: logError called with:', errorData); // Debug log
        
        // Validate input
        if (!errorData || typeof errorData !== 'object') {
            console.warn('Console Error Logger: Invalid error data provided');
            return;
        }
        
        // Check rate limiting
        if (isRateLimited()) {
            console.warn('Console Error Logger: Rate limit exceeded, skipping error log');
            return;
        }
        
        // Sanitize error data to prevent XSS
        const sanitizedErrorData = sanitizeErrorData(errorData);
        
        // Add to queue
        CEL.errorQueue.push(sanitizedErrorData);
        
        // Limit queue size
        if (CEL.errorQueue.length > CEL.maxQueueSize) {
            CEL.errorQueue = CEL.errorQueue.slice(-CEL.maxQueueSize);
        }
        
        // Process queue with debouncing
        if (CEL.batchTimeout) {
            clearTimeout(CEL.batchTimeout);
        }
        
        CEL.batchTimeout = setTimeout(processErrorQueue, 100);
    }
    
    // Process error queue
    function processErrorQueue() {
        if (CEL.isProcessing || CEL.errorQueue.length === 0) {
            return;
        }
        
        CEL.isProcessing = true;
        
        // Process one error at a time to avoid overwhelming the server
        const error = CEL.errorQueue.shift();
        console.log('CEL: Processing error from queue:', error); // Debug log
        
        // Add session and page information
        error.session_id = getSessionId();
        error.page_url = window.location.href;
        error.user_agent = navigator.userAgent;
        error.is_login_page = cel_ajax.is_login_page;
        
        console.log('CEL: Sending AJAX request to:', cel_ajax.ajax_url); // Debug log
        
        // Send to server
        $.ajax({
            url: cel_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cel_log_error',
                nonce: cel_ajax.nonce,
                error_data: JSON.stringify(error)
            },
            success: function(response) {
                console.log('CEL: AJAX Success:', response); // Debug log
                if (!response.success && response.data && response.data.message) {
                    console.error('CEL: Server error:', response.data.message);
                }
            },
            error: function(xhr, status, errorMsg) {
                console.log('CEL: AJAX Error:', status, errorMsg, xhr.responseText); // Debug log
            },
            complete: function() {
                CEL.isProcessing = false;
                
                // Process next error if queue is not empty
                if (CEL.errorQueue.length > 0) {
                    setTimeout(processErrorQueue, 100);
                }
            }
        });
    }
    
    // Capture JavaScript errors
    window.addEventListener('error', function(event) {
        const errorData = {
            error_type: 'error',
            error_message: event.message || 'Unknown error',
            error_source: event.filename || '',
            error_line: event.lineno || 0,
            error_column: event.colno || 0,
            stack_trace: (event.error && event.error.stack) ? String(event.error.stack).substring(0, 2000) : '', // Limit stack trace length
            timestamp: new Date().toISOString()
        };
        
        // Classify error type
        if (event.error) {
            if (event.error.name === 'SyntaxError') {
                errorData.error_type = 'syntax_error';
            } else if (event.error.name === 'TypeError') {
                errorData.error_type = 'type_error';
            } else if (event.error.name === 'ReferenceError') {
                errorData.error_type = 'reference_error';
            }
        }
        
        logError(errorData);
    });
    
    // Capture unhandled promise rejections
    window.addEventListener('unhandledrejection', function(event) {
        const errorData = {
            error_type: 'unhandledrejection',
            error_message: event.reason ? 
                           (event.reason.message || event.reason.toString()) : 
                           'Unhandled promise rejection',
            stack_trace: event.reason && event.reason.stack ? event.reason.stack : '',
            timestamp: new Date().toISOString()
        };
        
        logError(errorData);
    });
    
    // Override console methods
    const originalConsoleError = console.error;
    const originalConsoleWarn = console.warn;
    
    console.error = function() {
        // Call original console.error
        originalConsoleError.apply(console, arguments);
        
        // Log to server
        const errorData = {
            error_type: 'console.error',
            error_message: Array.from(arguments).map(arg => {
                if (typeof arg === 'object') {
                    try {
                        return JSON.stringify(arg);
                    } catch (e) {
                        return String(arg);
                    }
                }
                return String(arg);
            }).join(' '),
            timestamp: new Date().toISOString()
        };
        
        // Try to extract stack trace
        try {
            throw new Error();
        } catch (e) {
            errorData.stack_trace = e.stack;
        }
        
        logError(errorData);
    };
    
    console.warn = function() {
        // Call original console.warn
        originalConsoleWarn.apply(console, arguments);
        
        // Log to server (only if it looks like an error)
        const message = Array.from(arguments).join(' ');
        
        // Filter out non-error warnings
        const errorKeywords = ['error', 'fail', 'exception', 'critical', 'fatal'];
        const shouldLog = errorKeywords.some(keyword => 
            message.toLowerCase().includes(keyword)
        );
        
        if (shouldLog) {
            const errorData = {
                error_type: 'console.warn',
                error_message: message,
                timestamp: new Date().toISOString()
            };
            
            logError(errorData);
        }
    };
    
    // Monitor AJAX errors (jQuery)
    if ($ && $.ajaxSetup) {
        $(document).ajaxError(function(event, jqXHR, ajaxSettings, thrownError) {
            // Skip our own logging requests
            if (ajaxSettings.url && ajaxSettings.url.includes('cel_log_error')) {
                return;
            }
            
            const errorData = {
                error_type: 'ajax_error',
                error_message: thrownError || jqXHR.statusText || 'AJAX request failed',
                error_source: ajaxSettings.url || '',
                additional_data: {
                    request_url: ajaxSettings.url,
                    request_method: ajaxSettings.type,
                    response_status: jqXHR.status,
                    response_text: jqXHR.responseText ? 
                                  jqXHR.responseText.substring(0, 500) : ''
                },
                timestamp: new Date().toISOString()
            };
            
            logError(errorData);
        });
    }
    
    // Monitor Fetch API errors
    if (window.fetch) {
        const originalFetch = window.fetch;
        
        window.fetch = function() {
            const fetchArgs = arguments;
            const url = typeof fetchArgs[0] === 'string' ? 
                       fetchArgs[0] : 
                       (fetchArgs[0].url || 'unknown');
            
            return originalFetch.apply(this, fetchArgs)
                .then(function(response) {
                    // Check for HTTP errors
                    if (!response.ok) {
                        const errorData = {
                            error_type: 'fetch_error',
                            error_message: `Fetch failed: ${response.status} ${response.statusText}`,
                            error_source: url,
                            additional_data: {
                                request_url: url,
                                response_status: response.status,
                                response_statusText: response.statusText
                            },
                            timestamp: new Date().toISOString()
                        };
                        
                        logError(errorData);
                    }
                    
                    return response;
                })
                .catch(function(error) {
                    const errorData = {
                        error_type: 'fetch_error',
                        error_message: error.message || 'Fetch request failed',
                        error_source: url,
                        stack_trace: error.stack || '',
                        additional_data: {
                            request_url: url
                        },
                        timestamp: new Date().toISOString()
                    };
                    
                    logError(errorData);
                    
                    // Re-throw the error so it can be handled by the calling code
                    throw error;
                });
        };
    }
    
    // Monitor resource loading errors
    window.addEventListener('error', function(event) {
        console.log('CEL: Error event captured:', event); // Debug log
        
        // Check if this is a resource loading error (not a JavaScript error)
        if (event.target !== window && event.target.tagName) {
            const target = event.target;
            let resourceUrl = '';
            let resourceType = '';
            
            switch (target.tagName.toLowerCase()) {
                case 'script':
                    resourceUrl = target.src;
                    resourceType = 'JavaScript';
                    break;
                case 'link':
                    resourceUrl = target.href;
                    resourceType = 'CSS';
                    break;
                case 'img':
                    resourceUrl = target.src;
                    resourceType = 'Image';
                    break;
                default:
                    resourceUrl = target.src || target.href || '';
                    resourceType = target.tagName;
            }
            
            if (resourceUrl) {
                console.log('CEL: Logging resource error:', resourceUrl); // Debug log
                
                const errorData = {
                    error_type: 'resource_error',
                    error_message: `Failed to load ${resourceType}: ${resourceUrl}`,
                    error_source: resourceUrl,
                    timestamp: new Date().toISOString()
                };
                
                logError(errorData);
            }
        }
    }, true); // Use capture phase
    
    // Login-specific monitoring
    if (cel_ajax.is_login_page) {
        $(document).ready(function() {
            const $loginForm = $('#loginform, #login-form, form[name="loginform"]');
            
            if ($loginForm.length) {
                // Monitor login form submission
                $loginForm.on('submit', function() {
                    CEL.loginFormSubmitted = true;
                    
                    // Start timeout detection
                    const timeoutSeconds = parseInt(cel_ajax.login_timeout) || 10;
                    
                    CEL.loginTimeoutTimer = setTimeout(function() {
                        // Check if we're still on the login page
                        if (CEL.loginFormSubmitted && 
                            (window.location.href.includes('wp-login.php') || 
                             $('#loginform').length > 0)) {
                            
                            // Check for spinning indicators
                            const spinningSelectors = [
                                '.spinner.is-active',
                                '.loading:visible',
                                '.loader:visible',
                                '.ajax-loading:visible',
                                'input[type="submit"]:disabled'
                            ];
                            
                            let isSpinning = false;
                            for (let selector of spinningSelectors) {
                                if ($(selector).length > 0) {
                                    isSpinning = true;
                                    break;
                                }
                            }
                            
                            // Log timeout error
                            const errorData = {
                                error_type: 'login_timeout',
                                error_message: `Login attempt timeout after ${timeoutSeconds} seconds` + 
                                             (isSpinning ? ' (spinner detected)' : ''),
                                additional_data: {
                                    timeout_duration: timeoutSeconds,
                                    spinner_detected: isSpinning,
                                    username: $('#user_login, #username, input[name="log"]').val() || ''
                                },
                                timestamp: new Date().toISOString()
                            };
                            
                            logError(errorData);
                            
                            // Show user-friendly message
                            const $errorMessage = $('<div>')
                                .addClass('notice notice-error')
                                .html('<p><strong>Login timeout detected.</strong> ' +
                                     'The login process is taking longer than expected. ' +
                                     'This issue has been logged for investigation.</p>');
                            
                            $loginForm.before($errorMessage);
                        }
                    }, timeoutSeconds * 1000);
                });
                
                // Clear timeout if login succeeds (page changes)
                $(window).on('beforeunload', function() {
                    if (CEL.loginTimeoutTimer) {
                        clearTimeout(CEL.loginTimeoutTimer);
                    }
                });
            }
        });
    }
    
    // Performance monitoring
    if (window.performance && window.performance.timing) {
        window.addEventListener('load', function() {
            const timing = window.performance.timing;
            const loadTime = timing.loadEventEnd - timing.navigationStart;
            
            // Log if page load is extremely slow (> 10 seconds)
            if (loadTime > 10000) {
                const errorData = {
                    error_type: 'performance',
                    error_message: `Slow page load detected: ${(loadTime / 1000).toFixed(2)} seconds`,
                    additional_data: {
                        load_time: loadTime,
                        dom_ready: timing.domContentLoadedEventEnd - timing.navigationStart,
                        dns_time: timing.domainLookupEnd - timing.domainLookupStart,
                        connect_time: timing.connectEnd - timing.connectStart,
                        response_time: timing.responseEnd - timing.requestStart
                    },
                    timestamp: new Date().toISOString()
                };
                
                logError(errorData);
            }
        });
    }
    
    // Add sanitization function
    function sanitizeErrorData(errorData) {
        const sanitized = {};
        
        // List of allowed properties to prevent data injection
        const allowedProps = [
            'error_type', 'error_message', 'error_source', 'error_line', 'error_column',
            'stack_trace', 'timestamp', 'additional_data', 'session_id', 'page_url',
            'user_agent', 'is_login_page'
        ];
        
        allowedProps.forEach(function(prop) {
            if (errorData.hasOwnProperty(prop)) {
                let value = errorData[prop];
                if (typeof value === 'string') {
                    // Sanitize strings to prevent XSS
                    value = value.replace(/<script[^>]*>.*?<\/script>/gi, '[SCRIPT_REMOVED]')
                                 .replace(/<[^>]*>/g, '')
                                 .substring(0, 2000); // Limit length
                } else if (typeof value === 'object' && value !== null) {
                    try {
                        value = JSON.stringify(value);
                        value = value.substring(0, 1000); // Limit object serialization
                    } catch (e) {
                        value = '[Object serialization failed]';
                    }
                }
                sanitized[prop] = value;
            }
        });
        
        return sanitized;
    }
    
    // Cleanup on page unload with improved error handling
    window.addEventListener('beforeunload', function() {
        // Clear any pending timeouts to prevent memory leaks
        if (CEL.batchTimeout) {
            clearTimeout(CEL.batchTimeout);
        }
        if (CEL.loginTimeoutTimer) {
            clearTimeout(CEL.loginTimeoutTimer);
        }
        
        // Try to send any remaining errors
        if (CEL.errorQueue.length > 0 && navigator.sendBeacon && cel_ajax.ajax_url) {
            try {
                const formData = new FormData();
                formData.append('action', 'cel_log_error');
                formData.append('nonce', cel_ajax.nonce);
                
                // Limit the number of errors sent on unload to prevent performance issues
                const errorsToSend = CEL.errorQueue.slice(-10); // Only send last 10 errors
                errorsToSend.forEach(function(error) {
                    try {
                        formData.append('error_data', JSON.stringify(error));
                    } catch (e) {
                        console.warn('Console Error Logger: Failed to serialize error for beacon');
                    }
                });
                
                navigator.sendBeacon(cel_ajax.ajax_url, formData);
            } catch (e) {
                console.warn('Console Error Logger: Failed to send beacon data');
            }
        }
    });
    
    // Initialize the error logger
    function init() {
        if (CEL.initialized) {
            return;
        }
        CEL.initialized = true;
        console.log('Console Error Logger initialized successfully');
        console.log('CEL: Configuration:', cel_ajax);
        
        // Add test functions to window for manual testing
        window.CEL_Test = {
            triggerJSError: function() {
                console.log('CEL: Triggering JavaScript error...');
                nonExistentFunction();
            },
            
            triggerConsoleError: function() {
                console.log('CEL: Triggering console error...');
                console.error('Test console error message');
            },
            
            triggerResourceError: function() {
                console.log('CEL: Triggering resource error...');
                const img = document.createElement('img');
                img.src = '/test-broken-image-' + Date.now() + '.jpg';
                document.body.appendChild(img);
            },
            
            triggerAjaxError: function() {
                console.log('CEL: Triggering AJAX error...');
                $.ajax({
                    url: '/nonexistent-endpoint-' + Date.now(),
                    type: 'POST',
                    data: {test: 'data'}
                });
            },
            
            checkStatus: function() {
                console.log('CEL: Error queue length:', CEL.errorQueue.length);
                console.log('CEL: Rate limit counter:', CEL.rateLimitCounter);
                console.log('CEL: Is processing:', CEL.isProcessing);
            }
        };
        
        console.log('CEL: Test functions available as CEL_Test.*');
    }
    
    // Initialize when DOM is ready or immediately if already ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})(jQuery);