<?php

use Phinx\Migration\AbstractMigration;

class GooglePlayBillingDeveloperNotificationsAddNewStatus extends AbstractMigration
{
    public function up()
    {
        $this->table('google_play_billing_developer_notifications')
            ->changeColumn('status', 'enum', [
                'null' => false,
                'values' => [
                    'new',
                    'processed',
                    'error',
                    'do_not_retry',
                ],
                'default' => 'new',
                'comment' => 'Internal status indicates if notification was processed by CRM.'
            ])
            ->update();
    }

    public function down()
    {
        $this->table('google_play_billing_developer_notifications')
            ->changeColumn('status', 'enum', [
                'null' => false,
                'values' => [
                    'new',
                    'processed',
                    'error',
                ],
                'default' => 'new',
                'comment' => 'Internal status indicates if notification was processed by CRM.'
            ])
            ->update();
    }
}
