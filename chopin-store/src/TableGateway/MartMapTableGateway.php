<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

/**
 * @deprecated
 * @author hsien
 *
 */
class MartMapTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'mart_map';
}
