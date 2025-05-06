jQuery(document).ready(function ($) {
  // Sanitize the PAYMENT_CODE parameter
  const PAYMENT_CODE =
    typeof params.PAYMENT_CODE === 'string' ? $.trim(params.PAYMENT_CODE) : ''

  function toggleSandboxFields() {
    // Ensure PAYMENT_CODE is sanitized and valid
    if (PAYMENT_CODE) {
      // Check if sandbox mode is enabled based on checkbox state
      const sandboxChecked = $(
        '#woocommerce_' + $.escapeSelector(PAYMENT_CODE) + '_sandbox'
      ).is(':checked')

      // Selectors for sandbox and production key fields
      const sandboxSelector =
        '.' + $.escapeSelector(PAYMENT_CODE) + '-sandbox-keys'
      const productionSelector =
        '.' + $.escapeSelector(PAYMENT_CODE) + '-production-keys'

      // Show/hide sandbox and production key fields based on checkbox
      $(sandboxSelector).closest('tr').toggle(sandboxChecked)
      $(productionSelector).closest('tr').toggle(!sandboxChecked)
    }
  }

  // Initial toggle on page load
  toggleSandboxFields()

  // Toggle on checkbox change
  $('#woocommerce_' + $.escapeSelector(PAYMENT_CODE) + '_sandbox').change(
    function () {
      toggleSandboxFields()
    }
  )
})
jQuery(document).ready(function($) {
    $('#dfinsell-sync-accounts').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $status = $('#dfinsell-sync-status');
        var originalButtonText = $button.text();
        
        // Set loading state
        $button.prop('disabled', true);
        $button.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> Syncing...');
        $status.removeClass('error success').text('Syncing accounts...').show();
        
        $.ajax({
            url: dfinsell_ajax_object.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'dfinsell_manual_sync',
                nonce: dfinsell_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.addClass('success').text(response.data.message || 'Sync completed successfully!');
                    
                    // Refresh the page after 2 seconds to show updated statuses
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    $status.addClass('error').text(response.data.message || 'Sync failed. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'AJAX Error: ';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage += xhr.responseJSON.data.message;
                } else {
                    errorMessage += error;
                }
                $status.addClass('error').text(errorMessage);
            },
            complete: function() {
                $button.prop('disabled', false);
                $button.text(originalButtonText);
            }
        });
    });

    // Function to update all account statuses
    function updateAccountStatuses() {
        var sandboxEnabled = $('#woocommerce_dfinsell_sandbox').is(':checked');
        var liveStatus =$('input[name="live_status"]').val();
            var sandboxStatus =$('input[name="sandbox_status"]').val();
            if (!sandboxStatus) {
                sandboxStatus = 'unknown';
            }
            var $statusLabel =$('.dfinsell-status-label');

            if (sandboxEnabled) {
                // Update class and text for sandbox mode
                $statusLabel
                    .removeClass('live-status invalid active inactive')
                    .addClass('sandbox-status ' + sandboxStatus.toLowerCase())
                    .text('Status: ' + sandboxStatus);
            } else {
                // Update class and text for live mode
                $statusLabel
                    .removeClass('sandbox-status invalid active inactive')
                    .addClass('live-status ' + liveStatus.toLowerCase())
                    .text('Status: ' + liveStatus);
            }
       
    }

    // When checkbox is changed, update statuses
    $('#woocommerce_dfinsell_sandbox').on('change', function() {
        updateAccountStatuses();
    });

    // Optional: Update once on page load also (in case something is missed)
   // updateAccountStatuses();



});