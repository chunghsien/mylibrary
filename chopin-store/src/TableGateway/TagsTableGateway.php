<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;

class TagsTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'tags';

    public function withProducts($productId, $columns = null)
    {
        $select = $this->sql->select();
        if ($columns) {
            $select->columns($columns);
        }
        $pt = AbstractTableGateway::$prefixTable;
        $select->join(
            "{$pt}tags_has_products",
            "{$this->table}.id={$pt}tags_has_products.tags_id",
            []
        );
        $where = new Where();
        $where->equalTo("products_id", $productId);
        $select->where($where);
        return $this->selectWith($select)->toArray();
    }

    public function withNews($newsId, $columns = null)
    {
        $select = $this->sql->select();
        if ($columns) {
            $select->columns($columns);
        }
        $pt = AbstractTableGateway::$prefixTable;
        $select->join(
            "{$pt}tags_has_news",
            "{$this->table}.id={$pt}tags_has_news.tags_id",
            []
        );
        $where = new Where();
        $where->equalTo("news_id", $newsId);
        $select->where($where);
        return $this->selectWith($select)->toArray();
    }
}
