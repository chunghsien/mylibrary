<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;

class LogisticsServiceTableGateway extends AbstractTableGateway
{

    use SecurityTrait;

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'logistics_service';
}
