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
class BlockBeeSuccessModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $orderId = $_REQUEST['order_id'];
        try {
            $metaData = json_decode(blockbee::getPaymentResponse($orderId), true);
        } catch (Exception $e) {
            exit($this->module->l('Order not found.', 'success', 'en'));
        }

        $order = new Order((int) $orderId);

        $refresh_value_interval = (int) Configuration::get('blockbee_refresh_value_interval');
        $order_cancelation_timeout = (int) Configuration::get('blockbee_order_cancelation_timeout');

        $allowed_to_value = [
            'btc',
            'eth',
            'bch',
            'ltc',
            'miota',
            'xmr',
        ];

        $crypto_allowed_value = false;

        $conversion_timer = ((int) $metaData['blockbee_last_price_update'] + $refresh_value_interval) - time();
        $date_conversion_timer = date('i:s', $conversion_timer);
        $cancel_timer = $metaData['blockbee_order_created'] + $order_cancelation_timeout - time();

        if (in_array($metaData['blockbee_currency'], $allowed_to_value, true)) {
            $crypto_allowed_value = true;
        }

        $this->context->smarty->assign([
            'order_id' => $orderId,
            'color_scheme' => Configuration::get('blockbee_color_scheme'),
            'currency_symbol' => $this->context->currency->iso_code,
            'total' => BlockBeeHelper::sig_fig($metaData['blockbee_total_fiat'], 2),
            'qrcode_size' => (int) Configuration::get('blockbee_qrcode_size') + 20,
            'qrcode_default' => (string) Configuration::get('blockbee_qrcode_default') === '0' ? false : true,
            'show_branding' => (string) Configuration::get('blockbee_show_branding') === '0' ? false : true,
            'address_in' => $metaData['blockbee_address'],
            'crypto_value' => $metaData['blockbee_total'],
            'crypto_coin' => strtoupper($metaData['blockbee_currency']),
            'qr_code_img_value' => $metaData['blockbee_qr_code_value'],
            'qr_code_img' => $metaData['blockbee_qr_code'],
            'qr_code_setting' => Configuration::get('blockbee_qrcode_setting'),
            'canceled' => (int) $order->getCurrentOrderState()->id !== (int) Configuration::get('PS_OS_CANCELED') ? 1 : 0,
            'module_dir' => Media::getMediaPath(_PS_MODULE_DIR_ . 'blockbee/'),
            'conversion_timer' => $conversion_timer,
            'date_conversion_timer' => $date_conversion_timer,
            'cancel_timer' => $cancel_timer,
            'crypto_allowed_value' => $crypto_allowed_value,
            'min_tx' => $metaData['blockbee_min'] . ' ' . strtoupper($metaData['blockbee_currency']),
            'refresh_value_interval' => $refresh_value_interval,
            'order_cancelation_timeout' => $order_cancelation_timeout,
        ]);

        $this->setTemplate('module:blockbee/views/templates/front/payment_success.tpl');
    }

    public function setMedia()
    {
        $order_id = $_REQUEST['order_id'];

        $adminajax_link = $this->context->link->getModuleLink('blockbee', 'state');

        Media::addJsDef([
            'adminajax_link' => $adminajax_link,
            'order_id' => $order_id,
        ]);

        $this->context->controller->registerStylesheet(
            'blockbee',
            'modules/' . $this->module->name . '/views/css/blockbee.css',
            [
                'position' => 'top',
                'priority' => 150,
            ]
        );

        $this->context->controller->registerJavascript(
            'blockbee',
            'modules/' . $this->module->name . '/views/js/blockbee.js',
            [
                'position' => 'bottom',
                'priority' => 150,
            ]
        );

        return parent::setMedia();
    }
}
