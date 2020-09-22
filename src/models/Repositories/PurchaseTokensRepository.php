<?php

namespace Crm\GooglePlayBillingModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\ActiveRow;

class PurchaseTokensRepository extends Repository
{
    protected $tableName = 'google_play_billing_purchase_tokens';

    final public function add(string $purchaseToken, string $packageName, string $subscriptionId)
    {
        $payload = [
            'purchase_token' => $purchaseToken,
            'package_name' => $packageName,
            'subscription_id' => $subscriptionId,
        ];

        $row = $this->findByPurchaseToken($purchaseToken);
        if ($row) {
            $this->update($row, $payload);
            return $row;
        }
        return $this->getTable()->insert($payload);
    }

    final public function findByPurchaseToken(string $purchaseToken): ?ActiveRow
    {
        $row = $this->findBy('purchase_token', $purchaseToken);
        if (!$row) {
            return null;
        }
        return $row;
    }
}
