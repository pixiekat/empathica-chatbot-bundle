<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Pins the shape of the read-aloud route.
 *
 * The template builds a URL *template* for app.js by generating the route with
 * a numeric sentinel and swapping it for "__PART__". That only works if the
 * route actually ends in the part segment and the generator emits it — and
 * neither Twig linting nor PHPStan can tell you otherwise, because URL
 * generation is a runtime concern. The failure mode is a 500 while rendering
 * the chat page, which is about as visible as bugs get, so it is worth a
 * cheap guard.
 *
 * Two things bit here already and are covered below: passing "__PART__" to
 * path() directly throws, because the generator validates parameters against
 * the route requirements up front; and a parameter equal to its default is
 * omitted from the generated path, which would silently produce the wrong
 * template.
 */
final class TextToSpeechRouteTest extends KernelTestCase {

  private function generator(): UrlGeneratorInterface {
    self::bootKernel();

    return self::getContainer()->get('router');
  }

  /**
   * The sentinel round-trip the template relies on.
   */
  public function testUrlTemplateCanBeBuiltFromASentinel(): void {
    $url = $this->generator()->generate('app_tts', ['id' => 3, 'part' => 999999999]);

    $this->assertSame('/tts/3/999999999', $url);
    $this->assertSame('/tts/3/__PART__', str_replace('999999999', '__PART__', $url));
  }

  /**
   * A non-numeric part must be rejected at generation time. This is why the
   * template cannot simply pass "__PART__" to path().
   */
  public function testNonNumericPartIsRejected(): void {
    $this->expectException(\Symfony\Component\Routing\Exception\InvalidParameterException::class);
    $this->generator()->generate('app_tts', ['id' => 3, 'part' => '__PART__']);
  }

  /**
   * Part 0 is the route default, so it is omitted from the generated path.
   *
   * Documented here because it is the trap behind the sentinel approach: if
   * the template generated with part 0 and replaced the trailing segment, it
   * would rewrite the *message index* instead.
   */
  public function testDefaultPartIsOmittedFromTheUrl(): void {
    $this->assertSame('/tts/3', $this->generator()->generate('app_tts', ['id' => 3]));
  }
}
