<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Having;
use Laminas\Db\ResultSet\ResultSetInterface;
use Laminas\Db\ResultSet\ResultSet;

class NpClassTableGateway extends AbstractTableGateway
{
    use \App\Traits\I18nTranslatorTrait;

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'np_class';

    public function withProduct($productId, $columns = null)
    {
        $select = $this->getSql()->select();
        if ($columns) {
            $select->columns($columns);
        }
        $pt = AbstractTableGateway::$prefixTable;
        $select->join(
            "{$pt}np_class_has_products",
            "{$this->table}.id={$pt}np_class_has_products.np_class_id"
        );
        $where = $select->where;
        $where->equalTo("products_id", $productId);
        $select->where($where);
        return $this->selectWith($select)->toArray();
    }

    public function getCategoryWithProductGroupCount(ServerRequestInterface $request, $limit = 0, $withRandProduct = true)
    {
        $languageId = $request->getAttribute("language_id");
        $localeId = $request->getAttribute("locale_id");
        $key = crc32(__CLASS__ . __METHOD__ . $limit . $languageId . $localeId);
        $result = null;
        if ($this->getEnvCacheUse()) {
            $result = $this->getCache($key);
        }
        if (! $result) {
            $select = $this->getSql()->select();
            $pt = AbstractTableGateway::$prefixTable;
            $select->join("{$pt}np_class_has_products", "{$this->table}.id={$pt}np_class_has_products.np_class_id", [
                "np_class_id",
                "count_product" => new Expression("COUNT(products_id)")
            ]);
            $select->join("{$pt}products", "{$pt}products.id={$pt}np_class_has_products.products_id", []);
            $where = new Where();
            $where->isNull("{$this->table}.deleted_at");
            $where->isNull("{$pt}products.deleted_at");
            $select->group("np_class_id");
            $select->where($where);
            $having = new Having();
            $having->greaterThanOrEqualTo("count_product", 1);
            if ($limit > 0) {
                $select->limit($limit);
            }
            $resultSet = $this->selectWith($select)/*->toArray()*/;
            $productsTableGateway = new ProductsTableGateway($this->adapter);
            $pt = AbstractTableGateway::$prefixTable;
            $result = [];
            foreach ($resultSet as $row) {
                /**
                 * @var \Chopin\LaminasDb\RowGateway\RowGateway $row
                 */
                $productsSelect = $productsTableGateway->getSql()->select();
                $productsSelect->columns(["id", "model"]);
                $productsSelect->join(
                    "{$pt}np_class_has_products",
                    "{$productsTableGateway->table}.id={$pt}np_class_has_products.products_id",
                    [],
                );
                $productsWhere = new Where();
                $productsWhere->equalTo("np_class_id", $row->id);
                $productsWhere->equalTo("is_show", 1);
                $productsWhere->isNull("deleted_at");
                $productsSelect->where($productsWhere);
                $productsSelect->order(new Expression("RAND()"));
                $productsSelect->limit(1);
                //logger()->info($this->sql->buildSqlString($productsSelect));
                /**
                 *
                 * @var \Chopin\Store\RowGateway\ProductsRowGateway $productsRow
                 */
                $productsRow = $productsTableGateway->selectWith($productsSelect)->current();
                if ($productsRow) {
                    $row->with("product", $productsRow->toArray());
                    $result[] = $row->toArray();
                }
                unset($productsRow);
            }
            unset($resultSet);
        }
        return $result;
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
        $npClassTableGateway = new NpClassTableGateway($this->adapter);
        $where = new Where();
        $where->isNull("deleted_at");
        $where = $npClassTableGateway->select($where);
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
