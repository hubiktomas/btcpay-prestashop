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
 * Controller taking care of situation when customer returns from the payment gateway.
 */
class BTCPayReturnModuleFrontController extends ModuleFrontController
{
    /**
	 * @see FrontController::initContent()
	 */
    public function initContent()
    {
        parent::initContent();

		$cart_id = (int)Tools::getValue('cart_id');
		$secure_key = Tools::getValue('key');

		$cart = new Cart($cart_id);
		$customer = new Customer($cart->id_customer);

		// first verify the secure key
		if (!$cart_id || !$secure_key || $customer->secure_key != $secure_key) {
			Tools::redirect('index.php');
			return;
		}

		// check if order has been created yet (via the callback)
		if ($cart->orderExists()) {
			// order has been created, so redirect to order confirmation page
			Tools::redirectLink($this->context->link->getPageLink('order-confirmation', true, null, array(
				'id_cart' => $cart_id,
				'id_module' => $this->module->id,
				'key' => $secure_key,
			)));
		} else {
			// redirect to order payment page so customer can try another payment method
			Tools::redirect('index.php?controller=order?step=3');
		}
    }
}
