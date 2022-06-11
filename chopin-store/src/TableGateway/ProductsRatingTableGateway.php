<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Psr\Http\Message\ServerRequestInterface;
use Chopin\LaminasDb\RowGateway\RowGateway;
use Laminas\Db\Sql\Sql;
use Chopin\LaminasDb\ResultSet\ResultSet;
use Chopin\Store\RowGateway\ProductsRowGateway;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;
use Laminas\Db\Sql\Expression;
use Chopin\Users\TableGateway\MemberTableGateway;

class ProductsRatingTableGateway extends AbstractTableGateway
{
    use SecurityTrait;

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_rating';

    public function randList(ServerRequestInterface $request, $limit = 8)
    {
        $languageId = $request->getAttribute("language_id");
        $localeId = $request->getAttribute("locale_id");
        $select = $this->sql->select();
        $select->order("{$this->table}.id DESC");
        $where = new Where();
        $where->isNull("{$this->table}.deleted_at");
        $pt = AbstractTableGateway::$prefixTable;
        $aes_key = config('encryption.aes_key');
        $memberTableGateway = new MemberTableGateway($this->adapter);
        $select->join("{$pt}member", "{$this->table}.member_id={$pt}member.id", [
            "language_id",
            "locale_id",
            "full_name" => new Expression("CAST(AES_DECRYPT(`{$memberTableGateway->table}`.`full_name`, ?) AS CHAR)", [
                $aes_key
            ])
        ]);
        // new Expression("CAST(AES_DECRYPT(`{$PT}member`.`email`, ?) AS CHAR)", [$aes_key])
        $where->equalTo("{$pt}member.language_id", $languageId);
        $where->equalTo("{$pt}member.locale_id", $localeId);
        $select->where($where);
        $resultset = $this->selectWith($select);
        $result = [];
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        foreach ($resultset as $row) {
            if ($row->language_id == 119) {
                $row->full_name = nameMask($row->full_name, 'chName');
            }

            $select = $productsTableGateway->getSql()->select();
            $select->columns([
                "id",
                "model"
            ]);
            $select->where([
                "id" => $row->products_id
            ]);
            /**
             *
             * @var ProductsRowGateway $productRow
             */
            $productRow = $productsTableGateway->selectWith($select)->current();
            if ($productRow) {
                // $productRow->withAssets();
                if (isset($row->products_combination_id)) {
                    $productRow->withCombinationOptions();
                }
                $row->with("product", $productRow->toArray());
            }
            $result[] = $row->toArray();
        }
        unset($resultset);
        return $result;
    }

    public function withProductList($productsId)
    {
        $select = $this->sql->select();
        $select->order("{$this->table}.id DESC");
        $where = new Where();
        $where->equalTo("{$this->table}.products_id", $productsId);
        $where->isNull("{$this->table}.deleted_at");
        $pt = AbstractTableGateway::$prefixTable;
        $select->join("{$pt}member", "{$this->table}.member_id={$pt}member.id", [
            "full_name"
        ]);
        $select->where($where);
        $result = $this->selectWith($select)->toArray();
        foreach ($result as &$item) {
            $fullName = $this->deCryptData($item["full_name"]);
            if ($fullName) {
                $fullName = nameMask($fullName, "chName");
                $item["full_name"] = $fullName;
            }
        }
        return $result;
    }
}
