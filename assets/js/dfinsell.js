jQuery(function ($) {
	
	var isSubmitting = false; // Flag to track form submission
	var popupInterval; // Interval ID for checking popup status
	var paymentStatusInterval; // Interval ID for checking payment status
	var orderId; // To store the order ID
	var $button; // To store reference to the submit button
	var originalButtonText; // To store original button text
	// var isPollingActive = false; // Flag to ensure only one polling interval runs
	let isHandlerBound = false;

	const CHECK_ICON =
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
		'<path d="M5 12.5l4.5 4.5L19 7.5"></path>' +
		'</svg>';

	const SHIELD_ICON =
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
		'<path d="M12 3l7 3v6c0 4.2-2.9 7.6-7 9-4.1-1.4-7-4.8-7-9V6l7-3z"></path>' +
		'</svg>';

	const EXTERNAL_ICON =
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
		'<path d="M14 5h5v5"></path>' +
		'<path d="M19 5l-9 9"></path>' +
		'<path d="M18 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4"></path>' +
		'</svg>';

	// Sanitize loader URL and append loader image to the body
	var loaderUrl = dfinsell_params.dfin_loader ? encodeURI(dfinsell_params.dfin_loader) : '';
	$('body').append(
		'<div class="dfinsell-loader-background"></div>' +
		'<div class="dfinsell-loader"><img src="' + loaderUrl + '" alt="Loading..." /></div>'
	);

	// Disable default WooCommerce checkout for your custom payment method
	$('form.checkout').on('checkout_place_order', function () {
		var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();

		// Prevent WooCommerce default behavior for your custom method
		if (selectedPaymentMethod === dfinsell_params.payment_method) {
			return false; // Stop WooCommerce default script
		}
	});




	// Function to bind the form submit handler
	function bindCheckoutHandler() {
		if (isHandlerBound) return;
		isHandlerBound = true;

		// Unbind the previous handler before rebinding
		$("form.checkout").off("submit.dfinsell").on("submit.dfinsell", function (e) {
			// Check if the custom payment method is selected
			if ($(this).find('input[name="payment_method"]:checked').val() === dfinsell_params.payment_method) {
				handleFormSubmit.call(this, e);
				return false; // Prevent other handlers
			}
		});



	}

	// Rebind after checkout updates
	$(document.body).on("updated_checkout", function () {
		isHandlerBound = false; // Allow rebinding only once on the next update
		bindCheckoutHandler();
	});

	// Initial binding of the form submit handler
	bindCheckoutHandler();

	// Function to handle form submission
	function handleFormSubmit(e) {

		e.preventDefault(); // Prevent the form from submitting if already in progress

		var $form = $(this);
		

		// If a submission is already in progress, prevent further submissions
		if (isSubmitting) {
			return false;
		}

		// Set the flag to true to prevent further submissions
		isSubmitting = true;

		if ($form.find('input[name="radio-control-wc-payment-method-options"]:checked').val()) {
			var selectedPaymentMethod = $form.find('input[name="radio-control-wc-payment-method-options"]:checked').val();
			$button = $form.find('button.wc-block-components-checkout-place-order-button');
		}else{
			var selectedPaymentMethod = $form.find('input[name="payment_method"]:checked').val();
			$button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
		}

		if (selectedPaymentMethod !== dfinsell_params.payment_method) {
			isSubmitting = false; // Reset the flag if not using the custom payment method
			return true; // Allow default WooCommerce behavior
		}
		if ($form.find('input[name="radio-control-wc-payment-method-options"]:checked').val()) {
			// Disable the submit button immediately to prevent further clicks
			$button = $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button');
			originalButtonText = $button.text();
			$button.prop('disabled', true).text('Processing...');
		}else{
			// Disable the submit button immediately to prevent further clicks
			$button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
			originalButtonText = $button.text();
			$button.prop('disabled', true).text('Processing...');
		}

		// Show loader
		$('.dfinsell-loader-background, .dfinsell-loader').show();

		var data = $form.serialize();

		if ($form.find('input[name="radio-control-wc-payment-method-options"]:checked').val()) {
			$.ajax({
				method: 'POST',
				url: dfinsell_params.ajax_url,
				data: {
						action: 'dfinsell_block_gateway_process',
						nonce: dfinsell_params.dfinsell_nonce
						
				},
				success: function (response) {
					handleResponse(response, $form);
				},
				error: function () {
					handleError($form);
				},
				complete: function () {
					isSubmitting = false; // Always reset isSubmitting to false in case of success or error
				},
			});
		}else{
			$.ajax({
				type: 'POST',
				url: wc_checkout_params.checkout_url,
				data: data,
				dataType: 'json',
				success: function (response) {
					handleResponse(response, $form);
				},
				error: function () {
					handleError($form);
				},
				complete: function () {
					isSubmitting = false; // Always reset isSubmitting to false in case of success or error
				},
			});
		}
		

		e.preventDefault(); // Prevent default form submission
		return false;
	}

	function openPaymentLink(paymentLink) {
		var sanitizedPaymentLink = encodeURI(paymentLink);
		var width = 700;
		var height = 700;
		var left = window.innerWidth / 2 - width / 2;
		var top = window.innerHeight / 2 - height / 2;
		var popupWindow = window.open(
			sanitizedPaymentLink,
			'paymentPopup',
			'width=' + width + ',height=' + height + ',scrollbars=yes,top=' + top + ',left=' + left
		);

		if (!popupWindow || popupWindow.closed || typeof popupWindow.closed === 'undefined') {
			// Redirect to the payment link if popup was blocked
			window.location.href = sanitizedPaymentLink;
			resetButton();
		} else {
			popupInterval = setInterval(function () {
				if (popupWindow.closed) {
					clearInterval(popupInterval);
					clearInterval(paymentStatusInterval);
					// isPollingActive = false; // Reset polling active flag when popup closes

					// API call when popup closes
					$.ajax({
						type: 'POST',
						url: dfinsell_params.ajax_url, // Ensure this is localized correctly
						data: {
							action: 'dfinsell_popup_closed_event',
							order_id: orderId,
							security: dfinsell_params.dfinsell_nonce, // Ensure this is valid
						},
						dataType: 'json',
						cache: false,
						processData: true,
						success: function (response) {
						    if (response.success === true) {
						        clearInterval(paymentStatusInterval);
						        clearInterval(popupInterval);

						        // Log for debugging
						        console.log('Popup closed response:', response);

								$(document.body).trigger('update_checkout');
						        $(".wc-block-components-notice-banner").remove();

						        // Redirect if redirect_url exists (for any status)
						        if (response.data && response.data.redirect_url) {
						            // Use replace() to ensure redirect works even in popup-close timing
						            window.location.replace(response.data.redirect_url);
						        }else{
									if(response.data.notices){
										$(".wc-block-checkout__form").prepend('<div class="wc-block-components-notice-banner is-error"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg>'+response.data.notices+'<div>');
									}
									 window.scrollTo(0, 0);
								}
						    }else{
						    	$(document.body).trigger('update_checkout');
						        $(".wc-block-components-notice-banner").remove();
						    }

						    // isPollingActive = false;
						},
						error: function (xhr, status, error) {
							console.error("AJAX Error: ", error);
						},
						complete: function () {
							resetButton();
						}
					});
				}
			}, 500);

			// Start polling only if it's not already active
			// if (!isPollingActive) {
			// 	isPollingActive = true;

			// 	console.log('dfinsell_params :', dfinsell_params);


			// 	paymentStatusInterval = setInterval(function () {
			// 		$.ajax({
			// 			type: 'POST',
			// 			url: dfinsell_params.ajax_url,
			// 			data: {
			// 				action: 'dfinsell_check_payment_status',
			// 				order_id: orderId,
			// 				security: dfinsell_params.dfinsell_nonce,
			// 			},
			// 			dataType: 'json',
			// 			cache: false,
			// 			processData: true,
			// 			success: function (statusResponse) {
			// 				if (statusResponse.data.status === 'success') {
			// 					clearInterval(paymentStatusInterval);
			// 					clearInterval(popupInterval);
			// 					if (statusResponse.data && statusResponse.data.redirect_url) {
			// 						window.location.href = statusResponse.data.redirect_url;
			// 					}
			// 				} else if (statusResponse.data.status === 'failed') {
			// 					clearInterval(paymentStatusInterval);
			// 					clearInterval(popupInterval);
			// 					if (statusResponse.data && statusResponse.data.redirect_url) {
			// 						window.location.href = statusResponse.data.redirect_url;
			// 					}
			// 				}
			// 				isPollingActive = false; // Reset polling active flag after completion
			// 			},
			// 		});
			// 	}, 5000);
			// }
		}
	}

	function safeLink(value) {
		if (typeof value !== 'string' || !value) {
			return '';
		}
		try {
			const url = new URL(value);
			return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : '';
		} catch (e) {
			return '';
		}
	}

	function buildPaymentLinkSection(details) {
		const link = safeLink(details.purchase_url);
		if (!link) {
			return '';
		}
		const text = function (value) {
			return document.createTextNode(value);
		};
		return $('<p>', { 'class': 'dfinsell-order-received__link' }).append(
			text('Not receiving the email? '),
			$('<a>', {
				'class': 'dfinsell-order-received__link-anchor',
				href: link,
				target: '_blank',
				rel: 'noopener noreferrer'
			}).append(
				text('Click here'),
				$('<span>', { 'class': 'dfinsell-order-received__sr-only' })
					.text(' (opens in a new tab)'),
				$('<span>', {
					'class': 'dfinsell-order-received__link-icon',
					'aria-hidden': 'true'
				}).html(EXTERNAL_ICON)
			)
		);
	}

	function showOrderReceived(details) {
		const text = function (value) {
			return document.createTextNode(value);
		};

		const siteName = details.site_name || window.location.hostname;

		const $summary = $('<div>', { 'class': 'dfinsell-order-received__summary' });

		const row = function (label, value, modifier) {
			return $('<div>', {
				'class': 'dfinsell-order-received__row' + (modifier ? ' ' + modifier : '')
			}).append(
				$('<span>').text(label),
				$('<span>').text(value)
			);
		};

		$summary.append(row('Order', '#' + (details.order_number || '')));

		if (details.reference && String(details.reference) !== String(details.order_number || '')) {
			$summary.append(row('Voucher reference', details.reference));
		}

		(details.items || []).forEach(function (item) {
			$summary.append(row(item.label, item.value));
		});

		$summary.append(
			row('Amount due', details.amount_due || '', 'dfinsell-order-received__row--total')
		);

		const $panel = $('<div>', {
			'class': 'dfinsell-order-received',
			role: 'status',
			tabindex: '-1'
		}).append(
			$('<div>', {
				'class': 'dfinsell-order-received__icon',
				'aria-hidden': 'true'
			}).html(CHECK_ICON),

			$('<h2>', { 'class': 'dfinsell-order-received__title' })
				.text('Order received'),

			details.message
				? $('<p>', { 'class': 'dfinsell-order-received__status' }).text(details.message)
				: '',

			$('<p>', { 'class': 'dfinsell-order-received__lead' }).text(
				'As part of our secure checkout process, next you’ll receive your digital redemption voucher by email. You’ll get that email shortly, and can open it on your own time to purchase the voucher and complete this transaction.'
			),

			$summary,

			$('<div>', { 'class': 'dfinsell-order-received__note' }).append(
				$('<div>', {
					'class': 'dfinsell-order-received__note-icon',
					'aria-hidden': 'true'
				}).html(SHIELD_ICON),
				$('<div>').append(
					$('<p>', { 'class': 'dfinsell-order-received__note-title' })
						.text('Independent voucher & payment partner'),
					$('<p>').append(
						text('Your voucher and payment are completed through a separate, independent partner. That partner is never hosted on, embedded in, or otherwise associated with '),
						$('<span>').text(siteName),
						text('.')
					)
				)
			),

			$('<p>', { 'class': 'dfinsell-order-received__foot' }).append(
				text('No further action is needed here — check the inbox for '),
				$('<strong>').text(details.email || ''),
				text(' whenever you’re ready.')
			),

			buildPaymentLinkSection(details)
		);

		const $blockCheckout = $('.wp-block-woocommerce-checkout').first();

		const $target = $blockCheckout.length
			? $blockCheckout
			: $('form.checkout').first();

		$('.wc_er, .wc-block-components-notice-banner').remove();
		$('.dfinsell-order-received').remove();
		$('.woocommerce-form-coupon-toggle, .woocommerce-form-login-toggle, form.checkout_coupon, form.woocommerce-form-login').hide();

		if ($target.length) {
			$panel.insertBefore($target);
			$target.hide();
		} else {
			$('body').prepend($panel);
		}

		$('html, body').animate({
			scrollTop: Math.max($panel.offset().top - 80, 0)
		}, 300);

		if ($panel[0] && $panel[0].focus) {
			$panel[0].focus({ preventScroll: true });
		}
	}

	function handleResponse(response, $form) {
		$('.dfinsell-loader-background, .dfinsell-loader').hide();
		$('.wc_er').remove();

		try {
			if (response.result === 'success') {
				orderId = response.order_id;
				if(response.payment_status == 'success'){
					 window.location.href = response.redirect_url
					 return false;
				}

				var orderReceived = (response.data && response.data.order_received) ? response.data.order_received : (response.order_received || null);
				if (orderReceived) {
					showOrderReceived(orderReceived);
					resetButton();
					return false;
				}

				var paymentLink = response.payment_link;
				if (paymentLink) {
					openPaymentLink(paymentLink);
					$form.removeAttr('data-result');
					$form.removeAttr('data-redirect-url');
				} else if (response.redirect || response.redirect_url) {
					window.location.href = response.redirect || response.redirect_url;
				}
			} else {
				throw response.messages || response.message || 'An error occurred during checkout.';
			}
		} catch (err) {
			displayError(err, $form);
		}
	}

	function handleError($form) {
		$('.wc_er').remove();
		$form.prepend('<div class="wc_er">An error occurred during checkout. Please try again.</div>');
		$('html, body').animate(
			{
				scrollTop: $('.wc_er').offset().top - 300,
			},
			500
		);
		resetButton();
	}

	function displayError(err, $form) {
		$('.wc_er').remove();
		$form.prepend('<div class="wc_er">' + err + '</div>');
		$('html, body').animate(
			{
				scrollTop: $('.wc_er').offset().top - 300,
			},
			500
		);
		resetButton();
	}

	function resetButton() {
		isSubmitting = false;
		if ($button) {
			$button.prop('disabled', false).text(originalButtonText);
		}
		$('.dfinsell-loader-background, .dfinsell-loader').hide();
	}
});
