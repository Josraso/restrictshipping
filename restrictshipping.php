<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class RestrictShipping extends Module
{
    public function __construct()
    {
        $this->name = 'restrictshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Custom Module';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'Restricción de Transportistas';
        $this->description = 'Permite restringir transportistas por días de la semana.';
        $this->confirmUninstall = '¿Seguro que quieres desinstalar?';
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        Configuration::updateValue('RESTRICT_SHIPPING_ENABLED', 1);
        Configuration::updateValue('RESTRICT_SHIPPING_CARRIERS', json_encode(array()));
        Configuration::updateValue('RESTRICT_SHIPPING_MESSAGE', 'Este transportista no está disponible hoy. Días disponibles: {days}');

        $hooks_registered = true;
        $hooks_registered &= $this->registerHook('header');
        $hooks_registered &= $this->registerHook('displayShoppingCartFooter');
        $hooks_registered &= $this->registerHook('displayProductAdditionalInfo');
        
        // Hook principal para filtrar transportistas (PrestaShop 1.7.8+)
        if (Hook::getIdByName('actionFilterDeliveryOptionList')) {
            $hooks_registered &= $this->registerHook('actionFilterDeliveryOptionList');
        }
        
        // Hooks alternativos para versiones anteriores
        $hooks_registered &= $this->registerHook('displayBeforeCarrier');
        $hooks_registered &= $this->registerHook('displayAfterCarrier');
        $hooks_registered &= $this->registerHook('actionCarrierProcess');
        
        // NUEVO: Hook que se ejecuta DESPUÉS de cargar transportistas
        $hooks_registered &= $this->registerHook('displayCarrierList');
        
        // Hook específico para interceptar cuando se selecciona un transportista
        $hooks_registered &= $this->registerHook('actionObjectCarrierUpdateAfter');

        return $hooks_registered;
    }

    public function uninstall()
    {
        Configuration::deleteByName('RESTRICT_SHIPPING_ENABLED');
        Configuration::deleteByName('RESTRICT_SHIPPING_CARRIERS');
        Configuration::deleteByName('RESTRICT_SHIPPING_MESSAGE');
        
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $enabled = (bool)Tools::getValue('RESTRICT_SHIPPING_ENABLED');
            $message = pSQL(Tools::getValue('RESTRICT_SHIPPING_MESSAGE'));
            $carriers_config = array();

            $carriers = $this->getAllActiveCarriers();
            if ($carriers) {
                foreach ($carriers as $carrier) {
                    $carrier_enabled = (bool)Tools::getValue('carrier_enabled_' . (int)$carrier['id_carrier']);
                    $carrier_days = Tools::getValue('carrier_days_' . (int)$carrier['id_carrier']);
                    
                    if ($carrier_enabled && is_array($carrier_days) && count($carrier_days) > 0) {
                        $carriers_config[(int)$carrier['id_carrier']] = array(
                            'enabled' => true,
                            'days' => array_map('intval', $carrier_days),
                            'name' => pSQL($carrier['name'])
                        );
                    }
                }
            }

            Configuration::updateValue('RESTRICT_SHIPPING_ENABLED', $enabled);
            Configuration::updateValue('RESTRICT_SHIPPING_MESSAGE', $message);
            Configuration::updateValue('RESTRICT_SHIPPING_CARRIERS', json_encode($carriers_config));

            $output .= $this->displayConfirmation('Configuración guardada correctamente.');
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $days_options = array(
            array('id' => 1, 'name' => 'Lunes'),
            array('id' => 2, 'name' => 'Martes'),
            array('id' => 3, 'name' => 'Miércoles'),
            array('id' => 4, 'name' => 'Jueves'),
            array('id' => 5, 'name' => 'Viernes'),
            array('id' => 6, 'name' => 'Sábado'),
            array('id' => 7, 'name' => 'Domingo')
        );

        $carriers = $this->getAllActiveCarriers();
        $carriers_config = json_decode(Configuration::get('RESTRICT_SHIPPING_CARRIERS'), true);
        if (!$carriers_config) {
            $carriers_config = array();
        }

        $carriers_html = '<div class="panel panel-default">';
        $carriers_html .= '<div class="panel-heading">Configuración de Transportistas</div>';
        $carriers_html .= '<div class="panel-body">';
        
        if (empty($carriers)) {
            $carriers_html .= '<div class="alert alert-warning">No hay transportistas activos configurados.</div>';
        } else {
            $carriers_html .= '<p>Selecciona los transportistas que quieres restringir y los días disponibles:</p>';
            
            foreach ($carriers as $carrier) {
                $carrier_id = (int)$carrier['id_carrier'];
                $is_enabled = isset($carriers_config[$carrier_id]) && $carriers_config[$carrier_id]['enabled'];
                $selected_days = isset($carriers_config[$carrier_id]) ? $carriers_config[$carrier_id]['days'] : array();
                
                $zones = $this->getCarrierZones($carrier_id);
                $zone_names = array();
                if ($zones) {
                    foreach ($zones as $zone) {
                        if (isset($zone['name'])) {
                            $zone_names[] = $zone['name'];
                        }
                    }
                }
                $zones_text = !empty($zone_names) ? ' (Zonas: ' . implode(', ', $zone_names) . ')' : '';
                
                $carriers_html .= '<div class="carrier-config" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">';
                $carriers_html .= '<h4><label>';
                $carriers_html .= '<input type="checkbox" name="carrier_enabled_' . $carrier_id . '" value="1"' . ($is_enabled ? ' checked="checked"' : '') . '> ';
                $carriers_html .= htmlspecialchars($carrier['name']) . $zones_text;
                $carriers_html .= '</label></h4>';
                
                $carriers_html .= '<div class="days-selection" style="margin-left: 20px; margin-top: 10px;">';
                $carriers_html .= '<strong>Días disponibles:</strong><br>';
                
                foreach ($days_options as $day) {
                    $checked = in_array((int)$day['id'], $selected_days) ? ' checked="checked"' : '';
                    $carriers_html .= '<label style="margin-right: 15px; display: inline-block;">';
                    $carriers_html .= '<input type="checkbox" name="carrier_days_' . $carrier_id . '[]" value="' . (int)$day['id'] . '"' . $checked . '> ';
                    $carriers_html .= htmlspecialchars($day['name']);
                    $carriers_html .= '</label>';
                }
                
                $carriers_html .= '</div>';
                $carriers_html .= '</div>';
            }
        }
        
        $carriers_html .= '</div>';
        $carriers_html .= '</div>';

        $fields_form = array();
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => 'Configuración General',
            ),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => 'Habilitar restricción',
                    'name' => 'RESTRICT_SHIPPING_ENABLED',
                    'is_bool' => true,
                    'desc' => 'Activar o desactivar la restricción de transportistas',
                    'values' => array(
                        array(
                            'id' => 'active_on',
                            'value' => true,
                            'label' => 'Sí'
                        ),
                        array(
                            'id' => 'active_off',
                            'value' => false,
                            'label' => 'No'
                        )
                    ),
                ),
                array(
                    'type' => 'textarea',
                    'label' => 'Mensaje de restricción',
                    'name' => 'RESTRICT_SHIPPING_MESSAGE',
                    'desc' => 'Mensaje que se mostrará cuando el transportista no esté disponible. Usa {days} para mostrar los días disponibles.',
                    'rows' => 3,
                    'cols' => 60
                ),
                array(
                    'type' => 'html',
                    'name' => 'carriers_config',
                    'html_content' => $carriers_html
                )
            ),
            'submit' => array(
                'title' => 'Guardar',
                'class' => 'btn btn-default pull-right'
            )
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;

        $helper->fields_value['RESTRICT_SHIPPING_ENABLED'] = (bool)Configuration::get('RESTRICT_SHIPPING_ENABLED');
        $helper->fields_value['RESTRICT_SHIPPING_MESSAGE'] = Configuration::get('RESTRICT_SHIPPING_MESSAGE');

        return $helper->generateForm($fields_form);
    }

    private function getAllActiveCarriers()
    {
        $sql = 'SELECT c.id_carrier, c.name, c.active 
                FROM ' . _DB_PREFIX_ . 'carrier c 
                WHERE c.deleted = 0 AND c.active = 1
                ORDER BY c.name';
        
        $result = Db::getInstance()->executeS($sql);
        return $result ? $result : array();
    }

    private function getCarrierZones($carrier_id)
    {
        $sql = 'SELECT z.id_zone, z.name
                FROM ' . _DB_PREFIX_ . 'carrier_zone cz
                LEFT JOIN ' . _DB_PREFIX_ . 'zone z ON cz.id_zone = z.id_zone
                WHERE cz.id_carrier = ' . (int)$carrier_id;
        
        $result = Db::getInstance()->executeS($sql);
        return $result ? $result : array();
    }

    // HOOK PRINCIPAL CORREGIDO - Este es el que faltaba
    public function hookActionFilterDeliveryOptionList($params)
    {
        if (!Configuration::get('RESTRICT_SHIPPING_ENABLED')) {
            return;
        }

        $carriers_config = json_decode(Configuration::get('RESTRICT_SHIPPING_CARRIERS'), true);
        if (!$carriers_config) {
            return;
        }

        $current_day = (int)date('N'); // 1 = Lunes, 7 = Domingo
        $deliveryOptionList = &$params['delivery_option_list'];
        $restricted_carriers_for_address = array();

        foreach ($deliveryOptionList as $addressId => &$deliveryOptions) {
            // Obtener la zona de la dirección
            $address_zone = $this->getAddressZone($addressId);
            
            foreach ($deliveryOptions as $key => &$option) {
                if (isset($option['carrier_list'])) {
                    foreach ($option['carrier_list'] as $carrierId => $carrier) {
                        // Verificar si el transportista tiene restricciones
                        if (isset($carriers_config[$carrierId])) {
                            $allowedDays = $carriers_config[$carrierId]['days'];
                            
                            // Verificar si el transportista opera en esta zona
                            $carrier_operates_in_zone = $this->carrierOperatesInZone($carrierId, $address_zone);
                            
                            // Solo aplicar restricción si el transportista opera en esta zona
                            if ($carrier_operates_in_zone && !in_array($current_day, $allowedDays)) {
                                // Guardar info del transportista restringido para esta dirección
                                $days_names = array();
                                foreach ($allowedDays as $day) {
                                    $days_names[] = $this->getDayName($day);
                                }
                                $restricted_carriers_for_address[] = array(
                                    'name' => $carriers_config[$carrierId]['name'],
                                    'days' => implode(', ', $days_names)
                                );
                                
                                // Eliminar transportista
                                unset($option['carrier_list'][$carrierId]);
                            }
                        }
                    }
                    
                    // Eliminar opción de entrega si no quedan transportistas
                    if (empty($option['carrier_list'])) {
                        unset($deliveryOptions[$key]);
                    }
                }
            }
        }

        // Guardar información de transportistas restringidos para esta dirección
        if (!empty($restricted_carriers_for_address)) {
            $_SESSION['restricted_carriers_current'] = $restricted_carriers_for_address;
        } else {
            unset($_SESSION['restricted_carriers_current']);
        }
    }

    public function hookHeader($params)
    {
        $controller = $this->context->controller;
        $load_assets = false;
        
        if ($controller instanceof OrderController) {
            $load_assets = true;
        } elseif ($controller instanceof CartController) {
            $load_assets = true;
        } elseif (isset($controller->php_self) && strpos($controller->php_self, 'order') !== false) {
            $load_assets = true;
        }
        
        if (!$load_assets) {
            return '';
        }
        
        $this->context->controller->addJS($this->_path . 'views/js/front.js');
        $this->context->controller->addCSS($this->_path . 'views/css/front.css');

        $carriers_config = json_decode(Configuration::get('RESTRICT_SHIPPING_CARRIERS'), true);
        $enabled = Configuration::get('RESTRICT_SHIPPING_ENABLED');
        $message = Configuration::get('RESTRICT_SHIPPING_MESSAGE');

        $js_vars = array(
            'restrict_shipping_enabled' => (bool)$enabled,
            'restrict_shipping_carriers' => $carriers_config ? $carriers_config : array(),
            'restrict_shipping_message' => $message ? $message : 'Transportista no disponible',
            'current_day' => (int)date('N'),
            'days_names' => array(
                1 => 'Lunes',
                2 => 'Martes', 
                3 => 'Miércoles',
                4 => 'Jueves',
                5 => 'Viernes',
                6 => 'Sábado',
                7 => 'Domingo'
            ),
            'is_onepagecheckoutps' => Module::isInstalled('onepagecheckoutps') && Module::isEnabled('onepagecheckoutps')
        );

        $this->context->smarty->assign('restrict_shipping_vars', $js_vars);
        
        if (file_exists($this->local_path . 'views/templates/hook/header.tpl')) {
            return $this->context->smarty->fetch($this->local_path . 'views/templates/hook/header.tpl');
        }
        
        return '';
    }

    public function hookDisplayShoppingCartFooter($params)
    {
        // NO mostrar aquí para evitar duplicados en checkout
        return '';
    }

    public function hookDisplayProductAdditionalInfo($params)
    {
        // SÍ mostrar en producto - información crucial para el cliente
        if (!Configuration::get('RESTRICT_SHIPPING_ENABLED')) {
            return '';
        }

        $carriers_config = json_decode(Configuration::get('RESTRICT_SHIPPING_CARRIERS'), true);
        if (!$carriers_config) {
            return '';
        }

        $current_day = (int)date('N');
        $restricted_carriers = array();

        // Mostrar TODOS los transportistas con restricción hoy (sin filtrar por zona en producto)
        foreach ($carriers_config as $carrier_id => $config) {
            if (!in_array($current_day, $config['days'])) {
                $days_names = array();
                foreach ($config['days'] as $day) {
                    $days_names[] = $this->getDayName($day);
                }
                $restricted_carriers[] = array(
                    'name' => $config['name'],
                    'days' => implode(', ', $days_names)
                );
            }
        }

        if (!empty($restricted_carriers)) {
            $this->context->smarty->assign(array(
                'restricted_carriers' => $restricted_carriers
            ));
            
            if (file_exists($this->local_path . 'views/templates/hook/product_info.tpl')) {
                return $this->context->smarty->fetch($this->local_path . 'views/templates/hook/product_info.tpl');
            }
        }

        return '';
    }

    public function hookDisplayBeforeCarrier($params)
    {
        // SOLO mostrar el mensaje principal aquí - reemplaza el mensaje de PrestaShop
        if (isset($_SESSION['restricted_carriers_current']) && !empty($_SESSION['restricted_carriers_current'])) {
            $this->context->smarty->assign(array(
                'restricted_carriers' => $_SESSION['restricted_carriers_current']
            ));
            
            // Limpiar después de mostrar
            unset($_SESSION['restricted_carriers_current']);
            
            if (file_exists($this->local_path . 'views/templates/hook/no_carrier_available.tpl')) {
                return $this->context->smarty->fetch($this->local_path . 'views/templates/hook/no_carrier_available.tpl');
            }
        }
        return '';
    }

    public function hookDisplayAfterCarrier($params)
    {
        // También verificar aquí por si displayBeforeCarrier no funciona
        if (isset($_SESSION['restricted_carriers_current']) && !empty($_SESSION['restricted_carriers_current'])) {
            $this->context->smarty->assign(array(
                'restricted_carriers' => $_SESSION['restricted_carriers_current']
            ));
            
            // Limpiar después de mostrar
            unset($_SESSION['restricted_carriers_current']);
            
            if (file_exists($this->local_path . 'views/templates/hook/no_carrier_available.tpl')) {
                return $this->context->smarty->fetch($this->local_path . 'views/templates/hook/no_carrier_available.tpl');
            }
        }
        return '';
    }

    // MÉTODO ESPECÍFICO PARA ONEPAGECHECKOUTPS
    public function hookActionObjectCarrierUpdateAfter($params)
    {
        // Solo actuar si el carrito está seleccionando un transportista restringido
        $this->validateCurrentCarrierRestriction();
    }

    public function hookDisplayCarrierList($params)
    {
        if (!Configuration::get('RESTRICT_SHIPPING_ENABLED')) {
            return '';
        }

        // Solo mostrar mensaje si hay transportistas restringidos HOY para la zona actual
        $restricted_carriers = $this->getCurrentRestrictedCarriers();
        
        if (!empty($restricted_carriers)) {
            $this->context->smarty->assign(array(
                'restricted_carriers' => $restricted_carriers
            ));
            
            if (file_exists($this->local_path . 'views/templates/hook/no_carrier_available.tpl')) {
                return $this->context->smarty->fetch($this->local_path . 'views/templates/hook/no_carrier_available.tpl');
            }
        }
        return '';
    }

    private function getCurrentRestrictedCarriers()
    {
        $carriers_config = json_decode(Configuration::get('RESTRICT_SHIPPING_CARRIERS'), true);
        if (!$carriers_config) {
            return array();
        }

        $current_day = (int)date('N'); // 1 = Lunes, 7 = Domingo
        $restricted_carriers = array();
        
        // Obtener zona de la dirección de entrega actual
        $address_zone = null;
        if ($this->context->cart && $this->context->cart->id_address_delivery) {
            $address_zone = $this->getAddressZone($this->context->cart->id_address_delivery);
        }

        // Verificar cada transportista configurado
        foreach ($carriers_config as $carrier_id => $config) {
            $allowedDays = $config['days'];
            
            // Verificar si el transportista opera en esta zona
            $carrier_operates_in_zone = $this->carrierOperatesInZone($carrier_id, $address_zone);
            
            // Si el transportista opera en esta zona y NO está disponible hoy
            if ($carrier_operates_in_zone && !in_array($current_day, $allowedDays)) {
                $days_names = array();
                foreach ($allowedDays as $day) {
                    $days_names[] = $this->getDayName($day);
                }
                
                $restricted_carriers[] = array(
                    'name' => $config['name'],
                    'days' => implode(', ', $days_names)
                );
            }
        }

        return $restricted_carriers;
    }

    private function validateCurrentCarrierRestriction()
    {
        if (!Configuration::get('RESTRICT_SHIPPING_ENABLED')) {
            return;
        }

        if (!$this->context->cart || !$this->context->cart->id_carrier) {
            return;
        }

        $carriers_config = json_decode(Configuration::get('RESTRICT_SHIPPING_CARRIERS'), true);
        if (!$carriers_config) {
            return;
        }

        $current_day = (int)date('N');
        $current_carrier_id = $this->context->cart->id_carrier;

        // Verificar si el transportista actual está restringido
        if (isset($carriers_config[$current_carrier_id])) {
            $allowedDays = $carriers_config[$current_carrier_id]['days'];
            
            // Obtener zona de la dirección de entrega
            $address_zone = $this->getAddressZone($this->context->cart->id_address_delivery);
            $carrier_operates_in_zone = $this->carrierOperatesInZone($current_carrier_id, $address_zone);
            
            // Si el transportista opera en esta zona y NO está disponible hoy
            if ($carrier_operates_in_zone && !in_array($current_day, $allowedDays)) {
                // Resetear el transportista del carrito
                $this->context->cart->id_carrier = 0;
                $this->context->cart->delivery_option = '';
                $this->context->cart->update();
            }
        }
    }

    public function hookActionCarrierProcess($params)
    {
        if (!Configuration::get('RESTRICT_SHIPPING_ENABLED')) {
            return;
        }

        if (!isset($this->context->cart) || !$this->context->cart->id_carrier) {
            return;
        }

        $carrier_id = (int)$this->context->cart->id_carrier;
        if (!$this->isCarrierAllowed($carrier_id)) {
            $this->context->cart->id_carrier = 0;
            $this->context->cart->update();
        }
    }

    private function isCarrierAllowed($carrier_id)
    {
        $carriers_config = json_decode(Configuration::get('RESTRICT_SHIPPING_CARRIERS'), true);
        if (!$carriers_config || !isset($carriers_config[$carrier_id])) {
            return true;
        }

        $current_day = (int)date('N');
        return in_array($current_day, $carriers_config[$carrier_id]['days']);
    }

    private function getDayName($day_number)
    {
        $days = array(
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo'
        );
        return isset($days[(int)$day_number]) ? $days[(int)$day_number] : '';
    }

    /**
     * MÉTODO ESPECÍFICO PARA PROCESAR EL HTML DE ONEPAGECHECKOUTPS
     */
    public function filterOnePageCheckoutPSCarriers($html, $context)
    {
        if (!Configuration::get('RESTRICT_SHIPPING_ENABLED')) {
            return $html;
        }

        $carriers_config = json_decode(Configuration::get('RESTRICT_SHIPPING_CARRIERS'), true);
        if (!$carriers_config) {
            return $html;
        }

        $current_day = (int)date('N'); // 1 = Lunes, 7 = Domingo
        $restricted_carriers = array();

        // Verificar cada transportista configurado
        foreach ($carriers_config as $carrier_id => $config) {
            $allowedDays = $config['days'];
            
            // Obtener zona de la dirección de entrega
            $address_zone = $this->getAddressZone($context->cart->id_address_delivery);
            $carrier_operates_in_zone = $this->carrierOperatesInZone($carrier_id, $address_zone);
            
            // Si el transportista opera en esta zona y NO está disponible hoy
            if ($carrier_operates_in_zone && !in_array($current_day, $allowedDays)) {
                $days_names = array();
                foreach ($allowedDays as $day) {
                    $days_names[] = $this->getDayName($day);
                }
                
                $restricted_carriers[] = array(
                    'id' => $carrier_id,
                    'name' => $config['name'],
                    'days' => implode(', ', $days_names)
                );
                
                // OCULTAR transportista en el HTML usando CSS y JavaScript
                $html = str_replace(
                    'id_carrier=' . $carrier_id,
                    'id_carrier=' . $carrier_id . '" style="display: none !important;" class="restricted-carrier',
                    $html
                );
                
                // También ocultar por name
                $html = str_replace(
                    'value="' . $carrier_id . '"',
                    'value="' . $carrier_id . '" style="display: none !important;" class="restricted-carrier"',
                    $html
                );
            }
        }

        // Si hay transportistas restringidos, añadir el mensaje AL FINAL del HTML
        if (!empty($restricted_carriers)) {
            $context->smarty->assign(array(
                'restricted_carriers' => $restricted_carriers
            ));
            
            $module_instance = Module::getInstanceByName('restrictshipping');
            if ($module_instance && file_exists($module_instance->local_path . 'views/templates/hook/no_carrier_available.tpl')) {
                $message_html = $context->smarty->fetch($module_instance->local_path . 'views/templates/hook/no_carrier_available.tpl');
                
                // Añadir el mensaje ANTES del cierre del contenedor de transportistas
                $html = str_replace(
                    '</div><!-- fin transportistas -->',
                    $message_html . '</div><!-- fin transportistas -->',
                    $html
                );
                
                // Si no encuentra ese patrón, añadirlo al final
                if (strpos($html, $message_html) === false) {
                    $html .= $message_html;
                }
            }
        }

        return $html;
    }

    // NUEVOS MÉTODOS para verificar zonas
    private function getAddressZone($address_id)
    {
        if (!$address_id) {
            return null;
        }

        $sql = 'SELECT s.id_zone 
                FROM ' . _DB_PREFIX_ . 'address a
                LEFT JOIN ' . _DB_PREFIX_ . 'country c ON a.id_country = c.id_country
                LEFT JOIN ' . _DB_PREFIX_ . 'state s ON a.id_state = s.id_state
                WHERE a.id_address = ' . (int)$address_id;
        
        $result = Db::getInstance()->getRow($sql);
        
        if ($result && $result['id_zone']) {
            return (int)$result['id_zone'];
        }

        // Si no hay estado, obtener zona del país
        $sql = 'SELECT c.id_zone 
                FROM ' . _DB_PREFIX_ . 'address a
                LEFT JOIN ' . _DB_PREFIX_ . 'country c ON a.id_country = c.id_country
                WHERE a.id_address = ' . (int)$address_id;
        
        $result = Db::getInstance()->getRow($sql);
        return $result ? (int)$result['id_zone'] : null;
    }

    private function carrierOperatesInZone($carrier_id, $zone_id)
    {
        if (!$zone_id) {
            return true; // Si no hay zona, asumir que opera
        }

        $sql = 'SELECT COUNT(*) as count
                FROM ' . _DB_PREFIX_ . 'carrier_zone cz
                WHERE cz.id_carrier = ' . (int)$carrier_id . '
                AND cz.id_zone = ' . (int)$zone_id;
        
        $result = Db::getInstance()->getRow($sql);
        return $result && $result['count'] > 0;
    }
}