{assign var='back_link' value={$link->getPageLink('order', true, NULL, 'step=3')|escape:'html':'UTF-8'}}

{capture name=path}
  <a href="{$back_link|escape:'html':'UTF-8'}" title="{l s='Go back to the Checkout' mod='btcpay'}">{l s='Checkout' mod='btcpay'}</a><span class="navigation-pipe">{$navigationPipe}</span>{$heading}
{/capture}

<h1 class="page-heading">{$heading}</h1>

{assign var='current_step' value='payment'}
{include file="$tpl_dir./order-steps.tpl"}

<div class="alert alert-danger">
  {l s='An error occurred while attempting to create a new BTCPay payment.' mod='btcpay'}
</div>

<p>
  {{l s='The raw response data is displayed below. Please %sforward this%s to the site administrator so that they may rectify the issue.' mod='btcpay'}|sprintf:"<a href=\"{$link->getPageLink('contact')}\" target=\"_blank\">":'</a>'}
</p>
<pre>{$error}</pre>

<p class="cart_navigation clearfix" id="cart_navigation">
  <a href="{$back_link|escape:'html':'UTF-8'}" class="button-exclusive btn btn-default">
    <i class="icon-chevron-left"></i> {l s='Back to payment methods' mod='btcpay'}
  </a>
</p>