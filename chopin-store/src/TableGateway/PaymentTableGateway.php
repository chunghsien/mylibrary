<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\RowGateway\RowGatewayInterface;

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
    /**
     * 
     * @desc 取出結帳用的付款方式Row
     * @param int $id
     * @return RowGatewayInterface|null
     */
    public function getForCheckoutRow($id)
    {
        $paymentWhere = new Where();
        $paymentWhere->equalTo("id", $id);
        $paymentWhere->equalTo("is_use", 1);
        $paymentWhere->isNull("deleted_at");
        return $this->select($paymentWhere)->current();
    }
}
