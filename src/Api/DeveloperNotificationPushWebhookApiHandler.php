<?php

namespace Crm\GooglePlayBillingModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\ApiModule\Models\Api\JsonValidationTrait;
use Crm\GooglePlayBillingModule\Repositories\DeveloperNotificationsRepository;
use Crm\GooglePlayBillingModule\Repositories\PurchaseTokensRepository;
use Crm\GooglePlayBillingModule\Repositories\VoidedPurchaseNotificationsRepository;
use Nette\Http\IResponse;
use Nette\Http\Response;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tracy\Debugger;

class DeveloperNotificationPushWebhookApiHandler extends ApiHandler
{
    use JsonValidationTrait;

    public function __construct(
        private readonly DeveloperNotificationsRepository $developerNotificationsRepository,
        private readonly PurchaseTokensRepository $purchaseTokensRepository,
        private readonly VoidedPurchaseNotificationsRepository $voidedPurchaseNotificationsRepository,
    ) {
        parent::__construct();
    }

    public function params(): array
    {
        return [];
    }

    public function handle(array $params): ResponseInterface
    {
        // get DeveloperNotification data from google pub/sub message
        try {
            $json = Json::decode(file_get_contents('php://input'));

            if (!isset($json->message->data) || empty($json->message->data)) {
                throw new \Exception('Message must contain `data` field.');
            }
        } catch (\Exception $e) {
            return $this->logAndReturnPayloadError("Malformed JSON. Error: [{$e->getMessage()}].");
        }

        // decode & validate DeveloperNotification
        $result = $this->validateInput(__DIR__ . '/developer-notification.schema.json', base64_decode($json->message->data));
        if ($result->hasErrorResponse()) {
            $errorResponse = $result->getErrorResponse();
            $errorPayload = $errorResponse->getPayload();
            return $this->logAndReturnPayloadError(sprintf(
                "Unable to parse JSON of Google's DeveloperNotification: %s. %s",
                $errorPayload['message'],
                isset($errorPayload['errors']) ? ". Errors: [" . print_r($errorPayload['errors'], true) . '].' : ''
            ));
        }
        $developerNotification = $result->getParsedObject();

        if ($developerNotification->version !== "1.0") {
            return $this->logAndReturnPayloadError("Only version 1.0 of DeveloperNotification is supported.");
        }

        $eventTime = DateTime::createFromFormat(
            "U.u",
            sprintf("%.6f", $developerNotification->eventTimeMillis / 1000)
        );
        $eventTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        // store voided purchase notifications
        if (isset($developerNotification->voidedPurchaseNotification)) {
            $voidedPurchaseNotificationData = $developerNotification->voidedPurchaseNotification;

            $purchaseTokenRow = $this->purchaseTokensRepository->findByPurchaseToken($voidedPurchaseNotificationData->purchaseToken);
            if ($purchaseTokenRow === null) {
                Debugger::log(
                    "Trying to void purchase with missing purchase token: `{$voidedPurchaseNotificationData->purchaseToken}`",
                    Debugger::ERROR,
                );

                return new JsonApiResponse(Response::S400_BadRequest, [
                    'status' => 'error',
                    'message' => 'Payload error',
                    'errors' => [ 'Purchase token not found' ],
                ]);
            }

            $this->voidedPurchaseNotificationsRepository->add(
                $purchaseTokenRow,
                $voidedPurchaseNotificationData->orderId,
                $voidedPurchaseNotificationData->productType,
                $voidedPurchaseNotificationData->refundType ?? null,
                $eventTime,
            );

            return new JsonApiResponse(IResponse::S200_OK, [
                'status' => 'ok',
                'result' => 'Developer Notification acknowledged.',
            ]);
        }

        // TODO: what to do with different notifications (oneTimeProductNotification and testNotification)?
        if (!isset($developerNotification->subscriptionNotification)) {
            return $this->logAndReturnPayloadError("Only SubscriptionNotification is supported.");
        }
        if ($developerNotification->subscriptionNotification->version !== "1.0") {
            return $this->logAndReturnPayloadError("Only version 1.0 of SubscriptionNotification is supported.");
        }

        $purchaseTokenRow = $this->purchaseTokensRepository->add(
            $developerNotification->subscriptionNotification->purchaseToken,
            $developerNotification->packageName,
            $developerNotification->subscriptionNotification->subscriptionId
        );

        // exception from mysql will stop execution; message won't be acknowledged; no need to send internal mysql exception to google
        $this->developerNotificationsRepository->add(
            $purchaseTokenRow,
            $eventTime,
            $developerNotification->subscriptionNotification->notificationType,
            DeveloperNotificationsRepository::STATUS_NEW
        );

        $response = new JsonApiResponse(Response::S200_OK, [
            'status' => 'ok',
            'result' => 'Developer Notification acknowledged.',
            ]);
        return $response;
    }

    private function logAndReturnPayloadError(string $errorMessage): JsonApiResponse
    {
        // log as error; google probably changed payload
        Debugger::log(
            "Google Play Pub/Sub pushing Developer Notification with different payload. Error: [{$errorMessage}].",
            Debugger::ERROR
        );

        $response = new JsonApiResponse(Response::S400_BAD_REQUEST, [
            'status' => 'error',
            'message' => 'Payload error',
            'errors' => [ $errorMessage ],
        ]);
        return $response;
    }
}
