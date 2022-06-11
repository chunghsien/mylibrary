<?php
/**
 * @desc 中文假資料產生
 */

namespace Chopin\Support;

use GuzzleHttp\Client;

abstract class TC_LoremIpsum
{
    public static function buildContent($n=1, $limit=null)
    {
        $query = [ "n" => $n ];
        if (! is_null($limit)) {
            if (is_array($limit)) {
                $limit = $limit[0] . "," . $limit[1];
            }
            $query["limit"] = $limit;
        }
        $client = new Client();
        $res = $client->get("http://more.handlino.com/sentences.json", [
            "query" => $query
        ]);
        if ($res->getStatusCode() !== 200) {
            throw new \Exception($res->getStatusCode());
        }
        $result = json_decode($res->getBody());
        return $result->sentences;
    }
}
