<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Math\Rand;
use Chopin\Store\CouponRule\FreeShippingCouponRule;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;
use Chopin\Jwt\JwtTools;
use Laminas\I18n\Translator\Translator;
use Laminas\Db\RowGateway\RowGatewayInterface;
use Laminas\Db\ResultSet\ResultSetInterface;
use Laminas\Db\Sql\Select;
use function class_exists;
use Chopin\LanguageHasLocale\TableGateway\LanguageHasLocaleTableGateway;
use Laminas\Validator\Translator\TranslatorInterface;

class CouponTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    public static $isOutputResultSet = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'coupon';

    protected $units = [
        "percentile",
        "dollar"
    ];

    protected $discountPercentile = [
        "5% off",
        "10% off",
        "15% off",
        "20% off",
        "25% off",
        "30% off",
        "35% off",
        "40% off",
        "45% off",
        "50% off",
        "55% off",
        "60% off",
        "65% off",
        "70% off",
        "75% off",
        "80% off",
        "85% off",
        "90% off"
    ];

    protected $use_type = [
        'deduct_amount',
        'percent_off_tw'
        // 'target_amount',
        // 'auto_trigger',
        // 'rule_object',
    ];

    protected $limit_type = [
        'all_member'
        // 'assign_member',
        // 'member_quota'
    ];

    public $userSessionKey = 'member';

    /**
     *
     * @var ServerRequestInterface
     */
    protected $request;

    /**
     *
     * @var Translator
     */
    protected $translator;

    public function __construct(\Laminas\Db\Adapter\Adapter $adapter, $request = null)
    {
        parent::__construct($adapter);
        $this->translator = new Translator();
        if ($request instanceof ServerRequestInterface) {
            $this->request = $request;
            $locale = $request->getAttribute("php_lang");
            
            $this->translator->setLocale($locale);
            $filename = dirname(__DIR__, 2).'/resources/languages/' . $locale . '/chopin-store.php';
            $this->translator->addTranslationFile("phpArray", $filename, 'chopin-store', $locale);
            $filename = dirname(__DIR__, 2).'/resources/languages/' . $locale . "/translation.php";
            $this->translator->addTranslationFile('phpArray', $filename, "translation", $locale);
            
        }
    }
    
    public function getReactSelectValues(ServerRequestInterface $request)
    {
        $locale = $request->getAttribute("php_lang");
        $filename = './resources/languages/' . $locale . "/translation.php";
        $this->translator->addTranslationFile('phpArray', $filename, "translation", $locale);
        $this->translator->setLocale($locale);
        $defaultLabel = $this->translator->translate("coupon-scope-all", "translation");
        $options = [
            "scope" =>[
                ["label" => $defaultLabel, "value" => "0"]
            ],
        ];
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $productsSelect = $productsTableGateway->getSql()->select();
        $productsSelect->columns(['id', 'model'])->order(['language_id ASC', 'locale_id ASC']);
        $productsWhere = $productsSelect->where;
        $productsWhere->isNull('deleted_at');
        $productsSelect->where($productsWhere);
        $resultset = $productsTableGateway->selectWith($productsSelect);
        foreach ($resultset as $row) {
            $options["scope"][] = ["label" => $row->model, "value" => $row->id];
        }
        $methodOrId = $request->getAttribute('methodOrId');
        if(preg_match('/^\d+$/', $methodOrId)) {
            $selfRow = $this->select(['id' => $methodOrId])->current();
            if($selfRow->scope == 0) {
                $values = [
                    "scope" =>[["label" => $defaultLabel, "value" => "0"]]
                ];
            }else {
                $productsRow = $productsTableGateway->select(["id" => $selfRow->scope])->current();
                $values = [
                    "scope" =>[["label" => $productsRow->model, "value" => $productsRow->id]]
                ];
                
            }
        }else {
            $values = [
                "scope" =>[["label" => $defaultLabel, "value" => "0"]]
            ];
        }
        return [
            "options" => $options,
            "values" => $values,
        ];
    }
    /**
     *
     * @deprecated
     * @param int $language_id
     * @param int $locale_id
     * @param boolean $isUseCheck
     * @return RowGatewayInterface
     */
    public function getFreeShippingRow($language_id, $locale_id, $isUseCheck = true)
    {
        $couponWhere = new Where();
        $couponWhere->equalTo("language_id", $language_id);
        $couponWhere->equalTo("locale_id", $locale_id);
        if ($isUseCheck) {
            $couponWhere->equalTo("is_use", 1);
        }
        $couponWhere->equalTo("use_type", "freeshipping");
        $couponWhere->isNull("deleted_at");
        // $couponWhere->equalTo("rule_object", "Chopin\Store\CouponRule\FreeShippingCouponRule");
        $couponWhere->lessThanOrEqualTo("start", date("Y-m-d"));
        $couponWhere->greaterThanOrEqualTo("expiration", date("Y-m-d"));
        return $this->select($couponWhere)->current();
    }

    /**
     *
     * @param int $language_id
     * @param int $locale_id
     * @param boolean $isUseCheck
     * @return ResultSetInterface
     */
    public function getFreeShipping($language_id, $locale_id)
    {
        $couponWhere = new Where();
        $couponWhere->equalTo("language_id", $language_id);
        $couponWhere->equalTo("locale_id", $locale_id);
        $couponWhere->equalTo("is_use", 1);
        $couponWhere->equalTo("use_type", "freeshipping");
        $couponWhere->isNull("deleted_at");
        $couponWhere->lessThanOrEqualTo("start", date("Y-m-d"));
        $couponWhere->greaterThanOrEqualTo("expiration", date("Y-m-d"));
        $select = $this->sql->select();
        $select->where($couponWhere);
        $couponHasLogisticsGlobalTableGateway = new CouponHasLogisticsGlobalTableGateway($this->adapter);
        $couponHasPaymentTableGateway = new CouponHasPaymentTableGateway($this->adapter);
        $select->join($couponHasLogisticsGlobalTableGateway->table, "{$couponHasLogisticsGlobalTableGateway->table}.coupon_id={$this->table}.id", [
            "logistics_global_id"
        ], Select::JOIN_LEFT);
        $select->join($couponHasPaymentTableGateway->table, "{$couponHasPaymentTableGateway->table}.coupon_id={$this->table}.id", [
            "payment_id"
        ], Select::JOIN_LEFT);

        return $this->selectWith($select);
    }

    public function getCoupons(ServerRequestInterface $request, $isAutoApply=false)
    {
        $language_id = $request->getAttribute('language_id');
        $locale_id = $request->getAttribute('locale_id');
        $where = new Where();
        $ruleObjectWhere = new Where();
        $ruleObjectWhere->notIn('use_type', [
            FreeShippingCouponRule::TYPE_NAME
        ]);
        $where->addPredicate($ruleObjectWhere);
        if ($isAutoApply) {
            $where->equalTo('is_auto_apply', 1);
        } else {
            $where->equalTo('is_auto_apply', 0);
        }

        $where->equalTo('is_use', 1);
        $where->equalTo('language_id', $language_id);
        $where->equalTo('locale_id', $locale_id);
        $where->isNull('deleted_at');
        $today = date("Y-m-d");
        $where->lessThanOrEqualTo('start', $today);
        $where->greaterThanOrEqualTo('expiration', $today);
        $couponResult = $this->select($where)->toArray();
        $orderHasCouponTableGateway = new OrderHasCouponTableGateway($this->adapter);
        $couponIds = [];
        foreach ($couponResult as $item) {
            $couponIds[] = $item["id"];
        }

        $memberHasCouponWhere = new Where();
        /**
         *
         * @var \Mezzio\Session\LazySession $session
         */
        $session = $request->getAttribute('session');
        $member = $session->get("member");
        $memberHasCouponWhere->equalTo('member_id', $member["id"]);
        $memberHasCouponWhere->in('coupon_id', $couponIds);
        $orderHasCouponResult = $orderHasCouponTableGateway->select($memberHasCouponWhere)->toArray();
        if (count($orderHasCouponResult) > 0) {
            foreach ($orderHasCouponResult as $item1) {
                foreach ($couponResult as $key => $item2) {
                    if ($item1["coupon_id"] == $item2["id"]) {
                        //$newCouponResult[] = $item2;
                        unset($couponResult[$key]);
                        break;
                    }
                }
            }
        }
        $couponResult = array_values($couponResult);
        return $couponResult;
    }

    /**
     *
     * @param array $coupon
     * @param number $subtotal
     * @return boolean
     */
    public function verifyCoupon($coupon, $subtotal)
    {
        if ($coupon['use_type'] == 'deduct_amount' && floatval($subtotal) > floatval($coupon['target_value'])) {
            return true;
        }
        if ($coupon['use_type'] == 'percent_off_tw' && floatval($subtotal) > floatval($coupon['target_value'])) {
            return true;
        }
        return false;
    }

    /**
     *
     * @param int $id
     * @param number $subTotal
     * @param array $member
     * @param boolean $throwError
     * @throws \ErrorException
     * @return array
     */
    public function calCouponUseDetail($id, $subTotal, $member, $throwError = true)
    {
        $couponDiscount = 0;
        $selfWhere = new Where();
        $selfWhere->equalTo("id", $id);
        $selfWhere->lessThanOrEqualTo("target_value", $subTotal);
        $selfWhere->lessThanOrEqualTo('start', date("Y-m-d"));
        $selfWhere->greaterThanOrEqualTo('expiration', date("Y-m-d"));
        $selfWhere->isNull("deleted_at");
        $row = $this->select($selfWhere)->current();
        if (!$row) {
            return $couponDiscount;
        }
        $orderHasCouponTableGateway = new OrderHasCouponTableGateway($this->adapter);
        $orderHasCouponResultset = $orderHasCouponTableGateway->select([
            "member_id" => $member["id"],
            "coupon_id" => $row->id
        ]);
        if ($orderHasCouponResultset->count() > 0) {
            throw new \ErrorException("the coupon is used");
        }

        $unit = $row->unit;
        $value = $row->use_value;
        if ($unit == 'percent') {
            $couponDiscount = $subTotal - ($subTotal * (100 - $value) / 100);
        } else {
            if ($row->is_rebate == 0) {
                $couponDiscount = $value;
            } else {
                $targetValue = $row->target_value;
                $quotient = intval($subTotal / $targetValue);
                $couponDiscount = $quotient * $value;
            }
        }
        return [
            "id" => $row->id,
            "name" => $row->name,
            "discount" => $couponDiscount
        ];
    }
    /**
     * @deprecated
     * @param int $id
     * @param number $subTotal
     * @param array $member
     * @param boolean $throwError
     * @return number
     */
    public function calCouponUseValue($id, $subTotal, $member, $throwError = true)
    {
        $detail = $this->calCouponUseDetail($id, $subTotal, $member, $throwError);
        return $detail["discount"];
    }

    // 只有會員才能使用折價券
    /**
     *
     * @deprecated
     * @param ServerRequestInterface $request
     * @param string $code
     * @return \ArrayObject
     */
    public function couponVerify(ServerRequestInterface $request, $code)
    {
        $session = $request->getAttribute('session');
        $result = new \ArrayObject([
            "status" => false,
            "message" => "",
            "data" => []
        ]);
        if ($session->has("member")) {
            $member = $session->get("member");
            // 不確定是不是還是用JWT
            if (is_string($member)) {
                $JWT = $member;
                $JwtPayload = JwtTools::decode($JWT);
                $member = $JwtPayload->data;
            }
            $couponWhere = $this->getSql()->select()->where;
            $couponWhere->equalTo("code", $code);
            $couponWhere->lessThanOrEqualTo("start", date("Y-m-d"));
            $couponWhere->greaterThanOrEqualTo("expiration", date("Y-m-d"));
            $couponWhere->isNull("deleted_at");
            $couponResultset = $this->select($couponWhere);
            if ($couponResultset->count() == 1) {
                $couponRow = $couponResultset->current();
                $orderHasCoupon = new OrderHasCouponTableGateway($this->adapter);
                $useCount = $orderHasCoupon->select([
                    "coupon_id" => $couponRow->id
                ])->count();
                if ($couponRow->limit_number > 0) {
                    if ($useCount >= $couponRow->limit_number) {
                        $result->status = false;
                        $result->message = "couponError2";
                        $result->data = $couponRow->toArray();
                        return $result;
                    }
                    $memberIsUsed = $orderHasCoupon->select([
                        "member_id" => $member->id,
                        "coupon_id" => $couponRow->id
                    ])->count();
                    if ($memberIsUsed > 0) {
                        $result->status = false;
                        $result->message = "couponError3";
                        $result->data = $couponRow->toArray();
                        return $result;
                    }
                    $cartTableGateway = new CartTableGateway($this->adapter);
                    if ($couponRow->target_value > 0) {
                        $cartData = $cartTableGateway->getCart($request);
                        $subtotal = 0;
                        foreach ($cartData["carts"] as $cart) {
                            $subtotal += floatval($cart["real_price"]) * floatval($cart["quantity"]);
                        }
                    }
                    if ($couponRow->target_value > $subtotal) {
                        $result->status = false;
                        $result->message = "couponError4";
                        $result->data = $couponRow->toArray();
                        return $result;
                    }
                    $guestSerial = $cartTableGateway->getGuestSerial($request)["serial"];
                    $cartParamsTableGateway = new CartParamsTableGateway($this->adapter);
                    $cartParamsResultSet = $cartParamsTableGateway->select([
                        "guest_serial" => $guestSerial
                    ]);
                    if ($cartParamsResultSet->count() == 1) {
                        $couponParamsRow = $cartParamsResultSet->current();
                    } else {
                        $cartParamsTableGateway->insert([
                            "guest_serial" => $guestSerial,
                            "csvcom_params" => json_encode([
                                "coupon_code" => $couponRow->code,
                                "coupon_price" => $couponRow->use_value
                            ], JSON_UNESCAPED_UNICODE)
                        ]);
                        $couponParamsRow = $cartParamsTableGateway->select([
                            "guest_serial" => $guestSerial
                        ])->current();
                    }
                    $csvcomParams = $couponParamsRow->csvcom_params ? json_decode($couponParamsRow->csvcom_params, true) : [];
                    if (! $csvcomParams) {
                        $csvcomParams = [];
                    }
                    $cartParamsTableGateway->update([
                        "csvcom_params" => json_encode($csvcomParams, JSON_UNESCAPED_UNICODE)
                    ], [
                        "guest_serial" => $guestSerial
                    ]);
                    $result->status = true;
                    $result->data = $couponRow->toArray();
                    return $result;
                }
            } else {
                $result->status = false;
                $result->message = "couponError1";
                return $result;
            }
        }
        return $result;
    }

    /**
     *
     * @param array $values
     * @return \Laminas\Db\Adapter\Driver\ResultInterface
     */
    public function insert($values)
    {
        if (empty($values["code"])) {
            $values["code"] = $this->generalCode();
        }
        if (empty($values["start"]) && $values["use_type"] == FreeShippingCouponRule::TYPE_NAME) {
            $values["start"] = date("Y-m-d");
            $values["expiration"] = "2099-12-31";
            $values["use_value"] = floatval($values["target_value"]);
        }

        return parent::insert($values);
    }

    /**
     *
     * {@inheritdoc}
     * @see \Chopin\LaminasDb\TableGateway\AbstractTableGateway::update()
     */
    public function update($set, $where = null, array $joins = null)
    {
        if (isset($set["use_type"]) && $set["use_type"] == FreeShippingCouponRule::TYPE_NAME) {
            if (isset($set["use_value"])) {
                if (floatval($set["use_value"]) != floatval(floatval($set["target_value"]))) {
                    $set["use_value"] = floatval($set["target_value"]);
                }
            } else {
                if (isset($set["target_value"])) {
                    $set["use_value"] = floatval($set["target_value"]);
                }
            }
        }
        return parent::update($set, $where, $joins);
    }

    /**
     * *系統產生折扣碼
     *
     * @return string
     */
    protected function generalCode($length = 8)
    {
        $code = Rand::getString($length, 'abcdefghijklmnopqrstuvwxyz1234567890');
        if ($this->select([
            'code' => $code
        ])->count()) {
            return $this->generalCode();
        }
        return $code;
    }

    /**
     *
     * @param boolean $include_rule_object
     * @param string $locale
     * @return array
     */
    public function getUseTypeOption($include_rule_object = false, $locale = "zh_TW")
    {
        $options = [];
        foreach ($this->use_type as $ut) {
            $label = $this->translator->translate($ut, 'chopin-store', $locale);
            if ($ut == 'rule_object') {
                if ($include_rule_object == false) {
                    continue;
                }
                $options[] = [
                    'value' => $ut,
                    'label' => $label
                ];
            } else {
                $options[] = [
                    'value' => $ut,
                    'label' => $label
                ];
            }
        }
        return $options;
    }

    /**
     *
     * @param string $locale
     * @return array
     */
    public function getLimitTypeOption($locale = "zh_TW")
    {
        $options = [];
        foreach ($this->limit_type as $ut) {
            $label = $this->translator->translate($ut, 'chopin-store', $locale);
            $options[] = [
                'value' => $ut,
                'label' => $label
            ];
        }
        return $options;
    }

    /**
     *
     * @param string $locale
     * @return array
     */
    public function getUnitOption($locale = "zh_TW")
    {
        $options = [];
        foreach ($this->units as $ut) {
            $label = $this->translator->translate($ut, 'chopin-store', $locale);
            $options[] = [
                'value' => $ut,
                'label' => $label
            ];
        }
        return $options;
    }

    /**
     *
     * @param string $locale
     * @return array
     */
    public function getUnitDiscountPercentileOptions($locale = "zh_TW")
    {
        $options = [];
        foreach ($this->discountPercentile as $ut) {
            $label = $this->translator->translate($ut, 'chopin-store', $locale);
            $value = preg_replace("/%\Woff$/", "", $ut);
            $options[] = [
                'value' => $value,
                'label' => $label
            ];
        }
        return $options;
    }

    /**
     *
     * @param number $subtotal
     * @return number
     */
    public function getGlobalFreeShippingFee($subtotal)
    {
        $where = new Where();
        $where->equalTo('rule_object', FreeShippingCouponRule::class);
        $where->greaterThanOrEqualTo('expiration', date("Y-m-d H:i:s"));
        $resultSet = $this->select($where);
        if ($resultSet->count()) {
            $row = $resultSet->current();
            $target_value = $row->target_value;
            $freeShippingCouponRule = new FreeShippingCouponRule($this->adapter);
            return $freeShippingCouponRule->getValues($subtotal, $target_value);
        }
        return PHP_INT_MAX;
    }
}
