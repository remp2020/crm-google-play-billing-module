<?php

namespace Crm\GooglePlayBillingModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Api\JsonValidationTrait;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Hermes\DeveloperNotificationReceivedHandler;
use Crm\GooglePlayBillingModule\Model\GooglePlayValidatorFactory;
use Crm\GooglePlayBillingModule\Model\SubscriptionResponseProcessorInterface;
use Crm\GooglePlayBillingModule\Repository\GooglePlaySubscriptionTypesRepository;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\UsersModule\Auth\UserTokenAuthorization;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repository\AccessTokensRepository;
use Crm\UsersModule\Repository\UserMetaRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Crm\UsersModule\User\UnclaimedUser;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Nette\Utils\Json;
use Nette\Utils\Random;
use ReceiptValidator\GooglePlay\Acknowledger;
use ReceiptValidator\GooglePlay\SubscriptionResponse;
use ReceiptValidator\GooglePlay\Validator;
use Tracy\Debugger;

class VerifyPurchaseApiHandler extends ApiHandler
{
    use JsonValidationTrait;

    private $accessTokensRepository;
    private $applicationConfig;
    private $developerNotificationReceivedHandler;
    private $googlePlayValidatorFactory;
    private $googlePlaySubscriptionTypesRepository;
    private $paymentGatewaysRepository;
    private $paymentMetaRepository;
    private $paymentsRepository;
    private $subscriptionResponseProcessor;
    private $unclaimedUser;
    private $userMetaRepository;
    private $usersRepository;
    private $deviceTokensRepository;

    /** @var string */
    private $packageName;
    /** @var Validator */
    private $googlePlayValidator;

    public function __construct(
        AccessTokensRepository $accessTokensRepository,
        ApplicationConfig $applicationConfig,
        DeveloperNotificationReceivedHandler $developerNotificationReceivedHandler,
        GooglePlayValidatorFactory $googlePlayValidatorFactory,
        GooglePlaySubscriptionTypesRepository $googlePlaySubscriptionTypesRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentMetaRepository $paymentMetaRepository,
        PaymentsRepository $paymentsRepository,
        SubscriptionResponseProcessorInterface $subscriptionResponseProcessor,
        UnclaimedUser $unclaimedUser,
        UserMetaRepository $userMetaRepository,
        UsersRepository $usersRepository,
        DeviceTokensRepository $deviceTokensRepository
    ) {
        $this->accessTokensRepository = $accessTokensRepository;
        $this->applicationConfig = $applicationConfig;
        $this->developerNotificationReceivedHandler = $developerNotificationReceivedHandler;
        $this->googlePlayValidatorFactory = $googlePlayValidatorFactory;
        $this->googlePlaySubscriptionTypesRepository = $googlePlaySubscriptionTypesRepository;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->subscriptionResponseProcessor = $subscriptionResponseProcessor;
        $this->unclaimedUser = $unclaimedUser;
        $this->userMetaRepository = $userMetaRepository;
        $this->usersRepository = $usersRepository;
        $this->deviceTokensRepository = $deviceTokensRepository;
    }

    public function params()
    {
        return [];
    }

    public function handle(ApiAuthorizationInterface $authorization)
    {
        if (!($authorization instanceof UserTokenAuthorization)) {
            throw new \Exception("Wrong authorization service used. Should be 'UserTokenAuthorization'");
        }

        // validate input
        $validator = $this->validateInput(__DIR__ . '/verify-purchase.schema.json');
        if ($validator->hasErrorResponse()) {
            return $validator->getErrorResponse();
        }
        $payload = $validator->getParsedObject();

        // TODO: validate multiple receipts (purchase restore)
        $purchaseSubscription = reset($payload->purchaseSubscriptions);

        // verify receipt in Google system
        $subscriptionOrResponse =  $this->verifyGooglePlayBillingPurchaseSubscription($purchaseSubscription);
        if ($subscriptionOrResponse instanceof JsonResponse) {
            return $subscriptionOrResponse;
        }
        /** @var SubscriptionResponse $subscriptionResponse */
        $subscriptionResponse = $subscriptionOrResponse;

        // load user (from token or receipt)
        $userOrResponse = $this->getUser($authorization, $subscriptionResponse);
        if ($userOrResponse instanceof JsonResponse) {
            return $userOrResponse;
        }
        /** @var ActiveRow $user */
        $user = $userOrResponse;

        return $this->createPayment($user, $subscriptionResponse, $purchaseSubscription->purchaseToken, $purchaseSubscription->productId, $payload->article_id ?? null);
    }

    /**
     * @return JsonResponse|SubscriptionResponse - Return validated subscription (SubscriptionResponse) or JsonResponse which should be returned by API.
     */
    private function verifyGooglePlayBillingPurchaseSubscription($purchaseSubscription)
    {
        try {
            $this->googlePlayValidator = $this->googlePlayValidatorFactory->create();
            $gSubscription = $this->googlePlayValidator
                ->setPackageName($purchaseSubscription->packageName)
                ->setPurchaseToken($purchaseSubscription->purchaseToken)
                ->setProductId($purchaseSubscription->productId)
                ->validateSubscription();
        } catch (\Exception | \GuzzleHttp\Exception\GuzzleException | \Google_Exception $e) {
            Debugger::log("Unable to validate Google Play payment. Error: [{$e->getMessage()}]", Debugger::ERROR);

            $response = new JsonResponse([
                'status' => 'error',
                'code' => 'unable_to_validate',
                'message' => 'Unable to validate Google Play payment.',
            ]);
            $response->setHttpCode(Response::S503_SERVICE_UNAVAILABLE);
            return $response;
        }

        // validate google subscription payment state
        if (!in_array($gSubscription->getPaymentState(), [
            GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_CONFIRMED,
            GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_FREE_TRIAL
        ])) {
            $response = new JsonResponse([
                'status' => 'error',
                'code' => 'payment_not_confirmed',
                'message' => 'Payment is not confirmed by Google yet.',
            ]);
            $response->setHttpCode(Response::S400_BAD_REQUEST);
            return $response;
        }

        return $gSubscription;
    }

    private function createPayment(ActiveRow $user, SubscriptionResponse $subscriptionResponse, string $purchaseToken, string $googleProductId, ?string $articleID): JsonResponse
    {
        // validate subscription type
        $googlePlaySubscriptionType = $this->googlePlaySubscriptionTypesRepository->findByGooglePlaySubscriptionId($googleProductId);
        if (!$googlePlaySubscriptionType) {
            Debugger::log(
                "Unable to find SubscriptionType for Google Play product ID [{$googleProductId}].",
                Debugger::ERROR
            );
            $response = new JsonResponse([
                'status' => 'error',
                'code' => 'missing_subscription_type',
                'message' => 'Unable to find SubscriptionType for Google Play product ID.',
            ]);
            $response->setHttpCode(Response::S500_INTERNAL_SERVER_ERROR);
            return $response;
        }
        $subscriptionType = $googlePlaySubscriptionType->subscription_type;

        // validate payment gateway
        $paymentGatewayCode = GooglePlayBilling::GATEWAY_CODE;
        $paymentGateway = $this->paymentGatewaysRepository->findByCode($paymentGatewayCode);
        if (!$paymentGateway) {
            Debugger::log(
                "Unable to find PaymentGateway with code [{$paymentGatewayCode}]. Is GooglePlayBillingModule enabled?",
                Debugger::ERROR
            );
            $response = new JsonResponse([
                'status' => 'error',
                'code' => 'internal_server_error',
                'message' => "Unable to find PaymentGateway with code [{$paymentGatewayCode}].",
            ]);
            $response->setHttpCode(Response::S500_INTERNAL_SERVER_ERROR);
            return $response;
        }

        // check if payment with this purchase token already exists
        $paymentWithPurchaseToken = $this->paymentMetaRepository->findByMeta(
            GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
            $purchaseToken
        );
        if ($paymentWithPurchaseToken) {
            // payment is created internally; we can confirm it in Google
            if (!$subscriptionResponse->isAcknowledged()) {
                $this->acknowledge($googleProductId, $purchaseToken);
            }

            $response = new JsonResponse([
                'status' => 'ok',
                'code' => 'success_already_created',
                'message' => "Google Play purchase verified (transaction was already processed).",
            ]);
            $response->setHttpCode(Response::S200_OK);
            return $response;
        }

        $metas = [
            GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN => $purchaseToken,
            GooglePlayBillingModule::META_KEY_ORDER_ID => $subscriptionResponse->getRawResponse()->getOrderId(),
        ];
        if ($articleID) {
            $metas['article_id'] = $articleID;
        }
        $subscriptionStartAt = $this->subscriptionResponseProcessor->getSubscriptionStartAt($subscriptionResponse);
        $subscriptionEndAt = $this->subscriptionResponseProcessor->getSubscriptionEndAt($subscriptionResponse);

        // handle google trial subscription
        if ($subscriptionResponse->getPaymentState() === GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_FREE_TRIAL) {
            $this->developerNotificationReceivedHandler->createGoogleFreeTrialSubscription(
                $user,
                $subscriptionType,
                $subscriptionStartAt,
                $subscriptionEndAt,
                $metas
            );

            // payment is created internally; we can confirm it in Google
            if (!$subscriptionResponse->isAcknowledged()) {
                $this->acknowledge($googleProductId, $purchaseToken);
            }

            $response = new JsonResponse([
                'status' => 'ok',
                'code' => 'success_trial',
                'message' => "Google Play purchase verified (trial created).",
            ]);
            $response->setHttpCode(Response::S200_OK);
            return $response;
        }

        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));

        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $paymentGateway,
            $user,
            $paymentItemContainer,
            '',
            $subscriptionType->price,
            $subscriptionStartAt,
            $subscriptionEndAt,
            null,
            0,
            null,
            null,
            null,
            false,
            $metas
        );

        $payment = $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PREPAID);

        // payment is created internally; we can confirm it in Google
        if (!$subscriptionResponse->isAcknowledged()) {
            $this->acknowledge($googleProductId, $purchaseToken);
        }

        $response = new JsonResponse([
            'status' => 'ok',
            'code' => 'success',
            'message' => "Google Play purchase verified.",
        ]);
        $response->setHttpCode(Response::S200_OK);
        return $response;
    }

    /**
     * @return ActiveRow|JsonResponse - Return $user (ActiveRow) or JsonResponse which should be returnd by API.
     */
    private function getUser(UserTokenAuthorization $authorization, SubscriptionResponse $subscriptionResponse)
    {
        $user = null;

        // use authorized user if there is only one logged/claimed user or if there is only one unclaimed user
        $unclaimedUsers = [];
        $claimedUsers = [];
        foreach ($authorization->getAuthorizedUsers() as $authorizedUser) {
            if ($this->userMetaRepository->userMetaValueByKey($authorizedUser, UnclaimedUser::META_KEY)) {
                $unclaimedUsers[] = $authorizedUser;
            } else {
                $claimedUsers[] = $authorizedUser;
            }
        }

        if (count($claimedUsers) === 1) {
            $userFromToken = reset($claimedUsers);
        } elseif (count($unclaimedUsers) === 1) {
            $userFromToken = reset($unclaimedUsers);
        } else {
            // no user fits criteria; user will be created as unclaimed
            $userFromToken = null;
        }

        $userFromSubscriptionResponse = $this->getUserFromSubscriptionResponse($subscriptionResponse);
        if (!$userFromSubscriptionResponse) {
            $user = $userFromToken;
        } else {
            if ($userFromToken === null) {
                $user = $userFromSubscriptionResponse;
            } else {
                if ($userFromToken->id !== $userFromSubscriptionResponse->id) {
                    // find device token needed for claiming users
                    $deviceToken = null;
                    foreach ($authorization->getAccessTokens() as $accessToken) {
                        if (isset($accessToken->device_token)) {
                            $deviceToken = $accessToken->device_token;
                            break;
                        }
                    }
                    if ($deviceToken) {
                        // existing user with linked purchase is unclaimed? claim it
                        if ($this->userMetaRepository->userMetaValueByKey($userFromSubscriptionResponse, UnclaimedUser::META_KEY)) {
                            $this->unclaimedUser->claimUser($userFromSubscriptionResponse, $userFromToken, $deviceToken);
                            $user = $userFromToken;
                        } elseif ($this->userMetaRepository->userMetaValueByKey($userFromToken, UnclaimedUser::META_KEY)) {
                            $this->unclaimedUser->claimUser($userFromToken, $userFromSubscriptionResponse, $deviceToken);
                            $user = $userFromSubscriptionResponse;
                        }
                    } else {
                        $response = new JsonResponse([
                            'status' => 'error',
                            'code' => 'purchase_already_owned',
                            'message' => "Unable to verify purchase for user [$userFromToken->public_name]. This or previous purchase already owned by other user.",
                        ]);
                        $response->setHttpCode(Response::S400_BAD_REQUEST);
                        return $response;
                    }
                }
            }
        }

        // create unclaimed user if none was provided by authorization
        if ($user === null) {
            $rand = Random::generate();
            $user = $this->unclaimedUser->createUnclaimedUser(
                "google_play_billing_{$rand}",
                GooglePlayBillingModule::USER_SOURCE_APP
            );
        }

        $this->pairUserWithAuthorizedToken($authorization, $user);
        return $user;
    }

    /**
     * Search for a user using `obfuscatedExternalAccountId` via:
     *   - `SubscriptionResponse::getObfuscatedExternalAccountId()`
     *      (Google Play Billing, version >= 2.2),
     *   - field `obfuscatedExternalAccountId` in `SubscriptionResponse::getDeveloperPayload()`
     *      (Google Play Billing, version < 2.2).
     *
     * @todo merge this with \Crm\GooglePlayBillingModule\Model\SubscriptionResponseProcessor::getUser()
     *
     * @throws \Exception - If no user was found or multiple users have same purchase token.
     */
    public function getUserFromSubscriptionResponse(SubscriptionResponse $subscriptionResponse): ?ActiveRow
    {
        $userId = $this->getUserIdFromSubscriptionResponse($subscriptionResponse);
        if (!$userId) {
            return null;
        }
        return $this->usersRepository->find($userId);
    }

    private function getUserIdFromSubscriptionResponse(SubscriptionResponse $subscriptionResponse): ?string
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

        return $userId;
    }

    private function acknowledge(string $googleProductId, string $purchaseToken)
    {
        $googleAcknowledger = new Acknowledger(
            $this->googlePlayValidator->getPublisherService(),
            $this->packageName,
            $googleProductId,
            $purchaseToken
        );
        $googleAcknowledger->acknowledge();
    }

    private function pairUserWithAuthorizedToken(UserTokenAuthorization $authorization, $user)
    {
        // pair new unclaimed user with device token from authorization
        $deviceToken = null;
        foreach ($authorization->getAccessTokens() as $accessToken) {
            if (isset($accessToken->device_token)) {
                $deviceToken = $accessToken->device_token;
                break;
            }
        }

        if (!$deviceToken) {
            // try to read the token from authorized data (if handler was authorized directly with device token)
            $token = $authorization->getAuthorizedData()['token'] ?? null;
            if (isset($token->token)) {
                // just make sure it's actual and valid device token
                $deviceToken = $this->deviceTokensRepository->findByToken($token->token);
            }
        }

        if ($deviceToken) {
            $unclaimedUserAccessToken = $this->accessTokensRepository->add($user, 3, GooglePlayBillingModule::USER_SOURCE_APP);
            $this->accessTokensRepository->pairWithDeviceToken($unclaimedUserAccessToken, $deviceToken);
        } else {
            // TODO: shouldn't we throw an exception here? or return special error to the app?
            Debugger::log("No device token found. Unable to pair new unclaimed user [{$user->id}].", Debugger::ERROR);
        }
    }
}
