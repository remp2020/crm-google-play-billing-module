<?php

namespace Crm\GooglePlayBillingModule\Model;

use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\UsersModule\Repository\UserMetaRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;
use ReceiptValidator\GooglePlay\SubscriptionResponse;
use Tracy\Debugger;

class SubscriptionResponseProcessor implements SubscriptionResponseProcessorInterface
{
    use SubscriptionResponseDateTimesTrait;

    private $userMetaRepository;

    private $usersRepository;

    public function __construct(
        UserMetaRepository $userMetaRepository,
        UsersRepository $usersRepository
    ) {
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
     * @throws \Exception - If no user was found or multiple users have same purchase token.
     */
    public function getUser(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification): ActiveRow
    {
        $accountID = null;
        if (method_exists($subscriptionResponse, 'getObfuscatedExternalAccountId')) {
            $accountID = $subscriptionResponse->getObfuscatedExternalAccountId() ?? null;
        } else {
            Debugger::log('Missing `getObfuscatedExternalAccountId`. You should switch to version 3 of Google Play Billing library. Trying to find `obfuscatedExternalAccountId` in deprecated DeveloperPayload.', Debugger::WARNING);
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

        throw new \Exception('No user is linked to obfuscatedExternalAccountId provided by developer notification.');
    }
}
