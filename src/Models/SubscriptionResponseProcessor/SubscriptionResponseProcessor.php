<?php

namespace Crm\GooglePlayBillingModule\Models\SubscriptionResponseProcessor;

use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
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

    private $paymentMetaRepository;

    public function __construct(
        UnclaimedUser $unclaimedUser,
        UserMetaRepository $userMetaRepository,
        UsersRepository $usersRepository,
        PaymentMetaRepository $paymentMetaRepository
    ) {
        $this->unclaimedUser = $unclaimedUser;
        $this->userMetaRepository = $userMetaRepository;
        $this->usersRepository = $usersRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
    }

    /**
     * Default implementation of `SubscriptionResponse->getUser()` returns user based on obfuscatedExternalAccountId
     * and previous payments.
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

        // try to read user ID directly from the notification
        $googleResponse = $subscriptionResponse->getRawResponse();
        if (method_exists($googleResponse, 'getObfuscatedExternalAccountId')) {
            $userId = $googleResponse->getObfuscatedExternalAccountId() ?? null;
        } elseif (isset($googleResponse['modelData']['obfuscatedExternalAccountId'])) {
            $userId = $googleResponse['modelData']['obfuscatedExternalAccountId'];
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

        // find user via existing payment
        $paymentWithPurchaseToken = $this->paymentMetaRepository->findByMeta(
            GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
            $developerNotification->purchase_token
        );
        if ($paymentWithPurchaseToken) {
            return $paymentWithPurchaseToken->payment->user;
        }

        // find user via linked purchase token in user meta
        $usersWithPurchaseToken = $this->userMetaRepository->usersWithKey(
            GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
            $developerNotification->purchase_token
        )->fetchAll();
        if ($usersWithPurchaseToken) {
            if (count($usersWithPurchaseToken) > 1) {
                throw new \Exception("Multiple users with same purchase token [{$developerNotification->purchase_token}].");
            }
            return reset($usersWithPurchaseToken)->user;
        }

        // no user found; create anonymous unclaimed user (Android in-app purchases have to be possible without account in CRM)
        $rand = Random::generate();
        $user = $this->unclaimedUser->createUnclaimedUser("google_play_billing_{$rand}", GooglePlayBillingModule::USER_SOURCE_APP);
        return $user;
    }
}
