<?php

namespace Chopin\Store\RowGateway;

use Chopin\LaminasDb\RowGateway\RowGateway;
use Chopin\Store\TableGateway\NpClassTableGateway;
use Chopin\Store\TableGateway\NpClassHasProductsTableGateway;
use Chopin\Store\TableGateway\ProductsSpecGroupTableGateway;
use Chopin\SystemSettings\TableGateway\AssetsTableGateway;
use Chopin\Store\TableGateway\ProductsSpecTableGateway;
use Laminas\Db\Sql\Expression;
use Chopin\Store\TableGateway\ProductsSpecAttrsTableGateway;
use Chopin\Store\TableGateway\ProductsSpecGroupAttrsTableGateway;
use Chopin\Store\TableGateway\ProductsDiscountTableGateway;
use Chopin\Store\TableGateway\ProductsRatingTableGateway;
use Laminas\Db\Sql\Where;
use Chopin\Store\TableGateway\ProductsTableGateway;
use Chopin\Store\TableGateway\ProductsCombinationTableGateway;
use Chopin\LaminasDb\DB;

class ProductsRowGateway extends RowGateway
{
    protected $table = 'products';

    protected $primaryKeyColumn = [
        "id"
    ];

    public function withDiscount()
    {
        $adapter = $this->sql->getAdapter();
        $productsDiscountTableGateway = new ProductsDiscountTableGateway($adapter);
        $productsCombinationTableGateway = new ProductsCombinationTableGateway($adapter);
        $select = $productsDiscountTableGateway->getSql()->select();
        $select->columns([
            'id',
            'products_combination_id',
            'discount',
            'discount_unit'
        ]);
        $select->join(
            $productsCombinationTableGateway->table,
            "{$productsCombinationTableGateway->table}.id={$productsDiscountTableGateway->table}.products_combination_id",
            [
            "price",
            "real_price"
        ]
        );
        $where = $select->where;
        $id = $this->data["id"];
        $productsCombinationResultset = $productsCombinationTableGateway->select(["products_id" => $id]);
        $productsCombinatioIdsIn = [];
        foreach ($productsCombinationResultset as $row) {
            $productsCombinatioIdsIn[] = $row->id;
        }
        $where->in("{$productsDiscountTableGateway->table}.products_combination_id", $productsCombinatioIdsIn);
        $where->greaterThanOrEqualTo("end_date", date("Y-m-d H:i:s"));
        $where->lessThanOrEqualTo("start_date", date("Y-m-d H:i:s"));
        $where->isNull("{$productsDiscountTableGateway->table}.deleted_at");
        $select->where($where);
        $resultSet = $productsDiscountTableGateway->selectWith($select);
        $items = [];
        $discountRange = [];
        $discountPriceRange = [];
        foreach ($resultSet as $row) {
            $discount = intval($row->discount);
            $discountRange[] = $discount;
            $realPrice = $row->real_price;
            $item = $row->toArray();
            if ($item["discount_unit"] == "percent") {
                $item['discount_price'] = $realPrice * ((100 - $discount) / 100);
            } else {
                $item['discount_price'] = $realPrice - $discount;
            }

            $discountPriceRange[] = $item['discount_price'];
            $items[] = $item;
        }
        $this->with["discount"] = $items;
        if (count($discountRange) > 0) {
            if (count($discountRange) == 1) {
                $this->with["discountRange"] = $discountRange;
            } else {
                $this->with["discountRange"] = [
                    min($discountRange),
                    max($discountRange)
                ];
            }
        }
        if (count($discountPriceRange) > 0) {
            if (count($discountPriceRange) == 1) {
                $this->with["discountPriceRange"] = $discountPriceRange;
            } else {
                $this->with["discountPriceRange"] = [
                    min($discountPriceRange),
                    max($discountPriceRange)
                ];
            }
        }
    }

    public function withRatingAvg()
    {
        $adapter = $this->sql->getAdapter();
        DB::mysql8HigherGroupByFix();
        $productsRatingTableGateway = new ProductsRatingTableGateway($adapter);
        $select = $productsRatingTableGateway->getSql()->select();
        $select->columns([
            'id',
            "rating",
            "avg_rating" => new Expression("AVG(rating)"),
            "count_rating" => new Expression("COUNT(rating)")
        ]);
        $where = $select->where;
        $id = $this->data["id"];
        $where->equalTo('products_id', $id);
        $where->isNull("deleted_at");
        $select->where($where);
        $select->group("products_id");
        $row = $productsRatingTableGateway->selectWith($select)->current();
        if (! $row) {
            $this->with["rated"] = 0;
            $this->with["countRated"] = 0;
        } else {
            $this->with["rated"] = $row->avg_rating;
            $this->with["countRated"] = $row->count_rating;
        }
    }

    /**
     *
     * @param int $id
     */
    public function withItemSumStock()
    {
        ProductsCombinationTableGateway::$isRemoveRowGatewayFeature = true;
        $adapter = $this->sql->getAdapter();
        $productsCombinationTableGateway = new ProductsCombinationTableGateway($adapter);
        // ;
        $select = $productsCombinationTableGateway->getSql()->select();
        $where = $select->where;
        //$where->isNull('deleted_at');
        $id = $this->id;
        $where->equalTo('products_id', $id);
        $select->columns([
            'sum_stock' => new Expression("SUM(`stock`)")
        ]);
        DB::mysql8HigherGroupByFix();
        $item = $productsCombinationTableGateway->selectWith($select)->current();
        ProductsCombinationTableGateway::$isRemoveRowGatewayFeature = false;
        $this->with["sum_stock"] = intval($item['sum_stock']);
    }

    public function withAssets()
    {
        $productsId = $this->data["id"];
        $adapter = $this->sql->getAdapter();
        $assetsTableGateway = new AssetsTableGateway($adapter);
        $select = $assetsTableGateway->getSql()->select();
        $select->columns(['id', 'path']);
        $predicate = $select->where;
        $predicate->equalTo('table', "products");
        $predicate->equalTo('table_id', $productsId);
        $select->where($predicate);
        $select->order('sort asc, id asc');
        $resultSet = $assetsTableGateway->selectWith($select);
        $withData = [];
        foreach ($resultSet as /*$key =>*/$row) {
            $withData[] = $row->path;
        }
        $this->with['image'] = $withData;
    }

    public function withNpClass($complex = false)
    {
        $npClassTableGateway = new NpClassTableGateway($this->sql->getAdapter());
        $npClassHasProductsTableGateway = new NpClassHasProductsTableGateway($this->sql->getAdapter());
        $select = $npClassHasProductsTableGateway->getSql()->select();
        $select->columns([]);
        $products_id = $this->data['id'];
        $select->join($npClassTableGateway->table, "{$npClassHasProductsTableGateway->table}.np_class_id={$npClassTableGateway->table}.id", [
            "id",
            "name"
        ]);
        $predicate = $select->where;
        $predicate->equalTo("{$npClassHasProductsTableGateway->table}.products_id", $products_id);
        $select->where($predicate);
        $resultSet = $npClassHasProductsTableGateway->selectWith($select);
        $data = [];
        foreach ($resultSet as $row) {
            if ($complex) {
                $data[] = $row;
            } else {
                $data[] = $row['name'];
            }
        }
        $this->with["np_class"] = $data;
    }

    /**
     *
     * @param string $column
     * @param string $value
     * @param array $columns
     * @param boolean $isToArray
     */
    public function withNext($column, $value, $columns = [
        "*"
    ], $isToArray = false)
    {
        $select = $this->sql->select();
        $select->columns($columns);
        $where = new Where();
        $where->equalTo('is_show', 1);
        $where->greaterThan($column, $value);
        $where->isNull("deleted_at");
        $select->order("{$column} ASC");
        $select->limit(1);
        $productsTableGateway = new ProductsTableGateway($this->sql->getAdapter());
        $select->where($where);
        $row = $productsTableGateway->selectWith($select)->current();
        if ($isToArray) {
            $row = isset($row) ? $row->toArray() : [];
        }
        $this->with['next'] = $row;
        // return $row;
    }

    /**
     *
     * @param string $column
     * @param string $value
     * @param array $columns
     * @param boolean $isToArray
     */
    public function withPrev($column, $value, $columns = [
        "*"
    ], $isToArray = false)
    {
        $select = $this->sql->select();
        $select->columns($columns);
        $where = new Where();
        $where->lessThan($column, $value);
        $where->equalTo('is_show', 1);
        $where->isNull("deleted_at");
        $select->limit(1);
        $select->order("{$column} DESC");
        $productsTableGateway = new ProductsTableGateway($this->sql->getAdapter());
        $select->where($where);
        $row = $productsTableGateway->selectWith($select)->current();
        if ($isToArray) {
            $row = isset($row) ? $row->toArray() : [];
        }
        $this->with['prev'] = $row;
    }

    /**
     * @deprecated
     * @param int $id
     */
    public function withSpec($id = null)
    {
        $productsSpecTableGateway = new ProductsSpecTableGateway($this->sql->getAdapter());
        $productsSpecAttrsTableGateway = new ProductsSpecAttrsTableGateway($this->sql->getAdapter());
        $select = $productsSpecTableGateway->getSql()->select();
        $select->columns([
            "id",
            "stock",
            "stock_status"
        ]);
        $select->join($productsSpecAttrsTableGateway->table, "{$productsSpecTableGateway->table}.products_spec_attrs_id={$productsSpecAttrsTableGateway->table}.id", [
            "name",
            "extra_name",
            "triple_name"
        ]);
        $select->quantifier("DISTINCT");
        $where = $select->where;
        $where->isNull("{$productsSpecTableGateway->table}.deleted_at");
        $where->isNull("{$productsSpecAttrsTableGateway->table}.deleted_at");
        if ($id) {
            $where->equalTo("{$productsSpecTableGateway->table}.id", $id);
        }
        $products_id = $this->data["id"];
        $where->equalTo('products_id', $products_id);

        $dataSource = $productsSpecTableGateway->getSql()
            ->prepareStatementForSqlObject($select)
            ->execute();
        $resultSet = new \Laminas\Db\ResultSet\ResultSet();
        $resultSet->initialize($dataSource);
        if ($id) {
            $this->with['spec'] = $resultSet->current();
            return;
        }
        $this->with['spec'] = $resultSet->toArray();
    }

    /**
     * @deprecated
     * @param int $specGroupId
     * @param int $specId
     * @return []
     */
    protected function getSpecGroupWithSpec($specGroupId, $specId = null)
    {
        $productsSpecTableGateway = new ProductsSpecTableGateway($this->sql->getAdapter());
        $where = $productsSpecTableGateway->getSql()->select()->where;
        if ($specId) {
            $where->equalTo("{$productsSpecTableGateway->table}.id", $specId);
        }
        $where->greaterThan('real_price', 0);
        $where->isNull('deleted_at');
        $where->equalTo('products_spec_group_id', $specGroupId);
        $specsResultSet = $productsSpecTableGateway->select($where)/*->toArray()*/;
        $specsResult = [];
        $productsSpecAttrsTableGateway = new ProductsSpecAttrsTableGateway($this->sql->getAdapter());
        foreach ($specsResultSet as $spec) {
            $spec = $spec->toArray();
            $productsSpecAttrsItem = $productsSpecAttrsTableGateway->select([
                "id" => $spec["products_spec_attrs_id"]
            ])
                ->current()
                ->toArray();
            $spec["name"] = $productsSpecAttrsItem["name"];
            $specsResult[] = $spec;
        }
        if (count($specsResult) == 1) {
            return $specsResult[0];
        }
        return $specsResult;
    }

    public function withCombinationOptions()
    {
        $productsId = $this->id;
        $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->sql->getAdapter());
        $result = $productsCombinationTableGateway->getListUseProductsId($productsId);
        $this->with('combinationOptions', $result);
    }

    /**
     * @deprecated
     * @param int $id as products_spec_group_id
     * @param int $spec_id
     */
    public function withSpecGroup($id = null, $spec_id = null)
    {
        // ProductsSpecGroupTableGateway::$isRemoveRowGatewayFeature = true;
        $productsSpecGroupTableGateway = new ProductsSpecGroupTableGateway($this->sql->getAdapter());
        $productsSpecGroupAttrsTableGateway = new ProductsSpecGroupAttrsTableGateway($this->sql->getAdapter());
        $where1 = $productsSpecGroupTableGateway->getSql()->select()->where;
        $where1->isNull("{$productsSpecGroupTableGateway->table}.deleted_at");
        $where1->equalTo('products_id', $this->data['id']);

        if ($id) {
            $where1->equalTo("{$productsSpecGroupTableGateway->table}.id", $id);
            $select2 = $productsSpecGroupTableGateway->getSql()->select();
            $select2->join($productsSpecGroupAttrsTableGateway->table, "{$productsSpecGroupTableGateway->table}.products_spec_group_attrs_id={$productsSpecGroupAttrsTableGateway->table}.id", [
                "name",
                "extra_name",
                "attr_image",
                "is_color_code"
            ]);

            $select2->where($where1);
            $specGroup = $productsSpecGroupTableGateway->selectWith($select2)->current();
            if ($spec_id) {
                $specGroup['spec'] = $this->getSpecGroupWithSpec($id, $spec_id);
                if (! $specGroup['spec']) {
                    unset($specGroup['spec']);
                }
            }
            $this->with('spec_group', $specGroup);
            return;
        }
        $select1 = $productsSpecGroupTableGateway->getSql()->select();
        $select1->where($where1);

        $select1->join($productsSpecGroupAttrsTableGateway->table, "{$productsSpecGroupTableGateway->table}.products_spec_group_attrs_id={$productsSpecGroupAttrsTableGateway->table}.id", [
            "name",
            "extra_name",
            "attr_image",
            "is_color_code"
        ]);
        $where1->isNull("{$productsSpecGroupAttrsTableGateway->table}.deleted_at");
        $resultSet = $productsSpecGroupTableGateway->selectWith($select1);
        // ProductsSpecTableGateway::$isRemoveRowGatewayFeature = true;
        $productsSpecTableGateway = new ProductsSpecTableGateway($this->sql->getAdapter());
        $productsSpecAttrsTableGateway = new ProductsSpecAttrsTableGateway($this->sql->getAdapter());
        $result = [];
        $productsDiscountTableGateway = new ProductsDiscountTableGateway($this->sql->getAdapter());
        foreach ($resultSet as $row) {
            if (! $id) {
                $where = $productsSpecTableGateway->getSql()->select()->where;
                if ($spec_id) {
                    $where->equalTo("{$productsSpecTableGateway->table}.id", $spec_id);
                }
                $where->isNull("{$productsSpecTableGateway->table}.deleted_at");
                $where->isNull("{$productsSpecAttrsTableGateway->table}.deleted_at");
                $where->equalTo("{$productsSpecTableGateway->table}.products_spec_group_id", $row->id);
                $select = $productsSpecTableGateway->getSql()->select();
                $select->columns([
                    'id',
                    'stock',
                    'stock_status',
                    'price',
                    'real_price'
                ]);
                $select->join($productsSpecAttrsTableGateway->table, "{$productsSpecTableGateway->table}.products_spec_attrs_id={$productsSpecAttrsTableGateway->table}.id", [
                    "name",
                    "extra_name",
                    "triple_name"
                ]);
                $discountExpression = "(100-{$productsDiscountTableGateway->table}.discount) / 100 * {$productsSpecTableGateway->table}.real_price";
                $select->join($productsDiscountTableGateway->table, "{$productsDiscountTableGateway->table}.products_spec_id={$productsSpecTableGateway->table}.id", [
                    "discount",
                    "discount_price" => new Expression($discountExpression),
                    "start_date",
                    "end_date"
                ]);

                // $select->quantifier("DISTINCT");
                $select->where($where);
                $select->order("{$productsSpecTableGateway->table}.sort asc, {$productsSpecTableGateway->table}.id asc");
                $specsResult = $productsSpecTableGateway->selectWith($select)->toArray();
                $spec = [];
                $toDayTime = strtotime("today");
                foreach ($specsResult as $specItem) {
                    $specItem["image"] = $productsSpecTableGateway->withAssets($specItem['id']);
                    $startTimeStamp = strtotime($specItem["start_date"]);
                    $endTimeStamp = strtotime($specItem["end_date"]);
                    if ($startTimeStamp <= $toDayTime && $endTimeStamp >= $toDayTime) {
                        $specItem["discount_price"] = floatval($specItem["discount_price"]);
                    } else {
                        $specItem["discount_price"] = 0;
                    }
                    $spec[] = $specItem;
                }
                unset($specsResult);
                if ($spec) {
                    $row->with('spec', $spec);
                }
            }
            $row->with('image', $productsSpecGroupTableGateway->withAssets($row->id));
            $result[] = $row->toArray();
        }
        unset($resultSet);
        $this->with('spec_group', $result);
    }
}
