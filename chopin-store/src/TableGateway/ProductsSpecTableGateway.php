<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Laminas\Db\RowGateway\RowGatewayInterface;
use Chopin\SystemSettings\TableGateway\AssetsTableGateway;

/**
 * @deprecated
 * @author hsien
 *
 */
class ProductsSpecTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_spec';

    /**
     *
     * @param int $productsId
     * @param bool $isJoinName
     * @return array
     */
    public function fetchJoinAttrs($productsId, $isJoinName = true)
    {
        $select = $this->sql->select();
        $select->order(["sort asc", "{$this->table}.id ASC"]);
        $pt = AbstractTableGateway::$prefixTable;
        $where = new Where();
        $where->equalTo("{$this->table}.products_id", $productsId);
        $where->isNull("{$this->table}.deleted_at");
        $where->isNull("{$pt}products_spec_attrs.deleted_at");
        $select->join(
            "{$pt}products_spec_attrs",
            "{$pt}products_spec_attrs.id={$this->table}.products_spec_attrs_id",
            ["name", "extra_name", "triple_name"]
        );
        $select->where($where);
        $result = $this->selectWith($select)->toArray();
        if ($isJoinName) {
            $names = [];
            foreach ($result as $item) {
                $names[] = $item["name"];
                $names = array_unique($names);
            }
            return $names;
        }
        return $result;
    }

    /**
     *
     * @param int $id
     * @return RowGatewayInterface
     */
    public function getLocaleRow($id)
    {
        $select = $this->sql->select();
        $select->columns(["id"]);
        $where = new Where();
        $where->equalTo("{$this->table}.id", $id);
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $select->join(
            $productsTableGateway->table,
            "{$productsTableGateway->table}.id={$this->table}.products_id",
            ["language_id", "locale_id"]
        );
        $select->where($where);
        $row = $this->selectWith($select)->current();
        return $row;
    }

    /**
     *
     * @param int $id
     * @return array
     */
    public function withAssets($id)
    {
        $assetsTableGateway = new AssetsTableGateway($this->adapter);
        $resultSet = $assetsTableGateway->select([
            "table" => $this->getTailTableName(),
            "table_id" => $id
        ]);
        return $resultSet->toArray();
    }
}
