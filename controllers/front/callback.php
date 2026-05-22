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

class BlockBeeCallbackModuleFrontController extends ModuleFrontController
{
    /** We render no template here — only `*ok*` or an HTTP error. */
    public $ssl = true;

    public function postProcess()
    {
        $raw = (string) file_get_contents('php://input');
        $signature = $this->getSignatureHeader();

        if ($signature === '' || $raw === '') {
            $this->reject('Missing signature or body');
        }

        if (!BlockBeeHelper::verify_signature($raw, $signature)) {
            $this->reject('Signature verification failed');
        }

        $payload = $_POST;
        if (empty($payload)) {
            parse_str($raw, $payload);
        }

        PrestaShopLogger::addLog(
            '[BlockBee] Webhook received: ' . json_encode($payload),
            1
        );

        $paymentId = isset($payload['payment_id']) ? (string) $payload['payment_id'] : '';
        if ($paymentId === '') {
            $this->reject('Missing payment_id in verified body');
        }

        $row = blockbee::findPaymentByPaymentId($paymentId);
        if ($row === null) {
            PrestaShopLogger::addLog('[BlockBee] Webhook for unknown payment_id ' . $paymentId, 2);
            $this->ack();
        }

        $orderId = (int) $row['id_order'];
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            PrestaShopLogger::addLog('[BlockBee] Webhook referenced missing order ' . $orderId, 3);
            $this->ack();
        }

        // Persist the latest payload regardless of state — useful for the admin tab.
        blockbee::savePayment($orderId, $paymentId, $payload);

        $currentState = (int) $order->getCurrentState();
        $paidState = (int) Configuration::get('PS_OS_PAYMENT');

        if ($currentState === $paidState) {
            PrestaShopLogger::addLog('[BlockBee] Order ' . $orderId . ' already in PAYMENT state, ack', 1);
            $this->ack();
        }

        $isPaid = $this->isPaid($payload);
        if (!$isPaid) {
            PrestaShopLogger::addLog(
                '[BlockBee] Order ' . $orderId . ' webhook not paid yet (is_paid='
                . var_export($payload['is_paid'] ?? null, true) . ', status='
                . var_export($payload['status'] ?? null, true) . ')',
                1
            );
            $this->ack();
        }

        // setCurrentState is the simplest reliable transition — it persists to
        // both ps_order_history and ps_orders.current_state in one call. The
        // OrderHistory dance with changeIdOrderState() + addWithemail() can
        // silently leave ps_orders.current_state unchanged in some setups.
        $changed = $order->setCurrentState($paidState, 0);

        PrestaShopLogger::addLog(
            '[BlockBee] Order ' . $orderId . ' state transition '
            . $currentState . ' → ' . $paidState . ' result: '
            . var_export($changed, true),
            1
        );

        // Send the "order paid" e-mail to the customer (setCurrentState alone
        // doesn't trigger it). Look up the freshly-created history row.
        $history = OrderHistory::getLastOrderState($orderId);
        if (Validate::isLoadedObject($history)) {
            $orderHistoryRow = new OrderHistory();
            $orderHistoryRow->id_order = $orderId;
            $orderHistoryRow->id_order_state = $paidState;
            $orderHistoryRow->id_employee = 0;
            $orderHistoryRow->addWithemail();
        }

        // Annotate the existing OrderPayment row (from validateOrder) with
        // the txid + crypto coin instead of creating a duplicate row.
        if (!empty($payload['txid'])) {
            $payments = $order->getOrderPaymentCollection();
            if (count($payments) > 0) {
                $payment = $payments[0];
                $payment->transaction_id = (string) $payload['txid'];
                $payment->payment_method = $this->module->displayName
                    . (!empty($payload['paid_coin']) ? ' (' . strtoupper((string) $payload['paid_coin']) . ')' : '');
                $payment->save();
            }
        }

        $this->ack();
    }

    private function isPaid(array $payload)
    {
        if (isset($payload['is_paid'])) {
            $v = $payload['is_paid'];
            if ($v === true || $v === 1 || $v === '1' || strtolower((string) $v) === 'true') {
                return true;
            }
        }
        if (isset($payload['status'])) {
            $s = strtolower((string) $payload['status']);
            if (in_array($s, ['done', 'paid', 'success', 'completed', 'confirmed'], true)) {
                return true;
            }
        }

        return false;
    }

    private function getSignatureHeader()
    {
        $candidates = ['HTTP_X_CA_SIGNATURE', 'HTTP_X-CA-SIGNATURE'];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                return trim((string) $_SERVER[$key]);
            }
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'x-ca-signature') === 0) {
                    return trim((string) $value);
                }
            }
        }

        return '';
    }

    private function reject($reason)
    {
        PrestaShopLogger::addLog('[BlockBee] Webhook rejected: ' . $reason, 3);
        header('HTTP/1.1 401 Unauthorized');
        exit('unauthorized');
    }

    private function ack()
    {
        exit('*ok*');
    }
}
