<?php

namespace Crm\GooglePlayBillingModule\Hermes;

use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Models\GooglePlayValidatorFactory;
use Crm\GooglePlayBillingModule\Models\SubscriptionResponseProcessor\SubscriptionResponseProcessorInterface;
use Crm\GooglePlayBillingModule\Repositories\DeveloperNotificationsRepository;
use Crm\GooglePlayBillingModule\Repositories\GooglePlaySubscriptionTypesRepository;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\RecurrentPaymentsProcessor;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Psr\Log\LoggerAwareTrait;
use ReceiptValidator\GooglePlay\Acknowledger;
use ReceiptValidator\GooglePlay\SubscriptionResponse;
use ReceiptValidator\GooglePlay\Validator;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;
use Tracy\Debugger;

class DeveloperNotificationReceivedHandler implements HandlerInterface
{
    use LoggerAwareTrait;
    use RetryTrait;

    public const INFO_LOG_LEVEL = 'google_developer_notifications';

    private $googlePlayValidator;

    public function __construct(
        private SubscriptionResponseProcessorInterface $subscriptionResponseProcessor,
        private DeveloperNotificationsRepository $developerNotificationsRepository,
        private GooglePlaySubscriptionTypesRepository $googlePlaySubscriptionTypesRepository,
        private GooglePlayValidatorFactory $googlePlayValidatorFactory,
        private PaymentGatewaysRepository $paymentGatewaysRepository,
        private PaymentMetaRepository $paymentMetaRepository,
        private PaymentsRepository $paymentsRepository,
        private SubscriptionsRepository $subscriptionsRepository,
        private SubscriptionMetaRepository $subscriptionMetaRepository,
        private RecurrentPaymentsRepository $recurrentPaymentsRepository,
        private RecurrentPaymentsProcessor $recurrentPaymentsProcessor,
    ) {
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
        $googlePlayValidator = $this->googlePlayValidator ?: $this->googlePlayValidatorFactory->create();
        $gSubscription = $googlePlayValidator
            ->setPackageName($developerNotification->package_name)
            ->setPurchaseToken($developerNotification->purchase_token)
            ->setProductId($developerNotification->subscription_id)
            ->validateSubscription();

        $this->developerNotificationsRepository->update($developerNotification, [
            'subscription_purchase' => Json::encode($gSubscription->getRawResponse()->toSimpleObject()),
        ]);

        switch ($developerNotification->notification_type) {
            // following notification types will create payment with subscription
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RENEWED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RECOVERED:
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

            // handle upgraded, cancelled and revoked subscriptions
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_EXPIRED:
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

            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RESTARTED:
                $this->restartSubscription($gSubscription, $developerNotification);

            // doesn't affect existing payments; new will be created with confirmed price
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PRICE_CHANGE_CONFIRMED:
                break;

            // following notification types do not affect existing subscriptions or payments in CRM
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_ON_HOLD:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_DEFERRED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PAUSED:
            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED:
                break;

            case DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD:
                try {
                    $this->createGoogleGracePeriodSubscription($gSubscription, $developerNotification);
                } catch (DoNotRetryException $e) {
                    Debugger::log("Unable to create grace period subscription. DeveloperNotification ID: [{$developerNotification->id}]. Error: [{$e->getMessage()}]", Debugger::ERROR);
                    $this->developerNotificationsRepository->updateStatus(
                        $developerNotification,
                        DeveloperNotificationsRepository::STATUS_ERROR
                    );
                    return false;
                }
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

    // Useful in tests
    public function setGooglePlayValidator(Validator $googlePlayValidator): void
    {
        $this->googlePlayValidator = $googlePlayValidator;
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

        $subscription = $this->subscriptionMetaRepository->findSubscriptionBy(
            GooglePlayBillingModule::META_KEY_ORDER_ID,
            $subscriptionResponse->getRawResponse()->getOrderId()
        );

        if ($subscription?->type === SubscriptionsRepository::TYPE_FREE) {
            // free trial exists for given SubscriptionResponse,
            // do not create payment
            return null;
        }

        if ($subscription) {
            $isGraceSubscription = $this->subscriptionMetaRepository->findBySubscriptionAndKey(
                $subscription,
                GooglePlayBillingModule::META_KEY_GRACE_PERIOD_SUBSCRIPTION
            );

            if (!$isGraceSubscription) {
                // should not get here
                throw new \Exception("Subscription already exists for order ID [{$subscriptionResponse->getRawResponse()->getOrderId()}].");
            }
        }

        $user = $this->subscriptionResponseProcessor->getUser($subscriptionResponse, $developerNotification);

        if (!in_array($subscriptionResponse->getPaymentState(), [
            GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_CONFIRMED,
            GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_FREE_TRIAL
        ], true)) {
            throw new DoNotRetryException("Unable to handle PaymentState [{$subscriptionResponse->getPaymentState()}], no payment created.");
        }

        $subscriptionType = $this->getSubscriptionType($subscriptionResponse, $developerNotification);

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

        // check if any payment with same purchase token was created & load data from it; set recurrent charge to true
        $paymentWithPurchaseToken = $this->paymentsRepository->getTable()
            ->where([
                ':payment_meta.key' => GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
                ':payment_meta.value' => $developerNotification->purchase_token,
            ])
            ->order('payments.subscription_end_at DESC')
            ->fetch();

        if ($paymentWithPurchaseToken && isset($paymentWithPurchaseToken->subscription_end_at)) {
            $paymentWithPurchaseTokenOrderId = $this->paymentMetaRepository->findByPaymentAndKey(
                $paymentWithPurchaseToken,
                GooglePlayBillingModule::META_KEY_ORDER_ID
            );
            $samePurchaseTokenAndOrderId = false;
            if ($paymentWithPurchaseTokenOrderId !== null && $paymentWithPurchaseTokenOrderId->value === $subscriptionResponse->getRawResponse()->getOrderId()) {
                $samePurchaseTokenAndOrderId = true;
            }

            // purchase with same order ID & same end datetime was already created (by previous notification or legacy API endpoint `verify-purchase`)
            if ($samePurchaseTokenAndOrderId && $paymentWithPurchaseToken->subscription_end_at->format('Y-m-d H:i:s') === $subscriptionEndAt->format('Y-m-d H:i:s')) {
                throw new DoNotRetryException("Payment with same purchase token and end datetime already exists.");
            }

            if ($samePurchaseTokenAndOrderId && $paymentWithPurchaseToken->subscription_end_at->format('Y-m-d H:i:s') > $subscriptionEndAt->format('Y-m-d H:i:s')) {
                throw new DoNotRetryException("Future payment with same purchase token already exists.");
            }

            // google returns in start time:
            // - date of start for upgraded subscription (previous subscription from which upgrade was done can be still un-expired (google sends notifications in incorrect order))
            //   - in this case we want to keep start time provided by google
            //   - it has different order ID
            // - date of purchase for renewed subscription
            //   - in this case we want to start immediately after previous payment with same token
            //   - it has same order ID
            if ($samePurchaseTokenAndOrderId) {
                $subscriptionStartAt = $paymentWithPurchaseToken->subscription_end_at;
            }

            $recurrentCharge = true;
        }

        // this is grace period subscription.
        // if payment is after grace period subscription expired (purchased subscription recovered from hold) grace period subscription end time does not change.
        if ($subscription) {
            $this->stopGracePeriodSubscription($subscription);
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
            $recurrentCharge,
            $metas
        );

        if ($paymentWithPurchaseToken) {
            $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($paymentWithPurchaseToken);
            if ($lastRecurrentPayment) {
                $this->recurrentPaymentsRepository->update($lastRecurrentPayment, [
                    'payment_id' => $payment->id,
                ]);
                $this->recurrentPaymentsProcessor->processChargedRecurrent(
                    $lastRecurrentPayment,
                    PaymentsRepository::STATUS_PREPAID,
                    0,
                    'NOTIFICATION',
                );

                return $payment;
            }
        }

        $payment = $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PREPAID);

        $this->recurrentPaymentsRepository->createFromPayment(
            $payment,
            $developerNotification->purchase_token,
        );

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

        // stop active recurrent
        $recurrent = $this->recurrentPaymentsRepository->recurrent($paymentWithPurchaseToken);
        if (!$recurrent || $recurrent->state !== RecurrentPaymentsRepository::STATE_ACTIVE) {
            $lastRecurrent = $this->recurrentPaymentsRepository->getLastWithState($recurrent, RecurrentPaymentsRepository::STATE_ACTIVE);
            if ($lastRecurrent) {
                $recurrent = $lastRecurrent;
            }
        }
        if ($recurrent) {
            // payment was stopped by user through Google Play store
            $this->recurrentPaymentsRepository->update($recurrent, [
                'state' => RecurrentPaymentsRepository::STATE_USER_STOP
            ]);
        } else {
            Debugger::log("Cancelled GooglePlay payment [{$paymentWithPurchaseToken->id}] doesn't have active recurrent payment.", Debugger::WARNING);
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

    public function restartSubscription(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification)
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

        $this->paymentMetaRepository->add($paymentWithPurchaseToken, 'restart_datetime', new DateTime(), true);

        $restartNote = "Subscription restarted.";
        $paymentNote = !empty($paymentWithPurchaseToken->note) ? $paymentWithPurchaseToken->note . " | " : "";
        $this->paymentsRepository->update(
            $paymentWithPurchaseToken,
            [
                'note' => $paymentNote . $restartNote,
            ]
        );

        // restart recurrent
        $recurrent = $this->recurrentPaymentsRepository->recurrent($paymentWithPurchaseToken);
        if ($recurrent) {
            // payment was stopped by user through Google Play store
            $this->recurrentPaymentsRepository->update($recurrent, [
                'state' => RecurrentPaymentsRepository::STATE_ACTIVE
            ]);
        } else {
            Debugger::log("Restarted GooglePlay payment [{$paymentWithPurchaseToken->id}] doesn't have stopped recurrent payment. Creating new recurrent.", Debugger::WARNING);

            $this->recurrentPaymentsRepository->createFromPayment(
                $paymentWithPurchaseToken,
                $developerNotification->purchase_token,
            );
        }
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

    /**
     * Create grace period subscription
     *
     * @description The grace period is designed to help you reduce involuntary churn. If
     *              grace period is enabled for an auto-renewing base plan, a subscription
     *              purchase enters the grace period if there are payment issues at the
     *              end of a billing cycle. During this time, the user should still have
     *              access to their subscription while Google Play tries to renew
     *              the subscription by reattempting the charge periodically.
     *
     *              While the user is in a grace period, the subscription resource contains
     *              autoRenewEnabled = true. Google Play dynamically extends the expiryTime
     *              value until the grace period has expired because entitlement should last
     *              until the user cancels or the grace period has lasted for its maximum length.
     *              The value of the subscriptionState field during this period is
     *              SUBSCRIPTION_STATE_IN_GRACE_PERIOD.
     */
    public function createGoogleGracePeriodSubscription(
        SubscriptionResponse $subscriptionResponse,
        ActiveRow $developerNotification,
    ): ?ActiveRow {
        // Subscription might already be charged when we get here. Let's not assume we're still in the grace period.
        if (!$subscriptionResponse->getAutoRenewing() || $subscriptionResponse->getPaymentState() !== GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_PENDING) {
            return null;
        }

        // prepare subscription and subscription meta
        $user = $this->subscriptionResponseProcessor->getUser($subscriptionResponse, $developerNotification);
        $subscriptionType = $this->getSubscriptionType($subscriptionResponse, $developerNotification);
        $subscriptionStartAt = $this->subscriptionResponseProcessor->getSubscriptionStartAt($subscriptionResponse);
        $subscriptionEndAt = $this->subscriptionResponseProcessor->getSubscriptionEndAt($subscriptionResponse);

        $metas = [
            GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN => $developerNotification->purchase_token,
            GooglePlayBillingModule::META_KEY_ORDER_ID => $subscriptionResponse->getRawResponse()->getOrderId(),
            GooglePlayBillingModule::META_KEY_DEVELOPER_NOTIFICATION_ID => $developerNotification->id,
            GooglePlayBillingModule::META_KEY_GRACE_PERIOD_SUBSCRIPTION => true,
        ];

        // get last subscription (with or without payment) linked to grace period through purchase token
        $subscriptionWithPurchaseToken = $this->subscriptionsRepository->getTable()
            ->where([
                ':subscriptions_meta.key' => GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
                ':subscriptions_meta.value' => $developerNotification->purchase_token,
                'end_time >= ?' => $subscriptionStartAt->format('Y-m-d H:i:s'),
            ])
            ->order('subscriptions.end_time DESC')
            ->fetch();

        $paymentWithPurchaseToken = $this->paymentsRepository->getTable()
            ->where([
                ':payment_meta.key' => GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
                ':payment_meta.value' => $developerNotification->purchase_token,
                'subscription_end_at >= ?' => $subscriptionStartAt->format('Y-m-d H:i:s'),
            ])
            ->order('payments.subscription_end_at DESC')
            ->fetch();

        if ($paymentWithPurchaseToken === null || !isset($paymentWithPurchaseToken->subscription)) {
            throw new DoNotRetryException(
                "Cannot grant grace period without previous purchase. " .
                "Unable to find payment with purchase token [{$developerNotification->purchase_token}]."
            );
        }

        // get latest subscription (from subscription with meta or from payment with meta)
        if ($paymentWithPurchaseToken->subscription->end_time >= $subscriptionWithPurchaseToken?->end_time) {
            $lastSubscription = $paymentWithPurchaseToken->subscription;
        } else {
            $lastSubscription = $subscriptionWithPurchaseToken;
        }

        if ($lastSubscription->end_time >= $subscriptionEndAt) {
            throw new DoNotRetryException(
                "There is already subscription ID [{$lastSubscription->id}] with later end time " .
                "linked through purchase token [{$developerNotification->purchase_token}]."
            );
        }

        // start of subscription should be after end of last subscription or NOW if previous subscription is in past
        $subscriptionStartAt = max(new DateTime(), $lastSubscription->end_time);

        $subscription = $this->subscriptionsRepository->add(
            $subscriptionType,
            false,
            true,
            $user,
            SubscriptionsRepository::TYPE_PREPAID,
            $subscriptionStartAt,
            $subscriptionEndAt,
            'GooglePlay Grace Period'
        );

        foreach ($metas as $metaKey => $metaData) {
            $this->subscriptionMetaRepository->add($subscription, $metaKey, $metaData);
        }

        return $subscription;
    }

    private function stopGracePeriodSubscription(ActiveRow $subscription)
    {
        if ($subscription->end_time > new DateTime()) {
            $this->subscriptionsRepository->update($subscription, [
                'end_time' => new DateTime()
            ]);
        }
    }

    public function getSubscriptionType(SubscriptionResponse $subscriptionResponse, ActiveRow $developerNotification): ActiveRow
    {
        $googlePlaySubscriptionType = $this->googlePlaySubscriptionTypesRepository->findByGooglePlaySubscriptionId($developerNotification->subscription_id);
        if (!$googlePlaySubscriptionType || !isset($googlePlaySubscriptionType->subscription_type)) {
            throw new \Exception("Unable to find SubscriptionType with code [{$developerNotification->subscription_id}] provided by DeveloperNotification.");
        }

        // Handle case when introductory subscription type is different from renewals.
        if ($googlePlaySubscriptionType->subscription_type->next_subscription_type_id) {
            $usedOfferPeriods = $this->getUsedOfferPeriods($subscriptionResponse->getRawResponse()->getOrderId());
            // used all offer periods
            // if offer periods not set -> there is 1 offer period and we have used it already
            if (($googlePlaySubscriptionType->offer_periods && $usedOfferPeriods >= $googlePlaySubscriptionType->offer_periods)
                || (is_null($googlePlaySubscriptionType->offer_periods) && $usedOfferPeriods > 0)) {
                return $googlePlaySubscriptionType->subscription_type->next_subscription_type;
            }
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
        switch ($subscriptionResponse->getCancelReason()) {
            case 0:
                $cancelData['cancel_reason'] = 'cancelled_by_user';
                $userCancellationTime = $this->subscriptionResponseProcessor->getUserCancellationTime($subscriptionResponse);
                if ($userCancellationTime !== null) {
                    $cancelData['cancel_datetime'] = $userCancellationTime->format('Y-m-d H:i:s');
                }
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

    private function getUsedOfferPeriods($orderId): int
    {
        $matches = [];
        // Order ID for renewal payments ends with '..0', e.g. 'GPA.1111-1111-1111-11111..0'
        preg_match('/\.\.(\d+)$/', $orderId, $matches);

        if (isset($matches[1])) {
            // Renewal starts from 0; first renewal order id ends with '..0'; initial payment has no sufix
            return (int)$matches[1] + 1;
        }

        return 0;
    }
}
