<?php

use Phinx\Migration\AbstractMigration;

class FixMissingPaymentsPaidAtDatesGoogleGateway extends AbstractMigration
{
    public function up()
    {
        $sql = <<<SQL
UPDATE `payments`
INNER JOIN `payment_gateways`
  ON `payments`.`payment_gateway_id` = `payment_gateways`.`id` AND `payment_gateways`.`code` = 'google_play_billing'
SET `payments`.`paid_at` = COALESCE(`payments`.`subscription_start_at`, `payments`.`created_at`)
WHERE
  `payments`.`status` = 'prepaid'
  AND `payments`.`paid_at` IS NULL
;
SQL;

        $this->execute($sql);
    }

    public function down()
    {
        $this->output->writeln('Down migration is risky. See migration class for details. Nothing done.');
        return;

        // removing `paid_at` from payments is not recommended; run this query only if you know what you are doing
        $sql = <<<SQL
UPDATE `payments`
INNER JOIN `payment_gateways`
  ON `payments`.`payment_gateway_id` = `payment_gateways`.`id` AND `payment_gateways`.`code` = 'google_play_billing'
SET `payments`.`paid_at` = NULL
WHERE
  `payments`.`status` = 'prepaid'
;
SQL;

        $this->execute($sql);
    }
}
