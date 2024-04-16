<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateGooglePlayVoidedPurchaseNotifications extends AbstractMigration
{
    public function change(): void
    {
        $this->table('google_play_voided_purchase_notifications')
            ->addColumn('package_name', 'string', ['null' => false, 'comment' => 'The package name of the application that this notification relates to.'])
            ->addColumn('purchase_token_id', 'integer', ['null' => false, 'after' => 'id', 'comment' => 'The token provided to the user’s device by Google when the subscription was purchased.'])
            ->addColumn('order_id', 'string', ['null' => false])
            ->addColumn('product_type', 'integer', ['null' => false])
            ->addColumn('refund_type', 'integer', ['null' => true])
            ->addColumn('event_time', 'datetime', ['null' => false, 'comment' => 'Datetime of when event occurred.'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addForeignKey('purchase_token_id', 'google_play_billing_purchase_tokens')
            ->create();
    }
}
