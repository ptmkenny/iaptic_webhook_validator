<?php

declare(strict_types=1);

namespace Drupal\iaptic_webhook_validator\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\json_schema_validator\Enum\JsonSchema;
use Drupal\json_schema_validator\JsonSchemaValidator;
use Drupal\iaptic_webhook_validator\WebhookUuidLookup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Webmozart\Assert\Assert;

/**
 * Defines a controller for managing Iaptic receipts.
 */
final class IapticController extends ControllerBase {
  use LoggerChannelTrait;

  const LOGGER_CHANNEL = 'iaptic';

  /**
   * The HTTP request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected Request $request;

  /**
   * The UUID lookup service.
   *
   * @var \Drupal\iaptic_webhook_validator\WebhookUuidLookup
   */
  protected WebhookUuidLookup $uuidLookup;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The JSON Schema validator.
   *
   * @var \Drupal\json_schema_validator\JsonSchemaValidator
   */
  protected JsonSchemaValidator $jsonSchemaValidator;

  /**
   * Constructs a FoveaController object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param \Drupal\webhook_entities\WebhookUuidLookup $uuid_lookup
   *   An instance of the UUID lookup service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\json_schema_validator\JsonSchemaValidator $json_schema_validator
   *   The JSON Schema validator.
   */
  public function __construct(Request $request, WebhookUuidLookup $uuid_lookup, ConfigFactoryInterface $config_factory, JsonSchemaValidator $json_schema_validator) {
    $this->request = $request;
    $this->uuidLookup = $uuid_lookup;
    $this->configFactory = $config_factory;
    $this->jsonSchemaValidator = $json_schema_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): IapticController {
    $current_request = $container->get('request_stack')->getCurrentRequest();
    Assert::isInstanceOf($current_request, AssertClass::Request->value, 'Current request has invalid type! %s');

    return new self(
      $current_request,
      $container->get('webhook_entities.uuid_lookup'),
      $container->get('config.factory'),
      $container->get('json_schema_validator.validator')
    );
  }

  /**
   * Listens for webhook notifications and queues them for processing.
   *
   * The endpoint must be set at https://www.iaptic.com/settings.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Webhook providers typically expect an HTTP 200 (OK) response.
   */
  public function listener(): JsonResponse {
    // Prepare the response.
    $response = new JsonResponse(['message' => 'Notification received.'], 200);

    // Capture the contents of the notification (payload).
    $payload = $this->request->getContent(FALSE);

    // Update the user account with the receipt.
    $this->processItem("$payload");

    // Respond with the success message.
    return $response;
  }

  /**
   * Process the webhook payload.
   */
  public function processItem(string $payload): void {
    // Only process the payload if it contains data.
    Assert::stringNotEmpty($payload, 'Payload cannot be empty! %s');

    // Log the object.
    $this->getLogger(self::LOGGER_CHANNEL)->debug($payload);

    // Validate the payload is as expected.
    $this->jsonSchemaValidator->validateJsonSchema($payload, 'iaptic-webhook');

    // Decode the JSON payload.
    $entity_data = json_decode($payload, TRUE);
    Assert::isArray($entity_data, 'Failed to decode JSON data in webhook! %s');

    $iap_secret = $this->configFactory->get('card_store.settings')->get('secrets.fovea');
    Assert::stringNotEmpty($iap_secret, 'IAP secret cannot be empty! %s');
    // Ensure the payload contains the Fovea secret key.
    if (isset($entity_data['password']) && $entity_data['password'] === $iap_secret) {
      if (isset($entity_data['applicationUsername'])) {
        $uuid = $entity_data['applicationUsername'];
        // Determine whether an existing Drupal entity already
        // corresponds to the incoming UUID.
        $account = $this->uuidLookup->findUserEntity($uuid);

        // Ensure a Drupal entity to modify exists.
        if ($account !== FALSE) {
          assert($account instanceof UserBundle);
          $this->getLogger(self::LOGGER_CHANNEL)->info('Adding entity data to account @uuid @entity_data ', [
            '@uuid' => $uuid,
            '@entity_data' => json_encode($entity_data),
          ]);

          // DO WHATEVER YOU WANT WITH THE RECEIPT HERE. IN MY CASE, I ADD IT TO A JSON FIELD ON THE USER ACCOUNT.
        }
        else {
          $this->getLogger(self::LOGGER_CHANNEL)->warning('Webhook notification received for UUID @uuid but no corresponding Drupal entity exists', [
            '@uuid' => $uuid,
          ]);
        }
      }
      else {
        $this->getLogger(self::LOGGER_CHANNEL)->warning('Webhook notification received but no UUID set.');
      }
    }
    else {
      $this->getLogger(self::LOGGER_CHANNEL)->warning('Webhook notification received but not processed because secret key was wrong: @secret_key', [
        '@secret_key' => $entity_data['password'],
      ]);
    }
  }

  /**
   * Checks access for incoming webhook notifications.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(): AccessResultInterface {
    // The access check is handled when creating the entity.
    return AccessResult::allowed();
  }

}
