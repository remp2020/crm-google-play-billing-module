<?php

namespace Crm\GooglePlayBillingModule\Model;

use Crm\UsersModule\Repository\UsersRepository;
use Nette\Database\Table\ActiveRow;
use ReceiptValidator\GooglePlay\SubscriptionResponse;

class SubscriptionResponseProcessor implements SubscriptionResponseProcessorInterface
{
    use SubscriptionResponseDateTimesTrait;

    private $usersRepository;

    public function __construct(UsersRepository $usersRepository)
    {
        $this->usersRepository = $usersRepository;
    }

    /**
     * Default implementation of `SubscriptionResponse->getUser()` returns user based on `user_id` in DeveloperPayload
     *
     * @inheritDoc
     */
    public function getUser(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification): ?ActiveRow
    {
        if (!empty($subscriptionResponse->getDeveloperPayload())) {
            $developerPayload = json_decode($subscriptionResponse->getDeveloperPayload());
            if (isset($developerPayload['user_id'])) {
                $user = $this->usersRepository->find($developerPayload['user_id']);
                if ($user !== false) {
                    return $user;
                }
            }
        }
        return null;
    }
}
