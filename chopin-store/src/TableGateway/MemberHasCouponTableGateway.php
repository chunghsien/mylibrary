<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

/**
 *
 * @author hsien
 *
 */
class MemberHasCouponTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = true;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'member_has_coupon';
}
