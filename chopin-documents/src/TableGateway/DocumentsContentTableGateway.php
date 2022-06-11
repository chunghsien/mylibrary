<?php

namespace Chopin\Documents\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;

class DocumentsContentTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'documents_content';

    public function getItems($documents_id, $theme = null)
    {
        $where = new Where();
        $where->equalTo('documents_id', $documents_id);
        $where->isNull('deleted_at');
        if ($theme && preg_match('/^aboutus/i', $theme)) {
            $where->like('theme', $theme."%");
        } else {
            if ($theme) {
                $where->equalTo('theme', $theme);
            }
        }
        $select = $this->sql->select();
        $select->where($where);
        $select->order("id asc");
        return $this->selectWith($select)->toArray();
    }
}
