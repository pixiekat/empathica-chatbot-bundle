<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Twig\Extension;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lets the chat templates know whether they are being rendered inside the
 * floating chat widget rather than as a full page.
 *
 * ── How the widget loads the chat ───────────────────────────────────────────
 * The widget (templates/widget/_launcher.html.twig) holds a
 * <turbo-frame id="empathica-chatbot-widget-frame" src="/chat/">. When Turbo
 * fetches that src, and every link click or form post made inside the frame
 * afterwards, it sends a `Turbo-Frame: empathica-chatbot-widget-frame`
 * request header. Redirects keep the header, so the chat's own
 * POST → redirect → GET cycle stays inside the frame as well.
 *
 * The chat layout checks chatbot_is_widget_frame() and, when it is true,
 * extends a slim frame template instead of the host's full layout. Same
 * routes, same controllers, same CSRF tokens and session. Nothing about
 * the chat itself has to know the widget exists.
 *
 * Without JavaScript (or without Turbo) the header is never sent, so every
 * chat URL keeps rendering as the normal full page.
 */
class WidgetExtension extends AbstractExtension {

  /**
   * The id of the widget's <turbo-frame>. Turbo matches frames by id, so the
   * id the widget renders and the id the frame layout renders must be the same
   * string. Both read it through chatbot_widget_frame_id() so it lives here,
   * in one place.
   */
  public const FRAME_ID = 'empathica-chatbot-widget-frame';

  /**
   * The request header Turbo sends on every frame-scoped request.
   */
  public const FRAME_HEADER = 'Turbo-Frame';

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {
  }

  public function getFunctions(): array {
    return [
      // chatbot_ prefixed: Twig function names are global to the host app.
      new TwigFunction('chatbot_widget_frame_id', static fn (): string => self::FRAME_ID),
      new TwigFunction('chatbot_is_widget_frame', [$this, 'isWidgetFrame']),
    ];
  }

  /**
   * Is the current page being rendered for the widget's frame?
   *
   * The main request is used, not the current one: an embedded sub-request
   * (a render(controller(...)) call) belongs to whatever page the main request
   * is drawing, and does not carry the header itself.
   */
  public function isWidgetFrame(): bool {
    return $this->requestStack->getMainRequest()?->headers->get(self::FRAME_HEADER) === self::FRAME_ID;
  }
}
