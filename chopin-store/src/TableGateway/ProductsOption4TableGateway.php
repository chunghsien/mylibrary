<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Where;

class ProductsOption4TableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_option4';

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
            if (isset($queryParams["option3"])) {
                $contents["option3"] = json_decode(urldecode($queryParams["option3"]));
            }
        }

        if (isset($contents["options3"]) && count($contents["options3"])) {
            $option3 = $contents["options3"];
            $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
            $productsCombinationResultset = $productsCombinationTableGateway->select([
                "products_option3_id" => $option3
            ]);
            if ($productsCombinationResultset->count()) {
                $option4 = [];
                foreach ($productsCombinationResultset as $row) {
                    $option4[] = $row->products_option4_id;
                }
                if ($option4) {
                    $option4 = array_unique($option4);
                    $option4 = array_values($option4);
                    $where->in("id", $option4);
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
