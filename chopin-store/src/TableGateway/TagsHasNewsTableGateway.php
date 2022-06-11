<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

class TagsHasNewsTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = true;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'tags_has_news';
}
