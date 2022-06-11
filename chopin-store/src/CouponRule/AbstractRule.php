<?php

namespace Chopin\Store\CouponRule;

use Laminas\Db\Adapter\Adapter;

abstract class AbstractRule
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
}
