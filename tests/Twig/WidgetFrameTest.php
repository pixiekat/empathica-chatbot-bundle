<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Pixiekat\EmpathicaChatbot\EventSubscriber\WidgetFrameVarySubscriber;
use Pixiekat\EmpathicaChatbot\Twig\Extension\WidgetExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The two halves of "the same URL renders two ways":
 *
 *   - WidgetExtension decides, from the Turbo-Frame request header, whether a
 *     chat page is being drawn for the floating widget;
 *   - WidgetFrameVarySubscriber tells caches that the answer depends on that
 *     header, so the widget's slim fragment is never served as a full page.
 *
 * Plain TestCase: both classes only look at a Request / Response.
 */
final class WidgetFrameTest extends TestCase {

  private function extensionFor(?Request $mainRequest): WidgetExtension {
    $stack = new RequestStack();
    if ($mainRequest !== null) {
      $stack->push($mainRequest);
    }
    return new WidgetExtension($stack);
  }

  public function testWidgetFrameIsDetectedFromItsOwnFrameId(): void {
    $request = Request::create('/chat/');
    $request->headers->set('Turbo-Frame', WidgetExtension::FRAME_ID);

    $this->assertTrue($this->extensionFor($request)->isWidgetFrame());
  }

  /**
   * A host page may use Turbo Frames of its own. Their header must not switch
   * the chat into widget mode, or a host frame that happens to load /chat
   * would get the slim layout with no host navigation.
   */
  public function testOtherFramesAndPlainRequestsAreNotTheWidget(): void {
    $hostFrame = Request::create('/chat/');
    $hostFrame->headers->set('Turbo-Frame', 'some-host-frame');

    $this->assertFalse($this->extensionFor($hostFrame)->isWidgetFrame());
    $this->assertFalse($this->extensionFor(Request::create('/chat/'))->isWidgetFrame());
    // No request at all (a console command rendering a template).
    $this->assertFalse($this->extensionFor(null)->isWidgetFrame());
  }

  private function dispatchResponse(string $route, int $type = HttpKernelInterface::MAIN_REQUEST): Response {
    $request = Request::create('/');
    $request->attributes->set('_route', $route);
    $response = new Response();
    $response->setVary('Accept-Language');

    (new WidgetFrameVarySubscriber())->onResponse(
      new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response),
    );

    return $response;
  }

  /**
   * Added to the bundle's routes, and APPENDED to what was already there.
   */
  public function testVaryIsAppendedOnChatbotRoutes(): void {
    $vary = $this->dispatchResponse('empathica_chatbot_index')->getVary();

    $this->assertContains('Turbo-Frame', $vary);
    $this->assertContains('Accept-Language', $vary, 'An existing Vary value must be kept, not replaced.');
  }

  /**
   * The host's own responses are none of the bundle's business.
   */
  public function testHostRoutesAndSubRequestsAreLeftAlone(): void {
    $this->assertNotContains('Turbo-Frame', $this->dispatchResponse('app_dashboard')->getVary());
    $this->assertNotContains('Turbo-Frame', $this->dispatchResponse('empathica_chatbot_index', HttpKernelInterface::SUB_REQUEST)->getVary());
  }
}
