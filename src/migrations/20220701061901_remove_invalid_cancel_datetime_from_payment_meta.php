<?php

use Phinx\Migration\AbstractMigration;

class RemoveInvalidCancelDatetimeFromPaymentMeta extends AbstractMigration
{
    public function up()
    {
        $this->execute(<<<SQL
DELETE
    FROM `payment_meta`
WHERE
    `key` = 'cancel_datetime'
    AND `value` = '1970-01-01 01:00:00'
SQL
        );
    }

    public function down()
    {
        $this->output->writeln('Down migration is not available. Up migration fixed bug.');
    }
}
