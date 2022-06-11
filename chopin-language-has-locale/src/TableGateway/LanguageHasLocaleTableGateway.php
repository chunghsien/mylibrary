<?php

namespace Chopin\LanguageHasLocale\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;

class LanguageHasLocaleTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'language_has_locale';

    public function siteDropdownData()
    {
        $select = $this->sql->select();
        $select->columns(["language_id", "locale_id", "code", "display_name"]);
        $where = new Where();
        $where->equalTo("{$this->table}.is_use", 1);
        $where->isNull('deleted_at');
        $select->where($where);
        $languageTableGateway = new LanguageTableGateway($this->adapter);
        $select->join(
            $languageTableGateway->table,
            "{$languageTableGateway->table}.id={$this->table}.language_id",
            ["language_name" => "display_name"]
        );
        return $this->selectWith($select)->toArray();
    }

    public function getItemHasLangAndLocale($language_id, $locale_id)
    {
        $select = $this->sql->select();
        $languageTableGateway = new LanguageTableGateway($this->adapter);
        $localeTableGateway = new LocaleTableGateway($this->adapter);
        $select->join(
            $languageTableGateway->table,
            "{$languageTableGateway->table}.id={$this->table}.language_id",
            ["lang_code" => "code"],
        );
        $select->join(
            $localeTableGateway->table,
            "{$localeTableGateway->table}.id={$this->table}.locale_id",
            ["locale_code" => "code"],
        );
        $where = new Where();
        $where->equalTo("language_id", $language_id);
        $where->equalTo("locale_id", $locale_id);
        $select->where($where);
        return (array)$this->selectWith($select)->current();
    }
}
