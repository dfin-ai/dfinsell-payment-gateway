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

  $('#dfinsell-sync-accounts').on('click', function(e) {
    e.preventDefault();

    const $button = $(this);
    const $status = $('#dfinsell-sync-status');
    const originalButtonText = $button.text();
    const $syncTarget = $('.dfinsell-single-account');
    console.log($syncTarget.length); 
    const isSandbox = $('#woocommerce_dfinsell_sandbox').is(':checked');

    // Clear previous messages
    $('.dfinsell-sync-error').remove();
    $status.removeClass('error success').hide();

    // Get keys based on mode
    const publicKey = isSandbox
        ? $('[name="woocommerce_dfinsell_sandbox_public_key"]').val().trim()
        : $('[name="woocommerce_dfinsell_public_key"]').val().trim();

    const secretKey = isSandbox
        ? $('[name="woocommerce_dfinsell_sandbox_secret_key"]').val().trim()
        : $('[name="woocommerce_dfinsell_secret_key"]').val().trim();

    // Validate
    if (!publicKey || !secretKey) {
        const modeLabel = isSandbox ? 'Sandbox' : 'Live';
        const missingFields = [];
        if (!publicKey) missingFields.push('Public Key');
        if (!secretKey) missingFields.push('Secret Key');
    
        const errorMessage = `${modeLabel} ${missingFields.join(' and ')} required for sync.`;
        
        console.log($syncTarget.length); 
        console.log(errorMessage);
    
        // Ensure target exists before inserting
        if ($syncTarget.length) {
            $('<div class="dfinsell-sync-error" style="color: red; margin-left: 241px;">')
                .text(errorMessage)
                .insertAfter($syncTarget);
        } else {
            alert(errorMessage); // Fallback if the target div is missing
        }
    
        return; // Stop sync
    }

    // Proceed with sync
    $button.prop('disabled', true);
    $button.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> Syncing...');
    $status.text('Syncing accounts...').show();

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
                setTimeout(function() {
                    window.location.reload();
                }, 2000);
            } else {
                $status.addClass('error').text(response.data.message || 'Sync failed. Please try again.');
            }
        },
        error: function(xhr, status, error) {
            let errorMessage = 'AJAX Error: ';
            if (xhr.responseJSON?.data?.message) {
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

   $('form').on('submit', function (event) {
    $('.error-message').remove(); // Clear previous messages

    const isSandbox = $('#woocommerce_dfinsell_sandbox').is(':checked');

    // Fields
    const $livePublic = $('[name="woocommerce_dfinsell_public_key"]');
    const $liveSecret = $('[name="woocommerce_dfinsell_secret_key"]');
    const $sandboxPublic = $('[name="woocommerce_dfinsell_sandbox_public_key"]');
    const $sandboxSecret = $('[name="woocommerce_dfinsell_sandbox_secret_key"]');

    const livePublic = $livePublic.val().trim();
    const liveSecret = $liveSecret.val().trim();
    const sandboxPublic = $sandboxPublic.val().trim();
    const sandboxSecret = $sandboxSecret.val().trim();

    // Track if a field already has an error shown
    const shownErrors = new Set();

    function showError($field, message) {
        const fieldName = $field.attr('name');
        if (!shownErrors.has(fieldName)) {
            $field.after(`<div class="error-message" style="color:red; margin-top:4px;">${message}</div>`);
            shownErrors.add(fieldName);
        }
    }

    let hasErrors = false;

    // Required fields (only current mode)
    if (isSandbox) {
        if (!sandboxPublic) {
            showError($sandboxPublic, 'Public Key is required.');
            hasErrors = true;
        }
        if (!sandboxSecret) {
            showError($sandboxSecret, 'Secret Key is required.');
            hasErrors = true;
        }
    } else {
        if (!livePublic) {
            showError($livePublic, 'Public Key is required.');
            hasErrors = true;
        }
        if (!liveSecret) {
            showError($liveSecret, 'Secret Key is required.');
            hasErrors = true;
        }
    }

    // Same key (same mode) validation
    if (sandboxPublic && sandboxSecret && sandboxPublic === sandboxSecret) {
        showError($sandboxSecret, 'Sandbox Secret Key must be different from Sandbox Public Key.');
        hasErrors = true;
    }

    if (livePublic && liveSecret && livePublic === liveSecret) {
        showError($liveSecret, 'Live Secret Key must be different from Live Public Key.');
        hasErrors = true;
    }

    // Cross-mode comparisons (only one error per field)
    if (livePublic && sandboxPublic && livePublic === sandboxPublic) {
        showError($livePublic, 'Live Public Key and Sandbox Public Key must be different.');
        showError($sandboxPublic, 'Live Public Key and Sandbox Public Key must be different.');
        hasErrors = true;
    }

    if (liveSecret && sandboxSecret && liveSecret === sandboxSecret) {
        showError($liveSecret, 'Live Secret Key and Sandbox Secret Key must be different.');
        showError($sandboxSecret, 'Live Secret Key and Sandbox Secret Key must be different.');
        hasErrors = true;
    }

    if (livePublic && sandboxSecret && livePublic === sandboxSecret) {
        showError($livePublic, 'Live Public Key and Sandbox Secret Key must be different.');
        showError($sandboxSecret, 'Live Public Key and Sandbox Secret Key must be different.');
        hasErrors = true;
    }

    if (liveSecret && sandboxPublic && liveSecret === sandboxPublic) {
        showError($liveSecret, 'Live Secret Key and Sandbox Public Key must be different.');
        showError($sandboxPublic, 'Live Secret Key and Sandbox Public Key must be different.');
        hasErrors = true;
    }

    if (hasErrors) {
        event.preventDefault();
    }
});








});




