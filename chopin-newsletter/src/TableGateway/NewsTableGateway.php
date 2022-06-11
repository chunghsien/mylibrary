<?php

namespace Chopin\Newsletter\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Predicate\PredicateSet;
use Laminas\Diactoros\ServerRequest;
use Laminas\Paginator\Paginator;
use Laminas\Paginator\Adapter\LaminasDb\DbSelect;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Predicate\PredicateInterface;
use Laminas\Db\Sql\Where;
use Mezzio\Router\RouteResult;
use Chopin\LaminasDb\RowGateway\RowGateway;
use Chopin\Store\TableGateway\TagsTableGateway;

class NewsTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    public function getList(ServerRequestInterface $request, $extraWhere, $orderBy, $limit = 8, $page = 1)
    {
        $select = $this->getSelectPart($request);
        /**
         *
         * @var Where $where
         */
        $where = $select->getRawState("where");
        if ($extraWhere instanceof PredicateInterface) {
            $where->addPredicate($extraWhere);
        }
        $select->where($where);
        $select->order($orderBy);
        $select->limit($limit);
        if ($page > 1) {
            $select->offset($limit * $page);
        }
        $result = $this->selectWith($select)->toArray();
        return $result;
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    public function getSideBar(ServerRequestInterface $request)
    {
        $vars = [
            "items" => []
        ];
        $methodOrId = $request->getAttribute("methodOrId");
        $filterTable = "nn_class";
        $filterId = 0;
        if ($methodOrId) {
            $methodOrId = explode("-", $methodOrId);
            if (count($methodOrId) == 2) {
                $filterTable = $methodOrId[0];
                $filterId = trim($methodOrId[1]);
            } else {
                $filterId = $methodOrId[0];
            }
            $filterId = intval($filterId);
        }
        $mnClassHasNnClassTableGateway = new MnClassHasNnClassTableGateway($this->adapter);
        $nnClassTableGateway = new NnClassTableGateway($this->adapter);
        if ($mnClassHasNnClassTableGateway->select()->count() > 0) {
            $mnClassTableGateway = new MnClassTableGateway($this->adapter);
            $mnClassWhere = new Where();
            $mnClassWhere->isNull("deleted_at");
            $mnClassSelect = $mnClassTableGateway->getSql()->select();
            $mnClassSelect->order(["sort asc", "id asc"]);
            $mnClassSelect->where($mnClassWhere);
            $mnClassResultset = $mnClassTableGateway->selectWith($mnClassSelect);
            foreach ($mnClassResultset as $mnRow) {
                /**
                 *
                 * @var RowGateway $mnRow
                 */
                $withInMnClassResultset = $nnClassTableGateway->withInMnClass($mnRow->id);
                $childs = $withInMnClassResultset->toArray();
                foreach ($childs as $key => $child) {
                    $child['tableName'] = 'nn_class';
                    $childs[$key] = $child;
                }
                $mnRow->with("child", $childs);
                $mnRow->with("tableName", "mn_class");
                $mnItem = $mnRow->toArray();
                if ($filterId > 0 && $filterTable == "mn_class" && $mnRow->id == $filterId) {
                    $vars["current"] = $mnItem;
                } else {
                    $vars["current"] = null;
                }
                $vars["items"][] = $mnItem;
            }
            unset($mnClassResultset);
        }
        $nnClassWhere = new Where();
        $nnClassWhere->isNull("deleted_at");
        $nnClassSelect = $nnClassTableGateway->getSql()->select();
        $nnClassSelect->order(["sort ASC", "id ASC"]);
        $nnClassSelect->join(
            $mnClassHasNnClassTableGateway->table,
            "{$nnClassTableGateway->table}.id={$mnClassHasNnClassTableGateway->table}.nn_class_id",
            ["mn_class_id"],
            "LEFT"
        );
        $nnClassSelect->where($nnClassWhere);
        $nnClassResultSet = $nnClassTableGateway->selectWith($nnClassSelect);
        $vars["current"] = null;
        foreach ($nnClassResultSet as $nnRow) {
            if (!$nnRow->mn_class_id) {
                $nnRow->with("tableName", "nn_class");
                $nnItem = $nnRow->toArray();
                $vars["items"][] = $nnItem;
                if ($filterId > 0 &&$filterTable == "nn_class" && $nnRow->id == $filterId) {
                    $vars["current"] = $nnItem;
                }
            }
        }
        unset($nnClassResultSet);
        return $vars;
    }

    /**
     *
     * @inheritdoc
     */
    protected $table = 'news';

    /**
     *
     * @deprecated
     * @param ServerRequest $request
     * @param bool $is_expiration_use
     * @return \Laminas\Db\Sql\Select
     */
    protected function unionContext(ServerRequest $request, $is_expiration_use = false)
    {
        return $this->getSelectPart($request);
    }

    public function getSelectPart(ServerRequest $request)
    {
        $pt = AbstractTableGateway::$prefixTable;
        $query = $request->getQueryParams();
        $language_id = $request->getAttribute('language_id');
        $locale_id = $request->getAttribute('locale_id');
        $methodOrId = $request->getAttribute('methodOrId');
        if (! $methodOrId) {
            $methodOrId = $request->getAttribute('id');
        }
        $select = $this->sql->select();
        $select->order("{$this->table}.publish DESC", "{$this->table}.id DESC");
        $select->quantifier("distinct");
        $where = $select->where;
        $where->isNull("{$this->table}.deleted_at");
        $where->equalTo("{$this->table}.language_id", $language_id);
        $where->equalTo("{$this->table}.locale_id", $locale_id);
        if (empty($query['publish']) || ! $query['publish']) {
            $today = date("Y-m-d");
        } else {
            $today = strtotime($query['publish']);
            $today = date("Y-m-d", $today);
        }
        $where->lessThanOrEqualTo("publish", $today);
        $where = $where->AND->nest();
        $where->greaterThanOrEqualTo("expiration_date", $today);
        $where->OR;
        $where->isNull("expiration_date");
        $where = $where->unnest();

        // 產品關鍵字搜尋
        if (isset($query['q']) && $query['q']) {
            $keyword = $query['q'];
            $like = "%{$keyword}%";
            $searchPredicate = $where->nest();
            $searchPredicate->like("{$this->table}.title", $like);
            $where->addPredicate($searchPredicate, PredicateSet::COMBINED_BY_AND);
        }
        /**
         *
         * @var RouteResult $routeResult
         */

        $routeResult = $request->getAttribute(RouteResult::class);
        $routeParams = $routeResult->getMatchedParams();
        $action = '';
        if (isset($routeParams['action'])) {
            $action = $routeParams['action'];
        } else {
            $action = $routeResult->getMatchedRouteName();
        }
        if ($action == "news-category") {
            $nnClassHasNewsTableGateway = new NnClassHasNewsTableGateway($this->adapter);
            if ($nnClassHasNewsTableGateway->select()->count()) {
                if (preg_match('/^\d+$/', $methodOrId)) {
                    $select->join($nnClassHasNewsTableGateway->table, "{$this->table}.id={$nnClassHasNewsTableGateway->table}.news_id", []);
                    $where->equalTo("{$nnClassHasNewsTableGateway->table}.nn_class_id", $methodOrId);
                }
                $methodOrId = explode("-", $methodOrId);
                if (count($methodOrId) == 2) {
                    $id = intval($methodOrId[1]);
                    $select->join($nnClassHasNewsTableGateway->table, "{$this->table}.id={$nnClassHasNewsTableGateway->table}.news_id", []);
                    $select->join(
                        "{$pt}nn_class",
                        "{$pt}nn_class.id={$nnClassHasNewsTableGateway->table}.nn_class_id",
                        []
                    );

                    $select->join(
                        "{$pt}mn_class_has_nn_class",
                        "{$pt}mn_class_has_nn_class.nn_class_id={$pt}nn_class.id",
                        []
                    );
                    $where->equalTo("{$pt}mn_class_has_nn_class.mn_class_id", $id);
                }
            }
        }
        $select->join(
            "{$pt}users",
            "{$this->table}.users_id={$pt}users.id",
            ["athor" => "account"],
            "LEFT"
        );
        $select->where($where);
        $select->order("{$this->table}.publish desc");
        return $select;
    }

    public function getPaginator(ServerRequest $request, $countPerPage = 8)
    {
        $query = $request->getQueryParams();
        $select = $this->getSelectPart($request);
        if (isset($query["search"]) && strlen(trim($query["search"]))) {
            $where = $select->where;
            $search = $query["search"];
            $where->like("{$this->table}.title", "%{$search}%");
            $where = $where->OR;
            $where->like("{$this->table}.content", "%{$search}%");
            $select->where($where);
        }
        $pagiAdapter = new DbSelect($select, $this->adapter);
        $paginator = new Paginator($pagiAdapter);
        $paginator->setItemCountPerPage($countPerPage);

        $pageNumber = isset($query['page']) ? intval($query['page']) : 1;
        $paginator->setCurrentPageNumber($pageNumber);
        $items = (array) $paginator->getCurrentItems();
        for ($i = 0; $i < count($items); $i ++) {
            $content = $items[$i]['content'];
            $items[$i]['content'] = strip_tags($content);
        }
        $pages = $paginator->getPages();
        $pages->pagesInRange = (array) $pages->pagesInRange;
        $pages->pagesInRange = array_values($pages->pagesInRange);
        return [
            'news' => $items,
            'pages' => $pages
        ];
    }

    public function getTags($newsId)
    {
        $tagsTableGateway = new TagsTableGateway($this->adapter);
        return $tagsTableGateway->withNews($newsId);
    }
}
