<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Where;

class ProductsOption3TableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_option3';

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
        if (! $contents) {
            $queryParams = $request->getQueryParams();
            if (isset($queryParams["option2"])) {
                $contents["option2"] = json_decode(urldecode($queryParams["option2"]));
            }
        }
        if (isset($contents["option2"]) && count($contents["option2"])) {
            $option2 = $contents["option2"];
            $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
            $productsCombinationResultset = $productsCombinationTableGateway->select([
                "products_option2_id" => $option2
            ]);
            if ($productsCombinationResultset->count()) {
                $option3 = [];
                foreach ($productsCombinationResultset as $row) {
                    $option3[] = $row->products_option3_id;
                }
                if ($option3) {
                    $option3 = array_unique($option3);
                    $option3 = array_values($option3);
                    $where->in("id", $option3);
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
