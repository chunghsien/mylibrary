<?php

namespace Chopin\Store\TableGateway;

/**
 *
 * @namespace Chopin\Store\TableGateway
 * @desc 訂單交易流程狀態
 * @author hsien
 *
 */
abstract class OrderStatusConstant
{
    /**
     *
     * @var integer ORDER_NO_STATUS 訂單建立
     */
    public const ORDER_NO_STATUS = 0;

    /**
     *
     * @var integer CREDIT_ACCOUNT_PAID 付款完成(信用卡或相關綁定支付)
     */
    public const CREDIT_ACCOUNT_PAID = 10;

    /**
     *
     * @var integer TRANSFER_ACCOUNT_PAID 轉帳付款完成(ATM轉帳相關)
     */
    public const TRANSFER_ACCOUNT_PAID = 20;

    /**
     *
     * @var integer STOCK_UP 備貨中(完成對帳狀態，避免重複對帳造成)
     */
    public const STOCK_UP = 30;

    /**
     *
     * @var integer GOODS_SENT_OUT 貨品已寄出
     */
    public const GOODS_SENT_OUT = 40;

    /**
     *
     * @var integer GOODS_SENT_OUT_AND_UNPAID 貨品已寄出(尚未付款)，僅適用超商取貨付款
     */
    public const GOODS_SENT_OUT_AND_UNPAID = 50;

    /**
     *
     * @var integer UNEXPECTED_SITUATION 其他意外狀況
     */
    public const UNEXPECTED_SITUATION = 60;

    /**
     *
     * @var integer DELIVERED_TO_STORE 已到店，僅適用超商取貨或超商取貨付款
     */
    public const DELIVERED_TO_STORE = 70;

    /**
     *
     * @var integer DELIVERED_TO_HOUSE 已到貨，交付管理室或轉至在地物流中心。
     */
    public const DELIVERED_TO_HOUSE = 80;

    /**
     *
     * @var integer RECEIVED_AND_PAID 完成領收且完成付款(僅適用於超商取貨付款,貨到付款)
     */
    public const RECEIVED_AND_PAID = 90;

    /**
     *
     * @var integer RECEIVED 完成領收
     */
    public const RECEIVED = 100;

    /**
     *
     * @var integer TRANSACTION_COMPLETE 交易完成（網路鑑賞期7+3後轉換狀態，訂單完成不能轉換）
     */
    public const TRANSACTION_COMPLETE = 110;
}
