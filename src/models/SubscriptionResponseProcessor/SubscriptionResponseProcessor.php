<?php

namespace Crm\GooglePlayBillingModule\Model;

use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\UsersModule\Repository\UserMetaRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Crm\UsersModule\User\UnclaimedUser;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;
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
        $accountID = null;
        if (method_exists($subscriptionResponse, 'getObfuscatedExternalAccountId')) {
            $accountID = $subscriptionResponse->getObfuscatedExternalAccountId() ?? null;
        } elseif (isset($subscriptionResponse->getRawResponse()['modelData']['obfuscatedExternalAccountId'])) {
            $accountID = $subscriptionResponse->getRawResponse()['modelData']['obfuscatedExternalAccountId'];
        } else {
            Debugger::log('Missing `getObfuscatedExternalAccountId`. You should switch to version 2.2+ of Google Play Billing library. Trying to find `obfuscatedExternalAccountId` in deprecated DeveloperPayload.', Debugger::WARNING);
            if (method_exists($subscriptionResponse, 'getDeveloperPayload')) {
                if (!empty($subscriptionResponse->getDeveloperPayload())) {
                    $developerPayload = Json::decode($subscriptionResponse->getDeveloperPayload());
                    $accountID = $developerPayload->obfuscatedExternalAccountId ?? null;
                }
            }
        }

        if ($accountID === null) {
            throw new \Exception('No user found. Unable to load obfuscatedExternalAccountId from subscription response.');
        }

        // find user via linked obfuscated account ID in user meta
        $usersWithAccountID = $this->userMetaRepository->usersWithKey(
            GooglePlayBillingModule::META_KEY_OBFUSCATED_ACCOUNT_ID,
            $accountID
        )->fetchAll();
        if ($usersWithAccountID) {
            if (count($usersWithAccountID) > 1) {
                throw new \Exception("Multiple users with same account ID [{$accountID}].");
            }
            return reset($usersWithAccountID)->user;
        }

        // no user found; create anonymous unclaimed user (Android in-app purchases have to be possible without account in CRM)
        $user = $this->unclaimedUser->createUnclaimedUser($accountID, GooglePlayBillingModule::USER_SOURCE_APP);
        $this->userMetaRepository->add(
            $user,
            GooglePlayBillingModule::META_KEY_OBFUSCATED_ACCOUNT_ID,
            $accountID
        );
        return $user;
    }
}
