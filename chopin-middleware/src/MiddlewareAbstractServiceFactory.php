<?php
namespace Chopin\Middleware;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use Mezzio\Router\RouterInterface;
use App\Middleware\NotFoundMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Server\MiddlewareInterface;

class MiddlewareAbstractServiceFactory implements AbstractFactoryInterface
{

    /**
     *
     * @var \ReflectionClass
     */
    private $reflection;

    private $isRouteNotPath = false;

    /**
     *
     * {@inheritdoc}
     * @see \Laminas\ServiceManager\Factory\FactoryInterface::__invoke()
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        try {
            if ($this->isRouteNotPath) {
                $template = $container->get(TemplateRendererInterface::class);
                // $adapter = $container->get(Adapter::class);
                return new NotFoundMiddleware($template/*, $adapter*/);
            }
            $reflection = $this->reflection;
            $isConstruct = $reflection->hasMethod('__Construct');
            if ($isConstruct) {
                /**
                 *
                 * @var \ReflectionParameter[] $params
                 */
                $params = $reflection->getMethod('__Construct')->getParameters();

                $args = [];
                foreach ($params as $param) {
                    if (floatval(PHP_VERSION) >= 8) {
                        $id = $param->getType()->__toString();
                        $id = preg_replace('/^\?/', '', $id);
                    } else {
                        if (! $param->getClass()) {
                            return false;
                        }
                        $id = $param->getClass()->name;
                    }

                    if ($container->has($id)) {
                        $arg = $container->get($id);
                        $args[] = $arg;
                    }
                }
                unset($params);
                $middleware = $reflection->newInstanceArgs($args);
            } else {
                $middleware = $reflection->newInstance();
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
            exit();
        }
        return $middleware;
    }

    /**
     *
     * {@inheritdoc}
     * @see \Laminas\ServiceManager\Factory\AbstractFactoryInterface::canCreate()
     */
    public function canCreate(ContainerInterface $container, $requestedName)
    {
        try {
            if (! class_exists($requestedName)) {
                return false;
            }

            $config = $container->get('config');
            $this->reflection = new \ReflectionClass($requestedName);
            $reflection = $this->reflection;
            $interfaceNames = $reflection->getInterfaceNames();
            if (false !== array_search('App\NoMVC\MiddlewareInterface', $interfaceNames, true) || false !== array_search('App\MiddlewareInterface', $interfaceNames, true) || false !== array_search(\Psr\Http\Server\RequestHandlerInterface::class, $interfaceNames, true) || false !== array_search(MiddlewareInterface::class, $interfaceNames, true)) {
                return true;
            }

            $isConstruct = $reflection->hasMethod('__Construct');
            if ($isConstruct) {
                /**
                 *
                 * @var \ReflectionParameter[] $params
                 */
                $params = $reflection->getMethod('__Construct')->getParameters();
                foreach ($params as $param) {
                    if (floatval(PHP_VERSION) >= 8) {
                        $type = $param->getType();
                        return $type instanceof \ReflectionNamedType;
                    }
                    if (! $param->getType()) {
                        return false;
                    }
                }
            }

            if ($container->has('Psr\Http\Message\ServerRequestInterface') && $container->has(RouterInterface::class)) {
                /**
                 *
                 * @var \Mezzio\Router\FastRouteRouter $router
                 */
                $router = $container->get(RouterInterface::class);
                $requestCallback = $container->get('Psr\Http\Message\ServerRequestInterface');

                /**
                 *
                 * @var \Laminas\Diactoros\ServerRequest $request
                 */
                $request = $requestCallback();

                if ($router->match($request)->isSuccess()) {
                    if (class_exists($requestedName)) {
                        // process;
                        $reflection = new \ReflectionClass($requestedName);

                        if ($reflection->implementsInterface('Psr\Http\Server\MiddlewareInterface')) {
                            $find = explode('\\', $requestedName)[0];
                            // strpos($config, explode('\\', $requestedName)[0])
                            if (false !== array_search($find, $config, true)) {
                                return true;
                            }
                        }
                    }
                } else {
                    $this->isRouteNotPath = true;
                    return true;
                }
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
            exit();
        }

        return false;
    }
}
