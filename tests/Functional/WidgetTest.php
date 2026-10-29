<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Functional;

use Pixiekat\EmpathicaChatbot\Twig\Extension\WidgetExtension;

/**
 * The floating widget's server side: the same /chat URL, answered two ways.
 *
 * The browser half (opening, Escape, focus) is JavaScript, and is in the
 * manual QA checklist in docs/qa-testing.md instead.
 */
final class WidgetTest extends ChatbotWebTestCase {

  /**
   * From the widget's frame: just the frame, none of the host's page around
   * it, and a Vary header so a cache can't mix the two up.
   */
  public function testWidgetRequestGetsTheSlimFrame(): void {
    $this->loginAs($this->createUser());
    $this->client->request('GET', $this->url('empathica_chatbot_index'), server: ['HTTP_TURBO_FRAME' => WidgetExtension::FRAME_ID]);

    $this->assertResponseIsSuccessful();
    $html = (string) $this->client->getResponse()->getContent();
    $this->assertStringStartsWith('<turbo-frame id="' . WidgetExtension::FRAME_ID . '"', ltrim($html));
    $this->assertStringNotContainsString('<html', $html);
    $this->assertContains('Turbo-Frame', $this->client->getResponse()->getVary());
  }

  /**
   * A normal visit: the full host page, and no widget launcher, because on
   * /chat the chat already fills the page.
   */
  public function testFullPageHasTheHostLayoutAndNoLauncher(): void {
    $this->loginAs($this->createUser());
    $this->client->request('GET', $this->url('empathica_chatbot_index'));

    $this->assertResponseIsSuccessful();
    $html = (string) $this->client->getResponse()->getContent();
    $this->assertStringContainsString('<html', $html);
    $this->assertSelectorExists('[data-empathica-chatbot]');
    $this->assertSelectorNotExists('[data-empathica-chatbot-widget]');
    $this->assertContains('Turbo-Frame', $this->client->getResponse()->getVary());
  }
}
