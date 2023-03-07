<?php

namespace Chopin\Documents\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\RowGateway\RowGatewayInterface;
use Psr\Http\Message\ServerRequestInterface;
use Mezzio\Router\RouteResult;
use Laminas\Db\Sql\Where;
use Chopin\LaminasDb\RowGateway\RowGateway;
use Chopin\Store\TableGateway\AttributesTableGateway;
use Chopin\Store\TableGateway\NpClassTableGateway;
use Chopin\Store\TableGateway\MpClassTableGateway;
use Chopin\Store\TableGateway\FpClassHasMpClassTableGateway;
use Chopin\Store\TableGateway\MpClassHasNpClassTableGateway;
use Chopin\Store\TableGateway\FpClassTableGateway;
use Chopin\Newsletter\TableGateway\FnClassTableGateway;
use Chopin\Newsletter\TableGateway\MnClassTableGateway;
use Chopin\Newsletter\TableGateway\NnClassTableGateway;
use Chopin\Newsletter\TableGateway\MnClassHasNnClassTableGateway;
use Chopin\Newsletter\TableGateway\FnClassHasMnClassTableGateway;
use Chopin\Store\TableGateway\ProductsTableGateway;
use Chopin\Store\TableGateway\NpClassHasProductsTableGateway;
use Chopin\Newsletter\TableGateway\NewsTableGateway;
use Chopin\Newsletter\TableGateway\NnClassHasNewsTableGateway;
use Chopin\LanguageHasLocale\TableGateway\LanguageHasLocaleTableGateway;

class DocumentsTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'documents';

    /**
     *
     * @param int $documents_id
     * @return RowGatewayInterface|null
     */
    public function getSeoFromId(int $documents_id)
    {
        $seoTableGateway = new SeoTableGateway($this->adapter);
        $resultSet = $seoTableGateway->select([
            "table" => "documents",
            "table_id" => $documents_id
        ]);
        return $resultSet->current();
    }

    public function getTemplatteThemesResult()
    {
        $where = new Where();
        $where->in('index', ['about-us', 'index']);
        $where->isNull("deleted_at");
        $select = $this->sql->select();
        $select->where($where)->columns(["id", "language_id", "locale_id", "index"]);
        $resultSet = $this->selectWith($select);
        $result = [];
        /**
         * @var \Chopin\LaminasDb\RowGateway\RowGateway $row
         *
         */
        $languageHasLocaleTableGateway = new LanguageHasLocaleTableGateway($this->adapter);
        foreach ($resultSet as $row) {
            $item = $languageHasLocaleTableGateway->select([
                "language_id" => $row->language_id,
                "locale_id" => $row->locale_id,
            ])->current();
            if ($item) {
                $row->with('language_has_locale', $item->display_name);
            }
            if (empty($result[$row->index])) {
                $result[$row->index] = [];
            }
            $result[$row->index][] = $row->toArray();
        }
        unset($resultSet);
        return $result;
    }

    public function getLayoutUse(RowGatewayInterface $layoutRow, $categoryNotIn = [], $normalNotIn = [])
    {
        $categorySelect = $this->sql->select();
        $categoryWhere = $categorySelect->where;
        $categoryWhere->like('route', '%category%');
        $categoryWhere->equalTo('language_id', $layoutRow->language_id);
        $categoryWhere->equalTo('locale_id', $layoutRow->locale_id);
        if ($categoryNotIn) {
            $categoryWhere->notIn('route', $categoryNotIn);
        }
        $categorySelect->where($categoryWhere);
        $categoryResult = $this->selectWith($categorySelect)->toArray();

        $select = $this->sql->select();
        $where = $select->where;
        $where->isNull('deleted_at');
        $where->equalTo('language_id', $layoutRow->language_id);
        $where->equalTo('locale_id', $layoutRow->locale_id);
        $where->notLike('route', '%category%');
        $where->notLike('route', '%/product%');
        $where->notLike('route', '%/news%');
        if ($normalNotIn) {
            $where->notIn('route', $normalNotIn);
        }
        $select->where($where);
        $normalResult = $this->selectWith($select)->toArray();
        return [
            'normal' => $normalResult,
            'category' => $categoryResult
        ];
    }

    public function buildBreadCrumb(ServerRequestInterface $request)
    {
        /**
         *
         * @var RouteResult $routeResult
         */
        $routeResult = $request->getAttribute(RouteResult::class);
        $matchParams = $routeResult->getMatchedParams();
        $routeIndex = $routeResult->getMatchedRouteName();
        if (false !== strpos($routeIndex, 'api')) {
            $routeIndex = $matchParams["action"];
        }
        //

        $vars = [];
        $serverParams = $request->getServerParams();
        $requestUri = $serverParams["REQUEST_URI"];
        $where = new Where();
        $where->equalTo("index", $routeIndex)->isNull("deleted_at");
        if ($routeIndex == "other") {
            $methodOrId = $request->getAttribute("methodOrId");

            $where->equalTo("id", $methodOrId);
        }
        /**
         *
         * @var RowGateway $row
         */
        $row = $this->select($where)->current();
        if ($row instanceof RowGatewayInterface) {
            $layoutZonesTableGateway = new LayoutZonesTableGateway($this->adapter);
            $where = new Where();
            $where->equalTo("uri", $requestUri)->isNull("deleted_at");
            $layoutZonesRow = $layoutZonesTableGateway->select($where)->current();
            $methodOrId = $request->getAttribute("methodOrId");
            if (! $layoutZonesRow) {
                $where = new Where();
                $requestUri2 = preg_replace('/\/api\/site/', '', $requestUri);
                if ($methodOrId) {
                    $requestUri2 = preg_replace('/\/(m(n|p)_class\-\d+)|\/\d+$/', '/all', $requestUri2);
                }
                $requestUri2 = preg_replace('/\?.*$/', '', $requestUri2);
                $where->equalTo("uri", $requestUri2)->isNull("deleted_at");
                $layoutZonesRow = $layoutZonesTableGateway->select($where)->current();
                $requestUri = $requestUri2;
            }

            $languageId = $row->language_id;
            $localeId = $row->locale_id;
            $homeRow = $this->select([
                "index" => "index",
                "language_id" => $languageId,
                "locale_id" => $localeId
            ])->current();

            if ($layoutZonesRow) {
                $tmpItem = $layoutZonesRow->toArray();
                $tmpItem["route"] = $requestUri;
                unset($tmpItem["id"]);
                $items = [ $tmpItem, ];
            } else {
                if (false === preg_match('/product$/', $row->index) && false === preg_match('/news$/', $row->index)) {
                    $items = [ $row->toArray(), ];
                }
            }
            if (preg_match('/news$/', $row->index)) {
                $newsTableGateway = new NewsTableGateway($this->adapter);
                $where = new Where();
                $where->isNull("deleted_at");
                $where->equalTo("id", $methodOrId);
                // $where->equalTo("is_show", 1);
                $newsRow = $newsTableGateway->select($where)->current();
                $categoryUri = preg_replace('/\/news\/all/', '/news-category', $requestUri);
                if ($newsRow) {
                    $nnClassHasNewsTableGateway = new NnClassHasNewsTableGateway($this->adapter);
                    $nnClassHasNewsResultset = $nnClassHasNewsTableGateway->select([
                        "news_id" => $newsRow->id
                    ]);
                    $nnClassIdIn = [];
                    foreach ($nnClassHasNewsResultset as $item) {
                        $nnClassIdIn[] = $item->nn_class_id;
                    }
                    unset($nnClassHasNewsResultset);
                    $nnClassIdIn = array_unique($nnClassIdIn);
                    $nnClassIdIn = array_values($nnClassIdIn);
                    $mnClassHasNnClassTableGateway = new MnClassHasNnClassTableGateway($this->adapter);
                    $mnClassHasNnClassResultset = $mnClassHasNnClassTableGateway->select([
                        "nn_class_id" => $nnClassIdIn
                    ]);
                    if ($mnClassHasNnClassResultset->count()) {
                        $mnClassTableGateway = new MnClassTableGateway($this->adapter);
                        $mnClassIdIn = [];
                        foreach ($mnClassHasNnClassResultset as $item) {
                            $mnClassIdIn[] = $item->mp_class_id;
                        }
                        unset($mnClassHasNnClassResultset);
                        $mnClassIdIn = array_unique($mnClassIdIn);
                        $mnClassIdIn = array_values($mnClassIdIn);
                        if ($mnClassIdIn) {
                            $fnClassHasMnClassTableGateway = new FnClassHasMnClassTableGateway($this->adapter);
                            $fnClassTableGateway = new FnClassTableGateway($this->adapter);
                            $fnClassHasMnClassResultset = $fnClassHasMnClassTableGateway->select([
                                "mn_class_id" => $mnClassIdIn
                            ]);
                            if ($fnClassHasMnClassResultset->count()) {
                                $fnClassIn = [];
                                foreach ($fnClassHasMnClassResultset as $item) {
                                    $fnClassIn[] = $item->fn_class_id;
                                }
                                unset($fnClassHasMnClassResultset);
                                $fnClassIn = array_unique($fnClassIn);
                                $fnClassIn = array_values($fnClassIn);
                                if ($fnClassIn) {
                                    $where = new Where();
                                    $where->in("id", $fnClassIn);
                                    $where->isNull("deleted_at");
                                    $fnClassResultset = $fnClassTableGateway->select($where);
                                    foreach ($fnClassResultset as $fnRow) {
                                        $fnRow->route = "{$categoryUri}/fn_class-{$fnRow->id}";
                                        $items[] = $fnRow->toArray();
                                    }
                                    unset($fnClassResultset);
                                }
                            }
                            $where = new Where();
                            $where->in("id", $mnClassIdIn);
                            $where->isNull("deleted_at");
                            $mnClassResultset = $mnClassTableGateway->select($where);
                            foreach ($mnClassResultset as $mnClassRow) {
                                $mnClassRow->route = "{$categoryUri}/mn_class-{$mnClassRow->id}";
                                $items[] = $mnClassRow->toArray();
                            }
                            unset($mnClassResultset);
                        }
                    }
                    $nnClassTableGateway = new NnClassTableGateway($this->adapter);
                    $where = new Where();
                    $where->in("id", $nnClassIdIn);
                    $where->isNull("deleted_at");
                    $nnClassResultset = $nnClassTableGateway->select($where);

                    foreach ($nnClassResultset as $nnRow) {
                        $nnRow->with("route", $categoryUri . "/{$nnRow->id}");
                        $items[] = $nnRow->toArray();
                    }
                    unset($nnClassResultset);
                    $newsRow->name = $newsRow->title;
                    $items[] = $newsRow;
                }
            }
            if (preg_match('/product$/', $row->index)) {
                $productsTableGateway = new ProductsTableGateway($this->adapter);
                $where = new Where();
                $where->isNull("deleted_at");
                $where->equalTo("id", $methodOrId);
                $where->equalTo("is_show", 1);
                $productRow = $productsTableGateway->select($where)->current();
                $categoryUri = preg_replace('/\/product\/(all|\d+)$/', '/category', $requestUri);
                if ($productRow) {
                    $npClassHasProductsTableGateway = new NpClassHasProductsTableGateway($this->adapter);
                    $npClassHasProductsResultset = $npClassHasProductsTableGateway->select([
                        "products_id" => $productRow->id
                    ]);
                    $npClassIdIn = [];
                    foreach ($npClassHasProductsResultset as $item) {
                        $npClassIdIn[] = $item->np_class_id;
                    }
                    unset($npClassHasProductsResultset);
                    $npClassIdIn = array_unique($npClassIdIn);
                    $npClassIdIn = array_values($npClassIdIn);
                    $mpClassHasNpClassTableGateway = new MpClassHasNpClassTableGateway($this->adapter);
                    $mpClassHasNpClassResultset = $mpClassHasNpClassTableGateway->select([
                        "np_class_id" => $npClassIdIn
                    ]);
                    if ($mpClassHasNpClassResultset->count()) {
                        $mpClassTableGateway = new MpClassTableGateway($this->adapter);
                        $mpClassIdIn = [];
                        foreach ($mpClassHasNpClassResultset as $item) {
                            $mpClassIdIn[] = $item->mp_class_id;
                        }
                        unset($mpClassHasNpClassResultset);
                        $mpClassIdIn = array_unique($mpClassIdIn);
                        $mpClassIdIn = array_values($mpClassIdIn);
                        if ($mpClassIdIn) {
                            $fpClassHasMpClassTableGateway = new FpClassHasMpClassTableGateway($this->adapter);
                            $fpClassTableGateway = new FpClassTableGateway($this->adapter);
                            $fpClassHasMpClassResultset = $fpClassHasMpClassTableGateway->select([
                                "mp_class_id" => $mpClassIdIn
                            ]);
                            if ($fpClassHasMpClassResultset->count()) {
                                $fpClassIdIn = [];
                                foreach ($fpClassHasMpClassResultset as $item) {
                                    $fpClassIdIn[] = $item->fp_class_id;
                                }
                                unset($fpClassHasMpClassResultset);
                                $fpClassIdIn = array_unique($fpClassIdIn);
                                $fpClassIdIn = array_values($fpClassIdIn);
                                if ($fpClassIdIn) {
                                    $where = new Where();
                                    $where->in("id", $fpClassIdIn);
                                    $where->isNull("deleted_at");
                                    $fpClassResultset = $fpClassTableGateway->select($where);
                                    foreach ($fpClassResultset as $fpRow) {
                                        $fpRow->route = "{$categoryUri}/fp_class-{$fpRow->id}";
                                        $items[] = $fpRow->toArray();
                                    }
                                    unset($fpClassResultset);
                                }
                            }
                            $where = new Where();
                            $where->in("id", $mpClassIdIn);
                            $where->isNull("deleted_at");
                            $mpClassResultset = $mpClassTableGateway->select($where);
                            foreach ($mpClassResultset as $mpClassRow) {
                                $mpClassRow->route = "{$categoryUri}/mp_class-{$mpClassRow->id}";
                                $items[] = $mpClassRow->toArray();
                            }
                            unset($mpClassResultset);
                        }
                    }
                    $npClassTableGateway = new NpClassTableGateway($this->adapter);
                    $where = new Where();
                    $where->in("id", $npClassIdIn);
                    $where->isNull("deleted_at");
                    $npClassResultset = $npClassTableGateway->select($where);
                    foreach ($npClassResultset as $npRow) {
                        $npRow->with("route", $categoryUri . "/{$npRow->id}");
                        $items[] = $npRow->toArray();
                    }
                    unset($npClassResultset);
                    $productRow->name = $productRow->alias;
                    $items[] = $productRow;
                }
            }
            if (preg_match('/category$/', $row->index)) {
                if ($methodOrId != 'all') {
                    if (preg_match('/news\-category$/', $row->index)) {
                        // news-category
                        $fnClassTableGateway = new FnClassTableGateway($this->adapter);
                        $mnClassTableGateway = new MnClassTableGateway($this->adapter);
                        $nnClassTableGateway = new NnClassTableGateway($this->adapter);
                        $mnClassHasNnClassTableGateway = new MnClassHasNnClassTableGateway($this->adapter);
                        $fnClassHasMnClassTableGateway = new FnClassHasMnClassTableGateway($this->adapter);
                        if (preg_match('/\d+/', $methodOrId)) {
                            $where = new Where();
                            $where->isNull("deleted_at");
                            $where->equalTo("id", $methodOrId);
                            $nnClassRow = $nnClassTableGateway->select($where)->current();
                            if ($nnClassRow) {
                                $nnClassRow->tableName = $nnClassTableGateway->getTailTableName();
                                $mnClassHasNnClassResultset = $mnClassHasNnClassTableGateway->select([
                                    "nn_class_id" => $nnClassRow->id
                                ]);
                                $mnClassIn = [];
                                if ($mnClassHasNnClassResultset->count() > 0) {
                                    foreach ($mnClassHasNnClassResultset as $mnClassHasNnClassItem) {
                                        $mnClassIn[] = $mnClassHasNnClassItem->mn_class_id;
                                    }
                                    unset($mnClassHasNnClassResultset);
                                    $where = new Where();
                                    // $where->isNull("deleted_at");
                                    $where->in("mn_class_id", $mnClassIn);
                                    $fnClassHasMnClassResultset = $fnClassHasMnClassTableGateway->select($where);
                                    if ($fnClassHasMnClassResultset->count() > 0) {
                                        $fnClassIn = [];
                                        foreach ($fnClassHasMnClassResultset as $fnClassHasMnClassItem) {
                                            $fnClassIn[] = $fnClassHasMnClassItem->fn_class_id;
                                        }
                                        unset($fnClassHasMnClassResultset);
                                        $where = new Where();
                                        $where->isNull("deleted_at");
                                        $where->in("id", $fnClassIn);
                                        $fnClassResultset = $fnClassTableGateway->select($where);
                                        foreach ($fnClassResultset as $fnRow) {
                                            $fnRow->tableName = $fnClassTableGateway->getTailTableName();
                                            $tmn = $fnRow->toArray();
                                            $tmn["route"] = str_replace('all', 'fn_class-' . $tmn["id"], $requestUri);
                                            $items[] = $tmn;
                                        }
                                        unset($fnClassResultset);
                                    }
                                    $where = new Where();
                                    $where->isNull("deleted_at");
                                    $where->in("id", $mnClassIn);
                                    $mnClassResultset = $mnClassTableGateway->select($where);

                                    foreach ($mnClassResultset as $mnClassRow) {
                                        $mnClassRow->tableName = $mnClassTableGateway->getTailTableName();
                                        $tmn = $mnClassRow->toArray();
                                        $tmn["route"] = str_replace('all', 'mn_class-' . $tmn["id"], $requestUri);
                                        $items[] = $tmn;
                                    }
                                    unset($mnClassResultset);
                                }
                                $tmn = $nnClassRow->toArray();
                                $tmn["route"] = str_replace('all', $tmn["id"], $requestUri);
                                $items[] = $tmn;
                            }
                        }
                        if (preg_match('/^mn_class\-\d+$/', $methodOrId)) {
                            $methodOrId = explode('-', $methodOrId);
                            $methodOrId = $methodOrId[1];

                            $where = new Where();
                            $where->equalTo('id', $methodOrId);
                            $where->isNull("deleted_at");
                            $mnClassRow = $mnClassTableGateway->select($where)->current();
                            if ($mnClassRow) {
                                $where = new Where();
                                // $where->isNull("deleted_at");
                                $where->equalTo("mn_class_id", $mnClassRow->id);
                                $fnClassHasMnClassResultset = $fnClassHasMnClassTableGateway->select($where);
                                $fnClassResultset = $fnClassTableGateway->select($where);
                                if ($fnClassHasMnClassResultset->count() > 0) {
                                    $fnClassIn = [];
                                    foreach ($fnClassHasMnClassResultset as $fnClassHasMnClassItem) {
                                        $fnClassIn[] = $fnClassHasMnClassItem->fn_class_id;
                                    }
                                    $where = new Where();
                                    $where->isNull("deleted_at");
                                    $where->in("id", $fnClassIn);
                                    $fnClassResultset = $fnClassTableGateway->select($where);
                                    foreach ($fnClassResultset as $fnRow) {
                                        $fnRow->tableName = $fnClassTableGateway->getTailTableName();
                                        $tmn = $fnRow->toArray();
                                        $tmn["route"] = str_replace('all', 'fn_class-' . $tmn["id"], $requestUri);
                                        $items[] = $tmn;
                                    }
                                    unset($fnClassResultset);
                                }
                                unset($fnClassHasMnClassResultset);
                                $mnClassRow->tableName = $mnClassTableGateway->getTailTableName();
                                $tmn = $mnClassRow->toArray();
                                $tmn["route"] = str_replace('all', 'mn_class-' . $tmn["id"], $requestUri);
                                $items[] = $tmn;
                            }
                        }
                    } else {
                        if (preg_match('/category$/', $row->index)) {
                            // product category
                            $fpClassTableGateway = new FpClassTableGateway($this->adapter);
                            $mpClassTableGateway = new MpClassTableGateway($this->adapter);
                            $npClassTableGateway = new NpClassTableGateway($this->adapter);
                            $mpClassHasNpClassTableGateway = new MpClassHasNpClassTableGateway($this->adapter);
                            $fpClassHasMpClassTableGateway = new FpClassHasMpClassTableGateway($this->adapter);
                            if (preg_match('/\d+/', $methodOrId)) {
                                $where = new Where();
                                $where->isNull("deleted_at");
                                $where->equalTo("id", $methodOrId);
                                $npClassRow = $npClassTableGateway->select($where)->current();
                                if ($npClassRow) {
                                    $npClassRow->tableName = $npClassTableGateway->getTailTableName();
                                    $mpClassHasNpClassResultset = $mpClassHasNpClassTableGateway->select([
                                        "np_class_id" => $npClassRow->id
                                    ]);
                                    $mpClassIn = [];
                                    if ($mpClassHasNpClassResultset->count() > 0) {
                                        foreach ($mpClassHasNpClassResultset as $mpClassHasNpClassItem) {
                                            $mpClassIn[] = $mpClassHasNpClassItem->mp_class_id;
                                        }
                                        $where = new Where();
                                        $where->in("mp_class_id", $mpClassIn);
                                        $fpClassHasMpClassResultset = $fpClassHasMpClassTableGateway->select($where);
                                        if ($fpClassHasMpClassResultset->count() > 0) {
                                            $fpClassIn = [];
                                            foreach ($fpClassHasMpClassResultset as $fpClassHasMpClassItem) {
                                                $fpClassIn[] = $fpClassHasMpClassItem->fp_class_id;
                                            }
                                            $where = new Where();
                                            $where->isNull("deleted_at");
                                            $where->in("id", $fpClassIn);
                                            $fpClassResultset = $fpClassTableGateway->select($where);
                                            foreach ($fpClassResultset as $fpRow) {
                                                $fpRow->tableName = $fpClassTableGateway->getTailTableName();
                                                $tmp = $fpRow->toArray();
                                                $tmp["route"] = str_replace('all', 'fp_class-' . $tmp["id"], $requestUri);
                                                $items[] = $tmp;
                                            }
                                            unset($fpClassResultset);
                                        }
                                        unset($fpClassHasMpClassResultset);
                                        $where = new Where();
                                        $where->isNull("deleted_at");
                                        $where->in("id", $mpClassIn);
                                        $mpClassResultset = $mpClassTableGateway->select($where);
                                        foreach ($mpClassResultset as $mpClassRow) {
                                            $mpClassRow->tableName = $mpClassTableGateway->getTailTableName();
                                            $tmp = $mpClassRow->toArray();
                                            $tmp["route"] = str_replace('all', 'mp_class-' . $tmp["id"], $requestUri);
                                            $items[] = $tmp;
                                        }
                                    }
                                    unset($mpClassHasNpClassResultset);
                                    $tmp = $npClassRow->toArray();
                                    $tmp["route"] = str_replace('all', $tmp["id"], $requestUri);
                                    $items[] = $tmp;
                                }
                            }
                            if (preg_match('/^mp_class\-\d+$/', $methodOrId)) {
                                $methodOrId = explode('-', $methodOrId);
                                $methodOrId = $methodOrId[1];
                                $where = new Where();
                                $where->equalTo('id', $methodOrId);
                                $where->isNull("deleted_at");
                                $mpClassRow = $mpClassTableGateway->select($where)->current();
                                if ($mpClassRow) {
                                    $where = new Where();
                                    $where->equalTo("mp_class_id", $mpClassRow->id);
                                    $fpClassHasMpClassResultset = $fpClassHasMpClassTableGateway->select($where);
                                    // $fpClassResultset = $fpClassTableGateway->select($where);
                                    if ($fpClassHasMpClassResultset->count() > 0) {
                                        $fpClassIn = [];
                                        foreach ($fpClassHasMpClassResultset as $fpClassHasMpClassItem) {
                                            $fpClassIn[] = $fpClassHasMpClassItem->fp_class_id;
                                        }
                                        $where = new Where();
                                        $where->isNull("deleted_at");
                                        $where->in("id", $fpClassIn);
                                        $fpClassResultset = $fpClassTableGateway->select($where);
                                        foreach ($fpClassResultset as $fpRow) {
                                            $fpRow->tableName = $fpClassTableGateway->getTailTableName();
                                            $tmp = $fpRow->toArray();
                                            $tmp["route"] = str_replace('all', 'fp_class-' . $tmp["id"], $requestUri);
                                            $items[] = $tmp;
                                        }
                                        unset($fpClassResultset);
                                    }
                                    unset($fpClassHasMpClassResultset);
                                    $mpClassRow->tableName = $mpClassTableGateway->getTailTableName();
                                    $tmp = $mpClassRow->toArray();
                                    $tmp["route"] = str_replace('all', 'mp_class-' . $tmp["id"], $requestUri);
                                    $items[] = $tmp;
                                }
                            }
                        }
                    }
                }
            }
            $current = ($row instanceof RowGatewayInterface) ? $row->toArray() : $row;
            $items = isset($items) ? $items : [$row->toArray()];
            $discountGroupRow = $request->getAttribute('discountGroupRow');
            //debug($discountGroupRow);
            if($discountGroupRow instanceof RowGatewayInterface) {
                $items[] = ['name' => $discountGroupRow->name];
            }
            // debug(isset($items));
            $vars["bread"] = [
                "top" => isset($items) ? $items[0] : [],
                "items" => $items,
                "home" => $homeRow->toArray(),
                "current" => $current,
            ];
            //debug($vars["bread"]);
        }
        return $vars;
    }

    public function getFaqs(ServerRequestInterface $request, $documents_id)
    {
        $id = $request->getAttribute("methodOrId");
        $attributesTableGateway = new AttributesTableGateway($this->adapter);
        $language_id = $request->getAttribute('language_id');
        $locale_id = $request->getAttribute('locale_id');
        $select = $attributesTableGateway->getSql()->select();
        $where = $select->where;
        $where->equalTo('parent_id', 0);
        $where->equalTo('table', 'documents');
        $where->equalTo('language_id', $language_id);
        $where->equalTo('locale_id', $locale_id);
        $where->isNull("deleted_at");
        $select->order([
            "sort ASC",
            "id ASC"
        ]);
        $sideBarResult = $attributesTableGateway->selectWith($select);
        if ($id == 'all') {
            $select->where($where);
            $row = $attributesTableGateway->selectWith($select)->current();
        } else {
            $where->equalTo("id", $id);
            $select->where($where);
            $row = $attributesTableGateway->selectWith($select)->current();
        }
        if (isset($row)) {
            $faqsSelect = $attributesTableGateway->getSql()->select();
            $faqsWhere = $faqsSelect->where;
            $faqsWhere->isNull("deleted_at");
            $faqsWhere->equalTo("parent_id", $row->id);
            $faqsSelect->order([
                "sort asc",
                "id asc"
            ]);
            $faqsSelect->where($faqsSelect);
            $faqsResultSet = $attributesTableGateway->selectWith($faqsSelect);
        }
        return [
            "sideBar" => $sideBarResult->toArray(),
            "faqParantRow" => isset($row) ? $row->toArray() : null,
            "faqs" => isset($faqsResultSet) ? $faqsResultSet->toArray() : []
        ];
    }

    /**
     *
     * @param RowGateway $row
     * @return \Chopin\LaminasDb\RowGateway\RowGateway
     */
    public function withContents(RowGatewayInterface $row)
    {
        $theme = config('lezada.pageStyle.' . $row->index);
        if (is_file('./storage/persists/themesTemplates.json')) {
            $themes = file_get_contents('./storage/persists/themesTemplates.json');
            $themes = json_decode($themes, true);
            if ($themes) {
                $tmp = isset($themes[$row->index]) ? $themes[$row->index] : null;
                if ($tmp) {
                    $theme = $tmp;
                }
            }
        }
        $theme = preg_replace("/\.html\.twig$/", '', $theme);
        $theme = explode('/', $theme);
        $theme = end($theme);
        $documentsContentTableGateway = new DocumentsContentTableGateway($this->adapter);
        $contents = $documentsContentTableGateway->getItems($row->id, $theme);
        $row->with('contents', $contents);
        return $row;
    }

    public function withBanners(RowGatewayInterface $row)
    {
        $resultSet = [];
        $bannerHasDocumentsTableGateway = new BannerHasDocumentsTableGateway($this->adapter);
        $bannerTableGateway = new BannerTableGateway($this->adapter);
        $select = $bannerHasDocumentsTableGateway->getSql()->select();
        $select->join($bannerTableGateway->table, $bannerHasDocumentsTableGateway->table . ".banner_id=" . $bannerTableGateway->table . ".id", [
            "image"
        ]);
        $where = $select->where;
        $where->equalTo("documents_id", $row->id);
        $where->equalTo('is_show', 1);
        $where->isNull($bannerTableGateway->table . ".deleted_at");
        $select->where($where);
        $select->order($bannerHasDocumentsTableGateway->table . ".sort asc");
        $resultSet = $bannerHasDocumentsTableGateway->selectWith($select);
        $row->with('banners', $resultSet->toArray());
        return $row;
    }

    public function getFirstFaqParent(ServerRequestInterface $request)
    {
        $id = $request->getAttribute("methodOrId");
        $attributesTableGateway = new AttributesTableGateway($this->adapter);
        $where = new Where();
        $where->equalTo('id', $id);
        $where->equalTo('parent_id', 0);
        $where->isNull('deleted_at');
        $select = $attributesTableGateway->getSql()->select();
        $select->where($where);
        $select->order(['sort ASC', 'id ASC']);
        $row = $attributesTableGateway->selectWith($select)->current();
        if (!$row) {
            return [];
        }
        return $row->toArray();
    }
}
