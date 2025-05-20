<?php

namespace Crm\GooglePlayBillingModule\Events;

use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\PaymentsModule\Events\PaymentEventInterface;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class PaymentStatusChangeEventHandler extends AbstractListener
{
    private $subscriptionsRepository;

    private $paymentsRepository;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        PaymentsRepository $paymentsRepository,
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->paymentsRepository = $paymentsRepository;
    }

    public function handle(EventInterface $event)
    {
        if (!$event instanceof PaymentEventInterface) {
            throw new \Exception("Invalid type of event received, 'PaymentEventInterface' expected: " . get_class($event));
        }

        $payment = $event->getPayment();
        // hard reload, other handlers could have altered the payment
        $payment = $this->paymentsRepository->find($payment->id);

        if ($payment->payment_gateway->code !== GooglePlayBilling::GATEWAY_CODE) {
            return;
        }

        if ($payment->status !== PaymentStatusEnum::Prepaid->value) {
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
