<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Export;

/**
 * Makes text safe to put in a CSV cell that will be opened in a spreadsheet.
 *
 * fputcsv() takes care of CSV *correctness*: it quotes commas, quotes and
 * newlines. It does nothing about CSV *injection*. A cell whose text starts
 * with =, +, -, @ (or a tab or carriage return) is run as a formula when the
 * file is opened in Excel, LibreOffice or Google Sheets, and a formula can
 * fetch URLs, or on older desktop installs launch programs.
 *
 * The chatbot exports hold free text a participant typed, and text an LLM
 * wrote in reply to them, so either could start with one of those
 * characters, by accident or on purpose. Prefixing such a value with a
 * single quote makes the spreadsheet show it as literal text.
 *
 * Its own class (it used to be a private method on AdminController) so it
 * can be unit-tested directly, and reused by any future export.
 *
 * @see https://owasp.org/www-community/attacks/CSV_Injection
 */
final class CsvSanitizer {

  /**
   * First characters that make a spreadsheet treat a cell as a formula.
   */
  public const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

  /**
   * @param string|null $value The raw cell text. Null becomes an empty cell.
   *
   * @return string The text, prefixed with ' if it would otherwise run.
   */
  public static function neutralise(?string $value): string {
    $value = (string) $value;
    if ($value !== '' && in_array($value[0], self::FORMULA_TRIGGERS, true)) {
      $value = "'" . $value;
    }
    return $value;
  }
}
