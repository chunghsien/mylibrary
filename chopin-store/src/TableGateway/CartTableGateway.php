<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Where;
// use Chopin\Store\CouponRule\FreeShippingCouponRule;
use Chopin\SystemSettings\TableGateway\AssetsTableGateway;
use Laminas\Db\Sql\Join;
use Chopin\LaminasDb\DB;

class CartTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'cart';

    /**
     *
     * @return number
     */
    public function removeExpired($guest_serial = null)
    {
        $delete = $this->getSql()->delete();
        $predicate = $delete->where;
        $predicate->lessThan('expire', strtotime('now'));
        if ($guest_serial) {
            $predicate->equalTo('guest_serial', $guest_serial);
        }
        $delete->where($predicate);
        return $this->deleteWith($delete);
    }

    public function addCart(ServerRequestInterface $request)
    {
        $guest_serial = $this->getGuestSerial($request);
        $serial = $guest_serial['serial'];
        $request = $request->withAttribute('methodOrId', $serial);
        $params = $request->getParsedBody();
        if (! $params) {
            $params = json_decode($request->getBody()->getContents(), true);
        }
        $params['guest_serial'] = $serial;
        $where = $this->getSql()->select()->where;
        $where->equalTo('guest_serial', $params['guest_serial']);
        $where->equalTo('products_combination_id', $params['products_combination_id']);
        $item = (array) $this->select($where)->current();
        $quantity = intval($params['quantity']);
        $set = [];
        if ($item) {
            $quantity = intval($item["quantity"]) + intval($params['quantity']);
            $set["quantity"] = $quantity;
        } else {
            $set = $params;
        }
        $set["expire"] = strtotime("today") + (86400 * 7);
        $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
        $stock = 0;
        $productsCombinationRow = $productsCombinationTableGateway->select([
            'id' => $params['products_combination_id']
        ])->current();
        if (! $productsCombinationRow) {
            // ApiErrorResponse::$status = 200;
            $carts = $this->getCart($request);
            return [
                'status' => 'fail',
                'message' => [
                    "Inventory shortage"
                ],
                "data" => $carts
            ];
        }
        $stock = intval($productsCombinationRow->stock) - intval($productsCombinationRow->safety_stock);
        $carts = [];
        if ($stock < $quantity) {
            // ApiErrorResponse::$status = 200;
            $carts = $this->getCart($request);
            return [
                'status' => 'fail',
                'message' => [
                    "Inventory shortage"
                ],
                "data" => $carts
            ];
        }
        $where = [
            "guest_serial" => $serial,
            "products_combination_id" => $params["products_combination_id"]
        ];
        /**
         *
         * @var \Mezzio\Session\LazySession $session
         */
        $session = $request->getAttribute('session');
        if ($session->has('member')) {
            $member = $session->get('member');
            $set["member_id"] = $member["id"];
        }
        // $set["products_id"] = $productsCombinationRow->products_id;
        $set["products_combination_id"] = $productsCombinationRow->id;
        if ($item) {
            $this->update($set, $where);
        } else {
            $set["guest_serial"] = $serial;
            $this->insert($set);
        }

        $carts = $this->getCart($request);
        return [
            'status' => 'success',
            'message' => [
                "Added To Cart"
            ],
            "data" => $carts
        ];
    }

    public function getBaseCart(ServerRequestInterface $request, $isAssets = true)
    {
        $query = $request->getQueryParams();
        if (isset($query['guest_serial']) && strtolower($request->getMethod()) == 'get') {
            $guest_serial = $query['guest_serial'];
        }
        if (strtolower($request->getMethod()) != 'get') {
            $guest_serial = $request->getAttribute('methodOrId', null);
        }
        if (isset($guest_serial) && $guest_serial == 'undefined') {
            $guest_serial = null;
        }
        if (empty($guest_serial)) {
            $guestSerialArr = $this->getGuestSerial($request);
            $guest_serial = $guestSerialArr["serial"];
        }
        if (! preg_match('/^\d{10}$/', $guest_serial)) {
            $guest_serial = '';
            $cookies = $request->getCookieParams();
            // 防止有時第一次建立時cookie不能即時更新的問題
            if (empty($cookies["guest_serial"])) {
                $cookies["guest_serial"] = json_encode([
                    "serial" => $guest_serial,
                    "expire" => time() + (86400 * 7)
                ]);
            }
            if (isset($cookies["guest_serial"])) {
                $guest_serial = json_decode($cookies["guest_serial"])->serial;
            }
        }
        if (! $guest_serial) {
            $cart_guest_serial = $this->getGuestSerial($request);
            $guest_serial = $cart_guest_serial['serial'];
        }
        $this->removeExpired($guest_serial);
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
        $select = $this->getSql()->select();
        $select->join($productsCombinationTableGateway->table, "{$productsCombinationTableGateway->table}.id={$this->table}.products_combination_id", [
            "products_id",
            "products_option1_id",
            "products_option2_id",
            "products_option3_id",
            "products_option4_id",
            "products_option5_id",
            "price",
            "real_price",
            "safety_stock",
            "stock",
            "stock_status"
        ]);
        $productsOption1TableGateway = new ProductsOption1TableGateway($this->adapter);
        for ($i = 1; $i < 6; $i ++) {
            $table = $productsOption1TableGateway->table;
            $table = preg_replace('/1$/', $i, $table);
            $column = "option{$i}_name";
            $select->join($table, "{$table}.id={$productsCombinationTableGateway->table}.products_option{$i}_id", [
                $column => "name"
            ], Join::JOIN_LEFT);
        }
        
        $select->join($productsTableGateway->table, "{$productsCombinationTableGateway->table}.products_id={$productsTableGateway->table}.id", [
            'model',
            'alias'
        ]);
        /*
        $select->join($productsDiscountTableGateway->table, "{$this->table}.products_combination_id={$productsDiscountTableGateway->table}.products_combination_id", [
            "discount",
            "discount_unit",
            "start_date",
            "end_date"
        ], Join::JOIN_LEFT);
        */
        
        
        $where = new Where();
        $where->isNull("{$productsTableGateway->table}.deleted_at");
        $where->equalTo("{$this->table}.guest_serial", $guest_serial);
        $where->equalTo("{$productsTableGateway->table}.is_show", 1);
        $select->where($where);
        $result = $this->selectWith($select)->toArray();
        $total = 0;
        $digitsAfterTheDecimalPoint = $request->getAttribute('digitsAfterTheDecimalPoint', 0);
        //$toDayTime = strtotime("today");
        $assetsTableGateway = new AssetsTableGateway($this->adapter);
        $discountGroupHasProductsTableGateway = new DiscountGroupHasProductsTableGateway($this->adapter);
        $discountGroupTableGateway = new DiscountGroupTableGateway($this->adapter);
        //DB::mysqlSafeUpdateFix();
        foreach ($result as $key => $cartItem) {
            $discountGroupHasProductsSelect = $discountGroupHasProductsTableGateway->getSql()->select()->columns(['discount_group_id']);
            $discountGroupHasProductsSelect->where([
                'products_id' => $cartItem['products_id'],
                'products_combination_id' => $cartItem['products_combination_id'],
            ]);
            $discountGroupHasProductsResultset = $discountGroupHasProductsTableGateway->selectWith($discountGroupHasProductsSelect);
            if ($discountGroupHasProductsResultset->count() > 0) {
                $discount_price = [];
                foreach ($discountGroupHasProductsResultset as $item) {
                    $where = new Where();
                    $where->isNull('deleted_at');
                    $where->equalTo('id', $item->discount_group_id);
                    $where->lessThan('start_stamp', date("Y-m-d H:i:s"));
                    $where->greaterThan('end_stamp', date("Y-m-d H:i:s"));
                    $discountGroupResultset = $discountGroupTableGateway->select($where);
                    foreach ($discountGroupResultset as $key => $discountGroupRow) {
                        $discount = floatval($discountGroupRow->discount);
                        $realPrice = floatval($cartItem["real_price"]);
                        if ($discountGroupRow->discount_unit == "percent") {
                            $t = $realPrice * (100 - $discount) / 100;
                            $discount_price[] = floatval(number_format($t, 2, '.', ''));
                        }
                        if ($discountGroupRow->discount_unit == "yen") {
                            $t = $realPrice - $discount;
                            $discount_price[] = floatval(number_format($t, 2, '.', ''));
                        }
                        if ($discountGroupRow->discount_unit == "fixed") {
                            $discount_price[] = floatval(number_format($discount, 2, '.', ''));
                        }
                    }
                }
                $tPrice = min($discount_price);
                $cartItem["real_price"] = $cartItem["discount_price"] = round(min($discount_price));
            } else {
                $tPrice = $cartItem["real_price"];
                $tPrice = round($tPrice, $digitsAfterTheDecimalPoint);
            }
            $tPrice = $tPrice * $cartItem["quantity"];
            $total += $tPrice;
            if ($isAssets) {
                $cartItem["image"] = [];
                $combinationAssetSelect = $assetsTableGateway->getSql()->select();
                $combinationAssetSelect->order([
                    "sort ASC",
                    "id ASC"
                ]);
                $combinationAssetSelect->where([
                    "table" => "products_combination",
                    "table_id" => $cartItem["products_combination_id"]
                ]);
                $combinationAssetRow = $assetsTableGateway->selectWith($combinationAssetSelect)->current();
                if ($combinationAssetRow) {
                    $cartItem["image"][] = $combinationAssetRow->path;
                } else {
                    $peoductsId = $cartItem["products_id"];
                    $assetsSelect = $assetsTableGateway->getSql()->select();
                    $assetsSelect->order(["sort asc", "id asc"]);
                    $assetsWhere = $assetsSelect->where;
                    $assetsWhere->equalTo("table", "products");
                    $assetsWhere->equalTo("table_id", $peoductsId);
                    $assetsSelect->where($assetsWhere);
                    $assetsRow = $assetsTableGateway->selectWith($assetsSelect)->current();
                    if ($assetsRow) {
                        $cartItem["image"][] = $assetsRow->path;
                    } else {
                        $cartItem["image"][] = '/assets/images/cart_overlay_empty.jpg';
                    }
                    //
                }
            }
            $result[$key] = $cartItem;
        }
        return [
            $result,
            $total,
            $guest_serial
        ];
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @param boolean $assets 最後計算值的時候不需要用到
     * @return mixed[]
     */
    public function getCart(ServerRequestInterface $request, $isAssets = true)
    {
        $tmp = $this->getBaseCart($request, $isAssets);
        $result = $tmp[0];
        $prodTotal = $tmp[1];
        $guest_serial = $tmp[2];
        $shippingFeeValue = [];

        // 預設的運費
        $isFreeShippingFee = count($shippingFeeValue) === 0;
        // 購物車參數
        $logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($this->adapter);
        $paymentTableGateway = new PaymentTableGateway($this->adapter);
        $shippingResult = $logisticsGlobalTableGateway->getLogistics($request);
        $couponTableGateway = new CouponTableGateway($this->adapter);
        $couponSelect = $couponTableGateway->getSql()->select()->columns(['id', 'target_value']);
        $couponWhere = new Where();
        $couponWhere->isNull('deleted_at');
        $couponWhere->equalTo('use_type', 'freeshipping');
        $couponSelect->where($couponWhere);
        $shipfeeRow = $couponTableGateway->selectWith($couponSelect)->current();
        return [
            "cart" => $result,
            "guestSerial" => $guest_serial,
            "prodTotal" => $prodTotal,
            "shipping" => $shippingResult,
            "isFreeShippingFee" => $isFreeShippingFee,
            "shipfeeRow" => $shipfeeRow,
            'payment' => $paymentTableGateway->getPayments($request)
        ];
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    public function getGuestSerial(ServerRequestInterface $request)
    {
        $cookiePath = $request->getAttribute('_base_path');
        $expire_time = strtotime('today') + (86400 * 7);
        $query = $request->getQueryParams();
        /*
         * if ($request->getAttribute('methodOrId', null)) {
         * $guest_serial = $request->getAttribute('methodOrId', null);
         * if ($guest_serial != 'undefined') {
         * return [
         * 'serial' => $guest_serial,
         * 'expire' => $expire_time
         * ];
         * }
         * }
         */
        if (isset($query['guest_serial']) && strtolower($request->getMethod()) == 'get') {
            $guest_serial = $query['guest_serial'];
            return [
                'serial' => $guest_serial,
                'expire' => $expire_time
            ];
        }

        if (empty($_COOKIE['guest_serial'])) {
            $guest_serial = crc32(uniqid($this->table, true) . microtime(true));
            $data = [
                'serial' => $guest_serial,
                'expire' => $expire_time
            ];
            setcookie('guest_serial', json_encode($data), $expire_time, $cookiePath);
            return $data;
        } else {
            $data = json_decode($_COOKIE['guest_serial'], true);
            $guest_serial = $data['serial'];
            $data['expire'] = $expire_time;
            setcookie('guest_serial', json_encode($data), $expire_time, $cookiePath);
        }
        return json_decode($_COOKIE['guest_serial'], true);
    }
}
