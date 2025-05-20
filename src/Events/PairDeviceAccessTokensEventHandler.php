<?php

namespace Crm\GooglePlayBillingModule\Events;

use Crm\UsersModule\Events\PairDeviceAccessTokensEvent;
use Crm\UsersModule\Models\User\UnclaimedUser;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class PairDeviceAccessTokensEventHandler extends AbstractListener
{
    private $unclaimedUser;

    public function __construct(
        UnclaimedUser $unclaimedUser,
    ) {
        $this->unclaimedUser = $unclaimedUser;
    }

    public function handle(EventInterface $event)
    {
        if (!$event instanceof PairDeviceAccessTokensEvent) {
            throw new \Exception('invalid type of event received: ' . get_class($event));
        }

        $accessToken = $event->getAccessToken();
        $deviceToken = $event->getDeviceToken();

        $user = $accessToken->user;
        if ($this->unclaimedUser->isUnclaimedUser($user)) {
            // unclaimed user cannot claim other users
            return;
        }

        foreach ($deviceToken->related('access_tokens') as $deviceAccessToken) {
            if ($this->unclaimedUser->isUnclaimedUser($deviceAccessToken->user)) {
                $this->unclaimedUser->claimUser($deviceAccessToken->user, $user, $deviceToken);
            }
        }
    }
}
