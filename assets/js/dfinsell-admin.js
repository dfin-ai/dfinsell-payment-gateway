jQuery(document).ready(function ($) {
  // Sanitize the PAYMENT_CODE parameter
  const PAYMENT_CODE =
    typeof params.PAYMENT_CODE === 'string' ? $.trim(params.PAYMENT_CODE) : ''

  function toggleSandboxFields() {
    // Ensure PAYMENT_CODE is sanitized and valid
    if (PAYMENT_CODE) {
      var sandboxChecked = $(
        '#woocommerce_' + $.escapeSelector(PAYMENT_CODE) + '_sandbox'
      ).is(':checked')

      if (sandboxChecked) {
        $('.' + $.escapeSelector(PAYMENT_CODE) + '-sandbox-keys')
          .closest('tr')
          .show()
        $('.' + $.escapeSelector(PAYMENT_CODE) + '-production-keys')
          .closest('tr')
          .hide()
      } else {
        $('.' + $.escapeSelector(PAYMENT_CODE) + '-sandbox-keys')
          .closest('tr')
          .hide()
        $('.' + $.escapeSelector(PAYMENT_CODE) + '-production-keys')
          .closest('tr')
          .show()
      }
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
