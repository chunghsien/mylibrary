<?php

namespace Chopin\Tcat;

abstract class Tools {

    static public function outputParamsToJson() {
        $example = require __DIR__.'/config/example.php';
        return json_encode($example, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    }
}