<?php
namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\RowGateway\RowGateway;

// use Laminas\Db\Sql\Expression;
class DiscountGroupTableGateway extends AbstractTableGateway
{

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'discount_group';

    public function getRowUseCombinationRow(RowGateway $productsCombinationRow)
    {
        //$select = $this->getSql()->select();
        $where = new Where();
        $where->equalTo('products_combination_id', $productsCombinationRow->id);
        $where->greaterThanOrEqualTo('end_stamp', date("Y-m-d H:i:s"));
        $where->lessThanOrEqualTo('start_stamp', date("Y-m-d H:i:s"));
        $select = $this->sql->select();
        $discountGroupHasProductsTableGateway = new DiscountGroupHasProductsTableGateway($this->adapter);
        $select->join(
            $discountGroupHasProductsTableGateway->table,
            "{$discountGroupHasProductsTableGateway->table}.discount_group_id={$this->table}.id",
            []
        );
        $select->where($where);
        /**
         *
         * @var RowGateway|null $row
         */
        $row = $this->selectWith($select)->current();
        if ($row) {
            $discount = floatval($row->discount);
            $realPrice = floatval($productsCombinationRow->real_price);
            if ($row->discount_unit == "percent") {
                $discountPrice = $realPrice * ((100 - $discount) / 100);
            }
            if ($row->discount_unit == "yen") {
                $discountPrice = $realPrice - $discount;
            }
            if ($row->discount_unit == "fixed") {
                $discountPrice = $discount;
            }
            if($realPrice < $discountPrice) {
                $discountPrice = $realPrice;
            }
            $row->with('discount_price', $discountPrice);
            $row = $row->toArray();
        } else {
            $row = [];
        }
        $productsCombinationRow->with('discount', $row);
        return $productsCombinationRow;
    }
    
    
    public function getCountdown(ServerRequestInterface $request)
    {
        $languageId = $request->getAttribute('language_id', 119);
        $localeId = $request->getAttribute('locale_id', 229);
        $where = new Where();
        $where->equalTo('visible', 1);
        $where->equalTo('language_id', $languageId);
        $where->equalTo('locale_id', $localeId);
        $time = date("Y-m-d H:i:s");
        $where->lessThan('start_stamp', $time);
        $where->greaterThan('end_stamp', $time);
        $select = $this->sql->select();
        $select->order('sort ASC');
        $select->where($where);
        $resultSet = $this->selectWith($select);
        $result = [];
        $discountGroupHasProductsTableGateway = new DiscountGroupHasProductsTableGateway($this->adapter);
        
        
        /**
         * @var \Chopin\LaminasDb\RowGateway\RowGateway $row
         */
        foreach ($resultSet as $row) {
            $where = new Where();
            $where->equalTo('discount_group_id', $row->id);
            $resultSet2 = $discountGroupHasProductsTableGateway->select($where);
            $row->with('products', $resultSet2);
            /*
            $imgPath = './public'.$row->image;
            $imgHeight = 0;
            if(is_file($imgPath) && function_exists('getimagesize')) {
                $imgHeight = getimagesize($imgPath)[1];
            }
            $row->with('imgHeight', $imgHeight);
            */
            $result[] = $row->toArray();
        }
        return $result;
    }
}
