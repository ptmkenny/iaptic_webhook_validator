<?php

declare(strict_types=1);

namespace Drupal\iaptic_webhook_validator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Class WebhookUuidLookup.
 *
 * Attempts to load an entity by the UUID received from a webhook notification.
 */
class WebhookUuidLookup {

  /**
   * Entity Type Manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * WebhookUuidLookup constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity Type Manager service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Returns a user based on a UUID.
   */
  public function findUserEntity(string $uuid): UserInterface|FALSE {
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['uuid' => $uuid]);

    $found_user = reset($users);
    if ($found_user instanceof UserInterface) {
      return $found_user;
    }

    return FALSE;
  }

}
