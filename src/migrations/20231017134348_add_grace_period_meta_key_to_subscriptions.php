<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddGracePeriodMetaKeyToSubscriptions extends AbstractMigration
{
    public function up()
    {
        $sql = <<<SQL
SELECT id
FROM subscriptions
WHERE note = 'GooglePlay Grace Period'
;
SQL;

        foreach ($this->fetchAll($sql) as $row) {
            $this->execute(<<<SQL
INSERT IGNORE INTO subscriptions_meta(subscription_id, `key`, value, created_at, updated_at)
VALUES ({$row['id']}, 'google_play_billing_grace_period_subscription', TRUE, NOW(), NOW());
SQL
            );
        }
    }

    public function down()
    {
        $this->output->writeln('Down migration is risky. See migration class for details. Nothing done.');
        return;

        $sql = <<<SQL
DELETE FROM `subscriptions_meta`
WHERE `key` = 'google_play_billing_grace_period_subscription'
;
SQL;

        $this->execute($sql);
    }
}
