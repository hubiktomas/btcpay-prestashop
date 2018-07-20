<?php
/**
 * Copyright © 2018 Tomas Hubik <hubik.tomas@gmail.com>
 *
 * NOTICE OF LICENSE
 *
 * This file is part of BTCPay PrestaShop module.
 * 
 * BTCPay PrestaShop module is free software: you can redistribute it
 * and/or modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * BTCPay PrestaShop module is distributed in the hope that it will be
 * useful, but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this BTCPay
 * module to newer versions in the future. If you wish to customize this module
 * for your needs please refer to http://www.prestashop.com for more information.
 *
 *  @author Tomas Hubik <hubik.tomas@gmail.com>
 *  @copyright  2018 Tomas Hubik
 *  @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU General Public License (GPLv3)
 */

defined('_PS_VERSION_') or die;

/**
 * BTCPay main module class.
 */
class BTCPay extends PaymentModule
{
    protected $apiUrl;
    protected $apiKey;
    protected $defaultValues = array();

    /**
     * @see Module::__construct()
     */
    public function __construct()
    {
        $this->name = 'btcpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.2';
        $this->author = 'Tomas Hubik';
        $this->author_uri = 'https://github.com/hubiktomas';
        $this->ps_versions_compliancy = array('min' => '1.5', 'max' => '1.7');
        $this->controllers = array('payment', 'notification', 'return');

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->bootstrap = true;
        
        parent::__construct();

        $this->displayName = $this->l("BTCPay");
        $this->description = $this->l("Accept payments in cryptocurrencies through BTCPay Server.");

        if (!$this->getConfigValue('API_KEY') || !$this->getConfigValue('API_URL')) {
            $this->warning = $this->l("Account settings must be configured before using this module.");
        }
        
        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l("No currencies have been enabled for this module.");
        }
        
        if ($apiUrl = $this->getConfigValue('API_URL')) {
            $this->apiUrl = $apiUrl;
        }
        
        if ($apiKey = $this->getConfigValue('API_KEY')) {
            $this->apiKey = $apiKey;
        }
    }

    /**
     * @see Module::install()
     */
    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (
            !parent::install()
            // only for PrestaShop 1.6 and lower
            || (version_compare(_PS_VERSION_, '1.7', '<') && !$this->registerHook('payment'))
            // only for PrestaShop 1.7 and higher
            || (version_compare(_PS_VERSION_, '1.7', '>=') && !$this->registerHook('paymentOptions'))
            || !$this->registerHook('paymentReturn')
        ) {
            return false;
        }

        // create custom order statuses
        $this->createOrderStatus('PAYMENT_RECEIVED', "Payment received (unconfirmed)", array(
            'color' => '#FF8C00',
            'paid' => false,
        ));

        return true;
    }

    /**
     * @see Module::uninstall()
     */
    public function uninstall()
    {
        if (
            !parent::uninstall() ||
            !Configuration::deleteByName('BTCPAY_API_URL') ||
            !Configuration::deleteByName('BTCPAY_API_KEY') ||
            //!Configuration::deleteByName('BTCPAY_CALLBACK_PASSWORD') ||
            !Configuration::deleteByName('BTCPAY_CALLBACK_SSL') ||
            !Configuration::deleteByName('BTCPAY_INVOICE_URL_MESSAGE') ||
            !Configuration::deleteByName('BTCPAY_STATUS_RECEIVED') ||
            !Configuration::deleteByName('BTCPAY_STATUS_CONFIRMED') ||
            !Configuration::deleteByName('BTCPAY_STATUS_ERROR')/* ||
            !Configuration::deleteByName('BTCPAY_STATUS_REFUND')*/
        ) {
            return false;
        }

        // delete custom order statuses
        $this->deleteOrderStatus('PAYMENT_RECEIVED');

        return true;
    }

    /**
     * Handles the configuration page.
     *
     * @return string form html with eventual error/notification messages
     */
    public function getContent()
    {
        $output = "";

        // check if form has been submitted
        if (Tools::isSubmit('submit' . $this->name)) {
            $fieldValues = $this->getConfigFieldValues(false);

            // check api url
            if ($fieldValues['BTCPAY_API_URL'] == "") {
                $output .= $this->displayError($this->l("API URL is required."));
            }
            
            // check api key
            if ($fieldValues['BTCPAY_API_KEY'] == "") {
                $output .= $this->displayError($this->l("API Key is required."));
            }

            /*// check callback password
            if ($fieldValues['BTCPAY_CALLBACK_PASSWORD'] == "") {
                $output .= $this->displayError($this->l("Callback Password is required."));
            }*/

            // save only if there are no validation errors
            if ($output == "") {
                foreach ($fieldValues as $fieldName => $fieldValue) {
                    Configuration::updateValue($fieldName, $fieldValue);
                }

                $output .= $this->displayConfirmation($this->l("BTCPay settings saved."));
            }
        }
        return $output . $this->renderSettingsForm();
    }

    /**
     * Renders the settings form for the configuration page.
     * 
     * @return string form html
     */
    public function renderSettingsForm()
    {
        // get default language
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        // get all order statuses to use for order status options
        $orderStatuses = OrderState::getOrderStates((int)$this->context->cookie->id_lang);
        
        // form fields
        $formFields = array(
            array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l("BTCPay Settings"),
                        'icon' => 'icon-cog'
                    ),
                    'tabs' => array(
                        'general' => $this->l("General"),
                        'order_statuses' => $this->l("Order Statuses")
                    ),
                    'input' => array(
                        array(
                            'tab' => 'general',
                            'name' => 'BTCPAY_API_URL',
                            'type' => 'text',
                            'label' => $this->l("BTCPay URL"),
                            'desc' => $this->l("BTCPay URL is used to communicate with your payment gateway. Enter domain of your BTCPay Server."),
                            'required' => true
                        ),
                        array(
                            'tab' => 'general',
                            'name' => 'BTCPAY_API_KEY',
                            'type' => 'text',
                            'label' => $this->l("API Key"),
                            'desc' => $this->l("API key is used for backend authentication and you should keep it private. Use legacy key."),
                            'required' => true
                        ),
                        /*array(
                            'tab' => 'general',
                            'name' => 'BTCPAY_CALLBACK_PASSWORD',
                            'type' => 'text',
                            'label' => $this->l("Callback Password"),
                            'desc' => $this->l("Used as a data validation for stronger security. Callback password must be set under Settings > Security in your BTCPay account."),
                            'required' => true
                        ),*/
                        array(
                            'tab' => 'general',
                            'name' => 'BTCPAY_CALLBACK_SSL',
                            'type' => 'switch',
                            'label' => $this->l("Callback SSL"),
                            'desc' => $this->l("Allows SSL (HTTPS) to be used for payment callbacks sent to your server. Note that some SSL certificates may not work (such as self-signed certificates), so be sure to do a test payment if you enable this to verify that your server is able to receive callbacks successfully."),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l("Enable")
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l("Disable")
                                )
                            )
                        ),
                        array(
                            'tab' => 'general',
                            'name' => 'BTCPAY_INVOICE_URL_MESSAGE',
                            'type' => 'switch',
                            'label' => $this->l("Customer Message with Invoice URL"),
                            'desc' => $this->l("Creates new message with BTCPay invoice URL for every order so that customer can access it from order detail page. This setting will create new customer thread for every order."),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l("Enable")
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l("Disable")
                                )
                            )
                        ),
                        /*array(
                            'tab' => 'general',
                            'name' => 'BTCPAY_CURRENCY_IN_PAYMENT_METHOD',
                            'type' => 'switch',
                            'label' => $this->l("Currency Name in Payment Method"),
                            'desc' => $this->l("Use currency name as the order payment method. Payment method will be BTCPay for any currency if disabled."),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l("Enable")
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l("Disable")
                                )
                            )
                        ),
                        array(
                            'tab' => 'general',
                            'name' => 'BTCPAY_NOTIFY_EMAIL',
                            'type' => 'text',
                            'label' => $this->l("Notification Email"),
                            'desc' => $this->l("Email address to send payment status notifications to. Leave blank to disable.")
                        ),*/
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'BTCPAY_STATUS_CONFIRMED',
                            'type' => 'select',
                            'label' => $this->l("Payment Confirmed"),
                            'desc' => $this->l("The invoice is paid and has enough confirmations."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        ),
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'BTCPAY_STATUS_RECEIVED',
                            'type' => 'select',
                            'label' => $this->l("Payment Received"),
                            'desc' => $this->l("At least the required amount has been paid but a sufficient number of confirmations has not been received yet."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        ),
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'BTCPAY_STATUS_ERROR',
                            'type' => 'select',
                            'label' => $this->l("Payment Error"),
                            'desc' => $this->l("The invoice has not been paid in the required timeframe or amount."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        )/*,
                        array(
                            'tab' => 'order_statuses',
                            'name' => 'BTCPAY_STATUS_REFUND',
                            'type' => 'select',
                            'label' => $this->l("Payment Refund"),
                            'desc' => $this->l("The payment has been returned to the customer."),
                            'options' => array(
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            )
                        )*/
                    ),
                    'submit' => array(
                        'title' => $this->l("Save")
                    )
                )
            )
        );

        // set up form
        $helper = new HelperForm;

        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l("Save"),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules')
            ),
            'back' => array(
                'desc' => $this->l("Back to List"),
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules')
            )
        );
        
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm($formFields);
    }

    /**
     * Loads module config parameters values.
     *
     * @return array array of config values
     */
    public function getConfigFieldValues()
    {
        $configFieldValues = array(
            'BTCPAY_API_URL' => $this->getConfigValue('API_URL', true),
            'BTCPAY_API_KEY' => $this->getConfigValue('API_KEY', true),
            //'BTCPAY_CALLBACK_PASSWORD' => $this->getConfigValue('CALLBACK_PASSWORD', true),
            'BTCPAY_CALLBACK_SSL' => $this->getConfigValue('CALLBACK_SSL', true),
            'BTCPAY_INVOICE_URL_MESSAGE' => $this->getConfigValue('INVOICE_URL_MESSAGE', true),
            /*'BTCPAY_CURRENCY_IN_PAYMENT_METHOD' => $this->getConfigValue('CURRENCY_IN_PAYMENT_METHOD', true),
            'BTCPAY_NOTIFY_EMAIL' => $this->getConfigValue('NOTIFY_EMAIL', true),*/
            'BTCPAY_STATUS_CONFIRMED' => $this->getConfigValue('STATUS_CONFIRMED', true),
            'BTCPAY_STATUS_RECEIVED' => $this->getConfigValue('STATUS_RECEIVED', true),
            'BTCPAY_STATUS_ERROR' => $this->getConfigValue('STATUS_ERROR', true)/*,
            'BTCPAY_STATUS_REFUND' => $this->getConfigValue('STATUS_REFUND', true)*/
        );
        
        return $configFieldValues;
    }

    /**
     * Loads module default config parameters values.
     * 
     * @return array array of module default config values
     */
    public function getDefaultValues()
    {
        if (!$this->defaultValues) {
            $this->defaultValues = array(
                'BTCPAY_STATUS_CONFIRMED' => Configuration::get('PS_OS_PAYMENT'),
                'BTCPAY_STATUS_RECEIVED' => $this->getOrderStatus('PAYMENT_RECEIVED'),
                'BTCPAY_STATUS_ERROR' => Configuration::get('PS_OS_ERROR'),
                //'BTCPAY_STATUS_REFUND' => Configuration::get('PS_OS_REFUND')
            );
        }

        return $this->defaultValues;
    }

    /**
     * Reads configuration parameter value from the database or form POST data if required.
     * 
     * @param string $key name of the parameter without the prefix
     * @param bool $post whether to read the value from form POST data (true) or database (false)
     * 
     * @return config parameter value
     */
    public function getConfigValue($key, $post = false)
    {
        $name = 'BTCPAY_' . $key;
        $value = trim($post && isset($_POST[$name]) ? $_POST[$name] : Configuration::get($name));

        // use default value if empty
        if (!strlen($value)) {
            $defaultValues = $this->getDefaultValues();

            if (isset($defaultValues[$name])) {
                $value = $defaultValues[$name];
            }
        }

        return $value;
    }

    /**
     * Handles hook for payment options for PS < 1.7.
     */
    public function hookPayment($params)
    {
        if (!$this->active || !$this->apiKey || !$this->apiUrl || !$this->checkCurrency($params['cart'])) {
            return;
        }
        
        $this->smarty->assign(array(
            'payment_url' => $this->context->link->getModuleLink($this->name, 'payment', array(), Configuration::get('PS_SSL_ENABLED')),
            'button_image_url' => $this->_path . 'views/img/payment.png',
            'prestashop_15' => version_compare(_PS_VERSION_, '1.5', '>=') && version_compare(_PS_VERSION_, '1.6', '<'),
        ));

        return $this->display(__FILE__, 'payment.tpl');
    }
    
    /**
     * Handles hook for payment options for PS >= 1.7.
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->apiKey || !$this->apiUrl || !$this->checkCurrency($params['cart'])) {
            return;
        }

        $paymentButtons = array();
        $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $newOption->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with cryptocurrencies'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), Configuration::get('PS_SSL_ENABLED')))
            ->setAdditionalInformation($this->context->smarty->fetch('module:btcpay/views/templates/front/payment_infos.tpl'));
        $paymentButtons[] = $newOption;
        
        return $paymentButtons;
    }

    /**
     * Handles hook for return from the payment gateway.
     */
    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }
        
        if (version_compare(_PS_VERSION_, '1.7', '>=') === true) {
            $order     = $params['order'];
        } else {
            $order     = $params['objOrder'];
        }

        $id_order_states = Db::getInstance()->ExecuteS('
            SELECT `id_order_state`
            FROM `'._DB_PREFIX_.'order_history`
            WHERE `id_order` = '.$order->id.'
            ORDER BY `date_add` DESC, `id_order_history` DESC
        ');

        $outofstock = false;
        $confirmed = false;
        $received = false;
        $refunded = false;
        $error = false;
        foreach($id_order_states as $state) {
            if ($state['id_order_state'] == (int)Configuration::get('PS_OS_OUTOFSTOCK')) {
                $outofstock = true;
            }
            if ($state['id_order_state'] == $this->getConfigValue('STATUS_CONFIRMED')) {
                $confirmed = true;
            }
            if ($state['id_order_state'] == $this->getConfigValue('STATUS_RECEIVED')) {
                $received = true;
            }
            if ($state['id_order_state'] == $this->getConfigValue('STATUS_ERROR')) {
                $error = true;
            }
            /*if ($state['id_order_state'] == $this->getConfigValue('STATUS_REFUND')) {
                $refunded = true;
            }*/
        }

        $this->smarty->assign(array(
            'products' => $order->getProducts(),
            'confirmed' => $confirmed,
            'received' => $received,
            'refunded' => $refunded,
            'error' => $error,
            'outofstock' => $outofstock
        ));

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    /**
     * Translates status codes to human readable descriptions.
     *
     * @param string $status status code
     * @param bool $unhandledExceptions if there was an unhandledExceptions flag included in the invoice model
     *
     * @return string human readable status description
     */
    public function getStatusDesc($status, $exceptionStatus)
    {
        if ($status === 'new') {
            if ($exceptionStatus === 'paidPartial') {
                return $this->l("Active — Waiting for payment, some funds received.");
            } else {
                return $this->l("Active — Waiting for payment.");
            }
        } elseif ($status === 'expired') {
            if ($exceptionStatus === 'paidPartial') {
                return $this->l("Underpayment — Paid amount is lower than required.");
            } elseif ($exceptionStatus === 'paidLate') {
                return $this->l("Paid late — Payment has been received after the configurable required timeframe.");
            } else {
                return $this->l("Expired — Not paid in the configurable required timeframe.");
            }
        } elseif ($status === 'invalid') {
            return $this->l("Invalid — Invoice has received payment but was not confirmed in configurable required timeframe.");
        } elseif ($status === 'paid') {
            if ($exceptionStatus === 'paidOver') {
                return $this->l("Overpayment — Payment has been received but not confirmed yet, more funds than requested received.");
            } else {
                return $this->l("Confirming — Payment has been received but not confirmed yet.");
            }
        } elseif ($status === 'confirmed' || $status === 'complete') {
            if ($exceptionStatus === 'paidOver') {
                return $this->l("Overpayment — Payment is confirmed, more funds than requested received.");
            } else {
                return $this->l("Paid — Payment is confirmed.");
            }
        }
        
        return $status . ', exception: ' . $exceptionStatus;
    }

    /**
     * Requests a new BTCPay invoice.
     *
     * @param Cart $cart cart object to use for the payment request
     * @param array $requestData optional array of request data to override values retrieved from the order object
     *
     * @return array response data with new invoice
     * 
     * @throws UnexpectedValueException if no API key or URL has been set
     * @throws Exception if an unexpected API response was returned
     */
    public function createPayment($cart, $requestData = array())
    {
        if (!$this->apiUrl || !$this->apiKey) {
            throw new UnexpectedValueException("BTCPay API URL or Key has not been set.");
        }
        
        $customer = new Customer($cart->id_customer);

        // build request data
        $request = array(
            'fullNotifications' => true,
            'extendedNotifications' => true,
            'price' => $cart->getOrderTotal(),
            'currency' => Currency::getCurrencyInstance($cart->id_currency)->iso_code,
            'orderID' => $cart->id,
            'posData' => json_encode(array(
                'cart_id' => (string)$cart->id,
                'shop_id' => (string)$cart->id_shop,
                'hash' => crypt($cart->id, $this->apiKey),
                'key' => $customer->secure_key
            )),
            'buyerName' => $customer->firstname . " " . $customer->lastname,
            'buyerEmail' => $customer->email,
            'redirectURL' => $this->context->link->getModuleLink($this->name, 'return', array('cart_id' => $cart->id, 'key' => $customer->secure_key), true),
            'notificationURL' => $this->context->link->getModuleLink($this->name, 'notification', array('key' => $customer->secure_key), (bool)$this->getConfigValue('CALLBACK_SSL'))
        );

        // override default request data if set
        if ($requestData) {
            $request = array_merge_recursive($request, $requestData);
        }

        // request new payment
        return $this->apiRequest('invoices', $request);
    }
    
    /**
     * Requests a existing BTCPay invoice.
     *
     * @param String $id invoice ID
     *
     * @return array response data with the invoice
     * 
     * @throws UnexpectedValueException if no API key or URL has been set
     * @throws Exception if an unexpected API response was returned
     */
    public function getPayment($id)
    {
        if (!$this->apiUrl || !$this->apiKey) {
            throw new UnexpectedValueException("BTCPay API URL or Key has not been set.");
        }

        // request existing invoice
        return $this->apiRequest('invoices/' . $id);
    }

    /**
     * Makes a new API request to BTCPay.
     *
     * @param string $endpoint API endpoint URI segment.
     * @param array $request API request post data
     * @param bool $returnRaw return the raw response string
     *
     * @return stdClass response data after json_decode
     * 
     * @throws Exception
     */
    public function apiRequest($endpoint, $request = array(), $returnRaw = false)
    {
        $ch = curl_init();
        
        if ($request) {
            $postData = json_encode($request);
            $length = strlen($postData);
        } else {
            $length = 0;
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL => ltrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/'),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Content-Length: ' . $length,
                'Authorization: Basic ' . base64_encode($this->apiKey),
                'X-BTCPay-Plugin-Info: PrestaShop BTCPay '.$this->version,
            ),
            CURLINFO_HEADER_OUT => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_FRESH_CONNECT => 1
        ));

        // if $request is set then POST it, otherwise just GET it
        if ($request) {
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
            ));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        // return just raw response if required
        if ($returnRaw) {
            return $response;
        }

        if (trim($response)) {
            $data = json_decode($response);

            // check if the response contains information about errors
            if (isset($data->errors)) {
                $error = $data->errors;
            } elseif (isset($data->error)) {
                $error = $data->error;
            } else {
                return $data;
            }
        }

        // if the response contained information about error, then compile the whole error sting and throw new exception
        if (is_string($error)) {
            $error = "BTCPay: " . ($error ?: "Unknown API error.");
        } else {
            if (defined('JSON_PRETTY_PRINT')) {
                $error = json_encode($error, JSON_PRETTY_PRINT);
            } else {
                $error = json_encode($error);
            }
        }
        throw new Exception($error);
    }

    /**
     * Creates a custom order status for this module.
     * 
     * @param string $name new status name
     * @param string $label new status lables
     * @param array $options optional additional options
     * @param string $template optional template
     * @param string $icon status icon
     *
     * @return int|bool false on failure, new status ID if successful
     */
    public function createOrderStatus($name, $label, $options = array(), $template = null, $icon = 'status.gif')
    {
        $osName = 'BTCPAY_OS_' . strtoupper($name);

        if (!Configuration::get($osName)) {
            $os = new OrderState();
            $os->module_name = $this->name;

            // set label for each language
            $os->name = array();
            foreach (Language::getLanguages() as $language) {
                $os->name[$language['id_lang']] = $label;

                if ($template !== null) {
                    $os->template[$language['id_lang']] = $template;
                }
            }

            // set order status options
            foreach ($options as $optionName => $optionValue) {
                if (property_exists($os, $optionName)) {
                    $os->$optionName = $optionValue;
                }
            }

            if ($os->add()) {
                Configuration::updateValue($osName, (int)$os->id);

                // copy icon image to os folder
                if ($icon) {
                    @copy(__DIR__ . '/views/img/' . $icon, _PS_ROOT_DIR_ . '/img/os/' . $os->id . '.gif');
                }

                return (int)$os->id;
            } else {
                return false;
            }
        }
    }

    /**
     * Deletes custom order status for this module by name.
     * 
     * @param string $name status name
     */
    public function deleteOrderStatus($name)
    {
        $osName = 'BTCPAY_OS_' . strtoupper($name);

        if ($osId = Configuration::get($osName)) {
            $os = new OrderState($osId);
            $os->delete();

            Configuration::deleteByName($osName);

            @unlink(_PS_ROOT_DIR_ . '/img/os/' . $osId . '.gif');
        }
    }

    /**
     * Gets the custom order status ID by name.
     * 
     * @param string $name status name
     *
     * @return int|bool false on failure to retrieve, status ID if successful
     */
    public function getOrderStatus($name)
    {
        return (int)ConfigurationCore::get('BTCPAY_OS_' . strtoupper($name));
    }

    /**
     * Check that this payment method is enabled for the cart currency.
     *
     * @param Cart $cart cart object
     *
     * @return bool true if this payment method is enabled for the cart currency
     */
    public function checkCurrency($cart)
    {
        $currency_order = new Currency((int)($cart->id_currency));
        $currencies_module = $this->getCurrency((int)$cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }    
}
