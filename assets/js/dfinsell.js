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
          $('.dfinsell-loader-background, .dfinsell-loader').hide()
          $('.wc_er').remove()
          try {
            if (response.result === 'success') {
              // Get the order ID and save it for later use
              orderId = response.order_id // Assuming order_id is returned in the response

              // Display the payment link URL in a popup
              var paymentLink = response.payment_link // Assuming payment_link is where the URL is received
              var popupWindow = window.open(
                paymentLink,
                'paymentPopup',
                'width=600,height=400,scrollbars=yes'
              )

              if (
                !popupWindow ||
                popupWindow.closed ||
                typeof popupWindow.closed == 'undefined'
              ) {
                throw 'Popup blocked or failed to open'
              }

              // Check if the popup is closed periodically
              popupInterval = setInterval(function () {
                if (popupWindow.closed) {
                  clearInterval(popupInterval)
                  $button.prop('disabled', false).text(originalButtonText) // Re-enable the button and reset the text

                  // Stop checking the payment status
                  clearInterval(paymentStatusInterval)
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
            } else if (response.result === 'failure') {
              throw response.messages || 'An error occurred during checkout.'
            } else {
              throw 'Invalid response'
            }
          } catch (err) {
            // Display the error message to the user
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
        },
        error: function (jqXHR, textStatus, errorThrown) {
          // Handle specific error cases if needed
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
        },
      })
    }, 2000) // Wait for 2 seconds

    return false
  }

  // Unbind the default WooCommerce event handler for form submission and bind our custom handler
  $('form.checkout').off('submit').on('submit', handleFormSubmit)

  // Ensure that our custom handler remains bound even if WooCommerce re-binds its handler
  $(document.body).on('updated_checkout', function () {
    $('form.checkout').off('submit').on('submit', handleFormSubmit)
  })
})
