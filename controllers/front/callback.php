<?php

class BlockBeeCallbackModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        require_once _PS_MODULE_DIR_ . 'blockbee/lib/BlockBeeHelper.php';

        $callback = $_REQUEST;

        $orderId = (int)$callback['order'];

        $order = new Order($orderId);

        $metaData = json_decode(blockBee::getPaymentResponse($orderId), true);

        $paid = $order->getCurrentState() === Configuration::get('PS_OS_PAYMENT') ? true : false;

        if ($paid || $order->getCurrentOrderState()->id === (int)Configuration::get('PS_OS_CANCELED') || $callback['nonce'] !== $metaData['blockbee_nonce']) {
            die("*ok*");
        }

        $disableConversion = Configuration::get('disable_conversion') === 1 ? true : false;

        $qrCodeSize = Configuration::get('qrcode_size');

        $apiKey = Configuration::get('api_key');

        $paid = floatval($callback['value_coin']);

        $minTx = floatval($metaData['blockbee_min']);

        $historyDb = $metaData['blockbee_history'];

        if (empty($historyDb[$callback['uuid']])) {
            $fiat_conversion = BlockBeeHelper::get_conversion($metaData['blockbee_currency'], Currency::getDefaultCurrency()->iso_code, $paid, $disableConversion);

            $historyDb[$callback['uuid']] = [
                'timestamp' => time(),
                'value_paid' => BlockBeeHelper::sig_fig($paid, 6),
                'value_paid_fiat' => $fiat_conversion,
                'pending' => $callback['pending']
            ];
        } else {
            $historyDb[$callback['uuid']]['pending'] = $callback['pending'];
        }

        blockbee::updatePaymentResponse($orderId, 'blockbee_history', $historyDb);

        $metaData = json_decode(blockBee::getPaymentResponse($orderId), true);

        $historyDb = $metaData['blockbee_history'];

        $order->addOrderPayment(
            '0',
            $this->module->displayName,
            $callback['coin'] . ': txid_in: ' . $callback['txid_in'],
        );

        $calc = blockbee::calcOrder($historyDb, $metaData['blockbee_total'], $metaData['blockbee_total_fiat']);

        $remaining = $calc['remaining'];
        $remainingPending = $calc['remaining_pending'];

        if ($remainingPending <= 0) {
            if ($remaining <= 0) {
                $history = new OrderHistory();
                $history->id_order = (int)$callback['order'];
                $history->changeIdOrderState((int)Configuration::get('PS_OS_PAYMENT'), $history->id_order, false);
                $history->addWithemail();
                $history->save();
            }
            die("*ok*");
        }

        if ($remainingPending < $minTx) {
            blockBee::updatePaymentResponse($orderId, 'blockbee_qr_code_value', BlockBeeHelper::get_static_qrcode($metaData['blockbee_address'], $metaData['blockbee_currency'], $minTx, $apiKey, $qrCodeSize)['qr_code']);
        } else {
            blockBee::updatePaymentResponse($orderId, 'blockbee_qr_code_value', BlockBeeHelper::get_static_qrcode($metaData['blockbee_address'], $metaData['blockbee_currency'], $remainingPending, $apiKey, $qrCodeSize)['qr_code']);
        }

        die("*ok*");
    }
}