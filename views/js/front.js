/**
 * JavaScript para BLOQUEAR transportistas restringidos
 * Compatible con OnePageCheckoutPS y PrestaShop nativo
 */
$(document).ready(function() {
    if (typeof restrict_shipping_vars !== 'undefined' && restrict_shipping_vars.restrict_shipping_enabled) {
        initCarrierRestrictions();
        
        // Para OnePageCheckoutPS - múltiples intentos
        if (restrict_shipping_vars.is_onepagecheckoutps) {
            setTimeout(function() {
                initCarrierRestrictions();
            }, 1000);
            
            setTimeout(function() {
                initCarrierRestrictions();
            }, 3000);
            
            // Observar cambios específicos de OnePageCheckoutPS
            observeOnePageCheckoutPS();
        }
        
        // Observar cambios en el DOM
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList') {
                        setTimeout(function() {
                            checkCarrierAvailability();
                        }, 100);
                    }
                });
            });
            var targetNode = document.body;
            observer.observe(targetNode, { childList: true, subtree: true });
        }
    }
});

function observeOnePageCheckoutPS() {
    // Escuchar eventos específicos de OnePageCheckoutPS
    $(document).on('opc-carrier-refreshed opc-updated carrier-updated opc_carrier_updated', function() {
        setTimeout(function() {
            initCarrierRestrictions();
        }, 200);
    });
    
    // Escuchar cambios AJAX específicos de OnePageCheckoutPS
    $(document).ajaxComplete(function(event, xhr, settings) {
        if (settings.url && (settings.url.indexOf('carrier') !== -1 || 
                             settings.url.indexOf('delivery') !== -1 ||
                             settings.url.indexOf('loadCarrier') !== -1 ||
                             settings.url.indexOf('onepagecheckoutps') !== -1)) {
            setTimeout(function() {
                checkCarrierAvailability();
                hideRestrictedCarriersInOPC();
            }, 300);
        }
    });
    
    // Interceptar cuando OnePageCheckoutPS carga contenido
    var originalLoad = window.XMLHttpRequest.prototype.open;
    window.XMLHttpRequest.prototype.open = function(method, url) {
        if (url.indexOf('onepagecheckoutps') !== -1 || url.indexOf('loadCarrier') !== -1) {
            this.addEventListener('load', function() {
                setTimeout(function() {
                    hideRestrictedCarriersInOPC();
                }, 500);
            });
        }
        return originalLoad.apply(this, arguments);
    };
}

function hideRestrictedCarriersInOPC() {
    if (!restrict_shipping_vars.is_onepagecheckoutps) {
        return;
    }
    
    var carriersConfig = restrict_shipping_vars.restrict_shipping_carriers;
    var currentDay = parseInt(restrict_shipping_vars.current_day);
    var restrictedCarriers = [];
    
    // Identificar transportistas restringidos
    for (var carrierId in carriersConfig) {
        var allowedDays = carriersConfig[carrierId].days;
        if (allowedDays.indexOf(currentDay) === -1) {
            restrictedCarriers.push({
                id: carrierId,
                name: carriersConfig[carrierId].name,
                days: allowedDays.map(function(day) {
                    return restrict_shipping_vars.days_names[day];
                }).join(', ')
            });
            
            // OCULTAR transportista en OnePageCheckoutPS
            $('input[value="' + carrierId + '"], [data-carrier-id="' + carrierId + '"], [id*="carrier_' + carrierId + '"], [name*="carrier"][value="' + carrierId + '"]').each(function() {
                $(this).closest('.delivery-option, .carrier-container, .radio, .delivery-options-list > div, .opc-carrier, .carrier-line, tr, .carrier_module_container').hide();
            });
            
            // También ocultar por texto que contenga el ID
            $('*:contains("id_carrier=' + carrierId + '")').hide();
        }
    }
    
    // Mostrar mensaje de transportistas restringidos si los hay
    if (restrictedCarriers.length > 0) {
        showRestrictedCarriersMessage(restrictedCarriers);
    }
}

function showRestrictedCarriersMessage(restrictedCarriers) {
    // Remover mensajes anteriores
    $('.restricted-carriers-message-opc').remove();
    
    var messageHtml = '<div class="restricted-carriers-message-opc alert alert-danger" style="margin: 15px 0; padding: 15px; border-left: 6px solid #d9534f; background-color: #f2dede; color: #a94442;">';
    messageHtml += '<h4 style="margin-top: 0; color: #d9534f;"><i class="fa fa-ban" style="margin-right: 10px;"></i>No hay transportistas disponibles</h4>';
    messageHtml += '<p>Los siguientes transportistas no están disponibles hoy:</p>';
    messageHtml += '<ul>';
    
    for (var i = 0; i < restrictedCarriers.length; i++) {
        messageHtml += '<li><strong>' + restrictedCarriers[i].name + ':</strong> Disponible ' + restrictedCarriers[i].days + '</li>';
    }
    
    messageHtml += '</ul>';
    messageHtml += '<p>Por favor, inténtalo en uno de los días disponibles.</p>';
    messageHtml += '</div>';
    
    // Buscar dónde insertar el mensaje en OnePageCheckoutPS
    var $target = $('.carrier_module_container, .delivery-options-list, #carrier-selection, .opc-carrier-list, .carrier-container').first();
    if ($target.length) {
        $target.prepend(messageHtml);
    } else {
        // Fallback: añadir al final de la zona de transportistas
        $('.carrier_module, #shipping-method-information, .checkout-delivery-step').first().append(messageHtml);
    }
}

function initCarrierRestrictions() {
    checkCarrierAvailability();
    
    // Escuchar cambios en transportistas - selectores ampliados para OnePageCheckoutPS
    $(document).on('change', 'input[name="id_carrier"], input[type="radio"][name^="delivery_option"], select[name="id_carrier"], .delivery-option input, .carrier-container input, [name*="carrier"] input, .opc input[type="radio"]', function() {
        setTimeout(function() {
            checkCarrierAvailability();
        }, 100);
    });
    
    // BLOQUEAR envío de formularios
    $(document).on('submit', 'form[action*="order"], form[action*="checkout"], .checkout form, #checkout form, #onepagecheckout form, form[name*="opc"]', function(e) {
        if (!validateSelectedCarrier()) {
            e.preventDefault();
            e.stopPropagation();
            alert(getRestrictionMessage());
            return false;
        }
    });
    
    // BLOQUEAR botones de confirmación
    $(document).on('click', '#onepagecheckout-confirm, .payment-confirmation button, .btn-primary[type="submit"], button[name="confirmDeliveryOption"], .opc-confirm-btn, #opc-confirm', function(e) {
        if (!validateSelectedCarrier()) {
            e.preventDefault();
            e.stopPropagation();
            alert(getRestrictionMessage());
            return false;
        }
    });
    
    // BLOQUEAR botones de pago
    $(document).on('click', '.payment-option input, .payment-option-body button, .js-payment-option-form button, .opc-payment button', function(e) {
        if (!validateSelectedCarrier()) {
            e.preventDefault();
            e.stopPropagation();
            alert(getRestrictionMessage());
            return false;
        }
    });
    
    // OnePageCheckoutPS específico: ocultar transportistas restringidos
    if (restrict_shipping_vars.is_onepagecheckoutps) {
        setTimeout(function() {
            hideRestrictedCarriersInOPC();
        }, 500);
    }
}

function checkCarrierAvailability() {
    var carriersConfig = restrict_shipping_vars.restrict_shipping_carriers;
    var currentDay = parseInt(restrict_shipping_vars.current_day);
    
    // Verificar cada transportista - selectores ampliados
    $('input[name="id_carrier"], input[type="radio"][name^="delivery_option"], .delivery-option input, .carrier-container input, [name*="carrier"] input').each(function() {
        var carrierId = getCarrierIdFromElement($(this));
        
        if (carrierId && carriersConfig[carrierId]) {
            var allowedDays = carriersConfig[carrierId].days;
            var carrierElement = $(this).closest('.delivery-option, .carrier-container, .radio, .delivery-options-list > div, .opc-carrier, .carrier-line');
            
            if (allowedDays.indexOf(currentDay) === -1) {
                // DESHABILITAR y OCULTAR transportista
                disableCarrier($(this), carrierElement, carrierId);
            } else {
                // Habilitar transportista
                enableCarrier($(this), carrierElement);
            }
        }
    });
    
    // También verificar si hay algún transportista seleccionado que no esté disponible
    var selectedCarrierId = getSelectedCarrierId();
    if (selectedCarrierId && carriersConfig[selectedCarrierId]) {
        var allowedDays = carriersConfig[selectedCarrierId].days;
        if (allowedDays.indexOf(currentDay) === -1) {
            // Deseleccionar automáticamente
            $('input[name="id_carrier"][value="' + selectedCarrierId + '"], input[type="radio"][name^="delivery_option"]:checked, [name*="carrier"] input:checked').prop('checked', false);
        }
    }
}

function getCarrierIdFromElement($element) {
    var carrierId = null;
    
    // Método 1: Valor directo
    if ($element.attr('name') === 'id_carrier') {
        carrierId = $element.val();
    }
    
    // Método 2: delivery_option format
    if ($element.attr('name') && $element.attr('name').indexOf('delivery_option') !== -1) {
        var value = $element.val();
        if (value && value.indexOf(',') !== -1) {
            carrierId = value.split(',')[1];
        }
    }
    
    // Método 3: data attributes
    if (!carrierId) {
        carrierId = $element.data('carrier-id') || $element.data('id-carrier') || $element.data('carrierId');
    }
    
    // Método 4: buscar en contenedor
    if (!carrierId) {
        var $container = $element.closest('[data-carrier-id], [data-id-carrier], [data-carrier]');
        carrierId = $container.data('carrier-id') || $container.data('id-carrier') || $container.data('carrier');
    }
    
    // Método 5: buscar en value del input
    if (!carrierId && $element.val()) {
        var match = $element.val().match(/\d+,(\d+)/);
        if (match) {
            carrierId = match[1];
        }
    }
    
    // Método 6: buscar en el ID o class
    if (!carrierId) {
        var elementId = $element.attr('id') || '';
        var elementClass = $element.attr('class') || '';
        var match = (elementId + ' ' + elementClass).match(/carrier[_-]?(\d+)/i);
        if (match) {
            carrierId = match[1];
        }
    }
    
    return carrierId ? parseInt(carrierId) : null;
}

function disableCarrier($input, $container, carrierId) {
    // DESHABILITAR completamente
    $input.prop('disabled', true);
    $input.prop('checked', false);
    
    // OCULTAR la opción
    $container.hide();
    
    // Añadir clases CSS
    $container.addClass('carrier-disabled carrier-hidden');
    $input.addClass('disabled');
    
    // Mensaje de restricción
    var carrierName = restrict_shipping_vars.restrict_shipping_carriers[carrierId].name;
    var allowedDays = restrict_shipping_vars.restrict_shipping_carriers[carrierId].days;
    var daysNames = [];
    
    for (var i = 0; i < allowedDays.length; i++) {
        daysNames.push(restrict_shipping_vars.days_names[allowedDays[i]]);
    }
    
    var message = restrict_shipping_vars.restrict_shipping_message.replace('{days}', daysNames.join(', '));
    
    // Mostrar mensaje si el contenedor estaba visible
    if ($container.is(':visible')) {
        var messageHtml = '<div class="restriction-message alert alert-danger" style="margin: 10px 0;">' +
                         '<i class="fa fa-ban"></i> <strong>' + carrierName + ':</strong> ' + message +
                         '</div>';
        $container.after(messageHtml);
    }
}

function enableCarrier($input, $container) {
    // Habilitar
    $input.prop('disabled', false);
    $container.show();
    
    // Quitar clases CSS
    $container.removeClass('carrier-disabled carrier-hidden');
    $input.removeClass('disabled');
    
    // Quitar mensajes
    $container.siblings('.restriction-message').remove();
}

function validateSelectedCarrier() {
    var selectedCarrierId = getSelectedCarrierId();
    
    if (!selectedCarrierId) {
        // No hay transportista seleccionado - permitir que PrestaShop maneje la validación
        return true;
    }
    
    var carriersConfig = restrict_shipping_vars.restrict_shipping_carriers;
    var currentDay = parseInt(restrict_shipping_vars.current_day);
    
    if (carriersConfig[selectedCarrierId]) {
        var allowedDays = carriersConfig[selectedCarrierId].days;
        if (allowedDays.indexOf(currentDay) === -1) {
            return false; // BLOQUEAR - transportista no disponible
        }
    }
    
    return true; // Permitir - transportista disponible o no restringido
}

function getSelectedCarrierId() {
    var carrierId = null;
    
    // Selectores ampliados para OnePageCheckoutPS
    var $selected = $('input[name="id_carrier"]:checked, input[type="radio"][name^="delivery_option"]:checked, [name*="carrier"] input:checked, .delivery-option input:checked, .carrier-container input:checked');
    
    if ($selected.length > 0) {
        carrierId = getCarrierIdFromElement($selected);
    }
    
    return carrierId;
}

function getRestrictionMessage() {
    var selectedCarrierId = getSelectedCarrierId();
    
    if (selectedCarrierId && restrict_shipping_vars.restrict_shipping_carriers/**
 * JavaScript para BLOQUEAR transportistas restringidos
 * Compatible con OnePageCheckoutPS
 */
$(document).ready(function() {
    if (typeof restrict_shipping_vars !== 'undefined' && restrict_shipping_vars.restrict_shipping_enabled) {
        initCarrierRestrictions();
        
        // Para OnePageCheckoutPS - múltiples intentos
        setTimeout(function() {
            initCarrierRestrictions();
        }, 1000);
        
        setTimeout(function() {
            initCarrierRestrictions();
        }, 3000);
        
        // Observar cambios en el DOM
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList') {
                        setTimeout(function() {
                            checkCarrierAvailability();
                        }, 100);
                    }
                });
            });
            var targetNode = document.body;
            observer.observe(targetNode, { childList: true, subtree: true });
        }
        
        // Escuchar eventos específicos de OnePageCheckoutPS
        $(document).on('opc-carrier-refreshed opc-updated carrier-updated', function() {
            setTimeout(function() {
                initCarrierRestrictions();
            }, 200);
        });
        
        // Escuchar cambios AJAX
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url && (settings.url.indexOf('carrier') !== -1 || settings.url.indexOf('delivery') !== -1)) {
                setTimeout(function() {
                    checkCarrierAvailability();
                }, 300);
            }
        });
    }
});

function initCarrierRestrictions() {
    checkCarrierAvailability();
    
    // Escuchar cambios en transportistas - múltiples selectores
    $(document).on('change', 'input[name="id_carrier"], input[type="radio"][name^="delivery_option"], select[name="id_carrier"], .delivery-option input, .carrier-container input', function() {
        setTimeout(function() {
            checkCarrierAvailability();
        }, 100);
    });
    
    // OnePageCheckoutPS específico - más selectores
    $(document).on('change', '#onepagecheckout input[type="radio"], .onepagecheckout input[type="radio"], .opc input[type="radio"], [name*="carrier"] input, [id*="carrier"] input', function() {
        setTimeout(function() {
            checkCarrierAvailability();
        }, 100);
    });
    
    // BLOQUEAR envío de formularios
    $(document).on('submit', 'form[action*="order"], form[action*="checkout"], .checkout form, #checkout form, #onepagecheckout form', function(e) {
        if (!validateSelectedCarrier()) {
            e.preventDefault();
            e.stopPropagation();
            alert(getRestrictionMessage());
            return false;
        }
    });
    
    // BLOQUEAR botones de confirmación - más selectores
    $(document).on('click', '#onepagecheckout-confirm, .payment-confirmation button, .btn-primary[type="submit"], button[name="confirmDeliveryOption"], .opc-confirm-btn, #opc-confirm', function(e) {
        if (!validateSelectedCarrier()) {
            e.preventDefault();
            e.stopPropagation();
            alert(getRestrictionMessage());
            return false;
        }
    });
    
    // BLOQUEAR botones de pago
    $(document).on('click', '.payment-option input, .payment-option-body button, .js-payment-option-form button, .opc-payment button', function(e) {
        if (!validateSelectedCarrier()) {
            e.preventDefault();
            e.stopPropagation();
            alert(getRestrictionMessage());
            return false;
        }
    });
}

function checkCarrierAvailability() {
    var carriersConfig = restrict_shipping_vars.restrict_shipping_carriers;
    var currentDay = parseInt(restrict_shipping_vars.current_day);
    
    // Verificar cada transportista - selectores ampliados
    $('input[name="id_carrier"], input[type="radio"][name^="delivery_option"], .delivery-option input, .carrier-container input, [name*="carrier"] input').each(function() {
        var carrierId = getCarrierIdFromElement($(this));
        
        if (carrierId && carriersConfig[carrierId]) {
            var allowedDays = carriersConfig[carrierId].days;
            var carrierElement = $(this).closest('.delivery-option, .carrier-container, .radio, .delivery-options-list > div, .opc-carrier, .carrier-line');
            
            if (allowedDays.indexOf(currentDay) === -1) {
                // DESHABILITAR y OCULTAR transportista
                disableCarrier($(this), carrierElement, carrierId);
            } else {
                // Habilitar transportista
                enableCarrier($(this), carrierElement);
            }
        }
    });
    
    // También verificar si hay algún transportista seleccionado que no esté disponible
    var selectedCarrierId = getSelectedCarrierId();
    if (selectedCarrierId && carriersConfig[selectedCarrierId]) {
        var allowedDays = carriersConfig[selectedCarrierId].days;
        if (allowedDays.indexOf(currentDay) === -1) {
            // Deseleccionar automáticamente
            $('input[name="id_carrier"][value="' + selectedCarrierId + '"], input[type="radio"][name^="delivery_option"]:checked, [name*="carrier"] input:checked').prop('checked', false);
        }
    }
}

function getCarrierIdFromElement($element) {
    var carrierId = null;
    
    // Método 1: Valor directo
    if ($element.attr('name') === 'id_carrier') {
        carrierId = $element.val();
    }
    
    // Método 2: delivery_option format
    if ($element.attr('name') && $element.attr('name').indexOf('delivery_option') !== -1) {
        var value = $element.val();
        if (value && value.indexOf(',') !== -1) {
            carrierId = value.split(',')[1];
        }
    }
    
    // Método 3: data attributes
    if (!carrierId) {
        carrierId = $element.data('carrier-id') || $element.data('id-carrier') || $element.data('carrierId');
    }
    
    // Método 4: buscar en contenedor
    if (!carrierId) {
        var $container = $element.closest('[data-carrier-id], [data-id-carrier], [data-carrier]');
        carrierId = $container.data('carrier-id') || $container.data('id-carrier') || $container.data('carrier');
    }
    
    // Método 5: buscar en value del input
    if (!carrierId && $element.val()) {
        var match = $element.val().match(/\d+,(\d+)/);
        if (match) {
            carrierId = match[1];
        }
    }
    
    // Método 6: buscar en el ID o class
    if (!carrierId) {
        var elementId = $element.attr('id') || '';
        var elementClass = $element.attr('class') || '';
        var match = (elementId + ' ' + elementClass).match(/carrier[_-]?(\d+)/i);
        if (match) {
            carrierId = match[1];
        }
    }
    
    return carrierId ? parseInt(carrierId) : null;
}

function disableCarrier($input, $container, carrierId) {
    // DESHABILITAR completamente
    $input.prop('disabled', true);
    $input.prop('checked', false);
    
    // OCULTAR la opción
    $container.hide();
    
    // Añadir clases CSS
    $container.addClass('carrier-disabled carrier-hidden');
    $input.addClass('disabled');
    
    // Mensaje de restricción
    var carrierName = restrict_shipping_vars.restrict_shipping_carriers[carrierId].name;
    var allowedDays = restrict_shipping_vars.restrict_shipping_carriers[carrierId].days;
    var daysNames = [];
    
    for (var i = 0; i < allowedDays.length; i++) {
        daysNames.push(restrict_shipping_vars.days_names[allowedDays[i]]);
    }
    
    var message = restrict_shipping_vars.restrict_shipping_message.replace('{days}', daysNames.join(', '));
    
    // Mostrar mensaje si el contenedor estaba visible
    if ($container.is(':visible')) {
        var messageHtml = '<div class="restriction-message alert alert-danger" style="margin: 10px 0;">' +
                         '<i class="fa fa-ban"></i> <strong>' + carrierName + ':</strong> ' + message +
                         '</div>';
        $container.after(messageHtml);
    }
}

function enableCarrier($input, $container) {
    // Habilitar
    $input.prop('disabled', false);
    $container.show();
    
    // Quitar clases CSS
    $container.removeClass('carrier-disabled carrier-hidden');
    $input.removeClass('disabled');
    
    // Quitar mensajes
    $container.siblings('.restriction-message').remove();
}

function validateSelectedCarrier() {
    var selectedCarrierId = getSelectedCarrierId();
    
    if (!selectedCarrierId) {
        // No hay transportista seleccionado - permitir que PrestaShop maneje la validación
        return true;
    }
    
    var carriersConfig = restrict_shipping_vars.restrict_shipping_carriers;
    var currentDay = parseInt(restrict_shipping_vars.current_day);
    
    if (carriersConfig[selectedCarrierId]) {
        var allowedDays = carriersConfig[selectedCarrierId].days;
        if (allowedDays.indexOf(currentDay) === -1) {
            return false; // BLOQUEAR - transportista no disponible
        }
    }
    
    return true; // Permitir - transportista disponible o no restringido
}

function getSelectedCarrierId() {
    var carrierId = null;
    
    // Selectores ampliados para OnePageCheckoutPS
    var $selected = $('input[name="id_carrier"]:checked, input[type="radio"][name^="delivery_option"]:checked, [name*="carrier"] input:checked, .delivery-option input:checked, .carrier-container input:checked');
    
    if ($selected.length > 0) {
        carrierId = getCarrierIdFromElement($selected);
    }
    
    return carrierId;
}

function getRestrictionMessage() {
    var selectedCarrierId = getSelectedCarrierId();
    
    if (selectedCarrierId && restrict_shipping_vars.restrict_shipping_carriers[selectedCarrierId]) {
        var carrierName = restrict_shipping_vars.restrict_shipping_carriers[selectedCarrierId].name;
        var allowedDays = restrict_shipping_vars.restrict_shipping_carriers[selectedCarrierId].days;
        var daysNames = [];
        
        for (var i = 0; i < allowedDays.length; i++) {
            daysNames.push(restrict_shipping_vars.days_names[allowedDays[i]]);
        }
        
        return carrierName + ': ' + restrict_shipping_vars.restrict_shipping_message.replace('{days}', daysNames.join(', '));
    }
    
    return 'El transportista seleccionado no está disponible hoy.';
}

function debugCarrierInfo() {
    console.log('Current day:', restrict_shipping_vars.current_day);
    console.log('Carriers config:', restrict_shipping_vars.restrict_shipping_carriers);
    console.log('Selected carrier:', getSelectedCarrierId());
    console.log('Validation result:', validateSelectedCarrier());
}