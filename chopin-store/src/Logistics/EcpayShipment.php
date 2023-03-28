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
use function PHPUnit\Framework\throwException;

/**
 * @deprecated
 * @author User
 *
 */
class EcpayShipment extends AbstractPayment
{
    public const PAYMENT_SERIAL_PREFIX = "Ep";

    public const INVOICE_SERIAL_PREFIX = "In";

    /**
     *
     * @var Adapter
     */
    protected $adapter;

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

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($this->adapter);
        $pageConfig = \Chopin\Support\Registry::get('page_json_config');
        if (!$pageConfig) {
            $systemSettingsTableGateway = new SystemSettingsTableGateway($this->adapter);
            $toSerialize = $systemSettingsTableGateway->toSerialize();
            $ecpayConfig = $toSerialize["ecpay"]["to_config"]["ecpay-config"];
        } else {
            $ecpayConfig = $pageConfig["system_settings"]["ecpay"]["children"]["ecpay-config"]["value"];
        }
        $this->systemSettings = [
            "localization" => $pageConfig["system_settings"]["localization"],
            "site_info" => $pageConfig["system_settings"]["site_info"],
            "ecpay" => $ecpayConfig,
        ];
        $date = date("Ym");
        $this->logger = new Logger([
            'writers' => [
                [
                    'name' => \Laminas\Log\Writer\Stream::class,
                    'priority' => 1,
                    'options' => [
                        'mod' => 'a+',
                        'stream' => "storage/logs/ecpay_log_{$date}.log",
                    ],
                ],
            ],
        ]);
    }

    /**
     *
     * @param number $language_id
     * @param number $locale_id
     * @param string $code
     * @return array
     *
     */
    public function getConfig($language_id = 119, $locale_id = 229, $code = "")
    {
        if ($this->config) {
            return $this->config;
        }
        $where = new Where();
        $where->equalTo("language_id", $language_id);
        $where->equalTo("locale_id", $locale_id);
        $where->equalTo("is_use", 1);
        $where->like("code", 'ecpay-%');
        $resultset = $this->logisticsGlobalTableGateway->select($where);
        if ($resultset->count() > 0) {
            $config = $this->systemSettings["ecpay"];
            $config = str_replace('Chopin\\Store\\Logistics\\', 'Chopin\\\\Store\\\\Logistics\\\\', $config);
            $config = json_decode($config, true);
            if ($_ENV["APP_ENV"] === 'development') {
                if (! $this->devConfig) {
                    $this->devConfig = config('third_party_service.logistics.ecpay');
                }
                $config = array_merge($config, $this->devConfig);
            }
            $this->config = $config;
            return $this->config;
        } else {
            if ($_ENV["APP_ENV"] === 'development') {
                if (! $this->devConfig) {
                    $this->devConfig = config('third_party_service.logistics.ecpay');
                }
                $config = $this->devConfig;
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
            'hashIv' => $config[$type]["hashIv"],
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
                "RelateNumber" => $relateNumber,
            ];
            $input = [
                'MerchantID' => $merchantID,
                'RqHeader' => [
                    'Timestamp' => time(),
                    'Revision' => '3.0.0',
                ],
                'Data' => $data,
            ];
            $url = $ecpayConfig["invoiceParams"]["getIssueUri"];
            $getInvoiceIssueResponse = $getInvoiceIssueService->post($input, $url);
            $getInvoiceIssueData = $getInvoiceIssueResponse["Data"];
            if ($getInvoiceIssueData["RtnCode"] == 1) {
                $csvcom = json_encode($getInvoiceIssueData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $orderParamsCount = $orderParamsTableGateway->select([
                    "order_id" => $orderData["id"],
                    "name" => "invoice_params",
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
                            "invoice_no" => $getInvoiceIssueData["IIS_Number"],
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
                            "InvType" => "07",
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
                                'ItemAmount' => floatval($row->subtotal),
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
                                'ItemAmount' => floatval($orderData["trad_fee"]),
                            ];
                        }
                        if (floatval($orderData["discount"]) > 0) {
                            $items[] = [
                                'ItemName' => "折扣",
                                'ItemCount' => 1,
                                'ItemWord' => "式",
                                'ItemPrice' => - floatval($orderData["discount"]),
                                'ItemTaxType' => "1",
                                'ItemAmount' => - floatval($orderData["discount"]),
                            ];
                        }

                        $data["Items"] = $items;
                        $input = [
                            'MerchantID' => $merchantID,
                            'RqHeader' => [
                                'Timestamp' => time(),
                                'Revision' => '3.0.0',
                            ],
                            'Data' => $data,
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
                                "invoice_no" => $response["Data"]["InvoiceNo"],
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
            'TimeStamp' => time(),
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
                "status" => array_search('order_paid_fail', $orderTableGateway->reverse_status, true)
            ];
            $orderTableGateway->update($set, $where);
        }
    }

    /**
     * 超商取貨付款
     *
     * @param ServerRequestInterface $request
     * @param array $orderData
     */
    public function requestCvs(ServerRequestInterface $request, $orderData, $isCollection = "Y")
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
            "hashMethod" => "md5",
        ]);
        /**
         *
         * @var PostService $autoSubmitFormService
         */
        $postService = $factory->create('PostWithCmvEncodedStrResponseService');
        // $merchantTradeNo = preg_replace('/^([A-Z|a-z]{2})/', '', $orderData["serial"]);
        if (intval($orderData["total"]) > 20000 || intval($orderData["total"]) < 1) {
            throw new \ErrorException('ecpay-error-10500040');
        }
        $localeCode = $this->getLocaleCode($orderData);
        $translator = new Translator();
        $translator->setLocale($localeCode);
        $filename = PROJECT_DIR.'/resources/languages/' . $localeCode . "/site-translation.php";
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
        $serverReplyUrl = "{$urlPrefix}/ecpay-confirm/cvs_is_collection_y";
        if (mb_strlen($tradeDesc, 'UTF-8') > 25) {
            $tmp = mb_substr($tradeDesc, 0, 22, "UTF-8");
            $tmp .= "﹒﹒﹒";
            $tradeDesc = $tmp;
        }
        $input = [
            "MerchantID" => $config["logistics"]["merchantID"],
            // "MerchantTradeNo" => self::PAYMENT_SERIAL_PREFIX . $merchantTradeNo,
            "MerchantTradeDate" => date("Y/m/d H:i:s"),
            "LogisticsType" => "CVS",
            "LogisticsSubType" => $post["logistics_sub_type"],
            "GoodsAmount" => intval($orderData["total"]),
            'GoodsName' => $tradeDesc,
            'SenderName' => $logisticsServiceRow->name,
            'SenderCellPhone' => $logisticsServiceRow->cellphone,
            'ReceiverName' => $orderData["fullname"],
            'ReceiverCellPhone' => $orderData["cellphone"],
            "IsCollection" => $isCollection,
            "ServerReplyURL" => $serverReplyUrl,
            "ReceiverStoreID" => $post["receiver_store_id"],
        ];
        $url = $config["logistics"]["CreateStoreUri"];
        $response = $postService->post($input, $url);

        if ($response["RtnCode"] == "300") {
            $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
            $orderParamsTableGateway->insert([
                "order_id" => $orderData["id"],
                "name" => "cvs_is_collection_y",
                "merchant_trade_no" => $response["MerchantTradeNo"],
                "csvcom_params" => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return [
                "status" => "success",
                "response" => [
                    "cvsResponse" => $response
                ],
                "message" => [
                    $response["RtnMsg"]
                ],
            ];
        }
        throw new \ErrorException($response["RtnMsg"]);
    }

    /**
     * 超商取貨付款
     *
     * @param array $orderData
     */
    public function sendHomeDeliveryOrder($orderData)
    {
        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $config = $this->getConfig($languageId, $localeId);
        debug([
            'hashKey' => $config["logistics"]["hashKey"],
            'hashIv' => $config["logistics"]["hashIv"],
            "hashMethod" => "md5",
        ], [
            "is_console_display" => true
        ]);
        $factory = new Factory([
            'hashKey' => $config["logistics"]["hashKey"],
            'hashIv' => $config["logistics"]["hashIv"],
            "hashMethod" => "md5",
        ]);
        /**
         *
         * @var PostService $autoSubmitFormService
         */
        $postService = $factory->create('PostWithCmvEncodedStrResponseService');
        // $merchantTradeNo = preg_replace('/^([A-Z|a-z]{2})/', '', $orderData["serial"]);
        if (intval($orderData["total"]) > 20000 || intval($orderData["total"]) < 1) {
            throw new \ErrorException('ecpay-error-10500040');
        }
        $localeCode = $this->getLocaleCode($orderData);
        $translator = new Translator();
        $translator->setLocale($localeCode);
        $filename = PROJECT_DIR.'/resources/languages/' . $localeCode . "/site-translation.php";
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
        $serverReplyUrl = "{$urlPrefix}/ecpay-confirm/cvs_is_collection_y";
        if (mb_strlen($tradeDesc, 'UTF-8') > 25) {
            $tmp = mb_substr($tradeDesc, 0, 22, "UTF-8");
            $tmp .= "﹒﹒﹒";
            $tradeDesc = $tmp;
        }
        $input = [
            "MerchantID" => $config["logistics"]["merchantID"],
            // "MerchantTradeNo" => self::PAYMENT_SERIAL_PREFIX . $merchantTradeNo,
            "MerchantTradeDate" => date("Y/m/d H:i:s"),
            "LogisticsType" => "HOME",
            "GoodsAmount" => intval($orderData["total"]),
            'GoodsName' => $tradeDesc,
            'SenderName' => $logisticsServiceRow->name,
            'SenderCellPhone' => $logisticsServiceRow->cellphone,
            'ReceiverName' => $orderData["fullname"],
            'ReceiverCellPhone' => $orderData["cellphone"],
            "ServerReplyURL" => $serverReplyUrl,
            "ReceiverStoreID" => $post["receiver_store_id"],
        ];
        $url = $config["logistics"]["CreateStoreUri"];
        $response = $postService->post($input, $url);

        if ($response["RtnCode"] == "300") {
            $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
            $orderParamsTableGateway->insert([
                "order_id" => $orderData["id"],
                "name" => "cvs_is_collection_y",
                "merchant_trade_no" => $response["MerchantTradeNo"],
                "csvcom_params" => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return [
                "status" => "success",
                "response" => [
                    "cvsResponse" => $response
                ],
                "message" => [
                    $response["RtnMsg"]
                ],
            ];
        }
        throw new \ErrorException($response["RtnMsg"]);
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
        $extraParams = null;
        if ($paymentRow->extra_params) {
            $extraParams = json_decode($paymentRow->extra_params, true);
        }
        if ($extraParams && isset($extraParams["is_collection"]) && $extraParams["is_collection"] == "Y" && isset($extraParams["logistics_type"]) && $extraParams["logistics_type"] == "CVS") {
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
        $filename = PROJECT_DIR.'/resources/languages/' . $localeCode . "/site-translation.php";
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
            "EncryptType" => 1,
        ];
        if (isset($orderData["message"]) && $orderData["message"]) {
            $params["Remark"] = $orderData["message"];
        }
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
            'hashIv' => $config["payment"]["hashIv"],
        ]);
        /**
         *
         * @var HtmlService $htmlFormService
         */
        $htmlFormService = $factory->create(HtmlService::class);
        $input = $params;
        //CheckMacValueService
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
            "response" => ["paymentSubmitForm" => $paymentSubmitForm],
            "message" => [
                "redirect to ecpay"
            ],
        ];
    }
}
