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


function accountinfoshow() {
	console.log('hello...');
	var div = document.getElementById("account-info");
	if (div.style.display !== "none") {
		div.style.display = "none";
		document.getElementById("account-down-arrow").style.transform = "rotate(0deg)";

	}
	else {
		document.getElementById("account-down-arrow").style.transform = "rotate(180deg)";
		div.style.display = "block";

	}
}

function sandboxkey() {
	var checkBox = document.getElementById("sandbox-checkbox");
	var text = document.getElementById("sandbox-key");
	if (checkBox.checked == true){
		text.style.display = "block";
	} else {
		text.style.display = "none";
	}
}
