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
            '[BlockBee] Webhook received: payment_id='
            . substr((string) ($payload['payment_id'] ?? ''), 0, 64)
            . ' is_paid=' . var_export($payload['is_paid'] ?? null, true)
            . ' status=' . substr((string) ($payload['status'] ?? ''), 0, 32),
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

        // Verify the per-order nonce we embedded in the notify URL at checkout.
        // BlockBee echoes it back as a GET parameter on every callback. Reject on
        // empty (no stored nonce) or mismatch — constant-time compare. This is a
        // second gate on top of the signature check so a guessed callback URL
        // alone can never drive an order (e.g. to spoof address_out/txid).
        $expectedNonce = isset($row['nonce']) ? (string) $row['nonce'] : '';
        $givenNonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';
        if ($expectedNonce === '' || !hash_equals($expectedNonce, $givenNonce)) {
            $this->reject('Nonce mismatch for order ' . $orderId);
        }

        $paidState = (int) Configuration::get('PS_OS_PAYMENT');
        $terminalStates = [
            $paidState,
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_REFUND'),
            (int) Configuration::get('PS_OS_ERROR'),
        ];

        $db = Db::getInstance();
        $db->execute('START TRANSACTION');

        $sendPaidEmail = false;

        try {
            // Take the row lock and re-read the stored payload under it.
            $lockedRows = $db->executeS(
                'SELECT `payload` FROM `' . _DB_PREFIX_ . 'blockbee_order` WHERE `id_order` = ' . $orderId . ' LIMIT 1 FOR UPDATE'
            );
            $lockedRow = !empty($lockedRows) ? $lockedRows[0] : null;
            $storedPayload = ($lockedRow && !empty($lockedRow['payload'])) ? json_decode($lockedRow['payload'], true) : [];
            if (!is_array($storedPayload)) {
                $storedPayload = [];
            }
            $processedTxid = isset($storedPayload['blockbee_processed_txid'])
                ? (string) $storedPayload['blockbee_processed_txid'] : '';

            // Persist the latest payload for the admin tab, but carry the
            // processed-txid marker forward so we never erase the dedupe record.
            $toSave = $payload;
            if ($processedTxid !== '') {
                $toSave['blockbee_processed_txid'] = $processedTxid;
            }

            $txid = isset($payload['txid']) ? (string) $payload['txid'] : '';

            // Authoritative state read under the lock.
            $order = new Order($orderId);
            $currentState = (int) $order->getCurrentState();

            if (in_array($currentState, $terminalStates, true)) {
                blockbee::savePayment($orderId, $paymentId, $toSave);
                $db->execute('COMMIT');
                PrestaShopLogger::addLog(
                    '[BlockBee] Order ' . $orderId . ' already in terminal state ' . $currentState . ', ack',
                    1
                );
                $this->ack();
            }

            // Idempotency: a webhook whose txid we already finalized is a no-op.
            if ($txid !== '' && $processedTxid !== '' && hash_equals($processedTxid, $txid)) {
                blockbee::savePayment($orderId, $paymentId, $toSave);
                $db->execute('COMMIT');
                PrestaShopLogger::addLog('[BlockBee] Order ' . $orderId . ' txid already processed, ack', 1);
                $this->ack();
            }

            if (!$this->isPaid($payload)) {
                blockbee::savePayment($orderId, $paymentId, $toSave);
                $db->execute('COMMIT');
                PrestaShopLogger::addLog(
                    '[BlockBee] Order ' . $orderId . ' webhook not paid yet (is_paid='
                    . var_export($payload['is_paid'] ?? null, true) . ', status='
                    . var_export($payload['status'] ?? null, true) . ')',
                    1
                );
                $this->ack();
            }

            // setCurrentState reliably persists to both ps_order_history and
            // ps_orders.current_state in one call. The customer e-mail is sent
            // after COMMIT (below) so SMTP latency never holds the lock.
            $changed = $order->setCurrentState($paidState, 0);
            $sendPaidEmail = true;

            PrestaShopLogger::addLog(
                '[BlockBee] Order ' . $orderId . ' state transition '
                . $currentState . ' → ' . $paidState . ' result: '
                . var_export($changed, true),
                1
            );

            // Annotate the existing OrderPayment row (from validateOrder) with
            // the txid + crypto coin instead of creating a duplicate row.
            if ($txid !== '') {
                $payments = $order->getOrderPaymentCollection();
                if (count($payments) > 0) {
                    $payment = $payments[0];
                    $payment->transaction_id = $txid;
                    $payment->payment_method = $this->module->displayName
                        . (!empty($payload['paid_coin']) ? ' (' . strtoupper((string) $payload['paid_coin']) . ')' : '');
                    $payment->save();
                }
                // Record the finalized txid so any later duplicate is a no-op.
                $toSave['blockbee_processed_txid'] = $txid;
            }

            blockbee::savePayment($orderId, $paymentId, $toSave);

            $db->execute('COMMIT');
        } catch (Exception $e) {
            $db->execute('ROLLBACK');
            PrestaShopLogger::addLog('[BlockBee] Callback handler exception: ' . $e->getMessage(), 3);
            throw $e;
        }

        // Send the "order paid" e-mail after COMMIT. setCurrentState above does
        // not itself e-mail; sendEmail (not addWithemail) mails the customer
        // without inserting a second, duplicate history row.
        if ($sendPaidEmail) {
            try {
                $emailHistory = new OrderHistory();
                $emailHistory->id_order = $orderId;
                $emailHistory->id_order_state = $paidState;
                $emailHistory->sendEmail($order);
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    '[BlockBee] Order ' . $orderId . ' paid but confirmation email failed: ' . $e->getMessage(),
                    2
                );
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
