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

  toggleSandboxFields()

  $('#woocommerce_' + $.escapeSelector(PAYMENT_CODE) + '_sandbox').change(
    toggleSandboxFields
  )


    function validateAccounts() {
      $('.error-msg').remove(); // Clear existing error messages
      var isValid = false;
      var keySet = new Set(); // Store unique keys for cross-account validation
      var hasDuplicate = false;
  
      $('.dfinsell-account').each(function (index) {
        var allFilled = true;
        var currentKeys = [];
  
        $(this)
          .find('input')
          .each(function () {
            var value = $(this).val().trim();
            var fieldClass = $(this).attr('class');
  
            // Skip validation if the value is empty
            if (value === '') {
              allFilled = false;
              $(this).css('border', '2px solid red'); // Highlight empty fields
            } else {
              $(this).css('border', ''); // Reset border if filled
            }
  
            // Check for duplicate keys (only if the field is not empty)
            if (
              value !== '' &&
              (fieldClass.includes('sandbox-public-key') ||
                fieldClass.includes('sandbox-secret-key') ||
                fieldClass.includes('live-public-key') ||
                fieldClass.includes('live-secret-key'))
            ) {
              if (keySet.has(value)) {
                hasDuplicate = true;
                $(this).css('border', '2px solid red'); // Highlight duplicate key
              }
              keySet.add(value);
            }
          });
  
        if (allFilled) {
          isValid = true; // At least one fully filled account exists
        }
      });
  
      // Show error messages
      if (hasDuplicate) {
        $('.dfinsell-accounts-container').before(
          '<p class="error-msg" style="color:red; font-weight:bold;">Duplicate key detected across accounts! Each account must have unique keys.</p>'
        );
      }
  
      if (!isValid) {
        $('.dfinsell-accounts-container').before(
          '<p class="error-msg" style="color:red; font-weight:bold;">Please fill all details in at least one account.</p>'
        );
      }
  
      return isValid && !hasDuplicate;
    }
  
    // ADD ACCOUNT FUNCTIONALITY
    $('.dfinsell-add-account').on('click', function (e) {
      e.preventDefault();
      $('.error-msg').remove(); // Remove previous errors
  
      var $container = $('.dfinsell-accounts-container');
      var index = $container.find('.dfinsell-account').length;
      let isActive = index === 0;
      var html = `
        <div class="dfinsell-account">
           
            <h4>Account ${index + 1} <span class="active-indicator" style="${isActive ? 'display:inline;' : 'display:none;'}">✅ Active</span></h4>
            <input type="text" name="accounts[${index}][title]" class="account-title" placeholder="Account Title" required>
            
            <h5>Sandbox Keys</h5>
            <div class="add-blog">
            <input type="text" name="accounts[${index}][sandbox_public_key]" class="sandbox-public-key" placeholder="Sandbox Public Key" required>
            <input type="text" name="accounts[${index}][sandbox_secret_key]" class="sandbox-secret-key" placeholder="Sandbox Secret Key" required>
            </div>
            <h5>Live Keys</h5>
            <div class="add-blog">
            <input type="text" name="accounts[${index}][live_public_key]" class="live-public-key" placeholder="Live Public Key" required>
            <input type="text" name="accounts[${index}][live_secret_key]" class="live-secret-key" placeholder="Live Secret Key" required>
            <button class="button dfinsell-remove-account"><span>-</span></button>
            </div>
        </div>
      `;
  
      $container.append(html);
    });
  
    // REMOVE ACCOUNT FUNCTIONALITY
    $('.dfinsell-accounts-container').on(
      'click',
      '.dfinsell-remove-account',
      function (e) {
        e.preventDefault();
        $(this).closest('.dfinsell-account').remove();
        validateAccounts();
      }
    );
  
    // REAL-TIME INPUT VALIDATION
    $('.dfinsell-accounts-container').on('input', 'input', function () {
      validateAccounts();
    });
  
    // FORM SUBMISSION VALIDATION
    $('form').on('submit', function (e) {
      if (!validateAccounts()) {
        e.preventDefault(); // Prevent submission if validation fails
      }
    });
  });
 