define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, alert, $t) {
    'use strict';

    return function (config) {
        var apiKeyInput = $('#dropshipping_general_api_key');
        var apiUrlInput = $('#dropshipping_general_api_url');
        var emailInput = $('#dropshipping_general_email');
        var resultContainer = $('#test-api-connection-result');
        var button = $('#test_api_connection_button');

        button.on('click', function (e) {
            e.preventDefault();

            // Obtenir les valeurs actuelles des champs
            var apiKey = apiKeyInput.val();
            var apiUrl = apiUrlInput.val();
            var email = emailInput.val();

            // Vérifier que tous les champs sont remplis
            if (!apiKey || !apiUrl || !email) {
                alert({
                    title: $t('Erreur'),
                    content: $t('Veuillez remplir tous les champs : Clé API, URL API et Email.')
                });
                return;
            }

            // Désactiver le bouton pendant le test
            button.prop('disabled', true).addClass('disabled');

            // Afficher un message de chargement
            resultContainer.show().html(
                '<div class="message message-notice">' +
                '<span>' + $t('Test de connexion à l\'API en cours, veuillez patienter...') + '</span>' +
                '</div>'
            );

            // Envoyer la requête AJAX
            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    api_key: apiKey,
                    api_url: apiUrl,
                    email: email,
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
                        // Détecter les erreurs de limitation
                        if (response.message && response.message.indexOf('veuillez attendre') !== -1) {
                            resultContainer.html(
                                '<div class="message message-warning warning">' +
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
                    }

                    // Réactiver le bouton après 5 secondes
                    setTimeout(function() {
                        button.prop('disabled', false).removeClass('disabled');
                    }, 5000);
                },
                error: function (xhr, status, error) {
                    resultContainer.html(
                        '<div class="message message-error error">' +
                        '<span>' + $t('Une erreur est survenue pendant le test. Détails : ') + error + '</span>' +
                        '</div>'
                    );
                    console.error('AJAX Error:', error, xhr.responseText);

                    // Réactiver le bouton
                    button.prop('disabled', false).removeClass('disabled');
                }
            });
        });
    };
});
