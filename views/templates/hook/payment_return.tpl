{*
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
 *}

{if $refunded}
  <div class="alert alert-info">
    {{l s='Your payment has been refunded. Please make sure, you send the payment on time in full amount including network fee. Please %scontact us%s if you need further assistance.' mod='btcpay'}|sprintf:"<a href=\"{$link->getPageLink('contact')}\">":'</a>' nofilter}
  </div>
{elseif $confirmed}
  {if $outofstock }
    <div class="alert alert-success">
      {l s='Thank you for your payment. Your order has been successfully completed. Unfortunately, the item(s) that you ordered are now out-of-stock.' mod='btcpay'}
    </div>
  {else}
    <div class="alert alert-success">
      {l s='Thank you for your payment. Your order has been successfully completed.' mod='btcpay'}
    </div>
  {/if}
{elseif $received}
  <div class="alert alert-success">
    {l s='Thank you for your payment. It might take several minutes for your payment to get validated by the network. You should receive a confirmation email shortly.' mod='btcpay'}
  </div>
{elseif $error}
  <div class="alert alert-danger">
    {{l s='There was a problem processing your order, please %scontact us%s.' mod='btcpay'}|sprintf:"<a href=\"{$link->getPageLink('contact')}\">":'</a>' nofilter}
  </div>
{else}
  <div class="alert alert-danger">
    {{l s='Unexpected error, please %scontact us%s.' mod='btcpay'}|sprintf:"<a href=\"{$link->getPageLink('contact')}\">":'</a>' nofilter}
  </div>
{/if}
