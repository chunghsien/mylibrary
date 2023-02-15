<?php
namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Chopin\Store\RowGateway\ProductsRowGateway;
use Chopin\LaminasDb\RowGateway\RowGateway;

// use Laminas\Db\Sql\Expression;
// use Psr\Http\Message\ServerRequestInterface;
class DiscountGroupTableGateway extends AbstractTableGateway
{

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'discount_group';

    public function getCountdown()
    {
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $select = $this->getSql()->select();
        $where = new Where();
        $where->equalTo("is_countdown", 1);
        $where->isNull("{$this->table}.deleted_at");
        $where->isNull("{$productsTableGateway->table}.deleted_at");
        $now = date("Y-m-d H:i:s");
        $where->lessThanOrEqualTo("start_stamp", $now);
        $where->greaterThanOrEqualTo("end_stamp", $now);
        $select->where($where);
        $select->join($productsTableGateway->table, "{$this->table}.products_id={$productsTableGateway->table}.id", [
            'model',
            'alias',
            'product_price' => 'price',
            'product_real_price' => 'real_price'
        ]);
        $resultSet = $this->selectWith($select);
        $reuslt = [];

        foreach ($resultSet as $row) {
            $productWhere = new Where();
            $productWhere->equalTo("id", $row->products_id);
            $productsResultSet = $productsTableGateway->select($productWhere);
            $productsResult = [];
            foreach ($productsResultSet as $productsRow) {
                $productsRow->withAssets();
                $productsRow->withCombinationOptions();
                $productsResult[] = $productsRow->toArray();
            }
            $row->with("products", $productsResult);
            $reuslt[] = $row->toArray();
        }
        unset($resultSet);
        return $reuslt;
    }
}
