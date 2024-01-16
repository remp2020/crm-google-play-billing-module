<?php

namespace Crm\GooglePlayBillingModule\Tests;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\GooglePlayBillingModule\DataProviders\AccessTokenDataProvider;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repository\AccessTokensRepository;
use Crm\UsersModule\Repository\UserMetaRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Utils\Random;

class AccessTokenDataProviderTest extends DatabaseTestCase
{
    /** @var AccessTokensRepository */
    private $accessTokensRepository;

    /** @var DeviceTokensRepository */
    private $deviceTokensRepository;

    /** @var UsersRepository */
    private $usersRepository;

    protected function requiredRepositories(): array
    {
        return [
            UsersRepository::class,
            UserMetaRepository::class,
            DeviceTokensRepository::class,
            AccessTokensRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
        ];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->accessTokensRepository = $this->getRepository(AccessTokensRepository::class);
        $this->deviceTokensRepository = $this->getRepository(DeviceTokensRepository::class);
        $this->usersRepository = $this->getRepository(UsersRepository::class);

        $dataProviderManager = $this->inject(DataProviderManager::class);
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.access_tokens',
            $this->inject(AccessTokenDataProvider::class)
        );
    }

    public function testUnprotectedUnpairing()
    {
        $deviceToken = $this->deviceTokensRepository->generate('foo');

        // pair users
        $user1 = $this->getUser();
        $accessToken1 = $this->accessTokensRepository->add($user1);
        $this->accessTokensRepository->pairWithDeviceToken($accessToken1, $deviceToken);
        $user2 = $this->getUser();
        $accessToken2 = $this->accessTokensRepository->add($user2);
        $this->accessTokensRepository->pairWithDeviceToken($accessToken2, $deviceToken);

        // unpair token
        $this->accessTokensRepository->unpairDeviceToken($deviceToken);

        // regular unpairing should get rid of all access tokens linked to the device
        $this->assertCount(
            0,
            $this->accessTokensRepository->findAllByDeviceToken($deviceToken)
        );
    }

    public function testProtectedUnpairing()
    {
        $deviceToken = $this->deviceTokensRepository->generate('foo');

        // pair users, protected second
        $user1 = $this->getUser();
        $accessToken1 = $this->accessTokensRepository->add($user1);
        $this->accessTokensRepository->pairWithDeviceToken($accessToken1, $deviceToken);
        $user2 = $this->getUser();
        $accessToken2 = $this->accessTokensRepository->add($user2, 3, GooglePlayBillingModule::USER_SOURCE_APP);
        $this->accessTokensRepository->pairWithDeviceToken($accessToken2, $deviceToken);

        // unpair token
        $this->accessTokensRepository->unpairDeviceToken($deviceToken);

        // unpairing should get rid of the one access token without Google source
        $this->assertCount(
            1,
            $this->accessTokensRepository->findAllByDeviceToken($deviceToken)
        );
    }

    private function getUser()
    {
        return $this->usersRepository->add('user_' . Random::generate() . '@example.com', 'secret');
    }
}
