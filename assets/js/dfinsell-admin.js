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
  // Add Account
  $('.dfinsell-add-account').on('click', function(e) {
      e.preventDefault();
      var $container = $('.dfinsell-accounts-container');
      var index = $container.find('.dfinsell-account').length;
      var html = `
          <div class="dfinsell-account">
              <h4>Account ${index + 1}</h4>
              <input type="text" name="accounts[${index}][title]" placeholder="Account Title">
              <h5>Sandbox Keys</h5>
              <input type="text" name="accounts[${index}][sandbox_public_key]" placeholder="Sandbox Public Key">
              <input type="text" name="accounts[${index}][sandbox_secret_key]" placeholder="Sandbox Secret Key">
              <h5>Live Keys</h5>
              <input type="text" name="accounts[${index}][live_public_key]" placeholder="Live Public Key">
              <input type="text" name="accounts[${index}][live_secret_key]" placeholder="Live Secret Key">
              <button class="button dfinsell-remove-account">Remove</button>
          </div>
      `;
      $container.append(html);
  });

  // Remove Account
  $('.dfinsell-accounts-container').on('click', '.dfinsell-remove-account', function(e) {
      e.preventDefault();
      $(this).closest('.dfinsell-account').remove();
  });
});

/*
jQuery(document).ready(function($) {
  // Validate form before submission
  $('form').on('submit', function(e) {
      var isValid = true;
      var errorMessage = '';

      // Loop through each account
      $('.dfinsell-account').each(function(index) {
          var $account = $(this);
          var title = $account.find('input[name*="title"]').val();
          var sandboxPublicKey = $account.find('input[name*="sandbox_public_key"]').val();
          var sandboxSecretKey = $account.find('input[name*="sandbox_secret_key"]').val();
          var livePublicKey = $account.find('input[name*="live_public_key"]').val();
          var liveSecretKey = $account.find('input[name*="live_secret_key"]').val();

          // Check if the account is partially filled
          var isFilled = title && sandboxPublicKey && sandboxSecretKey && livePublicKey && liveSecretKey;
          var isEmpty = !title && !sandboxPublicKey && !sandboxSecretKey && !livePublicKey && !liveSecretKey;

          if (!isEmpty && !isFilled) {
              isValid = false;
              errorMessage = `Account ${index + 1} is invalid. Please fill all fields or leave the account empty.`;
              return false; // Exit the loop
          }
      });

      // Check if at least one account is filled
      if (isValid) {
          var hasValidAccount = false;
          $('.dfinsell-account').each(function() {
              var $account = $(this);
              var title = $account.find('input[name*="title"]').val();
              if (title) {
                  hasValidAccount = true;
                  return false; // Exit the loop
              }
          });

          if (!hasValidAccount) {
              isValid = false;
              errorMessage = 'At least one valid account is required.';
          }
      }

      // Show error message and prevent form submission
      if (!isValid) {
          alert(errorMessage);
          e.preventDefault();
      }
  });
});

*/