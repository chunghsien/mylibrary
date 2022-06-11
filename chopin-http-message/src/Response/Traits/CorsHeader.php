<?php

namespace Chopin\HttpMessage\Response\Traits;

trait CorsHeader
{

    protected function getCorsHeader()
    {
        //Cross-Origin Resource Sharing (CORS)
        if (isset($_SERVER['HTTP_ORIGIN']) && preg_match('/^http\:\/\/localhost(\:\d+)+/', $_SERVER['HTTP_ORIGIN'])) {
            return [
                "Access-Control-Allow-Origin" => "*",
                "Access-Control-Allow-Methods" => ["GET", "PUT", "POST", "DELETE", "OPTIONS"],
                "Access-Control-Allow-Credentials" => 1,
                "Access-Control-Allow-Headers" => "content-type"
            ];
        }
        return [];
    }
}