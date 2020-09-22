<?php

use Phinx\Migration\AbstractMigration;

class GooglePlayPurchaseTokensDates extends AbstractMigration
{
    public function up()
    {
        $this->table('google_play_billing_purchase_tokens')
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->update();

        $this->execute('UPDATE google_play_billing_purchase_tokens SET created_at = NOW(), updated_at = NOW()');

        $this->table('google_play_billing_purchase_tokens')
            ->changeColumn('created_at', 'datetime', ['null' => false])
            ->changeColumn('updated_at', 'datetime', ['null' => false])
            ->update();
    }

    public function down()
    {
        $this->table('google_play_billing_purchase_tokens')
            ->removeColumn('created_at')
            ->removeColumn('updated_at')
            ->update();
    }
}
