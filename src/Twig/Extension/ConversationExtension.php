<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Twig\Extension;

use Pixiekat\EmpathicaChatbot\Twig\Runtime\ConversationExtensionRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Makes the current user's conversations and preferences available to any
 * template, including the host application's own layout.
 *
 * The conversation switcher lives in shared chrome rendered on every page —
 * but only the chat action was passing a $conversations variable. With strict_variables on (the default in dev), that means every
 * other page a logged-in user visits dies on an undefined variable.
 *
 * Passing it from every controller would be the wrong fix: a global navigation
 * element should not be every action's problem to feed. A Twig function keeps
 * the data next to the markup that needs it, and stays lazy — pages that do
 * not render the navbar never trigger the query.
 */
class ConversationExtension extends AbstractExtension {

  public function getFunctions(): array {
    return [
      // chatbot_ prefixed: Twig function names are global to the host app.
      new TwigFunction('chatbot_conversations', [ConversationExtensionRuntime::class, 'getConversations']),
      new TwigFunction('chatbot_conversation_counts', [ConversationExtensionRuntime::class, 'getMessageCounts']),
      new TwigFunction('chatbot_preferences', [ConversationExtensionRuntime::class, 'getPreferences']),
    ];
  }
}
