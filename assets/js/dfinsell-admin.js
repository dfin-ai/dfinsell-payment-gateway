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

    // Ensure all account details are hidden on load
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

    // Toggle sandbox fields and clear inputs when unchecked
    $(document).on("change", ".sandbox-checkbox", function () {
        let sandboxContainer = $(this).closest(".dfinsell-account").find(".sandbox-key");

        if ($(this).is(":checked")) {
            sandboxContainer.show();
        } else {
            sandboxContainer.hide();
            sandboxContainer.find("input").val(""); // Clear sandbox key fields
        }
    });

    $(document).on("click", ".delete-account-btn", function () {
        $(this).closest(".dfinsell-account").remove();
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
                    <i class="fa fa-user" aria-hidden="true"></i> 
                    <span class="account-name-display">Untitled Account</span>
                    &nbsp;<i class="fa fa-caret-down account-toggle-btn" aria-hidden="true"></i>
                </h4>
                <div class="action-button">
                    <button type="button" class="delete-account-btn"><i class="fa fa-trash" aria-hidden="true"></i></button>
                </div>
            </div>

            <div class="account-info" style="display: none;">
                <div class="account-input">
                    <label>Account Name</label>
                    <input type="text" class="account-title" name="accounts[][title]" placeholder="Account Title">
                </div>
                <div class="add-blog">
                    <div class="account-input">
                        <label>Live Keys</label>
                        <input type="text" class="live-public-key" name="accounts[][live_public_key]" placeholder="Live Public Key">
                    </div>
                    <div class="account-input">
                        <label>&nbsp;</label>
                        <input type="text" class="live-secret-key" name="accounts[][live_secret_key]" placeholder="Live Secret Key">
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
                            <input type="text" class="sandbox-public-key" name="accounts[][sandbox_public_key]" placeholder="Sandbox Public Key">
                        </div>
                        <div class="account-input">
                            <label>&nbsp;</label>
                            <input type="text" class="sandbox-secret-key" name="accounts[][sandbox_secret_key]" placeholder="Sandbox Secret Key">
                        </div>
                    </div>
                </div>
            </div>
        </div>`;

        $(".dfinsell-accounts-container .empty-account").remove();
        
        // ✅ Insert before the "Add Account" button
        $(".dfinsell-add-account").closest(".add-account-btn").before(newAccountHtml);

        updateAccountIndices();
    });
});
