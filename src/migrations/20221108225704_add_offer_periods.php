<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddOfferPeriods extends AbstractMigration
{
    public function change(): void
    {
        $this->table('google_play_billing_subscription_types')
            ->addColumn('offer_periods', 'integer', ['null' => true])
            ->update();
    }
}
