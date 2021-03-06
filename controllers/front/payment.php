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

/**
 * Controller generating payment URLs and redirecting customers to the payment gateway.
 */
class BTCPayPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_column_left = false;

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;

        // if cart is empty then redirect to the home page
        if ($cart->nbProducts() <= 0) {
            Tools::redirect('index.php');
        }

        // if current currency isn't enabled for this method, then display error
        if (!$this->module->checkCurrency($cart)) {
            $this->displayError("Current currency not enabled for this payment method.");
        }

        // attempt to create a new BTCPay payment
        try {
            $response = $this->module->createPayment($cart);

            // if the response does not contain URL to the payment gateway, display error
            if (!empty($response->data) && !empty($response->data->url)) {
                Tools::redirect($response->data->url);
            } else {
                $this->displayError("Failed to retrieve BTCPay payment URL.");
            }
        } catch (Exception $e) {
            $this->displayError($e->getMessage());
        }
    }

    /**
     * Redirects to the error page and displays message to the customer.
     *
     * @param string $errorMessage error message to display to the customer
     */
    public function displayError($errorMessage)
    {
        // display payment request error page
        $heading = $this->module->l("BTCPay Error");
        if (isset($this->context->smarty->tpl_vars['meta_title'])) {
            $meta_title = $heading . ' - ' . $this->context->smarty->tpl_vars['meta_title']->value;
        } else {
            $meta_title = $heading;
        }

        $this->context->smarty->assign(array(
            'heading' => $heading,
            'meta_title' => $meta_title,
            'error' => $errorMessage,
            'hide_left_column' => true,
        ));

        if (version_compare(_PS_VERSION_, '1.7', '>=') === true) {
            $this->setTemplate('module:btcpay/views/templates/front/payment_error17.tpl');
        } else {
            $this->setTemplate('payment_error.tpl');
        }
    }
}
