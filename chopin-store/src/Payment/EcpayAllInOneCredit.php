<?php

namespace Chopin\Store\Payment;


use Psr\Http\Message\ServerRequestInterface;
use Chopin\Store\TableGateway\CartParamsTableGateway;
use Chopin\Store\TableGateway\CartTableGateway;

class EcpayAllInOneCredit extends AbstractPayment {
    
    /**
     * 
     * @var \ECPay_AllInOne
     */
    protected $allInOne;
    
    public function buildContent(ServerRequestInterface $request): bool
    {
        $post = json_decode($request->getBody()->getContents(), true);
        $cartTableGateway = new CartTableGateway($this->adapter);
        $guestSerial = $cartTableGateway->getGuestSerial($request)['serial'];
        $cartParamsTableGateway = new CartParamsTableGateway($this->adapter);
        debug($post);
        return true;
    }
}