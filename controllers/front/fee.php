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
class BlockBeeFeeModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        require_once _PS_MODULE_DIR_ . 'blockbee/lib/BlockBeeHelper.php';

        $totalFee = Configuration::get('blockbee_fee_order_percentage');

        if (empty($totalFee)) {
            exit(json_encode([
                'fee' => 0,
                'total' => 0,
            ]));
        }

        $objCart = new Cart($this->context->cart->id);

        $total = $objCart->getOrderTotal(true, Cart::BOTH);

        $feeOrder = $total * $totalFee;

        $selected = $_REQUEST['blockbee_coin'];
        if ($selected === 'none') {
            $this->context->cookie->blockbee_fee = round(BlockBeeHelper::sig_fig($feeOrder, 6), 2);
            exit(json_encode([
                'fee' => round(BlockBeeHelper::sig_fig($feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
                'total' => round(BlockBeeHelper::sig_fig($total + $feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
            ]));
        }

        $apiKey = Configuration::get('blockbee_api_key');

        if (!empty($selected) && $selected != 'none' && !empty(Configuration::get('blockbee_add_blockchain_fee'))) {
            $est = BlockBeeHelper::get_estimate($selected, $apiKey);

            $feeOrder += (float) $est->{Currency::getDefaultCurrency()->iso_code};
        }

        $this->context->cookie->blockbee_fee = round(BlockBeeHelper::sig_fig($feeOrder, 6), 2);

        exit(json_encode([
            'fee' => round(BlockBeeHelper::sig_fig($feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
            'total' => round(BlockBeeHelper::sig_fig($total + $feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
        ]));
    }
}
