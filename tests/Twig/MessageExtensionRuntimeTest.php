<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiekat\EmpathicaChatbot\Twig\Runtime\MessageExtensionRuntime;

/**
 * Stored-XSS guard for |chatbot_markdown.
 *
 * The templates print this filter's output with |raw, so this method is the
 * ONLY escaping between a stored message and the browser, both for the
 * participant who wrote it and for any admin reading the transcript later.
 * See the SECURITY note on MessageExtensionRuntime::parseMarkdown().
 *
 * The assertions are deliberately about what must NOT appear (a live tag, a
 * live attribute, a dangerous scheme) rather than about the exact HTML
 * Parsedown emits, so a harmless Parsedown upgrade doesn't break the test.
 */
final class MessageExtensionRuntimeTest extends TestCase {

  private function render(string $markdown): string {
    return (new MessageExtensionRuntime())->parseMarkdown($markdown);
  }

  /**
   * @return iterable<string, array{string, string}>
   *   The input, and a fragment that must never appear INSIDE an HTML tag.
   *   Inside a tag is what matters: the same characters as visible, escaped
   *   text are harmless, and are exactly what a researcher should see.
   */
  public static function attackProvider(): iterable {
    yield 'script tag' => ['<script>alert(1)</script>', 'script'];
    yield 'img onerror' => ['<img src=x onerror=alert(1)>', 'onerror'];
    yield 'svg onload' => ['<svg onload=alert(1)>', 'onload'];
    yield 'iframe' => ['<iframe src="https://evil.example"></iframe>', 'iframe'];
    yield 'javascript: markdown link' => ['[click me](javascript:alert(1))', 'javascript:'];
    yield 'data: markdown link' => ['[click me](data:text/html;base64,PHNjcmlwdD4=)', 'data:'];
    yield 'javascript: image' => ['![x](javascript:alert(1))', 'javascript:'];
    yield 'attribute breakout in link' => ['[x](https://ok.example" onmouseover="alert(1))', 'onmouseover'];
  }

  #[DataProvider('attackProvider')]
  public function testMarkupIsNeverPassedThrough(string $input, string $forbidden): void {
    $html = $this->render($input);

    // Every real tag in the output, i.e. every <...> Parsedown emitted.
    // Escaped input shows up as &lt;...&gt; and so is not matched here.
    preg_match_all('/<[^>]*>/', $html, $tags);
    foreach ($tags[0] as $tag) {
      $this->assertStringNotContainsStringIgnoringCase($forbidden, $tag, "Live markup in: $html");
    }
  }

  /**
   * The escaped text is still SHOWN, just inert. A message vanishing would
   * hide from a researcher what the participant actually typed.
   */
  public function testEscapedMarkupStaysVisibleAsText(): void {
    $this->assertStringContainsString('&lt;script&gt;', $this->render('<script>alert(1)</script>'));
  }

  /**
   * Ordinary Markdown from the model still renders: the escaping must not
   * break the formatting that makes medication answers readable.
   */
  public function testOrdinaryMarkdownStillRenders(): void {
    $html = $this->render("**Take with food.**\n\n- Morning\n- Evening\n\n[Leaflet](https://example.org/leaflet)");

    $this->assertStringContainsString('<strong>Take with food.</strong>', $html);
    $this->assertStringContainsString('<li>Morning</li>', $html);
    $this->assertStringContainsString('href="https://example.org/leaflet"', $html);
  }

  /**
   * Legacy rows were stored with nl2br() already applied. Those <br /> tags
   * become real line breaks instead of visible "&lt;br /&gt;" text.
   */
  public function testLegacyBreakTagsBecomeLineBreaks(): void {
    $html = $this->render('First line<br />Second line');

    $this->assertStringNotContainsString('&lt;br', $html);
    $this->assertMatchesRegularExpression('/First line<br\s*\/?>\s*Second line/', $html);
  }

  public function testEmptyAndNullInputRenderNothing(): void {
    $runtime = new MessageExtensionRuntime();
    $this->assertSame('', $runtime->parseMarkdown(''));
    $this->assertSame('', $runtime->parseMarkdown(null));
  }
}
