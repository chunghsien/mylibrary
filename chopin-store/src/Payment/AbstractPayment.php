<?php
namespace Chopin\Store\Payment;

use Laminas\Db\Adapter\Adapter;
use Psr\Http\Message\ServerRequestInterface;
use Chopin\Store\TableGateway\LogisticsGlobalTableGateway;
use Laminas\Db\Sql\Where;
use Laminas\Db\RowGateway\RowGatewayInterface;
use Chopin\Store\TableGateway\CartTableGateway;
use Chopin\Store\TableGateway\CouponTableGateway;
use Laminas\Db\Sql\Expression;
use Chopin\Store\TableGateway\OrderTableGateway;
use Chopin\Support\Registry;
use Chopin\Users\TableGateway\MemberTableGateway;
use Chopin\Support\TwigMail;
use Laminas\I18n\Translator\Translator;
use  Psr\Http\Message\ResponseInterface;

abstract class AbstractPayment
{

    /**
     *
     * @var Adapter
     */
    protected $adapter;

    /**
     * 
     * @var ServerRequestInterface
     */
    protected $request;

    public function __construct(Adapter $adapter, ServerRequestInterface $request)
    {
        $this->adapter = $adapter;
        $this->request = $request;
    }

    abstract public function processResponse(ServerRequestInterface $request):ResponseInterface;
    abstract public function requestRefundApi(ServerRequestInterface $request): array;
    /**
     * 
     * @param array $orderCommonParams
     * @param Translator $translator
     * @return array
     */
    public function requestApi(array $orderCommonParams, $translator = null): array
    {
        //$this->sendMail($orderCommonParams);
        return [
            "orderedStatus" => true,
        ];
    }
    
    /**
     * 
     * @param array $orderCommonParams 格式參考slef::processOrderCommon 的回傳
     */
    public function sendMail(array $orderCommonParams) {
        $request = $this->request;
        $pageConfig = Registry::get('page_json_config');
        $memberTableGateway = new MemberTableGateway($this->adapter);
        $orderSet = $orderCommonParams['order'];
        $details = $orderSet['details'];
        $paymentRow = $orderCommonParams['paymentRow'];
        $logisticsGlobalRow = $orderCommonParams['logisticsGlobalRow'];
        $member = (array) $memberTableGateway->getMember($orderSet['member_id']);
        $comParams = isset($orderCommonParams['com_params']) ? $orderCommonParams['com_params'] : [];
        $digitsAfterTheDecimalPoint = $orderCommonParams['digitsAfterTheDecimalPoint'];
        $varBaseUrl = $orderCommonParams['baseUrl'];
        $serverParams = $request->getServerParams();
        $baseUrl = $serverParams["REQUEST_SCHEME"] . "://" . $serverParams["HTTP_HOST"];
        $orderInquiryUri = $baseUrl;
        if (config('is_multiple_language')) {
            $lang = $request->getAttribute('lang');
            $orderInquiryUri .= "/{$lang}";
        }
        $orderId = $orderSet["id"];
        $orderInquiryUri .= "/my-account#orders/{$orderId}";
        
        $sendMailVars = [
            "lezada" => config('lezada'),
            "system" => $pageConfig["system_settings"]["system"]["to_config"],
            "member" => $member,
            "order" => $orderSet,
            "orderDetail" => $details,
            "payment" => $paymentRow->toArray(),
            "orderInquiryUri" => $orderInquiryUri,
            "baseUrl" => $varBaseUrl,
            "digitsAfterTheDecimalPoint" => $digitsAfterTheDecimalPoint,
            "logistics_name" => $logisticsGlobalRow->name,
            "postParams" => $comParams
        ];
        $phpLang = $request->getAttribute('php_lang');
        TwigMail::sendOrderedMail($request, [
            "vars" => $sendMailVars,
            "path" => "./resources/email_templates/{$phpLang}/",
            "name" => "recive_order.html.twig"
       ]);
        
    }
    
    /**
     * 
     * @param RowGatewayInterface $paymentRow
     * @param array $post
     * @param number $languageId
     * @param number $localeId
     * @return NULL|RowGatewayInterface
     */
    protected function getShippingInfo(RowGatewayInterface $paymentRow, $post, $languageId = 119, $localeId = 229)
    {
        $logistics_global_id = 0;
        $logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($this->adapter);
        $logisticsGlobalWhere = new Where();
        $logisticsGlobalRow = null;
        if (isset($post["logistics_global_id"])) {
            $logistics_global_id = $post["logistics_global_id"];
            $logisticsGlobalWhere->equalTo("id", $logistics_global_id);
            $logisticsGlobalWhere->equalTo("is_use", 1);
            $logisticsGlobalWhere->isNull("deleted_at");
            $logisticsGlobalRow = $logisticsGlobalTableGateway->select($logisticsGlobalWhere)->current();
        } else {
            // begin 客製自定義部分
            $logisticsGlobalWhere->equalTo("language_id", $languageId);
            $logisticsGlobalWhere->equalTo("locale_id", $localeId);
            $logisticsGlobalWhere->equalTo("is_use", 1);
            $logisticsGlobalWhere->isNull("deleted_at");
            $logisticsGlobalResultset = $logisticsGlobalTableGateway->select($logisticsGlobalWhere);
            foreach ($logisticsGlobalResultset as $row) {
                $paymentName = $paymentRow->name;
                if (false !== mb_stripos($row->name, $paymentName, 0, "UTF-8")) {
                    $logisticsGlobalRow = $row;
                }
                if (! $logisticsGlobalRow && $row->code == $paymentRow->code) {
                    $logisticsGlobalRow = $row;
                }
            }
            unset($logisticsGlobalResultset);
            // end of 客製自定義部分
        }
        return $logisticsGlobalRow;
    }

    public function processOrderCommon(RowGatewayInterface $paymentRow, $post, $languageId=119, $localeId=229)
    {
        $request = $this->request;
        $logisticsGlobalRow = $this->getShippingInfo($paymentRow, $post, $languageId, $localeId);
        if (! $logisticsGlobalRow) {
            throw new \ErrorException('No match ship method');
        }
        $cartTableGateway = new CartTableGateway($this->adapter);
        $baseCart = $cartTableGateway->getBaseCart($request);
        $subTotal = floatval($baseCart[1]);
        $tradFee = floatval($logisticsGlobalRow->price);
        // begin 計算是否免運
        $couponTableGateway = new CouponTableGateway($this->adapter);
        $freeShippingResultset = $couponTableGateway->getFreeShipping($languageId, $localeId);
        $freeShippingRow = null;
        if ($freeShippingResultset->count() == 1) {
            // begin 系統原生
            $freeShippingRow = $freeShippingResultset->current();
            // end of 系統原生
        } else {
            // begin 客製自定義免運部分
            foreach ($freeShippingResultset as $row) {
                if ($row->logistics_global_id == $logisticsGlobalRow->id) {
                    $freeShippingRow = $row;
                    break;
                }
            }
            unset($freeShippingResultset);
            // end of 客製自定義免運部分
        }
        $targetValue = floatval($freeShippingRow->target_value);
        if ($subTotal >= $targetValue) {
            $tradFee = 0;
        }
        // end of 計算是否免運
        /**
         *
         * @var \Mezzio\Session\LazySession $session
         */
        $session = $request->getAttribute('session');
        $member = $session->get('member');
        
        // begin discount(coupon) 計算
        $disount = 0;
        $coupon_id = 0;
        if (isset($post["coupon_id"])) {
            $coupon_id = intval($post["coupon_id"]);
        }
        $dicsountDetail = [];
        if ($coupon_id) {
            $dicsountDetail = $couponTableGateway->calCouponUseDetail($coupon_id, $subTotal, $member);
            $disount += floatval($dicsountDetail["discount"]);
        }
        // end of discount(coupon) 計算
        // begin of auto apply coupon (自動套用的coupon計算)
        $autoApplyCouponsResult = $couponTableGateway->getCoupons($request, true);
        foreach ($autoApplyCouponsResult as $autoApplyCouponItem) {
            $coupon_id = $autoApplyCouponItem["id"];
            $dicsountDetail = $couponTableGateway->calCouponUseDetail($coupon_id, $subTotal, $member);
            $disount += floatval($dicsountDetail["discount"]);
        }
        unset($coupon_id);
        // end of auto apply coupon (自動套用的coupon計算)
        
        if(!config('lezada.shopping.ship')){
            //運費由後台更新計算
            $tradFee = 0;
        }
        
        $orderTableGateway = new OrderTableGateway($this->adapter);
        $total = $subTotal + $tradFee - $disount;
        $serial = $orderTableGateway->buildOrderSerial();
        $nullExpression = new Expression("NULL");
        $paymentId = $paymentRow->id;
        $orderSet = [
            "member_id" => $member["id"],
            "logistics_global_id" => $logisticsGlobalRow->id,
            "payment_id" => $paymentId,
            "language_id" => $languageId,
            "locale_id" => $localeId,
            "serial" => $serial,
            "subtotal" => $subTotal,
            "trad_fee" => $tradFee,
            "discount" => $disount,
            "total" => $total,
            "fullname" => $post["fullname"],
            "email" => $post["email"],
            "cellphone" => $post["cellphone"],
            "county" => isset($post["county"]) ? $post["county"] : $nullExpression,
            "district" => isset($post["district"]) ? $post["district"] : $nullExpression,
            "zip" => isset($post["zip"]) ? $post["zip"] : $nullExpression,
            "address" => isset($post["address"]) ? $post["address"] : $nullExpression
        ];
        if (isset($post["logistics_type"]) && $post["logistics_type"]) {
            if($paymentRow->extra_params) {
                $extraParams = json_decode($paymentRow->extra_params, true);
                if (is_array($extraParams) && isset($extraParams["is_collection"]) && $extraParams["is_collection"] == "Y") {
                    // 超商取貨付款
                    $orderSet["merchant_payment"] = $post["logistics_sub_type"];
                }
            }
        }
        $comParams = [];
        if(isset($post['cvs_store_params'])) {
            if(is_string($post['cvs_store_params'])) {
                $post['cvs_store_params'] = json_decode($post['cvs_store_params'], true);
            }
            $comParams['cvs_store_params'] = $post['cvs_store_params'];
        }
        return [
            'order' => $orderSet,
            'com_params' => $comParams,
            'dicsountDetail' => $dicsountDetail,
            'carts' => $baseCart[0],
            'logisticsGlobalRow' => $logisticsGlobalRow,
        ];
        //debug($post);
    }
}