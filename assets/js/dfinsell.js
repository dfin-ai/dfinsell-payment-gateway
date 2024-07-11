jQuery(function ($) {
  var isSubmitting = false // Flag to track form submission

  // Append loader image to the body or a specific element
  $('body').append(
    '<div class="dfinsell-loader-background"></div>' +
      '<div class="dfinsell-loader"><img src="' +
      dfinsell_params.dfin_loader +
      '" alt="Loading..." /></div>'
  )

  // Listen for the checkout form submission event
  $('form.checkout').on('checkout_place_order', function (e) {
    // Prevent the default form submission
    e.preventDefault()

    $('.dfinsell-loader-background, .dfinsell-loader').show()

    var $form = $(this)
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
          $('.dfinsell-loader-background, .dfinsell-loader').hide()
        },
      })
    }, 2000) // Wait for 2 seconds

    return false
  })
})
