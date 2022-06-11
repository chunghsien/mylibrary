<?php
namespace Chopin\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NextJsDispatch extends Command
{

    protected static $defaultName = 'next-js-dispatch';

    protected function configure()
    {
        $this->setDescription("佈署Next.js至後端 (已棄用)");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            moveFolder('./storage/out/_next', './public/_next');
            $level1Htmls = glob('./storage/out/*.html');
            foreach ($level1Htmls as $oldname) {
                $newname = str_replace('storage/out', 'public/page', $oldname);
                $folder = dirname($newname);
                if (! is_dir($folder)) {
                    mkdir($folder, 0755, true);
                }
                rename($oldname, $newname);
            }
            $level2Htmls = glob('./storage/out/**/*.html');
            foreach ($level2Htmls as $oldname) {
                if(!preg_match('/assets/i', $oldname)) {
                    $newname = str_replace('storage/out', 'public/page', $oldname);
                    $folder = dirname($newname);
                    if (! is_dir($folder)) {
                        mkdir($folder, 0755, true);
                    }
                    rename($oldname, $newname);
                }
            }
            $level3Htmls = glob('./storage/out/**/**/*.html');
            foreach ($level3Htmls as $oldname) {
                if(!preg_match('/assets/i', $oldname)) {
                    $newname = str_replace('storage/out', 'public/page', $oldname);
                    $folder = dirname($newname);
                    if (! is_dir($folder)) {
                        mkdir($folder, 0755, true);
                    }
                    rename($oldname, $newname);
                }
            }

            $level4Htmls = glob('./storage/out/**/**/**/*.html');
            foreach ($level4Htmls as $oldname) {
                if(!preg_match('/assets/i', $oldname)) {
                    $newname = str_replace('storage/out', 'public/page', $oldname);
                    $folder = dirname($newname);
                    if (! is_dir($folder)) {
                        mkdir($folder, 0755, true);
                    }
                    rename($oldname, $newname);
                }
            }
            recursiveRemoveFolder('./storage/out');
            rmdir('./storage/out');
            $output->writeln("<info>Next.js 佈署成功</info>");
            return 1;
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return 0;
        }
    }
}
