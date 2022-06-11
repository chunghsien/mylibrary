<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;

class ManufacturesTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'manufactures';

    public function getList(ServerRequestInterface $request)
    {
        $select = $this->getSql()->select();
        $select->order([
            "{$this->table}.sort asc",
            "{$this->table}.id asc"
        ]);
        $where = $select->where;
        $where->isNull("{$this->table}.deleted_at");
        $languageId = $request->getAttribute("language_id");
        $localeId = $request->getAttribute("locale_id");
        $where->equalTo("{$this->table}.language_id", $languageId);
        $where->equalTo("{$this->table}.locale_id", $localeId);
        $select->where($where);
        $resultSet = $this->selectWith($select);
        $result = [];
        $attributesTableGateway = new AttributesTableGateway($this->adapter);

        foreach ($resultSet as $row) {
            if ($attributesTableGateway->select(["table" => $this->getTailTableName(), "table_id" => $row->id])->count() > 0) {
                $row->isAttribute = true;
            } else {
                $row->isAttribute = false;
            }
            $result[] = $row->toArray();
        }
        unset($resultSet);
        return $result;
    }
}
