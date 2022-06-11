<?php

namespace Chopin\Store\Console\LinePay;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Db\Adapter\Adapter;
use Chopin\Store\Logistics\LinePayPayment;
use Symfony\Component\Console\Input\InputArgument;

class LinePayRefund extends Command
{
    protected static $defaultName = 'linepay:refund';


    /**
     *
     * @var ServiceManager
     */
    private $container;


    public function __construct(ServiceManager $container=null)
    {
        parent::__construct();

        if (! $container) {
            global $container;
        }

        $this->container = $container;
    }

    protected function configure()
    {
        $this->addArgument('transcation', InputArgument::REQUIRED, '交易編號');
        $this->setDescription("LINE Pay 取消已付款(購買完成)的交易");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (APP_ENV != "development") {
            $output->writeln("<error>僅能在開發環境下執行</error>");
            return 0;
            //exit();
        }
        /**
         *
         * @var Adapter $adapter
         */
        $adapter = $this->container->get(Adapter::class);
        $linePayPayment = new LinePayPayment($adapter);
        $translationId = $input->getArgument('transcation');
        $response = $linePayPayment->refundApi($translationId);
        if ($response["returnCode"] == "0000") {
            $output->writeln("<info>取消付款成功</info>");
            return 1;
        } else {
            $message =  "<".$response["returnCode"].">: ".$response["returnMessage"];
            $output->writeln("<error>{$message}</error>");
            return 0;
        }
    }
}
