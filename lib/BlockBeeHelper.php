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

if (!defined('_PS_VERSION_')) {
    exit;
}

class BlockBeeHelper
{
    private const BASE_URL = 'https://api.blockbee.io';
    private const HTTP_TIMEOUT = 30;
    // Pubkey is reused signature-after-signature; refresh at most once a day
    // unless verification fails (then we force-refresh and retry).
    private const PUBKEY_REFRESH_INTERVAL = 86400;

    /**
     * Create a hosted checkout payment on BlockBee.
     *
     * @param array  $params  Must include redirect_url, notify_url, value. Optional: currency, item_description, post, expire_at.
     * @param string $api_key Merchant API key.
     *
     * @return array Decoded JSON response. On error, an array shaped like ['status' => 'error', 'error' => '...'].
     */
    public static function checkout_request(array $params, $api_key)
    {
        if (empty($api_key)) {
            return ['status' => 'error', 'error' => 'Missing API key'];
        }

        $query = array_merge(['apikey' => $api_key], $params);
        $url = self::BASE_URL . '/checkout/request/?' . http_build_query($query);

        $raw = self::http_get($url);
        if ($raw === null) {
            return ['status' => 'error', 'error' => 'No response from BlockBee'];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['status' => 'error', 'error' => 'Invalid JSON response from BlockBee'];
        }

        return $data;
    }

    /**
     * Verify a BlockBee webhook signature.
     *
     * For POST webhooks the signed payload is the raw request body.
     * For GET webhooks the signed payload is the full reconstructed URL.
     *
     * If verification fails we refresh the cached public key once (to handle key
     * rotation) and retry before giving up.
     */
    public static function verify_signature($signed_data, $signature_b64)
    {
        if (!is_string($signed_data) || !is_string($signature_b64) || $signature_b64 === '') {
            return false;
        }

        $signature = base64_decode($signature_b64, true);
        if ($signature === false || $signature === '') {
            return false;
        }

        $pubkey = self::get_pubkey();
        if (!empty($pubkey) && self::openssl_verify_pem($signed_data, $signature, $pubkey) === 1) {
            return true;
        }

        // Cached key may be stale (rotated upstream). Force-refresh once.
        $pubkey = self::refresh_pubkey();
        if (empty($pubkey)) {
            return false;
        }

        return self::openssl_verify_pem($signed_data, $signature, $pubkey) === 1;
    }

    /**
     * Returns the cached BlockBee public key (PEM) if fresh, otherwise refreshes.
     */
    public static function get_pubkey()
    {
        $cached = Configuration::get('blockbee_pubkey');
        $cached_at = (int) Configuration::get('blockbee_pubkey_at');

        if (!empty($cached) && (time() - $cached_at) < self::PUBKEY_REFRESH_INTERVAL) {
            return $cached;
        }

        $fresh = self::refresh_pubkey();

        return $fresh !== null ? $fresh : ($cached !== false ? $cached : null);
    }

    /**
     * Force-fetch the public key from BlockBee and cache it.
     */
    public static function refresh_pubkey()
    {
        $raw = self::http_get(self::BASE_URL . '/pubkey/');
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['pubkey'])) {
            return null;
        }

        Configuration::updateValue('blockbee_pubkey', $data['pubkey']);
        Configuration::updateValue('blockbee_pubkey_at', time());

        return $data['pubkey'];
    }

    private static function openssl_verify_pem($data, $signature, $pubkey_pem)
    {
        $key = openssl_pkey_get_public($pubkey_pem);
        if ($key === false) {
            return -1;
        }

        return openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);
    }

    private static function http_get($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        // TLS verification stays on (defaults: SSL_VERIFYHOST=2, SSL_VERIFYPEER=1).

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $err !== '' || $code >= 400) {
            self::log(sprintf('HTTP GET failed (code=%d, err=%s) — %s', $code, $err, $url));

            return null;
        }

        return (string) $response;
    }

    private static function log($message)
    {
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog('[BlockBee] ' . $message, 2);
        }
    }
}
