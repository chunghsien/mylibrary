<?php

declare(strict_types=1);

namespace Chopin\Store\Logistics;

use Chopin\Store\TableGateway\PaymentTableGateway;
use Laminas\Db\Adapter\Adapter;
use Chopin\LanguageHasLocale\TableGateway\CurrenciesTableGateway;
use Laminas\Db\Sql\Where;
use Chopin\LanguageHasLocale\TableGateway\LanguageHasLocaleTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Log\Logger;
use Laminas\I18n\Translator\Translator;
use Chopin\Store\TableGateway\OrderTableGateway;
use Chopin\Store\TableGateway\OrderPayerTableGateway;
use Chopin\SystemSettings\TableGateway\SystemSettingsTableGateway;
use Chopin\Store\TableGateway\OrderParamsTableGateway;

class LinePayPayment extends AbstractPayment
{
    public const TABLE_USE_NAME = "LINE PAY";
    public const TABLE_USE_CODE = "LINEPay";

    // const CURRENT_VER = 'v3';
    protected $useCurrencies = [
        "USD",
        "JPY",
        "TWD",
        "THB"
    ];

    protected $useDisplayLocales = [
        "en",
        "ja",
        "ko",
        "th",
        "zh_TW",
        "zh_CN"
    ];

    /**
     *
     * @var Adapter
     */
    protected $adapter;

    /**
     *
     * @var PaymentTableGateway
     */
    protected $paymentTableGateway;

    /**
     *
     * @var CurrenciesTableGateway
     */
    protected $currenciesTableGateway;

    protected $systemSettings;

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->paymentTableGateway = new PaymentTableGateway($this->adapter);
        $pageConfig = \Chopin\Support\Registry::get('page_json_config');
        $linePayConfig = [];
        if (!$pageConfig) {
            $systemSettingsTableGateway = new SystemSettingsTableGateway($this->adapter);
            $toSerialize = $systemSettingsTableGateway->toSerialize();
            if (isset($toSerialize["LINEPay"])) {
                $linePayConfig = $toSerialize["LINEPay"]["to_config"]["LINEPay-config"];
            }
        } else {
            $linePayConfig = $pageConfig["system_settings"]["LINEPay"]["children"]["LINEPay-config"]["value"];
        }
        $this->systemSettings = [
            "localization" => $pageConfig["system_settings"]["localization"],
            "site_info" => $pageConfig["system_settings"]["site_info"],
            "LINEPay" => $linePayConfig,
        ];
        $date = date("Ym");
        $this->logger = new Logger([
            'writers' => [
                [
                    'name' => \Laminas\Log\Writer\Stream::class,
                    'priority' => 1,
                    'options' => [
                        'mod' => 'a+',
                        'stream' => "storage/logs/linePaySend_log_{$date}.log",
                    ],
                ],
            ],
        ]);
    }

    /**
     *
     * @param string $testEnv
     * @return array
     */
    public function getDefaultParamsTemplate()
    {
        return [
            "serviceNamespace" => "Chopin\Store\Logistics\LinePayPayment",
            "channelId" => "",
            "channelSecret" => "",
            "storeId" => "",
            "lineUrl" => "https://api-pay.line.me",
            "requestUrl" => "/v3/payments/request",
            //"redirectUrls" => [
                //"confirmUrl" => "",
                //"cancelUrl" => "",
            //],
            "options" => [
                "display" => [
                    "locale" => "zh_TW"
                ],
                // 以下僅限日本使用,如果網站服務地區不在日本要unset掉
                "shipping" => [
                    "feeAmount" => "運費",
                    "address" => [
                        "country" => "收貨國家的locale code",
                        "postalCode" => "收貨郵遞區號",
                        "state" => "收貨州",
                        "city" => "收貨市",
                        "detail" => "收貨地址",
                        "recipient" => [
                            "firstName" => "收貨人名",
                            "lastName" => "收貨人姓",
                            "email" => "收貨人電子郵件",
                            "phoneNo" => "收貨人電話號碼",
                        ],
                    ],
                ],
                    /*"extra"=>[
                        "branchName" => "商店或分店名稱(僅會顯示前 100 字元)",
                    ],*/
                ]
        ];
    }

    public function addDataToPaymentTable($language_id = 119, $locale_id = 229)
    {
        $paymentTableGateway = $this->paymentTableGateway;
        $verifyResultset = $paymentTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => self::TABLE_USE_NAME
        ]);
        if ($verifyResultset->count() == 0) {
            $paymentTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "name" => self::TABLE_USE_NAME,
                "code" => self::TABLE_USE_CODE,
                "manufacturer" => "LINEPay",
            ]);
        }
    }

    /**
     *
     * @param number $language_id
     * @param number $locale_id
     * @return array
     *
     */
    public function getConfig($language_id = 119, $locale_id = 229)
    {
        if ($this->config) {
            return $this->config;
        }
        $where = new Where();
        $where->equalTo("language_id", $language_id);
        $where->equalTo("locale_id", $locale_id);
        $where->equalTo("code", "LINEPay");
        $row = $this->paymentTableGateway->select($where)->current();
        if ($row) {
            $config = $this->systemSettings["LINEPay"];
            $config = str_replace('Chopin\\Store\\Logistics\\', 'Chopin\\\\Store\\\\Logistics\\\\', $config);
            $config = json_decode($config, true);
            if ($_ENV["APP_ENV"] === 'development') {
                if (!$this->devConfig) {
                    $this->devConfig = config('third_party_service.logistics.LINEPay');
                }
                $config = array_merge($config, $this->devConfig);
            }

            $this->config = $config;
            return $this->config;
        }
        return [];
    }

    public function orderDataTransToRequestBody(ServerRequestInterface $request, $orderData)
    {
        $currencyCode = $this->getCurrency();
        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $config = $this->getConfig($languageId, $localeId);
        $urlPrefix = $this->getUrlprefix($request, $orderData);
        $orderSerial = $orderData["serial"];
        $confirmUrltUrl = "{$urlPrefix}/linepay-confirm?orderId=${orderSerial}";
        $cancelUrlUrl = "{$urlPrefix}/linepay-cancel";
        $config["redirectUrls"] = [
            "confirmUrl" => $confirmUrltUrl,
            "cancelUrl" => $cancelUrlUrl,
        ];

        $orderData = (array) $orderData;
        $orderData["subtotal"] = floatval($orderData["subtotal"]);
        $orderData["trad_fee"] = floatval($orderData["trad_fee"]);
        $orderData["discount"] = floatval($orderData["discount"]);
        $orderData["total"] = floatval($orderData["total"]);
        $detail = (array) $orderData["details"];
        unset($orderData["detail"]);
        $languageHasLocaleTableGateway = new LanguageHasLocaleTableGateway($this->adapter);
        $languageHasLocaleItem = $languageHasLocaleTableGateway->getItemHasLangAndLocale($languageId, $localeId);
        $lanAndLocaleCode = $languageHasLocaleItem['code'];
        $siteInfo = $this->systemSettings["site_info"][$lanAndLocaleCode]["to_config"];
        $products = [];
        foreach ($detail as $detailItem) {
            $model = $detailItem["model"];
            $name = $model;
            if ($detailItem["option1_name"]) {
                $option1Name = $detailItem["option1_name"];
                $name .= "({$option1Name}";
                for ($i = 2; $i <= 5; $i ++) {
                    $field = "option{$i}_name";
                    if ($detailItem[$field]) {
                        $optionName = $detailItem[$field];
                        $name .= "/{$optionName}";
                    }
                }
                $name .= ")";
            }
            $products[] = [
                "id" => "products_spec_id=" . $detailItem["id"],
                "name" => $name,
                "quantity" => floatval($detailItem["quantity"]),
                "price" => floatval($detailItem["price"]),
            ];
        }
        unset($detail);
        $phpLang = $request->getAttribute('php_lang');
        $translator = new Translator();
        $translator->addTranslationFilePattern('phpArray', PROJECT_DIR.'/resources/languages/', '%s/linepay.php');
        $translator->setLocale($phpLang);

        $products[] = [
            "id" => 'trad_fee_' . date("ymdHi") . microtime(true),
            "name" => $translator->translate('tradFee'),
            "quantity" => 1,
            "price" => $orderData["trad_fee"],
        ];

        if ($orderData["discountDetail"]) {
            $products[] = [
                "id" => "coupon=" . $orderData["discountDetail"]["id"],
                "name" => $orderData["discountDetail"]["name"],
                "quantity" => 1,
                "price" => - intval($orderData["discountDetail"]["discount"]),
            ];
        }
        $packages = [
            [
                "id" => date("y-m-d-h-i") . str_replace('.', '-', (string) (strtotime("now") + microtime(true))),
                "name" => $siteInfo["name"],
                "amount" => $orderData["total"],
                "userFee" => 0,
                "products" => $products,
            ]
        ];
        $options = $config["options"];
        // $options["extra"]["branchName"] = $siteInfo["name"];
        if ($lanAndLocaleCode != "ja_JP") {
            unset($options["shipping"]);
        } else {
            $options["shipping"]["feeAmount"] = $orderData["trad_fee"];
            $options["shipping"]["address"]["country"] = $orderData["country"];
            $options["shipping"]["address"]["postalCode"] = $orderData["zip"];
            $options["shipping"]["address"]["state"] = $orderData["state"];
            $options["shipping"]["address"]["city"] = $orderData["county"];
            $options["shipping"]["address"]["detail"] = $orderData["address"];
            $options["shipping"]["address"]["recipient"]["firstName"] = $orderData["first_name"];
            $options["shipping"]["address"]["recipient"]["lastName"] = $orderData["last_name"];
            $options["shipping"]["address"]["recipient"]["email"] = $orderData["email"];
            $options["shipping"]["address"]["recipient"]["phoneNo"] = $orderData["cellphone"];
        }
        if (false === array_search($lanAndLocaleCode, $this->useDisplayLocales, true)) {
            $lanAndLocaleCode = 'zh_TW';
        }
        $redirectUrls = $config["redirectUrls"];
        $serverParams = $request->getServerParams();
        // $urlPrefix = (intval($serverParams["SERVER_PORT"]) === 443 ? 'https://' : 'http://').$serverParams["HTTP_HOST"];
        $urlPrefix = 'https://' . $serverParams["HTTP_HOST"];
        if (config('is_multiple_language')) {
            $urlPrefix = $urlPrefix . '/' . str_replace('_', '-', $lanAndLocaleCode);
        }
        if (! preg_match('/^https/', $redirectUrls["confirmUrl"])) {
            $redirectUrls["confirmUrl"] = preg_replace('/\/\//', '/', $redirectUrls["confirmUrl"]);
            $redirectUrls["confirmUrl"] = $urlPrefix . "/" . $redirectUrls["confirmUrl"];
            $redirectUrls["confirmUrl"] = preg_replace('/\/\/linepay\-confirm$/', '/linepay-confirm', $redirectUrls["confirmUrl"]);
        }
        if (! preg_match('/^https/', $redirectUrls["cancelUrl"])) {
            $redirectUrls["cancelUrl"] = preg_replace('/\/\//', '/', $redirectUrls["cancelUrl"]);
            $redirectUrls["cancelUrl"] = $urlPrefix . "/" . $redirectUrls["cancelUrl"];
            $redirectUrls["cancelUrl"] = preg_replace('/\/\/linepay\-cancel$/', '/linepay-cancel', $redirectUrls["cancelUrl"]);
        }
        $requestBody = [
            "amount" => $orderData["total"],
            "currency" => $currencyCode,
            "orderId" => $orderData["serial"],
            "packages" => $packages,
            "redirectUrls" => $redirectUrls,
            "options" => $options
        ];
        return $requestBody;
    }

    protected function hmacSignature($channelSerect, $requestUrl, $requestBody, $nonce)
    {
        $signatureData = $channelSerect . $requestUrl . $requestBody . $nonce;
        $signature = base64_encode(hash_hmac("sha256", $signatureData, $channelSerect, true));
        return $signature;
    }
    protected function getUrlprefix(ServerRequestInterface $request, $orderData)
    {
        $serverParams = $request->getServerParams();
        $urlPrefix = 'https://' . $serverParams["HTTP_HOST"];
        $localeCode = $this->getLocaleCode($orderData);
        $lcoale = str_replace('_', '-', $localeCode);

        if (config('is_multiple_language')) {
            $urlPrefix = "{$urlPrefix}/{$lcoale}";
        }
        return $urlPrefix;
    }
    protected function getLocaleCode($orderData)
    {
        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $languageHasLocaleTableGateway = new LanguageHasLocaleTableGateway($this->adapter);
        $languageHasLocaleRow = $languageHasLocaleTableGateway->select([
            "language_id" => $languageId,
            "locale_id" => $localeId,
        ])->current();
        $localeCode = $languageHasLocaleRow->code;
        return $localeCode;
    }

    /**
     *
     * {@inheritdoc}
     * @see \Chopin\Store\Logistics\AbstractPayment::requestApi()
     */
    public function requestApi(ServerRequestInterface $request, $orderData)
    {
        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $config = $this->getConfig($languageId, $localeId);
        $requestBody = json_encode($this->orderDataTransToRequestBody($request, $orderData));
        $nonce = uniqid('', true) . '-' . microtime(true);
        $channelSerect = $config["channelSecret"];
        $lineUrl = $config["lineUrl"];
        $requestUrl = $config["requestUrl"];
        $signature = $this->hmacSignature($channelSerect, $requestUrl, $requestBody, $nonce);
        $channelId = $config["channelId"];
        $header = [
            "Content-Type: application/json",
            "X-LINE-ChannelId: {$channelId}",
            "X-LINE-Authorization-Nonce: {$nonce}",
            "X-LINE-Authorization: {$signature}"
        ];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $lineUrl . $requestUrl);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $requestBody);
        $response = curl_exec($curl);
        curl_close($curl);
        if (! $response) {
            throw new \ErrorException('curl error.');
        } else {
            $this->logger->info($response);
            $response = json_decode($response, true);
            if ($response["returnCode"] == "0000") {
                return [
                    "status" => "success",
                    "redirectUrl" => $response["info"]["paymentUrl"]["web"],
                    "response" => $response,
                    "message" => [
                        "redirect to linepay"
                    ],
                ];
            } else {
                $returnCode = $response["returnCode"];
                $this->logger->err(json_encode($response));
                throw new \ErrorException("LINE Pay error: {$returnCode}");
            }
        }
    }

    protected function getCurrency()
    {
        if (! $this->currenciesTableGateway instanceof CurrenciesTableGateway) {
            $this->currenciesTableGateway = new CurrenciesTableGateway($this->adapter);
        }
        $currenciesWhere = new Where();
        $currenciesWhere->equalTo("is_use", 1);
        $currencyCode = "TWD";
        $currencyRow = $this->currenciesTableGateway->select($currenciesWhere)->current();
        if ($currencyRow) {
            $currencyCode = $currencyRow->code;
            if (false === array_search($currencyCode, $this->useCurrencies, true)) {
                // 沒支援就設為TWD囉
                $currencyCode = "TWD";
            }
        }
        return $currencyCode;
    }

    public function checkPaymentApi($translationId)
    {
        $requestUrl = "/v3/payments/requests/{$translationId}/check";

        $config = $this->getConfig();
        $url = $config["lineUrl"] . $requestUrl;
        $channelId = $config["channelId"];
        $channelSerect = $config["channelSecret"];
        $storeId = $config["storeId"];
        $nonce = uniqid('', true) . '-' . microtime(true);
        $signature = $this->hmacSignature($channelSerect, $requestUrl, '', $nonce);
        $header = [
            "Content-Type: application/json",
            "X-LINE-ChannelId: {$channelId}",
            "X-LINE-MerchantDeviceProfileId: {$storeId}",
            "X-LINE-Authorization-Nonce: {$nonce}",
            "X-LINE-Authorization: {$signature}",
        ];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_URL, $url);
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response, true);
        sleep(2);
        return $response;
    }

    public function refundApi($transcationId)
    {
        $requestUrl = "/v3/payments/{$transcationId}/refund";

        $config = $this->getConfig();
        $url = $config["lineUrl"] . $requestUrl;
        $channelId = $config["channelId"];
        $channelSerect = $config["channelSecret"];
        $storeId = $config["storeId"];
        $nonce = uniqid('', true) . '-' . microtime(true);
        $signature = $this->hmacSignature($channelSerect, $requestUrl, '', $nonce);
        $header = [
            "Content-Type: application/json",
            "X-LINE-ChannelId: {$channelId}",
            "X-LINE-Authorization-Nonce: {$nonce}",
            "X-LINE-Authorization: {$signature}",
            "X-LINE-MerchantDeviceProfileId: {$storeId}",
        ];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        $response = curl_exec($curl);
        $this->logger->info("refund: {$response}");
        curl_close($curl);
        $response = json_decode($response, true);
        if ($response["returnCode"] == "0000") {
            $orderTableGateway = new OrderTableGateway($this->adapter);
            $status = "cancel_the_deal";
            $index = array_search($status, $orderTableGateway->reverse_status, true);
            $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
            $orderParamsRow = $orderParamsTableGateway->select(["merchant_trade_no" => $transcationId])->current();
            $orderTableGateway->update([
                "status" => -($index),
            ], ["id" => $orderParamsRow->order_id]);
        }
        return $response;
    }
    public function confirmApi(ServerRequestInterface $request)
    {
        $queryParams = $request->getQueryParams();
        $transactionId = $queryParams["transactionId"];
        $orderId = $queryParams["orderId"];
        $orderTableGateway = new OrderTableGateway($this->adapter);
        $where = new Where();
        $where->isNull("deleted_at");
        $where->equalTo("serial", $orderId);
        $row = $orderTableGateway->select($where)->current();
        if (! $row) {
            $this->logger->err(json_decode($queryParams));
            echo "Error";
            exit();
            // throw new \ErrorException("");
        }
        $languageId = $request->getAttribute('language_id');
        $localeId = $request->getAttribute('locale_id');
        $config = $this->getConfig($languageId, $localeId);
        $channelId = $config["channelId"];
        $channelSerect = $config["channelSecret"];
        $storeId = $config["storeId"];
        $requestUrl = "/v3/payments/{$transactionId}/confirm";
        $url = $config["lineUrl"] . $requestUrl;
        $nonce = uniqid('', true) . '-' . microtime(true);

        $requestBody = json_encode([
            "amount" => floatval($row->total),
            "currency" => $this->getCurrency(),
        ]);
        $nonce = uniqid('', true) . '-' . microtime(true);
        $signature = $this->hmacSignature($channelSerect, $requestUrl, $requestBody, $nonce);
        $header = [
            "Content-Type: application/json",
            "X-LINE-ChannelId: {$channelId}",
            "X-LINE-Authorization-Nonce: {$nonce}",
            "X-LINE-Authorization: {$signature}",
            "X-LINE-MerchantDeviceProfileId: {$storeId}"
        ];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $requestBody);
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response, true);
        if ($response["returnCode"] != "0000") {
            $this->logger->err(json_encode($response));
        } else {
            $this->logger->info(json_encode($response, JSON_UNESCAPED_UNICODE));
            $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
            $orderParamsTableGateway->insert([
                "order_id" => $row->id,
                "name" => "linepay_success",
                //"merchant_trade_no" => $response["info"]["transactionId"],
                "csvcom_params" => json_encode($response, JSON_UNESCAPED_UNICODE),
            ]);
        }
        return $response;
    }
}
