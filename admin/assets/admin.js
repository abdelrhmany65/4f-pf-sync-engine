/**
 * 4F Realty - Property Finder Sync Engine (Front-end Interaction)
 * Updated for async background processing via Action Scheduler.
 */
jQuery(document).ready(function($) {
    
    $('#fourf-pf-sync-now').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $msg = $('#sync-status-msg');

        if (!confirm('Warning: Are you sure you want to start the manual sync now? This process will scan all pages and may take some time depending on data volume and server load.')) {
            return;
        }

        // Debounce protection: disable button to prevent duplicate clicks
        $btn.attr('disabled', true).text('Starting sync process...');
        $msg.css({
            'background-color': '#f0f6fa',
            'border-right': '4px solid #00a0d2',
            'color': '#001f3f',
            'display': 'block'
        }).text('Initiating background sync process... Check the log file for live tracking.');

        $.ajax({
            url: fourf_pf_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'fourf_pf_manual_trigger',
                nonce: fourf_pf_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    $msg.css({
                        'background-color': '#ecf7ed',
                        'border-right': '4px solid #46b450',
                        'color': '#235a29'
                    }).text('✅ ' + (response.data.message || 'Sync process started in background.'));
                } else {
                    var errorMsg = response.data && response.data.message ? response.data.message : 'A security or technical error occurred during processing.';
                    $msg.css({
                        'background-color': '#fbeae9',
                        'border-right': '4px solid #dc3232',
                        'color': '#8a1f1f'
                    }).text('❌ Error: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                $msg.css({
                    'background-color': '#fbeae9',
                    'border-right': '4px solid #dc3232',
                    'color': '#8a1f1f'
                }).text('❌ Server connection failed (Timeout or Server Error). Please check PHP execution time and Logger logs.');
            },
            complete: function() {
                // Restore button to its default ready state for the next test
                $btn.attr('disabled', false).html('<span class="dashicons dashicons-cloud-download" style="vertical-align: middle; margin-top: -4px;"></span> Run Sync Now');
            }
        });
    });
});
