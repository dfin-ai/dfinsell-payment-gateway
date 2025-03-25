jQuery(document).ready(function ($) {
  // Sanitize the PAYMENT_CODE parameter
  const PAYMENT_CODE = typeof params.PAYMENT_CODE === 'string' ? $.trim(params.PAYMENT_CODE) : '';

  function toggleSandboxFields() {
      if (PAYMENT_CODE) {
          const sandboxChecked = $('#woocommerce_' + $.escapeSelector(PAYMENT_CODE) + '_sandbox').is(':checked');
          const sandboxSelector = '.' + $.escapeSelector(PAYMENT_CODE) + '-sandbox-keys';
          const productionSelector = '.' + $.escapeSelector(PAYMENT_CODE) + '-production-keys';

          $(sandboxSelector).closest('tr').toggle(sandboxChecked);
          $(productionSelector).closest('tr').toggle(!sandboxChecked);
      }
  }

  toggleSandboxFields();

  $('#woocommerce_' + $.escapeSelector(PAYMENT_CODE) + '_sandbox').change(toggleSandboxFields);

  // Function to validate accounts
  function validateAccounts() {
    $('.error-msg').remove(); // Clear existing error messages
    let isValid = false;
    let keyMap = new Map(); // Store keys with their type, account index, and value
    let hasDuplicate = false;
    let duplicateErrors = [];
    let partialAccounts = [];

    $('.dfinsell-account').each(function (index) {
        let allFilled = true;
        let anyFilled = false;

        $(this).find('input').each(function () {
            const value = $(this).val().trim();
            const fieldClass = $(this).attr('class');

            // Reset border before applying validation
            $(this).css('border', '');

            if (value === '') {
                allFilled = false;
            } else {
                anyFilled = true;
            }

            // Identify the key type
            let keyType = "";
            if (fieldClass.includes('sandbox-public-key')) keyType = "Sandbox Public Key";
            else if (fieldClass.includes('sandbox-secret-key')) keyType = "Sandbox Secret Key";
            else if (fieldClass.includes('live-public-key')) keyType = "Live Public Key";
            else if (fieldClass.includes('live-secret-key')) keyType = "Live Secret Key";

            // Check for duplicate keys (ignore empty fields)
            if (value !== '' && keyType !== "") {
                if (keyMap.has(value)) {
                    hasDuplicate = true;
                    let firstAccount = keyMap.get(value).accountIndex + 1; // Convert 0-based to 1-based
                    let firstKeyType = keyMap.get(value).keyType;

                    let errorMsg = `Duplicate ${firstKeyType} found in Account ${firstAccount} and ${keyType} in Account ${index + 1}. Value: <strong>${value}</strong>`;
                    duplicateErrors.push(errorMsg);
                    $(this).css('border', '2px solid red'); // Highlight duplicate key
                }
                keyMap.set(value, { accountIndex: index, keyType });
            }
        });

        // Skip the first account from partial validation
        if (index > 0 && anyFilled && !allFilled) {
            partialAccounts.push(index + 1); // Store 1-based index of the incomplete account
        }

        if (allFilled) {
            isValid = true; // At least one fully filled account exists
        }
    });

    if (hasDuplicate) {
        $('.dfinsell-accounts-container').before(
            `<p class="error-msg" style="color:red; font-weight:bold;">${duplicateErrors.join('<br>')}</p>`
        );
    }

    if (!isValid) {
        $('.dfinsell-accounts-container').before(
            '<p class="error-msg" style="color:red; font-weight:bold;">Please fill all details in at least one account.</p>'
        );
    }

    if (partialAccounts.length > 0) {
        $('.dfinsell-accounts-container').before(
            `<p class="error-msg" style="color:red; font-weight:bold;">Accounts ${partialAccounts.join(', ')} are partially filled. Please complete or remove them.</p>`
        );
        return false; // Prevent form submission
    }

    return isValid && !hasDuplicate;
}



  // ADD ACCOUNT FUNCTIONALITY
  $('.dfinsell-add-account').on('click', function (e) {
      e.preventDefault();
      $('.error-msg').remove(); // Remove previous errors

      const $container = $('.dfinsell-accounts-container');
      const index = $container.find('.dfinsell-account').length;
      const isActive = index === 0;
      
      const html = `
      <div class="dfinsell-account">
          <h4>Account ${index + 1} ${isActive ? '<span class="active-indicator" style="color: green; font-weight: bold;">✅ Active</span>' : ''}</h4>
          <input type="text" name="accounts[${index}][title]" class="account-title" placeholder="Account Title" >

          <h5>Sandbox Keys</h5>
          <div class="add-blog">
              <input type="text" name="accounts[${index}][sandbox_public_key]" class="sandbox-public-key" placeholder="Public Key" >
              <input type="text" name="accounts[${index}][sandbox_secret_key]" class="sandbox-secret-key" placeholder="Private Key" >
          </div>
          <h5>Live Keys</h5>
          <div class="add-blog">
              <input type="text" name="accounts[${index}][live_public_key]" class="live-public-key" placeholder="Public Key" >
              <input type="text" name="accounts[${index}][live_secret_key]" class="live-secret-key" placeholder="Private Key" >
              <button class="button dfinsell-remove-account"><span>-</span></button>
          </div>
      </div>`;

      $container.append(html);
  });

  // REMOVE ACCOUNT FUNCTIONALITY
  $('.dfinsell-accounts-container').on('click', '.dfinsell-remove-account', function (e) {
      e.preventDefault();
      
      $(this).closest('.dfinsell-account').remove(); // Remove the selected account
      updateAccountIndexes(); // Call function to update indexes
      validateAccounts(); // Revalidate after removal
  });

  // Function to re-index accounts dynamically
  function updateAccountIndexes() {
      $('.dfinsell-account').each(function (index) {
          // Update Account Title Numbering
          $(this).find('h4').html(
              `Account ${index + 1} ${index === 0 ? '<span class="active-indicator" style="color: green; font-weight: bold;">✅ Active</span>' : ''}`
          );

          // Update input field names to match the new index
          $(this).find('input').each(function () {
              var nameAttr = $(this).attr('name');
              if (nameAttr) {
                  var updatedName = nameAttr.replace(/\[\d+\]/, `[${index}]`); // Fix regex to only replace digits
                  $(this).attr('name', updatedName);
              }
          });
      });
  }

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
