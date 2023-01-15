<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;

class OrderParamsTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    use SecurityTrait;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'order_params';
}
