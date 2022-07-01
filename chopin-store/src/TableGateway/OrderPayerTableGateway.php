<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;
use Laminas\Db\Sql\Select;
use Laminas\Db\ResultSet\ResultSet;

class OrderPayerTableGateway extends AbstractTableGateway
{
    use SecurityTrait;

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = "order_payer";

    /**
     *
     * @param int $id
     * @return array|\ArrayObject
     */
    public function getOrderPayer($order_id)
    {
        $subSelect = $this->decryptSubSelectRaw;
        $select = new Select();
        $select = $select->from([
            $this->getDecryptTable() => $subSelect])->where(['order_id' => $order_id]);
        $dataSource = $this->sql->prepareStatementForSqlObject($select)->execute();
        $resultSet = new ResultSet();
        $resultSet->initialize($dataSource);
        return $resultSet->current();
    }
}
