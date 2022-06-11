<?php

namespace Chopin\LaminasServiceManager;

use Interop\Container\ContainerInterface;

abstract class AbstractServiceFactory
{
    protected function buildTargetObject(ContainerInterface $container, $requestedName)
    {
        $reflection = new \ReflectionClass($requestedName);
        $constructor = $reflection->getConstructor();
        $args = [];
        if ($reflection->hasMethod('__Construct')) {
            $construct = $reflection->getMethod('__Construct');
            $params = $construct->getParameters();
            foreach ($params as $param) {
                if(floatval(PHP_VERSION) >= 8) {
                    $id = $param->getType()->__toString();
                    $args[] = $container->get($id);
                }else {
                    /**
                     *
                     * @var \ReflectionParameter $param
                     */
                    if (! $param->getClass()) {
                        if ($param->name == 'config' && is_string($param->getDefaultValue())) {
                            $key = $param->getDefaultValue();
                            $args[] = json_encode($container->get('config')[$key]);
                            continue;
                        } else {
                            return false;
                        }
                    } else {
                        $id = $param->getClass()->name;
                        $args[] = $container->get($id);
                    }
                }
            }
            unset($params);
        }
        return $reflection->newInstanceArgs($args);
    }
}
