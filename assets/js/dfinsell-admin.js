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
      const instructionsSelector = '.dfinsell-instructions-url'

      // Show/hide sandbox and production key fields based on checkbox
      $(sandboxSelector).closest('tr').toggle(sandboxChecked)
      $(productionSelector).closest('tr').toggle(!sandboxChecked)

      const baseUrl = sandboxChecked
        ? params.SIP_HOST_SANDBOX
        : params.SIP_HOST_LIVE

      // Full URL for the developer page
      const newUrl = baseUrl + '/developers'

      $(instructionsSelector).attr('href', newUrl)
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
