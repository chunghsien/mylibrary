<?php

declare(strict_types=1);

namespace Chopin\Store\Logistics;

use Laminas\Db\Adapter\Adapter;
use Chopin\Store\TableGateway\PaymentTableGateway;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Log\Logger;
use Chopin\SystemSettings\TableGateway\SystemSettingsTableGateway;

/**
 * * 台新銀行刷卡串接
 *
 * @author hsien
 *
 */
class TspgCreditPayment extends AbstractPayment
{
    public const TABLE_USE_NAME = "信用卡";

    public const IDENTITY_CODE = "TSPG_Credit";

    public const VER = "1.0.0";

    public const TEST_URL = "https://tspg-t.taishinbank.com.tw/tspgapi/restapi/auth.ashx";

    public const PRODUCTION_URL = "https://tspg.taishinbank.com.tw/tspgapi/restapi/auth.ashx";

    /**
     *
     * @var Logger
     */
    protected $logger;

    protected $systemSettings;

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->paymentTableGateway = new PaymentTableGateway($this->adapter);
        $pageConfig = \Chopin\Support\Registry::get('page_json_config');
        $pageConfig = \Chopin\Support\Registry::get('page_json_config');
        if (!$pageConfig) {
            $systemSettingsTableGateway = new SystemSettingsTableGateway($this->adapter);
            $toSerialize = $systemSettingsTableGateway->toSerialize();
            $config = $toSerialize["TSPG_credit"]["to_config"]["TSPG_credit-config"];
        } else {
            $config = $pageConfig["system_settings"]["TSPG_credit"]["children"]["TSPG_credit-config"]["value"];
        }
        $date = date("Ym");
        $this->systemSettings = [
            "localization" => $pageConfig["system_settings"]["localization"],
            "site_info" => $pageConfig["system_settings"]["site_info"],
            "tspg_credit" => $config,
        ];

        $this->logger = new Logger([
            'writers' => [
                [
                    'name' => \Laminas\Log\Writer\Stream::class,
                    'priority' => 1,
                    'options' => [
                        'mod' => 'a+',
                        'stream' => "storage/logs/tspgCreditSend_log_{$date}.log",
                    ],
                ],
            ],
        ]);
    }

    public function getDefaultParamsTemplate()
    {
        return [
            "serviceNamespace" => "Chopin\Store\Logistics\TspgCreditPayment",
            "sender" => "rest",
            "ver" => "1.0.0",
            "mid" => "",
            // "s_mid" => "", //子特店代號
            "tid" => "",
            "pay_type" => 1,
            "tx_type" => 1,
            "params" => [
                "layout" => "1:一般網頁, 2:行動裝置網頁",
                "order_no" => "string(23)訂單號碼",
                "amt" => "string(12)交易金額,包含兩位小數,如100代表1.00元",
                "cur" => "NTD",
                "order_desc" => "string(40)訂單說明，允許中文，請以UTF-8編碼傳入",
                "capt_flag" => "0", // 授權同步請款標記 0:不同步請款, 1:同不請款 (若使用「 TSPG 系統自動請款」作業 方式， 請 設定為 0))
                "result_flag" => "1", // 回傳訊息標記 0:不查詢交易詳情, 1:查詢交易詳情 若為1 ，則 TSPG 會在傳送交易 資料至「 指定交易資料回傳 網址 (result_ 」時，一併傳送本交易之詳細資料。
                "post_back_url" => "指定接續網址",
                "result_url" => "指定交易資料回傳網址 ，須為 https",
            ]
        ];
    }

    public function addDataToPaymentTable(Adapter $adapter, $language_id = 119, $locale_id = 229)
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
                "name" => self::DEFAULT_TABLE_USE_NAME,
                "aes_value" => $this->getDefaultParamsTemplate(),
                "code" => self::IDENTITY_CODE,
                "manufacturer" => self::IDENTITY_CODE,
            ]);
        }
    }

    public function getConfig($language_id = 119, $locale_id = 229)
    {
        if ($this->config) {
            return $this->config;
        }
        $where = new Where();
        $where->equalTo("language_id", $language_id);
        $where->equalTo("locale_id", $locale_id);
        $where->equalTo("code", self::IDENTITY_CODE);
        $row = $this->paymentTableGateway->select($where)->current();
        if ($row) {
            $config = $this->systemSettings["tspg_credit"];
            $config = str_replace('Chopin\\Store\\Logistics\\', 'Chopin\\\\Store\\\\Logistics\\\\', $config);
            $config = json_decode($config, true);
            return $config;
        }
        return [];
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @param array $orderData
     * @throws \ErrorException
     * @return array
     */
    public function requestApi(ServerRequestInterface $request, $orderData)
    {
        $languageId = $orderData["language_id"];
        $localeId = $orderData["locale_id"];
        $config = $this->getConfig($languageId, $localeId);
        $send = json_decode($config, true);
        unset($config["serviceNamespace"]);
        // debug(mb_substr("卓1234忠賢", 0, 3, "UTF-8"));
        $send["params"]["order_no"] = $orderData["serial"];
        $send["params"]["amt"] = intval($orderData["total"]) . "00";
        $model = $orderData["detail"][0]["model"];
        $option1Name = $orderData["detail"][0]["option1_name"];
        $optionNameConcat = "";
        if ($option1Name) {
            $optionNameConcat .= "({$option1Name}";
            for ($i=2 ; $i <=5 ; $i++) {
                $key = "option{$i}_name";
                if ($orderData["detail"][0][$key]) {
                    $optionName = $orderData["detail"][0][$key];
                    $optionNameConcat .= "/{$optionName}";
                }
            }
            $optionNameConcat .= ")";
        }
        $detailCount = count($orderData["serial"]);
        $orderDesc = "{$model}{$optionNameConcat}等，共{$detailCount}樣商品。";
        $orderDesc = mb_substr($orderDesc, 0, 40, "UTF-8");
        $send["params"]["order_desc"] = $orderDesc;
        $serverParams = $request->getServerParams();
        $urlPrefix = 'https://' . $serverParams["HTTP_HOST"];
        if (! preg_match('/^https/', $send["params"]["post_back_url"])) {
            $postBackUrl = $urlPrefix . '/' . $send["params"]["post_back_url"];
            $postBackUrl = preg_replace('/\/\//', '/', $postBackUrl);
            $send["params"]["post_back_url"] = $postBackUrl;
        }
        if (! preg_match('/^https/', $send["params"]["result_url"])) {
            $resultUrl = $urlPrefix . '/' . $send["params"]["result_url"];
            $resultUrl = preg_replace('/\/\//', '/', $resultUrl);
            $send["params"]["result_url"] = $resultUrl;
        }
        $apiUrl = self::TEST_URL;
        if ($_ENV["APP_ENV"] == 'production') {
            $apiUrl = self::PRODUCTION_URL;
        }
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($send, JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, false);
        $result = curl_exec($ch);

        if (!$result) {
            $errorMessage = curl_error($ch);
            $this->logger->err($errorMessage);
            throw new \ErrorException($errorMessage);
        }
        $result = json_decode($result, true);
        if ($result['params']['ret_code'] !== '00') {
            $this->logger->err(json_encode($result));
            $errorMessage="交易錯誤，請洽服務人員。";
            throw new \ErrorException($errorMessage);
        }
        $this->logger->info(json_encode($result));
        return [
            "status" => "success",
            "redirectUrl" => $result["hpp_url"],
            "response" => $result,
            "message" => ["redirect to credit"],
        ];
    }
}
