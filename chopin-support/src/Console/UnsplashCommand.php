<?php

namespace Chopin\Support\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class UnsplashCommand extends Command
{
    protected static $defaultName = 'unsplash';

    protected function configure()
    {
        $this->setDescription("從Unsplash上抓取後台登入頁的背景圖片");
        $this->addOption('keyword', null, InputOption::VALUE_OPTIONAL, '圖片關鍵字');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = [];
        if ($config = config('unsplash')) {
            \Unsplash\HttpClient::init([
                'applicationId' => $config["applicationId"],
                'secret' => $config["secret"],
                'utmSource' => $config["utmSource"]
            ]);
            $scopes = [
                'public',
                'write_user'
            ];
            \Unsplash\HttpClient::$connection->getConnectionUrl($scopes);
            $options = $input->getOptions();
            $keyword = $options["keyword"];

            $page = 1;
            $per_page = 10;
            $orientation = 'squarish';

            $photos = \Unsplash\Search::photos($keyword, $page, $per_page, $orientation, 'latest');
            $photoResult = $photos->getResults();
            $unsplashJson = [];
            foreach ($photoResult as $photo) {
                $url = $photo["urls"]["full"];
                $id = $photo["id"];
                $savePath = "./public/unsplash/{$id}.jpg";
                if (! is_dir(dirname($savePath))) {
                    mkdir(dirname($savePath), 0755, true);
                }
                $stream = file_get_contents($url);
                file_put_contents($savePath, $stream);
                // $photo["local"] = $savePath;
                $tmp = [
                    "userLinks" => $photo["user"]["links"]["html"],
                    "name" => $photo["user"]["username"],
                    "image" => preg_replace("/^\.\/public/", "", $savePath)
                ];
                $unsplashJson[] = $tmp;
            }
            file_put_contents("./resources/unsplash.json", json_encode($unsplashJson));
            $output->writeln("<info>執行完成</info>");
            unset($photoResult);
            return 1;
        }
        $output->writeln("<error>缺少組態設定</error>");
        return 0;
    }
}
