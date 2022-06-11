<?php

namespace Chopin\Support;

/**
 * @deprecated
 * @desc 目前先允許只使用Youbube，若要使用其他供應商，可利用 self::getProvider提取出供應商
 **      另外編寫程式碼，或繼承本class override self::$allowedProviders
 * @author hsien
 *
 */
abstract class Oembed
{
    // const PROVIDERS_API = "https://oembed.com/providers.json";
    protected static $allowedProviders = [
        "YouTube",
    ];

    /**
     *
     * @var array
     */
    protected static $data = [];

    /**
     *
     * @return array
     */
    protected static function getData()
    {
        if (! self::$data) {
            $providers = file_get_contents(__DIR__.DIRECTORY_SEPARATOR.'oemberProvider.json');
            $providers = json_decode($providers, true);
            self::$data = $providers;
        }
        return self::$data;
    }

    public static function parse(array $item, string $field, $providerName="YouTube")
    {
        if (false !== array_search($providerName, self::$allowedProviders, true)) {
            $data = self::getData();
            $provider = $data[$providerName];
            $endPoint = $provider["endpoints"][0]["url"];
            echo 'https://www.youtube.com/oembed?url=http://www.youtube.com/watch?v=iwGFalTRHDA<br>';
            $content = $item[$field];
            $oembedMatches = [];
            preg_match_all('/<oembed>.*<\/oembed>/', $content, $oembedMatches);
            $oembedMatches = $oembedMatches[0];
            $contents = [];
            foreach ($oembedMatches as $oembedItem) {
                $content = str_replace('<oembed>', '', $oembedItem);
                $content = str_replace('</oembed>', '', $content);
                echo $content;
                exit();
                $api = "{$endPoint}?url=".$content;
                echo $api;
                exit();
                $responseJson = file_get_contents($api);
                $contents[] = json_decode($responseJson, true);
                //debug($endPoint);
            }
            unset($oembedMatches);
            debugJson($contents);
        }
        return [];
        /*
         * debugJson($providers);
         */
        /*
         * $jsonData = file_get_contents('./storage/oembed.json');
         * $data = json_decode($jsonData, true);
         * $resultset = [];
         * foreach ($data as $item) {
         * $provider_name = $item["provider_name"];
         * $resultset[$provider_name] = $item;
         * }
         * debugJson($resultset);
         */

        debug($oembedMatches);
    }

    public static function getProvider(string $name)
    {
        $data = self::getData();
        return $data[$name];
    }
}
