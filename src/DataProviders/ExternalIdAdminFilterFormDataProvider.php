<?php

namespace Crm\GooglePlayBillingModule\DataProviders;

use Crm\ApplicationModule\DataProvider\DataProviderException;
use Crm\PaymentsModule\DataProvider\AdminFilterFormDataProviderInterface;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Nette\Application\UI\Form;
use Nette\Database\Table\Selection;

class ExternalIdAdminFilterFormDataProvider implements AdminFilterFormDataProviderInterface
{
    public function __construct(private PaymentsRepository $paymentsRepository)
    {
    }

    public function provide(array $params): Form
    {
        if (!isset($params['form'])) {
            throw new DataProviderException('missing [form] within data provider params');
        }
        return $params['form'];
    }

    public function filter(Selection $selection, array $formData): Selection
    {
        $externalId = $formData['external_id'] ?? null;
        if (!$externalId) {
            return $selection;
        }

        $results = $this->paymentsRepository->getTable()
            ->where([
                ':payment_meta.value' => $externalId,
                ':payment_meta.key' => ['google_play_billing_order_id'],
            ])
            ->fetchPairs('id', 'id');

        if (count($results) > 0) {
            $selection->where('payments.id IN (?)', array_keys($results));
        }

        return $selection;
    }
}
