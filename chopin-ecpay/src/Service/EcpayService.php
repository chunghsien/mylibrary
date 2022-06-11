<?php

namespace Chopin\Ecpay\Service;

use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Adapter\Adapter;
use Chopin\Support\Registry;
use Chopin\Store\TableGateway\OrderTableGateway;

include_once dirname(dirname(__DIR__)) . '/ECPayAIO_PHP/AioSDK/sdk/ECPay.Payment.Integration.php';
include_once dirname(dirname(__DIR__)) . '/ECPayAIO_PHP/EInvoiceSDK/sdk/Ecpay_Invoice.php';

/**
 * @deprecated
 * @author User
 *
 */
class EcpayService /* extends ThirdPartyPaymentService */
{
    public const VERSION = '5';

    public const INVOICE_PAPER_VER = "3.3.4";

    /**
     *
     * @var Adapter
     */
    protected $adapter;

    protected $config;

    public function __construct(Adapter $adapter, $config)
    {
        $this->adapter = $adapter;
        $this->config = $config;
    }

    /**
     * @desc 防止訂單號碼重複
     */
    public function buildOrderSerial(OrderTableGateway $tableGateway)
    {
        $orderSerial = $tableGateway->buildOrderSerial();
        $tradeInfo = $this->queryTradeInfo($orderSerial);
        if ($tradeInfo["TradeStatus"] == '10200047') {
            return $orderSerial;
        }
        OrderTableGateway::$add += 1;
        return $this->buildOrderSerial($tableGateway);
    }
    /**
     * @desc 金流退款(信用卡)
     * @param string $MerchantTradeNo
     * @param
     *            array
     */
    public function refund($MerchantTradeNo)
    {
        if ($this->config["merchant_id"] !== "2000132") {
            $refundServiceUri = "https://payment.ecpay.com.tw/CreditDetail/DoAction";
            $tradInfo = $this->queryTradeInfo($MerchantTradeNo);
            $TradNo = $tradInfo["TradeNo"];
            $totalAmount = $tradInfo["TradeAmt"];
            $AL = new \ECPay_AllInOne();
            $AL->MerchantID = $this->config["merchant_id"];
            $AL->HashKey = $this->config["aio_hash_key"];
            $AL->HashIV = $this->config["aio_hash_iv"];
            $AL->EncryptType = \ECPay_EncryptType::ENC_SHA256;
            $AL->ServiceURL = $refundServiceUri;
            $AL->Action = [
                "MerchantTradeNo" => $MerchantTradeNo,
                "TradeNo" => $TradNo,
                "Action" => \ECPay_ActionType::R,
                "TotalAmount" => $totalAmount
            ];
            $feedBack = $AL->DoAction();
            $response = [
                "status" => $feedBack["RtnCode"] == 1 ? 1 : - 1,
                "feedBack" => $feedBack,
                "message" => $feedBack["RtnMsg"],
            ];
        } else {
            $response = [
                "status" => 1,
                "feedBack" => [],
                "message" => "測試環境不支援刷退",
            ];
        }
        return $response;
    }

    /**
     * @desc發票作廢
     * @param string $invoiceNumber
     * @param string $reason
     * @return array
     */
    public function invoiceVoid($invoiceNumber, $reason)
    {
        if ($this->config["merchant_id"] !== "2000132") {
            $serviceURL = "https://einvoice.ecpay.com.tw/Invoice/IssueInvalid";
        } else {
            $serviceURL = "https://einvoice-stage.ecpay.com.tw/Invoice/IssueInvalid";
        }
        $ecpay = new \EcpayInvoice();
        $ecpay->Invoice_Url = $serviceURL;
        $ecpay->Invoice_Method = 'INVOICE_VOID';
        $ecpay->MerchantID = $this->config["merchant_id"];
        $ecpay->HashKey = $this->config["invoice_hash_key"];
        $ecpay->HashIV = $this->config["invoice_hash_iv"];
        $ecpay->Send['InvoiceNumber'] = $invoiceNumber;
        $ecpay->Send['Reason'] = urlencode($invoiceNumber);
        $response = $ecpay->Check_Out();
        return [
            "status" => $response["RtnCode"],
            "feedBack" => $response,
            "message" => $response["RtnMsg"],
        ];
    }

    public function queryTradeInfo($MerchantTradeNo)
    {
        $version = self::VERSION;
        if ($this->config["merchant_id"] !== "2000132") {
            $queryServiceURL = "https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V{$version}";
        } else {
            $queryServiceURL = "https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V{$version}";
        }

        $AL = new \ECPay_AllInOne();
        $AL->MerchantID = $this->config["merchant_id"];
        $AL->HashKey = $this->config["aio_hash_key"];
        $AL->HashIV = $this->config["aio_hash_iv"];
        $AL->EncryptType = \ECPay_EncryptType::ENC_SHA256;
        $AL->ServiceURL = $queryServiceURL;
        $AL->Query = [
            "MerchantTradeNo" => $MerchantTradeNo,
            "TimeStamp" => time(),
        ];
        $tradInfo = $AL->QueryTradeInfo();
        return $tradInfo;
    }

    public function notify(ServerRequestInterface $request, $type = "Return")
    {
        $AL = new \ECPay_AllInOne();
        $AL->MerchantID = $this->config["merchant_id"];
        $AL->HashKey = $this->config["aio_hash_key"];
        $AL->HashIV = $this->config["aio_hash_iv"];
        $AL->EncryptType = \ECPay_EncryptType::ENC_SHA256;
        $feedback = $AL->CheckOutFeedback();
        if (! $feedback) {
            $post = $request->getParsedBody();
            if (! $post) {
                $post = json_decode($request->getBody()->getContents());
            }
            logger()->warning(__CLASS__ . "::" . $type . ",驗證失敗: " . json_encode($post, JSON_UNESCAPED_UNICODE));
            return [];
        }
        if (APP_ENV == 'development') {
            logger()->info(__CLASS__ . "::" . $type . ": " . json_encode($feedback, JSON_UNESCAPED_UNICODE));
        }
        if ($feedback["RtnCode"] == 1) {
            return $feedback;
        } else {
            if ($request->getAttribute("callback") == "client_redirect_url" && $feedback["RtnCode"] == 2) {
                return $feedback;
            }
            return [];
        }
    }

    public function buildMpgForm(ServerRequestInterface $request, $data)
    {
        $matcher = [];
        preg_match('/^production/i', APP_ENV, $matcher);
        $version = self::VERSION;
        if ($this->config["merchant_id"] !== "2000132") {
            $serviceURL = "https://payment.ecpay.com.tw/Cashier/AioCheckOut/V{$version}";
        } else {
            $serviceURL = "https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V{$version}";
        }
        $obj = new \ECPay_AllInOne();
        $obj->MerchantID = trim($this->config["merchant_id"]);
        $obj->HashKey = trim($this->config["aio_hash_key"]);
        $obj->HashIV = trim($this->config["aio_hash_iv"]);
        $obj->ServiceURL = $serviceURL;
        $obj->EncryptType = \ECPay_EncryptType::ENC_SHA256;
        $serverParams = $request->getServerParams();
        $serverName = $serverParams["SERVER_NAME"];
        $protocol = $serverParams["SERVER_PORT"] == 443 ? "https://" : "http://";
        $returnUri = config("third_party_service.logistics.return_uri");
        $client_redirect_uri = config("third_party_service.logistics.client_redirect_uri");
        //payment_info_uri
        $orderResultUri = config("third_party_service.logistics.order_result_uri");
        $items = [];
        $invoiceItems = []; // 給發票條件備用
        foreach ($data["carts"] as $cart) {
            $name = trim($cart['model']);
            if (isset($cart["spec_group"])) {
                $name .= '-' . trim($cart["spec_group"]["name"]);
            }
            if (isset($cart["spec"])) {
                $name .= '-' . trim($cart["spec"]["name"]);
            }

            $items[] = [
                "Name" => $name,
                "Price" => intval($cart["real_price"]),
                "Quantity" => intval($cart['quantity']),
                "Currency" => "元",
                'URL' => "dedwed"
            ];
            $invoiceItems[] = [
                "ItemName" => $name,
                "ItemWord" => "個",
                "ItemCount" => intval($cart['quantity']),
                "ItemPrice" => floatval($cart["real_price"]),
                "ItemAmount" => floatval($cart["real_price"]) * intval($cart['quantity']),
                'ItemTaxType' => \ECPay_TaxType::Dutiable
            ];
        }
        $serial = $data["serial"];
        $post = $request->getParsedBody();
        if (! $post) {
            $post = $request->getBody()->getContents();
            if ($post) {
                $post = json_decode($post, true);
            }
        }

        $obj->Send = [
            // "InvoiceMark" => $invoiceMark,
            "PlatformID" => $this->config["merchant_id"],
            "Items" => $items,
            "NeedExtraPaidInfo" => "N",
            "TradeDesc" => $this->config["trade_desc"],
            "MerchantTradeDate" => date("Y/m/d H:i:s"),
            "TotalAmount" => $data["total"],
            "PaymentType" => "aio",
            "MerchantTradeNo" => $serial,
            "ChoosePayment" => $data["payment"],
            "ReturnURL" => "{$protocol}{$serverName}{$returnUri}",
            "ClientBackURL" => "{$protocol}{$serverName}{$orderResultUri}?serial={$serial}",
            "OrderResultURL" => "{$protocol}{$serverName}{$orderResultUri}?serial={$serial}",
        ];
        $obj->Send["InvoiceMark"] = \ECPay_InvoiceState::No;
        $systemGlobalConfig = config('third_party_service.logistics');
        if ($systemGlobalConfig['isInvoiceUse']) {
            $postData = $request->getParsedBody();
            if (! $post) {
                $postData = $request->getBody()->getContents();
                $postData = json_decode($postData, true);
            }

            //$cookies = $request->getCookieParams();
            $obj->SendExtend["InvoiceMark"] = \ECPay_InvoiceState::Yes;
            //$guestSerial = json_decode($cookies["guest_serial"]);
            $obj->SendExtend["RelateNumber"] = $serial;
            // 一律價格都含稅。
            // SalesAmount
            // SalesAmount
            $obj->SendExtend["SalesAmount"] = intval($data["total"]);
            $print = 0;
            $carruerType = $post["invoice-CarruerType"];
            if ($carruerType == "CustomerIdentifier") {
                $print = 1;
                $obj->SendExtend["CustomerIdentifier"] = $post["invoice-CustomerIdentifier"];
                $obj->SendExtend["CustomerName"] = $post["invoice-CustomerName"];
                $obj->SendExtend["CustomerAddr"] = $post["invoice-CustomerAddr"];
                $obj->SendExtend["CustomerEmail"] = "";
            }
            $obj->SendExtend["CustomerPhone"] = $post["order-invoice_phone"];

            // 手續費(payment_price, payment)
            // 運費(shipping_price, shipping_name)
            // 其他費用(others_name, $others_price)
            // 折扣(coupon_price)
            /*
             * $invoiceItems[] = [
             * "ItemName" => $name,
             * "ItemWord" => "個",
             * "ItemCount" => intval($cart['quantity']),
             * "ItemPrice" => floatval($cart["real_price"]),
             * "ItemAmount" => floatval($cart["real_price"]) * intval($cart['quantity']),
             * 'ItemTaxType' => \ECPay_TaxType::Dutiable
             * ];
             */
            $csvComParams = isset($data["csvcomParams"]) ? $data["csvcomParams"] : [];
            if (isset($csvComParams["payment"]) && isset($csvComParams["payment_price"])) {
                $itemName = $csvComParams["payment"];
                $invoiceItems[] = [
                    "ItemName" => "手續費({$itemName})",
                    "ItemWord" => "筆",
                    "ItemCount" => 1,
                    "ItemPrice" => floatval($csvComParams["payment_price"]),
                    "ItemAmount" => floatval($csvComParams["payment_price"]),
                    'ItemTaxType' => \ECPay_TaxType::Dutiable
                ];
            }
            if (isset($csvComParams["shipping_price"]) && isset($csvComParams["shipping_name"])) {
                $itemName = $csvComParams["shipping_name"];
                $invoiceItems[] = [
                    "ItemName" => "運費({$itemName})",
                    "ItemWord" => "筆",
                    "ItemCount" => 1,
                    "ItemPrice" => floatval($csvComParams["shipping_price"]),
                    "ItemAmount" => floatval($csvComParams["shipping_price"]),
                    'ItemTaxType' => \ECPay_TaxType::Dutiable
                ];
            }
            if (isset($csvComParams["others"]) && isset($csvComParams["others_price"])) {
                $itemName = $csvComParams["others"];
                $invoiceItems[] = [
                    "ItemName" => "其他費用({$itemName})",
                    "ItemWord" => "筆",
                    "ItemCount" => 1,
                    "ItemPrice" => floatval($csvComParams["others_price"]),
                    "ItemAmount" => floatval($csvComParams["others_price"]),
                    'ItemTaxType' => \ECPay_TaxType::Dutiable
                ];
            }
            if (isset($csvComParams["coupon_price"])) {
                $price = floatval($csvComParams["coupon_price"]);
                $invoiceItems[] = [
                    "ItemName" => "折扣",
                    "ItemWord" => "筆",
                    "ItemCount" => 1,
                    "ItemPrice" => - ($price),
                    "ItemAmount" => - ($price),
                    'ItemTaxType' => \ECPay_TaxType::Dutiable
                ];
            }
            $obj->SendExtend['InvoiceItems'] = $invoiceItems;
            $obj->SendExtend["Donation"] = "0";
            if ($carruerType == "Donation") {
                $obj->SendExtend["LoveCode"] = $post["invoice-LoveCode"];
                $obj->SendExtend["Donation"] = "1";
            }

            $obj->SendExtend["TaxType"] = 1;
            $obj->SendExtend["CarruerType"] = ($carruerType != "CustomerIdentifier" && $carruerType != "Donation") ? $carruerType : "";
            if ($obj->SendExtend["CarruerType"] == 1) {
                $obj->SendExtend["CarruerNum"] = "";
            }
            if ($obj->SendExtend["CarruerType"] == 2 || $obj->SendExtend["CarruerType"] == 3) {
                $obj->SendExtend["CarruerNum"] = $post["invoice-CarruerNum"];
            }
            $obj->SendExtend["Print"] = $print;
            $obj->SendExtend["DelayDay"] = 0; // 付款後立即開立，退貨則直接作廢。
            $obj->SendExtend["InvType"] = \ECPay_InvType::General;

            // if($obj->SendExtend["CarruerNum"]) {
            Registry::set("invoiceSend", $obj->SendExtend);
            // }
        }
        if ($data["payment"] == "ATM" /*|| $data["payment"] == "WebATM"*/ || $data["payment"] == "CVS" || $data["payment"] == "BARCODE") {
            $obj->SendExtend["ClientRedirectURL"] = "{$protocol}{$serverName}{$client_redirect_uri}?serial={$serial}";
        } else {
            $obj->Send["OrderResultURL"] = "{$protocol}{$serverName}{$orderResultUri}?serial={$serial}";
        }
        $html = $obj->CheckOutString();
        if (APP_ENV == 'development') {
            logger()->info($html);
        }

        return $html;
    }

    /**
     *
     * @param array $send
     * @return mixed
     */
    public function B2CInvoiveIssue($send)
    {
        if ($this->config["merchant_id"] !== "2000132") {
            $serviceURL = "https://einvoice.ecpay.com.tw/Invoice/Issue";
        } else {
            $serviceURL = "https://einvoice-stage.ecpay.com.tw/Invoice/Issue";
        }
        $ecpay_invoice = new \EcpayInvoice();
        $ecpay_invoice->Invoice_Method;
        $ecpay_invoice->Invoice_Url = $serviceURL;
        $ecpay_invoice->MerchantID = $this->config["merchant_id"];
        $ecpay_invoice->HashKey = $this->config["invoice_hash_key"];
        $ecpay_invoice->HashIV = $this->config["invoice_hash_iv"];
        // $send["RelateNumber"] = time()+microtime(true).uniqid();
        $ecpay_invoice->Send = $send;
        return $ecpay_invoice->Check_Out();
    }
}
//訊息說明 Note for message：Parameter Error. itemName Not In Spec.
