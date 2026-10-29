<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Form;

use Pixiekat\EmpathicaChatbot\Entity\ChatbotPreferences;
use Pixiekat\EmpathicaChatbot\Form\ChatbotInputType;
use Pixiekat\EmpathicaChatbot\Form\PreferencesType;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The chat box and the settings form: who sees which fields, and the limits.
 *
 * Boots the host kernel for a real form factory, validator and voter (so the
 * host's role_hierarchy is in play), but touches no database: users live only
 * in the security token, and preferences are unsaved entities.
 *
 * Two details carried over from the host's own form tests:
 *   - roles are passed to the token explicitly, not read off the user;
 *   - csrf_protection is turned off, because submit() here bypasses the
 *     request and so never carries a token. CSRF itself is covered by the
 *     functional tests, which go through real requests.
 */
final class ChatbotFormsTest extends KernelTestCase {

  /**
   * @param list<string> $roles
   */
  private function loginAs(array $roles): void {
    self::bootKernel();
    $user = new InMemoryUser('tester@example.com', null, $roles);
    self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $roles));
  }

  private function preferencesForm(): FormInterface {
    $preferences = new ChatbotPreferences($this->createStub(HelpersUserInterface::class));

    return self::getContainer()->get(FormFactoryInterface::class)
      ->create(PreferencesType::class, $preferences, ['csrf_protection' => false]);
  }

  /**
   * A participant must never be offered the research controls, nor the
   * testing-only empathy arms (4 = anti empathy, 5 = confrontational).
   */
  public function testParticipantSeesOnlyTheParticipantFields(): void {
    $this->loginAs(['ROLE_USER']);
    $form = $this->preferencesForm();

    $this->assertTrue($form->has('memory'));
    $this->assertTrue($form->has('literacyLevel'));
    $this->assertTrue($form->has('empathyLevel'));
    foreach (['persona', 'provider', 'debugModeEnabled'] as $researchField) {
      $this->assertFalse($form->has($researchField), "Participants must not see '$researchField'.");
    }

    $offered = array_map(static fn ($choice) => $choice->value, $form->get('empathyLevel')->createView()->vars['choices']);
    $this->assertSame(['1', '2', '3'], array_values($offered));
  }

  /**
   * Not offering level 4 isn't enough: a hand-crafted POST could still send
   * it. It must be rejected.
   */
  public function testParticipantCannotSubmitATestingOnlyEmpathyLevel(): void {
    $this->loginAs(['ROLE_USER']);
    $form = $this->preferencesForm();

    $form->submit(['memory' => '', 'literacyLevel' => '2', 'empathyLevel' => '4']);

    $this->assertFalse($form->isValid());
    $this->assertFalse($form->get('empathyLevel')->isValid());
  }

  public function testResearcherSeesEverything(): void {
    $this->loginAs(['ROLE_ADMIN']);
    $form = $this->preferencesForm();

    foreach (['persona', 'provider', 'debugModeEnabled'] as $researchField) {
      $this->assertTrue($form->has($researchField), "Researchers should see '$researchField'.");
    }
    $offered = array_map(static fn ($choice) => $choice->value, $form->get('empathyLevel')->createView()->vars['choices']);
    $this->assertSame(['1', '2', '3', '4', '5'], array_values($offered));
  }

  /**
   * The saved context is sent with every message, so it's capped, and the
   * text box tells the browser the same limit.
   */
  public function testSavedContextIsCapped(): void {
    $this->loginAs(['ROLE_USER']);

    $form = $this->preferencesForm();
    $this->assertSame(ChatbotPreferences::MEMORY_MAX_LENGTH, $form->get('memory')->getConfig()->getOption('attr')['maxlength']);

    $form->submit(['memory' => str_repeat('a', ChatbotPreferences::MEMORY_MAX_LENGTH + 1), 'literacyLevel' => '2', 'empathyLevel' => '2']);
    $this->assertFalse($form->get('memory')->isValid());

    $form = $this->preferencesForm();
    $form->submit(['memory' => str_repeat('a', ChatbotPreferences::MEMORY_MAX_LENGTH), 'literacyLevel' => '2', 'empathyLevel' => '2']);
    $this->assertTrue($form->isValid(), 'Exactly the limit is allowed.');
  }

  private function chatForm(): FormInterface {
    return self::getContainer()->get(FormFactoryInterface::class)
      ->create(ChatbotInputType::class, ['message' => ''], ['csrf_protection' => false]);
  }

  public function testChatMessageIsCapped(): void {
    $this->loginAs(['ROLE_USER']);

    $form = $this->chatForm();
    $this->assertSame(ChatbotInputType::MESSAGE_MAX_LENGTH, $form->get('message')->getConfig()->getOption('attr')['maxlength']);

    $form->submit(['message' => str_repeat('a', ChatbotInputType::MESSAGE_MAX_LENGTH + 1)]);
    $this->assertFalse($form->isValid());

    $form = $this->chatForm();
    $form->submit(['message' => 'Can I take ibuprofen with my blood pressure tablets?']);
    $this->assertTrue($form->isValid());
  }

  public function testBlankChatMessageIsRejected(): void {
    $this->loginAs(['ROLE_USER']);

    $form = $this->chatForm();
    $form->submit(['message' => '   ']);
    $this->assertFalse($form->isValid());
  }
}
