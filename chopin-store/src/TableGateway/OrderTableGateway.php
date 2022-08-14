<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Laminas\Validator\AbstractValidator;
use Psr\Http\Message\ServerRequestInterface;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;
use Laminas\Db\Sql\Expression;
use Laminas\I18n\Translator\Translator;
use Chopin\LanguageHasLocale\TableGateway\LanguageHasLocaleTableGateway;
use Laminas\I18n\Translator\TranslatorInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\ResultSet\ResultSet;
use Chopin\Store\Logistics\AbstractPayment;

class OrderTableGateway extends AbstractTableGateway
{
    use SecurityTrait;

    // 發票號碼，跨金流平台統一名稱
    public const INVOICE_NUMBER = 'number';

    // 發票統一編號，跨金流平台統一名稱
    public const INVOICE_IDENTIFIER = 'identifier';

    // 發票抬頭，跨金流平台統一名稱
    public const INVOICE_TITLE = 'title';

    // 發票住址，跨金流平台統一名稱
    public const INVOICE_ADDRESS = 'address';

    // 發票電話，跨金流平台統一名稱
    public const INVOICE_PHONE = 'phone';

    // 發票載具類別，跨金流平台統一名稱
    public const INVOICE_CARRIER_TYPE = 'carrier_type';

    // 發票載具編號，跨金流平台統一名稱
    public const INVOICE_CARRIER_NUMBER = 'carrier_number';

    // 發票愛心捐贈碼，跨金流平台統一名稱
    public const INVOICE_LOVE_CODE = 'love_code';

    // 發票開立日期
    public const INVOICE_CREATED = 'created';

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = "order";

    protected $aes_key = '';

    protected $subSelectColumns = [];

    /**
     * *訂單正流程
     *
     * @var array
     */
    protected $status = [
        "order_no_status", // 訂單建立
        "order_paid", // 付款完成(信用卡或相關綁定支付)
        "simulate_paid", // 模擬付款成功
                          // "credit_account_paid", // 付款完成(信用卡或相關綁定支付)
                          // "transfer_account_paid", // 轉帳付款完成(ATM轉帳相關)
        "stock_up", // 備貨中(完成對帳狀態，避免重複對帳造成)
        "other_ship_stock_up", // 大型貨物備貨(無法使用超商取貨或一般材積總和150cm以上的宅配寄送商品)
        "other_ship_refrigeration_stock_up", // 大型冷藏貨物備貨(無法使用超商取貨或一般材積總和120cm以上)
        "other_ship_freezer_stock_up", // 大型冷凍貨物備貨(無法使用超商取貨或一般材積總和120cm以上)
        "goods_sent_out", // 貨品已寄出
                           // "goods_sent_out_and_unpaid", // 貨品已寄出(尚未付款)，僅適用超商取貨付款
        "unexpected_situation", // 其他意外狀況
        "delivered_to_store", // 已到店，僅適用超商取貨或超商取貨付款
        "delivered_to_house", // 已到貨，交付管理室或轉至在地物流中心。
        "received_and_paid", // 完成領收且完成付款(僅適用於超商取貨付款,貨到付款)
        "received", // 完成領收
        "transaction_complete" // 交易完成（網路鑑賞期7+3後轉換狀態）
    ];

    public function __get($property)
    {
        if ($property == "status") {
            return $this->status;
        }
        if ($property == "reverse_status") {
            return $this->reverse_status;
        }

        return parent::__get($property);
    }

    /**
     * *訂單逆流程(退貨，先不設計換貨，一律先退貨再重新購買)，資料表狀態是顯示負數
     *
     * @var array
     */
    protected $reverse_status = [
        "order_reverse_status_processing",
        "order_paid fail",
        "unable_to_ship", // 無法寄送
        "cancel_appication", // 訂單取消申請
        "cancel_agree", // 訂單取消同意
        "cancel_not_agree", // 訂單取消不同意
        "cancel_complete", // 訂單取消完成
        "order_reverse_application", // 退貨申請
        "order_reverse_agree", // 退貨同意
        "order_reverse_not_agree", // 退貨不同意
        "order_reverse_picup", // 逆物流已取貨
        "order_reverse_delivered", // 退貨店家已收到
        "order_reverse_complete", // 完成退貨
        "third_party_pay_process_fail", // 第三方金流處理錯誤
        'cancel_the_deal' // 取消交易(含退款)
    ];

    /**
     *
     * @var Translator
     */
    protected $translator;

    public function __construct(\Laminas\Db\Adapter\Adapter $adapter, $request = null)
    {
        parent::__construct($adapter);
        if ($request instanceof ServerRequestInterface) {
            $locale = $request->getAttribute("php_lang");
            $this->translator = new Translator();
            $this->translator->setLocale($locale);
            $filename = dirname(__DIR__, 2).'/resources/languages/' . $locale . "/chopin-store.php";
            $this->translator->addTranslationFile('phpArray', $filename, "chopin-store", $locale);
        }
    }

    /**
     *
     * @param string $serial
     * @param boolean $likeUse
     * @return \ArrayObject
     */
    public function getRow($serial, $likeUse = false)
    {
        $subSelect = $this->decryptSubSelectRaw;
        $select = new Select();
        $where = new Where();
        if ($likeUse) {
            $where->like('serial', "%{$serial}");
        } else {
            $where->equalTo('serial', $serial);
        }
        $select = $select->from([
            $this->getDecryptTable() => $subSelect
        ])->where($where);
        // ['serial' => $serial]
        $dataSource = $this->sql->prepareStatementForSqlObject($select)->execute();
        $resultSet = new ResultSet();
        $resultSet->initialize($dataSource);
        $item = $resultSet->current();
        $orderDetailTableGateway = new OrderDetailTableGateway($this->adapter);
        $orderDetailSelect = $orderDetailTableGateway->getSql()->select();
        $orderDetailSelect->order([
            "id asc"
        ]);
        $orderDetailWhere = $orderDetailSelect->where;
        $orderDetailWhere->isNull("deleted_at");
        $orderDetailSelect->where($orderDetailWhere);
        $detail = $orderDetailTableGateway->selectWith($orderDetailSelect)->toArray();
        $item["detail"] = $detail;
        return $item;
    }

    /**
     *
     * @param int $id
     * @return \ArrayObject
     */
    public function getRowFromId($id)
    {
        $subSelect = $this->decryptSubSelectRaw;
        $select = new Select();
        $select = $select->from([
            $this->getDecryptTable() => $subSelect
        ])->where([
            'id' => $id
        ]);
        $dataSource = $this->sql->prepareStatementForSqlObject($select)->execute();
        $resultSet = new ResultSet();
        $resultSet->initialize($dataSource);
        return $resultSet->current();
    }

    /**
     *
     * @return \Laminas\I18n\Translator\Translator
     */
    public function getTranslator()
    {
        return $this->translator;
    }

    public function initTranslator($language_id, $locale_id)
    {
        $languageHasLocaleTableGateway = new LanguageHasLocaleTableGateway($this->adapter);
        $localeRow = $languageHasLocaleTableGateway->select([
            "language_id" => $language_id,
            "locale_id" => $locale_id
        ])->current();
        $locale = $localeRow->code;

        if (! $this->translator instanceof TranslatorInterface) {
            $this->translator = new Translator();
            $this->translator->setLocale($locale);
            $filename = dirname(__DIR__, 2).'/resources/languages/' . $locale . "/chopin-store.php";
            $this->translator->addTranslationFile('phpArray', $filename, "chopin-store", $locale);
        }
        if ($this->translator->getLocale() != $locale) {
            $this->translator->setLocale($locale);
            $filename = dirname(__DIR__, 2).'/resources/languages/' . $locale . "/chopin-store.php";
            $this->translator->addTranslationFile('phpArray', $filename, "chopin-store", $locale);
        }
    }

    public function getStatusOptions($isMerge = false)
    {
        $translator = $this->translator;
        if (! $translator instanceof Translator) {
            throw new \ErrorException("請先初始化translator");
        }
        $options = [
            '_order_status_' => [],
            '_order_reverse_status_' => []
        ];
        foreach ($this->status as $index => $value) {
            $options['_order_status_'][] = [
                'label' => $translator->translate($value, 'chopin-store'),
                'value' => $index
            ];
        }
        $lastIndex = count($this->reverse_status) - 1;
        foreach ($this->reverse_status as $index => $value) {
            if ($index > 0) {
                $label = $translator->translate($value, 'chopin-store');
                if ($index == $lastIndex) {
                    $systemGlobalConfig = config('third_party_service.logistics');
                    if (isset($systemGlobalConfig['isInvoiceUse']) && $systemGlobalConfig['isInvoiceUse']) {
                        $label .= '(' . $translator->translate('invoice void success', 'chopin-store') . ')';
                    }
                }
                $options['_order_reverse_status_'][] = [
                    'label' => $label,
                    'value' => - ($index)
                ];
            } else {
                if (!$isMerge) {
                    $options['_order_reverse_status_'][] = null;
                }
            }
        }
        if ($isMerge) {
            return array_merge($options["_order_status_"], $options["_order_reverse_status_"]);
        }
        return $options;
    }

    /**
     *
     * @param array $order_row
     * @return array
     */
    public function parseOrder($order_row)
    {
        $_order_row = [];
        if ($order_row || (isset($order_row['order_row']) && $order_row['order_row'])) {
            $_order_row = isset($order_row['order_row']) ? $order_row['order_row'] : $order_row;
            $translator = AbstractValidator::getDefaultTranslator();
            $status = $_order_row['status'];
            if ($status < 0) {
                $_order_row['status'] = $translator->translate($this->reverse_status[abs($status)], 'chopin-store');
            } else {
                $_order_row['status'] = $translator->translate($this->status[$status], 'chopin-store');
            }
            if (isset($_order_row['third_party_pay_response']) && $_order_row['third_party_pay_response']) {
                $response = $_order_row['third_party_pay_response']['response'];
                $_order_row['third_party_pay_response']['response'] = json_decode($response, true);
            }
        }
        return $_order_row;
    }

    /**
     *
     * @param int $orderId
     * @return array|NULL
     */
    public function withInvoiceParams($orderId)
    {
        $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
        $select = $orderParamsTableGateway->getSql()->select();
        $select->where([
            "order_id" => $orderId,
            "name" => "invoice_query"
        ])->order("id desc");
        $resultset = $orderParamsTableGateway->selectWith($select);
        if ($resultset->count() == 1) {
            $row = $resultset->current();
            $item = $row->toArray();
            return json_decode($item["csvcom_params"], true);
        }
        return null;
    }

    public function withOrderParams($orderId, $name)
    {
        $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
        $select = $orderParamsTableGateway->getSql()->select();
        $select->where([
            "order_id" => $orderId,
            "name" => $name
        ])->order("id desc");
        $resultset = $orderParamsTableGateway->selectWith($select);
        if ($resultset->count() > 0) {
            $row = $resultset->current();
            $item = $row->toArray();
            return json_decode($item["csvcom_params"], true);
        }
        return null;
    }

    public function getOrderList($users_id, $lang, $getDetail = false, $limit = 0)
    {
        $translator = AbstractValidator::getDefaultTranslator();
        $subSelect = $this->decryptSubSelectRaw;
        $select = new Select();
        $select->from([
            $this->getDecryptTable() => $subSelect
        ]);
        $paymentTableGateway = new PaymentTableGateway($this->adapter);
        $select->join($paymentTableGateway->table, "{$paymentTableGateway->table}.id=decrypt_order.payment_id", [
            "payment_name" => "name",
        ]);
        $logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($this->adapter);
        $select->join($logisticsGlobalTableGateway->table, "{$logisticsGlobalTableGateway->table}.id=decrypt_order.logistics_global_id", [
            "logistics_name" => "name",
        ]);

        if ($limit > 0) {
            $select->limit($limit);
        }
        $select->order("id desc");
        $where = new Where();
        $where->equalTo("member_id", $users_id);
        $where->isNull("deleted_at");
        $dataSource = $this->sql->prepareStatementForSqlObject($select)->execute();
        $resultSet = new ResultSet();
        $resultSet->initialize($dataSource);
        $resultSet = $resultSet->toArray();
        $translator = new Translator();
        $translator->addTranslationFilePattern('phpArray', PROJECT_DIR.'/resources/languages/', '%s/order.php');
        $translator->setLocale($lang);
        $orderDetailTableGateway = new OrderDetailTableGateway($this->adapter);
        $logisticsServiceNamespace = config('third_party_service.logistics.logisticsServiceNamespace');
        $logisticsServiceObj = null;
        if ($logisticsServiceNamespace && class_exists($logisticsServiceNamespace)) {
            $logisticsServiceReflection = new \ReflectionClass($logisticsServiceNamespace);
            $logisticsServiceObj = $logisticsServiceReflection->newInstance($this->adapter);
        }
        $twBankListTableGateway = new TwBankListTableGateway($this->adapter);
        $btc603wTableGateway = new Btc603wTableGateway($this->adapter);
        $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
        foreach ($resultSet as &$order) {
            $status = $order['status'];
            if ($status < 0) {
                $order['status_str'] = $translator->translate($this->reverse_status[abs($status)]);
            } else {
                $order['status_str'] = $translator->translate($this->status[$status]);
            }
            if ($getDetail) {
                $id = $order["id"];
                $order["details"] = $orderDetailTableGateway->getDetailResult($id, true);
            }
            $orderId = $order["id"];
            $orderParamsSelect = $orderParamsTableGateway->getSql()->select();
            $orderParamsWhere = $orderParamsSelect->where;
            $orderParamsWhere->equalTo('order_id', $orderId);
            $orderParamsWhere->like('name', 'cvs_is_collection%');
            $orderParamsSelect->limit(1);
            $orderParamsSelect->order('id desc');
            $orderParamsSelect->where($orderParamsWhere);
            $orderParamsCvsRow = $orderParamsTableGateway->selectWith($orderParamsSelect)->current();
            if ($orderParamsCvsRow) {
                $csvcom = json_decode($orderParamsCvsRow->csvcom_params, true);
                $orderParamsSelect = $orderParamsTableGateway->getSql()->select();
                $orderParamsWhere = $orderParamsSelect->where;
                $orderParamsWhere->equalTo('order_id', $orderId);
                $orderParamsWhere->equalTo('name', 'post_params');
                $orderParamsSelect->limit(1);
                $orderParamsSelect->order('id desc');
                $orderParamsSelect->where($orderParamsWhere);
                $orderParamsPostRow = $orderParamsTableGateway->selectWith($orderParamsSelect)->current();
                if ($orderParamsPostRow) {
                    $orderParamsCsvCom = json_decode($orderParamsPostRow->csvcom_params, true);
                    if (empty($csvcom["LogisticsSubType"])) {
                        $csvcom["LogisticsSubType"] = $orderParamsCsvCom["LogisticsSubType"];
                    }
                    if (empty($csvcom["ReceiverStoreName"])) {
                        $csvcom["ReceiverStoreName"] = $orderParamsCsvCom["csv_store_name"];
                    }
                    $csvcom["orderPostParams"] = $orderParamsCsvCom;
                }
                $order["csvComParams"] = $csvcom;
            }
            if ($logisticsServiceObj instanceof AbstractPayment) {
                $invoiceParams = $this->withInvoiceParams($orderId);
                if ($invoiceParams) {
                    $finaleResult = $logisticsServiceObj->buildInvoiceResponse($invoiceParams);
                    if ($finaleResult) {
                        $order["invoiceParams"] = $finaleResult;
                    }

                    if (isset($finaleResult["love_code"])) {
                        $btc603wRow = $btc603wTableGateway->select([
                            "donate_code" => $finaleResult["love_code"]
                        ])->current();
                        if ($btc603wRow) {
                            $order["donate_organization"] = $btc603wRow->name;
                        }
                    }
                }
                $atmCvsBarcodeParams = $this->withOrderParams($orderId, 'ATM|CVS|BARCODE Result');
                if ($atmCvsBarcodeParams) {
                    if (isset($atmCvsBarcodeParams["BankCode"])) {
                        $bic = $atmCvsBarcodeParams["BankCode"];
                        $bankRow = $twBankListTableGateway->fetchRowUseBic($bic);
                        if ($bankRow) {
                            $atmCvsBarcodeParams["BankName"] = $bankRow->name;
                        }
                    }
                    if (isset($atmCvsBarcodeParams["PaymentType"]) && ($atmCvsBarcodeParams["PaymentType"] == "BARCODE_BARCODE" || $atmCvsBarcodeParams["PaymentType"] == "CVS_CVS")) {
                        $atmCvsBarcodeParams["isExpired"] = strtotime("now") < strtotime($atmCvsBarcodeParams["ExpireDate"]);
                    }
                    $order["atmCvsBarcodeParams"] = $atmCvsBarcodeParams;
                }
            }
        }
        return $resultSet;
    }

    /**
     * 如果訂單值驗證重複(金流的service object)
     *
     * @var integer
     */
    public static $add = 0;

    public function buildOrderSerial($prefix = 'PP')
    {
        if ($_ENV["APP_ENV"] != 'production') {
            $prefix = "LL";
        }
        $serial = date("ymd");
        $where = new Where();
        $where->between('created_at', date("Y-m-d") . ' 00:00:00', date("Y-m-d") . ' 23:59:59');
        $num = ($this->select($where)->count() + 1 + self::$add);
        $num = $num + strtotime("now");
        $tail = str_pad($num, 10, '0', STR_PAD_LEFT);
        return $prefix . $serial . $tail;
    }
}
