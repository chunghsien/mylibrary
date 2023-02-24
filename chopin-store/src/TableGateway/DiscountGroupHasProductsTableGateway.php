<?php
namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\RowGateway\RowGateway;
use Laminas\Db\Sql\Join;
use Laminas\Db\Sql\Where;
use Laminas\Filter\Word\UnderscoreToCamelCase;
use Chopin\SystemSettings\TableGateway\AssetsTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Paginator\Adapter\LaminasDb\DbSelect;
use Laminas\Paginator\Paginator;

class DiscountGroupHasProductsTableGateway extends AbstractTableGateway
{

    public static $isRemoveRowGatewayFeature = true;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'discount_group_has_products';

    /**
     *
     * @param int $discount_group_id
     */
    public function getListFromDiscountGroupId(ServerRequestInterface $request, $discount_group_id)
    {
        $discountGroupTableGateway = new DiscountGroupTableGateway($this->adapter);
        $discountGroupRow = $discountGroupTableGateway->select([ 'id' => $discount_group_id ])->current();
        $method = $discountGroupRow->scope;
        $filter = new UnderscoreToCamelCase();
        $method = lcfirst($filter->filter($method));
        $resultset = $this->{$method}($request, $discountGroupRow);
        return $resultset;
    }

    private function byFpClass(RowGateway $row)
    {}

    private function byMpClass(RowGateway $row)
    {}

    private function byNpClass(RowGateway $row)
    {}
    
    private function productsWithOtherData($resultSet) {
        $assetsTableGateway = new AssetsTableGateway($this->adapter);
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $npClassTableGateway = new NpClassTableGateway($this->adapter);
        $npClassHasProductsTableGateway = new NpClassHasProductsTableGateway($this->adapter);
        $result = [];
        foreach ($resultSet as $item) {
            $assetsSelect = $assetsTableGateway->getSql()->select();
            $assetsSelect->columns([
                'id',
                'path'
            ])
            ->limit(1)
            ->where([
                'table' => $productsTableGateway->getTailTableName(),
                'table_id' => $item->id
            ])
            ->order('sort asc, id asc');
            $assetsRow = $assetsTableGateway->selectWith($assetsSelect)->current();
            if ($assetsRow) {
                $item->image = $assetsRow->path;
            }
            $npClassHasProductsSelect = $npClassHasProductsTableGateway->getSql()->select();
            $npClassHasProductsSelect->join($npClassTableGateway->table, "{$npClassHasProductsTableGateway->table}.np_class_id={$npClassTableGateway->table}.id", [
                "id",
                "name",
                "alias"
            ]);
            $npClassHasProductsWhere = $npClassHasProductsSelect->where;
            $npClassHasProductsWhere->isNull("deleted_at")->equalTo("{$npClassHasProductsTableGateway->table}.products_id", $item->id);
            $npClassHasProductsSelect->where($npClassHasProductsWhere);
            $npClassHasProductsResult = $npClassHasProductsTableGateway->selectWith($npClassHasProductsSelect);
            $className = [];
            foreach ($npClassHasProductsResult as $i) {
                $className[] = [
                    $i->alias != null ? $i->alias : $i->name,
                    $i->id
                ];
            }
            $item->className = json_encode($className, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $result[] = $item;
        }
        return $result;
    }
    
    private function byProduct(ServerRequestInterface $request, RowGateway $row)
    {
        $queryParams = $request->getQueryParams();
        $tableId = $queryParams['table_id'];
        ProductsTableGateway::$isRemoveRowGatewayFeature = true;
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $where = new Where();
        $where->isNull('deleted_at');
        $where->equalTo('language_id', $row->language_id);
        $where->equalTo('locale_id', $row->locale_id);
        $where->equalTo('products_combination_id', 0);
        $select = $productsTableGateway->sql->select();
        $select->columns([
            'id',
            'model',
            'alias'
        ])->quantifier('DISTINCT');
        $select->join(
            $this->table, "{$this->table}.products_id={$productsTableGateway->table}.id",
            []
        );
        $select->where(['discount_group_id' => $tableId]);
        $checkedResultset = $productsTableGateway->selectWith($select);
        $checkedproductsIds = [];
        $checkedResult = $this->productsWithOtherData($checkedResultset);
        foreach ($checkedResult as $prodrow) {
            $checkedproductsIds[] = $prodrow->id;
        }
        $get = $request->getQueryParams();
        $where = new Where();
        $where->equalTo("{$productsTableGateway->table}.language_id", $row->language_id);
        $where->equalTo("{$productsTableGateway->table}.locale_id", $row->locale_id);
        $where->isNull("{$productsTableGateway->table}.deleted_at");
        if($checkedproductsIds){
            $where->notIn("{$productsTableGateway->table}.id", $checkedproductsIds);
        }
        $select = $productsTableGateway->sql->select();
        if(isset($get['filters'])) {
            $filters = json_decode($get['filters']);
            if(isset($filters->className)) {
                $npClassHasProductsTableGateway = new NpClassHasProductsTableGateway($this->adapter);
                $select->join(
                    $npClassHasProductsTableGateway->table,
                    "{$npClassHasProductsTableGateway->table}.products_id={$productsTableGateway->table}.id",
                    []
                );
                $value = intval($filters->className->filterVal);
                $where->equalTo('np_class_id', $value);
            }
            if(isset($filters->model)) {
                $value = $filters->model->filterVal;
                $where->equalTo('model', $value);
                
            }
            if(isset($filters->alias)) {
                $value = $filters->alias->filterVal;
                $where->equalTo('alias', $value);
                
            }
        }
        $select->where($where);
        $notCheckedResultset = $productsTableGateway->selectWith($select);
        $notCheckedResult = $this->productsWithOtherData($notCheckedResultset);
        $items = array_merge($checkedResult, $notCheckedResult);
        $itemCountPerPage = count($items);
        $paginatorAdapter = new \Laminas\Paginator\Adapter\ArrayAdapter(array_fill(0, $itemCountPerPage, '1'));
        $paginator = new Paginator($paginatorAdapter);
        
        $pageNumber = isset($get['page']) ? ($get['page']) ?? 1: 1;
        $paginator->setDefaultItemCountPerPage($itemCountPerPage);
        $paginator->setCurrentPageNumber($pageNumber);
        $pages = $paginator->getPages();
        $pages->pagesInRange = array_values($pages->pagesInRange);
        return [
            "items" => $items,
            'selected' => $checkedproductsIds,
            'pages' => $pages,
        ];
    }
    
}
