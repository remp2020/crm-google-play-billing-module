<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSubscriptionPurchase extends AbstractMigration
{
    public function change(): void
    {
        $this->table('google_play_billing_developer_notifications')
            ->addColumn('subscription_purchase', 'json', ['null' => true])
            ->update();
    }
}
