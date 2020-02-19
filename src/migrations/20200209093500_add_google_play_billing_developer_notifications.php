<?php

use Phinx\Migration\AbstractMigration;

class AddGooglePlayBillingDeveloperNotifications extends AbstractMigration
{
    public function change()
    {
        $this->table('google_play_billing_developer_notifications')
            ->addColumn('package_name', 'string', ['null' => false, 'comment' => 'The package name of the application that this notification relates to.'])
            ->addColumn('purchase_token', 'string', ['null' => false, 'comment' => 'The token provided to the user’s device when the subscription was purchased.'])
            ->addColumn('subscription_id', 'string', ['null' => false, 'comment' => 'The purchased Google subscription ID (equivalent of CRM\'s subscription_type_code).'])
            ->addColumn('event_time', 'datetime', ['null' => false, 'comment' => 'Datetime of when event occurred.'])
            ->addColumn('notification_type', 'integer', ['null' => false, 'comment' => 'See Google developer documentation: https://developer.android.com/google/play/billing/realtime_developer_notifications.html#json_specification'])
            ->addColumn('status', 'enum', [
                'null' => false,
                'values' => [
                    'new',
                    'processed',
                    'error'
                ],
                'default' => 'new',
                'comment' => 'Internal status indicates if notification was processed by CRM.'
            ])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('modified_at', 'datetime', ['null' => false])
            ->addIndex(['purchase_token'])
            ->addIndex(['subscription_id'])
            ->addIndex(['status'])
            ->addIndex(['created_at'])
            ->create();
    }
}
