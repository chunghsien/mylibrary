<?php

namespace Chopin\Newsletter\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;

class NnClassHasNewsTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = true;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'nn_class_has_news';

    public function getNearClassList($item)
    {
        $select = $this->getSql()->select();
        $where = new Where();
        $where->equalTo("news_id", $item["id"]);
        $where->isNull("deleted_at");
        $pt = AbstractTableGateway::$prefixTable;
        $select->join(
            "{$pt}nn_class",
            "{$this->table}.nn_class_id={$pt}nn_class.id",
            ["id", "name"]
        );
        $select->order(["sort asc", "id asc"]);
        $select->where($where);
        return $this->selectWith($select)->toArray();
    }
    /**
     *
     * @param int $news_id
     * @return array
     */
    public function getNnClassResult($news_id)
    {
        $resultset = $this->select(["news_id" => $news_id]);
        $nnClassId = [];
        foreach ($resultset as $item) {
            $nnClassId[] = $item->nn_class_id;
        }
        unset($resultset);
        $nnClassTableGateway = new NnClassTableGateway($this->adapter);
        $resultset = $nnClassTableGateway->select(["id" => $nnClassId]);
        $result = [];
        foreach ($resultset as $row) {
            $result[] = $row->toArray();
        }
        unset($resultset);
        return $result;
    }
}
