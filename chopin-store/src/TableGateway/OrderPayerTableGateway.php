<?php

namespace Chopin\Store\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Where;
use Laminas\Validator\AbstractValidator;
use Chopin\LaminasDb\DB;
use Psr\Http\Message\ServerRequestInterface;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\ResultSet\ResultSet;

class OrderPayerTableGateway extends AbstractTableGateway
{
    use SecurityTrait;

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = "order_payer";

    protected $aes_key = '';

    protected $subSelectColumns = [];

    public function __construct(\Laminas\Db\Adapter\Adapter $adapter)
    {
        parent::__construct($adapter);
        $this->aes_key = config('encryption.aes_key');
        $this->subSelectColumns = [
            'id' => 'id',
            'order_id',
            'full_name' => new Expression("CAST(AES_DECRYPT(`full_name`, '{$this->aes_key}') AS CHAR)"),
            'phone' => new Expression("CAST(AES_DECRYPT(`phone`, '{$this->aes_key}') AS CHAR)"),
            'cellphone' => new Expression("CAST(AES_DECRYPT(`cellphone`, '{$this->aes_key}') AS CHAR)"),
            'email' => new Expression("CAST(AES_DECRYPT(`email`, '{$this->aes_key}') AS CHAR)"),
            'zip',
            'county',
            'district',
            'address' => new Expression("CAST(AES_DECRYPT(`address`, '{$this->aes_key}') AS CHAR)"),
            'deleted_at',
            'created_at',
            'updated_at'
        ];
    }
    /**
     *
     * @param int $id
     * @return array|\ArrayObject
     */
    public function getOrderPayer($order_id)
    {
        $subSelect = $this->buildSubSelect(['*']);
        $select = new Select();
        $pt = self::$prefixTable;
        $select = $select->from(["{$pt}order_payer_decrypt" => $subSelect])->where(['order_id' => $order_id]);
        $dataSource = $this->sql->prepareStatementForSqlObject($select)->execute();
        $resultSet = new ResultSet();
        $resultSet->initialize($dataSource);
        return $resultSet->current();
    }

    /**
     *
     * @param array $columns
     * @return \Laminas\Db\Sql\Select
     */
    protected function buildSubSelect($columns = ['*'])
    {
        $subSelectTable= $this->table;
        $subSelect = new Select();
        $userColumns = [];
        if ($columns[0] == '*') {
            $userColumns = $this->subSelectColumns;
        } else {
            foreach ($columns as $column) {
                $userColumns[$column] = $this->subSelectColumns[$column];
            }
        }
        $subSelect->from($subSelectTable)->columns($userColumns);
        unset($columns);
        return $subSelect;
    }
}
