<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Chopin\SystemSettings\TableGateway\AssetsTableGateway;

class WishlistTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = true;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'wishlist';

    /**
     *
     * @var CartTableGateway
     */
    protected $cartTableGateway;

    public function addWishlist(ServerRequestInterface $request)
    {
        $guest_serial = $this->getGuestSerial($request);
        $serial = $guest_serial['serial'];
        $this->removeExpired($serial);
        $request = $request->withAttribute('methodOrId', $serial);
        $params = $request->getParsedBody();
        if (! $params) {
            $params = json_decode($request->getBody()->getContents(), true);
        }
        $params['guest_serial'] = $serial;
        $where = $this->getSql()->select()->where;
        $where->equalTo('guest_serial', $params['guest_serial']);
        $where->equalTo('products_id', $params['products_id']);
        $message = "";
        if ($this->select($where)->count() == 0) {
            $this->insert([
                "guest_serial" => $serial,
                "products_id" => $params['products_id'],
                "expire" => strtotime("today") + (86400 * 7)
            ]);
            $message = "Added To Wishlist";
        } else {
            $message = "item is added";
        }
        $wishlists = $this->getWishlist($request);
        return [
            'status' => 'success',
            'message' => [
                $message
            ],
            "data" => $wishlists
        ];
    }

    public function getWishlist(ServerRequestInterface $request)
    {
        $guest_serial = $this->getGuestSerial($request)["serial"];
        if (! $guest_serial) {
            $guest_serial = $this->getGuestSerial($request);
        }
        $this->removeExpired($guest_serial);
        $select = $this->sql->select();
        $where = $this->sql->select()->where;
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $select->join($productsTableGateway->table, "{$this->table}.products_id={$productsTableGateway->table}.id", [
            'model'
        ]);
        $where->isNull("{$productsTableGateway->table}.deleted_at");
        $where->equalTo("{$this->table}.guest_serial", $guest_serial);
        $where->equalTo("{$productsTableGateway->table}.is_show", 1);
        $select->where($where);
        $result = $this->selectWith($select)->toArray();
        $assetsTableGateway = new AssetsTableGateway($this->adapter);
        $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        foreach ($result as &$wishListItem) {
            // begin 取得products擁有的combination
            $productsId = $wishListItem["products_id"];
            $productsCombinationSelect = $productsCombinationTableGateway->getSql()->select();
            $productsCombinationWhere = $productsCombinationSelect->where;
            $productsCombinationWhere->equalTo('products_id', $productsId);
            $productsCombinationSelect->where($productsCombinationWhere);
            $productsCombinationResultset = $productsCombinationTableGateway->selectWith($productsCombinationSelect);
            $path = [];
            $priceRange = [];
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
                    }
                }
                unset($assetsResultset);
            }
            unset($productsCombinationResultset);
            if (! $path) {
                $assetsSelect = $assetsTableGateway->getSql()->select();
                $assetsSelect->order([
                    "sort asc",
                    "id asc"
                ]);
                $assetsWhere = $assetsSelect->where;
                $assetsWhere->equalTo("table", "products");
                $assetsWhere->equalTo("table_id", $productsId);
                $assetsSelect->where($assetsWhere);
                $assetsRow = $assetsTableGateway->selectWith($assetsSelect)->current();
                if ($assetsRow) {
                    $path[] = $assetsRow->path;
                } else {
                    $path[] = '/assets/images/product_empty.jpg';
                }
            }
            asort($priceRange);
            if (count($priceRange) > 1) {
                $priceRange = [
                    $priceRange[0],
                    $priceRange[count($priceRange) - 1]
                ];
            }
            $wishListItem["priceRange"] = $priceRange;
            $wishListItem["path"] = $path;
            $wishListItem["nameOptions"] = $productsCombinationTableGateway->OptionsToNameOptions($productsId);
            // end of 取得products擁有的combination
        }
        return [
            "wishList" => $result,
            "guestSerial" => $guest_serial
        ];
    }

    public function assignCartTablegateway(CartTableGateway $tablegateway)
    {
        if (! $this->cartTableGateway instanceof CartTableGateway) {
            $this->cartTableGateway = $tablegateway;
        }
    }

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

    /**
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    public function getGuestSerial(ServerRequestInterface $request)
    {
        if (! $this->cartTableGateway instanceof CartTableGateway) {
            $this->cartTableGateway = new CartTableGateway($this->adapter);
        }
        return $this->cartTableGateway->getGuestSerial($request);
    }
}
