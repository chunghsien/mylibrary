<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\ResultSet\ResultSetInterface;
use Laminas\Db\Sql\Join;

class LogisticsGlobalTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    public static $isOutputResultSet = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'logistics_global';

    protected function isExists(ServerRequestInterface $request, $set)
    {
        return $this->select($set)->count;
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @param number $isUse
     * @return ResultSetInterface|array
     */
    public function getLogistics(ServerRequestInterface $request, $isUse = 1)
    {
        $language_id = $request->getAttribute('language_id');
        $locale_id = $request->getAttribute('locale_id');
        $select = $this->sql->select();
        $couponHasLogisticsGlobalTableGateway = new CouponHasLogisticsGlobalTableGateway($this->adapter);
        $couponTableGateway = new CouponTableGateway($this->adapter);
        $select->join(
            $couponHasLogisticsGlobalTableGateway->table,
            "{$couponHasLogisticsGlobalTableGateway->table}.logistics_global_id={$this->table}.id",
            [],
            Join::JOIN_LEFT
        );
        $select->join(
            $couponTableGateway->table,
            "{$couponHasLogisticsGlobalTableGateway->table}.coupon_id={$couponTableGateway->table}.id",
            ["target_value"],
            Join::JOIN_LEFT
        );
        $select->order([
            "sort asc",
            "{$this->table}.id asc"
        ]);
        $where = new Where();
        $where->equalTo('method', 'shipping');
        $where->isNull("{$this->table}.deleted_at");
        $where->isNull("{$couponTableGateway->table}.deleted_at");
        $where->equalTo("{$this->table}.language_id", $language_id);
        $where->equalTo("{$this->table}.locale_id", $locale_id);
        if ($isUse < 2) {
            $where->equalTo("{$this->table}.is_use", $isUse);
        }
        $select->where($where);
        $resultSet = $this->selectWith($select);
        if (self::$isOutputResultSet) {
            return $resultSet;
        }
        $result = $resultSet->toArray();
        return $result;
    }

    /**
     *
     * @deprecated
     * @param ServerRequestInterface $request
     * @param number $isUse
     * @return ResultSetInterface|array
     */
    public function getPayMethods(ServerRequestInterface $request, $isUse = 1)
    {
        $language_id = $request->getAttribute('language_id');
        $locale_id = $request->getAttribute('locale_id');
        $where = new Where();
        $where->equalTo('method', 'payment');
        $where->isNull('deleted_at');
        $where->equalTo('language_id', $language_id);
        $where->equalTo('locale_id', $locale_id);
        if ($isUse < 2) {
            $where->equalTo('is_use', $isUse);
        }
        $resultSet = $this->select($where);
        if (self::$isOutputResultSet) {
            return $resultSet;
        }
        $result = $resultSet->toArray();
        return $result;
    }
}
