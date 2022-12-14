<?php

namespace Chopin\Users\TableGateway;

use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\Db\Sql\Select;
use Laminas\Db\ResultSet\ResultSet;
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

    /**
     *
     * @param string $email
     * @param bool $idUse
     * @param bool $isAll
     * @return ResultSet
     */
    public function getEmail(string $email, bool $idUse = false, bool $isAll = false): ResultSet
    {
        $subSelect = $this->decryptSubSelectRaw;
        $allColumns = $subSelect->getRawState('columns');
        if ($idUse == true) {
            if (!$isAll) {
                $newColumns = [
                    "id",
                    "email" => $allColumns['email'],
                    "password",
                    "salt",
                    "temporay_password",
                    "verify_expire",
                    "deleted_at",
                ];
                $subSelect->reset('columns');
                $subSelect->columns($newColumns);
            }
        } else {
            $newColumns = [
                "id",
                "email" => $allColumns['email'],
                "password",
                "salt",
                "temporay_password",
                "verify_expire",
                "deleted_at",
            ];
            $subSelect->reset('columns');
            $subSelect->columns($newColumns);
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
        $subSelect = $this->decryptSubSelectRaw;
        $select = new Select();
        $select = $select->from([$this->getDecryptTable() => $subSelect])/*->where(['cellphone' => $cellphone])*/;
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
        $subSelect = $this->decryptSubSelectRaw;
        $allColumns = $subSelect->getRawState('columns');
        if ($idUse) {
            if (!$isAll == true) {
                $newColumns = [
                    "id" => $allColumns["id"],
                    "cellphone" => $allColumns["cellphone"],
                ];
                $subSelect->columns($newColumns);
            } 
        } else {
            if (!$isAll == true) {
                $newColumns = [
                    "cellphone" => $allColumns["cellphone"],
                ];
                $subSelect->columns($newColumns);
            }
        }
        $select = new Select();
        $select = $select->from([$this->getDecryptTable() => $subSelect])->where(['cellphone' => $cellphone]);
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
        $subSelect = $this->decryptSubSelectRaw;
        $select = new Select();
        $select = $select->from([$this->getDecryptTable() => $subSelect])->where(['id' => $id]);
        $dataSource = $this->sql->prepareStatementForSqlObject($select)->execute();
        $resultSet = new ResultSet();
        $resultSet->initialize($dataSource);
        return $resultSet->current();
    }

}
