<?php

namespace Chopin\Newsletter\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\ResultSet\ResultSetInterface;
use Laminas\Db\Sql\Where;
use Laminas\Db\ResultSet\ResultSet;

class MnClassTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'mn_class';

    /**
     *
     * @param  ServerRequestInterface $request
     * @param  bool $isContinueFind
     * @return ResultSetInterface
     */
    public function getNavOptions(ServerRequestInterface $request, $isContinueFind)
    {
        $where = new Where();
        $where->isNull("deleted_at");
        $select = $this->sql->select();
        $select->order([
            "sort asc",
            "id asc"
        ]);
        $select->where($where);
        $resultSet = $this->selectWith($select);
        $newResultset = new ResultSet();
        $dataSource = [];
        $mnClassHasNnClassTableGateway = new MnClassHasNnClassTableGateway($this->adapter);
        $nnClassTableGateway = new NnClassTableGateway($this->adapter);
        $rowUri = $request->getAttribute('rowUri');
        foreach ($resultSet as $row) {
            $mnClassId = $row->id;
            $row->with('uri', $rowUri . "/mm_class-{$mnClassId}");
            $mnClassHasNnClassResultset = $mnClassHasNnClassTableGateway->select([
                "mn_class_id" => $mnClassId
            ]);
            $nnClassIns = [];
            if ($mnClassHasNnClassResultset->count() > 0) {
                foreach ($mnClassHasNnClassResultset as $item) {
                    $nnClassIns[] = $item["nn_class_id"];
                }
                $where = new Where();
                $where->isNull("deleted_at");
                $where->In("id", $nnClassIns);
                $nnClassResultset = $nnClassTableGateway->select($where);
                $nnClassResult = [];
                foreach ($nnClassResultset as $nnClassRow) {
                    $nnClassRow->with('uri', $rowUri . "/{$nnClassRow->id}");
                    $nnClassResult[] = $nnClassRow->toArray();
                }
                $row->with("data", $nnClassResult);
                $dataSource[] = $row;
                unset($nnClassResultset);
                unset($mnClassHasNnClassResultset);
            }
        }
        unset($resultSet);
        $newResultset->initialize($dataSource);
        return $newResultset;
    }

    /**
     *
     * @param int $parant_id
     * @return array
     */
    public function getNavFromParent($parant_id)
    {
        $select = $this->sql->select();
        $where = $select->where;
        $fnClassHasMnClassTableGateway = new FnClassHasMnClassTableGateway($this->adapter);
        $select->join($fnClassHasMnClassTableGateway->table, "{$this->table}.id={$fnClassHasMnClassTableGateway->table}.mn_class_id", [
            "fn_class_id"
        ]);
        $where->isNull("deleted_at");
        $where->equalTo("fn_class_id", $parant_id);
        $select->order([
            "sort asc",
            "id desc"
        ]);
        $select->where($where);
        $result = $this->selectWith($select)->toArray();
        if (count($result) == 0) {
            return $result;
        }
        $nnClassTableGateway = new NnClassTableGateway($this->adapter);
        foreach ($result as &$item) {
            $parent_id = $item["id"];
            $item["childs"] = $nnClassTableGateway->getNavFromParent($parent_id);
        }
        return $result;
    }
}
