<?php


declare(strict_types=1);

namespace Chopin\Store\Logistics;

use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Adapter\Adapter;

class StaticPayment extends AbstractPayment
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

    public function requestApi(ServerRequestInterface $request, $orderData)
    {
        return [
            "status" => "success"
        ];
    }
    public function getConfig($language_id = 119, $locale_id = 229)
    {
        return [];
    }
}
