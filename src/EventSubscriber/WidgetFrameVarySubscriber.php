<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\EventSubscriber;

use Pixiekat\EmpathicaChatbot\Twig\Extension\WidgetExtension;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds `Vary: Turbo-Frame` to every chatbot response.
 *
 * The same URL (/chat/, /chat/preferences, ...) now has two shapes: the full
 * page, and the slim frame the widget loads (see WidgetExtension). If a
 * response does not say it depends on that header, a browser cache or the
 * back/forward cache could hand the widget's frame fragment to a normal
 * page visit, or the whole page to the widget. Vary tells every cache to
 * keep the two apart.
 *
 * Scoped to the bundle's own routes (all named empathica_chatbot_*) so the
 * host's responses are never touched.
 */
final class WidgetFrameVarySubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => 'onResponse'];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $route = (string) $event->getRequest()->attributes->get('_route', '');
    if (!str_starts_with($route, 'empathica_chatbot_')) {
      return;
    }

    // Append, never replace: the response may already vary on other headers.
    $event->getResponse()->setVary(WidgetExtension::FRAME_HEADER, false);
  }
}
