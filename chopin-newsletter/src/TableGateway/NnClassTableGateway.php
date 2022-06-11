<?php

namespace Chopin\Newsletter\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Chopin\Support\Registry;
use Laminas\Db\ResultSet\ResultSetInterface;
use Laminas\Db\Sql\Where;
use Laminas\Db\ResultSet\ResultSet;

class NnClassTableGateway extends AbstractTableGateway
{
    use \App\Traits\I18nTranslatorTrait;

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'nn_class';

    public function getIds(ServerRequestInterface $request)
    {
        $language_id = $request->getAttribute('language_id');
        $locale_id = $request->getAttribute('locale_id');
        $select = $this->getSql()->select();
        $select->columns([
            'id'
        ]);
        $where = $select->where;
        $where->equalTo('language_id', $language_id);
        $where->equalTo('locale_id', $locale_id);
        $where->isNull('deleted_at');
        $select->order([
            'sort ASC',
            'id DESC'
        ]);
        $resultSet = $this->selectWith($select);
        $result = [];
        foreach ($resultSet as $row) {
            $result[] = [
                "params" => [
                    "type" => $row->id
                ]
            ];
        }
        unset($resultSet);
        return $result;
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
        $mnClassHasNnClassTableGateway = new MnClassHasNnClassTableGateway($this->adapter);
        $select->join($mnClassHasNnClassTableGateway->table, "{$this->table}.id={$mnClassHasNnClassTableGateway->table}.mp_class_id", [
            "mn_class_id"
        ]);
        $where->isNull("deleted_at");
        $where->equalTo("mn_class_id", $parant_id);
        $select->order([
            "sort asc",
            "id desc"
        ]);
        $select->where($where);
        $result = $this->selectWith($select)->toArray();
        if (count($result) == 0) {
            return $result;
        }
        return $result;
    }

    /**
     *
     * @param int $mnId
     * @return ResultSetInterface $resultset
     */
    public function withInMnClass($mnId)
    {
        $pt = AbstractTableGateway::$prefixTable;
        $select = $this->sql->select();
        $select->order([
            "sort ASC",
            "id ASC"
        ]);
        $where = new Where();
        $where->isNull("deleted_at");
        $select->join("{$pt}mn_class_has_nn_class", "{$this->table}.id={$pt}mn_class_has_nn_class.nn_class_id", []);
        $where->equalTo("mn_class_id", $mnId);
        $select->where($where);
        return $this->selectWith($select);
    }

    /**
     *
     * @param int $newsId
     * @param array $columns
     * @return array
     */
    public function withInNews($newsId, $columns = null)
    {
        $pt = AbstractTableGateway::$prefixTable;
        $select = $this->sql->select();
        if ($columns) {
            $select->columns($columns);
        }
        $select->join("{$pt}nn_class_has_news", "{$pt}nn_class_has_news.nn_class_id={$this->table}.id", [])->join("{$pt}news", "{$pt}news.id={$pt}nn_class_has_news.news_id", []);
        $where = new Where();
        $where->equalTo("news_id", $newsId);
        $where->isNull("{$this->table}.deleted_at");
        $where->isNull("{$pt}news.deleted_at");
        $select->where($where);
        return $this->selectWith($select)->toArray();
    }

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
        $select->order([
            "sort asc",
            "id asc"
        ]);
        $select->where($where);
        $resultSet = $this->selectWith($select);
        $newResultset = new ResultSet();
        $dataSource = [];
        $nnClassTableGateway = new NnClassTableGateway($this->adapter);
        $where = new Where();
        $where->isNull("deleted_at");
        $where = $nnClassTableGateway->select($where);
        $rowUri = $request->getAttribute('rowUri');
        foreach ($resultSet as $row) {
            $nnClassId = $row->id;
            $row->with('uri', $rowUri."/{$nnClassId}");
            $dataSource[] = $row;
        }
        $newResultset->initialize($dataSource);
        return $newResultset;
    }
}
