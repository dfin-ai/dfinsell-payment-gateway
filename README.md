# DFin Sell Payment Gateway

The DFin Sell Payment Gateway plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

## Plugin Information

**Contributors:** DFin Sell  
**Tags:** woocommerce, payment gateway, fiat, DFin Sell  
**Requires at least:** 6.2  
**Tested up to:** 6.2  
**Stable tag:** 1.0.10  
**License:** GPLv3 or later  
**License URI:** [GPLv3 License](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

For any issues or enhancement requests with this plugin, please contact the DFin support team. Ensure you provide your plugin, WooCommerce, and WordPress version where applicable to expedite troubleshooting.

## Getting Started

1. Obtain your API keys from your DFin dashboard in your Developer Settings - API Keys.
2. Follow the plugin installation instructions below.
3. You are ready to take payments in your WooCommerce store!

## Installation

### Minimum Requirements

- WooCommerce 8.9 or greater
- PHP version 8.0 or greater

### Steps

## 1. Download Plugin from GitHub

- Visit the GitHub repository for the DFin Sell Payment Gateway plugin at [GitHub Repository URL](https://github.com/dfin-ai/dfin-sell-payment-gateway).
- Download the plugin ZIP file to your local machine.

## 2. Install the Plugin in WordPress

- **Extract the Downloaded ZIP file to your Local Machine:**
  Extract the ZIP file containing the plugin files.

- **Upload via FTP or File Manager:**
  - Connect to your WordPress site via FTP or use File Manager in your hosting control panel.
  - Navigate to `wp-content/plugins` directory.
  - Upload the extracted plugin folder to the plugins directory on your server.

## 3. Activate the Plugin

- **Log in to WordPress Admin Dashboard:**
  Log in to your WordPress Admin Dashboard.
- **Navigate to Installed Plugins:**
  Go to `Plugins` > `Installed Plugins`.
- **Activate DFin Sell Payment Gateway:**
  - Locate the DFin Sell Payment Gateway plugin in the list.
  - Click `Activate` to enable the plugin.

## 4. Obtain API Keys from DFin Developer Settings Dashboard

- **Log in to DFin Account:**
  Visit the DFin website and log in to your account.
- **Navigate to Developer Settings to get API Keys:**
  Once logged in, find and access the Developer Settings.
- **Generate or Retrieve API Keys:**
  If API keys are not already generated, you can create new ones.
  Locate the API Keys or Credentials section.
  Generate or retrieve the required API keys (e.g., Public Key, Secret Key) needed for integration with the DFin Sell Payment Gateway plugin.

## 5. Update API Keys in WooCommerce Settings

- **Navigate to WooCommerce Settings:**
  Log in to your WordPress Admin Dashboard.
  Go to `WooCommerce` > `Settings`.
- **Access the Payments Tab:**
  Click on the `Payments` tab at the top of the settings page.
- **Select DFin Sell Payment Gateway:**
  Scroll down to find and select the DFin Sell Payment Gateway among the available payment methods.
- **Enter API Keys and Order Status:**
  Enter the API keys obtained from your DFin account into the respective fields:
  - Title: DFin Sell Payment Gateway
  - Description: Secure payments with DFin Sell Payment Gateway.
  - Public Key: [Your Public Key]
  - Secret Key: [Your Secret Key]
  - Order Status: Select `Processing` or `Completed` based on your preference.
- **Enable or Disable Payment Option:**
  Check the box to enable DFin Sell Payment Gateway as a payment option.
- **Save Changes:**
  Click `Save changes` at the bottom of the page to update and save your API key settings.

## 6. Place Order via DFin Payment Option

- **Visit Your Store Page and Add Products to Cart:**
  Navigate to your WordPress site's store page.
  Browse and add desired products to the cart.

- **Proceed to Checkout:**
  Go to your WordPress site's checkout page to review your order details.

- **Check Available Payment Methods:**
  Ensure that the DFin Sell Payment Gateway option is visible among the available payment methods listed on the checkout page.

- **Verify Integration:**
  Confirm that customers can select the DFin Sell Payment Gateway as a payment option when placing their orders.

## 7. Popup Window for Payment

- **Secure Payment Processing:**
  Upon selecting DFin, a secure popup window will open for payment processing.

## 8. Complete the Payment Process

- **Follow Instructions:**
  Follow the instructions provided in the popup window to securely complete the payment.

## 9. Redirect to WordPress Website with Order Status

- **After Successful Payment:**
  Once the payment is successfully processed, the popup window will automatically close.
  Customers will be redirected back to your WordPress site.

## 10. Check Orders in WordPress

- **Verify Order Status:**
  Log in to your WordPress Admin Dashboard.
  Navigate to `WooCommerce` > `Orders` to view all orders.
  Check for the latest orders placed using the DFin Sell Payment Gateway to verify their status.

## Documentation

The official documentation for this plugin is available at: [https://www.dfin.ai/api/docs/wordpress-plugin](https://www.dfin.ai/api/docs/wordpress-plugin)

## Changelog

### Version 1.0.10

- **Fixed Button Overlap on Checkout Page:** 
  Resolved an issue where buttons were overlapping on the checkout page, ensuring a smoother and more user-friendly experience.

### Version 1.0.9

- **Transaction Limit Handling:**  
  Added functionality to hide the plugin when the transaction amount exceeds the supported range or when no limit is set.

- **Enhanced Error Reporting:**  
  Included the plugin version in error messages to assist with troubleshooting when the transaction limit condition is met.

### Version 1.0.8

- **Added Mode Button:** Introduced a mode selection button, allowing users to switch between "Live" and "Test" modes directly in the plugin settings. This feature enables users to toggle seamlessly between environments for testing or production use.

### Version 1.0.7

- **Fixed Duplicate Order Issue:** Resolved an issue where multiple clicks on the "Place Order" button could result in duplicate orders. Now, the plugin properly handles repeated clicks, preventing duplicate transactions.

### Version 1.0.6

- **Sanitized, Escaped, and Validated Data:** To prevent a user from accidentally sending trash data through the system, as well as protecting them from potential security issues.
- **Generic function/class/define/namespace/option names:** Improved the uniqueness of the plugin on wordpress.

### Version 1.0.5

- **Fixed Nonce Verification Issue:** Resolved an issue with nonce verification that caused errors during the payment process. The nonce validation mechanism has been updated to ensure proper verification and smoother user experience.

### Version 1.0.4

- **Updated REST API Consent Handling:** Improved the consent handling mechanism in the REST API, ensuring better compliance with data privacy regulations.
- **Enhanced Security:** Implemented additional security measures for API requests, including improved API key verification and authorization checks.
- **Updated CORS Handling:** Refined CORS (Cross-Origin Resource Sharing) handling to ensure proper support for preflight requests and enhance security for API endpoints.
- **Added consent enable/disable option in settings:** Clients can now toggle the consent checkbox on or off.

### Version 1.0.3

- **Updated Consent Text:** Revised the consent text displayed on the payment page to: "I consent to the collection of my data to process this payment."

### Version 1.0.2

- **New Features and Enhancements:**

  - **Order Status Options:** You can now choose between "Processing" or "Completed" as the order status in payment settings, providing more flexibility in managing your orders.
  - **Improved Billing Address Handling:** Enhancements to how billing addresses are captured and processed, ensuring accuracy and compliance during the payment process.
  - **User Consent Compliance:** Added features to capture and handle user consent, aligning with data privacy regulations and enhancing trust with your customers.

### Version 1.0.1 (Initial Release)

- **Initial Release:** Launched the DFin Sell Payment Gateway plugin with core payment integration functionality for WooCommerce.

## Support

For customer support, visit: [https://www.dfin.ai/reach-out](https://www.dfin.ai/reach-out)

## Why Choose DFin Sell Payment Gateway?

With the DFin Sell Payment Gateway, you can easily transfer fiat payments to sell products. Choose DFin Sell Payment Gateway as your WooCommerce payment gateway to access your funds quickly through a powerful and secure payment engine provided by DFin.
