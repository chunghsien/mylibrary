<?php

namespace Chopin\LaminasDb\Console\SqlExecute;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Laminas\Db\Adapter\Adapter;

class Execute extends Command
{
    protected static $defaultName = 'sqls:execute';

    protected function configure()
    {
        $this->addOption('fullclass', null, InputOption::VALUE_REQUIRED, '欲執行檔案的完整class(含namespace)');
        $this->setDescription("執行 Laminas\Db\Sql 延伸的執行(insert、update、delete...)");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $container;
        $seedsPaths = $container->get('config')['console']['sql_seeds'];
        foreach ($seedsPaths as $path) {
            $seedDirIterator = new \DirectoryIterator($path);
            foreach ($seedDirIterator as $fileinfo) {
                /**
                 * @var \SplFileInfo $fileinfo
                 */

                if ($fileinfo->getExtension() == 'php') {
                    $pathname = $fileinfo->getPathname();
                    if (is_file($pathname)) {
                        require $pathname;
                    }
                }
            }
            unset($seedDirIterator);
        }
        unset($seedsPaths);
        $options = $input->getOptions();
        $fullclass = $options['fullclass'];

        if (! isset($fullclass) || ! class_exists($fullclass)) {
            $output->writeln("<error>{$fullclass} 不存在</error>");
            return 0;
        }
        try {
            $reflection = new \ReflectionClass($fullclass);
            $adpater = $container->get(Adapter::class);
            $reflection->newInstance($adpater)->run();
            $output->writeln('<info>sql 執行成功</info>');
            return 1;
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            //throw new \ErrorException($e->getMessage());
            return 0;
        }
    }
}
