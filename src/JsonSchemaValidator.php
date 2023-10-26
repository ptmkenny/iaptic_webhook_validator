<?php

declare(strict_types=1);

namespace Drupal\iaptic_webhook_validator;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;

/**
 * Handles JSON validation exceptions.
 */
final class JsonSchemaValidator {

  // Where the schema files are stored on disk.
  // Todo: Change this to match your app.
  const SCHEMA_DIRECTORY = '/app/web/modules/custom/iaptic_webhook_validator/schema';

  /**
   * Throws an exception when JSON is invalid according to JSON schema.
   */
  public static function throwInvalidJsonException(ValidationResult $result, string $what_failed_to_validate, array|JsonMemoryDataInterface $json_array): void {
    $error = $result->error();
    $formatter = new ErrorFormatter();

    // Print helper.
    $print = function ($value) {
      return json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
      );
    };
    $default_multiple_message = $print($formatter->format($error, TRUE));
    $error_message = $print($formatter->formatOutput($error, 'verbose')) . $default_multiple_message;
    $json_debug_data = json_encode($json_array);
    $uid = \Drupal::currentUser()->id();
    throw new \Exception("$what_failed_to_validate failed to validate!\n
      ||\n
      uid: $uid
      ||\n
      $error_message\n
      ||\n
      JSON data: $json_debug_data");
  }

  /**
   * Perform validation of the given JSON schema.
   *
   * @param string $encoded_json
   *   The stringified JSON.
   * @param string $schema
   *   The relevant JSON schema.
   *
   * @return bool
   *   TRUE if validation succeeded. Throws an exception otherwise.
   */
  public function validateJsonSchema(string $encoded_json, string $schema): bool {
    $validator = new Validator();
    $resolver = $validator->loader()->resolver();
    // TODO: If you change the schema URLs, you need to change the base URL here.
    $resolver->registerPrefix('https://www.example.com' . '/', self::SCHEMA_DIRECTORY);
    $decoded_preferences = json_decode($encoded_json, FALSE);
    $result = $validator->validate($decoded_preferences, "https://www.example.com/$schema->value.schema.json");
    if ($result->isValid()) {
      $encoded_json = json_encode($decoded_preferences);
      return TRUE;
    }
    $decoded_json_associative_array = json_decode($encoded_json, TRUE);
    if (!is_array($decoded_json_associative_array)) {
      throw new \Exception("Failed to decode encoded JSON! $encoded_json");
    }
    JsonSchemaValidator::throwInvalidJsonException($result, $schema->value, $decoded_json_associative_array);
  }

}
