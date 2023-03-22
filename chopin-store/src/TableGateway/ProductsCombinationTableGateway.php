<?php
namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Chopin\Store\RowGateway\ProductsRowGateway;
use Laminas\Db\Sql\Join;
use Laminas\Db\RowGateway\RowGatewayInterface;
use Chopin\LanguageHasLocale\TableGateway\LanguageHasLocaleTableGateway;
use Chopin\LaminasDb\RowGateway\RowGateway;
use Chopin\SystemSettings\TableGateway\AssetsTableGateway;

class ProductsCombinationTableGateway extends AbstractTableGateway
{

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'products_combination';

    /**
     *
     * @param int $id
     * @return RowGatewayInterface
     */
    public function getLocaleRow($id)
    {
        $select = $this->sql->select();
        $select->columns([
            "id"
        ]);
        $where = new Where();
        $where->equalTo("{$this->table}.id", $id);
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $select->join($productsTableGateway->table, "{$productsTableGateway->table}.id={$this->table}.products_id", [
            "language_id",
            "locale_id"
        ]);
        $select->where($where);
        $row = $this->selectWith($select)->current();
        return $row;
    }

    /**
     *
     * @param int $productsId
     * @return []
     */
    public function hierarchyFormProduct($productsId)
    {
        $lists = $this->getListUseProductsId($productsId);
        $optionsHierarchy = [];
        for ($i = 1; $i < 6; $i ++) {
            foreach ($lists as $item) {
                if ($i == 1) {
                    $set = [
                        "products_option1_id" => $item["products_option1_id"],
                        "name" => $item["option1"],
                        "is_color_use" => $item["option1_is_color_use"],
                        "color_code" => $item["option1_color_code"],
                        "option_image" => $item["option1_image"],
                        "children" => []
                    ];
                    if (false === array_search($set, $optionsHierarchy, true)) {
                        $optionsHierarchy[] = $set;
                    }
                }
                if ($i == 2) {
                    foreach ($optionsHierarchy as $key => $option) {
                        if ($item["products_option1_id"] == $option["products_option1_id"]) {
                            $set = [
                                "products_option1_id" => $item["products_option1_id"],
                                "products_option2_id" => $item["products_option2_id"],
                                "name" => $item["option2"],
                                "is_color_use" => $item["option2_is_color_use"],
                                "color_code" => $item["option2_color_code"],
                                "option_image" => $item["option2_image"],
                                "children" => []
                            ];
                            if ($item["products_option3_id"] == 0) {
                                unset($set["children"]);
                                $set["id"] = $item["id"];
                                $set["products_option3_id"] = 0;
                                $set["products_option4_id"] = 0;
                                $set["products_option5_id"] = 0;
                                $set["safety_stock"] = $item["safety_stock"];
                                $set["stock"] = $item["stock"];
                                $set["stock_status"] = $item["stock_status"];
                                $set["price"] = $item["price"];
                                $set["real_price"] = $item["real_price"];
                                $set["discount"] = $item["discount"];
                                $set["image"] = $item["image"];
                            }
                            if (false === array_search($set, $optionsHierarchy[$key]["children"], true)) {
                                $optionsHierarchy[$key]["children"][] = $set;
                            }
                        }
                    }
                }
                if ($i == 3) {
                    if ($item["products_option3_id"] > 0) {
                        foreach ($optionsHierarchy as $key => $option) {
                            foreach ($option["children"] as $lv2Key => $level2Option) {
                                if ($item["products_option1_id"] == $level2Option["products_option1_id"] && $item["products_option2_id"] == $level2Option["products_option2_id"]) {
                                    $set = [
                                        "products_option1_id" => $item["products_option1_id"],
                                        "products_option2_id" => $item["products_option2_id"],
                                        "products_option3_id" => $item["products_option3_id"],
                                        "name" => $item["option3"],
                                        "is_color_use" => $item["option3_is_color_use"],
                                        "color_code" => $item["option3_color_code"],
                                        "option_image" => $item["option3_image"],
                                        "children" => []
                                    ];
                                    if ($item["products_option4_id"] == 0) {
                                        unset($set["children"]);
                                        $set["id"] = $item["id"];
                                        $set["products_option4_id"] = 0;
                                        $set["products_option5_id"] = 0;
                                        $set["safety_stock"] = $item["safety_stock"];
                                        $set["stock"] = $item["stock"];
                                        $set["stock_status"] = $item["stock_status"];
                                        $set["price"] = $item["price"];
                                        $set["real_price"] = $item["real_price"];
                                        $set["discount"] = $item["discount"];
                                        $set["image"] = $item["image"];
                                    }
                                    if (false === array_search($set, $optionsHierarchy[$key]["children"][$lv2Key]["children"], true)) {
                                        $optionsHierarchy[$key]["children"][$lv2Key]["children"][] = $set;
                                    }
                                }
                            }
                        }
                    }
                }
                if ($i == 4) {
                    if ($item["products_option4_id"] > 0) {
                        foreach ($optionsHierarchy as $key => $option) {
                            foreach ($option["children"] as $lv2Key => $level2Option) {
                                if (isset($level2Option["children"])) {
                                    foreach ($level2Option["children"] as $lv3Key => $level3Option) {
                                        if ($item["products_option1_id"] == $level3Option["products_option1_id"] && $item["products_option2_id"] == $level3Option["products_option2_id"] && $item["products_option3_id"] == $level3Option["products_option3_id"]) {
                                            $set = [
                                                "products_option1_id" => $item["products_option1_id"],
                                                "products_option2_id" => $item["products_option2_id"],
                                                "products_option3_id" => $item["products_option3_id"],
                                                "products_option4_id" => $item["products_option4_id"],
                                                "name" => $item["option4"],
                                                "is_color_use" => $item["option4_is_color_use"],
                                                "color_code" => $item["option4_color_code"],
                                                "option_image" => $item["option4_image"],
                                                "children" => []
                                            ];
                                            if ($item["products_option5_id"] == 0) {
                                                unset($set["children"]);
                                                $set["id"] = $item["id"];
                                                $set["products_option5_id"] = 0;
                                                $set["safety_stock"] = $item["safety_stock"];
                                                $set["stock"] = $item["stock"];
                                                $set["stock_status"] = $item["stock_status"];
                                                $set["price"] = $item["price"];
                                                $set["real_price"] = $item["real_price"];
                                                $set["discount"] = $item["discount"];
                                                $set["image"] = $item["image"];
                                            }
                                            if (false === array_search($set, $optionsHierarchy[$key]["children"][$lv2Key]["children"][$lv3Key]["children"], true)) {
                                                $optionsHierarchy[$key]["children"][$lv2Key]["children"][$lv3Key]["children"][] = $set;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                if ($i == 5) {
                    if ($item["products_option5_id"] > 0) {
                        foreach ($optionsHierarchy as $key => $option) {
                            foreach ($option["children"] as $lv2Key => $level2Option) {
                                if (isset($level2Option["children"])) {
                                    foreach ($level2Option["children"] as $lv3Key => $level3Option) {
                                        if (isset($level3Option["children"])) {
                                            foreach ($level3Option["children"] as $lv4Key => $level4Option) {
                                                if ($item["products_option1_id"] == $level4Option["products_option1_id"] && $item["products_option2_id"] == $level4Option["products_option2_id"] && $item["products_option3_id"] == $level4Option["products_option3_id"] && $item["products_option4_id"] == $level4Option["products_option4_id"]) {
                                                    $set = [
                                                        "id" => $item["id"],
                                                        "products_option1_id" => $item["products_option1_id"],
                                                        "products_option2_id" => $item["products_option2_id"],
                                                        "products_option3_id" => $item["products_option3_id"],
                                                        "products_option4_id" => $item["products_option4_id"],
                                                        "products_option5_id" => $item["products_option5_id"],
                                                        "name" => $item["option5"],
                                                        "is_color_use" => $item["option5_is_color_use"],
                                                        "color_code" => $item["option5_color_code"],
                                                        "option_image" => $item["option5_image"],
                                                        "image" => $item["image"],
                                                        "real_price" => $item["real_price"],
                                                        "discount" => $item["discount"]
                                                    ];
                                                    if (false === array_search($set, $optionsHierarchy[$key]["children"][$lv2Key]["children"][$lv3Key]["children"][$lv4Key]["children"], true)) {
                                                        $set["id"] = $item["id"];
                                                        $optionsHierarchy[$key]["children"][$lv2Key]["children"][$lv3Key]["children"][$lv4Key]["children"][] = $set;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        unset($lists);
        return $optionsHierarchy;
    }

    /**
     *
     * @param int $id
     * @return array
     */
    public function getItem($id)
    {
        $select = $this->sql->select();
        $where = new Where();
        $where->equalTo("{$this->table}.id", $id);
        $select->where($where);
        /**
         *
         * @var ProductsRowGateway $row
         */
        $row = $this->selectWith($select)->current();
        $products_id = $row->products_id;
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $productsRow = $productsTableGateway->select([
            "id" => $products_id
        ])->current();
        $row->with('language_id', $productsRow->language_id);
        $row->with('locale_id', $productsRow->locale_id);
        $row->with('language_has_locale', json_encode([
            "locale_id" => $productsRow->locale_id,
            "language_id" => $productsRow->language_id
        ]));
        return $row->toArray();
    }

    public function getOptionsContainer($language_id, $locale_id, $productInclude = false)
    {
        return [
            "products" => $productInclude ? $this->getProductsOptions($language_id, $locale_id) : [],
            "option1" => $this->getOption1Options($language_id, $locale_id),
            "option2" => $this->getOption2Options($language_id, $locale_id),
            "option3" => $this->getOption3Options($language_id, $locale_id),
            "option4" => $this->getOption4Options($language_id, $locale_id),
            "option5" => $this->getOption5Options($language_id, $locale_id)
        ];
    }

    protected function getProductsOptions($language_id, $locale_id)
    {
        $tableGateway = new ProductsTableGateway($this->adapter);
        return $tableGateway->getOptions('id', 'model');
    }

    public function withAssets(RowGateway $combinationRow, $mime = 'image')
    {
        $assetsTableGateway = new AssetsTableGateway($this->adapter);
        $where = new Where();
        $where->equalTo('table', $this->getTailTableName());
        $where->equalTo('table_id', $combinationRow->id);
        $where->like('mime', "{$mime}%");
        $resultset = $assetsTableGateway->select($where);
        $result = [];
        foreach ($resultset as $row) {
            $result[] = $row->path;
        }
        unset($resultset);
        $combinationRow->with('image', $result);
        return $combinationRow;
    }

    /**
     *
     * @param AbstractTableGateway $tableGateway
     * @param int $language_id
     * @param int $locale_id
     * @return array
     */
    private function getOptionsCommon($tableGateway, $language_id, $locale_id)
    {
        $select = $tableGateway->getSql()->select();
        $where = new Where();
        $where->isNull('deleted_at');
        $where->equalTo('language_id', $language_id);
        $where->equalTo('locale_id', $locale_id);
        $select->columns([
            'id',
            'name'
        ]);
        $select->where($where);
        $resultset = $tableGateway->selectWith($select);
        $options = [];
        foreach ($resultset as $row) {
            $options[] = [
                "value" => $row->id,
                "label" => $row->name
            ];
        }
        unset($resultset);
        if (! $options) {
            return [
                [
                    "value" => 0,
                    "label" => ""
                ]
            ];
        }
        return $options;
    }

    protected function getOption1Options($language_id, $locale_id)
    {
        $tableGateway = new ProductsOption1TableGateway($this->adapter);
        return $this->getOptionsCommon($tableGateway, $language_id, $locale_id);
    }

    protected function getOption2Options($language_id, $locale_id)
    {
        $tableGateway = new ProductsOption2TableGateway($this->adapter);
        return $this->getOptionsCommon($tableGateway, $language_id, $locale_id);
    }

    protected function getOption3Options($language_id, $locale_id)
    {
        $tableGateway = new ProductsOption3TableGateway($this->adapter);
        return $this->getOptionsCommon($tableGateway, $language_id, $locale_id);
    }

    protected function getOption4Options($language_id, $locale_id)
    {
        $tableGateway = new ProductsOption4TableGateway($this->adapter);
        return $this->getOptionsCommon($tableGateway, $language_id, $locale_id);
    }

    protected function getOption5Options($language_id, $locale_id)
    {
        $tableGateway = new ProductsOption5TableGateway($this->adapter);
        return $this->getOptionsCommon($tableGateway, $language_id, $locale_id);
    }

    /**
     *
     * @param RowGatewayInterface $row
     * @return \Laminas\Db\RowGateway\RowGatewayInterface
     */
    public function withOptionsAndProduct(RowGatewayInterface $row)
    {
        $select = $this->sql->select();
        $select->columns([
            'id'
        ]);
        $where = new Where();
        $where->equalTo("{$this->table}.id", $row->id);
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $where->isNull("{$productsTableGateway->table}.deleted_at");
        $select->join($productsTableGateway->table, "{$productsTableGateway->table}.id={$this->table}.products_id", [
            "language_id",
            "locale_id",
            "model"
        ]);
        $select->where($where);
        for ($i = 1; $i < 6; $i ++) {
            $classname = __NAMESPACE__ . "\ProductsOption{$i}TableGateway";
            $reflection = new \ReflectionClass($classname);
            /**
             *
             * @var AbstractTableGateway $tablegatewayInstance
             */
            $tablegatewayInstance = $reflection->newInstance($this->adapter);
            $column = "option{$i}";
            $select->join($tablegatewayInstance->table, "{$tablegatewayInstance->table}.id={$this->table}.products_option{$i}_id", [
                $column => "name"
            ], Join::JOIN_LEFT);
        }
        // logger()->debug($this->sql->buildSqlString($select));
        $withRow = $this->selectWith($select)->current();
        if (! $withRow) {
            $select->reset('joins');
            $select->join($productsTableGateway->table, "{$productsTableGateway->table}.id={$this->table}.products_id", [
                "language_id",
                "locale_id",
                "model"
            ]);
            $withRow = $this->selectWith($select)->current();
        }
        if ($withRow) {
            $languageHasLocaleTableGateway = new LanguageHasLocaleTableGateway($this->adapter);
            $languageHasLocaleRow = $languageHasLocaleTableGateway->select([
                "language_id" => $withRow->language_id,
                "locale_id" => $withRow->locale_id
            ])->current();
            $row->with('language_has_locale_name', $languageHasLocaleRow->display_name);
            $row->with('option1', $withRow->option1);
            $row->with('option2', $withRow->option2);
            $row->with('option3', $withRow->option3);
            $row->with('option4', $withRow->option4);
            $row->with('option5', $withRow->option5);
            $row->with('model', $withRow->model);
        }

        return $row;
    }

    public function getListUseProductsId($productsId)
    {
        $where = new Where();
        $where->equalTo("{$this->table}.products_id", $productsId);
        $select = $this->sql->select();
        $productsTableGateway = new ProductsTableGateway($this->adapter);
        $productsOption1TableGateway = new ProductsOption1TableGateway($this->adapter);
        $productsOption2TableGateway = new ProductsOption2TableGateway($this->adapter);
        $productsOption3TableGateway = new ProductsOption3TableGateway($this->adapter);
        $productsOption4TableGateway = new ProductsOption4TableGateway($this->adapter);
        $productsOption5TableGateway = new ProductsOption5TableGateway($this->adapter);
        $select->join($productsTableGateway->table, "{$this->table}.products_id={$productsTableGateway->table}.id", [
            "model"
        ]);
        $select->order([
            "{$this->table}.products_option1_id ASC",
            "{$this->table}.products_option2_id ASC",
            "{$this->table}.products_option3_id ASC",
            "{$this->table}.products_option4_id ASC",
            "{$this->table}.products_option5_id ASC"
        ]);
        $select->join($productsOption1TableGateway->table, "{$this->table}.products_option1_id={$productsOption1TableGateway->table}.id", [
            "option1" => "name",
            'option1_image' => 'image',
            'option1_is_color_use' => 'is_color_use',
            'option1_color_code' => 'color_code'
        ], Join::JOIN_LEFT);
        $select->join($productsOption2TableGateway->table, "{$this->table}.products_option2_id={$productsOption2TableGateway->table}.id", [
            "option2" => "name",
            'option2_image' => 'image',
            'option2_is_color_use' => 'is_color_use',
            'option2_color_code' => 'color_code'
        ], Join::JOIN_LEFT);
        $select->join($productsOption3TableGateway->table, "{$this->table}.products_option3_id={$productsOption3TableGateway->table}.id", [
            "option3" => "name",
            'option3_image' => 'image',
            'option3_is_color_use' => 'is_color_use',
            'option3_color_code' => 'color_code'
        ], Join::JOIN_LEFT);
        $select->join($productsOption4TableGateway->table, "{$this->table}.products_option4_id={$productsOption4TableGateway->table}.id", [
            "option4" => "name",
            'option4_image' => 'image',
            'option4_is_color_use' => 'is_color_use',
            'option4_color_code' => 'color_code'
        ], Join::JOIN_LEFT);
        $select->join($productsOption5TableGateway->table, "{$this->table}.products_option5_id={$productsOption5TableGateway->table}.id", [
            "option5" => "name",
            'option5_image' => 'image',
            'option5_is_color_use' => 'is_color_use',
            'option5_color_code' => 'color_code'
        ], Join::JOIN_LEFT);
        $select->where($where);
        $result = [];
        $resultset = $this->selectWith($select);
        $discountGroupTableGateway = new DiscountGroupTableGateway($this->adapter);
        
        foreach ($resultset as $row) {
            /**
             *
             * @var RowGateway $row
             */
            $row = $discountGroupTableGateway->getRowUseCombinationRow($row);
            $row = $this->withAssets($row);
            $result[] = $row->toArray();
        }
        unset($resultset);
        return $result;
    }

    /**
     *
     * @param integer $productsId
     * @param array $combinationOptions
     * @return []
     */
    public function OptionsToNameOptions($productsId, $combinationOptions = null)
    {
        if (! $combinationOptions) {
            $combinationOptions = $this->getListUseProductsId($productsId);
        }
        $option1Names = [];
        $option2Names = [];
        $option3Names = [];
        $option4Names = [];
        $option5Names = [];
        foreach ($combinationOptions as $combination) {
            if ($combination["option1"]) {
                $option1Names[] = $combination["option1"];
            }
            if ($combination["option2"]) {
                $option2Names[] = $combination["option2"];
            }
            if ($combination["option3"]) {
                $option3Names[] = $combination["option3"];
            }
            if ($combination["option4"]) {
                $option4Names[] = $combination["option4"];
            }
            if ($combination["option5"]) {
                $option5Names[] = $combination["option5"];
            }
        }
        unset($combinationOptions);
        $option1Names = array_unique($option1Names);
        $option2Names = array_unique($option2Names);
        $option3Names = array_unique($option3Names);
        $option4Names = array_unique($option4Names);
        $option5Names = array_unique($option5Names);
        $vars = [];
        $vars["option1Names"] = array_values($option1Names);
        $vars["option2Names"] = array_values($option2Names);
        $vars["option3Names"] = array_values($option3Names);
        $vars["option4Names"] = array_values($option4Names);
        $vars["option5Names"] = array_values($option5Names);
        return $vars;
    }
}
