<?php
namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;

// use Laminas\Db\Sql\Expression;
class DiscountGroupTableGateway extends AbstractTableGateway
{

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'discount_group';

    public function getCountdown(ServerRequestInterface $request)
    {
        $languageId = $request->getAttribute('language_id', 119);
        $localeId = $request->getAttribute('locale_id', 229);
        $where = new Where();
        $where->equalTo('visible', 1);
        $where->equalTo('language_id', $languageId);
        $where->equalTo('locale_id', $localeId);
        $time = date("Y-m-d H:i:s");
        $where->lessThan('start_stamp', $time);
        $where->greaterThan('end_stamp', $time);
        $select = $this->sql->select();
        $select->order('sort ASC');
        $select->where($where);
        $resultSet = $this->selectWith($select);
        $result = [];
        $discountGroupHasProductsTableGateway = new DiscountGroupHasProductsTableGateway($this->adapter);
        
        
        /**
         * @var \Chopin\LaminasDb\RowGateway\RowGateway $row
         */
        foreach ($resultSet as $row) {
            $where = new Where();
            $where->equalTo('discount_group_id', $row->id);
            $resultSet2 = $discountGroupHasProductsTableGateway->select($where);
            $row->with('products', $resultSet2);
            /*
            $imgPath = './public'.$row->image;
            $imgHeight = 0;
            if(is_file($imgPath) && function_exists('getimagesize')) {
                $imgHeight = getimagesize($imgPath)[1];
            }
            $row->with('imgHeight', $imgHeight);
            */
            $result[] = $row->toArray();
        }
        return $result;
    }
}
