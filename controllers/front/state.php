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
class BlockBeeStateModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        require_once _PS_MODULE_DIR_ . 'blockbee/lib/BlockBeeHelper.php';

        if (empty($_REQUEST['order_id'])) {
            exit($this->module->l('Order not found.', 'error', 'en'));
        }

        $orderId = $_REQUEST['order_id'];

        try {
            $metaData = json_decode(blockbee::getPaymentResponse($orderId), true);
            $historyDb = $metaData['blockbee_history'];
        } catch (Exception $e) {
            exit($this->module->l('Order not found.', 'error', 'en'));
        }

        $order = new Order((int) $orderId);

        $showMinFee = 0;

        $calc = blockbee::calcOrder($historyDb, $metaData['blockbee_total'], $metaData['blockbee_total_fiat']);

        $alreadyPaid = $calc['already_paid'];
        $alreadyPaidFiat = BlockBeeHelper::sig_fig($calc['already_paid_fiat'], 2);

        $min_tx = (float) $metaData['blockbee_min'];

        $remainingPending = $calc['remaining_pending'];
        $remainingFiat = BlockBeeHelper::sig_fig($calc['remaining_fiat'], 2);

        $blockbeePending = 0;

        $paid = (int) $order->getCurrentState() === (int) Configuration::get('PS_OS_PAYMENT') ? 1 : 0;

        if ($remainingPending <= 0 && !$paid) {
            $blockbeePending = 1;
        }

        $counterCalc = (int) $metaData['blockbee_last_price_update'] + (int) Configuration::get('blockbee_refresh_value_interval') - time();

        if ($counterCalc < 0 && !$paid) {
            blockbee::blockbeeCronjob();
        }

        if ($remainingPending <= $min_tx && $remainingPending > 0) {
            $remainingPending = $min_tx;
            $showMinFee = 1;
        }

        if ($paid) {
            $remainingFiat = 0;
            $remainingPending = 0;
        }

        $params = [
            'is_paid' => $paid,
            'is_pending' => $blockbeePending,
            'qr_code_value' => $metaData['blockbee_qr_code_value'],
            'canceled' => (int) $order->getCurrentOrderState()->id === (int) Configuration::get('PS_OS_CANCELED') ? 1 : 0,
            'coin' => strtoupper($metaData['blockbee_currency']),
            'show_min_fee' => $showMinFee,
            'order_history' => $historyDb,
            'counter' => (string) $counterCalc,
            'crypto_total' => (float) $metaData['blockbee_total'],
            'already_paid' => $alreadyPaid,
            'remaining' => $remainingPending,
            'fiat_remaining' => $remainingFiat,
            'already_paid_fiat' => $alreadyPaidFiat,
            'fiat_symbol' => Currency::getDefaultCurrency()->symbol,
        ];

        exit(json_encode($params));
    }
}
