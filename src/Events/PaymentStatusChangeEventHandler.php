<?php

namespace Crm\GooglePlayBillingModule\Events;

use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class PaymentStatusChangeEventHandler extends AbstractListener
{
    private $subscriptionsRepository;

    private $paymentsRepository;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        PaymentsRepository $paymentsRepository
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->paymentsRepository = $paymentsRepository;
    }

    public function handle(EventInterface $event)
    {
        $payment = $event->getPayment();
        // hard reload, other handlers could have altered the payment
        $payment = $this->paymentsRepository->find($payment->id);

        if ($payment->payment_gateway->code !== GooglePlayBilling::GATEWAY_CODE) {
            return;
        }

        if ($payment->status !== PaymentsRepository::STATUS_PREPAID) {
            return;
        }

        if (!$payment->subscription_id) {
            return;
        }

        $this->subscriptionsRepository->update($payment->subscription, [
            'is_recurrent' => true,
        ]);
    }
}
