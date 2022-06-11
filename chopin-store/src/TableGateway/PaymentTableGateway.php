<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;

class PaymentTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'payment';

    /**
     *
     * @param ServerRequestInterface $request
     * @param number $isUse
     * @return array
     */
    public function getPayments(ServerRequestInterface $request, $isUse = 1)
    {
        $language_id = $request->getAttribute('language_id');
        $locale_id = $request->getAttribute('locale_id');
        $where = new Where();
        $where->isNull('deleted_at');
        $where->equalTo('language_id', $language_id);
        $where->equalTo('locale_id', $locale_id);
        if ($isUse < 2) {
            $where->equalTo('is_use', $isUse);
        }
        $resultSet = $this->select($where);
        $result = $resultSet->toArray();
        return $result;
    }
}
