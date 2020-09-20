<?php

namespace Crm\GooglePlayBillingModule\Model;

use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\UsersModule\Repository\UserMetaRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Crm\UsersModule\User\UnclaimedUser;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;
use Nette\Utils\Random;
use ReceiptValidator\GooglePlay\SubscriptionResponse;
use Tracy\Debugger;

class SubscriptionResponseProcessor implements SubscriptionResponseProcessorInterface
{
    use SubscriptionResponseDateTimesTrait;

    private $unclaimedUser;

    private $userMetaRepository;

    private $usersRepository;

    public function __construct(
        UnclaimedUser $unclaimedUser,
        UserMetaRepository $userMetaRepository,
        UsersRepository $usersRepository
    ) {
        $this->unclaimedUser = $unclaimedUser;
        $this->userMetaRepository = $userMetaRepository;
        $this->usersRepository = $usersRepository;
    }

    /**
     * Default implementation of `SubscriptionResponse->getUser()` returns user based on obfuscatedExternalAccountId.
     *
     * Search for a user using `obfuscatedExternalAccountId` via:
     * - `SubscriptionResponse::getObfuscatedExternalAccountId()` (Google Play Billing, version >=2.2),
     * - field `obfuscatedExternalAccountId` in `SubscriptionResponse::getDeveloperPayload()` (Google Play Billing, version <2.2).
     *
     * If no user was found, anonymous unclaimed user is created
     * and used to process Android's in-app purchases without registered user.
     *
     * @throws \Exception - If no user was found or multiple users have same purchase token.
     */
    public function getUser(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification): ActiveRow
    {
        $userId = null;

        if (method_exists($subscriptionResponse, 'getObfuscatedExternalAccountId')) {
            $userId = $subscriptionResponse->getObfuscatedExternalAccountId() ?? null;
        } elseif (isset($subscriptionResponse->getRawResponse()['modelData']['obfuscatedExternalAccountId'])) {
            $userId = $subscriptionResponse->getRawResponse()['modelData']['obfuscatedExternalAccountId'];
        } else {
            Debugger::log('Missing `getObfuscatedExternalAccountId`. You should switch to version 2.2+ of Google Play Billing library. Trying to find `obfuscatedExternalAccountId` in deprecated DeveloperPayload.', Debugger::WARNING);
            if (method_exists($subscriptionResponse, 'getDeveloperPayload')) {
                if (!empty($subscriptionResponse->getDeveloperPayload())) {
                    $developerPayload = Json::decode($subscriptionResponse->getDeveloperPayload());
                    $userId = $developerPayload->obfuscatedExternalAccountId ?? null;
                }
            }
        }

        $user = $this->usersRepository->find($userId);
        if ($user) {
            return $user;
        }

        // no user found; create anonymous unclaimed user (Android in-app purchases have to be possible without account in CRM)
        $rand = Random::generate();
        $user = $this->unclaimedUser->createUnclaimedUser("google_play_billing_{$rand}", GooglePlayBillingModule::USER_SOURCE_APP);
        return $user;
    }
}
