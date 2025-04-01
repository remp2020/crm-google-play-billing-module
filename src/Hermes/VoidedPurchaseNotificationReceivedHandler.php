<?php

namespace Crm\GooglePlayBillingModule\Hermes;

use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Repositories\VoidedPurchaseNotificationsRepository;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Models\Subscription\StopSubscriptionHandler;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\MessageInterface;
use Tracy\Debugger;

class VoidedPurchaseNotificationReceivedHandler implements HandlerInterface
{
    public function __construct(
        private readonly VoidedPurchaseNotificationsRepository $voidedPurchaseNotificationsRepository,
        private readonly PaymentMetaRepository $paymentMetaRepository,
        private readonly PaymentsRepository $paymentsRepository,
        private readonly RecurrentPaymentsRepository $recurrentPaymentsRepository,
        private readonly SubscriptionsRepository $subscriptionsRepository,
        private readonly StopSubscriptionHandler $stopSubscriptionHandler,
    ) {
    }

    public function handle(MessageInterface $message): bool
    {
        try {
            $voidedPurchaseNotification = $this->voidedPurchaseNotification($message);
        } catch (DoNotRetryException $e) {
            // log and do not retry; there is error in hermes message
            Debugger::log("Unable to load VoidedPurchaseNotification. Error: [{$e->getMessage()}]", Debugger::ERROR);
            return false;
        }

        // find payment
        $orderId = $voidedPurchaseNotification->order_id;
        $orderIdMeta = $this->paymentMetaRepository->findByMeta(GooglePlayBillingModule::META_KEY_ORDER_ID, $orderId);
        if (!$orderIdMeta) {
            Debugger::log("Unable to stop subscription. Unable to find payment with Google order_id: [$orderId].", Debugger::ERROR);
            return false;
        }
        $payment = $orderIdMeta->payment;

        // stop subscription if it's running or is in future
        if ($payment->subscription && $this->subscriptionsRepository->subscriptionIsActiveOrInFuture($payment->subscription)) {
            $this->stopSubscriptionHandler->stopSubscription($payment->subscription);
        }

        // stop recurrent if it's running
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        if ($recurrentPayment) {
            $lastRecurrentPayment = $this->recurrentPaymentsRepository->getLastWithState(
                $recurrentPayment,
                RecurrentPaymentStateEnum::Active->value,
            );

            if ($lastRecurrentPayment && $this->recurrentPaymentsRepository->canBeStopped($lastRecurrentPayment)) {
                $this->recurrentPaymentsRepository->stoppedBySystem($lastRecurrentPayment);
            }
        }

        // set payment status to refund
        if ($payment->status !== PaymentStatusEnum::Refund->value) {
            $this->paymentsRepository->update($payment, ['status' => PaymentStatusEnum::Refund->value]);
        }

        return true;
    }

    private function voidedPurchaseNotification(MessageInterface $message) : ActiveRow
    {
        $payload = $message->getPayload();
        if (!isset($payload['voided_purchase_notification_id'])) {
            throw new DoNotRetryException('`voided_purchase_notification_id` is missing from message payload.');
        }

        $voidedPurchaseNotification = $this->voidedPurchaseNotificationsRepository->find($payload['voided_purchase_notification_id']);
        if (!$voidedPurchaseNotification) {
            throw new DoNotRetryException("VoidedPurchaseNotification with ID [{$payload['voided_purchase_notification_id']}] is missing.");
        }

        return $voidedPurchaseNotification;
    }
}
