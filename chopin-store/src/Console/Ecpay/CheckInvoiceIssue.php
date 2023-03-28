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

class CheckInvoiceIssue extends Command
{
    protected static $defaultName = 'ecpay:invoice-issue';


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
        $this->setDescription("更新綠界的發票開立狀態");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         *
         * @var Adapter $adapter
         */
        $adapter = $this->container->get(Adapter::class);
        $orderTableGateway = new OrderTableGateway($adapter);
        $paymentTableGateway = new PaymentTableGateway($adapter);
        $select = $orderTableGateway->getSql()->select();
        $select->join(
            $paymentTableGateway->table,
            "{$paymentTableGateway->table}.id={$orderTableGateway->table}.payment_id",
            ["code"]
        );
        $where = new Where();
        $where->equalTo("status", 1);
        $where->isNull('invoice_no');
        $select->where($where);
        $resultset = $orderTableGateway->selectWith($select);
        $ecpayPayment = new EcpayPayment($adapter);
        $linePayPayment = new LinePayPayment($adapter);
        $orderParamsTableGateway = new OrderParamsTableGateway($adapter);
        foreach ($resultset as $row) {
            $order = $row->toArray();
            if ($order["code"] == "LINEPay") {
                //開立前確認付款狀態
                $orderId = $order["id"];
                $orderParamsRow = $orderParamsTableGateway->select([
                    "order_id" => $orderId,
                    "name" => "linepay_success"
                ])->current();
                if ($orderParamsRow) {
                    $translationId = $orderParamsRow->merchant_trade_no;
                    $response = $linePayPayment->checkPaymentApi($translationId);
                    if (isset($response["returnCode"]) && $response["returnCode"] != "0123") {
                        $index = array_search("order_paid_fail", $orderTableGateway->reverse_status, true) ;
                        $orderTableGateway->update([
                            "status" => -$index,
                        ], ["id" => $orderId]);
                        $message = "<".$response["returnCode"]. ">".$response["returnMessage"];
                        $output->writeln("<error>{$message}</error>");
                        continue;
                    }
                }
            }
            //開發票
            $msg = $ecpayPayment->checkInvoiceIssue($order);
            $output->writeln($msg);
            //查詢確認寫log
            $msg = $ecpayPayment->checkInvoiceIssue($order);
            $output->writeln($msg);
        }
        unset($resultset);
        return 1;
    }
}
