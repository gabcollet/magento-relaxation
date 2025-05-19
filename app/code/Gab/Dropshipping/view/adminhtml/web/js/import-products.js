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
            var closeFormBtn = $('#close-import-form');
            var cancelImportBtn = $('#cancel-import');
            var confirmImportBtn = $('#confirm-import');
            var currentPage = 1;
            var totalPages = 1;
            var itemsPerPage = 20;
            var currentProductPid = null;
            var currentProductData = null;

            // Search products when the search button is clicked
            searchBtn.on('click', function() {
                searchProducts(1);
            });

            // Also search when Enter key is pressed in the search field
            searchTerm.on('keypress', function(e) {
                if (e.which === 13) {
                    searchBtn.trigger('click');
                    return false;
                }
            });

            // Close import form when close button is clicked
            closeFormBtn.on('click', function() {
                hideImportForm();
            });

            // Close import form when cancel button is clicked
            cancelImportBtn.on('click', function() {
                hideImportForm();
            });

            // Close import form when clicking on overlay
            importFormOverlay.on('click', function() {
                hideImportForm();
            });

            // Import product when confirm button is clicked
            confirmImportBtn.on('click', function() {
                importProduct();
            });

            // Function to show import form
            function showImportForm(pid, productData) {
                currentProductPid = pid;
                currentProductData = productData;

                // Set product preview
                $('#product-preview-image').attr('src', productData.productImage || config.placeholderImage);
                $('#product-preview-name').text(productData.productNameEn || 'No Name');

                // Show form and overlay
                importFormOverlay.addClass('active');
                importForm.addClass('active');
            }

            // Function to hide import form
            function hideImportForm() {
                importFormOverlay.removeClass('active');
                importForm.removeClass('active');
                currentProductPid = null;
                currentProductData = null;
            }

            // Function to handle product search
            function searchProducts(page) {
                // Collect selected categories
                var categories = [];
                $('input[name="categories[]"]:checked').each(function() {
                    categories.push($(this).val());
                });

                // Show loading message
                noResultsMessage.hide();
                resultsContainer.hide();
                loadingMessage.insertAfter(noResultsMessage);

                // Disable search button to prevent multiple requests
                searchBtn.prop('disabled', true).addClass('disabled');

                // Make AJAX request
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
                    success: function(response) {
                        loadingMessage.remove();

                        // Vérifier si l'erreur est liée à une limitation de taux
                        if (!response.success && response.message && response.message.indexOf('Limitation des requêtes API') !== -1) {
                            // Extraire le temps d'attente de l'erreur
                            var waitTime = response.message.match(/attendre\s+(\d+)\s+secondes/);
                            if (waitTime && waitTime[1]) {
                                var seconds = parseInt(waitTime[1]);
                                var timerElement = $('<div class="rate-limit-timer">Réessayer dans <span>' + seconds + '</span> secondes</div>');
                                noResultsMessage.html(response.message + '<br>')
                                    .append(timerElement)
                                    .addClass('message-warning')
                                    .show();

                                // Démarrer un compte à rebours
                                var timer = setInterval(function() {
                                    seconds--;
                                    timerElement.find('span').text(seconds);
                                    if (seconds <= 0) {
                                        clearInterval(timer);
                                        timerElement.text('Vous pouvez maintenant réessayer.');

                                        // Activer le bouton de recherche
                                        searchBtn.prop('disabled', false).removeClass('disabled');

                                        // Changer le style du message
                                        noResultsMessage.removeClass('message-warning').addClass('message-info');
                                    }
                                }, 1000);
                            } else {
                                noResultsMessage.text(response.message).addClass('message-warning').show();

                                // Réactiver le bouton après 3 secondes
                                setTimeout(function() {
                                    searchBtn.prop('disabled', false).removeClass('disabled');
                                }, 3000);
                            }
                            resultsContainer.hide();
                            return;
                        }

                        // Réactiver le bouton de recherche
                        searchBtn.prop('disabled', false).removeClass('disabled');

                        if (response.success && response.products && response.products.length > 0) {
                            // Render products
                            renderProducts(response.products);

                            // Update pagination
                            currentPage = response.page || 1;
                            totalPages = Math.ceil((response.totalCount || 0) / itemsPerPage);
                            renderPagination();

                            resultsContainer.show();
                            noResultsMessage.hide();

                            // Scroll to results
                            $('html, body').animate({
                                scrollTop: resultsContainer.offset().top - 50
                            }, 500);
                        } else {
                            // Show no results message
                            noResultsMessage.text(response.message || $t('No products found. Please try different search terms.'))
                                .removeClass('message-warning message-info')
                                .addClass('message-notice')
                                .show();
                            resultsContainer.hide();
                        }
                    },
                    error: function(xhr, status, error) {
                        loadingMessage.remove();

                        // Réactiver le bouton de recherche
                        searchBtn.prop('disabled', false).removeClass('disabled');

                        // Afficher un message d'erreur
                        var errorMessage = '';

                        try {
                            var response = JSON.parse(xhr.responseText);
                            errorMessage = response.message || $t('An error occurred during the search. Please try again.');
                        } catch (e) {
                            errorMessage = $t('An error occurred during the search. Please try again.');
                        }

                        noResultsMessage.text(errorMessage)
                            .removeClass('message-warning message-info')
                            .addClass('message-error')
                            .show();
                        resultsContainer.hide();
                        console.error('AJAX Error:', error);
                    },
                    complete: function() {
                        // S'assurer que le bouton de recherche est réactivé en cas de problème inattendu
                        if (searchBtn.prop('disabled')) {
                            setTimeout(function() {
                                searchBtn.prop('disabled', false).removeClass('disabled');
                            }, 3000);
                        }
                    }
                });
            }

            // Function to render products
            function renderProducts(products) {
                productsGrid.empty();

                $.each(products, function(index, product) {
                    var productHtml =
                        '<div class="product-item">' +
                        '<div class="product-image">' +
                        '<img src="' + (product.productImage || config.placeholderImage) + '" alt="' + $t('Product Image') + '" />' +
                        '</div>' +
                        '<div class="product-details">' +
                        '<h4>' + (product.productNameEn || 'No Name') + '</h4>' +
                        '<div class="product-info">' +
                        '<div class="product-price">' +
                        $t('Price') + ': $' + (product.sellPrice || '0.00') +
                        '</div>' +
                        '<div class="product-sku">' +
                        $t('SKU') + ': ' + (product.productSku || 'N/A') +
                        '</div>' +
                        '</div>' +
                        '<div class="product-actions">' +
                        '<button type="button" class="action-secondary view-details" data-pid="' + (product.pid || '') + '">' +
                        $t('View Details') +
                        '</button>' +
                        '<button type="button" class="action-primary import-product" data-pid="' + (product.pid || '') + '">' +
                        $t('Import') +
                        '</button>' +
                        '</div>' +
                        '</div>' +
                        '</div>';

                    productsGrid.append(productHtml);
                });

                // Add event listeners to the newly created buttons
                productsGrid.find('.view-details').on('click', function() {
                    var pid = $(this).data('pid');
                    viewProductDetails(pid);
                });

                productsGrid.find('.import-product').on('click', function() {
                    var pid = $(this).data('pid');
                    getProductDataForImport(pid);
                });
            }

            // Function to render pagination
            function renderPagination() {
                paginationContainer.empty();

                if (totalPages <= 1) {
                    return;
                }

                var paginationHtml = '<div class="pages">';

                // Previous button
                if (currentPage > 1) {
                    paginationHtml += '<button class="page-prev" data-page="' + (currentPage - 1) + '">' + $t('Previous') + '</button>';
                } else {
                    paginationHtml += '<button class="page-prev disabled">' + $t('Previous') + '</button>';
                }

                // Page numbers
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

                // Next button
                if (currentPage < totalPages) {
                    paginationHtml += '<button class="page-next" data-page="' + (currentPage + 1) + '">' + $t('Next') + '</button>';
                } else {
                    paginationHtml += '<button class="page-next disabled">' + $t('Next') + '</button>';
                }

                paginationHtml += '</div>';

                paginationContainer.html(paginationHtml);

                // Add event listeners to pagination buttons
                paginationContainer.find('.page-number, .page-prev:not(.disabled), .page-next:not(.disabled)').on('click', function() {
                    var page = $(this).data('page');
                    searchProducts(page);
                });
            }

            // Function to view product details
            function viewProductDetails(pid) {
                if (!pid) {
                    return;
                }

                // Show loading message
                var loadingModal = $('<div class="loading-overlay"><div class="loading-message">' + $t('Loading product details...') + '</div></div>');
                $('body').append(loadingModal);

                // Fetch product details
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
                            // Fetch variants if product has them
                            fetchProductVariants(pid, response.product);
                        } else {
                            alert({
                                title: $t('Error'),
                                content: response.message || $t('Could not load product details. Please try again.')
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        loadingModal.remove();
                        alert({
                            title: $t('Error'),
                            content: $t('An error occurred while loading product details. Please try again.')
                        });
                        console.error('AJAX Error:', error);
                    }
                });
            }

            // Function to fetch product variants
            function fetchProductVariants(pid, product) {
                // Show loading message
                var loadingModal = $('<div class="loading-overlay"><div class="loading-message">' + $t('Loading product variants...') + '</div></div>');
                $('body').append(loadingModal);

                // Fetch product variants
                $.ajax({
                    url: config.getVariantsUrl,
                    type: 'GET',
                    dataType: 'json',
                    data: {
                        pid: pid,
                        form_key: window.FORM_KEY
                    },
                    success: function(response) {
                        loadingModal.remove();

                        if (response.success && response.variants) {
                            showProductDetailsModal(product, response.variants);
                        } else {
                            // Show details without variants
                            showProductDetailsModal(product, []);
                        }
                    },
                    error: function(xhr, status, error) {
                        loadingModal.remove();
                        // Show details without variants
                        showProductDetailsModal(product, []);
                        console.error('AJAX Error:', error);
                    }
                });
            }

            // Function to get product data for import
            function getProductDataForImport(pid) {
                if (!pid) {
                    return;
                }

                // Show loading message
                var loadingModal = $('<div class="loading-overlay"><div class="loading-message">' + $t('Loading product data...') + '</div></div>');
                $('body').append(loadingModal);

                // Fetch product details
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
                            // Show import form with product data
                            showImportForm(pid, response.product);
                        } else {
                            alert({
                                title: $t('Error'),
                                content: response.message || $t('Could not load product data. Please try again.')
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        loadingModal.remove();
                        alert({
                            title: $t('Error'),
                            content: $t('An error occurred while loading product data. Please try again.')
                        });
                        console.error('AJAX Error:', error);
                    }
                });
            }

            // Function to display product details modal
            function showProductDetailsModal(product, variants) {
                var hasVariants = variants && variants.length > 0;

                var variantsHtml = '';
                if (hasVariants) {
                    variantsHtml += '<div class="product-variants">';
                    variantsHtml += '<h4>' + $t('Product Variants') + ' (' + variants.length + ')</h4>';

                    $.each(variants, function(index, variant) {
                        variantsHtml += '<div class="variant-item">';
                        variantsHtml += '<div class="variant-header">';
                        variantsHtml += '<div class="variant-name">' + (variant.propertyValueName || 'Variant ' + (index + 1)) + '</div>';
                        variantsHtml += '<div class="variant-price">$' + (variant.variantSellPrice || '0.00') + '</div>';
                        variantsHtml += '</div>';

                        if (variant.variantImage) {
                            variantsHtml += '<div class="variant-image"><img src="' + variant.variantImage + '" alt="' + $t('Variant Image') + '" /></div>';
                        }

                        variantsHtml += '<div class="variant-stock">' + $t('Stock') + ': ' + (variant.variantStock || '0') + '</div>';

                        if (variant.variantSku) {
                            variantsHtml += '<div class="variant-sku">' + $t('SKU') + ': ' + variant.variantSku + '</div>';
                        }

                        variantsHtml += '</div>';
                    });

                    variantsHtml += '</div>';
                }

                var modalContent =
                    '<div class="product-detail-modal">' +
                    '<div class="product-images">' +
                    '<img src="' + (product.productImage || config.placeholderImage) + '" alt="' + $t('Product Image') + '" />' +
                    '</div>' +
                    '<div class="product-info">' +
                    '<h2>' + (product.productNameEn || 'No Name') + '</h2>' +
                    '<div class="product-detail">' +
                    '<strong>' + $t('Price') + ':</strong> $' + (product.sellPrice || '0.00') +
                    '</div>' +
                    '<div class="product-detail">' +
                    '<strong>' + $t('SKU') + ':</strong> ' + (product.productSku || 'N/A') +
                    '</div>' +
                    '<div class="product-detail">' +
                    '<strong>' + $t('Stock') + ':</strong> ' + (product.stock || '0') +
                    '</div>' +
                    (product.packageWeight ? '<div class="product-detail">' +
                        '<strong>' + $t('Weight') + ':</strong> ' + product.packageWeight + ' kg' +
                        '</div>' : '') +
                    (product.packageLength && product.packageWidth && product.packageHeight ?
                        '<div class="product-detail">' +
                        '<strong>' + $t('Dimensions') + ':</strong> ' +
                        product.packageLength + ' x ' + product.packageWidth + ' x ' + product.packageHeight + ' cm' +
                        '</div>' : '') +
                    '<div class="product-description">' +
                    '<strong>' + $t('Description') + ':</strong><br>' +
                    (product.description || $t('No description available.')) +
                    '</div>' +
                    (hasVariants ? variantsHtml : '') +
                    '<div class="modal-actions">' +
                    '<button type="button" class="action-primary import-from-modal" data-pid="' + (product.pid || '') + '">' +
                    $t('Import Product') +
                    '</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>';

                alert({
                    title: $t('Product Details'),
                    content: modalContent,
                    actions: {
                        always: function() {
                            // Nothing specific to do when modal is closed
                        }
                    },
                    buttons: [{
                        text: $.mage.__('Close'),
                        class: 'action-secondary',
                        click: function () {
                            this.closeModal();
                        }
                    }, {
                        text: $.mage.__('Import'),
                        class: 'action-primary',
                        click: function () {
                            getProductDataForImport(product.pid);
                            this.closeModal();
                        }
                    }]
                });

                // Add event listener to the import button in the modal
                $('.import-from-modal').on('click', function() {
                    var pid = $(this).data('pid');
                    getProductDataForImport(pid);
                });
            }

            // Function to import a product
            function importProduct() {
                if (!currentProductPid || !currentProductData) {
                    return;
                }

                // Show loading message
                var loadingModal = $('<div class="loading-overlay"><div class="loading-message">' + $t('Importing product...') + '</div></div>');
                $('body').append(loadingModal);

                // Get form values
                var importType = $('#import-type').val();
                var markupPercentage = $('#markup-percentage').val();
                var stockQuantity = $('#stock-quantity').val();
                var categoryIds = $('#category-ids').val();

                // Send import request
                $.ajax({
                    url: config.importUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        pid: currentProductPid,
                        import_type: importType,
                        markup_percentage: markupPercentage,
                        stock_quantity: stockQuantity,
                        category_ids: categoryIds,
                        form_key: window.FORM_KEY
                    },
                    success: function(response) {
                        loadingModal.remove();
                        hideImportForm();

                        if (response.success) {
                            // Show success message
                            alert({
                                title: $t('Success'),
                                content: response.message || $t('Product has been imported successfully.')
                            });
                        } else {
                            // Show error message
                            alert({
                                title: $t('Error'),
                                content: response.message || $t('Could not import product. Please try again.')
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        loadingModal.remove();
                        hideImportForm();

                        // Show error message
                        alert({
                            title: $t('Error'),
                            content: $t('An error occurred during import. Please try again.')
                        });
                        console.error('AJAX Error:', error);
                    }
                });
            }
        });
    };
});
