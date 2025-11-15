/**
 * Nostr Post Sync JavaScript
 * 
 * Handles post edit page sync functionality with NIP-07 and WebSocket publishing
 */

(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        initNostrPostSync();
    });
    
    function initNostrPostSync() {
        // Manual sync button
        $('#nostr-manual-sync').on('click', function(e) {
            e.preventDefault();
            handleManualSync($(this));
        });
        
        // Refresh status button
        $('#nostr-refresh-status').on('click', function(e) {
            e.preventDefault();
            handleRefreshStatus($(this));
        });
    }
    
    function handleManualSync(button) {
        const postId = button.data('post-id');
        
        // Check if NIP-07 extension is available
        if (typeof window.nostr === 'undefined') {
            showMessage('NIP-07 extension not found. Please install a Nostr browser extension.', 'error');
            return;
        }
        
        button.prop('disabled', true).text('Syncing...');
        
        // Get event data for this post
        $.post(ajaxurl, {
            action: 'nostr_get_post_event',
            post_id: postId,
            nonce: nostrForWPAdmin.nonce
        }).done(function(response) {
            if (response.success) {
                // Sign the event with NIP-07
                window.nostr.signEvent(response.data.event).then(function(signedEvent) {
                    // Publish to WebSocket relays
                    publishToRelays(signedEvent, postId, button);
                }).catch(function(error) {
                    showMessage('Signing failed: ' + error, 'error');
                    button.prop('disabled', false).text('Sync Now');
                });
            } else {
                showMessage('Failed to get event data: ' + response.data, 'error');
                button.prop('disabled', false).text('Sync Now');
            }
        }).fail(function() {
            showMessage('Failed to get event data', 'error');
            button.prop('disabled', false).text('Sync Now');
        });
    }
    
    function publishToRelays(signedEvent, postId, button) {
        // Get relays from WordPress settings
        const relays = nostrForWPAdmin ? (nostrForWPAdmin.relays || []) : [];
        
        if (relays.length === 0) {
            showMessage('No relays configured. Please add relays in Nostr settings.', 'error');
            button.prop('disabled', false).text('Sync Now');
            return;
        }
        
        let successCount = 0;
        let completedCount = 0;
        
        showMessage('Publishing to ' + relays.length + ' relays...', 'info');
        
        relays.forEach(relay => {
            try {
                const ws = new WebSocket(relay);
                
                ws.onopen = function() {
                    console.log('Connected to ' + relay);
                    ws.send(JSON.stringify(['EVENT', signedEvent]));
                };
                
                ws.onmessage = function(event) {
                    try {
                        const data = JSON.parse(event.data);
                        if (data[0] === 'OK' && data[2] === true) {
                            successCount++;
                            console.log('Published to ' + relay + ' successfully!');
                        } else if (data[0] === 'OK' && data[2] === false) {
                            console.log('Relay ' + relay + ' rejected event: ' + data[3]);
                        }
                    } catch (e) {
                        console.log('Received non-JSON message from ' + relay + ': ' + event.data);
                    }
                    
                    completedCount++;
                    ws.close();
                    
                    // Check if all relays have responded
                    if (completedCount === relays.length) {
                        if (successCount > 0) {
                            showMessage('Published successfully to ' + successCount + ' out of ' + relays.length + ' relays!', 'success');
                            
                            // Mark post as synced
                            $.post(ajaxurl, {
                                action: 'nostr_mark_post_synced',
                                post_id: postId,
                                nonce: nostrForWPAdmin.nonce
                            }).done(function() {
                                // Refresh the page to show updated status
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            });
                        } else {
                            showMessage('Failed to publish to any relays. Check console for details.', 'error');
                            button.prop('disabled', false).text('Sync Now');
                        }
                    }
                };
                
                ws.onerror = function(error) {
                    console.log('WebSocket error for ' + relay + ':', error);
                    completedCount++;
                    if (completedCount === relays.length) {
                        showMessage('WebSocket connection failed for all relays.', 'error');
                        button.prop('disabled', false).text('Sync Now');
                    }
                };
                
                ws.onclose = function() {
                    console.log('Disconnected from ' + relay);
                };
                
                // Timeout after 10 seconds
                setTimeout(() => {
                    if (ws.readyState === WebSocket.OPEN) {
                        ws.close();
                    }
                }, 10000);
                
            } catch (error) {
                console.log('Failed to connect to ' + relay + ':', error);
                completedCount++;
                if (completedCount === relays.length) {
                    showMessage('Failed to connect to any relays.', 'error');
                    button.prop('disabled', false).text('Sync Now');
                }
            }
        });
    }
    
    function handleRefreshStatus(button) {
        const postId = button.data('post-id');
        
        button.prop('disabled', true).text('Refreshing...');
        
        $.post(ajaxurl, {
            action: 'nostr_get_sync_status',
            post_id: postId,
            nonce: nostrForWPAdmin.nonce
        }).done(function(response) {
            if (response.success) {
                updateStatusDisplay(response.data);
                showMessage('Status refreshed', 'info');
            } else {
                showMessage('Failed to refresh status', 'error');
            }
        }).fail(function() {
            showMessage('Status refresh request failed', 'error');
        }).always(function() {
            button.prop('disabled', false).text('Refresh Status');
        });
    }
    
    function updateStatusDisplay(statusData) {
        const statusSpan = $('#nostr-sync-status');
        let statusIcon = '';
        let statusText = '';
        
        switch (statusData.status) {
            case 'synced':
                statusIcon = '<span class="dashicons dashicons-yes" style="color: green;"></span>';
                statusText = 'Synced';
                if (statusData.synced_at) {
                    statusText += ' (' + statusData.synced_at + ')';
                }
                break;
            case 'failed':
                statusIcon = '<span class="dashicons dashicons-no" style="color: red;"></span>';
                statusText = 'Failed';
                break;
            case 'pending':
            default:
                statusIcon = '<span class="dashicons dashicons-clock" style="color: gray;"></span>';
                statusText = 'Pending';
        }
        
        statusSpan.html(statusIcon + ' ' + statusText);
    }
    
    function showMessage(message, type) {
        // Remove existing messages
        $('.nostr-message').remove();
        
        // Create new message
        const messageDiv = $('<div class="nostr-message ' + type + '">' + message + '</div>');
        $('#nostr-sync-meta-box').append(messageDiv);
        
        // Auto-remove success messages after 3 seconds
        if (type === 'success') {
            setTimeout(function() {
                messageDiv.fadeOut();
            }, 3000);
        }
    }
    
})(jQuery);
