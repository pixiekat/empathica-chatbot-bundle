<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot;

use Pixiekat\EmpathicaChatbot\DependencyInjection\EmpathicaChatbotExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * EMPATHICA chatbot, packaged as a reusable Symfony bundle.
 *
 * Register it in the host's config/bundles.php AFTER
 * PixiekatSymfonyHelpersBundle — this bundle maps its user relations against
 * that bundle's HelpersUserInterface and relies on it to resolve the interface
 * to the host's user class.
 *
 * Same shape as PixiekatSymfonyHelpersBundle: an AbstractBundle whose path is
 * the package root (so templates/, translations/ and config/ sit beside src/,
 * and Twig registers templates/ as the @PixiekatEmpathicaChatbot namespace),
 * with a separate Extension class doing the container work.
 */
class PixiekatEmpathicaChatbotBundle extends AbstractBundle {

  /**
   * The package root, one level above src/.
   */
  public function getPath(): string {
    return \dirname(__DIR__);
  }

  /**
   * Hands back our own extension so the config alias is `empathica_chatbot`
   * (derived from the class name EmpathicaChatbotExtension) rather than the
   * `pixiekat_empathica_chatbot` AbstractBundle would otherwise derive.
   */
  public function getContainerExtension(): ?ExtensionInterface {
    return new EmpathicaChatbotExtension();
  }
}
