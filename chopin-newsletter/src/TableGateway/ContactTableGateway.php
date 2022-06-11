<?php

namespace Chopin\Newsletter\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;

class ContactTableGateway extends AbstractTableGateway
{
    use SecurityTrait;

    public static $isRemoveRowGatewayFeature = false;

    public function __construct($adapter)
    {
        parent::__construct($adapter);
        $this->initCrypt();
    }

    /**
     *
     * @inheritdoc
     */
    protected $table = 'contact';
}
