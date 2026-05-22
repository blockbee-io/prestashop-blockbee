[<img src="https://blockbee.io/static/assets/images/blockbee_logo_nospaces.png" width="300"/>](image.png)

# BlockBee Payment Gateway for PrestaShop

Accept cryptocurrency payments on your PrestaShop website

### Requirements:

```
PHP >= 7.4  (or whatever your PrestaShop version requires — PS 9 needs PHP 8.1+)
PrestaShop >= 1.7  (tested on 8.x and 9.x)
```

### Description

Accept payments in Bitcoin, Bitcoin Cash, Litecoin, Ethereum, USDT and Matic directly to your crypto wallet, without any sign-ups or lengthy processes. All you need is to provide your crypto address.

#### Allow users to pay with crypto directly on your store

The BlockBee module enables your PrestaShop store to get receive payments in cryptocurrency, with a simple setup and no sign-ups required.

#### Accepted cryptocurrencies & tokens include:

- (BTC) Bitcoin
- (ETH) Ethereum
- (BCH) Bitcoin Cash
- (LTC) Litecoin
- (POL) Polygon
- (TRX) Tron
- (BNB) Binance Coin
- (USDT) USDT

among many others, for a full list of the supported cryptocurrencies and tokens, check [this page](https://blockbee.io/cryptocurrencies/).

#### Auto-value conversion

BlockBee module will attempt to automatically convert the value you set on your store to the cryptocurrency your customer chose.
Exchange rates are fetched every 5 minutes.

Supported currencies for automatic exchange rates are:

- (USD) United States Dollar
- (EUR) Euro
- (GBP) Great Britain Pound
- (CAD) Canadian Dollar
- (JPY) Japanese Yen
- (AED) UAE Dollar
- (MYR) Malaysian Ringgit
- (IDR) Indonesian Rupiah
- (THB) Thai Baht
- (CHF) Swiss Franc
- (SGD) Singapore Dollar
- (RUB) Russian Ruble
- (ZAR) South African Rand
- (TRY) Turkish Lira
- (LKR) Sri Lankan Rupee
- (RON) Romanian Leu
- (BGN) Bulgarian Lev
- (HUF) Hungarian Forint
- (CZK) Czech Koruna
- (PHP) Philippine Peso
- (PLN) Poland Zloti
- (UGX) Uganda Shillings
- (MXN) Mexican Peso
- (INR) Indian Rupee
- (HKD) Hong Kong Dollar
- (CNY) Chinese Yuan
- (BRL) Brazilian Real
- (DKK) Danish Krone
- (TWD) New Taiwan Dollar
- (AUD) Australian Dollar
- (NGN) Nigerian Naira
- (SEK) Swedish Krona
- (NOK) Norwegian Krone
- (UAH) Ukrainian Hryvnia
- (VND) Vietnamese Dong

If your WooCommerce's currency is none of the above, the exchange rates will default to USD.
If you're using WooCommerce in a different currency not listed here and need support, please [contact us](https://blockbee.io/contacts/) via our live chat.

**Note:** BlockBee will not exchange your crypto for FIAT or other crypto, just convert the value

#### Why choose BlockBee?

BlockBee has no setup fees, no monthly fees, no hidden costs, and you don't even need to sign-up!
Simply set your crypto addresses and you're ready to go. As soon as your customers pay we forward your earnings directly to your own wallet.

BlockBee has a low 1% fee on the transactions processed. No hidden costs.
For more info on our fees [click here](https://blockbee.io/fees/)

### Installation

#### Uploading in Prestashop Dashboard

1. Navigate to the 'Module Manager' in the PrestaShop dashboard
2. Click the 'Upload a Module' button
3. Select `prestashop-blockbee.zip` from your computer

#### Using FTP

1. Download `prestashop-blockbee.zip`
2. Extract the `prestashop-blockbee` directory to your computer
3. Upload the `prestashop-blockbee` directory to the `/your-store/modules/` directory
4. Activate the module in the `Module Catalog` dashboard and then configure it.

### Configuration

1. Go to Prestashop dashboard
2. Select the "Modules" tab and click "Module Manager"
3. Search for "BlockBee" and click "configure" in our module
4. Set the name you wish to show your users on Checkout (for example: "Cryptocurrency")
5. Select which cryptocurrencies you wish to accept (control + click to select many)
6. Input your addresses to the cryptocurrencies you selected. This is where your funds will be sent to, so make sure the addresses are correct.
7. Click "Save"
8. All done!

### Enabling the Cronjob

Some features require a cronjob to work. You need to create one in your hosting that runs every 1 minute. It should call this URL YOUR-DOMAIN/module/blockbee/cronjob?nonce=`your_nonce_here`, using `CURL`.
The required `nonce` its generated in the Module Manager configuration.

### Frequently Asked Questions

#### Do I need an API key?

No. You just need to insert your crypto address of the cryptocurrencies you wish to accept. Whenever a customer pays, the money will be automatically and instantly forwarded to your address.

#### How long do payments take before they're confirmed?

This depends on the cryptocurrency you're using. Bitcoin usually takes up to 11 minutes, Ethereum usually takes less than a minute.

#### Is there a minimum for a payment?

Yes, the minimums change according to the chosen cryptocurrency and can be checked [here](https://blockbee.io/get_started/#fees).
If the WooCommerce order total is below the chosen cryptocurrency's minimum, an error is raised to the user.

#### Where can I find more documentation on your service?

You can find more documentation about our service on our [website](https://blockbee.io/), our [technical documentation](https://docs.blockbee.io/) page or our [e-commerce](https://blockbee.io/ecommerce/) page.
If there's anything else you need that is not covered on those pages, please get in touch with us, we're here to help you!

#### Where can I get support?

You can find more documentation about our service on our [website](https://blockbee.io/), our [technical documentation](https://docs.blockbee.io/) page or our [e-commerce](https://blockbee.io/ecommerce/) page.

### Changelog

#### 2.0.0
* Rewrite on top of BlockBee Checkout Payments — customers are now redirected to `pay.blockbee.io` to pay
* Webhook signature verification added
* PrestaShop 8 and 9 compatibility
* Cronjob no longer needed

#### 1.1.3
* Add new choices for order cancellation.

#### 1.1.2
* Minor bugfixes

#### 1.1.1
* Minor bugfixes

#### 1.1.0
* Support for Prestashop 8
* Minor bugfixes

#### 1.0.0
* Initial release.

### Upgrade Notice

- 2.0.0 contains breaking changes (architecture rewrite). Cancel pending 1.x orders and remove any cronjob entry before upgrading.
