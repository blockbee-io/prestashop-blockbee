<?php

class BlockBeeStateModuleFrontController extends ModuleFrontController
{

    public function initContent()
    {
        require_once _PS_MODULE_DIR_ . 'blockbee/lib/BlockBeeHelper.php';

        if (empty($_REQUEST['order_id'])) {
            die($this->module->l('Order not found.', 'error', 'en'));
        }

        $orderId = $_REQUEST['order_id'];

        try {
            $metaData = json_decode(blockbee::getPaymentResponse($orderId), true);
            $historyDb = $metaData['blockbee_history'];
        } catch (Exception $e) {
            die($this->module->l('Order not found.', 'error', 'en'));
        }

        $order = new Order((int)$orderId);

        $showMinFee = 0;

        $calc = blockbee::calcOrder($historyDb, $metaData['blockbee_total'], $metaData['blockbee_total_fiat']);



        $alreadyPaid = $calc['already_paid'];
        $alreadyPaidFiat = $calc['already_paid_fiat'];

        $min_tx = floatval($metaData['blockbee_min']);

        $remainingPending = $calc['remaining_pending'];
        $remainingFiat = $calc['remaining_fiat'];

        $blockbeePending = 0;


        $paid = $order->getCurrentState() === Configuration::get('PS_OS_PAYMENT') ? 1 : 0;

        if ($remainingPending <= 0 && !$paid) {
            $blockbeePending = 1;
        }

        $counterCalc = (int)$metaData['blockbee_last_price_update'] + (int)Configuration::get('refresh_value_interval') - time();


        if ($counterCalc < 0 && !$paid) {
            blockbee::blockbeeCronjob();
        }

        if ($remainingPending <= $min_tx && $remainingPending > 0) {
            $remainingPending = $min_tx;
            $showMinFee = 1;
        }

        if ($paid) {
            $remainingFiat = 0;
        }

        $params = array(
            'is_paid' => $paid,
            'is_pending' => $blockbeePending,
            'qr_code_value' => $metaData['blockbee_qr_code_value'],
            'canceled' => $order->getCurrentOrderState()->id === (int)Configuration::get('PS_OS_CANCELED') ? 1 : 0,
            'coin' => strtoupper($metaData['blockbee_currency']),
            'show_min_fee' => $showMinFee,
            'order_history' => $historyDb,
            'counter' => (string)$counterCalc,
            'crypto_total' => floatval($metaData['blockbee_total']),
            'already_paid' => $alreadyPaid,
            'remaining' => $remainingPending <= 0 ? 0 : $remainingPending,
            'fiat_remaining' => $remainingFiat <= 0 ? 0 : $remainingFiat,
            'already_paid_fiat' => floatval($alreadyPaidFiat) <= 0 ? 0 : floatval($alreadyPaidFiat),
            'fiat_symbol' => Currency::getDefaultCurrency()->symbol,
        );

        die(json_encode($params));
    }
}