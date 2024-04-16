<?php

declare(strict_types=1);

namespace Crm\GooglePlayBillingModule\Repositories;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Tomaj\Hermes\Emitter;

class VoidedPurchaseNotificationsRepository extends Repository
{
    protected $tableName = 'google_play_voided_purchase_notifications';

    public function __construct(
        Explorer $database,
        private readonly Emitter $hermesEmitter,
    ) {
        parent::__construct($database);
    }

    public function add(
        ActiveRow $purchaseToken,
        string $orderId,
        int $productType,
        ?int $refundType,
        DateTime $eventTime,
    ) : ActiveRow {
        $voidedPurchaseNotification = $this->insert([
            'package_name' => $purchaseToken->package_name,
            'purchase_token_id' => $purchaseToken->id,
            'order_id' => $orderId,
            'product_type' => $productType,
            'refund_type' => $refundType,
            'event_time' => $eventTime,
            'created_at' => new DateTime(),
        ]);

        if ($voidedPurchaseNotification instanceof ActiveRow) {
            $this->hermesEmitter->emit(new HermesMessage(
                type: 'voided-purchase-notification-received',
                payload: [
                    'voided_purchase_notification_id' => $voidedPurchaseNotification->getPrimary(),
                ],
            ), HermesMessage::PRIORITY_HIGH);
        }

        return $voidedPurchaseNotification;
    }
}
