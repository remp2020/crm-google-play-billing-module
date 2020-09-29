<?php

namespace Crm\GooglePlayBillingModule\DataProviders;

use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\UsersModule\DataProvider\AccessTokenDataProviderInterface;
use Nette\Database\Table\IRow;

class AccessTokenDataProvider implements AccessTokenDataProviderInterface
{
    public function canUnpairDeviceToken(IRow $accessToken, IRow $deviceToken): bool
    {
        if ($accessToken->source === GooglePlayBillingModule::USER_SOURCE_APP) {
            return false;
        }
        return true;
    }

    public function provide(array $params)
    {
        throw new \Exception('AccessTokenDataProvider does not provide generic method results');
    }
}
