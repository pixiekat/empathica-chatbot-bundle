<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Controller;

use Pixiekat\EmpathicaChatbot\Entity\ConversationMessage;
use Pixiekat\EmpathicaChatbot\Repository\ConversationMessageRepository;
use Pixiekat\EmpathicaChatbot\Services\TextToSpeechManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves spoken audio for a message in the current conversation.
 *
 * This exists because the Gemini API key must stay on the server. The browser
 * asks this endpoint for audio; the endpoint calls Gemini.
 *
 * WHAT MAY BE SYNTHESISED
 *
 * The request identifies a stored ConversationMessage by id — it never carries
 * the text itself. Ownership is then checked against the conversation the
 * message belongs to. That is the whole access control story, and it is
 * deliberately this shape:
 *
 *   - a user can only ever voice their own conversations;
 *   - the endpoint cannot be turned into a free text-to-speech service by
 *     POSTing arbitrary strings to it, which would be billable;
 *   - the text is bounded by what the chat flow already stored.
 *
 * This used to index into the PHP session transcript. Conversations are now
 * durable rows, so a message can still be replayed after the session that
 * produced it has expired — which is rather the point.
 *
 * WHEN TTS IS OFF
 *
 * Returns 204 No Content rather than an error. The front end treats that as
 * "use the browser's own voice", so an unconfigured or failing install
 * degrades quietly instead of throwing on every click. See assets/chatbot.js.
 */
class TextToSpeechController extends AbstractController {

  /**
   * @param int $id
   *   Id of the ConversationMessage to speak.
   * @param int $part
   *   Which piece of that message. Long answers are synthesised in pieces so
   *   playback can start on the first while the rest are still generating —
   *   see TextToSpeechManager::split(). Splitting is deterministic, so a
   *   given part number always refers to the same text.
   */
  #[IsGranted('ROLE_USER')]
  #[Route('/tts/{id}/{part}', name: 'empathica_chatbot_tts', methods: ['POST'], requirements: ['id' => '\d+', 'part' => '\d+'], defaults: ['part' => 0])]
  public function speak(
    int $id,
    int $part,
    Request $request,
    TextToSpeechManager $tts,
    ConversationMessageRepository $messages,
    RateLimiterFactoryInterface $textToSpeechLimiter,
  ): Response {
    // CSRF: the token is rendered into the conversation wrapper and posted
    // back by chatbot.js. Without this the endpoint would be callable cross-site,
    // and every call costs money.
    if (!$this->isCsrfTokenValid('tts', (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('Invalid CSRF token.');
    }

    // The plain Security user: all the ownership check below needs is identity,
    // so there is no reason to narrow it to any particular user class.
    $user = $this->getUser();

    if (!$tts->isEnabled()) {
      return new Response(null, Response::HTTP_NO_CONTENT);
    }

    // Throttle synthesis. 429 rather than 204: the front end treats any
    // non-OK response as "use the browser voice", so a throttled user still
    // gets their answer read aloud — just by the local engine. Returning 204
    // would say "TTS is off", which is a different and untrue thing, and 429
    // is what shows up honestly in the logs.
    if (!$textToSpeechLimiter->create($user?->getUserIdentifier() ?? 'anonymous')->consume(1)->isAccepted()) {
      return new Response(null, Response::HTTP_TOO_MANY_REQUESTS);
    }

    $message = $messages->find($id);

    // Not found, or not this user's. Both answer the same way: a 404-style
    // distinction would leak whether a given message id exists.
    if ($message === null || $message->getConversation()?->getUser() !== $user) {
      return new Response(null, Response::HTTP_NO_CONTENT);
    }

    // Only the assistant's side is offered for playback in the UI, so refuse
    // anything else rather than trusting the id alone.
    if ($message->getRole() !== ConversationMessage::ROLE_ASSISTANT) {
      return new Response(null, Response::HTTP_NO_CONTENT);
    }

    // strip_tags() as a belt-and-braces measure: stored content should be
    // plain text now that nl2br() is gone, but rows imported from the legacy
    // log may still carry <br /> from before that change.
    $text = strip_tags($message->getContent());

    $parts = $tts->split($text);
    if (!isset($parts[$part])) {
      return new Response(null, Response::HTTP_NO_CONTENT);
    }

    $wav = $tts->synthesizeChunk($parts[$part]);
    if ($wav === null) {
      return new Response(null, Response::HTTP_NO_CONTENT);
    }

    return new Response($wav, Response::HTTP_OK, [
      'Content-Type' => 'audio/wav',
      'Content-Length' => (string) strlen($wav),
      // How many pieces this message was split into, so the client knows how
      // many follow-up requests to make. Sent on every part, not just the
      // first, so a client that starts mid-message still learns the total.
      'X-TTS-Parts' => (string) count($parts),
      // private: this is one user's clinical conversation, so it must not be
      // held by any shared proxy. The browser may still reuse it, which makes
      // replaying an answer free on the client as well as on the server.
      'Cache-Control' => 'private, max-age=3600',
      // The audio is a rendering of the conversation, not a download.
      'Content-Disposition' => 'inline',
    ]);
  }
}
