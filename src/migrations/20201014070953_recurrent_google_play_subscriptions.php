<?php

use Phinx\Migration\AbstractMigration;

class RecurrentGooglePlaySubscriptions extends AbstractMigration
{
    public function up()
    {
        $sql = <<<SQL
UPDATE subscriptions
INNER JOIN payments ON payments.subscription_id = subscriptions.id
INNER JOIN payment_gateways ON payments.payment_gateway_id = payment_gateways.id
  AND payment_gateways.code = 'google_play_billing'
SET subscriptions.is_recurrent = 1
SQL;
        $this->execute($sql);
    }

    public function down()
    {
        $this->output->writeln('Down migration is not available.');
    }
}
