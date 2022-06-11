<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

class NpClassHasProductsTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = true;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'np_class_has_products';

    /**
     *
     * @param int $products_id
     * @return array
     */
    public function getNpClassResult($products_id)
    {
        $resultset = $this->select(["products_id" => $products_id]);
        $npClassId = [];
        foreach ($resultset as $item) {
            $npClassId[] = $item->np_class_id;
        }
        unset($resultset);
        $npClassTableGateway = new NpClassTableGateway($this->adapter);
        $resultset = $npClassTableGateway->select(["id" => $npClassId]);
        $result = [];
        foreach ($resultset as $row) {
            $result[] = $row->toArray();
        }
        unset($resultset);
        return $result;
    }
}
