<?php

namespace Chopin\Store\Payment;

use Laminas\Db\Adapter\Adapter;
use Psr\Http\Message\ServerRequestInterface;

abstract class PaymentFactory
{
    /**
     *
     * @param string $code
     * @param Adapter $adapter
     * @return AbstractPayment
     */
    static public function factory($code, Adapter $adapter){
        //$class = isset(self::$code[$code]) ? self::$code[$code] : null;
        $class = null;
        if($code) {
            $class = "Chopin\\Store\\Payment\\".ucfirst($code);
            if(class_exists($class)) {
                $reflection = new \ReflectionClass($class);
                return $reflection->newInstance($adapter);
            }
        }
        if(!$class) {
            throw new \ErrorException('無對應的類別');
        }
        throw new \ErrorException($class.'類別不存在');
        //return false;
    }
    
}
