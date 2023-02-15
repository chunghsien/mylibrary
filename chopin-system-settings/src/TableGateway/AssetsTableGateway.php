<?php

namespace Chopin\SystemSettings\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

class AssetsTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'assets';
    
    /**
     * 
     * @param string $table
     * @param number $table_id
     * @param number $limit
     * @return array
     */
    public function getAssets($table, $table_id, $limit=0) {
        $select = $this->sql->select();
        $select->where([
            'table' => $table,
            'table_id' => $table_id
        ]);
        $select->order(['is_top DESC', 'sort ASC']);
        if($limit > 0) {
            $select->limit($limit);
        }
        return $this->selectWith($select)->toArray();
    }
}
