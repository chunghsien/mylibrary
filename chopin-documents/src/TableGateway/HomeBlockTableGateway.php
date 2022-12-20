<?php

namespace Chopin\Documents\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Where;

class HomeBlockTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'home_block';

    public function buildHomeBlock(ServerRequestInterface $request)
    {
        $theme = config('lezada.pageStyle.index');
        $theme = str_replace('@app/lezada/Home/', '', $theme);
        $theme = preg_replace('/\.html\.twig$/', '', $theme);
        $languageId = $request->getAttribute('language_id');
        $localeId = $request->getAttribute('locale_id');
        $select = $this->getSql()->select();
        $select->order(['sort asc', 'id asc']);
        $where = new Where();
        $where->equalTo('is_show', 1);
        $where->equalTo('theme', $theme);
        $where->equalTo('language_id', $languageId);
        $where->equalTo('locale_id', $localeId);
        $where->isNull("deleted_at");
        $select->where($where);
        $resultSet = $this->selectWith($select);
        $result = [];

        foreach ($resultSet as $row) {
            $key = $row->key;
            if (empty($result[$key])) {
                $result[$key] = [];
            }
            $result[$key][] = $row->content;
        }
        unset($resultSet);
        return $result;
    }
}
