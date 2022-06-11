<?php

namespace Chopin\LanguageHasLocale\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

class I18nTranslationTableGateway extends AbstractTableGateway
{
    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'i18n_translation';

    public function toTranslatorArr($module, $language_id, $locale_id)
    {
        $resultSet = $this->select([
            "module" => $module,
            "language_id" => $language_id,
            "locale_id" => $locale_id,
        ]);
        $message = [];
        foreach ($resultSet as $row) {
            $key = $row->key;
            $value = $row->value;
            $message[$key] = $value;
        }
        unset($resultSet);
        return $message;
    }
}
