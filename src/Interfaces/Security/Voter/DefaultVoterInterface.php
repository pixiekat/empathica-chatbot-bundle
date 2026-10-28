<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Interfaces\Security\Voter;

/**
 * Attributes the chatbot bundle votes on.
 *
 * Every value is prefixed with `empathica_chatbot.`. In the standalone app they
 * were bare phrases ("access administration"), which was fine while this was
 * the only voter in the application. Inside a host with its own voters — and
 * the SymfonyHelpers voters — a bare phrase could be claimed by someone else,
 * and under Symfony's default `affirmative` strategy whichever voter grants it
 * wins. The prefix makes the attribute unambiguously ours.
 */
interface DefaultVoterInterface {

  /**
   * The researcher screens: users with conversations, transcripts, CSV export.
   *
   * Granted only when `empathica_chatbot.features.admin` is on AND the user
   * holds `empathica_chatbot.admin_role`.
   */
  public const ADMIN_ACCESS_ADMIN_FUNCTIONS = 'empathica_chatbot.admin';

  /**
   * Research-only controls on the preferences form: provider, persona, debug
   * mode and the testing-only empathy levels 4 and 5. Holds the admin role,
   * but independent of the admin *screens* flag — turning the screens off
   * should not also take away the ability to run a test conversation.
   */
  public const CAN_CHANGE_RESEARCH_SETTINGS = 'empathica_chatbot.research_settings';

  /**
   * Lets a logged-in user view their own conversation list. The controller
   * still scopes every query to the current user, so this grant never lets one
   * user see another's transcript.
   */
  public const CAN_VIEW_HISTORY = 'empathica_chatbot.view_history';

}
