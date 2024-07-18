jQuery(function ($) {
  var isSubmitting = false // Flag to track form submission
  var popupInterval // Interval ID for checking popup status
  var paymentStatusInterval // Interval ID for checking payment status
  var orderId // To store the order ID

  // Append loader image to the body or a specific element
  $('body').append(
    '<div class="dfinsell-loader-background"></div>' +
      '<div class="dfinsell-loader"><img src="' +
      dfinsell_params.dfin_loader +
      '" alt="Loading..." /></div>'
  )

  // Function to handle form submission
  function handleFormSubmit(e) {
    // Prevent the default form submission
    //e.preventDefault();

    var $form = $(this)

    var selectedPaymentMethod = $form
      .find('input[name="payment_method"]:checked')
      .val()

    // Check if the selected payment method is the custom plugin's payment option
    if (selectedPaymentMethod !== 'dfinsell') {
      // If it's not the custom payment method, allow WooCommerce to handle the form submission
      return true
    }

    $('.dfinsell-loader-background, .dfinsell-loader').show()

    var $button = $form.find('button[type="submit"]')
    var originalButtonText = $button.text() // Save the original button text
    $button.text('Processing...').prop('disabled', true) // Change button text to "Processing" and disable it

    var data = $form.serialize()

    // Check if the form is already being submitted
    if (isSubmitting) {
      return false
    }

    isSubmitting = true // Set the flag to true

    // Wait for 2 seconds before making the API call
    setTimeout(function () {
      $.ajax({
        type: 'POST',
        url: wc_checkout_params.checkout_url,
        data: data,
        dataType: 'json',
        success: function (response) {
          handleResponse(response, $form, $button, originalButtonText)
        },
        error: function (jqXHR, textStatus, errorThrown) {
          handleError($form, $button, originalButtonText)
        },
      })
    }, 2000) // Wait for 2 seconds

    return false
  }

  function handleResponse(response, $form, $button, originalButtonText) {
    $('.dfinsell-loader-background, .dfinsell-loader').hide()
    $('.wc_er').remove()
    try {
      if (response.result === 'success') {
        orderId = response.order_id // Assuming order_id is returned in the response
        var width = 800
        var height = 800
        var left = window.innerWidth / 2 - width / 2
        var top = window.innerHeight / 2 - height / 2
        var paymentLink = response.payment_link // Assuming payment_link is where the URL is received
        var popupWindow = window.open(
          paymentLink,
          'paymentPopup',
          'width=' +
            width +
            ',height=' +
            height +
            ',scrollbars=yes,top=' +
            top +
            ',left=' +
            left
        )

        if (
          !popupWindow ||
          popupWindow.closed ||
          typeof popupWindow.closed === 'undefined'
        ) {
          throw 'Popup blocked or failed to open'
        }

        popupInterval = setInterval(function () {
          if (popupWindow.closed) {
            clearInterval(popupInterval)
            $button.prop('disabled', false).text(originalButtonText) // Re-enable the button and reset the text
            clearInterval(paymentStatusInterval) // Stop checking the payment status
          }
        }, 500)

        // Check the payment status periodically
        paymentStatusInterval = setInterval(function () {
          $.ajax({
            type: 'POST',
            url: dfinsell_params.ajax_url,
            data: {
              action: 'check_payment_status',
              order_id: orderId,
              security: dfinsell_params.dfinsell_nonce, // Add nonce here
            },
            dataType: 'json',
            cache: false,
            processData: true,
            async: false,
            success: function (statusResponse) {
              if (statusResponse.data.status === 'success') {
                clearInterval(paymentStatusInterval)
                clearInterval(popupInterval)
                window.location.href = statusResponse.data.redirect_url // Redirect to success page
              } else if (statusResponse.data.status === 'failed') {
                clearInterval(paymentStatusInterval)
                clearInterval(popupInterval)
                window.location.href = statusResponse.data.redirect_url // Redirect to failure page
              }
            },
          })
        }, 5000) // Check every 5 seconds

        $form.removeAttr('data-result')
        $form.removeAttr('data-redirect-url')
        isSubmitting = false // Reset the flag
      } else {
        throw response.messages || 'An error occurred during checkout.'
      }
    } catch (err) {
      displayError(err, $form, $button, originalButtonText)
    }
  }

  function handleError($form, $button, originalButtonText) {
    $('.wc_er').remove()
    $form.prepend(
      '<div class="wc_er">An error occurred during checkout. Please try again.</div>'
    )
    $('html, body').animate(
      {
        scrollTop: $('.wc_er').offset().top - 300, // Adjusted to offset for better visibility
      },
      500
    )
    isSubmitting = false // Reset the flag on error
    $button.prop('disabled', false).text(originalButtonText) // Re-enable the button and reset the text
    $('.dfinsell-loader-background, .dfinsell-loader').hide()
  }

  function displayError(err, $form, $button, originalButtonText) {
    $('.wc_er').remove()
    $form.prepend('<div class="wc_er">' + err + '</div>')
    $('html, body').animate(
      {
        scrollTop: $('.wc_er').offset().top - 300, // Adjusted to offset for better visibility
      },
      500
    )
    isSubmitting = false // Reset the flag in case of error
    $button.prop('disabled', false).text(originalButtonText) // Re-enable the button and reset the text
  }

  // Unbind the default WooCommerce event handler for form submission and bind our custom handler
  $('form.checkout').off('submit').on('submit', handleFormSubmit)

  // Ensure that our custom handler remains bound even if WooCommerce re-binds its handler
  $(document.body).on('updated_checkout', function () {
    $('form.checkout').off('submit').on('submit', handleFormSubmit)
  })
})
