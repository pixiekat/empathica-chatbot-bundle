<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Services;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Pixiekat\EmpathicaChatbot\Entity\ChatbotPreferences;
use Pixiekat\EmpathicaChatbot\Repository\ChatbotPreferencesRepository;
use Pixiekat\EmpathicaChatbot\Services\ChatbotPreferencesManager;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;

/**
 * "Opening the chat never writes to the database."
 *
 * That promise is what makes the bundle plug-and-play: a host can render
 * chatbot_preferences() in every page's layout (for the widget, say) without
 * creating a row for every user who ever loads a page. These tests hold the
 * manager to it with a mocked EntityManager that fails on any persist() or
 * flush() it isn't expecting.
 */
final class ChatbotPreferencesManagerTest extends TestCase {

  private function user(?int $id): HelpersUserInterface {
    $user = $this->createStub(HelpersUserInterface::class);
    $user->method('getId')->willReturn($id);
    return $user;
  }

  public function testNewUserGetsConfiguredDefaultsWithoutAWrite(): void {
    $repository = $this->createStub(ChatbotPreferencesRepository::class);
    $repository->method('findOneForUser')->willReturn(null);

    $entityManager = $this->createMock(EntityManagerInterface::class);
    $entityManager->expects($this->never())->method('persist');
    $entityManager->expects($this->never())->method('flush');

    $preferences = (new ChatbotPreferencesManager($repository, $entityManager, 'Gemini'))->forUser($this->user(7));

    // The configured default (empathica_chatbot.default_provider), not the
    // entity's own last-resort constant.
    $this->assertSame('Gemini', $preferences->getProvider());
    $this->assertSame(ChatbotPreferences::DEFAULT_LEVEL, $preferences->getLiteracyLevel());
    $this->assertSame(ChatbotPreferences::DEFAULT_LEVEL, $preferences->getEmpathyLevel());
    $this->assertFalse($preferences->isDebugModeEnabled(), 'Debug mode must be off unless an admin turns it on.');
  }

  public function testSavedPreferencesAreReturnedAsStored(): void {
    $user = $this->user(7);
    $stored = (new ChatbotPreferences($user))->setProvider('EmpathicaAgent')->setLiteracyLevel(1);

    $repository = $this->createStub(ChatbotPreferencesRepository::class);
    $repository->method('findOneForUser')->willReturn($stored);

    $manager = new ChatbotPreferencesManager($repository, $this->createStub(EntityManagerInterface::class), 'Gemini');

    $this->assertSame($stored, $manager->forUser($user));
  }

  /**
   * Memoised per user: a page that asks several times (the toolbar, the chat
   * footer, the widget) costs one query, and every caller sees the same
   * object, so a change made by one is seen by the rest.
   */
  public function testLookupIsMemoisedPerUser(): void {
    $repository = $this->createMock(ChatbotPreferencesRepository::class);
    $repository->expects($this->exactly(2))->method('findOneForUser')->willReturn(null);

    $manager = new ChatbotPreferencesManager($repository, $this->createStub(EntityManagerInterface::class));
    $alice = $this->user(1);
    $bob = $this->user(2);

    $this->assertSame($manager->forUser($alice), $manager->forUser($alice));
    $this->assertNotSame($manager->forUser($alice), $manager->forUser($bob));
  }

  public function testSaveIsTheOnlyWrite(): void {
    $preferences = new ChatbotPreferences($this->user(7));

    $entityManager = $this->createMock(EntityManagerInterface::class);
    $entityManager->expects($this->once())->method('persist')->with($preferences);
    $entityManager->expects($this->once())->method('flush');

    (new ChatbotPreferencesManager($this->createStub(ChatbotPreferencesRepository::class), $entityManager))->save($preferences);
  }
}
