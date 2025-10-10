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

    /*$(document).on("change", ".sandbox-checkbox", function () {
        let sandboxContainer = $(this).closest(".dfinsell-account").find(".sandbox-key");
        if ($(this).is(":checked")) {
            sandboxContainer.show();
        } else {
            sandboxContainer.hide();
            sandboxContainer.find("input").val("");
        }
    }); */

    // ✅ Fix: Properly delete accounts
    $(document).on("click", ".delete-account-btn", function () {
        let $account = $(this).closest(".dfinsell-account");
        let index = $account.attr("data-index");

        // Mark deleted accounts (so they don't get submitted)
        $account.find("input").each(function () {
            $(this).attr("name", ""); // Remove name so it won't be submitted
        });

        $account.remove();
        updateAccountIndices();

        if ($(".dfinsell-account").length === 0) {
            $(".dfinsell-accounts-container").prepend('<div class="empty-account"> No any account added </div>');
        }
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

    function showErrorMessage(input, message) {
        $(input).next(".error-message").remove(); // Remove existing error
        $(input).after(`<div class="error-message" style="color: red; font-size: 12px;">${message}</div>`);
    }

    function clearErrorMessages() {
        $(".error-message").remove();
    }

    $(document).on("submit", "form", function (event) {
        clearErrorMessages(); // Clear previous errors
        let livePublicKeys = new Set();
        let liveSecretKeys = new Set();
        let sandboxPublicKeys = new Set();
        let sandboxSecretKeys = new Set();
        let hasErrors = false;
        let prioritySet = new Set(); // To track unique priority values

        $(".dfinsell-account").each(function () {
            let livePublicKey = $(this).find(".live-public-key");
            let liveSecretKey = $(this).find(".live-secret-key");
            let sandboxPublicKey = $(this).find(".sandbox-public-key");
            let sandboxSecretKey = $(this).find(".sandbox-secret-key");
            let sandboxCheckbox = $(this).find(".sandbox-checkbox");
            let title = $(this).find(".account-title");
            let priority = $(this).find(".account-priority");

            let livePublicKeyVal = livePublicKey.val().trim();
            let liveSecretKeyVal = liveSecretKey.val().trim();
            let sandboxPublicKeyVal = sandboxPublicKey.val().trim();
            let sandboxSecretKeyVal = sandboxSecretKey.val().trim();
            let titleVal = title.val().trim();
            let priorityVal = priority.val().trim();

            if (!titleVal) {
                showErrorMessage(title, "Title is required.");
                hasErrors = true;
            }
            if (!priorityVal) {
                showErrorMessage(priority, "Priority is required.");
                hasErrors = true;
            }
            else if (prioritySet.has(priorityVal)) {
				showErrorMessage(priority, "Priority must be unique.");
				hasErrors = true;
			} else {
				prioritySet.add(priorityVal);
			}

          
            if (!livePublicKeyVal) {
                showErrorMessage(livePublicKey, "Public Key is required.");
                hasErrors = true;
            }
            if (!liveSecretKeyVal) {
                showErrorMessage(liveSecretKey, "Secret Key is required.");
                hasErrors = true;
            }

            // Live Keys are required
            if (livePublicKeyVal && livePublicKeys.has(livePublicKeyVal)) {
                showErrorMessage(livePublicKey, "Live Public Key must be unique.");
                hasErrors = true;
            } else if (livePublicKeyVal) {
                livePublicKeys.add(livePublicKeyVal);
            }

            if (liveSecretKeyVal && liveSecretKeys.has(liveSecretKeyVal)) {
                showErrorMessage(liveSecretKey, "Live Secret Key must be unique.");
                hasErrors = true;
            } else if (liveSecretKeyVal) {
                liveSecretKeys.add(liveSecretKeyVal);
            }

            if (sandboxPublicKeyVal && sandboxPublicKeys.has(sandboxPublicKeyVal)) {
                showErrorMessage(sandboxPublicKey, "Sandbox Public Key must be unique.");
                hasErrors = true;
            } else if (sandboxPublicKeyVal) {
                sandboxPublicKeys.add(sandboxPublicKeyVal);
            }

            if (sandboxSecretKeyVal && sandboxSecretKeys.has(sandboxSecretKeyVal)) {
                showErrorMessage(sandboxSecretKey, "Sandbox Secret Key must be unique.");
                hasErrors = true;
            } else if (sandboxSecretKeyVal) {
                sandboxSecretKeys.add(sandboxSecretKeyVal);
            }

            // ✅ Ensure sandbox keys are mandatory if the checkbox is checked
            if (sandboxCheckbox.is(":checked")) {
                if (!sandboxPublicKeyVal) {
                    showErrorMessage(sandboxPublicKey, "Sandbox Public Key is required.");
                    hasErrors = true;
                }
                if (!sandboxSecretKeyVal) {
                    showErrorMessage(sandboxSecretKey, "Sandbox Secret Key is required.");
                    hasErrors = true;
                }
            }
        });

        if (hasErrors) {
            event.preventDefault(); // Stop form submission
        }
    });

    $(document).on("change", ".sandbox-checkbox", function () {
        let sandboxContainer = $(this).closest(".dfinsell-account").find(".sandbox-key");
        if ($(this).is(":checked")) {
            sandboxContainer.show();
        } else {
            sandboxContainer.hide();
            sandboxContainer.find("input").val("").next(".error-message").remove(); // Clear errors if unchecked
        }
    });
    
});
