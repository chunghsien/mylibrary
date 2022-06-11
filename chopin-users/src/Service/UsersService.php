<?php

namespace Chopin\Users\Service;

use Laminas\Db\Adapter\Adapter;
use Chopin\Users\TableGateway\PermissionTableGateway;
use Chopin\Users\TableGateway\RolesTableGateway;
use Chopin\Users\TableGateway\UsersTableGateway;
use Chopin\Users\TableGateway\UsersProfileTableGateway;
use Chopin\Users\TableGateway\UsersHasRolesTableGateway;
use Chopin\LaminasDb\DB;
use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Chopin\Users\TableGateway\RolesHasPermissionTableGateway;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\Sql\Where;

class UsersService
{
    /**
     *
     * @var PermissionTableGateway
     */
    private $permissionTableGateway;

    /**
     *
     * @var RolesTableGateway
     */
    private $rolesTableGateway;

    /**
     *
     * @var RolesHasPermissionTableGateway
     */
    private $rolesHasPermissionTableGateway;

    /**
     *
     * @var UsersTableGateway
     */
    private $usersTableGateway;

    /**
     *
     * @var UsersProfileTableGateway
     */
    private $usersProfileTableGateway;

    /**
     *
     * @var UsersHasRolesTableGateway
     */
    private $usersHasRolesTableGateway;

    /**
     *
     * @var Adapter
     */
    private $adapter;

    public function __construct(Adapter $adapter)
    {
        $this->permissionTableGateway = new PermissionTableGateway($adapter);
        $this->rolesHasPermissionTableGateway = new RolesHasPermissionTableGateway($adapter);
        $this->rolesTableGateway = new RolesTableGateway($adapter);
        $this->usersTableGateway = new UsersTableGateway($adapter);
        $this->usersProfileTableGateway = new UsersProfileTableGateway($adapter);
        $this->usersHasRolesTableGateway = new UsersHasRolesTableGateway($adapter);
        $this->adapter = $adapter;
    }

    /**
     *
     * @param int $usersId
     * @param bool $isShowName
     * @return array
     */
    public function getUserAllowedPermission(int $usersId, bool $isShowName = false): array
    {
        //$PT = AbstractTableGateway::$prefixTable;
        $select = $this->usersTableGateway->getSql()->select();
        $select->columns([]);
        $select->join($this->usersHasRolesTableGateway->table, "{$this->usersTableGateway->table}.id={$this->usersHasRolesTableGateway->table}.users_id", [], );
        $select->join($this->rolesTableGateway->table, "{$this->rolesTableGateway->table}.id={$this->usersHasRolesTableGateway->table}.roles_id", [], );
        $select->join($this->rolesHasPermissionTableGateway->table, "{$this->rolesHasPermissionTableGateway->table}.roles_id={$this->rolesTableGateway->table}.id", [], );

        $select->join($this->permissionTableGateway->table, "{$this->rolesHasPermissionTableGateway->table}.permission_id={$this->permissionTableGateway->table}.id", [
            'uri',
            'name',
            'is_no_upgrade_use'
        ], );
        $where = new Where();
        $where->equalTo("{$this->usersTableGateway->table}.id", $usersId);
        $where->isNull("{$this->usersTableGateway->table}.deleted_at");
        $where->isNull("{$this->permissionTableGateway->table}.deleted_at");
        $select->where($where);

        $sql = $this->usersTableGateway->getSql();
        $dataSource = $sql->prepareStatementForSqlObject($select)->execute();
        $usersPermissionResultSet = new ResultSet();
        $usersPermissionResultSet->initialize($dataSource);
        if ($isShowName) {
            return $usersPermissionResultSet->toArray();
        } else {
            $user = [];
            foreach ($usersPermissionResultSet as $row) {
                $user[] = $row['uri'];
            }
            unset($usersPermissionResultSet);
            return $user;
        }
    }

    /**
     *
     * @param int $usersId
     * @return array
     */
    public function getDenyPermission(int $usersId): array
    {
        $PT = AbstractTableGateway::$prefixTable;
        $allPermissionsResultset = DB::selectFactory([
            'from' => $PT.'permission',
            'columns' => [['uri']],
            'where' => [
                ['isNull', 'and', [$PT.'permission.deleted_at']]
            ]
        ]);
        $all = [];
        $user = $this->getUserAllowedPermission($usersId);
        ;
        foreach ($allPermissionsResultset as $row) {
            $all[] = $row['uri'];
        }
        unset($allPermissionsResultset);
        $deny = array_diff($all, $user);
        return $deny;
    }
}
