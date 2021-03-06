<?php

declare(strict_types=1);

namespace Chopin\View\TwigRender;

use Mezzio\Twig\TwigEnvironmentFactory as MezzioTwigEnvironmentFactory;
use Twig\Environment;
use Psr\Container\ContainerInterface;
use Twig\Lexer;

class TwigEnvironmentFactory extends MezzioTwigEnvironmentFactory
{
    public function __invoke(ContainerInterface $container): Environment
    {
        /**
         *
         * @var \Twig\Environment $environment
         */
        $environment = parent::__invoke($container);
        $environment->addExtension(new \Twig\Extension\StringLoaderExtension());
        $twigConfig = $container->get('config')['twig']/*['lexer']*/;
        $config = [];
        if (isset($twigConfig['lexer'])) {
            $config = $twigConfig['lexer'];
        }
        $lexer = new Lexer($environment, $config);
        $environment->setLexer($lexer);
        return $environment;
    }
}
