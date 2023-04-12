<?php
namespace Chopin\Store\Payment;

use Ecpay\Sdk\Services\UrlService;
use Ecpay\Sdk\Request\CheckMacValueRequest;
use Ecpay\Sdk\Services\HtmlService;
use Ecpay\Sdk\Factories\Factory;
use Ecpay\Sdk\Response\VerifiedArrayResponse;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\TextResponse;
use Chopin\Store\TableGateway\OrderTableGateway;
use Psr\Http\Message\ResponseInterface;
use Chopin\Store\TableGateway\CartTableGateway;
use Chopin\Store\TableGateway\OrderParamsTableGateway;

class EcpayAllInOneWebATM extends AbstractPayment
{

    const CODE = 'EcpayAllInOneWebATM';

    /**
     *
     * @var Factory
     */
    protected $factory;

    protected $config;

    protected function initFactory()
    {
        if (! $this->factory instanceof Factory) {
            $config = config('third_party_service.logistics.ecpayAllInOne.payment');
            $this->config = $config;
            $this->factory = new Factory([
                'hashKey' => $config['hashKey'],
                'hashIv' => $config['hashIv']
            ]);
        }
    }

    public function processResponse(ServerRequestInterface $request): ResponseInterface
    {
        $this->initFactory();
        $factory = $this->factory;
        $paychecked = false;
        $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
        $orderTableGateway = new OrderTableGateway($this->adapter);
        if (strtolower($request->getMethod()) == 'post') {
            $post = $request->getParsedBody();
            /**
             *
             * @var VerifiedArrayResponse $checkoutResponse
             */
            $checkoutResponse = $factory->create(VerifiedArrayResponse::class);
            $parsed = $checkoutResponse->get($post);
            logger('./storage/logs/ecpay_atm_%s.log')->info("Notify: " . json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            // ATM 回傳值時為2時，交易狀態為取號成功，其餘為失敗。
            if (intval($parsed['RtnCode']) == 2) {
                $orderSerial = $parsed['MerchantTradeNo'];
                $orderRow = $orderTableGateway->select([
                    'serial' => $orderSerial
                ])->current();
                $orderParamsRow = $orderParamsTableGateway->select(['order_id' => $orderRow->id,  'name' => 'post_params'])->current();
                if($orderParamsRow) {
                    $orderParamsRow = $orderParamsTableGateway->deCryptData($orderParamsRow);
                    $comParams = (array)$orderParamsRow->com_params;
                    $comParams['payment_response'] = $parsed;
                    $orderParamsTableGateway->update(['com_params' => json_encode($comParams)], ['id' => $orderParamsRow->id]);
                }
            }
        }
        if (strtolower($request->getMethod()) == 'get') {
            $queryParams = $request->getQueryParams();
            $order_id = $queryParams['order_id'];
            $orderTableGateway = new OrderTableGateway($this->adapter);
            $orderRow = $orderTableGateway->select([
                'id' => $order_id
            ])->current();
        }
        if ($orderRow && $orderRow->status == 0) {
            $config = $this->config;
            $input = [
                'MerchantID' => $config['merchantID'],
                'MerchantTradeNo' => $orderRow->serial,
                'TimeStamp' => time()
            ];
            $factory = $this->factory;
            $postService = $factory->create('PostWithCmvVerifiedEncodedStrResponseService');
            // 先取出交易資訊(銀行代碼BankCode、付款截止時間ExpireDate、付款金額TradeAmt、付款帳號vAccount)
            $actionUrl = $config['queryPaymentInfoUri'];
            $response = $postService->post($input, $actionUrl);
            $orderParamsRow = $orderParamsTableGateway->select(['order_id' => $orderRow->id,  'name' => 'post_params'])->current();
            if(!$orderParamsRow) {
                $orderParamsTableGateway->insert([
                    'order_id' => $orderRow->id,
                    'name' => 'post_params',
                    'com_params' => json_encode([]),
                ]);
                $orderParamsRow = $orderParamsTableGateway->select(['id' => $orderParamsTableGateway->getLastInsertValue()])->current();
            }
            $comParams['payment_response'] = $response;
            $orderParamsRow = $orderParamsTableGateway->deCryptData($orderParamsRow);
            if (intval($response['RtnCode']) == 2) {
                
                if (strtolower($request->getMethod()) == 'get') {
                    /**
                     *
                     * @var \Mezzio\Session\LazySession $session
                     */
                    $session = $request->getAttribute('session');
                    if ($session->has('member')) {
                        $cartTableGateway = new CartTableGateway($this->adapter);
                        $sessionMember = $session->get('member');
                        $cartTableGateway->delete([ 'member_id' => $sessionMember['id']]);
                    }
                }
                $actionUrl = $config['queryTradeInfoUri'];
                $response = $postService->post($input, $actionUrl);
                //$paymentResponse = isset($comParams['payment_response']) ? $comParams['payment_response'] : [];
                $comParams['payment_result'] = $response;
                if (intval($response['TradeStatus']) == 1) {
                    $paychecked = true;
                    $paychecked_at = $response['PaymentDate'];
                }
                $orderParamsTableGateway->update(['com_params' => json_encode($comParams)], ['id' => $orderParamsRow->id]);
            }
        }

        if ($paychecked) {
            $status = $orderTableGateway->status_mapper['order_paid'];
            if (empty($paychecked_at)) {
                $paychecked_at = date("Y-m-d H:i:s");
            }
            $set = [
                'status' => $status,
                'paychecked_at' => $paychecked_at
            ];
            $orderTableGateway->update($set, [
                'id' => $orderRow->id
            ]);
        }
        return new TextResponse('1|OK');
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    public function requestRefundApi(ServerRequestInterface $request): array
    {
        return [];
    }

    /**
     *
     * {@inheritdoc}
     * @see \Chopin\Store\Payment\AbstractPayment::requestApi()
     */
    public function requestApi($orderCommonParams, $translator = null): array
    {
        $this->initFactory();
        $factory = $this->factory;
        $config = $this->config;
        /**
         *
         * @var HtmlService $htmlService
         */
        $htmlService = $factory->create(HtmlService::class);

        /**
         *
         * @var CheckMacValueRequest $checkMacValueRequest
         */
        $checkMacValueRequest = $factory->create(CheckMacValueRequest::class);
        $input = [];
        $action = $config['aioCheckoutUri'];
        $orderSet = $orderCommonParams['order'];
        $itemName = '';
        foreach ($orderSet['details'] as $orderItem) {
            $name = $orderItem['alias'];
            if ($orderItem['option1_name']) {
                $option1_name = $orderItem['option1_name'];
                $name .= "({$option1_name}";
            }
            if ($orderItem['option2_name']) {
                $option2_name = $orderItem['option2_name'];
                $name .= ",{$option2_name}";
            }
            if ($orderItem['option3_name']) {
                $option3_name = $orderItem['option3_name'];
                $name .= ",{$option3_name}";
            }
            if ($orderItem['option4_name']) {
                $option4_name = $orderItem['option4_name'];
                $name .= ",{$option4_name}";
            }
            if ($orderItem['option5_name']) {
                $option5_name = $orderItem['option5_name'];
                $name .= ",{$option5_name}";
            }
            if ($orderItem['option1_name']) {
                $name .= ")";
            }
            $itemName .= "#{$name}";
        }
        if (mb_strlen($itemName, 'utf-8') > 400) {
            $itemName = mb_substr($itemName, 0, 397, 'utf-8');
            $itemName .= '...';
        }
        $tradeDesc = $orderSet['serial'] . "綠界ATM";

        $lang = '';
        if (config('is_multiple_language')) {
            $lang = $this->request->getAttribute('html_lang');
        }
        $code = lcfirst(self::CODE);
        $returnURL = "/{$lang}/third-party-pay-notify/{$code}";
        $returnURL = preg_replace('/\/\//', '/', $returnURL);
        $returnURL = siteBaseUrl() . $returnURL;
        $orderId = $orderSet['id'];

        $clientBackURL = "/{$lang}/my-account?order_id={$orderId}&code={$code}";
        $clientBackURL = preg_replace('/\/\//', '/', $clientBackURL);
        $clientBackURL = siteBaseUrl() . $clientBackURL;
        $input = [
            'MerchantID' => $config['merchantID'],
            'MerchantTradeNo' => $orderSet['serial'],
            'MerchantTradeDate' => date('Y/m/d H:i:s'),
            'PaymentType' => 'aio',
            'TotalAmount' => intval($orderSet['total']),
            'TradeDesc' => UrlService::ecpayUrlEncode($tradeDesc),
            'ItemName' => $itemName,
            'ChoosePayment' => 'WebATM',
            'EncryptType' => 1,
            'ReturnURL' => $returnURL,
            'ClientBackURL' => $clientBackURL
            // 'OrderResultURL ' => $orderResultURL
        ];
        /*
         * if($_ENV['APP_ENV'] != 'production') {
         * $input['']
         * }
         */
        $requestInput = $checkMacValueRequest->toArray($input);
        logger('./storage/logs/ecpay_allin_one/atm_%s.log')->info('Request: ' . json_encode($input, JSON_UNESCAPED_UNICODE));
        $form_id = 'EcpayAllInOneCreditForm';
        $content = $htmlService->form($requestInput, $action, '_self', $form_id, 'ecpay-button');
        $message = 'immediately_to_ecpay_all_in_one';
        $translator->addTranslationFilePattern('phpArray', dirname(dirname(__DIR__)), '/resources/languages/%s/ecpay.php', 'ecpay');
        $message = $translator->translate('immediately_to_ecpay_all_in_one', 'ecpay', $this->request->getAttribute('php_lang'));
        unset($input);
        return [
            'orderedStatus' => true,
            'content' => $content,
            'form_id' => $form_id,
            "message" => $message
        ];
    }
}