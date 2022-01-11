<?php

namespace Crm\GooglePlayBillingModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Api\JsonValidationTrait;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\GooglePlayBillingModule\Repository\DeveloperNotificationsRepository;
use Crm\GooglePlayBillingModule\Repository\PurchaseTokensRepository;
use Nette\Http\Response;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Tracy\Debugger;

class DeveloperNotificationPushWebhookApiHandler extends ApiHandler
{
    use JsonValidationTrait;

    private $developerNotificationsRepository;

    private $purchaseTokensRepository;

    public function __construct(
        DeveloperNotificationsRepository $developerNotificationsRepository,
        PurchaseTokensRepository $purchaseTokensRepository
    ) {
        $this->developerNotificationsRepository = $developerNotificationsRepository;
        $this->purchaseTokensRepository = $purchaseTokensRepository;
    }

    public function params(): array
    {
        return [];
    }

    public function handle(ApiAuthorizationInterface $authorization)
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
        // TODO: what to do with different notifications?
        if (!isset($developerNotification->subscriptionNotification)) {
            return $this->logAndReturnPayloadError("Only SubscriptionNotification is supported.");
        }
        if ($developerNotification->subscriptionNotification->version !== "1.0") {
            return $this->logAndReturnPayloadError("Only version 1.0 of SubscriptionNotification is supported.");
        }

        $eventTime = DateTime::createFromFormat(
            "U.u",
            sprintf("%.6f", $developerNotification->eventTimeMillis / 1000)
        );
        $eventTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));

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

        $response = new JsonResponse([
            'status' => 'ok',
            'result' => 'Developer Notification acknowledged.',
            ]);
        $response->setHttpCode(Response::S200_OK);
        return $response;
    }

    private function logAndReturnPayloadError(string $errorMessage): JsonResponse
    {
        // log as error; google probably changed payload
        Debugger::log(
            "Google Play Pub/Sub pushing Developer Notification with different payload. Error: [{$errorMessage}].",
            Debugger::ERROR
        );

        $response = new JsonResponse([
            'status' => 'error',
            'message' => 'Payload error',
            'errors' => [ $errorMessage ],
        ]);
        $response->setHttpCode(Response::S400_BAD_REQUEST);
        return $response;
    }
}
