<?php

namespace Chopin\Support\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class RunScriptCommand extends Command
{
    protected static $defaultName = 'run-script';

    protected function configure()
    {
        $this->setDescription("執行自訂腳本");
        $this->addOption('name', null, InputOption::VALUE_OPTIONAL, '腳本名稱');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $options = $input->getOptions();
        $name = $options["name"];
        if (is_file("./bin/php-scripts/{$name}.php")) {
            include_once "./bin/php-scripts/{$name}.php";
        }
        return 1;
    }
}
