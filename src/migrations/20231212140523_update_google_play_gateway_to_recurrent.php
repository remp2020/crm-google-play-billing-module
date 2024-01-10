<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpdateGooglePlayGatewayToRecurrent extends AbstractMigration
{

    public function up()
    {
        $sql = <<<SQL
UPDATE payment_gateways
SET is_recurrent = 1, modified_at = NOW()
WHERE code = 'google_play_billing'
SQL;
        $this->execute($sql);
    }

    public function down()
    {
        $sql = <<<SQL
UPDATE payment_gateways
SET is_recurrent = 0, modified_at = NOW()
WHERE code = 'google_play_billing'
SQL;
        $this->execute($sql);
    }
}
