<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;

class InvoiceAllowanceTableGateway extends AbstractTableGateway
{
    use SecurityTrait;
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = "invoice_allowance";
    
    public function __construct($adapter) {
        parent::__construct($adapter);
        $addDefaultEncryptionColumns = ['customer_name', 'notify_phone', 'notify_mail'];
        $this->defaultEncryptionColumns = array_merge($this->defaultEncryptionColumns, $addDefaultEncryptionColumns);
    }
}
