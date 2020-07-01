<?php

namespace Crm\GooglePlayBillingModule\Model;

use Crm\GooglePlayBillingModule\Hermes\DoNotRetryException;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use ReceiptValidator\GooglePlay\SubscriptionResponse;

interface SubscriptionResponseProcessorInterface
{
    /**
     * getUser handles SubscriptionResponse and returns User.
     *
     * There is no standard for providing user identification via Google's SubscriptionResponse.
     * User ID can be provided as field of DeveloperPayload in SubscriptionResponse,
     * or it can be external id which can be mapped to CRM user via user_meta.
     *
     * @throws \Exception - Thrown in case user was not found.
     * @throws DoNotRetryException - Thrown in case user was not found but we don't want to retry processing.
     *
     * @return ActiveRow User
     */
    public function getUser(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification): ActiveRow;

    /**
     * getSubscriptionStartAt returns subscription's start DateTime from Google's SubscriptionResponse.
     *
     * We recommend using SubscriptionResponseDateTimesTrait which converts Google's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getSubscriptionStartAt(SubscriptionResponse $subscriptionResponse): DateTime;

    /**
     * getSubscriptionEndAt returns subscription's end DateTime from Google's SubscriptionResponse.
     *
     * We recommend using SubscriptionResponseDateTimesTrait which converts Google's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getSubscriptionEndAt(SubscriptionResponse $subscriptionResponse): DateTime;
}
