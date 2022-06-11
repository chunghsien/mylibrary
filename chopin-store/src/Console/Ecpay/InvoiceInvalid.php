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
use Chopin\Store\TableGateway\PaymentTableGateway;
use Chopin\Store\Logistics\LinePayPayment;
use Chopin\Store\TableGateway\OrderParamsTableGateway;
use Ecpay\Sdk\Factories\Factory;

class InvoiceInvalid extends Command
{
    protected static $defaultName = 'ecpay:invoice-invalid';


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
        $this->setDescription("綠界的發票做廢");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         *
         * @var Adapter $adapter
         */
        $adapter = $this->container->get(Adapter::class);
        $orderTableGateway = new OrderTableGateway($adapter);
        $select = $orderTableGateway->getSql()->select();
        $where = new Where();
        $where->equalTo("status", -13);
        $where->equalTo('invoice_invalid', 0);
        $where->isNotNull('invoice_no');
        $select->where($where);
        $resultset = $orderTableGateway->selectWith($select);
        $ecpayPayment = new EcpayPayment($adapter);
        $config = $ecpayPayment->getConfig()["invoiceParams"];
        $factory = new Factory([
            'hashKey' => $config["hashKey"],
            'hashIv' => $config["hashIv"],
        ]);
        $postService = $factory->create('PostWithAesJsonResponseService');
        $orderParamsTableGateway = new OrderParamsTableGateway($adapter);
        foreach ($resultset as $row) {
            $row = $orderTableGateway->deCryptData($row);
            $orderParamsRow = $orderParamsTableGateway->select(["order_id" => $row->id, "name" => "invoice_create"])->current();
            if ($orderParamsRow) {
                $csvcomParams = json_decode($orderParamsRow->csvcom_params);
                $invoiceDate = date("Y-m-d", strtotime($csvcomParams->Data->InvoiceDate));
                $data = [
                    'MerchantID' => $config["merchantID"],
                    'InvoiceNo' => $row->invoice_no,
                    'InvoiceDate' => $invoiceDate,
                    'Reason' => '交易取消<含退款完成>',
                ];
                $input = [
                    'MerchantID' => $config["merchantID"],
                    'RqHeader' => [
                        'Timestamp' => time(),
                        'Revision' => '3.0.0',
                    ],
                    'Data' => $data,
                ];
                $url = $config["invalidUri"];
                $response = $postService->post($input, $url);
                if ($response["TransCode"] == 1) {
                    $ecpayPayment->getLogger()->info("Invoice invalid fail: ".json_encode($response, JSON_UNESCAPED_UNICODE));
                    $orderParamsTableGateway->insert([
                        "order_id" => $row->id,
                        "name" => "invoice_invalid",
                        "csvcom_params" => json_encode($response, JSON_UNESCAPED_UNICODE),
                    ]);
                    $orderTableGateway->update([
                        "invoice_invalid" => 1
                    ], ["id" => $row->id]);
                    $output->writeln("<info>發票（{$row->invoice_no}）做廢成功</info>");
                } else {
                    $ecpayPayment->getLogger()->err("Invoice invalid fail: ".json_encode($response, JSON_UNESCAPED_UNICODE));
                    $output->writeln("<error>發票（{$row->invoice_no}）做廢失敗</error>");
                }
            }
        }

        if ($resultset->count()) {
            $output->writeln("<comment>處理完成</comment>");
        } else {
            $output->writeln("<comment>無資料</comment>");
        }
        unset($resultset);
        return 1;
    }
}
