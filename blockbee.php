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
 * @author BlockBee <info@blockbee.io>
 * @copyright  2022 BlockBee
 * @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class blockbee extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = [];

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;

    public const BLOCKBEE_WAITING = 'BLOCKBEE_WAITING';

    public function __construct()
    {
        $this->name = 'blockbee';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '1.7.9.99'];
        $this->author = 'BlockBee';
        $this->controllers = ['state', 'validation', 'callback', 'success', 'cronjob', 'fee'];
        $this->module_key = '47cc969f06561eca622b85d1df87b189';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->displayName = $this->l('BlockBee Payment Gateway for PrestaShop', '', 'en');
        $this->description = $this->l('Accept cryptocurrency payments on your PrestaShop website', '', 'en');
        $this->bootstrap = true;
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?', '', 'en');

        parent::__construct();

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.', '', 'en');
        }

        require 'lib/BlockBeeHelper.php';
    }

    public function install()
    {
        $db = Db::getInstance();
        if (!parent::install() ||
            !$this->registerHook('paymentOptions') ||
            !$this->registerHook('paymentReturn') ||
            !$this->registerHook('displayAdminOrderTabOrder') ||
            !$this->registerHook('displayAdminOrderTabContent') ||
            !$this->registerHook('displayAdminOrderTabLink')
        ) {
            return false;
        }

        if (!$this->addOrderState()) {
            return false;
        }

        $db->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'blockbee_order`(
        `order_id` INT NOT NULL,
        `response` TEXT
        )');

        $db->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'blockbee_coins`(
        `id` int NOT NULL PRIMARY KEY,
        `coins` TEXT )');

        return true;
    }

    public function uninstall()
    {
        parent::uninstall() &&
        $this->unregisterHook('paymentOptions') &&
        $this->unregisterHook('paymentReturn') &&
        $this->unregisterHook('displayAdminOrderTabOrder') &&
        $this->unregisterHook('displayAdminOrderTabLink') &&
        $this->unregisterHook('displayAdminOrderTabContent') &&
        Configuration::updateValue('blockbee_active', 0);

        return true;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $db = Db::getInstance();
            $save_coins = [];

            $active = (string) Tools::getValue('blockbee_active');
            $title = (string) Tools::getValue('blockbee_checkout_title');
            $api_key = (string) Tools::getValue('blockbee_api_key');
            $show_branding = (string) Tools::getValue('blockbee_show_branding');
            $add_blockchain_fee = (string) Tools::getValue('blockbee_add_blockchain_fee');
            $fee_order_percentage = (string) Tools::getValue('blockbee_fee_order_percentage');
            $qrcode_default = (string) Tools::getValue('blockbee_qrcode_default');
            $qrcode_setting = (string) Tools::getValue('blockbee_qrcode_setting');
            $color_scheme = (string) Tools::getValue('blockbee_color_scheme');
            $disable_conversion = (string) Tools::getValue('blockbee_disable_conversion');
            $qrcode_size = (string) Tools::getValue('blockbee_qrcode_size');
            $coins_cache = (string) Tools::getValue('blockbee_coins_cache');
            $refresh_value_interval = (string) Tools::getValue('blockbee_refresh_value_interval');
            $order_cancelation_timeout = (string) Tools::getValue('blockbee_order_cancelation_timeout');
            $cronjob_nonce = Tools::getValue('blockbee_cronjob_nonce');
            $coins = Tools::getValue('blockbee_coins');

            if (empty($title) || !Validate::isString($title)) {
                $output = $this->displayError($this->l('Invalid Configuration value', '', 'en'));

                return $output;
            }

            if (empty($coins_cache) || !Validate::isString($coins_cache)) {
                $output = $this->displayError($this->l('Invalid Configuration value', '', 'en'));

                return $output;
            }

            if (empty($qrcode_size) || !Validate::isInt($qrcode_size)) {
                $output = $this->displayError($this->l('Invalid Configuration value. Qr Code Size must be a number.', '', 'en'));

                return $output;
            }

            if (empty($api_key)) {
                $output = $this->displayError($this->l('Please insert a valid API Key.', '', 'en'));

                return $output;
            }

            if (empty($coins)) {
                $output = $this->displayError($this->l('Invalid Configuration value. Please select the cryptocurrencies you want to enable.', '', 'en'));

                return $output;
            }

            if ($color_scheme != 'light' && $color_scheme != 'dark' && $color_scheme != 'auto') {
                $output = $this->displayError($this->l('Invalid Configuration value', '', 'en'));

                return $output;
            }

            foreach ($coins as $selected_coin) {
                $save_coins[] = $selected_coin;
            }

            // Update the database table with the selected currencies. If row doesn't exist, create new
            if (empty($db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'blockbee_coins` WHERE id=1'))) {
                $db->Execute('INSERT INTO `' . _DB_PREFIX_ . "blockbee_coins` (`id`, `coins`) VALUES (1, '" . json_encode($save_coins) . "')");
            } else {
                $db->Execute('UPDATE `' . _DB_PREFIX_ . "blockbee_coins` SET coins='" . json_encode($save_coins) . "' WHERE id=1");
            }

            Configuration::updateValue('blockbee_active', $active);
            Configuration::updateValue('blockbee_checkout_title', $title);
            Configuration::updateValue('blockbee_api_key', $api_key);
            Configuration::updateValue('blockbee_add_blockchain_fee', $add_blockchain_fee);
            Configuration::updateValue('blockbee_fee_order_percentage', $fee_order_percentage);
            Configuration::updateValue('blockbee_show_branding', $show_branding);
            Configuration::updateValue('blockbee_qrcode_default', $qrcode_default);
            Configuration::updateValue('blockbee_qrcode_setting', $qrcode_setting);
            Configuration::updateValue('blockbee_color_scheme', $color_scheme);
            Configuration::updateValue('blockbee_qrcode_size', $qrcode_size);
            Configuration::updateValue('blockbee_refresh_value_interval', $refresh_value_interval);
            Configuration::updateValue('blockbee_order_cancelation_timeout', $order_cancelation_timeout);
            Configuration::updateValue('blockbee_disable_conversion', $disable_conversion);
            Configuration::updateValue('blockbee_coins_cache', $coins_cache);
            Configuration::updateValue('blockbee_cronjob_nonce', $cronjob_nonce);
            $output = $this->displayConfirmation($this->l('Settings updated', '', 'en'));
        }

        // display any message, then the form
        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $db = Db::getInstance();

        $apiKey = Configuration::get('blockbee_api_key');

        $cryptocurrencies = [];

        try {
            $cryptocurrencies_api = BlockBeeHelper::get_supported_coins($apiKey);

            foreach ($cryptocurrencies_api as $ticker => $coin) {
                $cryptocurrencies[] = [
                    'id_option' => $ticker,
                    'name' => $coin,
                ];
            }
        } catch (Exception $e) {
        }

        $default_nonce = empty(Tools::getValue('blockbee_cronjob_nonce', Configuration::get('blockbee_cronjob_nonce'))) ? blockbee::generateNonce() : Tools::getValue('blockbee_cronjob_nonce', Configuration::get('blockbee_cronjob_nonce'));

        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings', '', 'en'),
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Activate:', '', 'en'),
                        'desc' => $this->l('This enables BlockBee Payment Gateway', '', 'en'),
                        'name' => 'blockbee_active',
                        'required' => true,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Title', '', 'en'),
                        'name' => 'blockbee_checkout_title',
                        'size' => 20,
                        'required' => true,
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Add the blockchain fee to the order:', '', 'en'),
                        'desc' => $this->l('This will add an estimation of the blockchain fee to the order value', '', 'en'),
                        'name' => 'blockbee_add_blockchain_fee',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Service fee manager:', '', 'en'),
                        'name' => 'blockbee_fee_order_percentage',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '0.05',
                                    'name' => '5%',
                                ],
                                [
                                    'id_option' => '0.048',
                                    'name' => '4.8%',
                                ],
                                [
                                    'id_option' => '0.045',
                                    'name' => '4.5%',
                                ],
                                [
                                    'id_option' => '0.045',
                                    'name' => '4.5%',
                                ],
                                [
                                    'id_option' => '0.042',
                                    'name' => '4.2%',
                                ],
                                [
                                    'id_option' => '0.04',
                                    'name' => '4%',
                                ],
                                [
                                    'id_option' => '0.038',
                                    'name' => '3.8%',
                                ],
                                [
                                    'id_option' => '0.035',
                                    'name' => '3.5%',
                                ],
                                [
                                    'id_option' => '0.032',
                                    'name' => '3.2%',
                                ],
                                [
                                    'id_option' => '0.03',
                                    'name' => '3%',
                                ],
                                [
                                    'id_option' => '0.028',
                                    'name' => '2.8%',
                                ],
                                [
                                    'id_option' => '0.025',
                                    'name' => '2.5%',
                                ],
                                [
                                    'id_option' => '0.022',
                                    'name' => '2.2%',
                                ],
                                [
                                    'id_option' => '0.02',
                                    'name' => '2%',
                                ],
                                [
                                    'id_option' => '0.018',
                                    'name' => '1.8%',
                                ],
                                [
                                    'id_option' => '0.015',
                                    'name' => '1.5%',
                                ],
                                [
                                    'id_option' => '0.012',
                                    'name' => '1.2%',
                                ],
                                [
                                    'id_option' => '0.01',
                                    'name' => '1%',
                                ],
                                [
                                    'id_option' => '0.0090',
                                    'name' => '0.90%',
                                ],
                                [
                                    'id_option' => '0.0085',
                                    'name' => '0.85%',
                                ],
                                [
                                    'id_option' => '0.0080',
                                    'name' => '0.80%',
                                ],
                                [
                                    'id_option' => '0.0075',
                                    'name' => '0.75%',
                                ],
                                [
                                    'id_option' => '0.0070',
                                    'name' => '0.70%',
                                ],
                                [
                                    'id_option' => '0.0065',
                                    'name' => '0.65%',
                                ],
                                [
                                    'id_option' => '0.0060',
                                    'name' => '0.60%',
                                ],
                                [
                                    'id_option' => '0.0055',
                                    'name' => '0.55%',
                                ],
                                [
                                    'id_option' => '0.0050',
                                    'name' => '0.50%',
                                ],
                                [
                                    'id_option' => '0.0040',
                                    'name' => '0.40%',
                                ],
                                [
                                    'id_option' => '0.0030',
                                    'name' => '0.30%',
                                ],
                                [
                                    'id_option' => '0.0025',
                                    'name' => '0.25%',
                                ],
                                [
                                    'id_option' => '0',
                                    'name' => '0%',
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Show BlockBee branding:', '', 'en'),
                        'desc' => $this->l('Show BlockBee logo and credits below the QR code', '', 'en'),
                        'name' => 'blockbee_show_branding',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('QR Code to show:', '', 'en'),
                        'name' => 'blockbee_qrcode_setting',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 'without_amount',
                                    'name' => 'Default Without Amount',
                                ],
                                [
                                    'id_option' => 'amount',
                                    'name' => 'Default Amount',
                                ],
                                [
                                    'id_option' => 'hide_without_amount',
                                    'name' => 'Hide Without Amount',
                                ],
                                [
                                    'id_option' => 'hide_amount',
                                    'name' => 'Hide Amount',
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('QR Code by default:', '', 'en'),
                        'desc' => $this->l('Show the QR Code by default', '', 'en'),
                        'name' => 'blockbee_qrcode_default',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('QR Code size', '', 'en'),
                        'name' => 'blockbee_qrcode_size',
                        'required' => true,
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Color Scheme:', '', 'en'),
                        'desc' => $this->l('Selects the color scheme of the plugin to match your website (Light, Dark and Auto to automatically detect it)', '', 'en'),
                        'required' => true,
                        'name' => 'blockbee_color_scheme',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 'light',
                                    'name' => $this->l('Light', '', 'en'),
                                ],
                                [
                                    'id_option' => 'dark',
                                    'name' => $this->l('Dark', '', 'en'),
                                ],
                                [
                                    'id_option' => 'auto',
                                    'name' => 'Auto',
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Refresh converted value:', '', 'en'),
                        'desc' => sprintf($this->l('The system will automatically update the conversion value of the invoices (with real-time data), every X minutes. %1$s This feature is helpful whenever a customer takes long time to pay a generated invoice and the selected crypto a volatile coin/token (not stable coin). %1$s %2$s Warning: %3$s Setting this setting to none might create conversion issues, as we advise you to keep it at 5 minutes. %1$s %4$s Do not forget to set up the cronjob in your server %3$s', '', 'en'), '<br/>', '<strong style="color: #f44336;">', '</strong>', '<strong>'),
                        'required' => true,
                        'name' => 'blockbee_refresh_value_interval',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '0',
                                    'name' => $this->l('Never', '', 'en'),
                                ],
                                [
                                    'id_option' => '300',
                                    'name' => $this->l('Every 5 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '600',
                                    'name' => $this->l('Every 10 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '900',
                                    'name' => $this->l('Every 15 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '1800',
                                    'name' => $this->l('Every 30 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '2700',
                                    'name' => $this->l('Every 45 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '3600',
                                    'name' => $this->l('Every 60 Minutes', '', 'en'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Order cancelation timeout:', '', 'en'),
                        'desc' => sprintf($this->l('Selects the amount of time the user has to pay for the order. %1$s When this time is over, order will be marked as "Canceled" and every paid value will be ignored. %1$s %2$s Notice: %3$s If the user still sends money to the generated address. Value will still be redirected to you.', '', 'en'), '<br/>', '<strong>', '</strong>'),
                        'required' => true,
                        'name' => 'blockbee_order_cancelation_timeout',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '0',
                                    'name' => $this->l('Never', '', 'en'),
                                ],
                                [
                                    'id_option' => '3600',
                                    'name' => $this->l('1 Hour', '', 'en'),
                                ],
                                [
                                    'id_option' => '21600',
                                    'name' => $this->l('6 Hours', '', 'en'),
                                ],
                                [
                                    'id_option' => '43200',
                                    'name' => $this->l('12 Hours', '', 'en'),
                                ],
                                [
                                    'id_option' => '64800',
                                    'name' => $this->l('18 Hours', '', 'en'),
                                ],
                                [
                                    'id_option' => '86400',
                                    'name' => $this->l('24 Hours', '', 'en'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Disable price conversion:', '', 'en'),
                        'desc' => $this->l('Attention: This option will disable the price conversion for ALL cryptocurrencies! If you check this, pricing will not be converted from the currency of your shop to the cryptocurrency selected by the user, and users will be requested to pay the same value as shown on your shop, regardless of the cryptocurrency selected', '', 'en'),
                        'name' => 'blockbee_disable_conversion',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API Keys', '', 'en'),
                        'name' => 'blockbee_api_key',
                        'size' => 100,
                        'required' => true,
                        'desc' => sprintf($this->l('Insert here your BlockBee API Key. You can get one here: %1$s.', '', 'en'), '<a href="https://dash.blockbee.io/" target="_blank">https://dash.blockbee.io/</a>', '<strong>', '</strong>'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Select cryptocurrencies', '', 'en'),
                        'desc' => sprintf($this->l('Please select the cryptocurrencies you wish to enable. CTRL + Mouse click to select more than one. %1$s %1$s %2$sNotice: %3$sIf you are using BlockBee you can choose if setting the receiving addresses here bellow or in your BlockBee settings page. %1$s In order to set the addresses on plugin settings, you need to select “Address Override” while creating the API key. %1$s In order to set the addresses on BlockBee settings, you need to NOT select “Address Override” while creating the API key.', '', 'en'), '<br/>', '<strong>', '</strong>'),
                        'name' => 'blockbee_coins',
                        'multiple' => true,
                        'options' => [
                            'query' => $cryptocurrencies,
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'hidden',
                        'name' => 'blockbee_coins_cache',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save', '', 'en'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $form['form']['input'][] = [
            'type' => 'text',
            'label' => $this->l('Cronjob Nonce', '', 'en'),
            'desc' => sprintf($this->l('Add this string to your cronjob URL when creating the cronjob in your system. %1$s The request should look like this: %2$s', '', 'en'), '<br/>', '<a href="' . _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/blockbee/cronjob?nonce=' . $default_nonce . '" target="_blank">' . _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/blockbee/cronjob?nonce=' . $default_nonce . '</a>'),
            'name' => 'blockbee_cronjob_nonce',
            'required' => true,
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->table = $this->table;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&' . http_build_query(['configure' => $this->name]);
        $helper->submit_action = 'submit' . $this->name;

        // Default language
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');

        // Creating this array to preselect the currency
        $coins_db = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'blockbee_coins` WHERE id=1');

        // Load current value into the form
        $helper->fields_value['blockbee_active'] = empty(Tools::getValue('blockbee_active', Configuration::get('blockbee_active'))) ? 0 : Tools::getValue('blockbee_active', Configuration::get('blockbee_active'));
        $helper->fields_value['blockbee_checkout_title'] = empty(Tools::getValue('blockbee_checkout_title', Configuration::get('blockbee_checkout_title'))) ? 'Cryptocurrency' : Tools::getValue('blockbee_checkout_title', Configuration::get('blockbee_checkout_title'));
        $helper->fields_value['blockbee_qrcode_size'] = empty(Tools::getValue('blockbee_qrcode_size', Configuration::get('blockbee_qrcode_size'))) ? '300' : Tools::getValue('blockbee_qrcode_size', Configuration::get('blockbee_qrcode_size'));
        $helper->fields_value['blockbee_api_key'] = Tools::getValue('blockbee_api_key', Configuration::get('blockbee_api_key'));
        $helper->fields_value['blockbee_add_blockchain_fee'] = empty(Tools::getValue('blockbee_add_blockchain_fee', Configuration::get('blockbee_add_blockchain_fee'))) ? 1 : Tools::getValue('blockbee_add_blockchain_fee', Configuration::get('blockbee_add_blockchain_fee'));
        $helper->fields_value['blockbee_fee_order_percentage'] = empty(Tools::getValue('blockbee_fee_order_percentage', Configuration::get('blockbee_fee_order_percentage'))) && Tools::getValue('blockbee_fee_order_percentage', Configuration::get('blockbee_fee_order_percentage')) !== 0 ? '0' : Tools::getValue('blockbee_fee_order_percentage', Configuration::get('blockbee_fee_order_percentage'));
        $helper->fields_value['blockbee_show_branding'] = empty(Tools::getValue('blockbee_show_branding', Configuration::get('blockbee_show_branding'))) ? 1 : Tools::getValue('blockbee_show_branding', Configuration::get('blockbee_show_branding'));
        $helper->fields_value['blockbee_qrcode_default'] = empty(Tools::getValue('blockbee_qrcode_default', Configuration::get('blockbee_qrcode_default'))) ? 1 : Tools::getValue('blockbee_qrcode_default', Configuration::get('blockbee_qrcode_default'));
        $helper->fields_value['blockbee_color_scheme'] = Tools::getValue('blockbee_color_scheme', Configuration::get('blockbee_color_scheme'));
        $helper->fields_value['blockbee_refresh_value_interval'] = empty(Tools::getValue('blockbee_refresh_value_interval', Configuration::get('blockbee_refresh_value_interval'))) && Tools::getValue('blockbee_refresh_value_interval', Configuration::get('blockbee_refresh_value_interval')) !== '0' ? '0' : Tools::getValue('blockbee_refresh_value_interval', Configuration::get('blockbee_refresh_value_interval'));
        $helper->fields_value['blockbee_order_cancelation_timeout'] = empty(Tools::getValue('blockbee_order_cancelation_timeout', Configuration::get('blockbee_order_cancelation_timeout'))) && Tools::getValue('blockbee_order_cancelation_timeout', Configuration::get('blockbee_order_cancelation_timeout')) !== '0' ? '0' : Tools::getValue('blockbee_order_cancelation_timeout', Configuration::get('blockbee_order_cancelation_timeout'));
        $helper->fields_value['blockbee_disable_conversion'] = Tools::getValue('blockbee_disable_conversion', Configuration::get('blockbee_disable_conversion'));
        $helper->fields_value['blockbee_qrcode_setting'] = Tools::getValue('blockbee_qrcode_setting', Configuration::get('blockbee_qrcode_setting'));
        $helper->fields_value['blockbee_coins_cache'] = json_encode($cryptocurrencies_api);
        $helper->fields_value['blockbee_cronjob_nonce'] = $default_nonce;

        if (empty($coins_db)) {
            $helper->fields_value['blockbee_coins[]'] = '';
        } else {
            $helper->fields_value['blockbee_coins[]'] = json_decode($coins_db['coins'], true);
        }

        return $helper->generateForm([$form]);
    }

    public function hookPaymentOptions($params)
    {
        if (empty(Configuration::get('blockbee_active'))) {
            return false;
        }

        if (empty(json_decode(Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'blockbee_coins` WHERE id=1')['coins'], true))) {
            return false;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return false;
        }

        $payment_options = [
            $this->getEmbeddedPaymentOption(),
        ];

        return $payment_options;
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

    public function getEmbeddedPaymentOption()
    {
        if (!Configuration::get('blockbee_active')) {
            return false;
        }
        $coins = [];

        $selected = json_decode(Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'blockbee_coins` WHERE id=1')['coins'], true);

        foreach (json_decode(Configuration::get('blockbee_coins_cache'), true) as $ticker => $coin) {
            foreach ($selected as $selected_coin) {
                if ($ticker === $selected_coin) {
                    if (!empty(Configuration::get('blockbee_api_key'))) {
                        $coins[] = [
                            'ticker' => $ticker,
                            'coin' => $coin,
                        ];
                    }
                }
            }
        }

        $embeddedOption = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $embeddedOption->setCallToActionText(Configuration::get('blockbee_checkout_title'))->setForm($this->generatePaymentForm($coins));

        return $embeddedOption;
    }

    protected function generatePaymentForm($coins)
    {
        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->name, 'validation', [], true),
            'cryptocurrencies' => $coins,
            'fee' => $this->context->link->getModuleLink($this->name, 'fee', [], true),
            'js_dir' => Media::getJSPath(_PS_MODULE_DIR_ . '/blockbee/views/js/blockbee_cart.js'),
        ]);

        return $this->context->smarty->fetch('module:blockbee/views/templates/front/payment_form.tpl');
    }

    public function addOrderState()
    {
        if (!Configuration::get(self::BLOCKBEE_WAITING) || !Validate::isLoadedObject(new OrderState(Configuration::get(self::BLOCKBEE_WAITING)))) {
            $order_state = new OrderState();
            $order_state->name = [];
            foreach (Language::getLanguages() as $language) {
                switch (Tools::strtolower($language['iso_code'])) {
                    case 'fr':
                        $order_state->name[$language['id_lang']] = pSQL('En attente de paiement BlockBee');
                        break;
                    case 'es':
                        $order_state->name[$language['id_lang']] = pSQL('Esperando pago BlockBee');
                        break;
                    case 'de':
                        $order_state->name[$language['id_lang']] = pSQL('Warten auf BlockBee-Zahlung');
                        break;
                    case 'nl':
                        $order_state->name[$language['id_lang']] = pSQL('Wachten op BlockBee-betaling');
                        break;
                    case 'it':
                        $order_state->name[$language['id_lang']] = pSQL('In attesa del pagamento BlockBee');
                        break;
                    case 'pt':
                        $order_state->name[$language['id_lang']] = pSQL('Aguardando o pagamento da BlockBee');
                        break;

                    default:
                        $order_state->name[$language['id_lang']] = pSQL('Waiting for BlockBee payment');
                        break;
                }
            }
            $order_state->invoice = false;
            $order_state->send_email = false;
            $order_state->logable = false;
            $order_state->color = '#258ecd';
            $order_state->module_name = $this->name;
            if ($order_state->add()) {
                $source = __DIR__ . '/views/img/blockbee_payment.png';
                $destination = _PS_ROOT_DIR_ . '/img/os/' . (int) $order_state->id . '.png';
                copy($source, $destination);
            }

            Configuration::updateValue(self::BLOCKBEE_WAITING, $order_state->id);
        }

        return true;
    }

    public static function addPaymentResponse($order_id, $params)
    {
        $db = Db::getInstance();

        $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'blockbee_order` (`order_id`, `response`) VALUES (' . $order_id . ", '" . $params . "')");
    }

    public static function getPaymentResponse($orderId)
    {
        return Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'blockbee_order` WHERE order_id=' . $orderId)['response'];
    }

    public static function updatePaymentResponse($order_id, $param, $value)
    {
        $metaData = blockbee::getPaymentResponse($order_id);
        if (!empty($metaData)) {
            $metaData = json_decode($metaData, true);
            $metaData[$param] = $value;
            $paymentData = json_encode($metaData);

            $db = Db::getInstance();
            $db->Execute('UPDATE `' . _DB_PREFIX_ . "blockbee_order` SET response='" . $paymentData . "' WHERE order_id=" . $order_id);
        }
    }

    public static function getAllOrders()
    {
        // Get only BlockBee Payments that are waiting for payment
        $orders = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'orders` WHERE module="blockbee" AND current_state=' . Configuration::get(self::BLOCKBEE_WAITING));

        return $orders;
    }

    public static function blockbeeCronjob()
    {
        $order_timeout = (int) Configuration::get('blockbee_order_cancelation_timeout');
        $value_refresh = (int) Configuration::get('blockbee_refresh_value_interval');

        if ((int) $order_timeout === 0 && (int) $value_refresh === 0) {
            return;
        }

        $apiKey = Configuration::get('blockbee_api_key');
        $orders = blockbee::getAllOrders();

        if (!empty($orders)) {
            $currency = strtolower(Currency::getDefaultCurrency()->iso_code);

            foreach ($orders as $order) {
                $orderId = $order['id_order'];
                $disableConversion = Configuration::get('blockbee_disable_conversion');
                $qrCodeSize = Configuration::get('blockbee_qrcode_size');

                $metaData = json_decode(blockbee::getPaymentResponse($orderId), true);

                if (!empty($metaData['blockbee_last_price_update'])) {
                    $last_price_update = $metaData['blockbee_last_price_update'];

                    $historyDb = $metaData['blockbee_history'];

                    $min_tx = (float) $metaData['blockbee_min'];

                    $calc = blockbee::calcOrder($historyDb, $metaData['blockbee_total'], $metaData['blockbee_total_fiat']);

                    $remaining = $calc['remaining'];
                    $remaining_pending = $calc['remaining_pending'];
                    $already_paid = $calc['already_paid'];

                    if ($value_refresh !== 0 && $last_price_update + $value_refresh <= time()) {
                        if ($remaining === $remaining_pending) {
                            $blockbee_coin = $metaData['blockbee_currency'];

                            $crypto_total = BlockBeeHelper::sig_fig(BlockBeeHelper::get_conversion($currency, $blockbee_coin, $order['total_paid'], $disableConversion, $apiKey), 6);

                            blockbee::updatePaymentResponse($orderId, 'blockbee_total', $crypto_total);

                            $metaData = json_decode(blockbee::getPaymentResponse($orderId), true);

                            $calc_cron = blockbee::calcOrder($historyDb, $metaData['blockbee_total'], $metaData['blockbee_total_fiat']);

                            $crypto_remaining_total = $calc_cron['remaining_pending'];

                            if ($remaining_pending <= $min_tx && $remaining_pending > 0) {
                                $qr_code_data_value = BlockBeeHelper::get_static_qrcode($metaData['blockbee_address'], $blockbee_coin, $min_tx, $apiKey, $qrCodeSize);
                            } else {
                                $qr_code_data_value = BlockBeeHelper::get_static_qrcode($metaData['blockbee_address'], $blockbee_coin, $crypto_remaining_total, $apiKey, $qrCodeSize);
                            }

                            blockbee::updatePaymentResponse($orderId, 'blockbee_qr_code_value', $qr_code_data_value['qr_code']);
                        }

                        blockbee::updatePaymentResponse($orderId, 'blockbee_last_price_update', time());
                    }

                    if ($order_timeout !== 0 && ($metaData['blockbee_order_created'] + $order_timeout) <= time() && $already_paid <= 0) {
                        $history = new OrderHistory();
                        $history->id_order = $orderId;
                        $history->changeIdOrderState((int) Configuration::get('PS_OS_CANCELED'), $history->id_order, false);
                        $history->addWithemail();
                        $history->save();
                    }
                }
            }
        }
    }

    public static function generateNonce($len = 32)
    {
        $data = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');

        $nonce = [];
        for ($i = 0; $i < $len; ++$i) {
            $nonce[] = $data[mt_rand(0, sizeof($data) - 1)];
        }

        return implode('', $nonce);
    }

    public static function calcOrder($history, $total, $total_fiat)
    {
        $already_paid = 0;
        $already_paid_fiat = 0;
        $remaining = $total;
        $remaining_pending = $total;
        $remaining_fiat = $total_fiat;

        if (!empty($history)) {
            foreach ($history as $uuid => $item) {
                if ((int) $item['pending'] === 0) {
                    $remaining = bcsub(BlockBeeHelper::sig_fig($remaining, 6), $item['value_paid'], 8);
                }

                $remaining_pending = bcsub(BlockBeeHelper::sig_fig($remaining_pending, 6), $item['value_paid'], 8);
                $remaining_fiat = bcsub(BlockBeeHelper::sig_fig($remaining_fiat, 6), $item['value_paid_fiat'], 8);

                $already_paid = bcadd(BlockBeeHelper::sig_fig($already_paid, 6), $item['value_paid'], 8);
                $already_paid_fiat = bcadd(BlockBeeHelper::sig_fig($already_paid_fiat, 6), $item['value_paid_fiat'], 8);
            }
        }

        return [
            'already_paid' => (float) $already_paid,
            'already_paid_fiat' => (float) $already_paid_fiat,
            'remaining' => (float) $remaining,
            'remaining_pending' => (float) $remaining_pending,
            'remaining_fiat' => (float) $remaining_fiat,
        ];
    }

    public static function sendMail($orderId)
    {
        $order = new Order((int) $orderId);
        $customer = $order->getCustomer();
        $customerMail = $customer->email;
        $customerName = $customer->firstname . ' ' . $customer->lastname;

        try {
            $metaData = json_decode(blockbee::getPaymentResponse($orderId), true);
        } catch (Exception $e) {
            return;
        }

        Mail::Send(
            (int) $order->id_lang,
            'blockbee_link',
            Translate::getModuleTranslation('blockbee', 'New Order %1$s. Please send a %2$s payment', 'blockbee', [
                $order->reference, strtoupper($metaData['blockbee_currency']),
            ]),
            [
                '{email}' => Configuration::get('PS_SHOP_EMAIL'),
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{order}' => $order->reference,
                '{coin}' => strtoupper($metaData['blockbee_currency']),
                '{url}' => $metaData['blockbee_payment_url'],
            ],
            $customerMail,
            $customerName,
            Configuration::get('PS_SHOP_EMAIL'),
            Configuration::get('PS_SHOP_NAME'),
            null,
            null,
            _PS_MODULE_DIR_ . 'blockbee/mails'
        );
    }

    public function hookDisplayAdminOrderTabContent($params)
    {
        if (version_compare(_PS_VERSION_, '1.7.7.4', '>=')) {
            $order = new Order($params['id_order']);
        } else {
            $order = $params['order'];
        }

        if ($order->module != 'blockbee') {
            return;
        }

        $metaData = json_decode(blockbee::getPaymentResponse($order->id), true);
        $history = $metaData['blockbee_history'];

        unset($metaData['blockbee_history']);

        $this->context->smarty->assign([
            'meta_data' => $metaData,
            'history' => $history,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/payment_tab_content.tpl');
    }

    public function hookDisplayAdminOrderTabOrder($params)
    {
        if (version_compare(_PS_VERSION_, '1.7.7.4', '>=')) {
            $order = new Order($params['id_order']);
        } else {
            $order = $params['order'];
        }

        if ($order->module != 'blockbee') {
            return;
        }

        return $this->display(__FILE__, 'views/templates/admin/payment_tab.tpl');
    }

    public function hookDisplayAdminOrderTabLink($params)
    {
        return $this->hookDisplayAdminOrderTabOrder($params);
    }
}
