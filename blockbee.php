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

require_once __DIR__ . '/lib/BlockBeeHelper.php';

class blockbee extends PaymentModule
{
    public const BLOCKBEE_WAITING = 'BLOCKBEE_WAITING';

    private const CONFIG_KEYS_KEEP = [
        'blockbee_active',
        'blockbee_api_key',
        'blockbee_checkout_title',
        'blockbee_pubkey',
        'blockbee_pubkey_at',
        self::BLOCKBEE_WAITING,
    ];

    // Configuration keys that existed in 1.x but are no longer used in 2.x.
    private const CONFIG_KEYS_LEGACY = [
        'blockbee_show_branding',
        'blockbee_add_blockchain_fee',
        'blockbee_fee_order_percentage',
        'blockbee_qrcode_default',
        'blockbee_qrcode_setting',
        'blockbee_color_scheme',
        'blockbee_disable_conversion',
        'blockbee_qrcode_size',
        'blockbee_coins_cache',
        'blockbee_refresh_value_interval',
        'blockbee_order_cancelation_timeout',
        'blockbee_cronjob_nonce',
    ];

    public function __construct()
    {
        $this->name = 'blockbee';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '9.99.99'];
        $this->author = 'BlockBee';
        $this->controllers = ['validation', 'callback'];
        $this->module_key = '47cc969f06561eca622b85d1df87b189';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('BlockBee Payment Gateway');
        $this->description = $this->l('Accept cryptocurrency payments via BlockBee Checkout.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall? Stored payment metadata will be deleted.');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
    }

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('paymentReturn')
            || !$this->registerHook('displayAdminOrderTabOrder')
            || !$this->registerHook('displayAdminOrderTabContent')
            || !$this->registerHook('displayAdminOrderTabLink')) {
            return false;
        }

        if (!$this->addOrderState()) {
            return false;
        }

        $this->cleanLegacy();

        $db = Db::getInstance();

        // Schema for the new metadata store. Drop any 1.x table first since the
        // shape changed and the old rows are no longer usable.
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'blockbee_order`');
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'blockbee_coins`');

        $created = $db->execute(
            'CREATE TABLE `' . _DB_PREFIX_ . 'blockbee_order` (
                `id_order` INT UNSIGNED NOT NULL,
                `payment_id` VARCHAR(64) NOT NULL,
                `payload` LONGTEXT NULL,
                `created_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`id_order`),
                UNIQUE KEY `payment_id` (`payment_id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );

        if (!$created) {
            return false;
        }

        // Leave blockbee_checkout_title empty by default — the display sites
        // fall back to $this->l('Pay with Cryptocurrency') so the customer
        // sees a translated label until the merchant sets a custom title.
        if (!Configuration::hasKey('blockbee_active')) {
            Configuration::updateValue('blockbee_active', 0);
        }

        return true;
    }

    public function uninstall()
    {
        $db = Db::getInstance();
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'blockbee_order`');
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'blockbee_coins`');

        foreach (array_merge(self::CONFIG_KEYS_KEEP, self::CONFIG_KEYS_LEGACY) as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * Remove the no-longer-used Configuration keys from a 1.x install.
     */
    private function cleanLegacy()
    {
        foreach (self::CONFIG_KEYS_LEGACY as $key) {
            Configuration::deleteByName($key);
        }
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            // CSRF is handled by PrestaShop's AdminController before we get here
            // (it validates the admin token / Symfony _token field on PS 8/9).
            $active = (int) Tools::getValue('blockbee_active');
            $title = trim((string) Tools::getValue('blockbee_checkout_title'));
            $apiKey = trim((string) Tools::getValue('blockbee_api_key'));

            if ($title === '' || !Validate::isGenericName($title)) {
                $output .= $this->displayError($this->l('Please enter a valid checkout title.'));
            } elseif ($apiKey === '') {
                $output .= $this->displayError($this->l('Please enter your BlockBee API key.'));
            } else {
                Configuration::updateValue('blockbee_active', $active);
                Configuration::updateValue('blockbee_checkout_title', $title);
                Configuration::updateValue('blockbee_api_key', $apiKey);

                // Warm the pubkey cache on save so the first webhook doesn't pay
                // the round-trip cost.
                BlockBeeHelper::refresh_pubkey();

                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            }
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enabled'),
                        'name' => 'blockbee_active',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Checkout title'),
                        'name' => 'blockbee_checkout_title',
                        'desc' => $this->l('Shown to the customer as the payment-option label.'),
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API key'),
                        'name' => 'blockbee_api_key',
                        'size' => 64,
                        'required' => true,
                        'desc' => sprintf(
                            $this->l('Get your API key at %s.'),
                            '<a href="https://dash.blockbee.io/" target="_blank" rel="noopener">dash.blockbee.io</a>'
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->table = $this->table;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&' . http_build_query(['configure' => $this->name]);
        $helper->submit_action = 'submit' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');

        $helper->fields_value = [
            'blockbee_active' => (int) Tools::getValue('blockbee_active', Configuration::get('blockbee_active')),
            'blockbee_checkout_title' => Tools::getValue(
                'blockbee_checkout_title',
                Configuration::get('blockbee_checkout_title') ?: $this->l('Pay with Cryptocurrency')
            ),
            'blockbee_api_key' => Tools::getValue('blockbee_api_key', Configuration::get('blockbee_api_key')),
        ];

        return $helper->generateForm([$form]);
    }

    public function hookPaymentOptions($params)
    {
        if (!Configuration::get('blockbee_active') || !Configuration::get('blockbee_api_key')) {
            return [];
        }

        if (!$this->checkCurrency($params['cart'])) {
            return [];
        }

        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->name, 'validation', [], true),
        ]);

        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText(Configuration::get('blockbee_checkout_title') ?: $this->l('Pay with Cryptocurrency'))
            ->setForm($this->context->smarty->fetch('module:blockbee/views/templates/front/payment_form.tpl'))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/blockbee_checkout.png'));

        return [$option];
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    public function addOrderState()
    {
        if (Configuration::get(self::BLOCKBEE_WAITING)
            && Validate::isLoadedObject(new OrderState((int) Configuration::get(self::BLOCKBEE_WAITING)))) {
            return true;
        }

        $order_state = new OrderState();
        $order_state->name = [];
        foreach (Language::getLanguages() as $language) {
            $order_state->name[$language['id_lang']] = $this->l('Waiting for BlockBee payment');
        }
        $order_state->invoice = false;
        $order_state->send_email = false;
        $order_state->logable = false;
        $order_state->color = '#258ecd';
        $order_state->module_name = $this->name;

        if (!$order_state->add()) {
            return false;
        }

        $source = __DIR__ . '/views/img/blockbee_payment.png';
        $destination = _PS_ROOT_DIR_ . '/img/os/' . (int) $order_state->id . '.png';
        if (is_readable($source)) {
            @copy($source, $destination);
        }

        Configuration::updateValue(self::BLOCKBEE_WAITING, (int) $order_state->id);

        return true;
    }

    /**
     * Persist (or update) the BlockBee payment metadata for a PrestaShop order.
     */
    public static function savePayment($id_order, $payment_id, array $payload)
    {
        $db = Db::getInstance();
        $payload_json = pSQL(json_encode($payload), true);
        $payment_id = pSQL($payment_id);
        $id_order = (int) $id_order;
        $now = time();

        $exists = $db->getRow(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'blockbee_order` WHERE `id_order` = ' . $id_order
        );

        if (empty($exists)) {
            $db->execute(
                'INSERT INTO `' . _DB_PREFIX_ . "blockbee_order` (`id_order`, `payment_id`, `payload`, `created_at`)
                 VALUES (" . $id_order . ", '" . $payment_id . "', '" . $payload_json . "', " . $now . ')'
            );
        } else {
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . "blockbee_order` SET `payment_id` = '" . $payment_id . "',
                 `payload` = '" . $payload_json . "' WHERE `id_order` = " . $id_order
            );
        }
    }

    public static function findPayment($id_order)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'blockbee_order` WHERE `id_order` = ' . (int) $id_order
        );

        return is_array($row) && !empty($row) ? $row : null;
    }

    public static function findPaymentByPaymentId($payment_id)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . "blockbee_order` WHERE `payment_id` = '" . pSQL($payment_id) . "'"
        );

        return is_array($row) && !empty($row) ? $row : null;
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $this->context->smarty->assign([
            'shop_name' => $this->context->shop->name,
        ]);

        return $this->fetch('module:blockbee/views/templates/front/payment_return.tpl');
    }

    public function hookDisplayAdminOrderTabContent($params)
    {
        $order = $this->resolveOrder($params);
        if ($order === null || $order->module !== $this->name) {
            return;
        }

        $row = self::findPayment((int) $order->id);
        $payload = ($row && !empty($row['payload'])) ? json_decode($row['payload'], true) : [];
        if (!is_array($payload)) {
            $payload = [];
        }

        $this->context->smarty->assign([
            'payment_id' => $row['payment_id'] ?? '',
            'created_at' => $row['created_at'] ?? 0,
            'payload' => $payload,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/payment_tab_content.tpl');
    }

    public function hookDisplayAdminOrderTabOrder($params)
    {
        $order = $this->resolveOrder($params);
        if ($order === null || $order->module !== $this->name) {
            return;
        }

        return $this->display(__FILE__, 'views/templates/admin/payment_tab.tpl');
    }

    public function hookDisplayAdminOrderTabLink($params)
    {
        return $this->hookDisplayAdminOrderTabOrder($params);
    }

    private function resolveOrder($params)
    {
        if (isset($params['order']) && Validate::isLoadedObject($params['order'])) {
            return $params['order'];
        }
        if (!empty($params['id_order'])) {
            $order = new Order((int) $params['id_order']);

            return Validate::isLoadedObject($order) ? $order : null;
        }

        return null;
    }
}
