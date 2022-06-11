<?php

namespace Chopin\Store\CouponRule;

use Chopin\Store\TableGateway\LogisticsGlobalTableGateway;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @author hsien
 *
 */
class FreeShippingCouponRule extends AbstractRule
{
    public const TYPE_NAME = "freeshipping";

    /**
     * @deprecated
     * @param ServerRequestInterface $request
     * @param number $subtotal
     * @param number $target_value
     * @return boolean[]
     */
    public function getValues(ServerRequestInterface $request, $subtotal, $target_value)
    {
        $logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($this->adapter);
        LogisticsGlobalTableGateway::$isOutputResultSet = true;
        $resultSet = $logisticsGlobalTableGateway->getLogistics($request);
        $result = [];
        foreach ($resultSet as $row) {
            $shipping = $subtotal >= $target_value ? 0 : $row->price;
            $item = $row->toArray();
            $item["price"] = $shipping;
            $item["is_free_shipping"] = $subtotal >= $target_value ? true : false;
            $result[] = $item;
        }
        unset($resultSet);
        LogisticsGlobalTableGateway::$isOutputResultSet = false;
        return $result;
    }
}
