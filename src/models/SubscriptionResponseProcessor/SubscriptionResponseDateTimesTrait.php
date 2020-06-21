<?php

namespace Crm\GooglePlayBillingModule\Model;

use Nette\Utils\DateTime;
use ReceiptValidator\GooglePlay\SubscriptionResponse;

trait SubscriptionResponseDateTimesTrait
{
    public function getSubscriptionStartAt(SubscriptionResponse $subscriptionResponse): DateTime
    {
        $subscriptionStartAt = DateTime::createFromFormat("U.u", $subscriptionResponse->getStartTimeMillis() / 1000);
        $subscriptionStartAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $subscriptionStartAt;
    }

    public function getSubscriptionEndAt(SubscriptionResponse $subscriptionResponse): DateTime
    {
        $subscriptionEndAt = DateTime::createFromFormat("U.u", $subscriptionResponse->getExpiryTimeMillis() / 1000);
        $subscriptionEndAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $subscriptionEndAt;
    }

    public function getUserCancellationTime(SubscriptionResponse $subscriptionResponse): DateTime
    {
        $userCancellationTime = DateTime::createFromFormat("U.u", $subscriptionResponse->getUserCancellationTimeMillis() / 1000);
        $userCancellationTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $userCancellationTime;
    }
}
