<?php

namespace Chopin\LaminasDb\DB\Traits;

use Laminas\Cache\Storage\Adapter\AbstractAdapter;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Db\ResultSet\ResultSetInterface;
//use Laminas\Cache\StorageFactory;
use Chopin\SystemSettings\TableGateway\DbCacheMapperTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Cache\Storage\StorageInterface;
use Mezzio\Router\RouteResult;
use Chopin\Support\Registry;
use Laminas\ServiceManager\ServiceManager;

trait CacheTrait
{
    /**
     *
     * @var AbstractAdapter
     */
    protected $cacheAdapter;

    protected $env_cache_use = false;

    protected $env_cache_vars = false;

    protected $initAdapter = 0;

    protected function initCacheAdapter()
    {
        try {
            if ($this->initAdapter == 0) {
                if ($this->cacheAdapter instanceof AbstractAdapter == false) {
                    $config = config('caches.' . StorageInterface::class);
                    if (isset($config['adapter']['options']['cache_dir'])) {
                        $cacheDir = $config['adapter']['options']['cache_dir'];
                        if (!is_dir($cacheDir)) {
                            mkdir($cacheDir, 0755, true);
                        }
                    }
                    $cacheAdapter = Registry::get(StorageInterface::class);
                    if(!$cacheAdapter){
                        $serviceContainer = Registry::get(ServiceManager::class);
                        $cacheAdapter = $serviceContainer->get(StorageInterface::class);
                    }
                    /**
                     * *先實作filecache就好，後面再來慢慢處理
                     *
                     * @var Filesystem $cacheAdapter
                     */
                    $this->cacheAdapter = $cacheAdapter;
                }
                $env_cache_use = config('env_cache');
                $this->env_cache_use = $env_cache_use['db'];
                $this->env_cache_vars = $env_cache_use['vars'];

                if ($this->cacheAdapter instanceof AbstractAdapter == false) {
                    $this->env_cache_use = false;
                    $this->env_cache_vars = false;
                }
            }
            $this->initAdapter ++;
        } catch (\Exception $e) {
            loggerException($e);
            throw \Exception;
        }
    }

    public function getEnvCacheUse()
    {
        $this->initCacheAdapter();
        return $this->env_cache_use;
    }

    public function getEnvCacheVars()
    {
        $this->initCacheAdapter();
        return $this->env_cache_vars;
    }

    /**
     *
     * @param string $serial
     * @param string $table
     * @param string $type
     */
    protected function saveDbCacheMapper($serial, $table, $type = 'env_cache_use')
    {
        $data = [
            'serial' => $serial,
            'table' => isset($table) && is_string($table) ? $table : '*',
            ];
        $this->saveDbCacheMapperFromSet($data);
    }

    protected function saveDbCacheMapperFromSet($set, $type = 'env_cache_use')
    {
        $this->initCacheAdapter();
        $verify = $this->{$type};
        if ($verify) {
            $exists = DbCacheMapperTableGateway::select($set)->count();
            if ($exists == 0) {
                DbCacheMapperTableGateway::insert($set);
            }
        }
    }

    protected $bindsuse = [];

    /**
     *
     * @param ServerRequestInterface $request
     * @param string|array $table
     * @return array
     */
    protected function buildCacheSet(ServerRequestInterface $request, $table)
    {
        $serial = '';
        $this->initCacheAdapter();
        /**
         *
         * @var RouteResult $routeResult
         */
        $routeResult = $request->getAttribute(RouteResult::class);
        $routeParams = $routeResult->getMatchedParams();
        $queryParams = $request->getQueryParams();
        $serial.= serialize($routeParams).serialize($queryParams);
        if (is_array($table)) {
            $serial.= serialize($table);
            $tmp = [];
            foreach ($table as $t) {
                $tmp[] = "-{$t}-";
            }
            $table = implode(",", $tmp);
        } else {
            $serial.= $table;
            $table = "-{$table}-";
        }
        return [
            "serial" => crc32($serial),
            "table" => $table,
        ];
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @param string $concatKey
     * @return number
     */
    public function buildCacheKey(ServerRequestInterface $request, $concatKey = '')
    {
        $serverParams = $request->getServerParams();
        $queryParams = json_encode($request->getQueryParams());
        $post = json_encode($request->getParsedBody());
        $contents = $request->getBody()->getContents();
        switch ($concatKey) {
            case 'site_header':
            case 'site_footer':
                $cacheKey = $concatKey;
                break;
            default:
                $requestUri = $serverParams["REQUEST_URI"];
                $cacheKey = crc32($post) + crc32($contents) + crc32($queryParams) + crc32($requestUri);
                if ($concatKey) {
                    $cacheKey+= crc32($concatKey);
                }
                break;
        }
        $methodOrId = $request->getAttribute('methodOrId');
        if ($methodOrId) {
            if (!is_numeric($cacheKey)) {
                $cacheKey = crc32($cacheKey);
            }
            $cacheKey += crc32($methodOrId);
        }
        return $cacheKey;
    }

    protected function getCache($key, $type = 'env_cache_use')
    {
        try {
            $this->initCacheAdapter();
            $verify = $this->{$type};
            if ($verify) {
                return $this->cacheAdapter->getItem($key);
            }
            return null;
        } catch (\Exception $e) {
            loggerException($e);
            throw new \ErrorException($e->getMessage());
        }
    }
    /**
     *
     * @param string $key
     * @param mixed $value
     * @param string $type
     * @return bool
     */
    protected function setCache($key, $value, $type = 'env_cache_use')
    {
        $this->initCacheAdapter();
        $verify = $this->{$type};
        if ($verify) {
            if ($value instanceof ResultSetInterface) {
                /**
                 * \Laminas\Db\ResultSet\ResultSet $value
                 */
                if ($value->getDataSource() instanceof \Laminas\Db\Adapter\Driver\Pdo\Result) {
                    $result = $value->getDataSource();
                    $dataSource = new \ArrayIterator();
                    foreach ($result as $item) {
                        $dataSource->append($item);
                    }
                    unset($result);
                    $value->initialize($dataSource);
                }
            }
            return $this->cacheAdapter->setItem($key, $value);
        }
        return false;
    }
}
