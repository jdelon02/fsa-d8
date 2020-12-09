/**
 * @file
 * Recaptcha v3 behaviors.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Attach recaptcha response token from google with form.
   *
   * @type {{attach: Drupal.behaviors.reCaptchaV3.attach}}
   */
  Drupal.behaviors.reCaptchaV3 = {
    attach: function (context) {
      $('.recaptcha-v3-token', context).once('recaptcha-v3-token').each(function () {
        var $token_element = $(this);
        var timer = setInterval(function() {
          if (grecaptcha !== undefined) {
            grecaptcha.ready(function () {
              grecaptcha.execute(
                $token_element.data('recaptchaV3SiteKey'),
                {
                  action: $token_element.data('recaptchaV3Action')
                }
              ).then(function (token) {
                $token_element.val(token);
                $token_element.trigger('change');
              });
            });
            clearInterval(timer)
          }
        }, 500);
      });
    }
  };

})(jQuery, Drupal);
