<?php

namespace Chopin\Store\TableGateway;

/**
 * @namespace Chopin\Store\TableGatewa
 * @desc 訂單逆向流程狀態(部分狀態為交易錯誤狀態，ex.串接錯誤回傳)，資料表型態顯示負數對應取出時要注意
 * @author hsien
 *
 */
abstract class OrderReverseStatusConstant
{
    /**
     *
     * @var integer ORDER_REVERSE_STATUS_PROCESSING 填空狀態，無意義完全不會顯示
     */
    public const ORDER_REVERSE_STATUS_PROCESSING = 0;

    /**
     *
     * @var integer CANCEL_APPICATION 訂單取消申請
     */
    public const CANCEL_APPICATION = 10;

    /**
     *
     * @var integer CANCEL_AGREE 訂單取消同意
     */
    public const CANCEL_AGREE = 20;

    /**
     *
     * @var integer CANCEL_NOT_AGREE 訂單取消不同意
     */
    public const CANCEL_NOT_AGREE = 30;

    /**
     *
     * @var integer CANCEL_COMPLETE 訂單取消完成
     */
    public const CANCEL_COMPLETE = 40;

    /**
     *
     * @var integer ORDER_REVERSE_APPLICATION 退貨申請
     */
    public const ORDER_REVERSE_APPLICATION = 50;

    /**
     *
     * @var integer ORDER_REVERSE_AGREE 退貨同意
     */
    public const ORDER_REVERSE_AGREE = 60;

    /**
     *
     * @var integer ORDER_REVERSE_NOT_AGREE 退貨不同意
     */
    public const ORDER_REVERSE_NOT_AGREE = 70;

    /**
     *
     * @var integer ORDER_REVERSE_PICUP 逆物流已收到貨
     */
    public const ORDER_REVERSE_PICUP = 80;

    /**
     *
     * @var integer ORDER_REVERSE_DELIVERED 店家已收到退貨
     */
    public const ORDER_REVERSE_DELIVERED = 90;

    /**
     *
     * @var integer ORDER_REVERSE_COMPLETE  完成退貨
     */
    public const ORDER_REVERSE_COMPLETE = 100;

    /**
     *
     * @var integer THIRD_PARTY_PAY_PROCESS_FAIL 第三方金流處理錯誤
     */
    public const THIRD_PARTY_PAY_PROCESS_FAIL = 110;

    /**
     *
     * @var integer CANCEL_THE_DEAL 交易取消(含退款)
     */
    public const CANCEL_THE_DEAL = 120;
}
