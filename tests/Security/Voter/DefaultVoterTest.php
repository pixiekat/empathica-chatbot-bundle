<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Security\Voter;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiekat\EmpathicaChatbot\Interfaces\Security\Voter\DefaultVoterInterface as Attr;
use Pixiekat\EmpathicaChatbot\Security\Voter\DefaultVoter;
use Pixiekat\SymfonyHelpers\Services\FeatureChecker;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The full permission matrix of the bundle's own voter.
 *
 * Plain TestCase. Security is stubbed so "does this user hold the admin role?"
 * is answered by the test, which keeps the matrix readable and keeps the host's
 * role_hierarchy out of it (that hierarchy is Symfony's job, and is exercised
 * by the functional tests instead).
 *
 * The rows are the reason this test exists. The `features.admin` switch has to
 * close the admin SCREENS without also taking research settings away from
 * researchers, and that's an easy thing to get backwards.
 */
final class DefaultVoterTest extends TestCase {

  /**
   * @param bool $hasAdminRole What isGranted($adminRole) should answer.
   */
  private function voter(bool $adminEnabled, bool $hasAdminRole): DefaultVoter {
    $security = $this->createStub(Security::class);
    $security->method('isGranted')->willReturnCallback(
      // ROLE_USER: everyone in these tests is logged in. The admin role:
      // whatever the row says.
      static fn (mixed $attribute): bool => match ($attribute) {
        'ROLE_USER' => true,
        'ROLE_ADMIN' => $hasAdminRole,
        default => false,
      },
    );

    return new DefaultVoter(
      $security,
      new NullLogger(),
      // No SymfonyHelpers features configured: the voter's own FEATURE is null,
      // and its flag comes from the constructor below instead.
      new FeatureChecker([]),
      $this->createStub(EntityManagerInterface::class),
      adminEnabled: $adminEnabled,
      adminRole: 'ROLE_ADMIN',
    );
  }

  private function loggedIn(): UsernamePasswordToken {
    $user = new InMemoryUser('participant@example.com', null, ['ROLE_USER']);
    return new UsernamePasswordToken($user, 'main', $user->getRoles());
  }

  /**
   * @return iterable<string, array{bool, bool, string, int}>
   *   features.admin, holds admin role, attribute, expected vote.
   */
  public static function matrixProvider(): iterable {
    $granted = VoterInterface::ACCESS_GRANTED;
    $denied = VoterInterface::ACCESS_DENIED;

    // Admin screens: need BOTH the switch and the role.
    yield 'admin screens: on + admin' => [true, true, Attr::ADMIN_ACCESS_ADMIN_FUNCTIONS, $granted];
    yield 'admin screens: on + participant' => [true, false, Attr::ADMIN_ACCESS_ADMIN_FUNCTIONS, $denied];
    yield 'admin screens: off + admin' => [false, true, Attr::ADMIN_ACCESS_ADMIN_FUNCTIONS, $denied];
    yield 'admin screens: off + participant' => [false, false, Attr::ADMIN_ACCESS_ADMIN_FUNCTIONS, $denied];

    // Research settings: the role only. Turning the admin SCREENS off must not
    // stop a researcher choosing a provider or persona.
    yield 'research: on + admin' => [true, true, Attr::CAN_CHANGE_RESEARCH_SETTINGS, $granted];
    yield 'research: off + admin' => [false, true, Attr::CAN_CHANGE_RESEARCH_SETTINGS, $granted];
    yield 'research: on + participant' => [true, false, Attr::CAN_CHANGE_RESEARCH_SETTINGS, $denied];

    // Own history: any logged-in user.
    yield 'history: participant' => [true, false, Attr::CAN_VIEW_HISTORY, $granted];
    yield 'history: admin switch off' => [false, false, Attr::CAN_VIEW_HISTORY, $granted];
  }

  #[DataProvider('matrixProvider')]
  public function testPermissionMatrix(bool $adminEnabled, bool $hasAdminRole, string $attribute, int $expected): void {
    $this->assertSame($expected, $this->voter($adminEnabled, $hasAdminRole)->vote($this->loggedIn(), null, [$attribute]));
  }

  /**
   * Nobody logged in: every attribute is denied, even with the switch on and
   * the role check (wrongly) answering yes.
   */
  public function testAnonymousIsDeniedEverything(): void {
    $voter = $this->voter(true, true);

    foreach ([Attr::ADMIN_ACCESS_ADMIN_FUNCTIONS, Attr::CAN_CHANGE_RESEARCH_SETTINGS, Attr::CAN_VIEW_HISTORY] as $attribute) {
      $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, [$attribute]), $attribute);
    }
  }

  /**
   * The voter only answers for its own, prefixed attributes, and only with no
   * subject. Anything else it abstains on, so it can never accidentally grant
   * an attribute that belongs to the host or to another bundle.
   */
  public function testAbstainsOnForeignAttributesAndSubjects(): void {
    $voter = $this->voter(true, true);

    $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->loggedIn(), null, ['ROLE_ADMIN']));
    $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->loggedIn(), null, ['admin']));
    $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->loggedIn(), new \stdClass(), [Attr::CAN_VIEW_HISTORY]));
  }
}
