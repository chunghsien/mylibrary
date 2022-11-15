<?php

namespace Chopin\SystemSettings\TableGateway;

use Laminas\Db\TableGateway\Feature\GlobalAdapterFeature;
use Laminas\Db\Sql\Sql;
use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Predicate\Predicate;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\Sql\Where;
use Laminas\Cache\Service\StorageAdapterFactory;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\Db\Adapter\Adapter;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Cache\Service\StorageCacheAbstractServiceFactory;

abstract class DbCacheMapperTableGateway
{
    /**
     *
     * @inheritdoc
     */
    protected static $table = 'db_cache_mapper';

    /**
     *
     * @var Sql
     */
    protected static $sql;

    protected static function singleton()
    {
        $table = str_replace(AbstractTableGateway::$prefixTable, '', self::$table);
        self::$table = AbstractTableGateway::$prefixTable . $table;
        if (! (self::$sql instanceof Sql)) {
            $adapter = GlobalAdapterFeature::getStaticAdapter();
            self::$sql = new Sql($adapter);
            self::$sql->setTable(self::$table);
        }
    }

    /**
     *
     * @param array $values
     * @return number
     */
    public static function insert($values)
    {
        self::singleton();
        $sql = self::$sql;
        $select = $sql->select();
        if (isset($values['serial'])) {
            $select->where([
                'serial' => $values['serial']
            ]);
            if ($sql->prepareStatementForSqlObject($select)
                ->execute()
                ->count() == 0) {
                $insert = $sql->insert();
                $insert->values($values);
                return $sql->prepareStatementForSqlObject($insert)
                    ->execute()
                    ->getAffectedRows();
            }
        }
        return 0;
    }

    public static function refreash($tablename)
    {
        self::singleton();
        try {
            $cfg = config('caches.' . StorageInterface::class);
            if ($cfg) {
                /**
                 * 
                 * @var ServiceManager $serviceContainer
                 */
                $serviceContainer = \Chopin\Support\Registry::get(ServiceManager::class);
                /**
                 *
                 * @var \Laminas\Cache\Storage\Adapter\Filesystem $cacheAdapter
                 */
                $cacheAdapter = $serviceContainer->get(StorageInterface::class);
                $predicate = new Where();
                $predicate->like('table', "%-{$tablename}-%");
                $predicate->OR;
                $predicate->equalTo('table', '*');
                $cacheQueryResultSet = self::select($predicate);
                if ($cacheQueryResultSet->count() > 0) {
                    foreach ($cacheQueryResultSet as $row) {
                        $cacheAdapter->removeItem($row->serial);
                    }
                }
                unset($cacheQueryResultSet);
            }
        } catch (\Exception $e) {
            loggerException($e);
            return false;
        }
    }

    /**
     *
     * @param Predicate|array $predicate
     * @return \Laminas\Db\ResultSet\ResultSet
     */
    public static function select($predicate)
    {
        self::singleton();
        $sql = self::$sql;
        $pt = AbstractTableGateway::$prefixTable;
        /**
         *
         * @var Adapter $adapter
         */
        $adapter = $sql->getAdapter();
        $tbExistsSource = $adapter->query("SHOW TABLES LIKE '{$pt}db_cache_mapper';", Adapter::QUERY_MODE_EXECUTE);
        if ($tbExistsSource->count()) {
            $select = $sql->select();
            $select->where($predicate);
            $dataSource = $sql->prepareStatementForSqlObject($select)->execute();
            $resultSet = new ResultSet();
            $resultSet->initialize($dataSource);
            return $resultSet;
        }
        return new ResultSet();
    }

    /**
     *
     * @param Predicate|array $predicate
     * @return number
     */
    public static function delete($predicate)
    {
        self::singleton();
        $sql = self::$sql;
        $select = $sql->select();
        $select->where($predicate);
        if ($sql->prepareStatementForSqlObject($select)->execute()->count() == 0) {
            $delete = $sql->delete();
            $delete->where($predicate);
            return $sql->prepareStatementForSqlObject($delete)->execute()->getAffectedRows();
        }
        return 0;
    }
}
