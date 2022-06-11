<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Where;

class ProductsOption2TableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_option2';

    public function buildSearchData(ServerRequestInterface $request)
    {
        $languageId = $request->getAttribute('language_id');
        $localeId = $request->getAttribute('locale_id');
        $where = new Where();
        $where->equalTo("language_id", $languageId);
        $where->equalTo("locale_id", $localeId);
        $where->isNull("deleted_at");
        $where->notEqualTo("name", "none");
        $contents = json_decode($request->getBody()->getContents(), true);
        if (!$contents) {
            $queryParams = $request->getQueryParams();
            if (isset($queryParams["option1"])) {
                $contents["option1"] = json_decode(urldecode($queryParams["option1"]));
            }
        }
        if (isset($contents["option1"]) && count($contents["option1"])) {
            $option1 = $contents["option1"];
            $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
            $productsCombinationResultset= $productsCombinationTableGateway->select(["products_option1_id" => $option1]);
            if ($productsCombinationResultset->count()) {
                $option2 = [];
                foreach ($productsCombinationResultset as $row) {
                    $option2[] = $row->products_option2_id;
                }
                if ($option2) {
                    $option2 = array_unique($option2);
                    $option2 = array_values($option2);
                    $where->in("id", $option2);
                }
                unset($productsCombinationResultset);
            } else {
                unset($productsCombinationResultset);
                return [];
            }
        }
        return $this->select($where)->toArray();
    }
}
