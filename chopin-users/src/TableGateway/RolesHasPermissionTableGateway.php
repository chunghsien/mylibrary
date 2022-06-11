<?php

namespace Chopin\Users\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;

//use Chopin\LaminasDb\DB\Traits\Profiling;

class RolesHasPermissionTableGateway extends AbstractTableGateway
{
    //use Profiling;
    public static $isRemoveRowGatewayFeature = true;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'roles_has_permission';

    public function buildTemplates($roles_id, $result = [])
    {
        $permissionTableGateway = new PermissionTableGateway($this->adapter);
        $where = new Where();
        $select = $permissionTableGateway->getSql()
            ->select()
            ->columns([
            "id"
        ]);
        $where->isNull("deleted_at");
        $select->where($where);
        $resultset = $permissionTableGateway->selectWith($select);
        $templates = [];
        foreach ($resultset as $row) {
            $item = null;
            if ($result) {
                $item = array_filter($result, function ($filter) use ($row) {
                    return $filter["permission_id"] == $row->id;
                });
                $item = array_values($item);
                if ($item) {
                    $item = $item[0];
                }
            }
            if ($item) {
                $templates[] = $item;
            } else {
                $templates[] = [
                    "roles_id" => $roles_id,
                    "permission_id" => $row->id,
                    "is_view" => 0,
                    "is_insert" => 0,
                    "is_edit" => 0,
                    "is_del" => 0
                ];
            }
        }
        return $templates;
    }
}
