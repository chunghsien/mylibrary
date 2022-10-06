<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;

class InvoiceTableGateway extends AbstractTableGateway
{
    use SecurityTrait;
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = "invoice";
    
    public function __construct($adapter) {
        parent::__construct($adapter);
        $addDefaultEncryptionColumns = ['customer_name', 'customer_addr', 'customer_phone', 'customer_email'];
        $this->defaultEncryptionColumns = array_merge($this->defaultEncryptionColumns, $addDefaultEncryptionColumns);
    }
}
