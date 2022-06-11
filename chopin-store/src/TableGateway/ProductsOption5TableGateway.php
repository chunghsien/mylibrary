<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Db\Sql\Where;

class ProductsOption5TableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_option5';

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
            if (isset($queryParams["option4"])) {
                $contents["option4"] = json_decode(urldecode($queryParams["option4"]));
            }
        }
        if (isset($contents["option4"]) && count($contents["option4"])) {
            $option4 = $contents["option4"];
            $productsCombinationTableGateway = new ProductsCombinationTableGateway($this->adapter);
            $productsCombinationResultset = $productsCombinationTableGateway->select([
                "products_option4_id" => $option4
            ]);
            if ($productsCombinationResultset->count()) {
                $option5 = [];
                foreach ($productsCombinationResultset as $row) {
                    $option5[] = $row->products_option5_id;
                }
                if ($option5) {
                    $option5 = array_unique($option5);
                    $option5 = array_values($option5);
                    $where->in("id", $option5);
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
