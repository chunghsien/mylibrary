<?php

namespace Chopin\Store\Logistics;

use Laminas\Db\Adapter\Adapter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @author User
 * @desc 物流抽象類別
 *
 */
abstract class AbstractLogistics
{
    /**
     *
     * @var Adapter
     */
    protected $adapter;

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * 
     * @param string $extraParamsJson
     * @return string
     */
    public function withExtraParams($extraParamsJson, $url='') {
        $extraParamsArr = json_decode($extraParamsJson, true);
        return json_encode($extraParamsArr);
    }
    
    public function buildContent(ServerRequestInterface $request):string{
        throw new \ErrorException('不適用');
        return '';
    }
}
