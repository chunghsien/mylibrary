<?php

namespace Chopin\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpgrade extends Command
{
    protected static $defaultName = 'self-upgrade';

    protected function configure()
    {
        $this->setDescription("開發階段時從smith.php 更新至 smith");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            if (copy('./smith.dev.phtml', './smith')) {
                $output->writeln("<info>smith 腳本更新成功</info>");
                return 1;
            } else {
                $output->writeln("<error>smith 腳本更新失敗</error>");
                return 0;
            }
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return 0;
        }
    }
}
