{**
 * 2026 BlockBee
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 *  @author BlockBee <info@blockbee.io>
 *  @copyright  2026 BlockBee
 *  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *}
<div class="tab-pane d-print-block fade show active" id="blockbeeTabContent" role="tabpanel" aria-labelledby="blockbeeTab">
    {if $payment_id}
        <div class="row" style="margin-bottom: 16px;">
            <div class="col-md-6">
                <strong>{l s='Payment ID' mod='blockbee'}:</strong>
                <p style="margin: 0; line-break: anywhere;">{$payment_id|escape:'htmlall':'UTF-8'}</p>
            </div>
            {if $created_at}
                <div class="col-md-6">
                    <strong>{l s='Created at' mod='blockbee'}:</strong>
                    <p style="margin: 0;">{$created_at|date_format:"%H:%M, %e %B, %Y"}</p>
                </div>
            {/if}
        </div>

        {if $payload && $payload|@count > 0}
            <h4>{l s='Latest webhook payload' mod='blockbee'}</h4>
            <ul style="list-style: none; padding: 0; margin: 0;">
                {foreach $payload as $key => $value}
                    <li style="margin-bottom: 8px;">
                        <strong>{$key|escape:'htmlall':'UTF-8'}:</strong>
                        <p style="margin: 0; line-break: anywhere;">
                            {if is_array($value)}
                                <code>{json_encode($value)|escape:'htmlall':'UTF-8'}</code>
                            {else}
                                {$value|escape:'htmlall':'UTF-8'}
                            {/if}
                        </p>
                    </li>
                {/foreach}
            </ul>
        {else}
            <p>{l s='No webhook received yet.' mod='blockbee'}</p>
        {/if}
    {else}
        <p>{l s='No BlockBee payment record for this order.' mod='blockbee'}</p>
    {/if}
</div>
