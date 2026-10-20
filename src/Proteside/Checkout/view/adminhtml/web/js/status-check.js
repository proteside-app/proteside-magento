/**
 * "Check status" button in Stores > Configuration > Security > Proteside.
 * POSTs to proteside/status/check (admin form key included) and reloads the page so the messages
 * added by the controller are displayed.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $button = $(element),
            $label = $button.find('span').length ? $button.find('span') : $button;

        $button.on('click', function (event) {
            event.preventDefault();
            if ($button.prop('disabled')) {
                return;
            }
            $button.prop('disabled', true);
            $label.text(config.checkingLabel || 'Checking…');

            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {form_key: window.FORM_KEY},
                showLoader: true
            }).always(function () {
                window.location.reload();
            });
        });
    };
});
