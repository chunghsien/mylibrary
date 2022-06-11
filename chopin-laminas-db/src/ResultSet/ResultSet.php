<?php

namespace Chopin\LaminasDb\ResultSet;

use Laminas\Db\ResultSet\ResultSet as LaminasResultSet;

class ResultSet extends LaminasResultSet implements \JsonSerializable
{
    /**
     *
     * {@inheritdoc}
     * @see \JsonSerializable::jsonSerialize()
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
