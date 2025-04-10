jQuery(function ($) {
	var isSubmitting = false;
	var popupInterval;
	var paymentStatusInterval;
	var orderId;
	var $button;
	var originalButtonText;
	var isPollingActive = false;
	let isHandlerBound = false;

	var loaderUrl = dfinsell_params.dfin_loader ? encodeURI(dfinsell_params.dfin_loader) : '';
	$('body').append(
		'<div class="dfinsell-loader-background"></div>' +
		'<div class="dfinsell-loader"><img src="' + loaderUrl + '" alt="Loading..." /></div>'
	);

	// Prevent default WooCommerce checkout behavior for custom method
	$('form.checkout').on('checkout_place_order', function () {
		var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
		if (selectedPaymentMethod === dfinsell_params.payment_method) {
			return false;
		}
	});

	function bindCheckoutHandler() {
		if (isHandlerBound) return;
		isHandlerBound = true;

		$("form.checkout").off("submit.dfinsell").on("submit.dfinsell", function (e) {
			if ($(this).find('input[name="payment_method"]:checked').val() === dfinsell_params.payment_method) {
				handleFormSubmit.call(this, e);
				return false;
			}
		});
	}

	$(document.body).on("updated_checkout", function () {
		isHandlerBound = false;
		bindCheckoutHandler();
	});

	bindCheckoutHandler();

	$('form.checkout').on('change', 'input[name="payment_method"]', function () {
		const selected = $(this).val();
		if (selected === dfinsell_params.payment_method) {
			$('.woocommerce-error, .wc-block-components-notice-banner, .woocommerce-message').remove();
			removeDuplicateErrors();
		} else {
			// On switching to another method, optionally hide your custom errors
			$('.dfinsell-error-wrap').remove();
		}
	});
	

	function handleFormSubmit(e) {
		e.preventDefault();

		var $form = $(this);

		if ($form.find('input[name="payment_method"]:checked').val() === dfinsell_params.payment_method) {
			$('.wc-block-components-notice-banner').remove();
		}
		
		// $('.woocommerce-error, .woocommerce-message, .woocommerce-notices-wrapper').remove();
		// $form.find('.woocommerce-error, .woocommerce-message, .woocommerce-invalid').removeClass('woocommerce-invalid');

		if (isSubmitting) {
			return false;
		}
		isSubmitting = true;

		var selectedPaymentMethod = $form.find('input[name="payment_method"]:checked').val();
		if (selectedPaymentMethod !== dfinsell_params.payment_method) {
			isSubmitting = false;
			return true;
		}

		$button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
		originalButtonText = $button.text();
		$button.prop('disabled', true).text('Processing...');

		$('.dfinsell-loader-background, .dfinsell-loader').show();

		var data = $form.serialize();

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
				isSubmitting = false;
			},
		});

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
			window.location.href = sanitizedPaymentLink;
			resetButton();
		} else {
			popupInterval = setInterval(function () {
				if (popupWindow.closed) {
					clearInterval(popupInterval);
					clearInterval(paymentStatusInterval);
					isPollingActive = false;

					$.ajax({
						type: 'POST',
						url: dfinsell_params.ajax_url,
						data: {
							action: 'popup_closed_event',
							order_id: orderId,
							payment_link: sanitizedPaymentLink,
							security: dfinsell_params.dfinsell_nonce,
						},
						dataType: 'json',
						success: function (response) {
							if (response.success === true) {
								clearInterval(paymentStatusInterval);
								clearInterval(popupInterval);
								window.location.href = response.data.redirect_url;
							}
							isPollingActive = false;
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

			if (!isPollingActive) {
				isPollingActive = true;
				paymentStatusInterval = setInterval(function () {
					$.ajax({
						type: 'POST',
						url: dfinsell_params.ajax_url,
						data: {
							action: 'check_payment_status',
							order_id: orderId,
							security: dfinsell_params.dfinsell_nonce,
						},
						dataType: 'json',
						success: function (statusResponse) {
							if (statusResponse.data.status === 'success' || statusResponse.data.status === 'failed') {
								clearInterval(paymentStatusInterval);
								clearInterval(popupInterval);
								window.location.href = statusResponse.data.redirect_url;
								isPollingActive = false;
							}
						}
					});
				}, 5000);
			}
		}
	}

	function handleResponse(response, $form) {
		$('.dfinsell-loader-background, .dfinsell-loader').hide();

		try {
			if (response.result === 'success') {
				orderId = response.order_id;
				var paymentLink = response.payment_link;
				openPaymentLink(paymentLink);
				$form.removeAttr('data-result');
				$form.removeAttr('data-redirect-url');
			} else {
				throw response.messages || 'An error occurred during checkout.';
			}
		} catch (err) {
			displayError(err, $form);
			removeDuplicateErrors();
		}
	}

	function handleError($form) {
		var $errorContainer = $('.woocommerce-notices-wrapper .dfinsell-error-wrap');
		if (!$errorContainer.length) {
			$errorContainer = $('<div class="dfinsell-error-wrap"></div>');
			$('.woocommerce-notices-wrapper').prepend($errorContainer);
		}
		$errorContainer.html('<div class="dfinsell-error">An error occurred during checkout. Please try again.</div>');
		removeDuplicateErrors();
		scrollToError($errorContainer);
		resetButton();
	}	

	function displayError(err, $form) {
		let $errorContainer = $('.woocommerce-notices-wrapper .dfinsell-error-wrap');
	
		if (!$errorContainer.length) {
			$errorContainer = $('<div class="dfinsell-error-wrap"></div>');
			$('.woocommerce-notices-wrapper').prepend($errorContainer);
		}
	
		let content = typeof err === 'string' ? err : (err.messages || 'An unknown error occurred');
		$errorContainer.html('<div class="dfinsell-error">' + content + '</div>');
	
		scrollToError($errorContainer);
		resetButton();
	}	

	function removeDuplicateErrors() {
		const seen = new Set();

		$('.woocommerce-error li, .wc-block-components-notice-banner li').each(function () {
			const $el = $(this);
			const text = $el.text().trim().replace(/\s+/g, ' ').toLowerCase();

			if (seen.has(text)) {
				$el.remove();
			} else {
				seen.add(text);
			}
		});

		// Remove any empty wrappers if all li are gone
		$('.woocommerce-error, .wc-block-components-notice-banner').each(function () {
			if ($(this).find('li').length === 0) {
				$(this).remove();
			}
		});
	}

	function scrollToError($el) {
		if ($el && $el.length) {
			$('html, body').animate({
				scrollTop: $el.offset().top - 300,
			}, 500);
		}
	}

	function resetButton() {
		isSubmitting = false;
		if ($button) {
			$button.prop('disabled', false).text(originalButtonText);
		}
		$('.dfinsell-loader-background, .dfinsell-loader').hide();
	}
});
