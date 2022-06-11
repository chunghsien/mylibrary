<?php

namespace Chopin\Store\Console\Ecpay;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Db\Adapter\Adapter;
use Chopin\Store\Logistics\EcpayPayment;
use Chopin\Store\TableGateway\PaymentTableGateway;
use Chopin\Store\TableGateway\LogisticsGlobalTableGateway;

class CreateLogistictData extends Command
{
    protected static $defaultName = 'ecpay:create-db-data';


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
        $this->setDescription("建立綠界金流的預設金物流資料。");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         *
         * @var Adapter $adapter
         */
        $adapter = $this->container->get(Adapter::class);
        $ecpayPayment = new EcpayPayment($adapter);
        try {
            $ecpayPayment->addDataToPaymentTable();
            $ecpayPayment->addDataToLogisticsTable();
            $paymentTableGateway = new PaymentTableGateway($adapter);
            $logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($adapter);
            $logisticsGlobalResultset = $logisticsGlobalTableGateway->select();
            foreach ($logisticsGlobalResultset as $row) {
                $code = $row->code;
                $paymentRow = $paymentTableGateway->select(["code" => $code])->current();
                if ($paymentRow) {
                    $paymentRow->logistics_global_id = $row->id;
                    $paymentRow->save();
                }
            }
            unset($logisticsGlobalResultset);
            $output->writeln("<info>資料建立完成</info>");
            return 1;
        } catch (\Exception $e) {
            loggerException($e);
            $output->writeln("<error>{$e->getMessage()}</error>");
            return 0;
        }
    }
}
