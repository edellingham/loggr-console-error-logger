/**
 * Console Error Logger - Admin JavaScript
 * @version 1.0.0
 */

(function($) {
    'use strict';
    
    // Early exit if required dependencies are not available
    if (typeof $ === 'undefined' || typeof cel_admin === 'undefined') {
        console.warn('Console Error Logger Admin: Required dependencies not loaded');
        return;
    }
    
    $(document).ready(function() {
        
        // Clear all logs button
        $('#cel-clear-logs').on('click', function(e) {
            e.preventDefault();
            
            if (!window.confirm(cel_admin.confirm_clear)) {
                return;
            }
            
            const $button = $(this);
            $button.prop('disabled', true).text('Clearing...');
            
            $.ajax({
                url: cel_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'cel_clear_logs',
                    nonce: cel_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to show empty logs
                        window.location.reload();
                    } else {
                        const errorMsg = (response.data && response.data.message) ? response.data.message : 'Failed to clear logs';
                        window.alert(errorMsg);
                        $button.prop('disabled', false).text('Clear All Logs');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Clear logs error:', textStatus, errorThrown);
                    window.alert('An error occurred while clearing logs. Please try again.');
                    $button.prop('disabled', false).text('Clear All Logs');
                }
            });
        });
        
        // View details button
        $('.cel-view-details').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const errorId = $button.data('error-id');
            const $detailsRow = $('#cel-error-' + errorId);
            
            if ($detailsRow.is(':visible')) {
                $detailsRow.hide();
                $button.text(cel_admin.view_details || 'Details');
            } else {
                // Hide other open details
                $('.cel-error-details').hide();
                $('.cel-view-details').text(cel_admin.view_details || 'Details');
                
                // Show this one
                $detailsRow.show();
                $button.text(cel_admin.hide_details || 'Hide');
            }
        });
        
        // Ignore error button
        $('.cel-ignore-error').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const errorMessage = $button.data('error-message');
            const errorSource = $button.data('error-source');
            
            // Show ignore options modal
            showIgnoreModal(errorMessage, errorSource, function(patternType, patternValue, notes) {
                $button.prop('disabled', true).text('Adding...');
                
                $.ajax({
                    url: cel_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cel_add_ignore_pattern',
                        nonce: cel_admin.nonce,
                        pattern_type: patternType,
                        pattern_value: patternValue,
                        notes: notes
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Ignore pattern added successfully!');
                            window.location.reload();
                        } else {
                            alert('Failed to add ignore pattern: ' + (response.data?.message || 'Unknown error'));
                            $button.prop('disabled', false).text('Ignore');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.error('Ignore pattern error:', textStatus, errorThrown);
                        alert('An error occurred while adding ignore pattern.');
                        $button.prop('disabled', false).text('Ignore');
                    }
                });
            });
        });
        
        // Toggle ignore pattern
        $('.cel-toggle-pattern').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const patternId = $button.data('pattern-id');
            
            $button.prop('disabled', true).text('Processing...');
            
            $.ajax({
                url: cel_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'cel_toggle_ignore_pattern',
                    nonce: cel_admin.nonce,
                    pattern_id: patternId
                },
                success: function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert('Failed to toggle pattern: ' + (response.data?.message || 'Unknown error'));
                        $button.prop('disabled', false).text('Toggle');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Toggle pattern error:', textStatus, errorThrown);
                    alert('An error occurred while toggling pattern.');
                    $button.prop('disabled', false).text('Toggle');
                }
            });
        });
        
        // Auto-refresh toggle for dashboard widget
        if ($('.cel-dashboard-widget').length) {
            let autoRefreshInterval = null;
            
            const startAutoRefresh = function() {
                autoRefreshInterval = setInterval(function() {
                    // Reload widget content via AJAX
                    $.ajax({
                        url: cel_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'cel_refresh_dashboard_widget',
                            nonce: cel_admin.nonce
                        },
                        success: function(response) {
                            if (response && response.success && response.data && response.data.html) {
                                const $widget = $('.cel-dashboard-widget');
                                if ($widget.length) {
                                    $widget.html(response.data.html);
                                }
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.warn('Dashboard widget refresh failed:', textStatus, errorThrown);
                        }
                    });
                }, 30000); // Refresh every 30 seconds
            };
            
            // Start auto-refresh if on dashboard
            if (window.location.pathname.includes('index.php')) {
                startAutoRefresh();
            }
            
            // Stop on page unload
            $(window).on('beforeunload', function() {
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                }
            });
        }
        
        // Filter form enhancements
        const $filterForm = $('.cel-logs-wrapper form');
        if ($filterForm.length) {
            // Add loading indicator
            $filterForm.on('submit', function() {
                $(this).find('input[type="submit"]').prop('disabled', true).val('Filtering...');
            });
            
            // Clear filters button
            const $clearButton = $('<input>')
                .attr('type', 'button')
                .addClass('button')
                .val('Clear Filters')
                .on('click', function() {
                    window.location.href = '?page=console-error-logger&tab=logs';
                });
            
            $filterForm.find('input[type="submit"]').after(' ').after($clearButton);
        }
        
        // Copy error details to clipboard
        $('.cel-details-content').each(function() {
            const $content = $(this);
            
            const $copyButton = $('<button>')
                .attr('type', 'button')
                .addClass('button button-small')
                .text('Copy to Clipboard')
                .on('click', function() {
                    const text = $content.find('pre, code').map(function() {
                        return $(this).text();
                    }).get().join('\n\n');
                    
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function() {
                            $copyButton.text('Copied!');
                            setTimeout(function() {
                                $copyButton.text('Copy to Clipboard');
                            }, 2000);
                        }).catch(function(err) {
                            console.warn('Clipboard write failed:', err);
                            // Fall back to the textarea method
                            fallbackCopyToClipboard(text, $copyButton);
                        });
                    } else {
                        fallbackCopyToClipboard(text, $copyButton);
                    }
                });
            
            $content.find('h4').append(' ').append($copyButton);
        });
        
        // Export functionality (future enhancement)
        const addExportButton = function() {
            const $exportButton = $('<button>')
                .attr('type', 'button')
                .addClass('button')
                .text('Export to CSV')
                .on('click', function() {
                    const params = new URLSearchParams(window.location.search);
                    params.set('export', 'csv');
                    window.location.href = '?' + params.toString();
                });
            
            $('.tablenav.top .alignright').prepend($exportButton).prepend(' ');
        };
        
        // Add export button if on logs tab
        if (window.location.search.includes('tab=logs') || 
            (!window.location.search.includes('tab=') && 
             window.location.search.includes('page=console-error-logger'))) {
            // Commented out for now - can be enabled when export functionality is added
            // addExportButton();
        }
        
        // Settings page enhancements
        const $settingsForm = $('.cel-settings-wrapper form');
        if ($settingsForm.length) {
            // Add unsaved changes warning
            let formChanged = false;
            
            $settingsForm.find('input, select, textarea').on('change', function() {
                formChanged = true;
            });
            
            $(window).on('beforeunload', function(e) {
                if (formChanged) {
                    const message = 'You have unsaved changes. Are you sure you want to leave?';
                    e.returnValue = message;
                    return message;
                }
                return undefined; // Explicitly return undefined for modern browsers
            });
            
            $settingsForm.on('submit', function() {
                formChanged = false;
            });
            
            // Real-time validation with better error handling
            $('#login_timeout_seconds').on('input', function() {
                const $this = $(this);
                const val = parseInt($this.val(), 10);
                if (isNaN(val) || val < 5) {
                    $this.val(5);
                } else if (val > 60) {
                    $this.val(60);
                }
            });
            
            $('#max_log_entries').on('input', function() {
                const $this = $(this);
                const val = parseInt($this.val(), 10);
                if (isNaN(val) || val < 100) {
                    $this.val(100);
                } else if (val > 10000) {
                    $this.val(10000);
                }
            });
            
            $('#auto_cleanup_days').on('input', function() {
                const $this = $(this);
                const val = parseInt($this.val(), 10);
                if (isNaN(val) || val < 0) {
                    $this.val(0);
                } else if (val > 365) {
                    $this.val(365);
                }
            });
        }
        
        // Fallback copy to clipboard function
        function fallbackCopyToClipboard(text, $button) {
            try {
                const $textarea = $('<textarea>')
                    .val(text)
                    .css({
                        position: 'fixed',
                        left: '-9999px',
                        top: '-9999px',
                        opacity: '0'
                    })
                    .appendTo('body');
                
                $textarea[0].select();
                $textarea[0].setSelectionRange(0, 99999); // For mobile devices
                
                const successful = document.execCommand('copy');
                $textarea.remove();
                
                if (successful) {
                    $button.text('Copied!');
                    setTimeout(function() {
                        $button.text('Copy to Clipboard');
                    }, 2000);
                } else {
                    $button.text('Copy failed');
                    setTimeout(function() {
                        $button.text('Copy to Clipboard');
                    }, 2000);
                }
            } catch (err) {
                console.error('Fallback copy failed:', err);
                $button.text('Copy failed');
                setTimeout(function() {
                    $button.text('Copy to Clipboard');
                }, 2000);
            }
        }
        
        // Stats page charts (using CSS for simple bar charts)
        const $statsTable = $('.cel-chart-section table');
        if ($statsTable.length) {
            $statsTable.find('tbody tr').each(function() {
                const $row = $(this);
                const $lastCell = $row.find('td:last');
                const percentageText = $lastCell.text().trim();
                const percentage = parseFloat(percentageText);
                
                if (!isNaN(percentage) && percentage >= 0 && percentage <= 100) {
                    const $bar = $('<div>')
                        .addClass('cel-stat-bar')
                        .css({
                            width: Math.min(percentage, 100) + '%',
                            height: '20px',
                            background: 'linear-gradient(90deg, #0073aa, #00a0d2)',
                            borderRadius: '3px',
                            marginTop: '5px',
                            minWidth: '2px' // Ensure visibility even for very small percentages
                        })
                        .attr({
                            'role': 'progressbar',
                            'aria-valuenow': percentage,
                            'aria-valuemin': '0',
                            'aria-valuemax': '100',
                            'aria-label': 'Statistics bar showing ' + percentage + '%'
                        });
                    
                    $lastCell.append($bar);
                }
            });
        }
        
        // Keyboard shortcuts
        $(document).on('keydown', function(e) {
            // Only on our admin page
            if (!window.location.search.includes('page=console-error-logger')) {
                return;
            }
            
            // Alt + C: Clear logs
            if (e.altKey && e.key === 'c') {
                e.preventDefault();
                $('#cel-clear-logs').click();
            }
            
            // Alt + R: Refresh page
            if (e.altKey && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }
            
            // Alt + 1/2/3: Switch tabs
            if (e.altKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        window.location.href = '?page=console-error-logger&tab=logs';
                        break;
                    case '2':
                        e.preventDefault();
                        window.location.href = '?page=console-error-logger&tab=stats';
                        break;
                    case '3':
                        e.preventDefault();
                        window.location.href = '?page=console-error-logger&tab=settings';
                        break;
                }
            }
        });
        
        // Add keyboard shortcuts help with better accessibility
        const $helpText = $('<div>')
            .attr({
                'role': 'note',
                'aria-label': 'Keyboard shortcuts information'
            })
            .css({
                float: 'right',
                fontSize: '12px',
                color: '#666',
                marginTop: '10px',
                clear: 'both'
            })
            .html('<strong>Keyboard shortcuts:</strong> Alt+1 (Logs), Alt+2 (Stats), Alt+3 (Settings), Alt+C (Clear), Alt+R (Refresh)');
        
        const $header = $('.wrap > h1');
        if ($header.length) {
            $header.after($helpText);
        }
        
        // Show ignore pattern modal
        function showIgnoreModal(errorMessage, errorSource, callback) {
            const $modal = $('<div>')
                .attr('id', 'cel-ignore-modal')
                .css({
                    position: 'fixed',
                    top: '0',
                    left: '0',
                    width: '100%',
                    height: '100%',
                    backgroundColor: 'rgba(0, 0, 0, 0.5)',
                    zIndex: '999999',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center'
                });
            
            const modalContent = `
                <div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
                    <h3 style="margin-top: 0;">Create Ignore Pattern</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Pattern Type:</label>
                        <select id="cel-pattern-type" style="width: 100%; padding: 8px;">
                            <option value="exact_message">Exact message match</option>
                            <option value="message_contains">Message contains</option>
                            <option value="exact_source">Exact source match</option>
                            <option value="source_contains">Source contains</option>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Pattern Value:</label>
                        <textarea id="cel-pattern-value" style="width: 100%; height: 80px; padding: 8px;" readonly>${errorMessage}</textarea>
                        <small style="color: #666;">This will be automatically filled based on the pattern type.</small>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Notes (optional):</label>
                        <textarea id="cel-pattern-notes" style="width: 100%; height: 60px; padding: 8px;" placeholder="Optional notes about why this error should be ignored"></textarea>
                    </div>
                    
                    <div style="text-align: right;">
                        <button type="button" id="cel-modal-cancel" class="button" style="margin-right: 10px;">Cancel</button>
                        <button type="button" id="cel-modal-confirm" class="button button-primary">Add Ignore Pattern</button>
                    </div>
                </div>
            `;
            
            $modal.html(modalContent);
            $('body').append($modal);
            
            // Update pattern value based on type selection
            function updatePatternValue() {
                const type = $('#cel-pattern-type').val();
                const $valueField = $('#cel-pattern-value');
                
                switch(type) {
                    case 'exact_message':
                    case 'message_contains':
                        $valueField.val(errorMessage);
                        break;
                    case 'exact_source':
                    case 'source_contains':
                        $valueField.val(errorSource || '');
                        break;
                }
            }
            
            // Initial update
            updatePatternValue();
            
            // Update on type change
            $('#cel-pattern-type').on('change', updatePatternValue);
            
            // Handle confirmation
            $('#cel-modal-confirm').on('click', function() {
                const patternType = $('#cel-pattern-type').val();
                const patternValue = $('#cel-pattern-value').val().trim();
                const notes = $('#cel-pattern-notes').val().trim();
                
                if (!patternValue) {
                    alert('Pattern value cannot be empty.');
                    return;
                }
                
                $modal.remove();
                callback(patternType, patternValue, notes);
            });
            
            // Handle cancellation
            $('#cel-modal-cancel').on('click', function() {
                $modal.remove();
            });
            
            // Close on escape key
            $(document).on('keydown.cel-modal', function(e) {
                if (e.key === 'Escape') {
                    $modal.remove();
                    $(document).off('keydown.cel-modal');
                }
            });
            
            // Close on backdrop click
            $modal.on('click', function(e) {
                if (e.target === this) {
                    $modal.remove();
                }
            });
        }
        
    });
    
})(jQuery);