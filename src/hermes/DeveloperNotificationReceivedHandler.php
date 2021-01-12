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
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Psr\Log\LoggerAwareTrait;
use ReceiptValidator\GooglePlay\Acknowledger;
use ReceiptValidator\GooglePlay\SubscriptionResponse;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;
use Tracy\Debugger;

class DeveloperNotificationReceivedHandler implements HandlerInterface
{
    use LoggerAwareTrait;
    use RetryTrait;

    public const INFO_LOG_LEVEL = 'google_developer_notifications';

    private $developerNotificationsRepository;

    private $googlePlaySubscriptionTypesRepository;

    private $googlePlayValidatorFactory;

    private $paymentGatewaysRepository;

    private $paymentMetaRepository;

    private $paymentsRepository;

    private $subscriptionsRepository;

    private $subscriptionMetaRepository;

    private $subscriptionResponseProcessor;

    public function __construct(
        SubscriptionResponseProcessorInterface $subscriptionResponseProcessor,
        DeveloperNotificationsRepository $developerNotificationsRepository,
        GooglePlaySubscriptionTypesRepository $googlePlaySubscriptionTypesRepository,
        GooglePlayValidatorFactory $googlePlayValidator,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentMetaRepository $paymentMetaRepository,
        PaymentsRepository $paymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionMetaRepository $subscriptionMetaRepository
    ) {
        $this->developerNotificationsRepository = $developerNotificationsRepository;
        $this->googlePlaySubscriptionTypesRepository = $googlePlaySubscriptionTypesRepository;
        $this->googlePlayValidatorFactory = $googlePlayValidator;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->paymentsRepository = $paymentsRepository;
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

        switch ($developerNotification->notification_type) {
            // following notification types will create payment with subscription
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RENEWED:
                try {
                    $this->createPayment($gSubscription, $developerNotification);

                    // payment is created internally; we can confirm it in Google
                    if (!$gSubscription->isAcknowledged()) {
                        $googleAcknowledger = new Acknowledger(
                            $googlePlayValidator->getPublisherService(),
                            $developerNotification->package_name,
                            $developerNotification->subscription_id,
                            $developerNotification->purchase_token
                        );
                        $googleAcknowledger->acknowledge();
                    }
                } catch (DoNotRetryException $e) {
                    Debugger::log("Processing stopped, no further attempts. Reason: [{$e->getMessage()}]", self::INFO_LOG_LEVEL);
                    $this->developerNotificationsRepository->updateStatus(
                        $developerNotification,
                        DeveloperNotificationsRepository::STATUS_DO_NOT_RETRY
                    );
                    return false;
                }
                break;

            // following notification types do not affect existing subscriptions or payments
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_EXPIRED:
                break;

            // handle cancelled and revoked subscriptions
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_CANCELED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_REVOKED:
                try {
                    $this->cancelSubscription($gSubscription, $developerNotification);
                } catch (DoNotRetryException $e) {
                    Debugger::log("Unable to cancel subscription. DeveloperNotification ID: [{$developerNotification->id}]. Error: [{$e->getMessage()}]", Debugger::ERROR);
                    $this->developerNotificationsRepository->updateStatus(
                        $developerNotification,
                        DeveloperNotificationsRepository::STATUS_ERROR
                    );
                    return false;
                }
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

    /**
     * @return ActiveRow|null - Returns $payment if created.
     *
     * @throws \Exception - Thrown in case of internal failure or missing settings.
     * @throws DoNotRetryException - Thrown in case processing should be stopped without trying again.
     */
    private function createPayment(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification): ?ActiveRow
    {
        $paymentOrder = $this->paymentMetaRepository->findByMeta(
            GooglePlayBillingModule::META_KEY_ORDER_ID,
            $subscriptionResponse->getRawResponse()->getOrderId()
        );
        if ($paymentOrder) {
            return $paymentOrder->payment;
        }

        $user = $this->subscriptionResponseProcessor->getUser($subscriptionResponse, $developerNotification);

        if (!in_array($subscriptionResponse->getPaymentState(), [
            GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_CONFIRMED,
            GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_FREE_TRIAL
        ])) {
            throw new DoNotRetryException("Unable to handle PaymentState [{$subscriptionResponse->getPaymentState()}], no payment created.");
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
            $subscriptionType->price,
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

    /**
     * cancelSubscriptions processes cancellation and refund
     *
     * - updates subscription's end date
     *   - From Google docs: If expiryTimeMillis is in the past, then the user loses entitlement immediately.
     *     Otherwise, the user should retain entitlement until it is expired.
     * - adds note about cancellation
     * - stores cancellation reason into payment_meta
     * - changes internal payment's status to REFUND in case of REVOKED notification type
     *   - no status change in case of CANCELLATION (money are not returned to customer)
     */
    public function cancelSubscription(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification)
    {
        $subscriptionStartAt = $this->subscriptionResponseProcessor->getSubscriptionStartAt($subscriptionResponse);
        $subscriptionEndAt = $this->subscriptionResponseProcessor->getSubscriptionEndAt($subscriptionResponse);

        // check if any payment with same purchase token was created & load data from it
        $paymentWithPurchaseToken = $this->paymentsRepository->getTable()
            ->where([
                ':payment_meta.key' => GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
                ':payment_meta.value' => $developerNotification->purchase_token,
                'subscription_start_at >= ?' => $subscriptionStartAt->format('Y-m-d H:i:s'),
            ])
            ->order('payments.subscription_end_at DESC')
            ->fetch();

        if (!$paymentWithPurchaseToken) {
            throw new DoNotRetryException("Unable to find payment with purchase token [{$developerNotification->purchase_token}] and start date [{$subscriptionStartAt->format('Y-m-d H:i:s')}].");
        }

        // store cancel reason
        $cancelReason = $this->processCancelReason($subscriptionResponse, $developerNotification);
        foreach ($cancelReason as $key => $value) {
            $this->paymentMetaRepository->add($paymentWithPurchaseToken, $key, $value, true);
        }

        $cancelNote = "Subscription cancelled. Reason: " . ($cancelReason['cancel_reason'] ?? 'unknown');
        $paymentNote = !empty($paymentWithPurchaseToken->note) ? $paymentWithPurchaseToken->note . " | " : "";
        $this->paymentsRepository->update(
            $paymentWithPurchaseToken,
            [
                'note' => $paymentNote . $cancelNote,
            ]
        );
        // if subscription is revoked, money are returned and subscription is stopped (in case of cancellation, money are not returned)
        if ($developerNotification->notification_type === DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_REVOKED) {
            $this->paymentsRepository->updateStatus($paymentWithPurchaseToken, PaymentsRepository::STATUS_REFUND);
        }

        if (!$paymentWithPurchaseToken->subscription) {
            Debugger::log("Missing subscription which should be cancelled. DeveloperNotification ID: [{$developerNotification->id}].", Debugger::ERROR);
            return;
        }
        $subscriptionNote = !empty($paymentWithPurchaseToken->subscription->note) ? $paymentWithPurchaseToken->subscription->note . " | " : "";
        $this->subscriptionsRepository->update(
            $paymentWithPurchaseToken->subscription,
            [
                'note' => $subscriptionNote . $cancelNote,
                'end_time' => $subscriptionEndAt, // update end date of subscription with information from google
            ]
        );
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
        $googlePlaySubscriptionType = $this->googlePlaySubscriptionTypesRepository->findByGooglePlaySubscriptionId($developerNotification->subscription_id);
        if (!$googlePlaySubscriptionType || !isset($googlePlaySubscriptionType->subscription_type)) {
            throw new \Exception("Unable to find SubscriptionType with code [{$developerNotification->subscription_id}] provided by DeveloperNotification.");
        }
        return $googlePlaySubscriptionType->subscription_type;
    }

    /**
     * Process cancel reason result and return array with user understandable data.
     *
     * @return array Returns array with named keys. Format:
     * [
     *    'cancel_reason' => string {cancelled_by_user|cancelled_by_system|replaced_by_new_subscription|cancelled_by_developer},
     *    'cancel_survey_reason' => string (set only if 'cancel_reason' === 'cancelled_by_user') {other|dont_use_enough|technical_issues|cost_related_issues|found_better_app}
     *    'cancel_survey_user_input' => string (set only if 'cancel_survey_reason' === 'other')
     * ]
     */
    public function processCancelReason(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification): array
    {
        $cancelData = [];
        $cancelData['cancel_datetime'] = $this->subscriptionResponseProcessor->getUserCancellationTime($subscriptionResponse)->format('Y-m-d H:i:s');
        switch ($subscriptionResponse->getCancelReason()) {
            case 0:
                $cancelData['cancel_reason'] = 'cancelled_by_user';
                $cancelSurveyResult = $subscriptionResponse->getRawResponse()->getCancelSurveyResult();
                if ($cancelSurveyResult && $cancelSurveyResult->getCancelSurveyReason()) {
                    switch ($cancelSurveyResult->getCancelSurveyReason()) {
                        case 0:
                            $cancelData['cancel_survey_reason'] = 'other';
                            $cancelData['cancel_survey_user_input'] = $cancelSurveyResult->getUserInputCancelReason();
                            break;
                        case 1:
                            $cancelData['cancel_survey_reason'] = 'dont_use_enough';
                            break;
                        case 2:
                            $cancelData['cancel_survey_reason'] = 'technical_issues';
                            break;
                        case 3:
                            $cancelData['cancel_survey_reason'] = 'cost_related_issues';
                            break;
                        case 4:
                            $cancelData['cancel_survey_reason'] = 'found_better_app';
                            break;
                        default:
                            Debugger::log("Unknown cancel survey reason {$cancelSurveyResult->getCancelSurveyReason()}. Google added new state? DeveloperNotification ID: [{$developerNotification->id}]", Debugger::ERROR);
                    }
                }
                break;
            case 1:
                $cancelData['cancel_reason'] = 'cancelled_by_system';
                break;
            case 2:
                $cancelData['cancel_reason'] = 'replaced_by_new_subscription';
                break;
            case 3:
                $cancelData['cancel_reason'] = 'cancelled_by_developer';
                break;
            default:
                Debugger::log("Unknown cancel reason {$subscriptionResponse->getCancelReason()}. Google added new state? DeveloperNotification ID: [{$developerNotification->id}]", Debugger::ERROR);
        }

        return $cancelData;
    }
}
