<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Services;

use Pixiekat\EmpathicaChatbot\Interfaces\Security\Voter\DefaultVoterInterface;
use Pixiekat\SymfonyHelpers\Interfaces\Services\AdminNavProviderInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Adds the chatbot's admin screens to the host's control panel menu.
 *
 * The SymfonyHelpers bundle collects every service implementing
 * AdminNavProviderInterface (the interface carries #[AutoconfigureTag], so
 * autoconfiguration is the whole registration step). That means installing
 * this bundle puts "Chatbot" in Infosage's admin menu without the host's
 * admin layout listing a single one of our routes.
 *
 * As that interface requires, this provider filters itself: it asks the same
 * voter attribute that guards AdminController, so the menu entry appears
 * exactly when the screens behind it would let you in — including
 * disappearing when `empathica_chatbot.features.admin` is off.
 */
class AdminNav implements AdminNavProviderInterface {

  public function __construct(
    private readonly AuthorizationCheckerInterface $authorization,
  ) {  }

  /**
   * {@inheritdoc}
   */
  public function sections(): array {
    if (!$this->authorization->isGranted(DefaultVoterInterface::ADMIN_ACCESS_ADMIN_FUNCTIONS)) {
      return [];
    }

    return [
      [
        'label' => 'Chatbot',
        'links' => [
          [
            'route' => 'empathica_chatbot_admin_users',
            'label' => 'Conversations',
            // Drill-down screens highlight the list they came from.
            'also' => [
              'empathica_chatbot_admin_user_conversations',
              'empathica_chatbot_admin_transcript',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Below the helpers' own sections (0) — the chatbot is one feature among
   * Infosage's many, not the main event in its control panel.
   */
  public function priority(): int {
    return 10;
  }
}
