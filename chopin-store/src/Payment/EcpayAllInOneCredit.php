<?php
namespace Chopin\Store\Payment;

use Ecpay\Sdk\Services\UrlService;
use Ecpay\Sdk\Request\CheckMacValueRequest;
use Ecpay\Sdk\Services\HtmlService;
use Ecpay\Sdk\Factories\Factory;
use Ecpay\Sdk\Response\VerifiedArrayResponse;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\TextResponse;
use Chopin\Support\Log;
use Chopin\Store\TableGateway\OrderTableGateway;
use  Psr\Http\Message\ResponseInterface;
use Chopin\Store\TableGateway\CartTableGateway;
use Chopin\Store\TableGateway\OrderParamsTableGateway;

class EcpayAllInOneCredit extends AbstractPayment
{

    const CODE = 'EcpayAllInOneCredit';

    /**
     * 
     * @var Factory
     */
    protected $factory;
    
    protected $config;
    
    protected function initFactory() {
        if(!$this->factory instanceof Factory) {
            $config = config('third_party_service.logistics.ecpayAllInOne.payment');
            $this->config = $config;
            $this->factory = new Factory([
                'hashKey' => $config['hashKey'],
                'hashIv' => $config['hashIv']
            ]);
            
        }
    }
    
    public function processResponse(ServerRequestInterface $request):ResponseInterface {
        $this->initFactory();
        $factory = $this->factory;
        $paychecked = false;
        $orderParamsTableGateway = new OrderParamsTableGateway($this->adapter);
        if(strtolower($request->getMethod()) == 'post') {
            $post = $request->getParsedBody();
            /**
             *
             * @var VerifiedArrayResponse $checkoutResponse
             */
            $checkoutResponse = $factory->create(VerifiedArrayResponse::class);
            $parsed = $checkoutResponse->get($post);
            if(intval($parsed['RtnCode']) == 1) {
                $orderSerial = $parsed['MerchantTradeNo'];
                $orderTableGateway = new OrderTableGateway($this->adapter);
                $orderRow = $orderTableGateway->select(['serial' => $orderSerial])->current();
                $orderParamsRow = $orderParamsTableGateway->select(['order_id' => $orderRow->id,  'name' => 'post_params'])->current();
                if($orderParamsRow) {
                    $orderParamsRow = $orderParamsTableGateway->deCryptData($orderParamsRow);
                    $comParams = (array)$orderParamsRow->com_params;
                    $comParams['payment_response'] = $parsed;
                    $orderParamsTableGateway->update(['com_params' => json_encode($comParams)], ['id' => $orderParamsRow->id]);
                }
                $paychecked = true;
                $paychecked_at = isset($parsed['PaymentDate']) ? $parsed['PaymentDate'] : date("Y-m-d H:i:s");
                logger('./storage/logs/ecpay_credit_%s.log')->info('Notify: '.json_encode($parsed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                
            }
        }
        if(strtolower($request->getMethod()) == 'get') {
            $queryParams = $request->getQueryParams();
            $order_id = $queryParams['order_id'];
            $orderTableGateway = new OrderTableGateway($this->adapter);
            $orderRow = $orderTableGateway->select(['id' => $order_id])->current();
            if($orderRow && $orderRow->status == 0) {
                $config = $this->config;
                $input = [
                    'MerchantID' => $config['merchantID'],
                    'MerchantTradeNo' => $orderRow->serial,
                    'TimeStamp' => time(),
                ];
                $factory = $this->factory;
                $postService = $factory->create('PostWithCmvVerifiedEncodedStrResponseService');
                $actionUrl = $config['queryTradeInfoUri'];
                $response = $postService->post($input, $actionUrl);
                logger('./storage/logs/ecpay_credit_%s.log')->info("queryTradeInfo: ".json_encode($response, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                $orderParamsRow = $orderParamsTableGateway->select(['order_id' => $orderRow->id,  'name' => 'post_params'])->current();
                if($orderParamsRow) {
                    $orderParamsRow = $orderParamsTableGateway->deCryptData($orderParamsRow);
                    $comParams = (array)$orderParamsRow->com_params;
                    $comParams['payment_response'] = $response;
                    $orderParamsTableGateway->update(['com_params' => json_encode($comParams)], ['id' => $orderParamsRow->id]);
                }
                
                if(intval($response['TradeStatus']) == 1) {
                    $paychecked = true;
                    $paychecked_at = $response['PaymentDate'];
                    /**
                     *
                     * @var \Mezzio\Session\LazySession $session
                     */
                    $session = $request->getAttribute('session');
                    if($session->has('member')) {
                        $cartTableGateway = new CartTableGateway($this->adapter);
                        $sessionMember = $session->get('member');
                        $cartTableGateway->delete(['member_id' => $sessionMember['id']]);
                    }
                }
            }
        }
        if($paychecked) {
            $status = $orderTableGateway->status_mapper['order_paid'];
            if(empty($paychecked_at)) {
                $paychecked_at = date("Y-m-d H:i:s");
            }
            $set = [
                'status' => $status,
                'paychecked_at' => $paychecked_at
            ];
            $orderTableGateway->update($set, ['id' => $orderRow->id]);
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
        if(isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] == 'development') {
            return [
                'status' => true,
                'msg' => '刷退成功',
            ];
        }
        
        $this->initFactory();
        $factory = $this->factory;
        $config = $this->config;
        $actionUrl = $config['creditDetailDoActionUri'];
        $postService = $factory->create('PostWithCmvVerifiedEncodedStrResponseService');
        $post = $request->getParsedBody();
        if(!$post) {
            $post = json_decode($request->getBody()->getContents());
        }
        $order_id = $post['order_id'];
        $orderTableGateway = new OrderTableGateway($this->adapter);
        $orderRow = $orderTableGateway->select([ 'id' => $order_id ])->current();
        if(!$order_id){
            throw new \ErrorException('order_refund_apply_fail');
        }
        $config = $this->config;
        $input = [
            'MerchantID' => $config['merchantID'],
            'MerchantTradeNo' => $orderRow->serial,
                'TimeStamp' => time(),
            ];
        
        $factory = $this->factory;
        $postService = $factory->create('PostWithCmvVerifiedEncodedStrResponseService');
        $actionUrl = $config['queryTradeInfoUri'];
        $response = $postService->post($input, $actionUrl);
        if(empty($response['TradeNo'])) {
            throw new \ErrorException('order_refund_apply_fail');
        }
        $totalAmount = intval($orderRow->total);
        if(isset($post['refund_amount'])) {
            $totalAmount = intval($post['refund_amount']);
        }
        $input = [
            'MerchantID' => $config['merchantID'],
            'MerchantTradeNo' => $orderRow->serial,
            'TradeNo' => $response['TradeNo'],
            'Action' => 'R',
            'TotalAmount' => $totalAmount,
        ];
        $response = $postService->post($input, $actionUrl);
        $response['status'] = true; 
        if(intval($response['RtnCode ']) == 1) {
            $response['status'] = false; 
        }
        $response['msg'] = $response['RtnMsg']; 
        return $response;
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
        $tradeDesc = $orderSet['serial'] . "綠界刷卡";
        
        $lang = '';
        if (config('is_multiple_language')) {
            $lang = $this->request->getAttribute('html_lang');
        }
        $code = lcfirst(self::CODE);
        $returnURL = "/{$lang}/third-party-pay-notify/{$code}";
        $returnURL = preg_replace('/\/\//', '/', $returnURL);
        $returnURL  = siteBaseUrl().$returnURL;
        $orderId = $orderSet['id'];
        
        $clientBackURL = "/{$lang}/my-account?order_id={$orderId}&code={$code}";
        $clientBackURL = preg_replace('/\/\//', '/', $clientBackURL);
        $clientBackURL  = siteBaseUrl().$clientBackURL;
        $input = [
            'MerchantID' => $config['merchantID'],
            'MerchantTradeNo' => $orderSet['serial'],
            'MerchantTradeDate' => date('Y/m/d H:i:s'),
            'PaymentType' => 'aio',
            'TotalAmount' => intval($orderSet['total']),
            'TradeDesc' => UrlService::ecpayUrlEncode($tradeDesc),
            'ItemName' => $itemName,
            'ChoosePayment' => 'Credit',
            'EncryptType' => 1,
            'ReturnURL' => $returnURL,
            'ClientBackURL' => $clientBackURL
            //'OrderResultURL ' => $orderResultURL
        ];
        $requestInput = $checkMacValueRequest->toArray($input);
        logger('./storage/logs/ecpay_allin_one/credit_%s.log')->info('Request: ' . json_encode($input, JSON_UNESCAPED_UNICODE));
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