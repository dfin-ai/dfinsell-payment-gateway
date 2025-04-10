=== DFin Sell Payment Gateway ===
Contributors: DFin Sell
Tags: woocommerce, payment gateway, fiat, DFin Sell
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.1.3 (Beta)  
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The DFin Sell Payment Gateway plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

== Description ==

This plugin integrates DFin Sell Payment Gateway with WooCommerce, enabling you to accept fiat payments. 

== Installation ==

1. Download the plugin ZIP file from GitHub.
2. Extract the ZIP file and upload it to the `wp-content/plugins` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Visit the DFin website and log in to your account. Navigate to Developer Settings to generate or retrieve API keys.

== Changelog ==

= 1.1.3 (Beta) =
* Implemented unique payment token handling for orders to prevent duplicate payment processing and enhance order reliability.

= 1.1.2 (Beta) =
* Fixes incorrect redirection of unpaid orders to the thank you page by ensuring only successful payments are redirected there. Unpaid orders are now handled appropriately.

= 1.1.1 (Beta) =
* Fixed: Improved the payment lock mechanism to prevent simultaneous transactions from conflicting when using multiple accounts.

= 1.1.0 (Beta) =
* Added Multiple Payment Accounts: Users can now add and manage multiple payment accounts, each with its own API keys and settings, allowing flexible payment configurations.
* Added special character validation on the checkout page to ensure secure data entry, preventing the use of unsupported or potentially harmful characters.

= 1.0.10 =
* Resolved an issue where buttons were overlapping on the checkout page, ensuring a smoother and more user-friendly experience.

= 1.0.9 =
* Added Transaction Limits Handling: Hides the payment gateway if the transaction amount exceeds the supported range or if the limit is not set.
* Enhanced Error Messages: Included plugin version information in error messages for better troubleshooting.
* Minor bug fixes and improvements.

= 1.0.8 =
* Added Mode Button: Introduced a mode selection button in the plugin settings, allowing users to switch between "Live" and "Test" modes for easier testing and production deployment.

= 1.0.7 =
* Fixed Duplicate Order Issue: Resolved an issue where multiple clicks on the "Place Order" button could result in duplicate orders.

= 1.0.6 =
* Sanitized, escaped, and validated data to enhance security and prevent potential vulnerabilities.
* Improved the uniqueness of function, class, define, namespace, and option names to avoid conflicts with other plugins and ensure better compatibility with WordPress.

= 1.0.5 =
* Fixed Nonce Verification Issue: Resolved an issue with nonce verification that caused errors during the payment process. The nonce validation mechanism has been updated to ensure proper verification and smoother user experience.

= 1.0.4 =
* Added detailed request and response logging.
* Handled both HTTP and HTTPS URLs for REST API endpoints.
* Updated REST API Consent Handling: Improved the consent handling mechanism in the REST API, ensuring better compliance with data privacy regulations.
* Enhanced Security: Implemented additional security measures for API requests, including improved API key verification and authorization checks.
* Updated CORS Handling: Refined CORS (Cross-Origin Resource Sharing) handling to ensure proper support for preflight requests and enhance security for API endpoints.
* Added consent enable/disable option in settings: Clients can now toggle the consent checkbox on or off.

= 1.0.3 =
* Updated consent text to: "I consent to the collection of my data to process this payment."

= 1.0.2 =
* Added order status field in payment settings.
* Improved billing address handling for the payment.
* Added user consent handling.

= 1.0.1 =
* Initial release.

== Upgrade Notice ==
= 1.1.3 (Beta) =
* Implemented unique payment token handling for orders to prevent duplicate payment processing and enhance order reliability.

= 1.1.2 (Beta) =
* Fixes incorrect redirection of unpaid orders to the thank you page by ensuring only successful payments are redirected there. Unpaid orders are now handled appropriately.

= 1.1.1 (Beta) =
* Fixed: Improved the payment lock mechanism to prevent simultaneous transactions from conflicting when using multiple accounts.

= 1.1.0 (Beta) =
* Added Multiple Payment Accounts: Users can now add and manage multiple payment accounts, each with its own API keys and settings, allowing flexible payment configurations.
* Added special character validation on the checkout page to ensure secure data entry, preventing the use of unsupported or potentially harmful characters.

= 1.0.10 =
* Resolved an issue where buttons were overlapping on the checkout page, ensuring a smoother and more user-friendly experience.

= 1.0.9 =
* Added Transaction Limits Handling: Now, the plugin is hidden if the transaction amount exceeds the supported range or if the limit is not set.
* Enhanced Error Messages: Plugin version information is included in error messages to help with troubleshooting.

= 1.0.8 =
* Added Mode Button: Switch between "Live" and "Test" modes directly in plugin settings for safer testing and smoother deployment to production.

= 1.0.7 =
* Fixed Duplicate Order Issue: Prevented duplicate orders caused by repeated clicks on the "Place Order" button.

= 1.0.6 =
* Improved security by sanitizing, escaping, and validating data.
* Enhanced compatibility by improving the uniqueness of function, class, define, namespace, and option names.

= 1.0.5 =
* Fixed Nonce Verification Issue: Resolved an issue with nonce verification that caused errors during the payment process. The nonce validation mechanism has been updated to ensure proper verification and smoother user experience.

= 1.0.4 =

Added logging for requests/responses.
Improved REST API consent and security.
Enhanced CORS handling.
Added consent toggle option in settings.

= 1.0.3 =
* Updated consent text to: "I consent to the collection of my data to process this payment."

= 1.0.2 =
* Added order status field in payment settings.
* Improved billing address handling for the payment.
* Added user consent handling.

= 1.0.1 =
Initial release.

== Support ==

For support, visit: [https://www.dfin.ai/reach-out](https://www.dfin.ai/reach-out)
