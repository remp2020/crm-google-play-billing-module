<?php

namespace Crm\GooglePlayBillingModule\Repository;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Repository;
use Crm\ApplicationModule\Repository\AuditLogRepository;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;
use Tomaj\Hermes\Emitter;

class DeveloperNotificationsRepository extends Repository
{
    protected $tableName = 'google_play_billing_developer_notifications';

    private $hermesEmitter;

    const STATUS_NEW = 'new';
    const STATUS_PROCESSED = 'processed';
    const STATUS_ERROR = 'error';
    const STATUS_DO_NOT_RETRY = 'do_not_retry';

    const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_PROCESSED,
        self::STATUS_ERROR
    ];

    const NOTIFICATION_TYPE_SUBSCRIPTION_RECOVERED = 1;
    const NOTIFICATION_TYPE_SUBSCRIPTION_RENEWED = 2;
    const NOTIFICATION_TYPE_SUBSCRIPTION_CANCELED = 3;
    const NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED = 4;
    const NOTIFICATION_TYPE_SUBSCRIPTION_ON_HOLD = 5;
    const NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD = 6;
    const NOTIFICATION_TYPE_SUBSCRIPTION_RESTARTED = 7;
    const NOTIFICATION_TYPE_SUBSCRIPTION_PRICE_CHANGE_CONFIRMED = 8;
    const NOTIFICATION_TYPE_SUBSCRIPTION_DEFERRED = 9;
    const NOTIFICATION_TYPE_SUBSCRIPTION_PAUSED = 10;
    const NOTIFICATION_TYPE_SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED = 11;
    const NOTIFICATION_TYPE_SUBSCRIPTION_REVOKED = 12;
    const NOTIFICATION_TYPE_SUBSCRIPTION_EXPIRED = 13;

    const NOTIFICATION_TYPES = [
        self::NOTIFICATION_TYPE_SUBSCRIPTION_RECOVERED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_RENEWED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_CANCELED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_ON_HOLD,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_RESTARTED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_PRICE_CHANGE_CONFIRMED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_DEFERRED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_PAUSED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_REVOKED,
        self::NOTIFICATION_TYPE_SUBSCRIPTION_EXPIRED
    ];

    public function __construct(
        Explorer $database,
        AuditLogRepository $auditLogRepository,
        Emitter $hermesEmitter
    ) {
        parent::__construct($database);

        $this->auditLogRepository = $auditLogRepository;
        $this->hermesEmitter = $hermesEmitter;
    }

    /**
     * @return bool|int|IRow
     */
    public function add(
        IRow $purchaseToken,
        DateTime $eventTime,
        int $notificationType,
        string $status = self::STATUS_NEW
    ) {
        if (!in_array($notificationType, self::NOTIFICATION_TYPES)) {
            throw new \Exception("Incorrect notification type provided. Got [{$notificationType}].");
        }

        $developerNotification = $this->insert([
            'purchase_token_id' => $purchaseToken->id,
            'package_name' => $purchaseToken->package_name,
            'purchase_token' => $purchaseToken->purchase_token,
            'subscription_id' => $purchaseToken->subscription_id,
            'event_time' => $eventTime,
            'notification_type' => $notificationType,
            'status' => $status,
            'created_at' => new DateTime(),
            'modified_at' => new DateTime(),
        ]);

        if ($developerNotification instanceof IRow) {
            $this->hermesEmitter->emit(new HermesMessage(
                'developer-notification-received',
                [
                    'developer_notification_id' => $developerNotification->getPrimary(),
                ],
                null,
                null,
                DateTime::from('+15 seconds')->getTimestamp() // give verify purchase API some headroom
            ), HermesMessage::PRIORITY_HIGH);
        }

        return $developerNotification;
    }

    public function updateStatus(ActiveRow $developerNotification, string $status)
    {
        return $this->update($developerNotification, [
            'status' => $status,
            'modified_at' => new DateTime(),
        ]);
    }
}
