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
class BlockBeeCronjobModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $nonce = $_REQUEST['nonce'];
        $saved_nonce = Configuration::get('blockbee_cronjob_nonce');

        // In case the Nonce is missing from the url, it redirects to the home page and dies
        if ($nonce !== $saved_nonce) {
            Tools::redirect(_PS_BASE_URL_ . __PS_BASE_URI__);
        }

        blockbee::blockbeeCronjob();
        exit('*ok*');
    }
}
