<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Where;
use Laminas\Db\ResultSet\ResultSetInterface;
use Laminas\Db\ResultSet\ResultSet;

class MpClassTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'mp_class';

    /**
     *
     * @param ServerRequestInterface $request
     * @param bool $isContinueFind
     * @return ResultSetInterface
     */
    public function getNavOptions(ServerRequestInterface $request, $isContinueFind)
    {
        $where = new Where();
        $where->isNull("deleted_at");
        $select = $this->sql->select();
        $select->order(["sort asc", "id asc"]);
        $select->where($where);
        $resultSet = $this->selectWith($select);
        $newResultset = new ResultSet();
        $dataSource = [];
        $mpClassHasNpClassTableGateway = new MpClassHasNpClassTableGateway($this->adapter);
        $npClassTableGateway = new NpClassTableGateway($this->adapter);
        $rowUri = $request->getAttribute('rowUri');
        foreach ($resultSet as $row) {
            $mpClassId = $row->id;
            $row->with('uri', $rowUri."/mp_class-{$mpClassId}");
            $mpClassHasNpClassResultset = $mpClassHasNpClassTableGateway->select(["mp_class_id" => $mpClassId]);
            $npClassIns = [];
            if ($mpClassHasNpClassResultset->count() > 0) {
                foreach ($mpClassHasNpClassResultset as $item) {
                    $npClassIns[] = $item["np_class_id"];
                }
                $where = new Where();
                $where->isNull("deleted_at");
                $where->In("id", $npClassIns);
                $npClassResultset = $npClassTableGateway->select($where);
                $npClassResult = [];
                foreach ($npClassResultset as $npClassRow) {
                    $npClassRow->with('uri', $rowUri."/{$npClassRow->id}");
                    $npClassResult[] = $npClassRow->toArray();
                }
                unset($npClassResultset);
                $row->with("data", $npClassResult);
                $dataSource[] = $row;
            }
            unset($mpClassHasNpClassResultset);
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
        $fpClassHasMpClassTableGateway = new FpClassHasMpClassTableGateway($this->adapter);
        $select->join(
            $fpClassHasMpClassTableGateway->table,
            "{$this->table}.id={$fpClassHasMpClassTableGateway->table}.mp_class_id",
            ["fp_class_id"]
        );
        $where->isNull("deleted_at");
        $where->equalTo("fp_class_id", $parant_id);
        $select->order(["sort asc", "id desc"]);
        $select->where($where);
        $result = $this->selectWith($select)->toArray();
        if (count($result) == 0) {
            return $result;
        }
        $npClassTableGateway = new NpClassTableGateway($this->adapter);
        foreach ($result as &$item) {
            $parent_id = $item["id"];
            $item["childs"] = $npClassTableGateway->getNavFromParent($parent_id);
        }
        return $result;
    }
}
