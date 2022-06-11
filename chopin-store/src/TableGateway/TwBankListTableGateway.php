<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

class TwBankListTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'tw_bank_list';

    /**
     *
     * {@inheritDoc}
     * @see \Chopin\LaminasDb\TableGateway\AbstractTableGateway::getOptions()
     */
    public function getBanklOptions($service)
    {
        $resultSet = $this->select(["service" => $service]);
        $options = [];
        foreach ($resultSet as $row) {
            $options[] = ["value" => $row->bic, "label" => $row->name];
        }
        unset($resultSet);
        return $options;
    }

    /**
     *
     * @param string $bic
     * @return array
     */
    public function fetchRowUseBic(string $bic)
    {
        $select = $this->sql->select();
        $select->where(["bic" => $bic])->limit(1);
        return $this->selectWith($select)->current();
    }
}
