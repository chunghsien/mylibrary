<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Predicate\PredicateInterface;
use Laminas\Db\Sql\Where;
use Laminas\Db\RowGateway\RowGatewayInterface;
use Chopin\Store\RowGateway\ProductsRowGateway;
use Mezzio\Router\RouteResult;
use Laminas\Paginator\Adapter\LaminasDb\DbSelect;
use Laminas\Paginator\Paginator;
use Chopin\Documents\TableGateway\LayoutZonesTableGateway;
use Laminas\Db\Sql\Join;
use Chopin\LaminasDb\DB;

class ProductsTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    public static $isJoinProductsRating = false;

    public static $isJoinProductsDiscount = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products';

    private $product_type_options = [
        ["value" => 1, "label" => "product_type_options_1"],
        ["value" => 2, "label" => "product_type_options_2"],
        ["value" => 4, "label" => "product_type_options_4"],
    ];

    protected $stockStatusOptions = [
        0 => "stock_status_normal", // 正常供貨
        10 => "stock_status_pre_order" // 預購
    ];

    protected $stockStatusOptionsReverse = [
        10 => "stock_status_sold_out", // 售完
        20 => "stock_status_out_off_stock" // 缺貨
    ];

    public function getTypeOptions()
    {
        return $this->product_type_options;
    }

    public function getTabs(ServerRequestInterface $request, $tabs, $orders, $extraWhere = [])
    {
        $contents = [];
        foreach ($orders as $orderBy) {
            $limit = config('lezada.tabLimit');
            $content = $this->getList($request, $extraWhere, $orderBy, $limit);
            $contents[] = $content["products"];
        }
        unset($orders);
        return [
            "tabs" => $tabs,
            "contents" => $contents,
        ];
    }

    public function getPriceRange(ServerRequestInterface $request = null)
    {
        if ($request instanceof ServerRequestInterface /*&& isset($queryParams["min_price"]) && isset($queryParams["max_price"])*/) {
            $queryParams = $request->getQueryParams();
            if (isset($queryParams["min_price"]) && isset($queryParams["max_price"])) {
                return [
                    $queryParams["min_price"],
                    $queryParams["max_price"]
                ];
            }
        }
        $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
        $select = $productsCombinationTableGateway->getSql()->select();
        $select->columns([
            "id",
            "real_price"
        ]);
        $select->limit(1)->order("real_price DESC");
        $maxPriceRow = $this->selectWith($select)->current();
        $select->reset('order')->order('real_price ASC');
        $minPriceRow = $this->selectWith($select)->current();
        if (! $minPriceRow) {
            return [];
        }
        return [
            $minPriceRow->real_price,
            $maxPriceRow->real_price
        ];
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @param PredicateInterface $extraWhere
     * @param string|array $orderBy
     * @param int $limit
     * @param int $page
     * @return RowGatewayInterface[]
     */
    public function getList(ServerRequestInterface $request, $extraWhere, $orderBy, $limit = 20)
    {
        $queryParams = $request->getQueryParams();
        $page = isset($queryParams["page"]) ? intval($queryParams["page"]) : 1;
        $select = $this->getSelectPart($request);
        $select->quantifier('DISTINCT');
        /**
         *
         * @var $where Where
         */
        $where = $select->getRawState("where");
        if ($extraWhere instanceof PredicateInterface) {
            if ($extraWhere->count()) {
                $where->addPredicate($extraWhere);
            }
        }
        /**
         *
         * @var \Mezzio\Router\RouteResult $routeResult
         */
        $routeResult = $request->getAttribute(\Mezzio\Router\RouteResult::class);
        $pt = AbstractTableGateway::$prefixTable;
        $matchedParams = $routeResult->getMatchedParams();
        if ($routeResult->getMatchedRouteName() == "category" || (isset($matchedParams["action"]) && $matchedParams["action"] == 'category')) {
            $methodOrId = $request->getAttribute("methodOrId");
            $categoryId = null;
            if (preg_match("/^fp_class\-/", $methodOrId)) {
                $tmp = explode("-", $methodOrId);
                $categoryId = $tmp[1];
            }

            if (preg_match("/^mp_class\-/", $methodOrId)) {
                $tmp = explode("-", $methodOrId);
                $categoryId = $tmp[1];
            }
            $select->join("{$pt}np_class_has_products", "{$this->table}.id={$pt}np_class_has_products.products_id", [], Join::JOIN_LEFT);
            if ($categoryId) {
                if (preg_match("/^fp_class\-/", $methodOrId)) {
                    $select->join("{$pt}np_class", "{$pt}np_class_has_products.np_class_id={$pt}np_class.id", [], Join::JOIN_LEFT);
                    $select->join("{$pt}mp_class_has_np_class", "{$pt}np_class.id = {$pt}mp_class_has_np_class.np_class_id", [], Join::JOIN_LEFT);
                    $select->join("{$pt}mp_class", "{$pt}mp_class.id = {$pt}mp_class_has_np_class.mp_class_id", []);
                    $select->join("{$pt}fp_class_has_mp_class", "{$pt}mp_class.id = {$pt}fp_class_has_mp_class.fp_class_id", [], Join::JOIN_LEFT);
                    $where->equalTo("{$pt}fp_class_has_mp_class.fp_class_id", $categoryId);
                } elseif (preg_match("/^mp_class\-/", $methodOrId)) {
                    $select->join("{$pt}np_class", "{$pt}np_class_has_products.np_class_id={$pt}np_class.id", [], Join::JOIN_LEFT);
                    $select->join("{$pt}mp_class_has_np_class", "{$pt}np_class.id = {$pt}mp_class_has_np_class.np_class_id", [], Join::JOIN_LEFT);
                    $where->equalTo("{$pt}mp_class_has_np_class.mp_class_id", $categoryId);
                }
            } else {
                if (preg_match('/^\d+$/', $methodOrId)) {
                    $where->equalTo("{$pt}np_class_has_products.np_class_id", $methodOrId);
                } else {
                    $language_id = $request->getAttribute("language_id");
                    $locale_id = $request->getAttribute("locale_id");
                    $npClassTableGateway = new NpClassTableGateway($this->adapter);
                    $npClassWhere = new Where();
                    $npClassWhere->equalTo("language_id", $language_id);
                    $npClassWhere->equalTo("locale_id", $locale_id);
                    $npClassWhere->equalTo("url_id", $methodOrId);
                    $npClassWhere->isNull("deleted_at");
                    $npClassRow = $npClassTableGateway->select($npClassWhere)->current();
                    if ($npClassRow) {
                        $np_class_id = $npClassRow->id;
                        $where->equalTo("{$pt}np_class_has_products.np_class_id", $np_class_id);
                    }
                }
            }
        }
        $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
        // $productsSpecTableGateway = new ProductsSpecTableGateway($this->adapter);
        $select->join($productsCombinationTableGateway->table, "{$productsCombinationTableGateway->table}.products_id={$this->table}.id", [
            "price",
            "real_price"
        ], Join::JOIN_LEFT);
        $select->group("{$productsCombinationTableGateway->table}.products_id");
        $select->order($orderBy);
        $where->greaterThan("{$productsCombinationTableGateway->table}.real_price", 0);
        $select->where($where);
        DB::mysql8HigherGroupByFix();
        $pagiAdapter = new DbSelect($select, $this->adapter);
        $paginator = new Paginator($pagiAdapter);
        $paginator->setItemCountPerPage($limit);
        $paginator->setCurrentPageNumber($page);
        $items = (array) $paginator->getCurrentItems();
        $result = [];
        foreach ($items as $item) {
            /**
             *
             * @var ProductsRowGateway $row
             */
            $row = new ProductsRowGateway($this->adapter);
            $row->exchangeArray((array) $item);
            $row->withAssets();
            $row->withDiscount();
            $row->withRatingAvg();
            $row->withCombinationOptions();
            $row->withItemSumStock();
            $result[] = $row->toArray();
        }
        unset($items);
        foreach ($result as $key => $productItem) {
            $sum_stock = 0;
            $productItem[$key]["sum_stock"] = $sum_stock;
        }
        $paginator = $paginator->getPages();
        $paginator->pagesInRange = array_values($paginator->pagesInRange);
        return [
            "products" => $result,
            "paginator" => [
                "pages" => (array) $paginator
            ]
        ];
    }

    protected function getSelectPart(ServerRequestInterface $request)
    {
        $select = $this->sql->select();
        $select->columns([
            "id",
            "manufactures_id",
            "model",
            "introduction",
            "is_new",
            "is_hot",
            "is_recommend",
            "is_show",
            "viewed_count",
            "sale_count",
            "sort"
        ]);
        $where = new Where();
        $where->isNull("{$this->table}.deleted_at");
        $where->equalTo("{$this->table}.is_show", 1);
        $languageId = $request->getAttribute("language_id");
        $localeId = $request->getAttribute("locale_id");
        $where->equalTo("{$this->table}.language_id", $languageId);
        $where->equalTo("{$this->table}.locale_id", $localeId);
        /**
         *
         * @var \Mezzio\Router\RouteResult $routeResult
         */
        $routeResult = $request->getAttribute(\Mezzio\Router\RouteResult::class);
        if (! preg_match("/admin|system_maintain/", $routeResult->getMatchedRouteName())) {
            $where->equalTo("{$this->table}.is_show", 1);
            // $queryParams = $request->getQueryParams();
        }
        $pt = AbstractTableGateway::$prefixTable;
        $select->join("{$pt}manufactures", "{$pt}products.manufactures_id={$pt}manufactures.id", [
            "manufacture" => "name"
        ], "LEFT");

        if (self::$isJoinProductsRating == true) {
            $adapter = $this->sql->getAdapter();
            DB::mysql8HigherGroupByFix();
            $select->join("{$pt}products_rating", "{$this->table}.id={$pt}products_rating.products_id", [
                "rating"
            ], "LEFT");
            $select->group("{$pt}products.id");
        }
        $select->where($where);
        return $select;
        // $select->order($orderBy);
    }

    public function getSideBar(ServerRequestInterface $request)
    {
        $vars = [
            "items" => []
        ];
        $methodOrId = $request->getAttribute("methodOrId");
        $filterTable = "np_class";
        $filterId = 0;
        if ($methodOrId && preg_match('/\-/', $methodOrId)) {
            $methodOrId = explode("-", $methodOrId);
            if (count($methodOrId) == 2) {
                $filterTable = $methodOrId[0];
                $filterId = trim($methodOrId[1]);
            } else {
                $filterId = $methodOrId[0];
            }
            $filterId = intval($filterId);
        }
        $result = null;
        $fpClassTableGateway = new FpClassTableGateway($this->adapter);
        $mpClassTableGateway = new MpClassTableGateway($this->adapter);
        $npClassTableGateway = new NpClassTableGateway($this->adapter);
        $mpClassHasNpClassTableGateway = new MpClassHasNpClassTableGateway($this->adapter);

        $fpClassSelect = $fpClassTableGateway->getSql()->select();
        $fpClassWhere = new Where();
        $fpClassWhere->isNull("deleted_at");
        $fpClassSelect->order([
            "sort ASC",
            "id asc"
        ]);
        $fpClassSelect->where($fpClassWhere);
        $fpClassResultset = $fpClassTableGateway->selectWith($fpClassSelect);
        if ($fpClassResultset->count() > 0) {
            $fpClassHasMpClassTableGateway = new FpClassHasMpClassTableGateway($this->adapter);
            $filterTable = $fpClassTableGateway->getTailTableName();
            foreach ($fpClassResultset as $fpRow) {
                $fpRow->with('tableName', $filterTable);
                $fpId = $fpRow->id;
                $fpClassHasMpClassResultset = $fpClassHasMpClassTableGateway->select([
                    "fp_class_id" => $fpId
                ]);
                $mpClassIdIns = [];
                foreach ($fpClassHasMpClassResultset as $item) {
                    $mpClassIdIns[] = $item->mp_class_id;
                }
                $where = new Where();
                $where->isNull("deleted_at");
                $where->in("id", $mpClassIdIns);
                $mpClassSelect = $mpClassTableGateway->getSql()->select();
                $mpClassSelect->order([
                    "sort ASC",
                    "id asc"
                ]);
                $mpClassSelect->where($where);
                $mpClassResultset = $mpClassTableGateway->selectWith($mpClassSelect);
                if ($mpClassResultset->count() > 0) {
                    $mpClassResult = [];
                    foreach ($mpClassResultset as $mpRow) {
                        $mpClassId = $mpRow->id;
                        $mpClassHasNpClassResultset = $mpClassHasNpClassTableGateway->select([
                            "mp_class_id" => $mpClassId
                        ]);
                        if ($mpClassHasNpClassResultset->count() > 0) {
                            $npClassIn = [];
                            foreach ($mpClassHasNpClassResultset as $item2) {
                                $npClassIn[] = $item2->np_class_id;
                            }
                            $where = new Where();
                            $where->isNull("deleted_at");
                            $where->in("id", $npClassIn);
                            $npClassSelect = $npClassTableGateway->getSql()->select();
                            $npClassSelect->order([
                                "sort ASC",
                                "id asc"
                            ]);
                            $npClassSelect->where($where);
                            $npClassResultset = $npClassTableGateway->selectWith($npClassSelect);
                            if ($npClassResultset->count() > 0) {
                                $mpRow->with('child', $npClassResultset->toArray());
                            }
                        }
                        $mpClassResult[] = $mpRow->toArray();
                        unset($mpClassHasNpClassResultset);
                    }
                    $fpRow->with("child", $mpClassResult);
                    unset($mpClassResultset);
                }
                $result[] = $fpRow->toArray();
            }
            unset($fpClassResultset);
        }
        if (! $result) {
            $mpClassSelect = $mpClassTableGateway->getSql()->select();
            $mpClassSelect->order([
                "sort ASC",
                "id asc"
            ]);
            $mpClassResultset = $mpClassTableGateway->selectWith($mpClassSelect);
            $where = new Where();
            if ($mpClassResultset->count() > 0) {
                $result = [];
                $filterTable = $mpClassTableGateway->getTailTableName();
                foreach ($mpClassResultset as $mpRow) {
                    $mpRow->with('tableName', $filterTable);
                    $mpClassId = $mpRow->id;
                    $mpClassHasNpClassResultset = $mpClassHasNpClassTableGateway->select([
                        "mp_class_id" => $mpClassId
                    ]);
                    if ($mpClassHasNpClassResultset->count() > 0) {
                        $npClassIn = [];
                        foreach ($mpClassHasNpClassResultset as $item2) {
                            $npClassIn[] = $item2->np_class_id;
                        }
                        $where = new Where();
                        $where->isNull("deleted_at");
                        $where->in("id", $npClassIn);
                        $npClassSelect = $npClassTableGateway->getSql()->select();
                        $npClassSelect->order([
                            "sort ASC",
                            "id asc"
                        ]);
                        $npClassSelect->where($where);
                        $npClassResultset = $npClassTableGateway->selectWith($npClassSelect);
                        if ($npClassResultset->count() > 0) {
                            $mpRow->with('child', $npClassResultset->toArray());
                        }
                    }
                    $result[] = $mpRow->toArray();
                    unset($mpClassHasNpClassResultset);
                }
                unset($mpClassResultset);
            }
        }
        if (! $result) {
            $npClassWhere = new Where();
            $npClassWhere->isNull("deleted_at");
            $npClassSelect = $npClassTableGateway->getSql()->select();
            $npClassSelect->order([
                "sort ASC",
                "id ASC"
            ]);
            $npClassSelect->where($npClassWhere);
            $resultset = $npClassTableGateway->selectWith($npClassSelect)/*->toArray()*/;
            if ($resultset->count() > 0) {
                foreach ($resultset as $row) {
                    $row->with("tableName", $filterTable);
                    $result[] = $row->toArray();
                }
            }
            unset($resultset);
        }
        // begin of 針對只有np_class沒有設定父層分類但是有設定產品分類情形
        $npClassWhere = new Where();

        $npClassSelect = $npClassTableGateway->getSql()->select();
        $npClassSelect->order([
            "{$npClassTableGateway->table}.sort ASC",
            "{$npClassTableGateway->table}.id ASC"
        ]);
        $npClassSelect->join($mpClassHasNpClassTableGateway->table, "{$npClassTableGateway->table}.id={$mpClassHasNpClassTableGateway->table}.np_class_id", [
            "mp_class_id"
        ], Join::JOIN_LEFT);
        $npClassWhere->isNull("mp_class_id");
        $npClassSelect->where($npClassWhere);
        $npClassResultset = $npClassTableGateway->selectWith($npClassSelect);
        // end of 針對只有np_class沒有設定父層分類但是有設定產品分類情形
        if (! $result) {
            $result = [];
        }
        if ($npClassResultset->count()) {
            $npClassResult = array_reverse($npClassResultset->toArray());
            foreach ($npClassResult as $npClassItem) {
                $npClassItem["tableName"] = $npClassTableGateway->getTailTableName();
                array_unshift($result, $npClassItem);
            }
            unset($npClassResult);
        }
        unset($npClassResultset);
        $vars["items"] = $result;
        if (empty($vars["current"])) {
            $vars["current"] = null;
        }
        /**
         *
         * @var RouteResult $routeResult
         */
        $routeResult = $request->getAttribute(RouteResult::class);
        $matchName = $routeResult->getMatchedRoute()->getName();
        $breadAppendCheck = false;
        $action = $request->getAttribute("action");
        if ($matchName == "category" || $action == "category") {
            $breadAppendCheck = true;
        }
        if (/*$matchName == 'category' || $matchName == "api.site"*/$breadAppendCheck) {
            $vars["breadAppend"] = [];
            $layoutZonesTableGateway = new LayoutZonesTableGateway($this->adapter);
            $layoutZonesSlect = $layoutZonesTableGateway->getSql()->select();
            $layoutZonesWhere = $layoutZonesSlect->where;
            $layoutZonesWhere->isNull("deleted_at");
            $layoutZonesWhere->like("uri", '%/category/all');
            $layoutZonesWhere->equalTo("language_id", $request->getAttribute("language_id"));
            $layoutZonesWhere->equalTo("locale_id", $request->getAttribute("locale_id"));
            $layoutZonesSlect->order([
                "sort asc",
                "id asc"
            ]);
            $layoutZonesSlect->where($layoutZonesWhere);
            $layoutZonesResultset = $layoutZonesTableGateway->selectWith($layoutZonesSlect);
            $vars["breadAppend"] = [];
            if ($layoutZonesResultset->count()) {
                foreach ($layoutZonesResultset as $layoutZonesRow) {
                    $vars["breadAppend"][] = [
                        "id" => 0,
                        "name" => $layoutZonesRow->name,
                    ];
                }
            } else {
                $vars["breadAppend"][] = [
                    "id" => 0,
                    "name" => i18nStaticTranslator('All products', 'site-translation'),
                ];
            }
            unset($layoutZonesResultset);
        }
        // debug($vars);
        return $vars;
    }

    public function getUseManufactures(ServerRequestInterface $request)
    {
        $languageId = $request->getAttribute("language_id");
        $localeId = $request->getAttribute("locale_id");
        $manufacturesTableGateway = new ManufacturesTableGateway($this->adapter);
        $select = $manufacturesTableGateway->getSql()->select();
        $select->quantifier("distinct");
        $select->columns([
            "id",
            "name",
            "image"
        ]);
        $select->join($this->table, "{$manufacturesTableGateway->table}.id={$this->table}.manufactures_id", []);
        $where = new Where();
        $where->equalTo("{$this->table}.language_id", $languageId);
        $where->equalTo("{$this->table}.locale_id", $localeId);
        $where->isNull("{$manufacturesTableGateway->table}.deleted_at");
        $where->isNull("{$this->table}.deleted_at");
        $select->where($where);
        return $manufacturesTableGateway->selectWith($select)->toArray();
    }
}
