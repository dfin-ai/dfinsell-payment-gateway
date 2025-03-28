jQuery(document).ready(function ($) {
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

    $(document).on("change", ".sandbox-checkbox", function () {
        let sandboxContainer = $(this).closest(".dfinsell-account").find(".sandbox-key");
        if ($(this).is(":checked")) {
            sandboxContainer.show();
        } else {
            sandboxContainer.hide();
            sandboxContainer.find("input").val("");
        }
    });

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

    // ✅ Validate unique Live & Sandbox keys before form submission
    $(document).on("submit", "form", function (event) {
        let livePublicKeys = new Set();
        let liveSecretKeys = new Set();
        let sandboxPublicKeys = new Set();
        let sandboxSecretKeys = new Set();
        let hasDuplicate = false;

        $(".dfinsell-account").each(function () {
            let livePublicKey = $(this).find(".live-public-key").val().trim();
            let liveSecretKey = $(this).find(".live-secret-key").val().trim();
            let sandboxPublicKey = $(this).find(".sandbox-public-key").val().trim();
            let sandboxSecretKey = $(this).find(".sandbox-secret-key").val().trim();

            if (livePublicKey && livePublicKeys.has(livePublicKey)) {
                alert("Live Public Key must be unique.");
                hasDuplicate = true;
                return false;
            }
            if (liveSecretKey && liveSecretKeys.has(liveSecretKey)) {
                alert("Live Secret Key must be unique.");
                hasDuplicate = true;
                return false;
            }
            if (sandboxPublicKey && sandboxPublicKeys.has(sandboxPublicKey)) {
                alert("Sandbox Public Key must be unique.");
                hasDuplicate = true;
                return false;
            }
            if (sandboxSecretKey && sandboxSecretKeys.has(sandboxSecretKey)) {
                alert("Sandbox Secret Key must be unique.");
                hasDuplicate = true;
                return false;
            }

            // Add keys to the set
            if (livePublicKey) livePublicKeys.add(livePublicKey);
            if (liveSecretKey) liveSecretKeys.add(liveSecretKey);
            if (sandboxPublicKey) sandboxPublicKeys.add(sandboxPublicKey);
            if (sandboxSecretKey) sandboxSecretKeys.add(sandboxSecretKey);
        });

        if (hasDuplicate) {
            event.preventDefault(); // Stop form submission
        }
    });
});
