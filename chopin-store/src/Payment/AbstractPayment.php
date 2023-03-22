<?php
namespace Chopin\Store\Payment;

use Laminas\Db\Adapter\Adapter;
use Psr\Http\Message\ServerRequestInterface;

abstract class AbstractPayment
{

    /**
     *
     * @var Adapter
     */
    protected $adapter;
    
    /**
     * 
     * @var string
     */
    protected $content;
    
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    public function buildContent(ServerRequestInterface $request): bool
    {
        return false;
    }
}