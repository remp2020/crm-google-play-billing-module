<?php

namespace Crm\GooglePlayBillingModule\Hermes;

use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Model\GooglePlayValidatorFactory;
use Crm\GooglePlayBillingModule\Model\SubscriptionResponseProcessorInterface;
use Crm\GooglePlayBillingModule\Repository\DeveloperNotificationsRepository;
use Crm\GooglePlayBillingModule\Repository\GooglePlaySubscriptionTypesRepository;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Psr\Log\LoggerAwareTrait;
use ReceiptValidator\GooglePlay\SubscriptionResponse;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;
use Tracy\Debugger;

class DeveloperNotificationReceivedHandler implements HandlerInterface
{
    use LoggerAwareTrait;
    use RetryTrait;

    private $developerNotificationsRepository;

    private $googlePlaySubscriptionTypesRepository;

    private $googlePlayValidatorFactory;

    private $paymentGatewaysRepository;

    private $paymentsRepository;

    private $recurrentPaymentsRepository;

    private $subscriptionsRepository;

    private $subscriptionMetaRepository;

    private $subscriptionResponseProcessor;

    public function __construct(
        SubscriptionResponseProcessorInterface $subscriptionResponseProcessor,
        DeveloperNotificationsRepository $developerNotificationsRepository,
        GooglePlaySubscriptionTypesRepository $googlePlaySubscriptionTypesRepository,
        GooglePlayValidatorFactory $googlePlayValidator,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentsRepository $paymentsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionMetaRepository $subscriptionMetaRepository
    ) {
        $this->developerNotificationsRepository = $developerNotificationsRepository;
        $this->googlePlaySubscriptionTypesRepository = $googlePlaySubscriptionTypesRepository;
        $this->googlePlayValidatorFactory = $googlePlayValidator;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionMetaRepository = $subscriptionMetaRepository;
        $this->subscriptionResponseProcessor = $subscriptionResponseProcessor;
    }

    public function handle(MessageInterface $message): bool
    {
        try {
            $developerNotification = $this->developerNotification($message);
        } catch (DoNotRetryException $e) {
            // log and do not retry; there is error in hermes message
            Debugger::log("Unable to load DeveloperNotification. Error: [{$e->getMessage()}]", Debugger::ERROR);
            return false;
        }

        // validate and load google subscription
        $googlePlayValidator = $this->googlePlayValidatorFactory->create();
        $gSubscription = $googlePlayValidator
            ->setPackageName($developerNotification->package_name)
            ->setPurchaseToken($developerNotification->purchase_token)
            ->setProductId($developerNotification->subscription_id)
            ->validateSubscription();

        $this->logger->debug('RAW response from Google PurchasesSubscriptions.get:', [
            'developer_notification_id' => $developerNotification->id,
            'subscription_purchase' => $gSubscription->getRawResponse()
        ]);

        $user = $this->getUser($gSubscription, $developerNotification);

        switch ($developerNotification->notification_type) {
            // following notification types will create payment with subscription
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RENEWED:
                try {
                    $this->createPayment($gSubscription, $developerNotification, $user);
                } catch (DoNotRetryException $e) {
                    Debugger::log("Unable to create payment. Error: [{$e->getMessage()}]", Debugger::ERROR);
                    $this->developerNotificationsRepository->updateStatus(
                        $developerNotification,
                        DeveloperNotificationsRepository::STATUS_ERROR
                    );
                    return false;
                }
                break;

            // following notification types do not affect existing subscriptions or payments
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_CANCELED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_EXPIRED:
                break;

            // doesn't affect existing payments; new will be created with confirmed price
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PRICE_CHANGE_CONFIRMED:
                break;

            // following notification types do not affect existing subscriptions or payments in CRM
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RECOVERED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_ON_HOLD:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RESTARTED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_DEFERRED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PAUSED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_REVOKED:
                break;

            default:
                // new notification type? log it
                Debugger::log(
                    "Unable to handle DeveloperNotification, received new notification type: [{$developerNotification->notification_type}]",
                    Debugger::ERROR
                );
                $this->developerNotificationsRepository->updateStatus(
                    $developerNotification,
                    DeveloperNotificationsRepository::STATUS_ERROR
                );
                return false;
        }

        $this->developerNotificationsRepository->updateStatus(
            $developerNotification,
            DeveloperNotificationsRepository::STATUS_PROCESSED
        );
        return true;
    }

    private function developerNotification(MessageInterface $message): ActiveRow
    {
        $payload = $message->getPayload();
        if (!isset($payload['developer_notification_id'])) {
            throw new DoNotRetryException('`developer_notification_id` is missing from message payload.');
        }

        $developerNotification = $this->developerNotificationsRepository->find($payload['developer_notification_id']);
        if (!$developerNotification) {
            throw new DoNotRetryException("DeveloperNotification with ID [{$payload['developer_notification_id']}] is missing.");
        }

        return $developerNotification;
    }

    public function getUser(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification): ActiveRow
    {
        $user = $this->subscriptionResponseProcessor->getUser($subscriptionResponse, $developerNotification);
        if (!$user) {
            throw new \Exception(
                "Unable to load user from SubscriptionResponse for DeveloperNotification ID [$developerNotification->id]." .
                "Check `SubscriptionResponseProcessorInterface::getUser()` and it's implementations."
            );
        }
        return $user;
    }

    private function createPayment(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification, ActiveRow $user): ?ActiveRow
    {
        if ($subscriptionResponse->getPaymentState() === GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_PENDING) {
            throw new DoNotRetryException("PaymentState is [pending]. No payment or subscription were created.");
        }

        $subscriptionType = $this->getSubscriptionType($developerNotification);

        $paymentGatewayCode = GooglePlayBilling::GATEWAY_CODE;
        $paymentGateway = $this->paymentGatewaysRepository->findByCode($paymentGatewayCode);
        if (!$paymentGateway) {
            throw new \Exception("Unable to find PaymentGateway with code [{$paymentGatewayCode}].");
        }

        $subscriptionStartAt = $this->subscriptionResponseProcessor->getSubscriptionStartAt($subscriptionResponse);
        $subscriptionEndAt = $this->subscriptionResponseProcessor->getSubscriptionEndAt($subscriptionResponse);

        $metas = [
            GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN => $developerNotification->purchase_token,
            GooglePlayBillingModule::META_KEY_ORDER_ID => $subscriptionResponse->getRawResponse()->getOrderId(),
            GooglePlayBillingModule::META_KEY_DEVELOPER_NOTIFICATION_ID => $developerNotification->id,
        ];

        if ($subscriptionResponse->getPaymentState() === GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_FREE_TRIAL) {
            return $this->createGoogleFreeTrialSubscription($user, $subscriptionType, $subscriptionStartAt, $subscriptionEndAt, $metas);
        }

        if ($subscriptionResponse->getPaymentState() !== GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_CONFIRMED) {
            throw new DoNotRetryException("Unable to handle PaymentState [{$subscriptionResponse->getPaymentState()}], no payment created.");
        }

        $amount = ($subscriptionResponse->getPriceAmountMicros() / 1000000);
        $recurrentCharge = false;
        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));

        // check if any payment with same purchase token was created & load data from it; set recurrent charge to true
        $paymentWithPurchaseToken = $this->paymentsRepository->getTable()
            ->where([
                ':payment_meta.key' => GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
                ':payment_meta.value' => $developerNotification->purchase_token,
            ])
            ->order('payments.subscription_end_at DESC')
            ->fetch();

        if ($paymentWithPurchaseToken && isset($paymentWithPurchaseToken->subscription_end_at)) {
            // check if same payment wasn't created already by legacy API endpoint `verify-purchase`
            if ($paymentWithPurchaseToken->subscription_end_at->format('Y-m-d H:i:s') === $subscriptionEndAt->format('Y-m-d H:i:s')) {
                throw new DoNotRetryException("Payment with same purchase token and end datetime already exists.");
            }

            if ($paymentWithPurchaseToken->subscription_end_at->format('Y-m-d H:i:s') > $subscriptionEndAt->format('Y-m-d H:i:s')) {
                throw new DoNotRetryException("Future payment with same purchase token already exists.");
            }

            // google returns in start time of renewed subscription datetime of purchase
            // we will set start of new subscription same as end of previous subscription
            $subscriptionStartAt = $paymentWithPurchaseToken->subscription_end_at;
            $recurrentCharge = true;
        }

        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $paymentGateway,
            $user,
            $paymentItemContainer,
            '',
            $amount,
            $subscriptionStartAt,
            $subscriptionEndAt,
            null,
            0,
            null,
            null,
            null,
            $recurrentCharge,
            $metas
        );

        $payment = $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PREPAID);
        return ($payment ?? null);
    }

    public function createGoogleFreeTrialSubscription(
        ActiveRow $user,
        ActiveRow $subscriptionType,
        DateTime $startDateTime,
        DateTime $endDateTime,
        array $metas
    ): ActiveRow {
        $subscription = $this->subscriptionsRepository->add(
            $subscriptionType,
            false,
            false,
            $user,
            SubscriptionsRepository::TYPE_FREE,
            $startDateTime,
            $endDateTime,
            'GooglePlay Free Trial',
            null,
            true,
            null
        );

        foreach ($metas as $metaKey => $metaData) {
            $this->subscriptionMetaRepository->add($subscription, $metaKey, $metaData);
        }

        return $subscription;
    }

    public function getSubscriptionType(ActiveRow $developerNotification): ActiveRow
    {
        $googlePlaySubscriptionType = $this->googlePlaySubscriptionTypesRepository->findBySubscriptionId($developerNotification->subscription_id);
        if (!$googlePlaySubscriptionType || !isset($googlePlaySubscriptionType->subscription_type)) {
            throw new \Exception("Unable to find SubscriptionType with code [{$developerNotification->subscription_id}] provided by DeveloperNotification.");
        }
        return $googlePlaySubscriptionType->subscription_type;
    }
}
