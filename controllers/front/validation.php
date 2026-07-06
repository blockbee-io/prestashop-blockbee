<?php
/**
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
 */

class BlockBeeValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;

        if ($cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
            || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === 'blockbee') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            exit($this->module->l('This payment method is not available.', 'validation'));
        }

        $expectedToken = blockbee::csrfToken((int) $cart->id, (int) $cart->id_customer);
        $givenToken = (string) Tools::getValue('bb_token');
        if (Tools::strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || $givenToken === ''
            || !hash_equals($expectedToken, $givenToken)) {
            PrestaShopLogger::addLog('[BlockBee] Checkout rejected: missing/invalid CSRF token', 2);
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $apiKey = Configuration::get('blockbee_api_key');
        if (empty($apiKey)) {
            PrestaShopLogger::addLog('[BlockBee] API key not configured at checkout time', 3);
            exit($this->module->l('Payment is currently unavailable. Please try again later.', 'validation'));
        }

        $currency = $this->context->currency;
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // Create the PrestaShop order up-front in the "waiting" state so we have an
        // id_order to embed in the BlockBee notify/redirect URLs. The state will be
        // moved to PS_OS_PAYMENT by the signed webhook.
        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get(blockbee::BLOCKBEE_WAITING),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );

        $orderId = (int) $this->module->currentOrder;
        if ($orderId <= 0) {
            PrestaShopLogger::addLog('[BlockBee] validateOrder did not produce a currentOrder', 3);
            exit($this->module->l('Could not create your order. Please try again.', 'validation'));
        }

        // Per-order unguessable secret. BlockBee echoes back every GET parameter
        // on the callback (even for POST webhooks), so the callback handler can
        // reject any webhook whose nonce it doesn't recognise — defence-in-depth
        // on top of the signature check. See https://docs.blockbee.io — "Harden
        // your callback with a secret".
        $nonce = blockbee::generateNonce(32);

        // Notify URL — order_id here is just a hint; the authoritative correlation
        // comes from payment_id inside the signed webhook body. The nonce is the
        // per-order secret verified in callback.php.
        $notifyUrl = $this->context->link->getModuleLink('blockbee', 'callback', [
            'order_id' => $orderId,
            'nonce' => $nonce,
        ], true);

        $redirectUrl = $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => $orderId,
            'key' => $customer->secure_key,
        ]);

        $params = [
            'redirect_url' => $redirectUrl,
            'notify_url' => $notifyUrl,
            'value' => number_format($total, 2, '.', ''),
            'currency' => strtolower($currency->iso_code),
            'item_description' => 'Order #' . $orderId,
            'post' => '1', // POST webhooks → signature covers the raw body.
        ];

        $response = BlockBeeHelper::checkout_request($params, $apiKey);

        if (!is_array($response) || ($response['status'] ?? null) !== 'success' || empty($response['payment_url']) || empty($response['payment_id'])) {
            PrestaShopLogger::addLog(
                '[BlockBee] checkout_request failed for order ' . $orderId . ' — '
                . (is_array($response) ? json_encode($response) : 'no response'),
                3
            );
            exit($this->module->l('Could not create a BlockBee payment. Please try again.', 'validation'));
        }

        blockbee::savePayment($orderId, (string) $response['payment_id'], [
            'payment_url' => $response['payment_url'],
            'created_at' => time(),
            'value' => $params['value'],
            'currency' => $params['currency'],
        ], $nonce);

        Tools::redirect($response['payment_url']);
    }
}
