<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pixiekat\EmpathicaChatbot\Entity\ChatbotPreferences;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;

/**
 * Storage access for ChatbotPreferences.
 *
 * Application code should not normally call this directly — use
 * ChatbotPreferencesManager::forUser(), which also handles "this user has never
 * saved anything" by handing back defaults.
 *
 * @extends ServiceEntityRepository<ChatbotPreferences>
 */
class ChatbotPreferencesRepository extends ServiceEntityRepository {

  public function __construct(ManagerRegistry $registry) {
    parent::__construct($registry, ChatbotPreferences::class);
  }

  /**
   * The saved preferences row for a user, or null if they have never saved.
   */
  public function findOneForUser(HelpersUserInterface $user): ?ChatbotPreferences {
    return $this->findOneBy(['user' => $user]);
  }
}
