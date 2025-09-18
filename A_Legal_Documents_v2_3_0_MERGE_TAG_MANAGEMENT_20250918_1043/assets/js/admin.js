jQuery(document).ready(function($) {
    console.log('LDA Admin initialized');

    // Reusable notification function
    function showNotification(message, type = 'info') {
        var $notification = $('<div class="notice is-dismissible"></div>');
        $notification.addClass('notice-' + type);
        $notification.html('<p>' + message + '</p>');
        $('.wrap h1').after($notification);
        setTimeout(function() {
            $notification.fadeOut('slow', function() {
                $(this).remove();
            });
        }, 5000);
    }

    // --- Template Button Handlers ---

    $(document).on('click', '.validate-template', function(e) {
        e.preventDefault();
        alert('functionality coming soon');
    });

    $(document).on('click', '.test-template', function(e) {
        e.preventDefault();
        alert('functionality coming soon');
    });

    $(document).on('click', '.delete-template', function(e) {
        e.preventDefault();
        
        if (!confirm(lda_admin.strings.confirm_delete || 'Are you sure you want to delete this template?')) {
            return;
        }

        var $button = $(this);
        var template = $button.data('template');
        var $row = $button.closest('tr');

        console.log('Deleting template:', template);
        $button.prop('disabled', true).text('Deleting...');

        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_delete_template',
                template: template,
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            console.log('Delete response:', response);
            if (response.success) {
                showNotification('Template "' + template + '" deleted successfully.', 'success');
                $row.fadeOut('slow', function() {
                    $(this).remove();
                });
            } else {
                showNotification('Error deleting template: ' + response.data, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('AJAX error:', status, error);
            showNotification('An unexpected error occurred while deleting the template. Check the browser console for details.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text('Delete');
        });
    });

    // --- Email Test Handler ---

    $(document).on('click', '#send-test-email', function(e) {
        e.preventDefault();
        
        var testEmail = $('#test-email').val();
        if (!testEmail) {
            showNotification('Please enter a test email address.', 'error');
            return;
        }
        
        if (!isValidEmail(testEmail)) {
            showNotification('Please enter a valid email address.', 'error');
            return;
        }
        
        var $button = $(this);
        var $result = $('#email-test-result');
        
        $button.prop('disabled', true).text(lda_admin.strings.testing || 'Testing...');
        $result.html('<p>Sending test email...</p>');
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_test_email',
                test_email: testEmail,
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            console.log('Email test response:', response);
            if (response.success) {
                var message = typeof response.data === 'string' ? response.data : 'Test email sent successfully!';
                $result.html('<div class="notice notice-success inline"><p>' + message + '</p></div>');
                showNotification('Test email sent successfully!', 'success');
            } else {
                var errorMessage = typeof response.data === 'string' ? response.data : 'Failed to send test email.';
                $result.html('<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>');
                showNotification('Failed to send test email: ' + errorMessage, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('Email test AJAX error:', status, error);
            $result.html('<div class="notice notice-error inline"><p>An unexpected error occurred. Check the browser console for details.</p></div>');
            showNotification('An unexpected error occurred while testing email.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text('Send Test Email');
        });
    });

    // Email validation helper
    function isValidEmail(email) {
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // --- Google Drive Credentials Upload Handler ---


    $(document).on('click', '#upload_credentials', function(e) {
        e.preventDefault();
        console.log('Upload credentials button clicked!');
        
        var fileInput = document.getElementById('gdrive_credentials_upload');
        console.log('File input found:', fileInput);
        
        if (!fileInput) {
            console.error('File input not found!');
            showNotification('File input not found. Please refresh the page.', 'error');
            return;
        }
        
        var file = fileInput.files[0];
        console.log('Selected file:', file);
        
        if (!file) {
            showNotification('Please select a credentials file to upload.', 'error');
            return;
        }
        
        if (file.type !== 'application/json') {
            showNotification('Please select a valid JSON file.', 'error');
            return;
        }
        
        var $button = $(this);
        var $result = $('#upload_result');
        
        $button.prop('disabled', true).text('Uploading...');
        $result.html('<p>Uploading credentials file...</p>');
        
        console.log('lda_admin object:', typeof lda_admin !== 'undefined' ? lda_admin : 'UNDEFINED');
        
        if (typeof lda_admin === 'undefined') {
            console.error('lda_admin object not available!');
            showNotification('Admin configuration not loaded. Please refresh the page.', 'error');
            return;
        }
        
        var formData = new FormData();
        formData.append('action', 'lda_upload_gdrive_credentials');
        formData.append('credentials_file', file);
        formData.append('nonce', lda_admin.nonce);
        
        console.log('Form data prepared, sending AJAX request...');
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        })
        .done(function(response) {
            console.log('Credentials upload response:', response);
            if (response.success) {
                $result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                showNotification('Google Drive credentials uploaded successfully!', 'success');
                // Reload the page to show the updated status
                setTimeout(function() {
                    location.reload();
                }, 2000);
            } else {
                $result.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                showNotification('Failed to upload credentials: ' + response.data, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('Credentials upload AJAX error:', status, error);
            $result.html('<div class="notice notice-error inline"><p>An unexpected error occurred. Check the browser console for details.</p></div>');
            showNotification('An unexpected error occurred while uploading credentials.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text('Upload Credentials');
        });
    });

    // --- Log Button Handlers ---

    $(document).on('click', '#refresh-logs', function() {
        console.log('Refreshing logs...');
        $(this).prop('disabled', true).text('Refreshing...');
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_get_logs',
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            if (response.success) {
                $('#log-entries').html(response.data);
                showNotification('Logs refreshed.', 'info');
            } else {
                showNotification('Failed to refresh logs.', 'error');
            }
        })
        .fail(function() {
            showNotification('An error occurred while refreshing logs.', 'error');
        })
        .always(function() {
            $('#refresh-logs').prop('disabled', false).text('Refresh');
        });
    });

    $(document).on('click', '#copy-logs', function() {
        console.log('Copying logs to clipboard...');
        var $button = $(this);
        var originalText = $button.text();
        
        $button.prop('disabled', true).text('Copying...');
        
        // Get all log entries text
        var logText = '';
        $('#log-entries .log-entry').each(function() {
            var $entry = $(this);
            var timestamp = $entry.find('.log-timestamp').text() || '';
            var level = $entry.find('.log-level').text() || '';
            var message = $entry.find('.log-message').text() || '';
            
            // Clean up the text and format it nicely
            logText += timestamp + ' ' + level + ' ' + message + '\n';
        });
        
        // If no log entries found, try to get the raw text
        if (!logText.trim()) {
            logText = $('#log-entries').text();
        }
        
        // Clean up the text
        logText = logText.trim();
        
        if (!logText) {
            showNotification('No logs to copy.', 'warning');
            $button.prop('disabled', false).text(originalText);
            return;
        }
        
        // Add a header to the copied text
        var headerText = '=== A Legal Documents Plugin Logs ===\n';
        headerText += 'Copied on: ' + new Date().toLocaleString() + '\n';
        headerText += '=====================================\n\n';
        
        var fullText = headerText + logText;
        
        // Use the modern clipboard API if available
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(fullText).then(function() {
                showNotification('Logs copied to clipboard successfully!', 'success');
            }).catch(function(err) {
                console.error('Failed to copy to clipboard:', err);
                fallbackCopyTextToClipboard(fullText);
            });
        } else {
            // Fallback for older browsers
            fallbackCopyTextToClipboard(fullText);
        }
        
        $button.prop('disabled', false).text(originalText);
    });
    
    // Fallback copy function for older browsers
    function fallbackCopyTextToClipboard(text) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        
        // Avoid scrolling to bottom
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";
        textArea.style.opacity = "0";
        
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                showNotification('Logs copied to clipboard successfully!', 'success');
            } else {
                showNotification('Failed to copy logs. Please select and copy manually.', 'error');
            }
        } catch (err) {
            console.error('Fallback copy failed:', err);
            showNotification('Failed to copy logs. Please select and copy manually.', 'error');
        }
        
        document.body.removeChild(textArea);
    }

    $(document).on('click', '#clear-logs', function() {
        if (!confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
            return;
        }

        console.log('Clearing logs...');
        $(this).prop('disabled', true).text('Clearing...');

        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_clear_logs',
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            if (response.success) {
                $('#log-entries').html('<p>No log entries found.</p>');
                showNotification('Logs cleared successfully.', 'success');
            } else {
                showNotification('Failed to clear logs: ' + response.data, 'error');
            }
        })
        .fail(function() {
            showNotification('An error occurred while clearing logs.', 'error');
        })
        .always(function() {
            $('#clear-logs').prop('disabled', false).text('Clear Logs');
        });
    });

});
