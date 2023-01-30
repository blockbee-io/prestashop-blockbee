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

        $totalFee = (float) Configuration::get('blockbee_fee_order_percentage');
        $blockchainFee = Configuration::get('blockbee_add_blockchain_fee');

        if (empty($totalFee) && $blockchainFee !== '1') {
            $this->context->cookie->blockbee_fee = '';
            exit(json_encode([
                'fee' => 0,
                'total' => 0,
                'simbCurrency' => Currency::getDefaultCurrency()->symbol,
            ]));
        }

        $objCart = new Cart($this->context->cart->id);

        $total = $objCart->getOrderTotal(true, Cart::BOTH);

        $feeOrder = '0';

        $selected = (string) $_REQUEST['blockbee_coin'];

        if ($selected === 'none') {
            $this->context->cookie->blockbee_fee = '';
            exit(json_encode([
                'fee' => 0,
                'total' => 0,
                'simbCurrency' => Currency::getDefaultCurrency()->symbol,
            ]));
        }

        if (!empty($totalFee)) {
            $feeOrder = bcmul((string) $total, (string) $totalFee, 2);
        }

        $apiKey = Configuration::get('blockbee_api_key');

        if ($blockchainFee === '1') {
            $est = BlockBeeHelper::get_estimate($selected, $apiKey);
            $feeOrder = bcadd($feeOrder, (string) $est->{Currency::getDefaultCurrency()->iso_code}, 2);
        }

        $this->context->cookie->blockbee_fee = BlockBeeHelper::sig_fig($feeOrder, 2);

        exit(json_encode([
            'fee' => (float) BlockBeeHelper::sig_fig($feeOrder, 2),
            'total' => bcadd((string) $total, $feeOrder, 2),
            'simbCurrency' => Currency::getDefaultCurrency()->symbol,
        ]));
    }
}
