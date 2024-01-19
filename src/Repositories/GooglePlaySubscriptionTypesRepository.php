<?php

namespace Crm\GooglePlayBillingModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;

class GooglePlaySubscriptionTypesRepository extends Repository
{
    protected $tableName = 'google_play_billing_subscription_types';

    final public function add(string $googlePlaySubscriptionId, ActiveRow $subscriptionType, ?int $offerPeriods = null)
    {
        return $this->getTable()->insert([
            'subscription_id' => $googlePlaySubscriptionId,
            'subscription_type_id' => $subscriptionType->id,
            'offer_periods' => $offerPeriods,
        ]);
    }

    final public function findByGooglePlaySubscriptionId(string $googlePlaySubscriptionId): ?ActiveRow
    {
        $row = $this->findBy('subscription_id', $googlePlaySubscriptionId);
        if (!$row) {
            return null;
        }
        return $row;
    }
}
