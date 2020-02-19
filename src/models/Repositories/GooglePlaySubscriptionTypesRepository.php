<?php

namespace Crm\GooglePlayBillingModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\ActiveRow;

class GooglePlaySubscriptionTypesRepository extends Repository
{
    protected $tableName = 'google_play_billing_subscription_types';

    /**
     * @param string $subscriptionId Identification of Google Play Billing subscription type
     */
    public function findBySubscriptionId(string $subscriptionId): ?ActiveRow
    {
        return $this->findBy('subscription_id', $subscriptionId) ?? null;
    }
}
