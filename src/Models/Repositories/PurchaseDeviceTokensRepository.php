<?php

namespace Crm\GooglePlayBillingModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;

class PurchaseDeviceTokensRepository extends Repository
{
    protected $tableName = 'google_play_billing_purchase_device_tokens';

    final public function add(IRow $purchaseToken, IRow $deviceToken)
    {
        $payload = [
            'purchase_token_id' => $purchaseToken->id,
            'device_token_id' => $deviceToken->id,
        ];

        $row = $this->getTable()->where($payload)->fetch();
        if ($row) {
            return $row;
        }
        return $this->getTable()->insert($payload);
    }
}
