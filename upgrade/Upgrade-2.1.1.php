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

/**
 * Add the per-order callback `nonce` column to blockbee_order for installs
 * created before the column existed (it was added to the CREATE TABLE, but
 * already-installed tables were never migrated). Idempotent: skips the ALTER if
 * the column is already present, so it is safe to re-run and portable across
 * MySQL/MariaDB.
 */
function upgrade_module_2_1_1($module)
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'blockbee_order';

    $exists = $db->getRow(
        "SELECT 1 AS x FROM information_schema.COLUMNS "
        . "WHERE table_schema = DATABASE() AND table_name = '" . pSQL($table) . "' "
        . "AND column_name = 'nonce'"
    );

    if (!empty($exists)) {
        return true;
    }

    if (!$db->execute('ALTER TABLE `' . $table . "` ADD COLUMN `nonce` VARCHAR(64) NOT NULL DEFAULT '' AFTER `payload`")) {
        PrestaShopLogger::addLog(
            '[BlockBee] upgrade 2.1.1: ALTER TABLE ADD COLUMN nonce failed on ' . $table,
            3
        );

        return false;
    }

    return true;
}
