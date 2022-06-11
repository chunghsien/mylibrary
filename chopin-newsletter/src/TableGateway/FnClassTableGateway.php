<?php

namespace Chopin\Newsletter\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Psr\Http\Message\ServerRequestInterface;

class FnClassTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'fn_class';

    /**
     *
     * @param ServerRequestInterface $request
     * @param bool $isContinueFind
     * @return array
     */
    public function getNavOptions(ServerRequestInterface $request, $isContinueFind)
    {
        $language_id = $request->getAttribute("language_id");
        $locale_id = $request->getAttribute("locale_id");
        $where = $this->sql->select()->where;
        $where->equalTo("language_id", $language_id);
        $where->equalTo("locale_id", $locale_id);
        $result = $this->select($where);
        if ($result->count() == 0) {
            return [];
        }
        $result = $result->toArray();
        if (count($result) == 0 || !$isContinueFind) {
            return $result;
        }
        $mnClassTableGateway = new MnClassTableGateway($this->adapter);
        foreach ($result as &$item) {
            $parent_id = $item["id"];
            $item["childs"] = $mnClassTableGateway->getNavFromParent($parent_id);
        }
        return $result;
    }
}
