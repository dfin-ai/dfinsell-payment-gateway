jQuery(document).ready(function ($) {
	// Sanitize the payment_method parameter
	const payment_method = typeof dfinsell_ajax_object.payment_method === 'string' ? $.trim(dfinsell_ajax_object.payment_method) : '';

	function toggleSandboxFields() {
		if (payment_method) {
			const sandboxChecked = $('#woocommerce_' + $.escapeSelector(payment_method) + '_sandbox').is(':checked');
			const sandboxSelector = '.' + $.escapeSelector(payment_method) + '-sandbox-keys';
			const productionSelector = '.' + $.escapeSelector(payment_method) + '-production-keys';

			$(sandboxSelector).closest('tr').toggle(sandboxChecked);
			$(productionSelector).closest('tr').toggle(!sandboxChecked);
		}
	}

	toggleSandboxFields();

	$('#woocommerce_' + $.escapeSelector(payment_method) + '_sandbox').change(toggleSandboxFields);

	function updateAccountIndices() {
		$(".dfinsell-account").each(function (index) {
			$(this).attr("data-index", index);
			$(this).find("input, select").each(function () {
				let name = $(this).attr("name");
				if (name) {
					name = name.replace(/\[.*?\]/, "[" + index + "]");
					$(this).attr("name", name);
				}
			});
		});
	}

	$(".account-info").hide();

	$(document).on("click", ".account-toggle-btn", function () {
		let accountInfo = $(this).closest(".dfinsell-account").find(".account-info");
		accountInfo.slideToggle();
		$(this).toggleClass("rotated");
	});

	$(document).on("input", ".account-title", function () {
		let newTitle = $(this).val().trim() || "Untitled Account";
		$(this).closest(".dfinsell-account").find(".account-name-display").text(newTitle);
	});

	$(document).on("click", ".delete-account-btn", function () {
		const $accounts = $(".dfinsell-account");

		// Remove any previous error message
		$(".delete-account-error").remove();

		if ($accounts.length === 1) {
			// Append inline error message before the account block
			$accounts.first().before('<div class="delete-account-error" style="color: red; margin-bottom: 10px;">At least one account must be present.</div>');
			return;
		}

		let $account = $(this).closest(".dfinsell-account");

		// Remove account
		$account.find("input").each(function () {
			$(this).attr("name", ""); // Prevent form submission
		});
		$account.remove();
		updateAccountIndices();
	});


	$(document).on("click", ".dfinsell-add-account", function () {
		let newAccountHtml = `
        <div class="dfinsell-account">
            <div class="title-blog">
                <h4>
                    <span class="account-name-display">Untitled Account</span>
                    &nbsp;<i class="fa fa-caret-down account-toggle-btn" aria-hidden="true"></i>
                </h4>
                <div class="action-button">
                    <button type="button" class="delete-account-btn"><i class="fa fa-trash" aria-hidden="true"></i></button>
                </div>
            </div>

            <div class="account-info" style="display: none;">
				<div class="add-blog title-priority">
	                <div class="account-input account-name">
	                    <label>Account Name</label>
	                    <input type="text" class="account-title" name="accounts[][title]" placeholder="Account Title">
	                </div>
					<div class="account-input priority-name">
                        <label>Priority</label>
                        <input type="number" class="account-priority" name="accounts[][priority]" placeholder="Priority" min="1">
                    </div>
				</div>
                <div class="add-blog">
                    <div class="account-input">
                        <label>Live Keys</label>
                        <input type="text" class="live-public-key" name="accounts[][live_public_key]" placeholder="Public Key">
                    </div>
                    <div class="account-input">
                        <input type="text" class="live-secret-key" name="accounts[][live_secret_key]" placeholder="Secret Key">
                    </div>
                </div>

                <div class="account-checkbox">
                    <input type="checkbox" class="sandbox-checkbox" name="accounts[][has_sandbox]">
                    Do you have the sandbox keys?
                </div>

                <div class="sandbox-key" style="display: none;">
                    <div class="add-blog">
                        <div class="account-input">
                            <label>Sandbox Keys</label>
                        <input type="text" class="sandbox-public-key" name="accounts[][sandbox_public_key]" placeholder="Public Key">
                        </div>
                        <div class="account-input">
                            <input type="text" class="sandbox-secret-key" name="accounts[][sandbox_secret_key]" placeholder="Secret Key">
                        </div>
                    </div>
                </div>
            </div>
        </div>`;

		$(".dfinsell-accounts-container .empty-account").remove();
		$(".dfinsell-add-account").closest(".add-account-btn").before(newAccountHtml);
		updateAccountIndices();
	});

	function showErrorMessage(inputField, message) {
		const $input = $(inputField); // ensure it's a jQuery object
		console.log(" :: showErrorMessage :: ", $input, message);

		$input.addClass("error");

		// Remove existing error message next to this input
		$input.siblings('.error-message').remove();

		// Create and insert error message after input
		const errorMessage = $("<div>").addClass("error-message").text(message);
		$input.after(errorMessage);
	}

	function clearErrorMessage(inputField) {
		const $input = $(inputField);
		$input.removeClass("error");
		$input.siblings('.error-message').remove();
	}

	function clearErrorMessages() {
		// Function to clear all previous error messages
		$(".error-message").remove();
		$(".error").removeClass("error");
	}

	$(document).off("submit", "form").on("submit", "form", function (event) {

		clearErrorMessages(); // Clear previous errors

		let allKeys = new Set();       // For unique key validation
		let prioritySet = new Set();   // For unique priority validation
		let titleSet = new Set();      // For unique title validation
		let hasErrors = false;

		function validateKeyUniqueness(inputField, keyValue, label) {
			if (allKeys.has(keyValue)) {
				showErrorMessage(inputField, `${label} must be unique across all accounts and key types.`);
				hasErrors = true;
			} else {
				allKeys.add(keyValue);
			}
		}

		// Validate gateway title
		const globalTitleField = $('#woocommerce_' + $.escapeSelector(payment_method) + '_title');
		const globalTitleVal = globalTitleField.val()?.trim() || '';
		if (!globalTitleVal) {
			showErrorMessage(globalTitleField, "Enter a name for this payment method (shown to customers at checkout).");
			hasErrors = true;
		}

		// Validate gateway description
		const globalDescField = $('#woocommerce_' + $.escapeSelector(payment_method) + '_description');
		const globalDescVal = globalDescField.val()?.trim() || '';
		if (!globalDescVal) {
			showErrorMessage(globalDescField, "Add a brief description shown to customers at checkout.");
			hasErrors = true;
		}

		$(".dfinsell-account").each(function () {
			const $account = $(this);

			const livePublicKey = $account.find(".live-public-key");
			const liveSecretKey = $account.find(".live-secret-key");
			const sandboxPublicKey = $account.find(".sandbox-public-key");
			const sandboxSecretKey = $account.find(".sandbox-secret-key");
			const sandboxCheckbox = $account.find(".sandbox-checkbox");
			const title = $account.find(".account-title");
			const priority = $account.find(".account-priority");

			const titleVal = title.val()?.trim() || '';
			const priorityVal = priority.val()?.trim() || '';
			const livePublicKeyVal = livePublicKey.val()?.trim() || '';
			const liveSecretKeyVal = liveSecretKey.val()?.trim() || '';
			const sandboxPublicKeyVal = sandboxPublicKey.val()?.trim() || '';
			const sandboxSecretKeyVal = sandboxSecretKey.val()?.trim() || '';

			// Title required and unique
			if (!titleVal) {
				console.log(" :: titleVal :: ", titleVal)
				showErrorMessage(title, "Title is required.");
				hasErrors = true;
			} else if (titleSet.has(titleVal)) {
				showErrorMessage(title, "Title must be unique.");
				hasErrors = true;
			} else {
				titleSet.add(titleVal);
			}

			console.log(" :: titleVal :: ", titleVal)
			console.log(" :: hasErrors :: ", hasErrors)

			// Priority required and unique
			if (!priorityVal) {
				showErrorMessage(priority, "Priority is required.");
				hasErrors = true;
			} else if (prioritySet.has(priorityVal)) {
				showErrorMessage(priority, "Priority must be unique.");
				hasErrors = true;
			} else {
				prioritySet.add(priorityVal);
			}

			// Live keys required
			if (!livePublicKeyVal) {
				showErrorMessage(livePublicKey, "Live Public Key is required.");
				hasErrors = true;
			}
			if (!liveSecretKeyVal) {
				showErrorMessage(liveSecretKey, "Live Secret Key is required.");
				hasErrors = true;
			}

			// Sandbox keys required (only if checkbox checked)
			const sandboxRequired = sandboxCheckbox.is(":checked");
			if (sandboxRequired) {
				if (!sandboxPublicKeyVal) {
					showErrorMessage(sandboxPublicKey, "Sandbox Public Key is required.");
					hasErrors = true;
				}
				if (!sandboxSecretKeyVal) {
					showErrorMessage(sandboxSecretKey, "Sandbox Secret Key is required.");
					hasErrors = true;
				}
			}

			// Global uniqueness across keys
			if (livePublicKeyVal) {
				validateKeyUniqueness(livePublicKey, livePublicKeyVal, "Live Public Key");
			}
			if (liveSecretKeyVal) {
				validateKeyUniqueness(liveSecretKey, liveSecretKeyVal, "Live Secret Key");
			}
			if (sandboxPublicKeyVal) {
				validateKeyUniqueness(sandboxPublicKey, sandboxPublicKeyVal, "Sandbox Public Key");
			}
			if (sandboxSecretKeyVal) {
				validateKeyUniqueness(sandboxSecretKey, sandboxSecretKeyVal, "Sandbox Secret Key");
			}

			// Same-account key duplication checks
			if (livePublicKeyVal && liveSecretKeyVal && livePublicKeyVal === liveSecretKeyVal) {
				showErrorMessage(liveSecretKey, "Live Secret Key must be different from Live Public Key.");
				hasErrors = true;
			}
			if (sandboxPublicKeyVal && sandboxSecretKeyVal && sandboxPublicKeyVal === sandboxSecretKeyVal) {
				showErrorMessage(sandboxSecretKey, "Sandbox Public Key and Sandbox Secret Key must be different.");
				hasErrors = true;
			}

			// Cross-type key collision (e.g., live vs sandbox)
			if (livePublicKeyVal && sandboxPublicKeyVal && livePublicKeyVal === sandboxPublicKeyVal) {
				showErrorMessage(sandboxPublicKey, "Live Public Key and Sandbox Public Key must be different.");
				hasErrors = true;
			}
			if (liveSecretKeyVal && sandboxSecretKeyVal && liveSecretKeyVal === sandboxSecretKeyVal) {
				showErrorMessage(sandboxSecretKey, "Live Secret Key and Sandbox Secret Key must be different.");
				hasErrors = true;
			}
		});

		// Final form blocking check
		if (hasErrors) {
			console.log("Form blocked due to validation errors.");
			event.preventDefault();
			$(this).find('[type="submit"]').removeClass('is-busy');
		} else {
			console.log("Form passed validation.");
		}
	});


	$(document).on("change", ".sandbox-checkbox", function () {
		let sandboxContainer = $(this).closest(".dfinsell-account").find(".sandbox-key");
		if ($(this).is(":checked")) {
			sandboxContainer.show();
		} else {
			sandboxContainer.hide();
			//sandboxContainer.find("input").val("").next(".error-message").remove(); // Clear errors if unchecked
		}
	});

	function runAccountSync() {
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
			success: function (response) {
				if (response.success) {
					$status
						.removeClass('error') // Clear previous state
						.addClass('success')
						.text(response.data.message || 'Sync completed successfully!')
						.fadeIn()       // Show message
						.delay(4000)    // Wait 4 seconds
						.fadeOut();     // Hide it


					// Re-render account statuses
					updateAccountStatuses(response.data.statuses);

				} else {
					$status
						.removeClass('success') // Clear previous state
						.addClass('error')
						.text(response.data.message || 'Sync failed. Please try again.')
						.fadeIn()
						.delay(4000)
						.fadeOut();
				}
			},
			error: function (xhr, status, error) {
				var errorMessage = 'AJAX Error: ';
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					errorMessage += xhr.responseJSON.data.message;
				} else {
					errorMessage += error;
				}
				$status.addClass('error').text(errorMessage);
			},
			complete: function () {
				$button.prop('disabled', false);
				$button.text(originalButtonText);
			}
		});
	}

	$('#dfinsell-sync-accounts').on('click', function (e) {
		e.preventDefault();

		runAccountSync();
	});

	// For the sandbox toggle (if it's a checkbox or select)
	$('#woocommerce_dfinsell_sandbox').on('change', function () {
		runAccountSync();
	});

	// Function to update all account statuses
	function updateAccountStatuses(statuses) {
		if (!Array.isArray(statuses)) return;

		statuses.forEach(function (statusItem) {
			var accountTitle = statusItem.title;
			var mode = statusItem.mode;
			var newStatus = statusItem.status;

			console.log(" :: statusItem :: ", statusItem)
			console.log('Updating status for:', accountTitle, mode, newStatus);

			// Loop through all accounts to find matching title
			$('.dfinsell-account').each(function () {
				var $account = $(this);
				var currentTitle = $.trim($account.find('.account-title').val());

				if (currentTitle === accountTitle) {
					// Update hidden inputs
					if (mode === 'live') {
						$account.find('.live-status').val(newStatus);
					} else if (mode === 'sandbox') {
						$account.find('.sandbox-status').val(newStatus);
					}

					// Update visible label
					var sandboxEnabled = $('#woocommerce_dfinsell_sandbox').is(':checked'); // <-- Updated
					var statusLabel = $account.find('.account-status-label');

					console.log(" :: sandboxEnabled :: ", sandboxEnabled)
					console.log(" :: statusLabel :: ", statusLabel)

					if ((sandboxEnabled && mode === 'sandbox') || (!sandboxEnabled && mode === 'live')) {
						statusLabel
							.removeClass('active inactive invalid unknown')
							.addClass(newStatus.toLowerCase())
							.text((mode === 'sandbox' ? 'Sandbox Account Status: ' : 'Live Account Status: ') + capitalize(newStatus));
					}
				}
			});
		});
	}

	function capitalize(text) {
		if (!text) return '';
		return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
	}

	// When checkbox is changed, update statuses
	$('#woocommerce_dfinsell_sandbox').on('change', function () {
		updateAccountStatuses();
	});

	// Optional: Update once on page load also (in case something is missed)
	// updateAccountStatuses();

});