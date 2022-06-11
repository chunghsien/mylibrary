<?php

namespace Chopin\Users\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Expression;
use Laminas\Db\ResultSet\ResultSet;
use NoProtocol\Encryption\MySQL\AES\Crypter;
use Chopin\LaminasDb\DB\Traits\SecurityTrait;
use Laminas\Db\Sql\Where;

class MemberTableGateway extends AbstractTableGateway
{
    use SecurityTrait;

    public static $isRemoveRowGatewayFeature = false;

    /**
     *
     * @inheritdoc
     */
    protected $table = 'member';

    protected $subSelectColumns = [];

    protected $aes_key = '';

    public function __construct(\Laminas\Db\Adapter\Adapter $adapter)
    {
        parent::__construct($adapter);
        $this->aes_key = config('encryption.aes_key');
        $this->subSelectColumns = [
            'id' => 'id',
            'language_id',
            'locale_id',
            //'account',
            'birth',
            'full_name'=> new Expression("CAST(AES_DECRYPT(`full_name`, ?) AS CHAR)", [$this->aes_key]),
            'cellphone'=> new Expression("CAST(AES_DECRYPT(`cellphone`, ?) AS CHAR)", [$this->aes_key]),
            'email'=> new Expression("CAST(AES_DECRYPT(`email`, ?) AS CHAR)", [$this->aes_key]),
            'country',
            'state',
            'zip',
            'county',
            'district',
            'address'=> new Expression("CAST(AES_DECRYPT(`address`, ?) AS CHAR)", [$this->aes_key]),
            'password',
            'temporay_password',
            'salt',
            'is_fb_account',
            'is_cellphone_verify',
            'verify_code',
            'verify_expire',
            'deleted_at',
            'created_at',
            'updated_at'
        ];
    }

    /**
     *
     * @param array|\ArrayObject $data
     * @return array|\ArrayObject
     */
    public function deCryptData($data)
    {
        $aes_columns = ['email', 'cellphone', 'full_name', 'address'];

        //轉換成array，防止\ArrayObject轉型失敗。
        $columns = array_keys((array)$data);
        $crypter = new Crypter($this->aes_key);

        foreach ($columns as $column) {
            if (false !== array_search($column, $aes_columns, true)) {
                $data[$column] = $crypter->decrypt($data[$column]);
            }
        }
        unset($columns);
        return $data;
    }

    /**
     *
     * @param string $email
     * @param bool $idUse
     * @param bool $isAll
     * @return ResultSet
     */
    public function getEmail(string $email, bool $idUse = false, bool $isAll = false): ResultSet
    {
        if ($idUse == true) {
            if ($isAll == true) {
                $subSelect = $this->buildSubSelect(['*']);
            } else {
                $subSelect = $this->buildSubSelect(['id', 'email', 'temporay_password', "verify_expire", 'deleted_at']);
            }
        } else {
            $subSelect = $this->buildSubSelect(['email', 'temporay_password', "verify_expire", 'deleted_at']);
        }
        $select = new Select();
        $pt = self::$prefixTable;
        $where = new Where();
        $where->equalTo('email', $email);
        $where->isNull("deleted_at");
        //$where->isNull("temporay_password");
        $select = $select->from([$pt.'member_decrypt' => $subSelect])->where($where);

        $dataSource = $this->sql->prepareStatementForSqlObject($select)->execute();
        $resultSet = new ResultSet();
        $resultSet->initialize($dataSource);
        return $resultSet;
    }

    /**
     *
     * @param string $cellphone
     * @param int $id
     * @return \Laminas\Db\ResultSet\ResultSet
     */
    public function getCellphoneNotId(string $cellphone, $id)
    {
        $subSelect = $this->buildSubSelect(['*']);
        $select = new Select();
        $pt = self::$prefixTable;
        $select = $select->from([$pt.'member_decrypt' => $subSelect])/*->where(['cellphone' => $cellphone])*/;
        $where = $select->where;
        $where->equalTo('cellphone', $cellphone);
        $where->notEqualTo('id', $id);
        $select->where($where);
        $dataSource = $this->sql->prepareStatementForSqlObject($select)->execute();
        $resultSet = new ResultSet();
        $resultSet->initialize($dataSource);
        return $resultSet;
    }
    /**
     *
     * @param string $cellphone
     * @param bool $idUse
     * @return ResultSet
     */
    public function getCellphone(string $cellphone, bool $idUse = false, bool $isAll = false): ResultSet
    {
        if ($idUse) {
            if ($isAll == true) {
                $subSelect = $this->buildSubSelect(['*']);
            } else {
                $subSelect = $this->buildSubSelect(['id', 'cellphone']);
            }
        } else {
            if ($isAll == true) {
                $subSelect = $this->buildSubSelect(['*']);
            } else {
                $subSelect = $this->buildSubSelect(['cellphone']);
            }
        }
        $select = new Select();
        $pt = self::$prefixTable;
        $select = $select->from([$pt.'member_decrypt' => $subSelect])->where(['cellphone' => $cellphone]);
        $dataSource = $this->sql->prepareStatementForSqlObject($select)->execute();
        $resultSet = new ResultSet();
        $resultSet->initialize($dataSource);
        return $resultSet;
    }

    /**
     *
     * @param int $id
     * @return \ArrayObject
     */
    public function getMember($id)
    {
        $subSelect = $this->buildSubSelect(['*']);
        $select = new Select();
        $pt = self::$prefixTable;
        $select = $select->from([$pt.'member_decrypt' => $subSelect])->where(['id' => $id]);
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
    public function buildSubSelect($columns = ['*'])
    {
        $subSelectTable= $this->table;
        $subSelect = new Select();
        $userColumns = [];
        if ($columns[0] == '*') {
            $userColumns = $this->subSelectColumns;
        } else {
            foreach ($columns as $column) {
                if (isset($this->subSelectColumns[$column])) {
                    $userColumns[$column] = $this->subSelectColumns[$column];
                } else {
                    if (false !== array_search($column, $this->subSelectColumns, true)) {
                        $userColumns[] = $column;
                    }
                }
            }
        }
        $subSelect->from($subSelectTable)->columns($userColumns);
        return $subSelect;
    }
}
