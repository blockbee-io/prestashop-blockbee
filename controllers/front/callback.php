<?php
/**
 * 2022 BlockBee
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@blockbee.io so we can send you a copy immediately.
 *
 *  @author BlockBee <info@blockbee.io>
 *  @copyright  2022 BlockBee
 *  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
class BlockBeeCallbackModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        require_once _PS_MODULE_DIR_ . 'blockbee/lib/BlockBeeHelper.php';

        $callback = $_REQUEST;

        $orderId = (int) $callback['order'];

        $order = new Order($orderId);

        $metaData = json_decode(blockbee::getPaymentResponse($orderId), true);

        $paid = (int) $order->getCurrentState() === (int) Configuration::get('PS_OS_PAYMENT') ? true : false;

        if ($callback['coin'] !== $metaData['blockbee_currency']) {
            exit('*ok*');
        }

        if ($paid || (int) $order->getCurrentOrderState()->id === (int) Configuration::get('PS_OS_CANCELED') || $callback['nonce'] !== $metaData['blockbee_nonce']) {
            exit('*ok*');
        }

        $disableConversion = (int) Configuration::get('blockbee_disable_conversion') === 1 ? true : false;

        $qrCodeSize = Configuration::get('blockbee_qrcode_size');

        $apiKey = Configuration::get('blockbee_api_key');

        $paid = (float) $callback['value_coin'];

        $minTx = (float) $metaData['blockbee_min'];

        $historyDb = $metaData['blockbee_history'];

        if (empty($historyDb[$callback['uuid']])) {
            $fiat_conversion = BlockBeeHelper::get_conversion($metaData['blockbee_currency'], Currency::getDefaultCurrency()->iso_code, $paid, $disableConversion, $apiKey);

            $historyDb[$callback['uuid']] = [
                'timestamp' => time(),
                'value_paid' => BlockBeeHelper::sig_fig($paid, 6),
                'value_paid_fiat' => $fiat_conversion,
                'pending' => $callback['pending'],
            ];
        } else {
            $historyDb[$callback['uuid']]['pending'] = $callback['pending'];
        }

        blockbee::updatePaymentResponse($orderId, 'blockbee_history', $historyDb);

        $metaData = json_decode(blockbee::getPaymentResponse($orderId), true);

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
                $history->id_order = (int) $callback['order'];
                $history->changeIdOrderState((int) Configuration::get('PS_OS_PAYMENT'), $history->id_order, false);
                $history->addWithemail();
                $history->save();
            }

            exit('*ok*');
        }

        if ($remainingPending < $minTx) {
            blockbee::updatePaymentResponse($orderId, 'blockbee_qr_code_value', BlockBeeHelper::get_static_qrcode($metaData['blockbee_address'], $metaData['blockbee_currency'], $minTx, $apiKey, $qrCodeSize)['qr_code']);
        } else {
            blockbee::updatePaymentResponse($orderId, 'blockbee_qr_code_value', BlockBeeHelper::get_static_qrcode($metaData['blockbee_address'], $metaData['blockbee_currency'], $remainingPending, $apiKey, $qrCodeSize)['qr_code']);
        }

        exit('*ok*');
    }
}
