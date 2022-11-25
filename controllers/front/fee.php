<?php

class BlockBeeFeeModuleFrontController extends ModuleFrontController
{

    public function initContent()
    {
        require_once _PS_MODULE_DIR_ . 'blockbee/lib/BlockBeeHelper.php';

        $totalFee = Configuration::get('fee_order_percentage');

        if (empty($totalFee)) die(json_encode([
            'fee' => 0,
            'total' => 0
        ]));

        $objCart = new Cart($this->context->cart->id);

        $total = $objCart->getOrderTotal(true, Cart::BOTH);

        $feeOrder = $total * $totalFee;

        $selected = $_REQUEST['blockbee_coin'];

        if ($selected === 'none') {
            session_start();
            $_SESSION['blockbee_fee'] = round(BlockBeeHelper::sig_fig($feeOrder, 6), 2);
            die(json_encode([
                'fee' => round(BlockBeeHelper::sig_fig($feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
                'total' => round(floatval(BlockBeeHelper::sig_fig($total + $feeOrder, 6)), 2) . ' ' . Currency::getDefaultCurrency()->symbol
            ]));
        }

        if (!empty($selected) && $selected != 'none' && !empty(Configuration::get('add_blockchain_fee'))) {
            $est = BlockBeeHelper::get_estimate($selected);

            $feeOrder += (float)$est->{Currency::getDefaultCurrency()->iso_code};
        }
        session_start();
        $_SESSION['blockbee_fee'] = round(BlockBeeHelper::sig_fig($feeOrder, 6), 2);


        die(json_encode([
            'fee' => round(BlockBeeHelper::sig_fig($feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
            'total' => round(floatval(BlockBeeHelper::sig_fig($total + $feeOrder, 6)), 2) . ' ' . Currency::getDefaultCurrency()->symbol
        ]));
    }
}