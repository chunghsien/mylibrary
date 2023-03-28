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
     * @param ServerRequestInterface $request
     * @return AbstractPayment
     */
    static public function factory($code, Adapter $adapter, ServerRequestInterface $request): AbstractPayment
    {
        //$class = isset(self::$code[$code]) ? self::$code[$code] : null;
        $class = null;
        if($code) {
            $class = "Chopin\\Store\\Payment\\".ucfirst($code);
            if(class_exists($class)) {
                $reflection = new \ReflectionClass($class);
                return $reflection->newInstance($adapter);
            }else{
                $class = "Chopin\\Store\\Payment\\NotMatchPayment";
                if(class_exists($class)) {
                    $reflection = new \ReflectionClass($class);
                    return $reflection->newInstance($adapter);
                }else{
                    throw new \ErrorException('無對應的類別');
                }
            }
        }
        throw new \ErrorException($class.'類別不存在');
    }
    
}
