<?php

namespace Chopin\LaminasDb;

use Chopin\Support\Registry;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Db\Adapter\Adapter;
use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\TableIdentifier;
use Laminas\Db\Sql\Sql;
use Laminas\Db\ResultSet\ResultSet;
use Chopin\LaminasPaginator\Adapter\DbSelect;
use Laminas\Paginator\Paginator;
use Chopin\LaminasDb\DB\Traits\CacheTrait;
use Laminas\Db\Sql\Where;
use Laminas\Filter\Word\UnderscoreToCamelCase;
use Chopin\SystemSettings\TableGateway\SystemSettingsTableGateway;
use Laminas\Db\TableGateway\Feature\GlobalAdapterFeature;

class DB
{
    use CacheTrait;

    /**
     *
     * @var Adapter
     */
    protected $adapter;

    protected $table;

    /**
     *
     * @var Select
     */
    protected $select;

    /**
     *
     * @var Adapter
     */
    protected static $staticAdapter;

    public $securityEnabled = false;

    /**
     *
     * @var \Laminas\Db\TableGateway\AbstractTableGateway
     */
    protected static $staticTablegateway;

    /**
     * @desc 針對 Mysql8 關閉 ONLY_FULL_GROUP_BY的處理
     */
    public static function mysql8HigherGroupByFix()
    {
        try {
            $adapter = self::$staticAdapter;
            if (!$adapter) {
                self::$staticAdapter = GlobalAdapterFeature::getStaticAdapter();
                $adapter = self::$staticAdapter;
            }
            /**
             *
             * @var \PDO $resource
             */
            $resource = $adapter->getDriver()->getConnection()->getResource();
            if (strtolower($resource->getAttribute(\PDO::ATTR_DRIVER_NAME)) == "mysql") {
                $mySqlVer = $resource->getAttribute(\PDO::ATTR_DRIVER_NAME).preg_replace('/\-(.*)$/', '', $resource->getAttribute(\PDO::ATTR_SERVER_VERSION));
                $mySqlVer = preg_replace('/mysql/i', '', $mySqlVer);
                $mySqlVer = floatval($mySqlVer);
                $adapter->getDriver()->getConnection()->execute("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
            }
        } catch (\Exception $e) {
            loggerException($e);
        }
    }

    public static function setStaticTablegateway(\Laminas\Db\TableGateway\AbstractTableGateway $tablegateway)
    {
        self::$staticTablegateway = $tablegateway;
    }

    /**
     *
     * @param string $connection
     * @return \Chopin\LaminasDb\DB
     */
    protected static function init($connection = '', $table = null)
    {
        $adapter = self::initAdapter();
        return new static($adapter, $table);
    }

    /**
     *
     * @param string $connection
     * @return \Laminas\Db\Adapter\Adapter
     */
    protected static function initAdapter($connection = ''): Adapter
    {
        /**
         *
         * @var ServiceManager $serviceManager
         */
        $serviceManager = Registry::get(ServiceManager::class);
        if (trim($connection) == '') {
            $adapter = $serviceManager->get(Adapter::class);
        } else {
            $adapter = $serviceManager->get($connection);
        }

        self::$staticAdapter = $adapter;
        return self::$staticAdapter;
    }

    public static function transaction(\Closure $callback)
    {
        $adapter = self::initAdapter();
        $connection = $adapter->getDriver()->getConnection();
        $connection->beginTransaction();
        try {
            $callback();
        } catch (\Exception $e) {
            $connection->rollback();
            loggerException($e);
            return false;
        }
        $connection->commit();
        return true;
    }

    public static function beginTransaction()
    {
        $adapter = self::initAdapter();
        $connection = $adapter->getDriver()->getConnection();
        return $connection->beginTransaction();
    }

    public static function rollback()
    {
        $adapter = self::initAdapter();
        $connection = $adapter->getDriver()->getConnection();
        return $connection->rollback();
    }

    public static function commit()
    {
        $adapter = self::initAdapter();
        $connection = $adapter->getDriver()->getConnection();
        return $connection->commit();
    }

    public function __construct(Adapter $adapter, $table = null)
    {
        $this->table = $table;
        $this->adapter = $adapter;
    }

    public static function processScripts(Select $select, array $scripts)
    {
        foreach ($scripts as $method => $params) {
            switch ($method) {
                case 'quantifier':
                case 'from':
                case 'columns':
                case 'group':
                case 'order':
                case 'limit':
                case 'offset':
                    if (false === is_array($params)) {
                        $select->{$method}($params);
                    } else {
                        // $select->from($table);
                        if ($method == 'from') {
                            if (is_string($params) || $params instanceof TableIdentifier/* || is_array($params)*/) {
                                $select->from($params);
                            } else {
                                if (is_array($params)) {
                                    $select->from($params[0]);
                                } else {
                                    $keys = array_keys($params);
                                    if ($keys[0] === 0) {
                                        call_user_func_array([
                                            $select,
                                            'from'
                                        ], $params);
                                    } else {
                                        if (is_string($keys[0]) && false !== array_search('from', $keys, true)) {
                                            $subSelect = self::processScripts(new Select(), $scripts['from']);
                                            $subqueryName = 'subquery_' . $scripts['from']['from'];
                                            $select->from([
                                                $subqueryName => $subSelect
                                            ]);
                                        } else {
                                            if (is_string($params[$keys[0]])) {
                                                $select->from($params);
                                            } else {
                                                throw new \Error('from 參數錯誤');
                                            }
                                        }
                                    }
                                }
                            }
                        } else if($method == 'order'){
                            if(substr_count($params[0], '[') > 1){
                                $newParams = explode(' ', $params[0]);
                                foreach ($newParams as $item){
                                    $itemArr = json_decode($item);
                                    if($itemArr){
                                        $orderExpresssive = $itemArr[0].' '.$itemArr[1];
                                        $select->order($orderExpresssive);
                                    }
                                }
                            }else{
                                call_user_func_array([
                                    $select,
                                    $method
                                ], $params);
                            }
                        }else {
                            call_user_func_array([
                                $select,
                                $method
                            ], $params);
                        }
                    }
                    break;
                case 'join':
                    if (is_string($params[0])) {
                        call_user_func_array([
                            $select,
                            'join'
                        ], $params);
                    } else {
                        foreach ($params as $join) {
                            call_user_func_array([
                                $select,
                                'join'
                            ], $join);
                        }
                    }
                    break;
                case 'where':
                case 'having':
                    $where = $select->where;
                    $allow_methods = [
                        'equalto',
                        'notequalto',
                        'lessthan',
                        'greaterthan',
                        'lessthanorequalto',
                        'greaterthanorequalto',
                        'like',
                        'notlink',
                        'expression',
                        // 'literal',
                        'isnull',
                        'isnotnull',
                        'in',
                        'notin',
                        'between',
                        'notbetween'
                    ];
                    if ($params && is_string($params[0]) && false !== array_search(strtolower($params[0]), $allow_methods, true)) {
                        $logic = strtolower($params[1]);
                        $where->{$logic};
                        call_user_func_array([
                            $where,
                            $params[0]
                        ], $params[2]);
                    } else {
                        foreach ($params as $_params) {
                            if (is_string($_params[0]) && false !== array_search(strtolower($_params[0]), $allow_methods, true)) {
                                $logic = strtolower($_params[1]);
                                $where->{$logic};
                                call_user_func_array([
                                    $where,
                                    $_params[0]
                                ], $_params[2]);
                            } else {
                                if (is_string($_params[0]) && is_array($_params[1])) {
                                    $logic = strtolower($_params[0]);
                                    $where->{$logic};
                                    $nestWhere = new Where();
                                    foreach ($_params[1] as $nest) {
                                        $nestMethod = $nest[0];
                                        $nestLogic = strtolower($nest[1]);
                                        $nestWhere->{$nestLogic};
                                        call_user_func_array([
                                            $nestWhere,
                                            $nestMethod
                                        ], $nest[2]);
                                    }
                                    $where->addPredicate($nestWhere, $logic);
                                }
                            }
                        }
                    }
                    if ($method == 'where') {
                        $select->where($where);
                    } else {
                        $select->having($where);
                    }
                    break;
            }
        }
        unset($scripts);
        return $select;
    }

    protected function buildResultset(Select $select, $bindParams = [], $paginatorParams = null, $currentPageNumber = null)
    {
        $adapter = $this->adapter;
        $sql = new Sql($adapter);
        if ($_ENV["APP_ENV"] != 'production') {
            logger()->debug($sql->buildSqlString($select));
        }
        if (is_int($currentPageNumber)) {
            $adapter = self::initAdapter();
            $bindParams = is_array($bindParams) ? $bindParams : [];
            $paginatorAdapter = new DbSelect($select, $adapter);
            $paginatorAdapter->setBindParams($bindParams);
            // debug($bindParams);
            $paginator = new Paginator($paginatorAdapter);
            if ($paginatorParams) {
                $underscoreToCamelCase = new UnderscoreToCamelCase();
                foreach ($paginatorParams as $idx => $value) {
                    $idx = $underscoreToCamelCase->filter($idx);
                    $idx = ucfirst($idx);
                    $func = 'set' . $idx;
                    $paginator->{$func}($value);
                }
            }
            unset($paginatorParams);

            $paginator->setCurrentPageNumber($currentPageNumber);
            $pages = $paginator->getPages();
            $pages->pagesInRange = array_values($pages->pagesInRange);
            $result = [
                'items' => (array) $paginator->getCurrentItems(),
                'pages' => $pages
            ];
            $tableRawState =  $select->getRawState('table');
            if(is_array($tableRawState)) {
                $tablename = array_keys($tableRawState)[0];
                $tableRawState = $tablename;
            }
            $table = str_replace(AbstractTableGateway::$prefixTable, '', $tableRawState);
            if (self::$staticTablegateway instanceof \Laminas\Db\TableGateway\AbstractTableGateway) {
                $tableGateway = self::$staticTablegateway;
            } else {
                if (is_string($table)) {
                    $table = str_replace('_decrypt', '', $table);
                    /**
                     *
                     * @var SystemSettingsTableGateway $tableGateway
                     */
                    $tableGateway = AbstractTableGateway::newInstance($table, $adapter);
                }
            }

            // 如果script有用子查詢取出解密資料就不需要另外再進行解密了
            if (isset($tableGateway) && isset($tableGateway->defaultEncryptionColumns)) {
                if (is_string($select->getRawState('table'))) {
                    $items = $result['items'];
                    foreach ($items as &$item) {
                        $item = $tableGateway->deCryptData($item);
                    }
                    $result['items'] = $items;
                }
            }
            $returnMainTable = $select->getRawState("from")["table"];
            $pt = AbstractTableGateway::$prefixTable;
            if (is_string($returnMainTable)) {
                $result["main_table"] = str_replace($pt . "_", "", $returnMainTable);
            }
            if (is_array($returnMainTable)) {
                $main_table_keys = array_keys($returnMainTable);
                $result["main_table"] = preg_replace("/_decrypt$/i", "", $main_table_keys[0]);
            }
            return $result;
        } else {
            $result = $sql->prepareStatementForSqlObject($select)->execute($bindParams);
            $resultSet = new ResultSet();
            $resultSet->initialize($result);
            return $resultSet;
        }
    }

    public static function selectFactory($scripts = [], $bindParams = [])
    {
        $select = new Select();
        self::processScripts($select, $scripts);
        $bindParams = is_array($bindParams) ? $bindParams : [];
        $DB = self::init();
        if (isset($scripts["default_order"]) && empty($scripts["order"])) {
            if (! $select->getRawState('order')) {
                $select->order($scripts["default_order"]);
            }
        }
        return $DB->buildResultset($select, $bindParams);
    }

    public static function paginatorFactory($scripts = [], $bindParams = [], $paginatorParams = [], $currentPageNumber = 1)
    {
        $select = new Select();
        self::processScripts($select, $scripts);
        $bindParams = is_array($bindParams) ? $bindParams : [];
        $DB = self::init();
        return $DB->buildResultset($select, $bindParams, $paginatorParams, $currentPageNumber);
    }

    public static function __callStatic($name, $arguments)
    {
        if ($name == 'connection') {
            return self::init($arguments[0]);
        }
        if ($name == 'table') {
            return self::init('', $arguments[0]);
        }
        $cruds = [
            'select',
            'insert',
            'update',
            'delete'
        ];
        if (false !== array_search($name, $cruds, true)) {
            $adapter = self::initAdapter('', $arguments[0]);
            /**
             *
             * @var StatementInterface $statement
             */
            $statement = call_user_func_array([
                $adapter,
                'query'
            ], $arguments[0]);
            $parameters = isset($arguments[1]) ? $arguments[1] : null;
            return $statement->execute($parameters);
        }
    }

    public function __get($key)
    {
        if (strtolower($key) == 'select') {
            return $this->select;
        }
    }

    public function __set($key, $value)
    {
        if (strtolower($key) == 'select' && $value instanceof Select) {
            $this->select = $value;
        }
    }

    public function __call($name, $arguments)
    {
        if ($this->table) {
            $allowed = [
                'select',
                'update',
                'insert',
                'delete'
            ];
            if (false !== array_search($name, $allowed, true)) {
                $tableGateway = AbstractTableGateway::newInstance($this->table, $this->adapter);
                return call_user_func_array([
                    $tableGateway,$name
                ], $arguments);
            }
        } else {
            /**
             *
             * @var StatementInterface $statement
             */
            $statement = call_user_func_array([
                $this->adapter,'query'
            ], $arguments[0]);
            $parameters = isset($arguments[1]) ? $arguments[1] : null;
            return $statement->execute($parameters);
        }
    }
}
