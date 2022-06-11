<?php

namespace Chopin\Ecpay;

final class Resources
{
    private static function getBasePath()
    {
        return dirname(__DIR__);
    }

    public static function requiureClass($staticPath, $isUseBasePath = true)
    {
        if ($isUseBasePath) {
            if (preg_match('/^\//', $staticPath)) {
                require_once self::getBasePath().$staticPath;
            } else {
                require_once self::getBasePath().'/'.$staticPath;
            }
        } else {
            require_once $staticPath;
        }
    }
}
