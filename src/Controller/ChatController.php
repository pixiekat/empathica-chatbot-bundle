<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Controller;

use Pixiekat\EmpathicaChatbot\Entity;
use Pixiekat\EmpathicaChatbot\Form;
use Pixiekat\EmpathicaChatbot\Interfaces;
use Pixiekat\EmpathicaChatbot\Repository;
use Pixiekat\EmpathicaChatbot\Services;
use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The chat itself: the conversation page, the thread switcher, history and
 * the user's chatbot settings.
 *
 * Was App\Controller\AppController in the standalone chatbot-app. Inside a
 * bundle every route is mounted under whatever prefix the host chooses (see
 * config/routes.yaml in the bundle) and every route name carries the
 * `empathica_chatbot_` prefix, so nothing here can collide with a host route.
 *
 * Everything works as plain HTML forms and redirects (POST → redirect → GET).
 * Turbo and the floating widget enhance that; nothing depends on them.
 */
class ChatController extends AbstractController {

  /**
   * Session key holding the id of the thread the user is currently in.
   *
   * The session no longer stores the conversation itself — only which one is
   * open. Losing it costs you your place, not your history.
   */
  private const ACTIVE_CONVERSATION = 'active_conversation_id';

  /**
   * Set when the user has explicitly asked to begin a new thread.
   *
   * Needed because "no active conversation" is ambiguous on its own. Arriving
   * with no pointer usually means a fresh session, and the friendly thing to
   * do then is reopen the most recent thread. But after pressing "New
   * conversation" that same fallback would reopen the thread the user just
   * left, so the button would appear to do nothing at all.
   *
   * The flag is cleared the moment a new conversation is actually created —
   * conversations are created lazily, on the first message, so it has to
   * survive the redirect in between.
   */
  private const START_NEW_CONVERSATION = 'start_new_conversation';

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly EntityManagerInterface $entityManager,
    private readonly Services\ChatbotPreferencesManager $preferencesManager,
  ) {
  }

  #[IsGranted('ROLE_USER')]
  #[Route('/preferences', name: 'empathica_chatbot_preferences', methods: ['GET', 'POST'])]
  public function setPrefs(Request $request): Response {
    $session = $request->getSession();
    // Bound to the bundle's own preferences entity, never the host's User.
    // For a user who has never saved, this is an unsaved defaulted instance;
    // the first valid submit is what creates their row.
    $preferences = $this->preferencesManager->forUser($this->currentUser());
    $form = $this->createForm(Form\PreferencesType::class, $preferences, [
      'action' => $this->generateUrl('empathica_chatbot_preferences'),
      'method' => 'POST',
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted()) {
      if ($form->isValid()) {
        // Changing persona or tone mid-thread would make the transcript read
        // as if the assistant had changed personality, so a preference change
        // starts a fresh conversation on the next message. The old thread is
        // untouched and still reachable from the switcher.
        $this->beginNewConversation($session);
        $this->preferencesManager->save($preferences);

        $this->addFlash('success', 'Preferences saved.');
        return $this->redirectToRoute('empathica_chatbot_index');
      }
    }

    // 422 for an invalid submit, for the same Turbo reason as index():
    // a 200 answer to a form POST is discarded with "Form responses must
    // redirect to another location", so the validation errors would never
    // appear.
    return $this->render('@PixiekatEmpathicaChatbot/chatbot/set-prefs.html.twig', [
      'form' => $form->createView(),
    ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
  }

  #[IsGranted(Interfaces\Security\Voter\DefaultVoterInterface::CAN_VIEW_HISTORY, message: 'You do not have permission to view chat history.')]
  #[Route('/history', name: 'empathica_chatbot_history', methods: ['GET'])]
  public function history(Request $request, Repository\ConversationRepository $conversations): Response {
    $user = $this->currentUser();

    // Conversations per page. The navbar switcher shows the 20 most recent as
    // a quick way back; this is the full, paginated archive.
    $perPage = 15;

    // Read the requested page from the query string (?page=N). Default to 1,
    // and clamp the lower bound so a hand-typed ?page=0 or ?page=-3 can't push
    // setFirstResult() to a negative offset.
    $page = max(1, $request->query->getInt('page', 1));

    // Scoped to the logged-in user in the query itself. The voter only checks
    // that the requester is authenticated, so this is where we make sure a
    // user can only ever read *their own* transcripts.
    $paginator = $conversations->findByUserPaginated($user, $page, $perPage);
    $items = iterator_to_array($paginator);

    // count() on the Paginator triggers its COUNT query: the total for this
    // user, not just this page. At least one page, so an empty history still
    // reports "page 1 of 1".
    $totalItems = count($paginator);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));

    return $this->render('@PixiekatEmpathicaChatbot/chatbot/history.html.twig', [
      'conversations' => $items,
      'messageCounts' => $conversations->countMessagesFor($items),
      'currentPage' => $page,
      'totalPages' => $totalPages,
      'totalItems' => $totalItems,
    ]);
  }

  /**
   * The logged-in user, narrowed to HelpersUserInterface.
   *
   * getUser() is typed UserInterface, which carries no id. The bundle's
   * entities relate to HelpersUserInterface (resolved to the host's real
   * class by the SymfonyHelpers bundle), so that is the type we need — and
   * the ONLY thing this bundle ever asks of the host's user.
   *
   * Every action here is behind #[IsGranted('ROLE_USER')], so a user always
   * exists. The check catches a host whose firewall authenticates some other
   * kind of principal (an API token user, say) that cannot own a conversation.
   */
  private function currentUser(): HelpersUserInterface {
    $user = $this->getUser();

    if (!$user instanceof HelpersUserInterface) {
      throw $this->createAccessDeniedException('Authenticated principal does not implement HelpersUserInterface.');
    }

    return $user;
  }

  /**
   * Tokens of conversation history we are willing to replay each turn.
   *
   * This replaces the old "keep the last 4 messages, or 2 for OpenAI, once
   * history exceeds 10" rule. Those numbers were a stand-in for a token budget
   * that ignored both how long the individual messages were and the ~28,500
   * token system prompt that dominates every request anyway.
   *
   * Sized to be comfortable for every provider currently wired up: the
   * smallest context window in play is OpenAI's, and system prompt + this +
   * a 1,250 token reply still leaves substantial headroom.
   */
  private const HISTORY_TOKEN_BUDGET = 6000;

  /**
   * Start a fresh conversation.
   *
   * Nothing is created here — a Conversation row only appears once there is a
   * message to put in it, so abandoning a new thread without typing anything
   * leaves no empty shell in the list. Clearing the pointer is enough.
   */
  #[IsGranted('ROLE_USER')]
  #[Route('/conversation/new', name: 'empathica_chatbot_conversation_new', methods: ['POST'])]
  public function newConversation(Request $request): Response {
    if (!$this->isCsrfTokenValid('conversation_new', (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('Invalid CSRF token.');
    }

    $this->beginNewConversation($request->getSession());

    return $this->redirectToRoute('empathica_chatbot_index');
  }

  /**
   * Step out of the current thread so the next message starts a new one.
   *
   * Removing the pointer alone is not enough — see START_NEW_CONVERSATION.
   */
  private function beginNewConversation(SessionInterface $session): void {
    $session->remove(self::ACTIVE_CONVERSATION);
    $session->set(self::START_NEW_CONVERSATION, true);
  }

  /**
   * Switch to an existing conversation.
   *
   * Ownership is enforced by the query itself (findOneOwnedBy), not by a check
   * after loading — a conversation is a clinical transcript, and "fetch then
   * verify" is one forgotten branch away from disclosing someone else's.
   */
  #[IsGranted('ROLE_USER')]
  #[Route('/conversation/{id}', name: 'empathica_chatbot_conversation', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function openConversation(int $id, Request $request, Repository\ConversationRepository $conversations): Response {
    $conversation = $conversations->findOneOwnedBy($id, $this->currentUser());
    if ($conversation === null) {
      // 404 rather than 403: whether a conversation exists is itself
      // information about another user.
      throw $this->createNotFoundException('Conversation not found.');
    }

    // Opening a thread cancels any pending "start new" request.
    $request->getSession()->remove(self::START_NEW_CONVERSATION);
    $request->getSession()->set(self::ACTIVE_CONVERSATION, $conversation->getId());

    return $this->redirectToRoute('empathica_chatbot_index');
  }

  #[IsGranted('ROLE_USER')]
  // The standalone app also registered this as '<front>'. That alias is
  // deliberately gone: in a host application the front page is the host's.
  #[Route('/', name: 'empathica_chatbot_index')]
  public function index(
    Request $request,
    Services\ChatbotManager $chatbotManager,
    Services\PromptRepository $prompts,
    Repository\ConversationRepository $conversations,
    Repository\ConversationMessageRepository $messages,
    RateLimiterFactoryInterface $chatMessageLimiter,
  ): Response {
    $session = $request->getSession();
    $entityManager = $this->entityManager;
    $user = $this->currentUser();
    // Never null: forUser() hands back configured defaults for a user who has
    // never saved. ChatbotMessage::setPersona() and ProviderRegistry::
    // getOrDefault() still handle stale or unrecognised stored values.
    $preferences = $this->preferencesManager->forUser($user);
    $persona = $preferences->getPersona();
    $chatbot = $preferences->getProvider();
    $literacyLevel = $preferences->getLiteracyLevel() ?: 2;
    $empathyLevel = $preferences->getEmpathyLevel() ?: 2;

    // The active thread. The session now holds only a pointer to it, not the
    // conversation itself — so session expiry costs you your place, not your
    // history, and the same thread can be picked up on another device.
    $conversation = $this->resolveActiveConversation($session, $user, $conversations);

    // The ChatbotManager's providers are wired with their keys from the
    // empathica_chatbot configuration (see EmpathicaChatbotExtension). configure() sets the per-request runtime
    // state — safe because the service is defined as `shared: false`.
    // Re-read $chatbot afterwards so we pick up provider name normalisation
    // (e.g. historical 'Antropic' -> 'Anthropic').
    $chatbotManager->configure($chatbot, $persona, $literacyLevel, $empathyLevel, $preferences);
    $chatbot = $chatbotManager->getChatbot();

    $form = $this->createForm(Form\ChatbotInputType::class, ['message' => ''], [
      'action' => $this->generateUrl('empathica_chatbot_index'),
      'method' => 'POST',
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid() && !empty($form->getData()['message'])) {
      $prompt = $form->getData()['message'];

      // Throttle before spending anything. Keyed on the user, not the IP:
      // participants may well share a network, and one person hammering the
      // form should not lock out everyone else in the same clinic.
      //
      // Checked here rather than in a listener so it only ever counts real
      // submissions — rendering the page, switching threads, or toggling a
      // preference costs nothing and should not consume anyone's allowance.
      $limit = $chatMessageLimiter->create($user->getUserIdentifier())->consume(1);
      if (!$limit->isAccepted()) {
        $this->logger->warning('Chat rate limit hit', [
          'user' => $user->getUserIdentifier(),
          'retry_after' => $limit->getRetryAfter()->getTimestamp(),
        ]);
        // The message is deliberately not saved: nothing was sent to a
        // provider, so recording a turn with no reply would misrepresent the
        // transcript. The text stays in the form for the user to resubmit.
        $this->addFlash('warning', sprintf(
          'You are sending messages very quickly. Please wait about %d second(s) and try again.',
          max(1, $limit->getRetryAfter()->getTimestamp() - time())
        ));

        // 429 rather than the default 200. Turbo (Drive and the widget's
        // frame alike) refuses to render a 200 answer to a form POST
        // ("Form responses must redirect to another location") but does
        // render a 4xx, and 429 is the honest status for a throttled request.
        return $this->render('@PixiekatEmpathicaChatbot/chatbot/index.html.twig', [
          'conversation' => $conversation,
          'transcript' => $conversation ? $conversation->getMessages() : [],
          'form' => $form->createView(),
          'persona' => $persona,
          'chatbot' => $chatbot,
        ], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
      }

      // Created lazily, on the first message, so the list never fills with
      // empty threads.
      if ($conversation === null) {
        $conversation = (new Entity\Conversation())
          ->setUser($user)
          ->setChatbotModel($chatbot)
          ->setPersona($persona)
          ->setLiteracyLevel($literacyLevel)
          ->setEmpathyLevel($empathyLevel)
          ->setPromptVersion($prompts->getVersion());
        $entityManager->persist($conversation);
        // The request has been honoured; subsequent messages continue this
        // thread rather than spawning another.
        $session->remove(self::START_NEW_CONVERSATION);
      }
      $conversation->deriveTitleFrom($prompt);

      // Replay as much prior context as the budget allows, oldest first.
      //
      // Skipped for a thread created moments ago: it has been persisted but
      // not flushed, so it has no identifier yet, and Doctrine cannot bind an
      // identifier-less entity as a query parameter. There is nothing to fetch
      // in that case anyway — a new conversation has no history.
      if ($conversation->getId() !== null) {
        foreach ($messages->findRecentWithinBudget($conversation, self::HISTORY_TOKEN_BUDGET) as $previous) {
          $chatbotManager->getChatbotMessage()->addMessage($previous->getRole(), $previous->getContent());
        }
      }
      $chatbotManager->getChatbotMessage()->addMessage(Interfaces\Services\ChatbotMessageInterface::USER, $prompt);

      $userTurn = Entity\ConversationMessage::fromUser($prompt);
      $conversation->addMessage($userTurn);
      $entityManager->persist($userTurn);

      $started = microtime(true);

      try {
        // One call, one shape, whichever provider is selected. The four
        // response dialects are each parsed by their own provider class — see
        // Pixiekat\EmpathicaChatbot\Chatbot\Provider and ChatbotManager::getReply().
        $reply = $chatbotManager->getReply();
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($reply->isEmpty()) {
          $this->logger->error('Chatbot response empty or unrecognised', [
            'user' => $user->getUserIdentifier(),
            'provider' => $chatbot,
            'conversation' => $conversation->getId(),
            // Usually the whole explanation. A Gemini reply that hit
            // MAX_TOKENS reports it here and nowhere else, and that failure
            // has bitten this app before — see GeminiProvider::buildPayload().
            'finish_reason' => $reply->finishReason,
          ]);
          $this->addFlash('error', 'The chatbot is temporarily unavailable. Please try again.');
          // The user's turn is still saved: a question that got no answer is
          // part of the record, and losing it would hide the failure.
          $entityManager->flush();
          // Point at the thread explicitly rather than relying on it happening
          // to be the most recent — the same reason the exception path below
          // does it.
          $session->set(self::ACTIVE_CONVERSATION, $conversation->getId());

          return $this->redirectToRoute('empathica_chatbot_index');
        }

        $assistantTurn = Entity\ConversationMessage::fromAssistant($reply->content)
          ->setProvider($chatbot)
          ->setModelId($chatbotManager->getModel())
          ->setLiteracyLevel($literacyLevel)
          ->setEmpathyLevel($empathyLevel)
          ->setPromptVersion($prompts->getVersion())
          ->setPromptTokens($reply->promptTokens)
          ->setCompletionTokens($reply->completionTokens)
          ->setFinishReason($reply->finishReason)
          ->setLatencyMs($latencyMs);
        $conversation->addMessage($assistantTurn);
        $entityManager->persist($assistantTurn);

        $entityManager->flush();
        $session->set(self::ACTIVE_CONVERSATION, $conversation->getId());

        return $this->redirectToRoute('empathica_chatbot_index');
      }
      catch (\Exception $e) {
        // Never surface raw exception messages to users — they can leak API
        // URLs, stack traces, or upstream response bodies. Log fully, show
        // generic. The user's turn is still committed for the same reason as
        // above.
        $this->logger->error('Chatbot request threw exception', [
          'exception' => $e,
          'user' => $user->getUserIdentifier(),
          'provider' => $chatbot,
          'conversation' => $conversation->getId(),
        ]);
        $entityManager->flush();
        $session->set(self::ACTIVE_CONVERSATION, $conversation->getId());
        $this->addFlash('error', 'The chatbot is temporarily unavailable. Please try again.');

        return $this->redirectToRoute('empathica_chatbot_index');
      }
    }

    // The switcher's contents are no longer passed from here: it lives in the
    // navbar, which every page renders, so it is fed by the user_conversations()
    // Twig function instead. Only the *active* thread is action-specific.
    // A submitted form only reaches this point when it was invalid or empty
    // (every successful path redirects above), so answer 422. Turbo refuses
    // to render a 200 answer to a form POST but does render a 4xx; a plain GET
    // still gets 200.
    return $this->render('@PixiekatEmpathicaChatbot/chatbot/index.html.twig', [
      'conversation' => $conversation,
      'transcript' => $conversation ? $conversation->getMessages() : [],
      'form' => $form->createView(),
      'persona' => $persona,
      'chatbot' => $chatbot,
    ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
  }

  /**
   * Work out which conversation the user is looking at.
   *
   * In order: the one they explicitly opened (pointer in the session), then
   * their most recently active one, then none — in which case the next
   * message starts a new thread.
   */
  private function resolveActiveConversation(
    SessionInterface $session,
    HelpersUserInterface $user,
    Repository\ConversationRepository $conversations,
  ): ?Entity\Conversation {
    // Explicitly asked for a blank slate: do not fall back to the most recent
    // thread, or the "New conversation" button silently does nothing.
    if ($session->get(self::START_NEW_CONVERSATION)) {
      return null;
    }

    $pointer = $session->get(self::ACTIVE_CONVERSATION);

    if ($pointer !== null) {
      $conversation = $conversations->findOneOwnedBy((int) $pointer, $user);
      if ($conversation !== null) {
        return $conversation;
      }
      // Stale pointer — the thread was deleted, or the session outlived it.
      $session->remove(self::ACTIVE_CONVERSATION);
    }

    return $conversations->findMostRecentForUser($user);
  }
}
