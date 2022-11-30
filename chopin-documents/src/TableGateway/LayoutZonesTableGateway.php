<?php

namespace Chopin\Documents\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Chopin\Store\TableGateway\AttributesTableGateway;
use Laminas\Db\Sql\Where;

class LayoutZonesTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'layout_zones';

    public function getChildren($parent_id)
    {
        $select = $this->sql->select();
        $where = $select->where;
        $where->isNull('deleted_at');
        $where->equalTo('parent_id', $parent_id);
        $select->where($where);
        $select->order([
            'sort asc',
            'id asc'
        ]);
        return $this->selectWith($select);
    }

    public function insert($set)
    {
        if (isset($set["uri"])) {
            $uri = $set["uri"];
            if (preg_match('/(\/news\-category|\/category)$/', $uri)) {
                $set["uri"] = $uri .= "/all";
            }
            if (preg_match('/\/faq$/', $uri)) {
                $attributesTableGateway = new AttributesTableGateway($this->adapter);
                $select = $attributesTableGateway->getSql()->select();
                $select->order([
                    "sort asc",
                    "id asc"
                ]);
                $where = new Where();
                $where->equalTo("parent_id", 0)->isNull("deleted_at");
                $select->where($where);
                $row = $attributesTableGateway->selectWith($select)->current();
                if ($row) {
                    $set["uri"] = $uri .= "/" . $row->id;
                }
            }
        }
        return parent::insert($set);
    }

    public static function mergeNavigationToFlat($navigation/*, $limit=6*/)
    {
        $merge = [];
        foreach ($navigation as $item) {
            if(isset($item["child"])){
                foreach ($item["child"]["data"] as $child) {
                    $merge[] = $child;
                }
            }else {
                $merge[] = $item;
            }
        }
        return $merge;
    }
}
