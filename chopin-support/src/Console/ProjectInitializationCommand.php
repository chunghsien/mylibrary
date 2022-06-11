<?php

namespace Chopin\Support\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectInitializationCommand extends Command
{
    protected static $defaultName = 'project-initialization';

    protected function configure()
    {
        $this->setDescription("初始化");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (is_file('./bin/clear-config-cache.replace') && !is_file('./bin/clear-config-cache')) {
            if (copy('./bin/clear-config-cache.replace', './bin/clear-config-cache')) {
                $output->writeln("<info>./bin/clear-config-cache.replace 複製到 ./bin/clear-config-cache 完成</info>");
            } else {
                $output->writeln("<error>./bin/clear-config-cache.replace 複製到 ./bin/clear-config-cache 失敗</error>");
            }
        }

        if (is_file('./config/autoload/system.local.php.dist') && !is_file('./config/autoload/system.local.php')) {
            if (copy('./config/autoload/system.local.php.dist', './config/autoload/system.local.php')) {
                $output->writeln("<info>./config/autoload/system.local.php.dist 複製到 ./config/autoload/system.local.php 完成</info>");
            } else {
                $output->writeln("<error>./config/autoload/system.local.php.dist 複製到 ./config/autoload/system.local.php 失敗</error>");
            }
        }

        if (is_file('./config/autoload/lezada.local.php.dist') && !is_file('./config/autoload/lezada.local.php')) {
            if (copy('./config/autoload/lezada.local.php.dist', './config/autoload/lezada.local.php')) {
                $output->writeln("<info>./config/autoload/lezada.local.php.dist 複製到 ./config/autoload/lezada.local.php 完成</info>");
            } else {
                $output->writeln("<error>./config/autoload/lezada.local.php.dist 複製到 ./config/autoload/system.local.php 失敗</error>");
            }
        }

        if (is_file('./src/app/options/zh_TW_admin_navigation.php.dist') && !is_file('./src/app/options/zh_TW_admin_navigation.php')) {
            if (copy('./src/app/options/zh_TW_admin_navigation.php.dist', './src/app/options/zh_TW_admin_navigation.php')) {
                $output->writeln("<info>./src/app/options/zh_TW_admin_navigation.php.dist 複製到 ./src/app/options/zh_TW_admin_navigation.php 完成</info>");
            } else {
                $output->writeln("<error>./src/app/options/zh_TW_admin_navigation.php.dist 複製到 ./src/app/options/zh_TW_admin_navigation.php 失敗</error>");
            }
        }
        $output->writeln("<info>專案組態初始化完成</info>");
        return 1;
    }
}
