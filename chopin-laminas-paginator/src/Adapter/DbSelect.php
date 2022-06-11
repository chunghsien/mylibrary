<?php

namespace Chopin\LaminasPaginator\Adapter;

use Laminas\Paginator\Adapter\LaminasDb\DbSelect as LaminasPaginatorAdapter;
use Chopin\LaminasDb\DB\Traits\CacheTrait;
use Laminas\Diactoros\ServerRequestFactory;

/**
 *
 * @author hsien
 * @desc 加入where 條件 參數綁定
 */
class DbSelect extends LaminasPaginatorAdapter
{
    use CacheTrait;

    protected $bindParams = [];

    public function setBindParams($bindParams)
    {
        $this->bindParams = $bindParams;
    }

    /**
     *
     * {@inheritDoc}
     * @see \Laminas\Paginator\Adapter\DbSelect::count()
     */
    public function count()
    {
        if ($this->rowCount !== null) {
            return $this->rowCount;
        }

        $select = $this->getSelectCount();
        $statement = $this->sql->prepareStatementForSqlObject($select);

        $result    = $statement->execute($this->bindParams);
        $row       = $result->current();

        $this->rowCount = (int) $row[self::ROW_COUNT_COLUMN_NAME];

        return $this->rowCount;
    }

    public function getItems($offset, $itemCountPerPage)
    {
        $select = clone $this->select;
        $select->offset($offset);
        $select->limit($itemCountPerPage);
        $statement = $this->sql->prepareStatementForSqlObject($select);
        if ($this->bindParams) {
            $result = $statement->execute($this->bindParams);
        } else {
            $result = $statement->execute();
        }

        $resultSet = clone $this->resultSetPrototype;
        $resultSet->initialize($result);
        return iterator_to_array($resultSet);
    }
}
