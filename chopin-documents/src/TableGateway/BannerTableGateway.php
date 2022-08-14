<?php

namespace Chopin\Documents\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\I18n\Translator\Translator;
use Psr\Http\Message\ServerRequestInterface;

class BannerTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    protected $types = [
        "banner",
        "carousel",
        "calltoaction"
    ];

    /**
     *
     * @inheritdoc
     */
    protected $table = 'banner';

    /**
     *
     * @var Translator
     */
    protected $translator;

    /**
     *
     * @var ServerRequestInterface
     */
    protected $request;

    public function __construct(\Laminas\Db\Adapter\Adapter $adapter, ServerRequestInterface $request = null)
    {
        parent::__construct($adapter);
        if ($request instanceof ServerRequestInterface) {
            $this->request = $request;
            $locale = $request->getAttribute("php_lang");
            $this->translator = new Translator();
            $this->translator->setLocale($locale);
            $filename = PROJECT_DIR.'/resources/languages/' . $locale . '/chopin-banner.php';
            $this->translator->addTranslationFile("phpArray", $filename, 'chopin-store', $locale);
        }
    }

    /**
     *
     * @deprecated
     * @return string[][]|NULL[][]
     */
    public function getHorizentalAlignOptions()
    {
        $translator = $this->translator;
        return [
            [
                "value" => "left",
                "label" => $translator->translate("left", "chopin-store")
            ],
            [
                "value" => "center",
                "label" => $translator->translate("center", "chopin-store")
            ],
            [
                "value" => "right",
                "label" => $translator->translate("right", "chopin-store")
            ]
        ];
    }

    /**
     *
     * @deprecated
     * @return string[][]|NULL[][]
     */
    public function getVerticalAlignOptions()
    {
        $translator = $this->translator;
        return [
            [
                "value" => "top",
                "label" => $translator->translate("top", "chopin-store")
            ],
            [
                "value" => "middle",
                "label" => $translator->translate("middle", "chopin-store")
            ],
            [
                "value" => "bottom",
                "label" => $translator->translate("bottom", "chopin-store")
            ]
        ];
    }

    /**
     *
     * @param string $index
     * @param string $language_id
     * @param string $locale_id
     * @return array
     */
    public function getCarousell($index, $language_id, $locale_id)
    {
        $documentsTableGateway = new DocumentsTableGateway($this->adapter);
        $bannerHasDocumentsTableGateway = new BannerHasDocumentsTableGateway($this->adapter);
        $select = $this->sql->select();
        $pt = AbstractTableGateway::$prefixTable;
        $select->join("{$bannerHasDocumentsTableGateway->table}", "{$this->table}.id={$bannerHasDocumentsTableGateway->table}.banner_id", []);
        $select->join("{$documentsTableGateway->table}", "{$bannerHasDocumentsTableGateway->table}.documents_id={$documentsTableGateway->table}.id", ["index"]);
        $where = $select->where;
        $where->equalTo("{$documentsTableGateway->table}.language_id", $language_id);
        $where->equalTo("{$documentsTableGateway->table}.locale_id", $locale_id);
        $where->equalTo("{$documentsTableGateway->table}.index", $index);
        $where->equalTo("{$this->table}.is_show", 1);
        $where->isNull("{$this->table}.deleted_at");
        $where->isNull("{$pt}documents.deleted_at");

        $select->where($where);
        $select->order(["sort asc", "{$this->table}.id asc"]);
        $result = $this->selectWith($select)->toArray();
        return $result;
    }
}
