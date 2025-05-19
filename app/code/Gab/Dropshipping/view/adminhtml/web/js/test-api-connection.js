define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, alert, $t) {
    'use strict';

    return function (config) {
        var apiKeyInput = $('#dropshipping_general_api_key');
        var apiUrlInput = $('#dropshipping_general_api_url');
        var resultContainer = $('#test-api-connection-result');
        var button = $('#test_api_connection_button');

        button.on('click', function (e) {
            e.preventDefault();

            // Get the current values from the fields
            var apiKey = apiKeyInput.val();
            var apiUrl = apiUrlInput.val();

            // Verify both fields are filled
            if (!apiKey || !apiUrl) {
                alert({
                    title: $t('Error'),
                    content: $t('Please enter both API Key and API URL.')
                });
                return;
            }

            // Show loading message
            resultContainer.show().html(
                '<div class="message message-notice">' +
                '<span>' + $t('Testing API connection, please wait...') + '</span>' +
                '</div>'
            );

            // Send AJAX request
            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    api_key: apiKey,
                    api_url: apiUrl,
                    form_key: window.FORM_KEY
                },
                success: function (response) {
                    if (response.success) {
                        resultContainer.html(
                            '<div class="message message-success success">' +
                            '<span>' + response.message + '</span>' +
                            '</div>'
                        );
                    } else {
                        resultContainer.html(
                            '<div class="message message-error error">' +
                            '<span>' + response.message + '</span>' +
                            '</div>'
                        );
                    }
                },
                error: function (xhr, status, error) {
                    resultContainer.html(
                        '<div class="message message-error error">' +
                        '<span>' + $t('An error occurred during the test. Details: ') + error + '</span>' +
                        '</div>'
                    );
                    console.error('AJAX Error:', error, xhr.responseText);
                }
            });
        });
    };
});
