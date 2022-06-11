<?php

namespace Chopin\Store\Console\Ecpay;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Db\Adapter\Adapter;
use Chopin\Store\Logistics\EcpayPayment;
use Chopin\Store\TableGateway\OrderTableGateway;
use Laminas\Db\Sql\Where;
use Chopin\Store\TableGateway\LogisticsGlobalTableGateway;
use Chopin\Store\TableGateway\PaymentTableGateway;
use Ecpay\Sdk\Factories\Factory;
use Ecpay\Sdk\Services\PostService;
use Laminas\I18n\Translator\Translator;
use Chopin\Store\TableGateway\OrderDetailTableGateway;

class ExpressCreate extends Command
{
    protected static $defaultName = 'ecpay:express-create';

    /**
     *
     * @var ServiceManager
     */
    private $container;

    public function __construct(ServiceManager $container = null)
    {
        parent::__construct();

        if (! $container) {
            global $container;
        }

        $this->container = $container;
    }

    protected function configure()
    {
        $this->setDescription("建立綠界物流訂單(超取及宅配)");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         *
         * @var Adapter $adapter
         */
        $adapter = $this->container->get(Adapter::class);
        $ecpayPayment = new EcpayPayment($adapter);
        $config = $ecpayPayment->getConfig()["logistics"];
        $factory = new Factory([
            "hashKey" => $config["hashKey"],
            "hashIv" => $config["hashIv"],
            "hashMethod" => "md5",
        ]);

        $orderTableGateway = new OrderTableGateway($adapter);
        $logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($adapter);
        $where = new Where();
        $where->equalTo("status", 1);
        $where->isNull("{$orderTableGateway->table}.deleted_at");
        $select = $orderTableGateway->getSql()->select();
        $select->join($logisticsGlobalTableGateway->table, "{$orderTableGateway->table}.logistics_global_id={$logisticsGlobalTableGateway->table}.id", [
            "code", "extra_params"
        ]);
        $select->where($where);
        $result = $orderTableGateway->selectWith($select)->toArray();
        /**
         *
         * @var PostService $postService
         */
        $postService = $factory->create('PostWithCmvEncodedStrResponseService');
        $orderDetailTableGateway = new OrderDetailTableGateway($adapter);
        foreach ($result as $item) {
            $item = $orderTableGateway->deCryptData($item);
            $where = new Where();
            $where->isNull("deleted_at");
            $where->equalTo("order_id", $item["id"]);
            $details = $orderDetailTableGateway->select($where)->toArray();
            $item["details"] = $details;
            $ecpayPayment->sendOrderShip($item);
            $serial = $item["serial"];
            $output->writeln("<info><{$serial}>物流訂單建立完成</info>");
            sleep(1);
        }
        unset($result);
        return 1;
        //debug($result);
    }
}
