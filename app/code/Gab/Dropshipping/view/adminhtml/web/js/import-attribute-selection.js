define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, alert, $t) {
    'use strict';

    return {
        config: null,
        modal: null,
        overlay: null,
        selectedAttributes: [],
        currentPid: null,
        currentProductData: null,

        init: function(config) {
            this.config = config;
            this.initElements();
            this.bindEvents();
        },

        initElements: function() {
            this.modal = $('#attribute-selection-modal');
            this.overlay = $('#attribute-selection-overlay');
        },

        bindEvents: function() {
            var self = this;

            $('#close-attribute-modal, #cancel-attribute-selection').on('click', function() {
                self.hideModal();
            });

            this.overlay.on('click', function() {
                self.hideModal();
            });

            $('#confirm-attribute-selection').on('click', function() {
                self.confirmSelection();
            });
        },

        showModal: function(pid, productData) {
            this.currentPid = pid;
            this.currentProductData = productData;
            this.loadAttributes(pid);
        },

        hideModal: function() {
            this.overlay.removeClass('active');
            this.modal.removeClass('active');
            this.selectedAttributes = [];
            this.currentPid = null;
            this.currentProductData = null;
        },

        loadAttributes: function(pid) {
            var self = this;
            var loadingModal = $('<div class="loading-overlay"><div class="loading-message">' +
                $t('Loading variant attributes...') + '</div></div>');
            $('body').append(loadingModal);

            $.ajax({
                url: this.config.selectAttributesUrl,
                type: 'GET',
                dataType: 'json',
                data: {
                    pid: pid,
                    form_key: window.FORM_KEY
                },
                success: function(response) {
                    loadingModal.remove();
                    if (response.success && response.attributes) {
                        self.renderAttributes(response.attributes);
                        self.overlay.addClass('active');
                        self.modal.addClass('active');
                    } else {
                        alert({
                            title: $t('Error'),
                            content: response.message || $t('Could not load variant attributes.')
                        });
                    }
                },
                error: function() {
                    loadingModal.remove();
                    alert({
                        title: $t('Error'),
                        content: $t('An error occurred while loading variant attributes.')
                    });
                }
            });
        },

        renderAttributes: function(attributes) {
            var self = this;
            var container = $('#attributes-list');
            container.empty();
            this.selectedAttributes = [];

            if (Object.keys(attributes).length === 0) {
                container.html('<p class="no-attributes">No configurable attributes found.</p>');
                return;
            }

            $.each(attributes, function(code, attrData) {
                var attributeHtml = self.buildAttributeHtml(code, attrData);
                container.append(attributeHtml);
            });

            this.bindAttributeEvents(container);
            this.updateSelectionSummary();
        },

        buildAttributeHtml: function(code, attrData) {
            var html = '<div class="attribute-item" data-code="' + code + '">' +
                '<div class="attribute-header">' +
                '<input type="checkbox" class="attribute-checkbox" id="attr_' + code + '" />' +
                '<label for="attr_' + code + '" class="attribute-label">' + attrData.label + '</label>' +
                '<span class="attribute-code">(' + code + ')</span>' +
                '</div>' +
                '<div class="attribute-values">';

            for (var i = 0; i < Math.min(attrData.sample_values.length, 3); i++) {
                html += '<span class="attribute-value">' + attrData.sample_values[i] + '</span>';
            }

            if (attrData.values.length > 3) {
                html += '<span class="attribute-value">+' + (attrData.values.length - 3) + ' more</span>';
            }

            html += '</div></div>';
            return html;
        },

        bindAttributeEvents: function(container) {
            var self = this;

            container.find('.attribute-checkbox').on('change', function() {
                var code = $(this).closest('.attribute-item').data('code');
                var item = $(this).closest('.attribute-item');

                if ($(this).is(':checked')) {
                    self.selectedAttributes.push(code);
                    item.addClass('selected');
                } else {
                    self.selectedAttributes = self.selectedAttributes.filter(function(attr) {
                        return attr !== code;
                    });
                    item.removeClass('selected');
                }

                self.updateSelectionSummary();
            });

            container.find('.attribute-item').on('click', function(e) {
                if (e.target.type !== 'checkbox') {
                    $(this).find('.attribute-checkbox').trigger('click');
                }
            });
        },

        updateSelectionSummary: function() {
            $('#selected-count').text(this.selectedAttributes.length);

            var confirmBtn = $('#confirm-attribute-selection');
            confirmBtn.prop('disabled', this.selectedAttributes.length === 0);
        },

        confirmSelection: function() {
            if (!this.selectedAttributes || this.selectedAttributes.length === 0) {
                alert({
                    title: $t('Warning'),
                    content: $t('Please select at least one configurable attribute.')
                });
                return;
            }

            this.hideModal();
            this.importConfigurableProduct();
        },

        mportConfigurableProduct: function() {
            var formData = {
                pid: this.currentPid,
                import_type: 'configurable',
                markup_percentage: $('#markup-percentage').val(),
                stock_quantity: $('#stock-quantity').val(),
                category_ids: $('#category-ids').val(),
                selected_attributes: this.selectedAttributes,
                form_key: window.FORM_KEY
            };

            var loadingModal = $('<div class="loading-overlay"><div class="loading-message">' +
                $t('Importing configurable product...') + '</div></div>');
            $('body').append(loadingModal);

            var self = this;
            $.ajax({
                url: this.config.importUrl,
                type: 'POST',
                dataType: 'json',
                data: formData,
                success: function (response) {
                    loadingModal.remove();
                    alert({
                        title: response.success ? $t('Success') : $t('Error'),
                        content: response.message || (response.success ? $t('Product imported successfully.') : $t('Import failed.'))
                    });
                },
                error: function (xhr, status, error) {
                    loadingModal.remove();
                    alert({
                        title: $t('Error'),
                        content: $t('An error occurred during import.')
                    });
                }
            });
        }
    };
});
