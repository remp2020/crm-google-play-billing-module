<?php

namespace Crm\GooglePlayBillingModule\Models\SubscriptionResponseProcessor;

use Nette\Utils\DateTime;
use ReceiptValidator\GooglePlay\SubscriptionResponse;

trait SubscriptionResponseDateTimesTrait
{
    public function getSubscriptionStartAt(SubscriptionResponse $subscriptionResponse): DateTime
    {
        $startTimeMicros = sprintf("%.6f", (int) $subscriptionResponse->getStartTimeMillis() / 1000);
        $subscriptionStartAt = DateTime::createFromFormat("U.u", $startTimeMicros);
        $subscriptionStartAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $subscriptionStartAt;
    }

    public function getSubscriptionEndAt(SubscriptionResponse $subscriptionResponse): DateTime
    {
        $expiryTimeMicros = sprintf("%.6f", (int) $subscriptionResponse->getExpiryTimeMillis() / 1000);
        $subscriptionEndAt = DateTime::createFromFormat("U.u", $expiryTimeMicros);
        $subscriptionEndAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $subscriptionEndAt;
    }

    public function getUserCancellationTime(SubscriptionResponse $subscriptionResponse): ?DateTime
    {
        if ($subscriptionResponse->getUserCancellationTimeMillis() === null) {
            return null;
        }

        $userCancellationTimeMicros = sprintf("%.6f", $subscriptionResponse->getUserCancellationTimeMillis() / 1000);
        $userCancellationTime = DateTime::createFromFormat("U.u", $userCancellationTimeMicros);
        $userCancellationTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $userCancellationTime;
    }
}
