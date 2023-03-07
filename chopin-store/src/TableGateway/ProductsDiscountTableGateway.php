<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Chopin\Store\RowGateway\ProductsRowGateway;
use Chopin\LaminasDb\RowGateway\RowGateway;

//use Laminas\Db\Sql\Expression;
//use Psr\Http\Message\ServerRequestInterface;
/**
 * @de
 * @author User
 *
 */
class ProductsDiscountTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_discount';

    public function getRowUseCombinationRow(RowGateway $productsCombinationRow)
    {
        //$select = $this->getSql()->select();
        $where = new Where();
        $where->equalTo('products_combination_id', $productsCombinationRow->id);
        $where->greaterThanOrEqualTo('end_date', date("Y-m-d H:i:s"));
        $where->lessThanOrEqualTo('start_date', date("Y-m-d H:i:s"));
        /**
         *
         * @var RowGateway|null $row
         */
        $row = $this->select($where)->current();
        if ($row) {
            $discount = floatval($row->discount);
            $realPrice = floatval($productsCombinationRow->real_price);
            if ($row->discount_unit == "percent") {
                $discountPrice = $realPrice * ((100 - $discount) / 100);
            } else {
                $discountPrice = $realPrice - $discount;
            }

            $row->with('discount_price', $discountPrice);
            $row = $row->toArray();
        } else {
            $row = [];
        }
        $productsCombinationRow->with('discount', $row);
        return $productsCombinationRow;
    }

    public function getCountdown()
    {
        $select = $this->getSql()->select();
        $where = new Where();
        $where->equalTo("is_countdown", 1);
        $where->isNull("deleted_at");
        $now = date("Y-m-d H:i:s");
        $where->lessThanOrEqualTo("start_date", $now);
        $where->greaterThanOrEqualTo("end_date", $now);
        $select->where($where);
        $resultSet = $this->selectWith($select);
        $reuslt = [];
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        foreach ($resultSet as $row) {
            /**
             * @var \Chopin\LaminasDb\RowGateway\RowGateway $row
             */
            $productWhere = new Where();
            $productWhere->isNull("deleted_at");
            $productWhere->equalTo("id", $row->products_id);
            /**
             *
             * @var ProductsRowGateway $productsRow
             */
            $productsRow = $productsTableGateway->select($productWhere)->current();
            $productsRow->withDiscount();
            if (!$row->image) {
                $productsRow->withAssets();
            }
            $row->with("product", $productsRow->toArray());
            //$row->with("image", "/assets/images/countdown/bg-countdown-1.jpg");
            $reuslt[] = $row->toArray();
        }
        unset($resultSet);
        return $reuslt;
    }
}
