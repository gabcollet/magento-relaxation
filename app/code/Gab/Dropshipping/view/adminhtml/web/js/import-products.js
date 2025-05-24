define([
    'jquery',
    'jquery/ui',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/confirm',
    'mage/translate'
], function ($, ui, alert, confirm, $t) {
    'use strict';

    return function (config) {
        $(document).ready(function() {
            var searchBtn = $('#search-products-btn');
            var searchTerm = $('#search-term');
            var resultsContainer = $('#search-results-container');
            var productsGrid = resultsContainer.find('.products-grid');
            var noResultsMessage = $('.no-results');
            var loadingMessage = $('<div class="loading-message">' + $t('Searching products...') + '</div>');
            var paginationContainer = $('.pagination-container');
            var importForm = $('#import-configuration-form');
            var importFormOverlay = $('#import-form-overlay');
            var currentPage = 1;
            var totalPages = 1;
            var itemsPerPage = 20;
            var currentProductPid = null;
            var currentProductData = null;

            initEventListeners();

            function initEventListeners() {
                searchBtn.on('click', function() {
                    searchProducts(1);
                });

                searchTerm.on('keypress', function(e) {
                    if (e.which === 13) {
                        searchBtn.trigger('click');
                        return false;
                    }
                });

                $('#close-import-form, #cancel-import').on('click', hideImportForm);
                importFormOverlay.on('click', hideImportForm);
                $('#confirm-import').on('click', importProduct);

                $('#import-type').on('change', function() {
                    if ($(this).val() === 'configurable') {
                        $('.configurable-info').show();
                    } else {
                        $('.configurable-info').hide();
                    }
                });
            }

            function searchProducts(page) {
                var categories = [];
                $('input[name="categories[]"]:checked').each(function() {
                    categories.push($(this).val());
                });

                noResultsMessage.hide();
                resultsContainer.hide();
                loadingMessage.insertAfter(noResultsMessage);
                searchBtn.prop('disabled', true).addClass('disabled');

                $.ajax({
                    url: config.searchUrl,
                    type: 'GET',
                    dataType: 'json',
                    data: {
                        term: searchTerm.val(),
                        categories: categories,
                        page: page,
                        limit: itemsPerPage,
                        form_key: window.FORM_KEY
                    },
                    success: handleSearchSuccess,
                    error: handleSearchError,
                    complete: function() {
                        if (searchBtn.prop('disabled')) {
                            setTimeout(function() {
                                searchBtn.prop('disabled', false).removeClass('disabled');
                            }, 3000);
                        }
                    }
                });
            }

            function handleSearchSuccess(response) {
                loadingMessage.remove();

                if (!response.success && response.message && response.message.indexOf('Limitation des requêtes API') !== -1) {
                    handleRateLimit(response);
                    return;
                }

                searchBtn.prop('disabled', false).removeClass('disabled');

                if (response.success && response.products && response.products.length > 0) {
                    renderProducts(response.products);
                    currentPage = response.page || 1;
                    totalPages = Math.ceil((response.totalCount || 0) / itemsPerPage);
                    renderPagination();
                    resultsContainer.show();
                    noResultsMessage.hide();

                    $('html, body').animate({
                        scrollTop: resultsContainer.offset().top - 50
                    }, 500);
                } else {
                    noResultsMessage.text(response.message || $t('No products found.'))
                        .removeClass('message-warning message-info')
                        .addClass('message-notice')
                        .show();
                    resultsContainer.hide();
                }
            }

            function handleSearchError(xhr, status, error) {
                loadingMessage.remove();
                searchBtn.prop('disabled', false).removeClass('disabled');

                var errorMessage = $t('An error occurred during the search. Please try again.');
                try {
                    var response = JSON.parse(xhr.responseText);
                    errorMessage = response.message || errorMessage;
                } catch (e) {}

                noResultsMessage.text(errorMessage)
                    .removeClass('message-warning message-info')
                    .addClass('message-error')
                    .show();
                resultsContainer.hide();
            }

            function handleRateLimit(response) {
                var waitTime = response.message.match(/attendre\s+(\d+)\s+secondes/);
                if (waitTime && waitTime[1]) {
                    var seconds = parseInt(waitTime[1]);
                    var timerElement = $('<div class="rate-limit-timer">Réessayer dans <span>' + seconds + '</span> secondes</div>');
                    noResultsMessage.html(response.message + '<br>')
                        .append(timerElement)
                        .addClass('message-warning')
                        .show();

                    var timer = setInterval(function() {
                        seconds--;
                        timerElement.find('span').text(seconds);
                        if (seconds <= 0) {
                            clearInterval(timer);
                            timerElement.text('Vous pouvez maintenant réessayer.');
                            searchBtn.prop('disabled', false).removeClass('disabled');
                            noResultsMessage.removeClass('message-warning').addClass('message-info');
                        }
                    }, 1000);
                } else {
                    noResultsMessage.text(response.message).addClass('message-warning').show();
                    setTimeout(function() {
                        searchBtn.prop('disabled', false).removeClass('disabled');
                    }, 3000);
                }
                resultsContainer.hide();
            }

            function renderProducts(products) {
                productsGrid.empty();

                $.each(products, function(index, product) {
                    var productHtml = buildProductHtml(product);
                    productsGrid.append(productHtml);
                });

                bindProductEvents();
            }

            function buildProductHtml(product) {
                return '<div class="product-item">' +
                    '<div class="product-image">' +
                    '<img src="' + (product.productImage || config.placeholderImage) + '" alt="' + $t('Product Image') + '" />' +
                    '</div>' +
                    '<div class="product-details">' +
                    '<h4>' + (product.productNameEn || 'No Name') + '</h4>' +
                    '<div class="product-info">' +
                    '<div class="product-price">' + $t('Price') + ': $' + (product.sellPrice || '0.00') + '</div>' +
                    '<div class="product-sku">' + $t('SKU') + ': ' + (product.productSku || 'N/A') + '</div>' +
                    '</div>' +
                    '<div class="product-actions">' +
                    '<button type="button" class="action-secondary view-details" data-pid="' + (product.pid || '') + '">' +
                    $t('View Details') + '</button>' +
                    '<button type="button" class="action-primary import-product" data-pid="' + (product.pid || '') + '">' +
                    $t('Import') + '</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
            }

            function bindProductEvents() {
                productsGrid.find('.import-product').on('click', function() {
                    var pid = $(this).data('pid');
                    getProductDataForImport(pid);
                });

                productsGrid.find('.import-product').on('click', function() {
                    getProductDataForImport($(this).data('pid'));
                });
            }

            function renderPagination() {
                paginationContainer.empty();

                if (totalPages <= 1) return;

                var paginationHtml = '<div class="pages">';

                if (currentPage > 1) {
                    paginationHtml += '<button class="page-prev" data-page="' + (currentPage - 1) + '">' + $t('Previous') + '</button>';
                } else {
                    paginationHtml += '<button class="page-prev disabled">' + $t('Previous') + '</button>';
                }

                var startPage = Math.max(1, currentPage - 2);
                var endPage = Math.min(totalPages, startPage + 4);

                if (startPage > 1) {
                    paginationHtml += '<button class="page-number" data-page="1">1</button>';
                    if (startPage > 2) {
                        paginationHtml += '<span class="ellipsis">...</span>';
                    }
                }

                for (var i = startPage; i <= endPage; i++) {
                    if (i === currentPage) {
                        paginationHtml += '<button class="page-number active" data-page="' + i + '">' + i + '</button>';
                    } else {
                        paginationHtml += '<button class="page-number" data-page="' + i + '">' + i + '</button>';
                    }
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) {
                        paginationHtml += '<span class="ellipsis">...</span>';
                    }
                    paginationHtml += '<button class="page-number" data-page="' + totalPages + '">' + totalPages + '</button>';
                }

                if (currentPage < totalPages) {
                    paginationHtml += '<button class="page-next" data-page="' + (currentPage + 1) + '">' + $t('Next') + '</button>';
                } else {
                    paginationHtml += '<button class="page-next disabled">' + $t('Next') + '</button>';
                }

                paginationHtml += '</div>';
                paginationContainer.html(paginationHtml);

                paginationContainer.find('.page-number, .page-prev:not(.disabled), .page-next:not(.disabled)').on('click', function() {
                    searchProducts($(this).data('page'));
                });
            }

            function getProductDataForImport(pid) {
                if (!pid) {
                    return;
                }

                var loadingModal = showLoadingModal($t('Loading product data...'));

                $.ajax({
                    url: config.detailsUrl,
                    type: 'GET',
                    dataType: 'json',
                    data: {
                        pid: pid,
                        form_key: window.FORM_KEY
                    },
                    success: function(response) {
                        loadingModal.remove();

                        if (response.success && response.product) {
                            var importType = $('#import-type').val();

                            if (importType === 'configurable') {
                                require(['Gab_Dropshipping/js/import-attribute-selection'], function(attributeSelector) {
                                    attributeSelector.init(config);
                                    attributeSelector.showModal(pid, response.product);
                                });
                            } else {
                                showImportForm(pid, response.product);
                            }
                        } else {
                            showAlert($t('Error'), response.message || $t('Could not load product data.'));
                        }
                    },
                    error: function(xhr, status, error) {
                        loadingModal.remove();
                        showAlert($t('Error'), $t('An error occurred while loading product data.'));
                    }
                });
            }

            function showImportForm(pid, productData) {
                currentProductPid = pid;
                currentProductData = productData;

                $('#product-preview-image').attr('src', productData.productImage || config.placeholderImage);
                $('#product-preview-name').text(productData.productNameEn || 'No Name');

                importFormOverlay.addClass('active');
                importForm.addClass('active');
            }

            function hideImportForm() {
                importFormOverlay.removeClass('active');
                importForm.removeClass('active');
                currentProductPid = null;
                currentProductData = null;
            }

            function importProduct() {
                if (!currentProductPid || !currentProductData) return;

                var loadingModal = showLoadingModal($t('Importing product...'));

                $.ajax({
                    url: config.importUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        pid: currentProductPid,
                        import_type: $('#import-type').val(),
                        markup_percentage: $('#markup-percentage').val(),
                        stock_quantity: $('#stock-quantity').val(),
                        category_ids: $('#category-ids').val(),
                        form_key: window.FORM_KEY
                    },
                    success: function(response) {
                        loadingModal.remove();
                        hideImportForm();
                        showAlert(
                            response.success ? $t('Success') : $t('Error'),
                            response.message || (response.success ? $t('Product imported successfully.') : $t('Import failed.'))
                        );
                    },
                    error: function() {
                        loadingModal.remove();
                        hideImportForm();
                        showAlert($t('Error'), $t('An error occurred during import.'));
                    }
                });
            }

            function viewProductDetails(pid) {
                if (!pid) return;

                var loadingModal = showLoadingModal($t('Loading product details...'));

                $.ajax({
                    url: config.detailsUrl,
                    type: 'GET',
                    dataType: 'json',
                    data: { pid: pid, form_key: window.FORM_KEY },
                    success: function(response) {
                        loadingModal.remove();
                        if (response.success && response.product) {
                            fetchProductVariants(pid, response.product);
                        } else {
                            showAlert($t('Error'), response.message || $t('Could not load product details.'));
                        }
                    },
                    error: function() {
                        loadingModal.remove();
                        showAlert($t('Error'), $t('An error occurred while loading product details.'));
                    }
                });
            }

            function fetchProductVariants(pid, product) {
                var loadingModal = showLoadingModal($t('Loading product variants...'));

                $.ajax({
                    url: config.getVariantsUrl,
                    type: 'GET',
                    dataType: 'json',
                    data: { pid: pid, form_key: window.FORM_KEY },
                    success: function(response) {
                        loadingModal.remove();
                        showProductDetailsModal(product, response.success ? response.variants : []);
                    },
                    error: function() {
                        loadingModal.remove();
                        showProductDetailsModal(product, []);
                    }
                });
            }

            function showProductDetailsModal(product, variants) {
                var modalContent = buildProductDetailsContent(product, variants);

                alert({
                    title: $t('Product Details'),
                    content: modalContent,
                    buttons: [{
                        text: $t('Close'),
                        class: 'action-secondary',
                        click: function () { this.closeModal(); }
                    }, {
                        text: $t('Import'),
                        class: 'action-primary',
                        click: function () {
                            getProductDataForImport(product.pid);
                            this.closeModal();
                        }
                    }]
                });
            }

            function buildProductDetailsContent(product, variants) {
                var hasVariants = variants && variants.length > 0;
                var variantsHtml = '';

                if (hasVariants) {
                    variantsHtml = '<div class="product-variants"><h4>' + $t('Product Variants') + ' (' + variants.length + ')</h4>';
                    $.each(variants, function(index, variant) {
                        variantsHtml += '<div class="variant-item">' +
                            '<div class="variant-header">' +
                            '<div class="variant-name">' + (variant.propertyValueName || 'Variant ' + (index + 1)) + '</div>' +
                            '<div class="variant-price">$' + (variant.variantSellPrice || '0.00') + '</div>' +
                            '</div>' +
                            (variant.variantImage ? '<div class="variant-image"><img src="' + variant.variantImage + '" alt="' + $t('Variant Image') + '" /></div>' : '') +
                            '<div class="variant-stock">' + $t('Stock') + ': ' + (variant.variantStock || '0') + '</div>' +
                            (variant.variantSku ? '<div class="variant-sku">' + $t('SKU') + ': ' + variant.variantSku + '</div>' : '') +
                            '</div>';
                    });
                    variantsHtml += '</div>';
                }

                return '<div class="product-detail-modal">' +
                    '<div class="product-images">' +
                    '<img src="' + (product.productImage || config.placeholderImage) + '" alt="' + $t('Product Image') + '" />' +
                    '</div>' +
                    '<div class="product-info">' +
                    '<h2>' + (product.productNameEn || 'No Name') + '</h2>' +
                    '<div class="product-detail"><strong>' + $t('Price') + ':</strong> $' + (product.sellPrice || '0.00') + '</div>' +
                    '<div class="product-detail"><strong>' + $t('SKU') + ':</strong> ' + (product.productSku || 'N/A') + '</div>' +
                    '<div class="product-detail"><strong>' + $t('Stock') + ':</strong> ' + (product.stock || '0') + '</div>' +
                    (product.packageWeight ? '<div class="product-detail"><strong>' + $t('Weight') + ':</strong> ' + product.packageWeight + ' kg</div>' : '') +
                    '<div class="product-description"><strong>' + $t('Description') + ':</strong><br>' + (product.description || $t('No description available.')) + '</div>' +
                    variantsHtml +
                    '</div>' +
                    '</div>';
            }

            function showLoadingModal(message) {
                var modal = $('<div class="loading-overlay"><div class="loading-message">' + message + '</div></div>');
                $('body').append(modal);
                return modal;
            }

            function showAlert(title, content) {
                alert({ title: title, content: content });
            }
        });
    };
});
