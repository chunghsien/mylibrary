<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Where;

class ProductsOption1TableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_option1';

    public function buildSearchData(ServerRequestInterface $request)
    {
        $languageId = $request->getAttribute('language_id');
        $localeId = $request->getAttribute('locale_id');
        $where = new Where();
        $where->equalTo("language_id", $languageId);
        $where->equalTo("locale_id", $localeId);
        $where->isNull("deleted_at");
        $where->notEqualTo("name", "none");
        return $this->select($where)->toArray();
    }
}
