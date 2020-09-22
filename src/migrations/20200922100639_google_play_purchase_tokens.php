<?php

use Phinx\Migration\AbstractMigration;

class GooglePlayPurchaseTokens extends AbstractMigration
{
    public function up()
    {
        $this->table('google_play_billing_purchase_tokens')
            ->addColumn('purchase_token', 'string', ['null' => false])
            ->addColumn('package_name', 'string', ['null' => false])
            ->addColumn('subscription_id', 'string', ['null' => false])
            ->addIndex('purchase_token', ['unique' => true])
            ->create();

        $this->table('google_play_billing_purchase_device_tokens')
            ->addColumn('purchase_token_id', 'integer', ['null' => false])
            ->addColumn('device_token_id', 'integer', ['null' => false])
            ->addForeignKey('purchase_token_id', 'google_play_billing_purchase_tokens')
            ->addForeignKey('device_token_id', 'device_tokens')
            ->create();

        $this->table('google_play_billing_developer_notifications')
            ->addColumn('purchase_token_id', 'integer', ['null' => true, 'after' => 'id'])
            ->update();

        $sql = <<<SQL
INSERT INTO google_play_billing_purchase_tokens (purchase_token, package_name, subscription_id)
SELECT purchase_token, package_name, subscription_id FROM google_play_billing_developer_notifications;

UPDATE google_play_billing_developer_notifications
JOIN google_play_billing_purchase_tokens
  ON google_play_billing_purchase_tokens.purchase_token = google_play_billing_developer_notifications.purchase_token
SET purchase_token_id = google_play_billing_purchase_tokens.id;
SQL;
        $this->execute($sql);

        $this->table('google_play_billing_developer_notifications')
            ->changeColumn('purchase_token_id', 'integer', ['null' => false, 'after' => 'id'])
            ->update();
    }

    public function down()
    {
        $this->table('google_play_billing_developer_notifications')
            ->removeColumn('purchase_token_id')
            ->update();

        $this->table('google_play_billing_purchase_device_tokens')->drop()->update();
        $this->table('google_play_billing_purchase_tokens')->drop()->update();
    }
}
