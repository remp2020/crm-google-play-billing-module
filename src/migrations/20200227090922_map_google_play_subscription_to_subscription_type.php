<?php

use Phinx\Migration\AbstractMigration;

class MapGooglePlaySubscriptionToSubscriptionType extends AbstractMigration
{
    public function change()
    {
        $this->table('google_play_billing_subscription_types')
            ->addColumn('subscription_id', 'string', [
                'null' => false,
                'comment' => 'Google Play Subscription ID used to identify type of subscription.'
            ])
            ->addColumn('subscription_type_id', 'integer', [
                'null' => false,
                'comment' => 'CRM subscription type'
            ])
            ->addForeignKey('subscription_type_id', 'subscription_types')
            ->addIndex(['subscription_id'], array('unique' => true))
            ->create();
    }
}
