<?php

namespace Chopin\Support\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class SiteViewChangeCommand extends Command
{
    protected static $defaultName = 'view-type';

    protected function configure()
    {
        $this->setDescription("更改前端樣板的編譯狀態")->addOption('type', null, InputOption::VALUE_REQUIRED, 'react 或 twig');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $type = strtolower(trim($input->getOption('type')));
        if (strlen($type) == 0) {
            $output->writeln('<error>請輸入 --type={react|twig}</error>');
            return 0;
        }
        if ($type != 'react' && $type != 'twig') {
            $output->writeln('<error>不支援這個type</error>');
            return 0;
        }
        $constent = file_get_contents('./package.json');
        $constent = str_replace(['sview=twig', 'sview=react'], "sview={$type}", $constent);
        if (file_put_contents('./package.json', $constent)) {
            $output->writeln('<info>./package.json更改成功</info>');
        } else {
            $output->writeln('<error>./package.json更改失敗</error>');
        }
        $constent = file_get_contents('./config/autoload/template.global.php');
        $replace = sprintf('"sview" => "%s"', $type);
        $search = ['"sview" => "react"', '"sview" => "twig"'];
        $constent = str_replace($search, $replace, $constent);
        if (file_put_contents('./config/autoload/template.global.php', $constent)) {
            $output->writeln('<info>./config/autoload/template.global.php更改成功</info>');
        } else {
            $output->writeln('<error>./config/autoload/template.global.php更改失敗</error>');
        }
        return 1;
    }
}
