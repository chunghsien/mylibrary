<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

/**
 * @deprecated
 * @author hsien
 *
 */
class ProductsSpecIdentityTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_spec_identity';
}
