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
 * Controller for BTCPay callbacks.
 */
class BTCPayNotificationModuleFrontController extends ModuleFrontController
{
    /**
	 * @see FrontController::initContent()
	 */
    public function initContent()
    {
        parent::initContent();

        // get the callback data
        $callback = file_get_contents('php://input');
        // log all callbacks received for debugging purposes
        //$this->error("Callback received:", $callback, false);
        if (!$callback) {
            // the callback data is empty, just die without logging anything
            die;
        }

        $callbackData = json_decode($callback);
        
        // check that the callback has the reference data we need
        if (empty($callbackData->posData)) {
            $this->error("Reference data missing from callback.", $callback);
        }
        if (empty($callbackData->id)) {
            $this->error("Invoice ID missing from callback.", $callback);
        }
        $callbackReference = json_decode($callbackData->posData);
        if (empty($callbackReference->cart_id)) {
            $this->error("Cart ID missing from callback.", $callback);
        }
        if (empty($callbackReference->hash)) {
            $this->error("Hash missing from callback.", $callback);
        }
        if ($callbackReference->hash != crypt($callbackReference->cart_id, $this->module->getConfigValue('API_KEY'))) {
            $this->error("Callback hash validation failed.", $callback);
        }
        
        $invoice = $this->module->getPayment($callbackData->id);
        // log all invoices retrieved for debugging purposes
        //$this->error("Invoice retrieved:", json_encode($invoice), false);
        // check that the invoice has the data we need
        if (!$invoice || empty($invoice->data)) {
            $this->error("Invoice data missing.", json_encode($invoice));
            die;
        }

        $invoiceData = $invoice->data;
        
        // check that the callback has the reference data we need
        if (empty($invoiceData->posData)) {
            $this->error("Reference data missing from invoice.", json_encode($invoice));
        }
        if (empty($invoiceData->id)) {
            $this->error("Invoice ID missing from invoice.", json_encode($invoice));
        }
        $reference = json_decode($invoiceData->posData);
        if (empty($reference->cart_id)) {
            $this->error("Cart ID missing from invoice.", json_encode($invoice));
        }
        if (empty($reference->hash)) {
            $this->error("Hash missing from invoice.", json_encode($invoice));
        }
        if ($reference->hash != crypt($reference->cart_id, $this->module->getConfigValue('API_KEY'))) {
            $this->error("Invoice hash validation failed.", json_encode($invoice));
        }
        
        if ($callbackData->id != $invoiceData->id) {
            $this->error("Invoice ID and callback ID do not match.", $callback . PHP_EOL . json_encode($invoice));
        }
        if ($callbackData->posData != $invoiceData->posData) {
            $this->error("Invoice data and callback data do not match.", $callback . PHP_EOL . json_encode($invoice));
        }

        // check that the cart and currency are both valid
        $cart = new Cart((int)$reference->cart_id);
        if (!$invoiceData->currency || !$invoiceData->price) {
            $this->error("Invoice amount or invoice currency not set in invoice.", json_encode($invoice));
        }
        $currency = Currency::getCurrencyInstance((int)Currency::getIdByIsoCode($invoiceData->currency));
        if (!Validate::isLoadedObject($cart) || (!Validate::isLoadedObject($currency) || $currency->id != $cart->id_currency)) {
            $this->error("Cart or currency in invoice is invalid.", json_encode($invoice));
        }

        // check customer and secure key
        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->error("Customer not found or invalid.", json_encode($invoice));
        } elseif ($customer->secure_key != $reference->key) {
            $this->error("Secure key in invoice is invalid.", json_encode($invoice));
        }

        // set order status according to payment status
        switch ($invoiceData->status) {
            case 'confirmed':
            case 'complete':
                $orderStatus = (int)$this->module->getConfigValue('STATUS_CONFIRMED');
                break;
            case 'paid':
                $orderStatus = (int)$this->module->getConfigValue('STATUS_RECEIVED');
                break;
            case 'expired':
                if ($invoiceData->btcPaid > 0) {
                    $orderStatus = (int)$this->module->getConfigValue('STATUS_ERROR');
                } else {
                    // we do not want to invalidate the whole order on expired payment - allow the customer to repeat the payment
                    die;
                }
                break;
            case 'invalid':
                $orderStatus = (int)$this->module->getConfigValue('STATUS_ERROR');
                break;
            case 'new':
                // still waiting for payment
                die;
                break;
            default:
                // payment status is one we don't handle, so just stop processing
                $this->error("Unknown invoice status:", json_encode($invoice));
                die;
        }
        
        // check if this cart has already been converted into an order
        if ($cart->orderExists()) {
            $order = new Order((int)OrderCore::getOrderByCartId($cart->id));
            
            // if the order status is different from the current one, add order history
            if ($order->current_state != $orderStatus) {
                $orderHistory = new OrderHistory();
                $orderHistory->id_order = $order->id;
                $orderHistory->changeIdOrderState($orderStatus, $order, true);
                $orderHistory->addWithemail(true);
            }

            // attach new note for updated payment status
            $message = new Message();
            $message->message = $this->module->l('Updated Payment Status') . ': ' . $this->module->getStatusDesc($invoiceData->status, $invoiceData->exceptionStatus);
            $message->id_cart = $order->id_cart;
            $message->id_customer = $order->id_customer;
            $message->id_order = $order->id;
            $message->private = true;
            $message->add();
        } else {
            // create order
            $extra = array('transaction_id' => $invoiceData->id);
            $shop = !empty($reference->shop_id) ? new Shop((int)$reference->shop_id) : null;
            $payment_method = $this->module->l("BTCPay");
            // set payment method name to currency name if enabled
            $this->module->validateOrder($cart->id, $orderStatus, $invoiceData->price, $payment_method, null, $extra, null, false, $customer->secure_key, $shop);
            $order = new Order($this->module->currentOrder);
            
            // add BTCPay payment info to private order note for admin reference
            $messageLines = array(
                $this->module->l('Payment Status') . ': ' . $this->module->getStatusDesc($invoiceData->status, $invoiceData->exceptionStatus),
                $this->module->l('Payment ID') . ': ' . $invoiceData->id,
                $this->module->l('Invoice URL') . ': ' . $invoiceData->url,
                $this->module->l('Invoice URL') . ': ' . rtrim($this->module->getConfigValue('API_URL'), '/') . '/invoices/' . $invoiceData->id,
                $this->module->l('Cryptocurrencies Info') . ': '
            );
            
            foreach($invoiceData->cryptoInfo as $cryptoInfo) {
                $messageLines[] = $cryptoInfo->cryptoCode . ' - ' . $cryptoInfo->paymentType;
                $messageLines[] = '- ' . $this->module->l('Payment Address') . ': ' . $cryptoInfo->address;
                $messageLines[] = '- ' . $this->module->l('Requested Amount') . ': ' . $cryptoInfo->totalDue . ' ' . $cryptoInfo->cryptoCode;
                $messageLines[] = '- ' . $this->module->l('Paid Amount') . ': ' . $cryptoInfo->cryptoPaid . ' ' . $cryptoInfo->cryptoCode;
                $messageLines[] = '- ' . $this->module->l('Exchange rate') . ': ' . sprintf('%f', $cryptoInfo->rate) . ' ' . $invoiceData->currency . '/' . $cryptoInfo->cryptoCode;
            }

            $message = new Message();
            $message->message = implode(PHP_EOL . ' ', $messageLines);
            $message->id_order = $order->id;
            $message->id_cart = $order->id_cart;
            $message->id_customer = $order->id_customer;
            $message->private = true;
            $message->add();

            // add BTCPay invoice URL to customer order note if enabled
            if (!empty($invoiceData->url) && $this->module->getConfigValue('INVOICE_URL_MESSAGE')) {
                $customer_thread = new CustomerThread();
                $customer_thread->id_contact = 0;
                //$customer_thread->id_customer = 0;
                $customer_thread->id_order = (int)$order->id;
                $customer_thread->id_shop = !empty($shop) ? (int)$shop->id : null;
                $customer_thread->id_lang = (int)$this->context->language->id;
                //$customer_thread->email = $customer->email;
                $customer_thread->status = 'closed';
                $customer_thread->token = Tools::passwdGen(12);
                $customer_thread->add();
                $customer_message = new CustomerMessage();
                $customer_message->id_customer_thread = $customer_thread->id;
                $customer_message->id_employee = 0;
                $customer_message->message = $this->module->l('BTCPay Invoice URL') . ': ' . $invoiceData->url;
                $customer_message->private = 0;
                $customer_message->add();
            }
        }
        
        // we're done doing what we need to do, so make sure nothing else happens
        die;
    }

    /**
     * Writes an error message to /log/btcpay_errors.log and halts execution if not set otherwise.
     * 
     * @param string $message error message
     * @param string $dataString callback string
     * @param bool $die halts the whole exection after writing to log if true
     */
    public function error($message, $dataString = "", $die = true)
    {
        $entry = date('Y-m-d H:i:s P') . " -- " . $message;

        if ($dataString != "") {
            $entry .= PHP_EOL . $dataString;
        }

        error_log($entry . PHP_EOL, 3, _PS_ROOT_DIR_ . '/log/btcpay_errors.log');

        if ($die) {
            die;
        }
    }
}
