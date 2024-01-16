<?php

namespace Crm\GooglePlayBillingModule\Tests;

use Crm\GooglePlayBillingModule\Models\SubscriptionResponseProcessor\SubscriptionResponseDateTimesTrait;
use Mockery\MockInterface;
use Nette\Utils\DateTime;
use PHPUnit\Framework\TestCase;
use ReceiptValidator\GooglePlay\SubscriptionResponse;

class SubscriptionResponseDateTimesTraitTest extends TestCase
{
    use SubscriptionResponseDateTimesTrait;

    public function testRegularTime()
    {
        /** @var SubscriptionResponse|MockInterface $subscriptionResponse */
        $subscriptionResponse = \Mockery::mock(SubscriptionResponse::class)
            ->shouldReceive('getStartTimeMillis')
            ->andReturn(1601101422024)
            ->getMock();

        $startsAt = $this->getSubscriptionStartAt($subscriptionResponse);
        $this->assertEquals(DateTime::from('2020-09-26T06:23:42.024Z'), $startsAt);
    }

    public function testZeroSuffixedTime()
    {
        /** @var SubscriptionResponse|MockInterface $subscriptionResponse */
        $subscriptionResponse = \Mockery::mock(SubscriptionResponse::class)
            ->shouldReceive('getStartTimeMillis')
            ->andReturn(1601101840000)
            ->getMock();

        $startsAt = $this->getSubscriptionStartAt($subscriptionResponse);
        $this->assertEquals(DateTime::from('2020-09-26T06:30:40Z'), $startsAt);
    }
}
