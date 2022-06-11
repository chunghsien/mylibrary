<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Chopin\SystemSettings\TableGateway\AssetsTableGateway;

class OrderDetailTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'order_detail';

    /**
     *
     * @param int $orderId
     * @param bool $getProdAsset
     * @return array
     */
    public function getDetailResult($orderId, $getProdAsset=false)
    {
        $where = new Where();
        $where->equalTo("order_id", $orderId);
        $where->isNull("deleted_at");
        $result = $this->select($where)->toArray();
        if ($getProdAsset) {
            $assetsTableGateway = new AssetsTableGateway($this->adapter);
            foreach ($result as &$item) {
                $table = 'products_combination';
                $table_id = $item["products_combination_id"];
                $select = $assetsTableGateway->getSql()->select();
                $select->columns(["id", "path"]);
                $where = new Where();
                $where->equalTo("table", $table);
                $where->equalTo("table_id", $table_id);
                $select->order(["sort asc", "id asc"]);
                $select->limit(1);
                $select->where($where);
                $assetRow = $assetsTableGateway->selectWith($select)->current();
                if (!$assetRow) {
                    $productsId = $item['products_id'];
                    $assetsSelect = $assetsTableGateway->getSql()->select();
                    $assetsSelect->order(["sort asc", "id asc"]);
                    $assetsWhere = $assetsSelect->where;
                    $assetsWhere->equalTo("table", "products");
                    $assetsWhere->equalTo("table_id", $productsId);
                    $assetsSelect->where($assetsWhere);
                    $assetRow = $assetsTableGateway->selectWith($assetsSelect)->current();
                }
                if ($assetRow) {
                    $item["asset"] = $assetRow;
                }
            }
        }
        return $result;
    }

    /**
     *
     * @param int $member_id
     * @return array
     */
    public function getSellRateList($member_id)
    {
        $orderTableGateway = new OrderTableGateway($this->adapter);
        $orderFinalStatusProp = $orderTableGateway->status;
        $orderFinalStatusName = end($orderFinalStatusProp);
        $orderFinalStatusindex = array_search($orderFinalStatusName, $orderTableGateway->status, true);
        $productsRatingTableGateway = new ProductsRatingTableGateway($this->adapter);
        $assetsTableGateway = new AssetsTableGateway($this->adapter);
        $where = new Where();
        $where->equalTo("{$orderTableGateway->table}.status", $orderFinalStatusindex);
        $where->equalTo("{$orderTableGateway->table}.member_id", $member_id);
        $where->isNull("{$orderTableGateway->table}.deleted_at");
        $where->isNull("{$this->table}.deleted_at");
        $select = $this->getSql()->select();
        $select->join(
            $orderTableGateway->table,
            "{$orderTableGateway->table}.id={$this->table}.order_id",
            ["member_id"]
        );
        $select->where($where);
        $result = $this->selectWith($select)->toArray();
        //$productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
        foreach ($result as &$item) {
            $table = 'products_combination';
            $table_id = $item["products_combination_id"];
            $assetsSelect = $assetsTableGateway->getSql()->select();
            $assetsSelect->order(["sort asc", "id asc"]);
            $assetsSelect->limit(1);
            $assetsSelect->where(["table" => $table, "table_id" => $table_id]);
            $assetsRow = $assetsTableGateway->selectWith($assetsSelect)->current();
            if ($assetsRow) {
                $item["path"] = $assetsRow->path;
            } else {
                $productsId = $item["products_id"];
                $assetsSelect = $assetsTableGateway->getSql()->select();
                $assetsSelect->order(["sort asc", "id asc"]);
                $assetsWhere = $assetsSelect->where;
                $assetsWhere->equalTo("table", "products");
                $assetsWhere->equalTo("table_id", $productsId);
                $assetsSelect->where($assetsWhere);
                $assetsRow = $assetsTableGateway->selectWith($assetsSelect)->current();
                if ($assetsRow) {
                    $item["path"] = $assetsRow->path;
                } else {
                    $item["path"] = '/assets/images/product_empty.jpg';
                }
            }
            $productsRatingRow = $productsRatingTableGateway->select([
                "products_combination_id" => $item["products_combination_id"],
                "member_id" => $member_id,
                "order_id" => $item["order_id"],
                "order_detail_id" => $item["id"],
            ])->current();
            if ($productsRatingRow) {
                $item["rated"] = $productsRatingRow->toArray();
            }
        }
        return $result;
    }
}
