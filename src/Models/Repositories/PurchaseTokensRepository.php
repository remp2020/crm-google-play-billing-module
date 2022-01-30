<?php

namespace Crm\GooglePlayBillingModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class PurchaseTokensRepository extends Repository
{
    protected $tableName = 'google_play_billing_purchase_tokens';

    final public function add(string $purchaseToken, string $packageName, string $subscriptionId)
    {
        $row = $this->findByPurchaseToken($purchaseToken);
        if ($row) {
            $this->update($row, [
                'purchase_token' => $purchaseToken,
                'package_name' => $packageName,
                'subscription_id' => $subscriptionId,
            ]);
            return $row;
        }
        $now = new DateTime();
        return $this->getTable()->insert([
            'purchase_token' => $purchaseToken,
            'package_name' => $packageName,
            'subscription_id' => $subscriptionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    final public function update(ActiveRow &$row, $data)
    {
        $data['updated_at'] = new DateTime();
        return parent::update($row, $data);
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
