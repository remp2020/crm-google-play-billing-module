<?php

namespace Crm\GooglePlayBillingModule\Components;

use Crm\ApplicationModule\Widget\BaseLazyWidget;
use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;

class StopRecurrentPaymentButtonWidget extends BaseLazyWidget
{
    private $templateName = 'stop_recurrent_payment_info_widget.latte';

    public function identifier()
    {
        return 'stopgooglerecurrentpaymentbuttonwidget';
    }

    public function render($recurrentPayment)
    {
        if ($recurrentPayment->payment_gateway->code !== GooglePlayBilling::GATEWAY_CODE) {
            return;
        }
        $this->template->setFile(__DIR__ . DIRECTORY_SEPARATOR . $this->templateName);
        $this->template->render();
    }
}
