<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Where;
use Chopin\Store\RowGateway\ProductsRowGateway;
use Chopin\SystemSettings\TableGateway\AssetsTableGateway;

class CompareTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = true;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'compare';

    /**
     *
     * @var CartTableGateway
     */
    private $cartTableGateway;

    /**
     *
     * @return number
     */
    public function removeExpired($guest_serial = null)
    {
        $delete = $this->getSql()->delete();
        $predicate = $delete->where;
        $predicate->lessThan('expire', strtotime('now'));
        if ($guest_serial) {
            $predicate->equalTo('guest_serial', $guest_serial);
        }
        $delete->where($predicate);
        return $this->deleteWith($delete);
    }

    private function initCartTableGateway()
    {
        if (! $this->cartTableGateway instanceof CartTableGateway) {
            $this->cartTableGateway = new CartTableGateway($this->adapter);
        }
    }
    public function addCompare(ServerRequestInterface $request)
    {
        $guest_serial = $this->getGuestSerial($request);
        $serial = $guest_serial['serial'];
        $this->removeExpired($serial);
        $request = $request->withAttribute('methodOrId', $serial);
        $params = $request->getParsedBody();
        if (!$params) {
            $params = json_decode($request->getBody()->getContents(), true);
        }
        $where = $this->getSql()->select()->where;
        $where->equalTo('guest_serial', $serial);
        $where->equalTo('products_id', $params['products_id']);
        $message = "";
        if ($this->select($where)->count() == 0) {
            $this->insert([
                "guest_serial" => $serial,
                "products_id" => $params['products_id'],
                "expire" => strtotime("today") + (86400 * 7)
            ]);
            $message = "Added To Compare";
        } else {
            $message = "item is added";
        }
        $compares = $this->getCompare($request);
        return [
            'status' => 'success',
            'message' => [
                $message
            ],
            "data" => $compares
        ];
    }

    public function getCompare(ServerRequestInterface $request)
    {
        $guestSerial = $this->getGuestSerial($request);
        $guest_serial = $guestSerial["serial"];
        $this->removeExpired($guest_serial);
        $where = new Where();
        $where->equalTo("guest_serial", $guest_serial);
        $comppareResult = $this->select($where)->toArray();
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $result = [];
        $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
        $assetsTableGateway = new AssetsTableGateway($this->adapter);
        foreach ($comppareResult as $compareItem) {
            $productsId = $compareItem["products_id"];

            $productsSelect = $productsTableGateway->getSql()->select();
            $productsSelect->columns(["id", "model", "real_price"]);
            $productWhere = new Where();
            $productWhere->equalTo("id", $productsId);
            $productWhere->equalTo("is_show", 1);
            $productWhere->isNull("deleted_at");
            $productsSelect->where($productWhere);
            /**
             * @var ProductsRowGateway $productsRowGateway
             */
            $productsRowGateway = $productsTableGateway->selectWith($productsSelect)->current();
            if ($productsRowGateway) {
                $productsRowGateway->withDiscount($productsRowGateway->id);
                $productsRowGateway->withDiscount($productsRowGateway->id);
                $optionsToNameOptions = $productsCombinationTableGateway->OptionsToNameOptions($productsRowGateway->id);
                //debug($optionsToNameOptions);
                $productsRowGateway->with('nameOptions', $optionsToNameOptions);

                $productsCombinationResultset = $productsCombinationTableGateway->select(["products_id" => $productsRowGateway->id]);
                $priceRange = [];
                $path = [];
                foreach ($productsCombinationResultset as $combinationRow) {
                    $priceRange[] = floatval($combinationRow->real_price);
                    $table = $productsTableGateway->getTailTableName();
                    $table_id = $combinationRow->products_id;
                    $assetsSelect = $assetsTableGateway->getSql()->select();
                    $assetsWhere = $assetsSelect->where;
                    $assetsWhere->equalTo('table', $table);
                    $assetsWhere->equalTo('table_id', $table_id);
                    $assetsSelect->order([
                        "sort asc",
                        "id asc"
                    ]);
                    $assetsSelect->limit(1);
                    $assetsSelect->where($assetsWhere);
                    $assetsResultset = $assetsTableGateway->selectWith($assetsSelect);
                    if ($assetsResultset->count() > 0) {
                        foreach ($assetsResultset as $assetsRow) {
                            $path[] = $assetsRow->path;
                            break;
                        }
                    }
                    if (!$path) {
                        $path[] = '/assets/images/product_empty.jpg';
                    }
                    asort($priceRange);
                    if (count($priceRange) > 1) {
                        $priceRange = [
                            $priceRange[0],
                            $priceRange[count($priceRange) - 1]
                        ];
                    }
                    unset($assetsResultset);
                }
                $productsRowGateway->with('path', $path);
                $productsRowGateway->with("priceRange", $priceRange);
                $compareItem['product'] = $productsRowGateway->toArray();
                $result[] = (array)$compareItem;
            }
        }
        unset($comppareResult);
        return [
            "compares" => $result,
            "guestSerial" => $guest_serial,
        ];
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @return array|mixed|number[]|mixed[]
     */
    public function getGuestSerial(ServerRequestInterface $request)
    {
        $this->initCartTableGateway();
        return $this->cartTableGateway->getGuestSerial($request);
    }
}
