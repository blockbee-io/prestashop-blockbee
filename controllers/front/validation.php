<?php

class BlockBeeValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        require_once _PS_MODULE_DIR_ . 'blockbee/lib/BlockBeeHelper.php';

        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'blockbee') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'validation'));
        }

        $selected = $_REQUEST['blockbee_coin'];
        if ($selected == 'none') {
            die($this->module->l('Please select a cryptocurrency.', 'validation'));
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        session_start();
        $fee = !empty($_SESSION['blockbee_fee']) ? $_SESSION['blockbee_fee'] : 0;

        $total = (float)$cart->getOrderTotal(true, Cart::BOTH) + $fee;
        $currency = $this->context->currency;

        $disableConversion = Configuration::get('disable_conversion') === '0' ? false : true;
        $info = BlockBeeHelper::get_info($selected);
        $minTx = floatval($info->minimum_transaction_coin);

        $cryptoTotal = BlockBeeHelper::sig_fig(BlockBeeHelper::get_conversion($currency->iso_code, $selected, $total, $disableConversion), 6);

        if ($cryptoTotal < $minTx) {
            die($this->module->l('Value too low, minimum is.', 'validation')) . $minTx;
        }

        $apiKey = Configuration::get('api_key');

        if (empty($apiKey)) {
            die($this->module->l('There\'s was an error with this payment. Please try again.', 'validation'));
        }

        // Actually create order in prestashop
        $this->module->validateOrder(
            (int)$cart->id,
            (int)Configuration::get('BLOCKBEE_WAITING'),
            $total,
            $this->module->displayName,
            NULL,
            [],
            (int)$currency->id,
            false,
            $customer->secure_key
        );

        $qrCodeSize = Configuration::get('qrcode_size');

        $nonce = blockbee::generateNonce();
        $orderId = $this->module->currentOrder;

        $callbackUrl = _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/blockbee/callback?order=' . $orderId . '&nonce=' . $nonce;

        $api = new BlockBeeHelper($selected, $apiKey, $callbackUrl, [], true);

        $addressIn = $api->get_address();

        if (empty($addressIn)) {
            die($this->module->l('There\'s was an error with this payment. Please try again.', 'validation'));
        }

        $qrCodeDataValue = $api->get_qrcode($cryptoTotal, $qrCodeSize);
        $qrCodeData = $api->get_qrcode('', $qrCodeSize);
        $paymentURL = _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/blockbee/success?order_id=' . $this->module->currentOrder;

        $paymentData = [
            'blockbee_nonce' => $nonce,
            'blockbee_address' => $addressIn,
            'blockbee_total' => $cryptoTotal,
            'blockbee_total_fiat' => $total,
            'blockbee_currency' => $selected,
            'blockbee_qr_code_value' => $qrCodeDataValue['qr_code'],
            'blockbee_qr_code' => $qrCodeData['qr_code'],
            'blockbee_last_price_update' => time(),
            'blockbee_min' => $minTx,
            'blockbee_fee' => $fee,
            'blockbee_order_created' => time(),
            'blockbee_history' => [],
            'blockbee_payment_url' => $paymentURL
        ];

        blockBee::addPaymentResponse($orderId, json_encode($paymentData));

        $this->context->smarty->assign([
            'params' => $_REQUEST,
        ]);

        blockbee::sendMail($orderId);

        Tools::redirectLink($paymentURL);
    }

    private function getSession()
    {
        return \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get('session');
    }
}
