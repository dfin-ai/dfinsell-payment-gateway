jQuery(document).ready(function ($) {
    // Function to update the account indices dynamically
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

    // Toggle account info on caret icon click
    $(document).on("click", ".account-toggle-btn", function () {
        let accountContainer = $(this).closest(".dfinsell-account");
        let accountInfo = accountContainer.find(".account-info");

        accountInfo.slideToggle();
        $(this).toggleClass("rotated"); // Rotate icon
    });

    // Update account title dynamically when the account name input changes
    $(document).on("input", ".account-title", function () {
        let accountContainer = $(this).closest(".dfinsell-account");
        let newTitle = $(this).val().trim() || "Untitled Account"; // Default fallback title
        accountContainer.find(".account-name-display").text(newTitle);
    });

    // Toggle sandbox fields based on checkbox
    $(document).on("change", ".sandbox-checkbox", function () {
        let sandboxContainer = $(this).closest(".dfinsell-account").find(".sandbox-key");
        sandboxContainer.toggle($(this).is(":checked"));
    });

    // Handle account deletion
    $(document).on("click", ".delete-account-btn", function () {
        $(this).closest(".dfinsell-account").remove();
        updateAccountIndices();

        // Check if no accounts remain, show "No any account added"
        if ($(".dfinsell-account").length === 0) {
            $(".dfinsell-accounts-container").prepend('<div class="empty-account"> No any account added </div>');
        }
    });

    // Handle adding a new account
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

            <div class="account-info">
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
        $(".dfinsell-accounts-container").append(newAccountHtml);

        updateAccountIndices();
    });
});
