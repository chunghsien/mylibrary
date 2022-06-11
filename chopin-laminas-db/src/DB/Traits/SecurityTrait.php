<?php

namespace Chopin\LaminasDb\DB\Traits;

use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select as LaminasSelect;
use NoProtocol\Encryption\MySQL\AES\Crypter;
use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

trait SecurityTrait
{
    /**
     *
     * @var Crypter
     */
    protected $aesCrypter;

    /**
     *
     * @var LaminasSelect
     */
    protected $decryptSubSelectRaw;

    public $defaultEncryptionColumns = [
        'full_name',
        'fullname',
        'first_name',
        'last_name',
        'username',
        'email',
        'phone',
        'cellphone',
        'invoice_phone',
        'tel',
        'telphone',
        'address', //住址
        'full_address', //住址
        'aes_value',
        "third_party_payment_params",
    ];

    /**
     ** 預設的密碼欄位名稱
     * @var string
     */
    protected $defaultPasswordColumn = 'password';

    /**
     *
     * @return \NoProtocol\Encryption\MySQL\AES\Crypter
     */
    public function getCrypter()
    {
        return $this->aesCrypter;
    }

    public function getAesCrypter(): Crypter
    {
        return $this->aesCrypter;
    }

    /**
     **建立AES加密的子查詢樣式
     * @throws \ErrorException
     */
    protected function buildAESDecryptFrom($table)
    {
        $encryptionColumns = $this->getTableGateway()->encryptionColumns;
        if (! $encryptionColumns) {
            $encryptionColumns = [];
        }
        $encryptColumns = array_merge($this->defaultEncryptionColumns, $encryptionColumns);
        $columns = [];
        $idEncrypt = false;
        $select = new LaminasSelect($table);
        $columns = [];
        foreach ($this->tablegateway->getColumns() as $column) {
            if ((false !== array_search($column, $encryptColumns, true)) || $column == 'aes_value') {
                $idEncrypt = true;
                //CAST(AES_DECRYPT(email, '/l0WIyEE`y|tr&y@') AS CHAR) AS email
                $encryptionOptions = config('encryption');
                $aesKey = $encryptionOptions['aes_key'];
                $raw = sprintf('CAST(AES_DECRYPT(%s, \'%s\') AS CHAR)', $column, $aesKey);
                $columns[$column] = new Expression($raw);
            } else {
                $columns[] = $column;
            }
        }
        if ($idEncrypt) {
            $select->columns($columns);
            $this->decryptSubSelectRaw = $select;
            return $select;
        }
    }

    /**
     *
     * @param string $password
     * @return string
     */
    protected function passwordHash($password)
    {
        if (floatval(PHP_VERSION) < 7.2) {
            $algo = PASSWORD_DEFAULT;
        } else {
            $algo =  PASSWORD_ARGON2I;
        }
        return password_hash($password, $algo);
    }
    /**
     *
     * @param array $set
     * @return mixed
     */
    public function securty($set)
    {
        if ($this instanceof AbstractTableGateway) {
            $tablegateway = $this;
        } else {
            $tablegateway = isset($this->tablegateway) ? $this->tablegateway : null ;
        }

        if (!$tablegateway || !($tablegateway instanceof AbstractTableGateway)) {
            throw new \ErrorException('物件需有 tablegateway屬性');
        }
        $encryptionColumns = [];
        $tablegateway = null;
        if (isset($tablegateway) && isset($tablegateway->encryptionColumns)) {
            $encryptionColumns = $tablegateway->encryptionColumns;
        }
        $encryptColumns = array_merge($this->defaultEncryptionColumns, $encryptionColumns);
        if (is_array($set)) {
            foreach ($encryptColumns as $encrypt) {
                if (empty($set[$encrypt])) {
                    continue;
                }
                if (is_string($set[$encrypt]) && $set[$encrypt]) {
                    $set[$encrypt] = $this->aesCrypter->encrypt($set[$encrypt]);
                }
            }
        } else {
            foreach ($encryptColumns as $encrypt) {
                if (empty($set->{$encrypt})) {
                    continue;
                }
                if (is_string($set->{$encrypt}) && $set->{$encrypt}) {
                    $set->{$encrypt} = $this->aesCrypter->encrypt($set->{$encrypt});
                }
            }
        }
        unset($encryptColumns);

        // password加密，不可逆
        if (is_array($set)) {
            if (isset($set[$this->defaultPasswordColumn])) {
                $confirm_key = $this->defaultPasswordColumn . '_confirm';
                if (isset($set[$confirm_key])) {
                    unset($set[$confirm_key]);
                }

                if ($set[$this->defaultPasswordColumn]) {
                    $salt = '';
                    if (empty($set['salt'])) {
                        $encryptionOptions = config('encryption');
                        $salt = \Laminas\Math\Rand::getString(8, $encryptionOptions['charlist']);
                    } else {
                        $salt = $set['salt'];
                    }
                    $password = $set[$this->defaultPasswordColumn];
                    $set['salt'] = $salt;
                    $password = str_replace($salt, '', $password);
                    $set[$this->defaultPasswordColumn] = $this->passwordHash($password.$salt);
                } else {
                    unset($set[$this->defaultPasswordColumn]);
                }
            }
            if (isset($set["temporay_password"])) {
                if (is_string($set["temporay_password"])) {
                    if (!$set["temporay_password"]) {
                        $set["temporay_password"] = new Expression("null");
                    } else {
                        $temporay_password = $set["temporay_password"];
                        $temporay_password = $this->passwordHash($temporay_password);
                        $set["temporay_password"] = $temporay_password;
                    }
                }
            }
        }
        return $set;
    }

    public function getDecryptTable()
    {
        $decryptTable = 'decrypt_'.$this->table;
        $decryptTable = str_replace(self::$prefixTable, '', $decryptTable);
        $decryptTable = self::$prefixTable.$decryptTable;
        return $decryptTable;
    }

    protected function initCrypt()
    {
        if ($this->aesCrypter instanceof Crypter == false) {
            $encryptionOptions = config('encryption');
            $aesKey = $encryptionOptions['aes_key'];
            $this->aesCrypter = new Crypter($aesKey);
        }
    }

    public function deCryptData($data)
    {
        $this->initCrypt();
        $encryptionColumns = $this->defaultEncryptionColumns;
        if (is_string($data)) {
            return $this->aesCrypter->decrypt($data);
        }
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if (array_search($key, $encryptionColumns, true) !== false) {
                    if ($value) {
                        $value = $this->aesCrypter->decrypt($value);
                    }
                }
            }
        } else {
            foreach ($encryptionColumns as $column) {
                if (isset($data->{$column})) {
                    $value = $data->{$column};
                    $value = $this->aesCrypter->decrypt($value);
                    $data->{$column} = $value;
                }
            }
        }
        return $data;
    }
}
