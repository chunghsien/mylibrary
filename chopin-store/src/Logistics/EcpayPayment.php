<?php

declare(strict_types=1);

namespace Chopin\Store\Logistics;

use Chopin\Store\TableGateway\PaymentTableGateway;
use Laminas\Db\Adapter\Adapter;
use Chopin\LanguageHasLocale\TableGateway\CurrenciesTableGateway;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Log\Logger;
use Chopin\LanguageHasLocale\TableGateway\LanguageHasLocaleTableGateway;
use Chopin\Store\TableGateway\LogisticsGlobalTableGateway;
use Laminas\I18n\Translator\Translator;
use Ecpay\Sdk\Services\UrlService;
use Ecpay\Sdk\Factories\Factory;
use Ecpay\Sdk\Services\HtmlService;
use Ecpay\Sdk\Services\CheckMacValueService;
use Chopin\Store\TableGateway\OrderTableGateway;
use Ecpay\Sdk\Services\PostService;
use Chopin\Store\TableGateway\OrderParamsTableGateway;
use Chopin\Users\TableGateway\MemberTableGateway;
use Chopin\Store\TableGateway\OrderDetailTableGateway;
use Chopin\Store\TableGateway\LogisticsServiceTableGateway;
use Chopin\SystemSettings\TableGateway\SystemSettingsTableGateway;
use Laminas\Db\RowGateway\AbstractRowGateway;
use Chopin\Support\Registry;
use function PHPUnit\Framework\throwException;

class EcpayPayment extends AbstractPayment
{
    /**
     *
     * @deprecated
     * @var string
     */
    public const TABLE_USE_NAME = "Ecpay All In One";

    /**
     *
     * @deprecated
     * @var string
     */
    public const TABLE_USE_CODE = "ecpay";

    public const PAYMENT_SERIAL_PREFIX = "Ep";

    public const INVOICE_SERIAL_PREFIX = "In";

    /**
     *
     * @var Adapter
     */
    protected $adapter;

    protected $carrierTypeMapper = [
        // 0無載具
        '',
        // 1綠界電子發票載具
        'ecpay_carrier',
        // 2自然人憑證載具
        'citizen_digital_certificate',
        // 3手機載具
        'mobile_carrier'
    ];

    protected $responseMapper = [
        // 發票號碼
        "IIS_Number" => OrderTableGateway::INVOICE_NUMBER,
        // 買方統編
        "IIS_Identifier" => OrderTableGateway::INVOICE_IDENTIFIER,
        // 發票抬頭
        "IIS_Customer_Name" => OrderTableGateway::INVOICE_TITLE,
        // 發票住址
        "IIS_Customer_Addr" => OrderTableGateway::INVOICE_ADDRESS,
        // 發票電話
        "IIS_Customer_Phone" => OrderTableGateway::INVOICE_PHONE,
        // 載具類別
        "IIS_Carrier_Type" => OrderTableGateway::INVOICE_CARRIER_TYPE,
        // 載具編號
        "IIS_Carrier_Num" => OrderTableGateway::INVOICE_CARRIER_NUMBER,
        // 愛心捐贈碼
        "IIS_Love_Code" => OrderTableGateway::INVOICE_LOVE_CODE,
        // 發票開立日期
        "IIS_Create_Date" => OrderTableGateway::INVOICE_CREATED
    ];

    /**
     *
     * @param array $invoiceParams
     * @return boolean|array
     */
    public function buildInvoiceResponse($invoiceParams)
    {
        if (isset($invoiceParams["IIS_Number"])) {
            $finaleResult = [];
            foreach ($this->responseMapper as $oKey => $tKey) {
                if (empty($invoiceParams[$oKey])) {
                    continue;
                }
                $value = $invoiceParams[$oKey];
                if ($tKey == OrderTableGateway::INVOICE_CARRIER_TYPE) {
                    $mapperIndex = intval($value);
                    $value = $this->carrierTypeMapper[$mapperIndex];
                }
                if ($tKey == OrderTableGateway::INVOICE_LOVE_CODE && $value == '0') {
                    continue;
                }
                $finaleResult[$tKey] = $value;
            }

            return $finaleResult;
        }
        return false;
    }

    /**
     *
     * @var PaymentTableGateway
     */
    protected $paymentTableGateway;

    /**
     *
     * @var LogisticsGlobalTableGateway
     */
    protected $logisticsGlobalTableGateway;

    /**
     *
     * @var OrderParamsTableGateway
     */
    protected $orderParamsTableGateway;

    /**
     *
     * @var OrderTableGateway
     */
    protected $orderTableGateway;

    /**
     *
     * @var CurrenciesTableGateway
     */
    protected $currenciesTableGateway;

    protected $systemSettings;

    /**
     *
     * @var AbstractRowGateway
     */
    protected $logisticsGlobalRow = null;

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->paymentTableGateway = new PaymentTableGateway($this->adapter);
        $this->logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($this->adapter);
        $this->orderTableGateway = new OrderTableGateway($this->adapter);
        $this->orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
        $pageConfig = \Chopin\Support\Registry::get('page_json_config');
        $ecpayConfig = [];
        if (! $pageConfig) {
            $systemSettingsTableGateway = new SystemSettingsTableGateway($this->adapter);
            $toSerialize = $systemSettingsTableGateway->toSerialize();
            if (isset($toSerialize["ecpay"])) {
                $ecpayConfig = $toSerialize["ecpay"]["to_config"]["ecpay-config"];
            }
        } else {
            if (isset($pageConfig["system_settings"]["ecpay"])) {
                $ecpayConfig = $pageConfig["system_settings"]["ecpay"]["children"]["ecpay-config"]["value"];
            }
        }
        if ($ecpayConfig) {
            $this->systemSettings = [
                "localization" => $pageConfig["system_settings"]["localization"],
                "site_info" => $pageConfig["system_settings"]["site_info"],
                "ecpay" => $ecpayConfig
            ];
            $date = date("Ym");
            $this->logger = new Logger([
                'writers' => [
                    [
                        'name' => \Laminas\Log\Writer\Stream::class,
                        'priority' => 1,
                        'options' => [
                            'mod' => 'a+',
                            'stream' => "storage/logs/ecpay_log_{$date}.log"
                        ]
                    ]
                ]
            ]);
        }
    }

    /**
     *
     * @param string $testEnv
     * @return array
     */
    public function getDefaultParamsTemplate()
    {
        return [
            "serviceNamespace" => "Chopin\Store\Logistics\EcpayPayment",
            "payment" => [
                "hashKey" => "",
                "hashIv" => "",
                "merchantID" => "",
                // 產生訂單API網址
                "aioCheckoutUri" => "https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5",
                // 查詢訂單API網址
                "queryTradeInfoUri" => "https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5",
                // 查詢信用卡單筆明細記錄，測試環境， 因無法提供實際授權，故無法使用此 API
                "creditDetailQueryTradeUri" => "https://payment.ecpay.com.tw/CreditDetail/QueryTrade/V2",
                // 查詢ATM/CVS/BARCODE取號結果
                "queryPaymentInfoUri" => "https://payment.ecpay.com.tw/Cashier/QueryPaymentInfo",
                // 信用卡請退款功能，測試環境， 因無法提供實際授權，故無法使用此 API
                "creditDetailDoActionUri" => "https://payment.ecpay.com.tw/CreditDetail/DoAction",
                // 信用卡定期定額訂單作業
                "creditCardPeriodActionUri" => "https://payment.ecpay.com.tw/Cashier/CreditCardPeriodAction",
                // 信用卡定期定額訂單查詢
                "creditCardPeriodInfoUri" => "https://payment.ecpay.com.tw/Cashier/CreditCardPeriodInfo",
                // 下載特店對帳媒體檔
                "paymentMediaTradeNoAio" => "https://vendor.ecpay.com.tw/PaymentMedia/TradeNoAio",
                // 下載信用卡撥款對帳資料檔，測試環境，因無法提供實際授權，故無法使用此 API
                "fundingReconDetail" => "https://payment.ecPay.com.tw/CreditDetail/FundingReconDetail"
            ],
            "logistics" => [
                // 若無申請物流功能，正式上線時hashKey,hashIv及merchantID留空即可
                "hashKey" => "",
                "hashIv" => "",
                "merchantID" => "",
                // (B2C)測試標籤資料產生
                "createTestDataUri" => "https://logistics.ecpay.com.tw/Express/CreateTestData",
                // 物流訂單產生 I(B2C/C2C)
                "mapUri" => "https://logistics.ecpay.com.tw/Express/map",
                // 門市訂單建立
                "CreateStoreUri" => "https://logistics.ecpay.com.tw/Express/Create",
                // 物流訂單產生 II(宅配) -宅配訂單建立
                "CreateAddressUri" => "https://logistics.ecpay.com.tw/Express/Create",
                // 列印託運單(C2C) 7-ELEVEN
                "printUniMartC2COrderInfoUri" => "https://logistics.ecpay.com.tw/Express/PrintUniMartC2COrderInfo",
                // 列印託運單(C2C) 全家
                "printFAMIC2COrderInfoUri" => "https://logistics.ecpay.com.tw/Express/PrintFAMIC2COrderInfo",
                // 列印託運單(C2C) 萊爾富
                "printHILIFEC2COrderInfoUri" => "https://logistics.ecpay.com.tw/Express/PrintHILIFEC2COrderInfo",
                // 列印託運單(C2C) OK 超商
                "printOKMARTC2COrderInfoUri" => "https://logistics.ecpay.com.tw/Express/PrintOKMARTC2COrderInfo",
                // (B2C 含測標)、宅配
                "printTradeDocumentUri" => "https://logistics.ecpay.com.tw/helper/printTradeDocumen",
                // 逆物流訂單產生 I (7-11)(B2C 超商逆物流，暫不提供萊爾富逆物流訂單 API)
                "returnUniMartCVS" => "https://logistics.ecpay.com.tw/express/ReturnUniMartCVS",
                // 逆物流訂單產生 I (全家)
                "returnCVSUri" => "https://logistics.ecpay.com.tw/express/ReturnCVS",
                // 逆物流訂單產生 I (宅配)
                "returnHomeUri" => "https://logistics.ecpay.com.tw/Express/ReturnHome",
                // 物流訂單查詢
                "queryLogisticsTradeInfoUri" => "https://logistics.ecpay.com.tw/Helper/QueryLogisticsTradeInfo/V3",
                // 物流訂單異動
                "updateShipmentInfoUri" => "https://logistics.ecpay.com.tw/Helper/UpdateShipmentInfo",
                // (C2C)7-ELEVEN、全家、OK - 更新門市
                "updateStoreInfoUri" => "https://logistics.ecpay.com.tw/Express/UpdateStoreInfo",
                // 取消訂單(7-ELEVEN 超商 C2C)
                "cancelC2COrderUri" => "https://logistics.ecpay.com.tw/Express/CancelC2COrder"
            ],

            "invoiceParams" => [
                // 若無申請發票功能，正式上線時hashKey,hashIv及merchantID留空即可
                "hashKey" => "ejCk326UnaZWKisg",
                "hashIv" => "q9jcZX8Ib9LM8wYk",
                "merchantID" => "",
                // 查詢財政部配號結果
                "getGovInvoiceWordSettingUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/GetGovInvoiceWordSetting",
                // 字軌與配號設定
                "addInvoiceWordSettingUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/AddInvoiceWordSetting",
                // 設定字軌號碼狀態
                "updateInvoiceWordStatusUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/UpdateInvoiceWordStatus",
                // 開立發票
                "issueUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/Issue",
                // 延遲開立發票(預約開立發票)
                "delayIssueUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/DelayIssue",
                // 觸發開立發票
                "triggerIssueUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/TriggerIssue",
                // 取消延遲開立發票
                "cancelDelayIssueUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/CancelDelayIssue",
                // 開立折讓
                "allowanceUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/Allowance",
                // 線上開立折讓(通知開立)
                "allowanceByCollegiateUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/AllowanceByCollegiate",
                // 作廢發票
                "invalidUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/Invalid",
                // 作廢折讓
                "allowanceInvalidUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/AllowanceInvalid",
                // 取消線上折讓
                "allowanceInvalidByCollegiateUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/AllowanceInvalidByCollegiate",
                // 註銷重開
                "voidWithReIssueUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/VoidWithReIssue",
                // 查詢發票明細
                "getIssueUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue",
                // 查詢折讓明細
                "getAllowanceList" => "https://einvoice.ecpay.com.tw/B2CInvoice/GetAllowanceLis",
                // 查詢作廢發票明細
                "getInvalidUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/GetInvalid",
                // 查詢作廢折讓明細
                "getAllowanceInvalidUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/GetAllowanceInvalid",
                // 查詢字軌
                "getInvoiceWordSettingUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/GetInvoiceWordSetting",
                // 發送發票通知
                "invoiceNotifyUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/InvoiceNotify",
                // 發票列印
                "invoicePrint" => "https://einvoice.ecpay.com.tw/B2CInvoice/InvoicePrint",
                // 手機條碼驗證
                "checkBarcodeUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/CheckBarcode",
                // 捐贈碼驗證
                "checkLoveCodeUri" => "https://einvoice.ecpay.com.tw/B2CInvoice/CheckLoveCode"
            ]
        ];
    }

    public function addDataToPaymentTable($language_id = 119, $locale_id = 229)
    {
        $systemSettingsTableGateway = new SystemSettingsTableGateway($this->adapter);
        $systemSettingsRow = $systemSettingsTableGateway->select([
            "key" => "ecpay-config"
        ])->current();
        $config = json_encode($this->getDefaultParamsTemplate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $systemSettingsTableGateway->update([
            "aes_value" => $config
        ], [
            "id" => $systemSettingsRow->id
        ]);
        $paymentTableGateway = $this->paymentTableGateway;
        $verifyResultset = $paymentTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "All in one 付款",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $paymentTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "name" => "All in one 付款",
                "manufacturer" => "ecpay",
                "code" => "ecpay-all-in-one",
                "is_use" => 1,
                "extra_params" => json_encode([
                    "is_collection" => "N"
                ])
            ]);
        }
        $verifyResultset = $paymentTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "全家取貨付款",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $paymentTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "name" => "全家取貨付款",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "is_collection" => "Y",
                    "logistics_type" => "CVS",
                    "logistics_sub_type" => "FAMI"
                ]),
                "code" => "ecpay-cvs-fami",
                "is_use" => 1
            ]);
        }
        $verifyResultset = $paymentTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "7-ELEVEN取貨付款",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $paymentTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "name" => "7-ELEVEN取貨付款",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "is_collection" => "Y",
                    "logistics_type" => "CVS",
                    "logistics_sub_type" => "UNIMART"
                ]),
                "code" => "ecpay-cvs-unimart",
                "is_use" => 1
            ]);
        }

        $verifyResultset = $paymentTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "7-ELEVEN冷凍店取付款",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $paymentTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "name" => "7-ELEVEN冷凍店取付款",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "is_collection" => "Y",
                    "logistics_type" => "CVS",
                    "logistics_sub_type" => "UNIMARTFREEZE"
                ]),
                "code" => "ecpay-cvs-freeze",
                "is_use" => 1
            ]);
        }
    }

    public function addDataToLogisticsTable($language_id = 119, $locale_id = 229)
    {
        $logisticsGlobalTableGateway = $this->logisticsGlobalTableGateway;
        $verifyResultset = $logisticsGlobalTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "黑貓常溫宅急便",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $logisticsGlobalTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "method" => "shipping",
                "name" => "黑貓常溫宅急便",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "logistics_type" => "HOME",
                    "logistics_sub_type" => "TCAT",
                    "temperature" => "001"
                ]),
                "code" => "ecpay-home-tcat-001",
                "is_use" => 1,
                // 以最低價為基準
                "price" => 130
            ]);
        }
        $verifyResultset = $logisticsGlobalTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "黑貓低溫(冷藏)宅急便",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $logisticsGlobalTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "method" => "shipping",
                "name" => "黑貓低溫(冷藏)宅急便",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "logistics_type" => "HOME",
                    "logistics_sub_type" => "TCAT",
                    "temperature" => "002"
                ]),
                "code" => "ecpay-home-tcat-002",
                "is_use" => 1,
                // 以最低價為基準
                "price" => 160
            ]);
        }
        $verifyResultset = $logisticsGlobalTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "黑貓低溫(冷凍)宅急便",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $logisticsGlobalTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "method" => "shipping",
                "name" => "黑貓低溫(冷凍)宅急便",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "logistics_type" => "HOME",
                    "logistics_sub_type" => "TCAT",
                    "temperature" => "003"
                ]),
                "code" => "ecpay-home-tcat-003",
                "is_use" => 1,
                // 以最低價為基準
                "price" => 160
            ]);
        }

        $verifyResultset = $logisticsGlobalTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "大嘴鳥宅配通",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $logisticsGlobalTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "method" => "shipping",
                "name" => "宅配通",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "logistics_type" => "HOME",
                    "logistics_sub_type" => "ECAN",
                    "temperature" => "001"
                ]),
                "code" => "ecpay-home-ecan",
                "is_use" => 1,
                "price" => 125
            ]);
        }

        $verifyResultset = $logisticsGlobalTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "全家超商取貨",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $logisticsGlobalTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "method" => "shipping",
                "name" => "全家超商取貨",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "is_collection" => "N",
                    "logistics_type" => "CVS",
                    "logistics_sub_type" => "FAMI"
                ]),
                "code" => "ecpay-cvs-fami",
                "is_use" => 1,
                "price" => 60
            ]);
        }

        $verifyResultset = $logisticsGlobalTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "7-ELEVEN超商取貨",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $logisticsGlobalTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "method" => "shipping",
                "name" => "7-ELEVEN超商取貨",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "is_collection" => "N",
                    "logistics_type" => "CVS",
                    "logistics_sub_type" => "UNIMART"
                ]),
                "code" => "ecpay-cvs-unimart",
                "is_use" => 1,
                "price" => 60
            ]);
        }
        $verifyResultset = $logisticsGlobalTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id,
            "name" => "7-ELEVEN冷凍店取",
            "manufacturer" => "ecpay"
        ]);
        if ($verifyResultset->count() == 0) {
            $logisticsGlobalTableGateway->insert([
                "language_id" => $language_id,
                "locale_id" => $locale_id,
                "method" => "shipping",
                "name" => "7-ELEVEN冷凍店取",
                "manufacturer" => "ecpay",
                "extra_params" => json_encode([
                    "is_collection" => "N",
                    "logistics_type" => "CVS",
                    "logistics_sub_type" => "UNIMARTFREEZE",
                    "is_freeze_ship" => true
                ]),
                "code" => "ecpay-cvs-freeze",
                "is_use" => 1,
                "price" => 160
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
        $where->like("code", "ecpay-%");
        $resultset = $this->paymentTableGateway->select($where);
        if ($resultset->count() > 0) {
            $config = $this->systemSettings["ecpay"];
            $config = str_replace('Chopin\\Store\\Logistics\\', 'Chopin\\\\Store\\\\Logistics\\\\', $config);
            $config = json_decode($config, true);
            if (APP_ENV === 'development') {
                if (! $this->devConfig) {
                    $this->devConfig = config('third_party_service.logistics.ecpay');
                }
                $config = array_merge($config, $this->devConfig);
            }
            $this->config = $config;
            return $this->config;
        }
        return [];
    }

    /**
     *
     * @param string $type
     * @param int $language_id
     * @param int $locale_id
     * @return boolean|array
     */
    public function getSecurityKey($type, $language_id, $locale_id)
    {
        $config = $this->getConfig();
        if (strlen($config[$type]["hashKey"]) == 0 || strlen($config[$type]["hashIv"]) == 0) {
            return false;
        }
        return [
            'hashKey' => $config[$type]["hashKey"],
            'hashIv' => $config[$type]["hashIv"]
        ];
    }

    public function checkInvoiceIssue($orderData): string
    {
        if (! $this->orderParamsTableGateway instanceof OrderParamsTableGateway) {
            $this->orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
        }
        $orderParamsTableGateway = $this->orderParamsTableGateway;
        if (! $this->orderTableGateway instanceof OrderTableGateway) {
            $this->orderTableGateway = new OrderTableGateway($this->adapter);
        }
        $orderTableGateway = $this->orderTableGateway;
        $orderSerial = $orderData["serial"];
        $language_id = $orderData["language_id"];
        $locale_id = $orderData["locale_id"];
        $ecpayInvoiceServiceSecurityKey = $this->getSecurityKey('invoiceParams', $language_id, $locale_id);
        $ecpayConfig = $this->getConfig($language_id, $locale_id);
        $config = $this->getConfig($language_id, $locale_id);
        $merchantID = $config["invoiceParams"]["merchantID"];
        if ($ecpayInvoiceServiceSecurityKey) {
            $relateNumber = preg_replace('/^[a-z|A-Z]{2}/', self::INVOICE_SERIAL_PREFIX, $orderSerial);
            $ecpayInvoiceServiceFactory = new Factory($ecpayInvoiceServiceSecurityKey);
            $getInvoiceIssueService = $ecpayInvoiceServiceFactory->create('PostWithAesJsonResponseService');
            $data = [
                'MerchantID' => $merchantID,
                "RelateNumber" => $relateNumber
            ];
            $input = [
                'MerchantID' => $merchantID,
                'RqHeader' => [
                    'Timestamp' => time(),
                    'Revision' => '3.0.0'
                ],
                'Data' => $data
            ];
            $url = $ecpayConfig["invoiceParams"]["getIssueUri"];
            $getInvoiceIssueResponse = $getInvoiceIssueService->post($input, $url);
            $getInvoiceIssueData = $getInvoiceIssueResponse["Data"];
            if ($getInvoiceIssueData["RtnCode"] == 1) {
                $csvcom = json_encode($getInvoiceIssueData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $orderParamsCount = $orderParamsTableGateway->select([
                    "order_id" => $orderData["id"],
                    "name" => "invoice_params"
                ])->count();
                if ($orderParamsCount == 0 || ! $orderData["invoice_no"]) {
                    $this->logger->info("Invocie issue data: " . $csvcom);
                    $orderParamsTableGateway->insert([
                        "order_id" => $orderData["id"],
                        "name" => "invoice_query",
                        "csvcom_params" => $csvcom
                    ]);
                    $set = [];
                    if (! $orderData["invoice_no"]) {
                        $set = [
                            "invoice_no" => $getInvoiceIssueData["IIS_Number"]
                        ];
                    }
                    if ($getInvoiceIssueData["IIS_Identifier"] != "0000000000") {
                        $set["business_no"] = $getInvoiceIssueData["IIS_Identifier"];
                        $set["business_title"] = $getInvoiceIssueData["IIS_Customer_Name"];
                    }
                    $where = [
                        "id" => $orderData["id"]
                    ];
                    $orderTableGateway->update($set, $where);
                }
                return "<info>" . $getInvoiceIssueData["RtnMsg"] . "</info>";
            } else {
                if ($getInvoiceIssueData["RtnCode"] == 2) {
                    $orderData = $orderTableGateway->deCryptData($orderData);
                    // 開立發票
                    $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
                    $orderParamsRow = $orderParamsTableGateway->select([
                        "order_id" => $orderData["id"],
                        "name" => "post_params"
                    ])->current();
                    if ($orderParamsRow) {
                        $memberTableGateway = new MemberTableGateway($this->adapter);
                        $member = $memberTableGateway->getMember($orderData["member_id"]);
                        $csvcom = json_decode($orderParamsRow->csvcom_params, true);
                        $data = [
                            'MerchantID' => $merchantID,
                            "RelateNumber" => $relateNumber,
                            "CustomerPhone" => $member->cellphone,
                            "Print" => "0",
                            "Donation" => "0",
                            // 預設為無載具
                            "CarrierType" => "",
                            "TaxType" => "1",
                            "SalesAmount" => intval($orderData["total"]),
                            // 字軌類別, 07 一般稅額, 08 : 特種稅額
                            "InvType" => "07"
                        ];

                        $logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($this->adapter);
                        $logisticsGlobalRow = $logisticsGlobalTableGateway->select([
                            "id" => $orderData["logistics_global_id"]
                        ])->current();

                        // 海外寄送，實際有做到在來詳寫
                        if ($logisticsGlobalRow->is_overseas_delivery == 1) {
                            // $data["ClearanceMark"] = '2';
                            // $data["TaxType"] = '2';
                        }
                        // 載具
                        if (isset($csvcom["carrier_type"])) {
                            switch ($csvcom["carrier_type"]) {
                                case 'ecpay_carrier':
                                    $data["CarrierType"] = "1";
                                    break;
                                case 'citizen_digital_certificate':
                                    $data["CarrierType"] = "2";
                                    break;
                                case 'mobile_carrier':
                                    $data["CarrierType"] = "3";
                                    break;
                            }
                            // 載具號碼
                            if (isset($csvcom["carrier"])) {
                                $data["CarrierNum"] = $csvcom["carrier"];
                            }
                        }

                        // 愛心捐贈碼
                        if (isset($csvcom["donate_organization"])) {
                            $data["Donation"] = "1";
                            $data["LoveCode"] = $csvcom["donate_organization"];
                        }

                        // 打統編
                        if (isset($csvcom["invoice_tax_id"])) {
                            // invoice_addr
                            if (isset($csvcom["invoice_addr"])) {
                                $data["CustomerAddr"] = $csvcom["invoice_addr"];
                            } else {
                                $data["CustomerAddr"] = $member->zip . $member->county . $member->district . $member->address;
                            }
                            $data["CustomerIdentifier"] = $csvcom["invoice_tax_id"];
                            $data["CustomerName"] = $csvcom["invoice_title"];
                            $data["Print"] = "1";
                        }
                        $orderDetailTableGateway = new OrderDetailTableGateway($this->adapter);
                        $where = new Where();
                        $where->isNull("deleted_at");
                        $where->equalTo("order_id", $orderData["id"]);
                        $orderDetailResultset = $orderDetailTableGateway->select($where)/*->toArray()*/;
                        $items = [];
                        foreach ($orderDetailResultset as $row) {
                            $itemWords = [];
                            for ($i = 1; $i <= 5; $i ++) {
                                $key = "option{$i}_name";
                                if ($row->{$key}) {
                                    $itemWords[] = $row->{$key};
                                }
                            }
                            $itemWord = implode('/', $itemWords);
                            $itemWord = mb_strcut($itemWord, 0, 6, "UTF-8");
                            $item = [
                                'ItemName' => $row->model,
                                'ItemCount' => $row->quantity,
                                'ItemWord' => $itemWord,
                                'ItemPrice' => floatval($row->price),
                                'ItemTaxType' => "1",
                                'ItemAmount' => floatval($row->subtotal)
                            ];
                            $items[] = $item;
                        }
                        unset($orderDetailResultset);
                        if (floatval($orderData["trad_fee"]) > 0) {
                            $items[] = [
                                'ItemName' => "運費",
                                'ItemCount' => 1,
                                'ItemWord' => "式",
                                'ItemPrice' => floatval($orderData["trad_fee"]),
                                'ItemTaxType' => "1",
                                'ItemAmount' => floatval($orderData["trad_fee"])
                            ];
                        }
                        if (floatval($orderData["discount"]) > 0) {
                            $items[] = [
                                'ItemName' => "折扣",
                                'ItemCount' => 1,
                                'ItemWord' => "式",
                                'ItemPrice' => - floatval($orderData["discount"]),
                                'ItemTaxType' => "1",
                                'ItemAmount' => - floatval($orderData["discount"])
                            ];
                        }

                        $data["Items"] = $items;
                        $input = [
                            'MerchantID' => $merchantID,
                            'RqHeader' => [
                                'Timestamp' => time(),
                                'Revision' => '3.0.0'
                            ],
                            'Data' => $data
                        ];
                        $url = $ecpayConfig["invoiceParams"]["issueUri"];
                        $response = $getInvoiceIssueService->post($input, $url);
                        if ($response["Data"]["RtnCode"] == 1) {
                            $csvcom = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $this->logger->info("Invocie issue data: " . $csvcom);
                            $orderParamsTableGateway->insert([
                                "order_id" => $orderData["id"],
                                "name" => "invoice_create",
                                "csvcom_params" => $csvcom
                            ]);
                            $set = [
                                "invoice_no" => $response["Data"]["InvoiceNo"]
                            ];
                            if (isset($data["CustomerIdentifier"])) {
                                $set["business_no"] = $data["CustomerIdentifier"];
                                $set["business_title"] = $data["CustomerName"];
                            }
                            $where = [
                                "id" => $orderData["id"]
                            ];
                            $orderTableGateway->update($set, $where);
                            return "<info>" . $response["Data"]["RtnMsg"] . "</info>";
                        // return $response["Data"]["RtnMsg"];
                        } else {
                            $this->logger->err("{$relateNumber} Invocie issue fail params: " . json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                            $this->logger->err("{$relateNumber} Invocie issue fail: " . json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                            return "<error>" . $response["Data"]["RtnMsg"] . "</error>";

                            // return $response["Data"]["RtnMsg"];
                        }
                    } else {
                        $this->logger->err("{$relateNumber} Invocie issue fail: " . json_encode($getInvoiceIssueData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        return "<error>" . $getInvoiceIssueData["Data"]["RtnMsg"] . "</error>";
                    }
                } else {
                    $this->logger->err("{$relateNumber} Invocie issue fail: " . json_encode($getInvoiceIssueData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    return "<error>" . $getInvoiceIssueData["Data"]["RtnMsg"] . "</error>";
                }
            }
        }
        return '';
    }

    /**
     * * 重新確認該筆交易資料
     *
     * @param array $orderData
     */
    public function reCheckTradInfo($orderData): void
    {
        $orderSerial = $orderData["serial"];
        $language_id = $orderData["language_id"];
        $locale_id = $orderData["locale_id"];
        $merchantTradeNo = preg_replace('/^[a-z|A-Z]{2}/', self::PAYMENT_SERIAL_PREFIX, $orderSerial);
        $config = $this->getConfig($language_id, $locale_id);
        $securityKey = $this->getSecurityKey('payment', $language_id, $locale_id);

        $factory = new Factory($securityKey);
        /**
         *
         * @var PostService $postService
         */
        $postService = $factory->create('PostWithCmvVerifiedEncodedStrResponseService');
        $input = [
            'MerchantID' => $config["payment"]["merchantID"],
            'MerchantTradeNo' => $merchantTradeNo,
            "EncryptType" => 1,
            'TimeStamp' => time()
        ];
        $url = $config["payment"]["queryTradeInfoUri"];
        $response = $postService->post($input, $url);
        if (! $this->orderTableGateway instanceof OrderTableGateway) {
            $this->orderTableGateway = new OrderTableGateway($this->adapter);
        }
        $orderTableGateway = $this->orderTableGateway;
        $where = [
            "id" => $orderData["id"]
        ];
        if (isset($response["TradeStatus"]) && $response["TradeStatus"] == '0') {
            // 交易訂單成立未付款
            $orderTableGateway->update([
                "status" => 0
            ], $where);
        }
        if (isset($response["TradeStatus"]) && $response["TradeStatus"] == '1') {
            // 代表交易訂單成立已付款
            $set = [
                "status" => array_search('order_paid', $orderTableGateway->status, true)
            ];
            $orderTableGateway->update($set, $where);
        }
        if (isset($response["TradeStatus"]) && $response["TradeStatus"] == '10200095') {
            // 消費者未完成付款作業，交易失敗。
            $set = [
                "status" => array_search('order_paid fail', $orderTableGateway->reverse_status, true)
            ];
            $orderTableGateway->update($set, $where);
        }
    }

    public function testData(ServerRequestInterface $request, $orderData)
    {
        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $config = $this->getConfig($languageId, $localeId);
        $merchantID = $config["logistics"]["merchantID"];
        $post = $request->getParsedBody();
        // 產生測試訂單
        $testDataInput = [
            'MerchantID' => $merchantID,
            'LogisticsSubType' => $post["logistics_sub_type"]
        ];
        $testDataAction = $config["logistics"]["createTestDataUri"];
        $testDataResponse = $postService->post($testDataInput, $testDataAction);
        $this->logger->info("Test data response: " . json_encode($testDataResponse, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 超商取貨(付款)
     *
     * @param ServerRequestInterface $request
     * @param array $orderData
     */
    protected function requestCvs(ServerRequestInterface $request, $orderData, $isCollection = "Y")
    {
        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $config = $this->getConfig($languageId, $localeId);
        $post = $request->getParsedBody();
        if (! $post) {
            $content = json_decode($request->getBody()->getContents(), true);
            $post = $content;
        }
        $factory = new Factory([
            'hashKey' => $config["logistics"]["hashKey"],
            'hashIv' => $config["logistics"]["hashIv"],
            "hashMethod" => "md5"
        ]);
        $merchantID = $config["logistics"]["merchantID"];
        /**
         *
         * @var PostService $autoSubmitFormService
         */
        $postService = $factory->create('PostWithCmvEncodedStrResponseService');

        $orderParamsTableGateway = $this->orderParamsTableGateway;

        // $merchantTradeNo = preg_replace('/^([A-Z|a-z]{2})/', '', $orderData["serial"]);
        if (intval($orderData["total"]) > 20000 || intval($orderData["total"]) < 1) {
            throw new \ErrorException('ecpay-error-10500040');
        }
        $localeCode = $this->getLocaleCode($orderData);
        $translator = new Translator();
        $translator->setLocale($localeCode);
        $filename = './resources/languages/' . $localeCode . "/site-translation.php";
        $translator->addTranslationFile('phpArray', $filename);
        $modelAndCombinations = $this->getModelAndCombinations($orderData);
        $tradeDesc = $translator->translate('ecpay-trad-desc');
        $tradeDesc = str_replace('%modelAndCombinations%', $modelAndCombinations, $tradeDesc);
        $productsCount = count($orderData["details"]);
        $tradeDesc = str_replace('%productsCount%', $productsCount, $tradeDesc);
        $logisticsServiceTableGateway = new LogisticsServiceTableGateway($this->adapter);
        $logisticsServiceRow = $logisticsServiceTableGateway->select([
            "language_id" => $languageId,
            "locale_id" => $localeId
        ])->current();
        $urlPrefix = $this->getUrlprefix($request, $orderData);
        $serverReplyUrl = "{$urlPrefix}/ecpay-logistics";
        if (mb_strlen($tradeDesc, 'UTF-8') > 25) {
            $tmp = mb_substr($tradeDesc, 0, 22, "UTF-8");
            $tmp .= "﹒﹒﹒";
            $tradeDesc = $tmp;
        }
        $input = [
            "MerchantID" => $merchantID,
            // "MerchantTradeNo" => isset($merchant_trade_no) ? ,
            "MerchantTradeDate" => date("Y/m/d H:i:s"),
            "LogisticsType" => "CVS",
            "LogisticsSubType" => $post["logistics_sub_type"],
            "GoodsAmount" => intval($orderData["total"]),
            'GoodsName' => $tradeDesc,
            'SenderName' => $logisticsServiceRow->name,
            'SenderCellPhone' => $logisticsServiceRow->cellphone,
            'ReceiverName' => $orderData["fullname"],
            'ReceiverCellPhone' => $orderData["cellphone"],
            "IsCollection" => strtoupper($isCollection),
            "ServerReplyURL" => $serverReplyUrl,
            "ReceiverStoreID" => $post["receiver_store_id"]
        ];
        $url = $config["logistics"]["CreateStoreUri"];
        $response = $postService->post($input, $url);

        if (isset($response["RtnCode"]) && $response["RtnCode"] == "300") {
            $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
            $name = "cvs_is_collection_y";
            if ($isCollection == "N") {
                $name = "cvs_is_collection_n";
            }
            $orderParamsTableGateway->insert([
                "order_id" => $orderData["id"],
                "name" => $name,
                "merchant_trade_no" => $response["MerchantTradeNo"],
                "csvcom_params" => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
            // stock_up
            $index = array_search("stock_up", $this->orderTableGateway->status, true);
            $this->orderTableGateway->update([
                "status" => $index
            ], [
                "id" => $orderData["id"]
            ]);
            return [
                "status" => "success",
                "response" => [
                    "cvsResponse" => $response
                ],
                "message" => [
                    $response["RtnMsg"]
                ]
            ];
        } else {
            logger()->err("error: " . json_encode($response, JSON_UNESCAPED_UNICODE));
        }
        throw new \ErrorException($response["RtnMsg"]);
    }

    /**
     * 物流訂單產生
     *
     * @param ServerRequestInterface $request
     * @params array $orderData
     */
    public function logisticsExpressCreate(ServerRequestInterface $request, $orderData)
    {
        $orderData = (array) $orderData;
        $logisticsTableGateway = $this->logisticsGlobalTableGateway;
        $logisticsGlobalId = $orderData["id"];
        $row = $logisticsTableGateway->select([
            "id" => $logisticsGlobalId
        ])->current();
        if ($row instanceof AbstractRowGateway) {
            $this->logisticsGlobalRow = $row;
            if (preg_match('/^ecpay-home-/', $row->code)) {
                $this->sendOrderShip($orderData);
                // sendOrderShip
                // $this->sendHomeDeliveryOrder($orderData);
            }
            if (preg_match('/^ecpay-cvs-/', $row->code)) {
                $this->requestCvs($request, $orderData, "N");
            }
        }
    }

    protected function unableToShipBatch($orderData, $title = "")
    {
        $logger = $this->logger;
        $orderTableGateway = $this->orderTableGateway;
        if ($title == "") {
            $title = "不提供寄送： ";
        }
        $logger->err($title . json_decode($orderData, JSON_UNESCAPED_UNICODE));
        $index = array_search("unable_to_ship", $orderTableGateway->reverse_status, true);
        $index = - $index;
        $orderId = $orderData["id"];
        $orderTableGateway->update([
            "status" => $index
        ], [
            "id" => $orderId
        ]);
    }

    /**
     *
     * 宅配或超商取貨
     *
     * @param array $orderData
     */
    public function sendOrderShip($orderData)
    {
        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $config = $this->getConfig($languageId, $localeId);
        $factory = new Factory([
            'hashKey' => $config["logistics"]["hashKey"],
            'hashIv' => $config["logistics"]["hashIv"],
            "hashMethod" => "md5"
        ]);
        /**
         *
         * @var PostService $autoSubmitFormService
         */
        $postService = $factory->create('PostWithCmvEncodedStrResponseService');
        // $merchantTradeNo = preg_replace('/^([A-Z|a-z]{2})/', '', $orderData["serial"]);
        if (intval($orderData["total"]) <= 20000 || intval($orderData["total"]) >= 1) {
            $localeCode = $this->getLocaleCode($orderData);
            $translator = new Translator();
            $translator->setLocale($localeCode);
            $filename = './resources/languages/' . $localeCode . "/site-translation.php";
            $translator->addTranslationFile('phpArray', $filename);
            $modelAndCombinations = $this->getModelAndCombinations($orderData);
            $tradeDesc = $translator->translate('ecpay-trad-desc');
            $tradeDesc = str_replace('%modelAndCombinations%', $modelAndCombinations, $tradeDesc);
            $productsCount = count($orderData["details"]);
            $tradeDesc = str_replace('%productsCount%', $productsCount, $tradeDesc);
            $logisticsServiceTableGateway = new LogisticsServiceTableGateway($this->adapter);
            $logisticsServiceRow = $logisticsServiceTableGateway->select([
                "language_id" => $languageId,
                "locale_id" => $localeId
            ])->current();
            if (! Registry::get('ecpayShipServerReplyUrl')) {
                $host = file_get_contents('./storage/persists/host.json');
                $host = json_decode($host);
                $host = $host->host;
                Registry::set('ecpayShipServerReplyUrl', $host);
            }
            $serverReplyURL = Registry::get('ecpayShipServerReplyUrl');
            if (mb_strlen($tradeDesc, 'UTF-8') > 25) {
                $tmp = mb_substr($tradeDesc, 0, 22, "UTF-8");
                $tmp .= "﹒﹒﹒";
                $tradeDesc = $tmp;
            }
            $rowExtraParams = json_decode($orderData["extra_params"], true);
            $receiverFullAddress = $orderData["address"];
            $receiverFullAddress = str_replace($orderData["county"], "", $receiverFullAddress);
            $receiverFullAddress = str_replace($orderData["district"], "", $receiverFullAddress);
            $receiverFullAddress = $orderData["county"] . $orderData["district"] . $receiverFullAddress;

            $senderFullAddress = $logisticsServiceRow->address;
            $senderFullAddress = str_replace($logisticsServiceRow->county, "", $senderFullAddress);
            $senderFullAddress = str_replace($logisticsServiceRow->district, "", $senderFullAddress);
            $senderFullAddress = $logisticsServiceRow->county . $logisticsServiceRow->district . $senderFullAddress;

            $input = [
                "MerchantID" => $config["logistics"]["merchantID"],
                // "MerchantTradeNo" => self::PAYMENT_SERIAL_PREFIX . $merchantTradeNo,
                "MerchantTradeDate" => date("Y/m/d H:i:s"),
                "LogisticsType" => $rowExtraParams["logistics_type"],
                "LogisticsSubType" => $rowExtraParams["logistics_sub_type"],
                "GoodsAmount" => intval($orderData["total"]),
                'GoodsName' => $tradeDesc,
                'SenderName' => $logisticsServiceRow->name,
                'SenderCellPhone' => $logisticsServiceRow->cellphone,
                "SenderZipCode" => $logisticsServiceRow->zip,
                "SenderAddress" => $receiverFullAddress,
                'ReceiverName' => $orderData["fullname"],
                'ReceiverCellPhone' => $orderData["cellphone"],
                "ServerReplyURL" => $serverReplyURL,
                "ScheduledPickupTime" => "4",
                "Temperature" => "0001",
                "Specification" => "0001"
            ];
            if ($rowExtraParams["logistics_type"] == "HOME") {
                $input["ReceiverZipCode"] = $orderData["zip"];
                $input["ReceiverAddress"] = $receiverFullAddress;
            }
            if (intval($orderData["pack_size"]) <= 60) {
                $input["Specification"] = "0001";
            }
            if (intval($orderData["pack_size"]) > 60 && intval($orderData["pack_size"]) <= 90) {
                $input["Specification"] = "0002";
            }
            if (intval($orderData["pack_size"]) > 90 && intval($orderData["pack_size"]) <= 120) {
                $input["Specification"] = "0003";
            }
            if (intval($orderData["pack_size"]) > 120 && intval($orderData["pack_size"]) <= 150) {
                $input["Specification"] = "0004";
            }
            $orderTableGateway = $this->orderTableGateway;
            if (intval($orderData["pack_size"]) > 150) {
                // 打到大型貨物的備貨狀態
                $index = array_search("other_ship_stock_up", $orderTableGateway->status, true);
                $orderId = $orderData["id"];
                $orderTableGateway->update([
                    "status" => $index
                ], [
                    "id" => $orderId
                ]);
                return;
            }
            // 溫層另外設定
            if (isset($rowExtraParams["Temperature"])) {
                $input["Temperature"] = $rowExtraParams["Temperature"];
            }
            if ($rowExtraParams["logistics_sub_type"] == "ECAN" && $input["Temperature"] != "0001") {
                $input["Temperature"] = "0001";
            }
            if (($input["Temperature"] == "0003" || $input["Temperature"] == "0004") && $input["Specification"] == "0004" || $orderData["pack_size"] > 120) {
                // 打到大型貨物的備貨狀態
                if ($input["Temperature"] == "0003") {
                    $index = array_search("other_ship_refrigeration_stock_up", $orderTableGateway->status, true);
                }
                if ($input["Temperature"] == "0004") {
                    $index = array_search("other_ship_freezer_stock_up", $orderTableGateway->status, true);
                }
                $orderId = $orderData["id"];
                $orderTableGateway->update([
                    "status" => $index
                ], [
                    "id" => $orderId
                ]);
                return;
            }
            if ($rowExtraParams["logistics_type"] == "HOME") {
                $sendLocalCounty = $logisticsServiceRow->county;
                $sendLocalCounty = str_replace("台", "臺", $sendLocalCounty);
                $receiverCounty = $orderData["county"];
                $receiverCounty = str_replace("台", "臺", $receiverCounty);
                if ($sendLocalCounty == $receiverCounty) {
                    $input["Distance"] = "00";
                } else {
                    $offshore_islands = [
                        "澎湖縣",
                        "金門縣",
                        "連江縣"
                    ];
                    if (false !== array_search($orderData["county"], $offshore_islands, true)) {
                        // 離島
                        $input["Distance"] = "02";
                    } else {
                        // 外縣市
                        $input["Distance"] = "01";
                    }
                }
                if ($logisticsServiceRow->district != "綠島鄉" && $orderData["district"] == "綠島鄉") {
                    // 離島
                    $input["Distance"] = "02";
                }

                if (false !== array_search($orderData["district"], [
                    "望安鄉",
                    "七美鄉",
                    "烏坵鄉",
                    "蘭嶼鄉"
                ], true)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                $mb_internal_encoding = "UTF-8";
                if (false !== mb_strpos("釣魚台", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                if (false !== mb_strpos("東沙", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                if (false !== mb_strpos("南沙", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                if (false !== mb_strpos("虎井島", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                if (false !== mb_strpos("桶盤島", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                if (false !== mb_strpos("大倉嶼", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                if (false !== mb_strpos("員貝嶼", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                if (false !== mb_strpos("吉貝嶼", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                if (false !== mb_strpos("大膽島", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }
                if (false !== mb_strpos("二膽島", $receiverFullAddress, 0, $mb_internal_encoding)) {
                    $this->unableToShipBatch($orderData);
                    return;
                }

                // 釣魚台、東沙、南沙, 虎井島、桶盤島,大倉嶼、員貝嶼、鳥嶼、吉貝嶼, 大膽島、二膽島
                // 離島地區為澎湖、金門、馬祖、綠島；離島互寄係指該四地互寄。
                // 一般包裹尺寸以150公分為上限，低溫包裹尺寸以120公分為上限，每件皆不得過20公斤。
                // Temperature
                // Distance
                // Specification
            }
            $url = "";
            $name = "";
            if ($rowExtraParams["logistics_type"] == "HOME") {
                $url = $config["logistics"]["CreateAddressUri"];
                $name = "home_ship";
            }
            if ($rowExtraParams["logistics_type"] == "CVS") {
                $url = $config["logistics"]["CreateStoreUri"];
                $name = "cvs_ship";
                $orderID = $orderData["id"];
                $orderParamsRow = $this->orderParamsTableGateway->select([
                    "order_id" => $orderID,
                    "name" => "post_params"
                ])->current();
                $csvcomParams = json_decode($orderParamsRow->csvcom_params);
                $input["ReceiverStoreID"] = $csvcomParams->csvcomDetail->CVSStoreID;
            }

            $response = $postService->post($input, $url);
            if (isset($response["RtnCode"]) && $response["RtnCode"] == "300") {
                $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
                $orderParamsTableGateway->insert([
                    "order_id" => $orderData["id"],
                    "name" => $name,
                    "merchant_trade_no" => $response["MerchantTradeNo"],
                    "csvcom_params" => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);
                $status = "stock_up";
                $index = array_search($status, $this->orderTableGateway->status, true);
                $this->orderTableGateway->update([
                    "status" => $index
                ], [
                    "id" => $orderData["id"]
                ]);
                return [
                    "status" => "success",
                    "response" => [
                        "cvsResponse" => $response
                    ],
                    "message" => $response["RtnMsg"]
                ];
            } else {
                $message = array_keys($response);
                $this->logger->err("sendHomeDeliveryOrder error: " . json_encode([
                    "response" => $response,
                    "message" => $message,
                    "order" => $orderData
                ], JSON_UNESCAPED_UNICODE));
            }
            return;
            // throw new \ErrorException($response["RtnMsg"]);
        }
    }

    public function getLocaleCode($orderData)
    {
        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $languageHasLocaleTableGateway = new LanguageHasLocaleTableGateway($this->adapter);
        $languageHasLocaleRow = $languageHasLocaleTableGateway->select([
            "language_id" => $languageId,
            "locale_id" => $localeId
        ])->current();
        $localeCode = $languageHasLocaleRow->code;
        return $localeCode;
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

    protected function getModelAndCombinations(array $orderData): string
    {
        $modelAndCombinations = "";
        $orderDetailFirstProduct = $orderData["details"][0];
        $orderDetailFirstProductModel = $orderDetailFirstProduct["model"];
        $modelAndCombinations = $orderDetailFirstProductModel;
        $modelAndCombinations .= "(";
        for ($i = 1; $i <= 5; $i ++) {
            $key = "option{$i}_name";
            if ($orderDetailFirstProduct[$key]) {
                $modelAndCombinations .= $orderDetailFirstProduct[$key];
                $modelAndCombinations .= "/";
            }
        }
        $modelAndCombinations = preg_replace('/\/$/', '', $modelAndCombinations);
        $modelAndCombinations .= ")";
        return $modelAndCombinations;
    }

    /**
     *
     * {@inheritdoc}
     * @see \Chopin\Store\Logistics\AbstractPayment::requestApi()
     */
    public function requestApi(ServerRequestInterface $request, $orderData)
    {
        $paymentId = $orderData["payment_id"];
        $paymentTableGateway = new PaymentTableGateway($this->adapter);
        $paymentRow = $paymentTableGateway->select([
            "id" => $paymentId
        ])->current();
        $paymentPxtraParams = null;
        if ($paymentRow->extra_params) {
            $paymentPxtraParams = json_decode($paymentRow->extra_params, true);
        }

        // 超商取貨付款
        if ($paymentPxtraParams && isset($paymentPxtraParams["is_collection"]) && $paymentPxtraParams["is_collection"] == "Y" && isset($paymentPxtraParams["logistics_type"]) && $paymentPxtraParams["logistics_type"] == "CVS") {
            return $this->requestCvs($request, $orderData);
        }

        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $config = $this->getConfig($languageId, $localeId);
        $post = $request->getParsedBody();
        if (! $post) {
            $content = json_decode($request->getBody()->getContents(), true);
            $post = $content;
        }
        $choosePayment = "ALL";
        $allowePayments = [
            "ALL",
            "Credit",
            "WebATM",
            "ATM",
            "CVS",
            "BARCODE"
        ];
        if (isset($post["choosePayment"]) && false !== array_search($post["choosePayment"], $allowePayments, true)) {
            $choosePayment = $post["choosePayment"];
        }

        $urlPrefix = $this->getUrlprefix($request, $orderData);
        $localeCode = $this->getLocaleCode($orderData);
        $orderResultUrl = "{$urlPrefix}/ecpay-confirm/order_result";
        $returnUrl = "{$urlPrefix}/ecpay-confirm/receive";
        $clientRedirectURL = "{$urlPrefix}/ecpay-confirm/client_redirect";
        $translator = new Translator();
        $translator->setLocale($localeCode);
        $filename = './resources/languages/' . $localeCode . "/site-translation.php";
        $translator->addTranslationFile('phpArray', $filename);
        $modelAndCombinations = $this->getModelAndCombinations($orderData);
        $tradeDesc = $translator->translate('ecpay-trad-desc');
        $tradeDesc = str_replace('%modelAndCombinations%', $modelAndCombinations, $tradeDesc);
        $productsCount = count($orderData["details"]);
        $tradeDesc = str_replace('%productsCount%', $productsCount, $tradeDesc);
        $itemNames = [];
        foreach ($orderData["details"] as $detailItem) {
            $quantity = $detailItem["quantity"];
            $nameCombination = $detailItem["model"];
            $itemWord = "(";
            // $nameCombination.= "(";
            for ($i = 1; $i <= 5; $i ++) {
                $key = "option{$i}_name";
                if ($detailItem[$key]) {
                    $itemWord .= $detailItem[$key];
                    $itemWord .= "/";
                }
            }
            $itemWord = preg_replace('/\/$/', '', $nameCombination);
            $itemWord .= ")";
            $nameCombination .= $itemWord;
            $itemNames[] = "{$nameCombination} × {$quantity}";
        }
        $merchantTradeNo = preg_replace('/^([A-Z|a-z]{2})/', '', $orderData["serial"]);
        $params = [
            "MerchantID" => $config["payment"]["merchantID"],
            "MerchantTradeNo" => self::PAYMENT_SERIAL_PREFIX . $merchantTradeNo,
            "MerchantTradeDate" => date("Y/m/d H:i:s"),
            "PaymentType" => "aio",
            "TotalAmount" => intval($orderData["total"]),
            "TradeDesc" => UrlService::ecpayUrlEncode($tradeDesc),
            "ItemName" => UrlService::ecpayUrlEncode(implode('#', $itemNames)),
            "ReturnURL" => $returnUrl,
            "ChoosePayment" => $choosePayment,
            "ItemURL" => $urlPrefix,
            "OrderResultURL" => $orderResultUrl,
            "ClientRedirectURL" => $clientRedirectURL,
            "EncryptType" => 1
        ];
        if (isset($orderData["message"]) && $orderData["message"]) {
            $params["Remark"] = $orderData["message"];
        }
        unset($orderData);
        if (isset($post["storeID"])) {
            $params["storeID"] = $post["storeID"];
        }
        if (isset($post["chooseSubPayment"])) {
            $params["ChooseSubPayment"] = $post["chooseSubPayment"];
        }
        if (isset($post["needExtraPaidInfo"])) {
            $params["NeedExtraPaidInfo"] = strtoupper($post["needExtraPaidInfo"]);
            if ($params["NeedExtraPaidInfo"] != "Y") {
                unset($params["NeedExtraPaidInfo"]);
            }
        }
        if (isset($post["ignorePayment"])) {
            $params["IgnorePayment"] = $post["ignorePayment"];
        }
        if (isset($post["platformID"])) {
            $params["PlatformID"] = $post["platformID"];
        }
        if (isset($post["Language"])) {
            $params["Language"] = strtoupper($post["language"]);
            $allowLanguages = [
                "ENG",
                "KOR",
                "JPN",
                "CHI"
            ];
            if (false === array_search($params["Language"], $allowLanguages, true)) {
                $params["Language"] = "ENG";
            }
        }
        if (false !== array_search($choosePayment, [
            "ALL",
            "ATM"
        ], true) && isset($post["expireDate"])) {
            $expireDate = intval($post["expireDate"]);
            if ($expireDate > 60 || $expireDate < 1) {
                $expireDate = 3;
            }
            $params["ExpireDate"] = $expireDate;
        }
        if (isset($post["ignore_payment"])) {
            $params["IgnorePayment"] = $post["ignore_payment"];
        }
        $action = $config["payment"]["aioCheckoutUri"];
        $factory = new Factory([
            'hashKey' => $config["payment"]["hashKey"],
            'hashIv' => $config["payment"]["hashIv"]
        ]);
        /**
         *
         * @var HtmlService $htmlFormService
         */
        $htmlFormService = $factory->create(HtmlService::class);
        $input = $params;
        // CheckMacValueService
        /**
         *
         * @var CheckMacValueService $checkMacValueService
         */
        $checkMacValueService = $factory->create(CheckMacValueService::class);
        foreach ($input as $key => $value) {
            if (is_string($value) && strlen($value) == 0) {
                unset($input[$key]);
            }
        }
        $checkMacValue = $checkMacValueService->generate($input);
        $input["CheckMacValue"] = $checkMacValue;
        $paymentSubmitForm = $htmlFormService->form($input, $action, '_self', 'paymentSubmitForm', '');
        unset($input);
        return [
            "status" => "success",
            "response" => [
                "paymentSubmitForm" => $paymentSubmitForm
            ],
            "message" => [
                "redirect to ecpay"
            ]
        ];
    }

    public function printShipTag($allPayLogisticsIDs)
    {
        $config = $this->getConfig();

        $factory = new Factory([
            'hashKey' => $config["logistics"]["hashKey"],
            'hashIv' => $config["logistics"]["hashIv"],
            'hashMethod' => 'md5',
        ]);
        /**
         *
         * @var \Ecpay\Sdk\Request\CheckMacValueRequest $ecpayRequest
         */
        $ecpayRequest = $factory->create(\Ecpay\Sdk\Request\CheckMacValueRequest::class);
        /**
         *
         * @var HtmlService $htmlService
         */
        $htmlService = $factory->create(HtmlService::class);

        $input = [
            'MerchantID' => $config["logistics"]["merchantID"],
            'AllPayLogisticsID' => $allPayLogisticsIDs,
        ];
        $input = $ecpayRequest->toArray($input);
        //$action = 'https://logistics-stage.ecpay.com.tw/helper/printTradeDocument';
        $form = "";
        foreach ($input as $name => $value) {
            $inputName = $htmlService->escapeHtml($name);
            $inputValue = $htmlService->escapeHtml($value);
            $form .= '<input type="hidden" name="' . $inputName . '" value="' . $inputValue . '">';
        }
        unset($input);
        return $form;
    }
}
