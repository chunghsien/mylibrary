<?php

namespace Chopin\Store\Console\LinePay;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Db\Adapter\Adapter;
use Chopin\Store\Logistics\LinePayPayment;
use Chopin\SystemSettings\TableGateway\SystemSettingsTableGateway;

class CreateDbData extends Command
{
    protected static $defaultName = 'linepay:create-db-data';


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
        $this->setDescription("建立LINE Pay的組態。");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            /**
             *
             * @var Adapter $adapter
             */
            $adapter = $this->container->get(Adapter::class);
            $linePayPayment = new LinePayPayment($adapter);
            $linePayPayment->addDataToPaymentTable();
            $config = $linePayPayment->getDefaultParamsTemplate();
            $systemSettingsTableGateway = new SystemSettingsTableGateway($adapter);
            $row = $systemSettingsTableGateway->select(["key" => "LINEPay-config"])->current();
            $configCrypt = json_encode($config, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
            //$aesCrypt = $systemSettingsTableGateway->getAesCrypter();
            //$configCrypt = $aesCrypt->encrypt($configCrypt);
            $systemSettingsTableGateway->update(["aes_value" => $configCrypt], ["id" => $row->id]);
            $output->writeln("<info>LINE Pay組態建立完成</info>");
            return 1;
        } catch (\Exception $e) {
            loggerException($e);
            $output->writeln("<error>{$e->getMessage()}</error>");
            return 0;
        }
    }
}
