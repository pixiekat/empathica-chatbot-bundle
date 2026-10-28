<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Security\Voter;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\EmpathicaChatbot\Interfaces;
use Pixiekat\SymfonyHelpers\Security as PixieSecurity;
use Pixiekat\SymfonyHelpers\Services\FeatureChecker;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Grants the bundle's own attributes. See DefaultVoterInterface for each one.
 *
 * Extends the SymfonyHelpers BaseVoter for its role helpers. Its FEATURE
 * constant is left null, because BaseVoter's flag checks the *SymfonyHelpers*
 * feature list — this bundle's flags live under empathica_chatbot.features and
 * are injected below instead.
 */
final class DefaultVoter extends PixieSecurity\Voter\BaseVoter implements Interfaces\Security\Voter\DefaultVoterInterface {

  /**
   * @param bool $adminEnabled
   *   `empathica_chatbot.features.admin`.
   * @param string $adminRole
   *   `empathica_chatbot.admin_role`. Checked through isGranted(), so the
   *   host's role_hierarchy applies — ROLE_SUPER_ADMIN satisfies ROLE_ADMIN.
   */
  public function __construct(
    Security $security,
    LoggerInterface $logger,
    FeatureChecker $features,
    EntityManagerInterface $entityManager,
    private readonly bool $adminEnabled = true,
    private readonly string $adminRole = 'ROLE_ADMIN',
  ) {
    parent::__construct($security, $logger, $features, $entityManager);
  }

  protected function supports(string $attribute, mixed $subject): bool {
    // No subject is ever needed: ownership of a conversation is enforced in
    // the repository query, not by passing the entity in here.
    return in_array($attribute, $this->getAttributes(), true) && $subject === null;
  }

  protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool {
    if (!$token->getUser() instanceof UserInterface) {
      return false;
    }

    return match ($attribute) {
      self::ADMIN_ACCESS_ADMIN_FUNCTIONS => $this->adminEnabled && $this->security->isGranted($this->adminRole),
      self::CAN_CHANGE_RESEARCH_SETTINGS => $this->security->isGranted($this->adminRole),
      self::CAN_VIEW_HISTORY => $this->isAuthenticated(),
      // Anything else: deny.
      default => false,
    };
  }

}
