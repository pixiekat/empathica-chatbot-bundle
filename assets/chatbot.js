/*
 * EMPATHICA chatbot — progressive enhancement for the chat screens.
 *
 * Loaded by the bundle's own layout (@PixiekatEmpathicaChatbot/chatbot/
 * layout.html.twig) as a plain <script type="module" src="…">, NOT through
 * the host's importmap entrypoint. That keeps the bundle plug-and-play: the
 * host does not have to add an importmap entry or import anything for the
 * chat to work.
 *
 * It therefore imports NOTHING. In the standalone app this file was also the
 * app's main entrypoint and pulled in Stimulus, jQuery, Bootstrap and Font
 * Awesome; inside a host those all belong to the host (Infosage already loads
 * them), and loading a second copy would mean two Bootstraps fighting over the
 * same elements. jQuery was assigned to window but never actually used here.
 *
 * Design rule for this file: the chat must remain fully usable with JavaScript
 * disabled. Everything below is an *enhancement* on top of a plain
 * POST-redirect-GET form — the loading widget, the spinner, read-aloud, and
 * copy-to-clipboard are all nice-to-haves that degrade to nothing.
 */

/**
 * Does the user want us to keep animation to a minimum?
 *
 * Honoured for the scroll-to-latest-message below. Respecting this is an
 * accessibility requirement, not a preference: smooth scrolling can trigger
 * nausea or migraine for people with vestibular disorders.
 */
function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Move the reader to the newest message after a reply comes back.
 *
 * Scrolling alone only helps people who can see the page. We also move focus
 * to the message, so keyboard and screen reader users are placed *at* the new
 * reply rather than left at the top of a page that silently grew. The target
 * carries tabindex="-1" so it can receive focus without joining the tab order.
 */
function goToLatestMessage() {
  const latest = document.getElementById('chatbot-last-message');
  if (!latest) {
    return;
  }

  latest.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
  latest.focus({ preventScroll: true });
}

/**
 * The <audio> element currently playing a Gemini-synthesised response, and
 * the button that started it. Module-level because playback is exclusive:
 * starting one response must stop any other.
 */
let currentAudio = null;
let currentButton = null;
/** Aborts any in-flight prefetch when playback stops. */
let currentAbort = null;

/**
 * Stop whatever is being spoken, by either engine, and reset its button.
 */
function stopSpeaking() {
  if (currentAbort) {
    // Cancel pieces that were fetched ahead but will now never be played, so
    // stopping early does not leave requests running.
    currentAbort.abort();
    currentAbort = null;
  }
  if (currentAudio) {
    currentAudio.pause();
    // Release the object URL, or every playback leaks a blob for the life of
    // the page.
    URL.revokeObjectURL(currentAudio.src);
    currentAudio = null;
  }
  if ('speechSynthesis' in window) {
    window.speechSynthesis.cancel();
  }
  if (currentButton) {
    setReadAloudState(currentButton, 'idle');
    currentButton = null;
  }
}

/**
 * Read a response aloud.
 *
 * Two engines, tried in order:
 *
 *   1. Gemini TTS, via our own /tts/{index} endpoint. Considerably more
 *      natural than the built-in voices, which matters here because the app
 *      is aimed partly at older and hard-of-hearing users — robotic prosody
 *      is a comprehension problem, not just an aesthetic one. The request
 *      carries only a message index, never the text: the key lives on the
 *      server and so does the decision about what may be voiced.
 *   2. The browser's own speechSynthesis. Free, local, no key. Used whenever
 *      the endpoint is switched off (204), errors, or the network is down.
 *
 * The fallback is the point of the design. Gemini TTS is billed per
 * character and depends on a preview model, so read-aloud must keep working
 * when it is unavailable — the feature degrades, it does not disappear.
 *
 * If neither engine exists (no speechSynthesis and no endpoint) the button is
 * removed rather than left on the page doing nothing when clicked.
 */
function initReadAloud() {
  // Every lookup is scoped to the chatbot root: generic class names like
  // .play and .clipboard could otherwise match elements of the host page.
  const buttons = document.querySelectorAll('[data-empathica-chatbot] .play');
  const hasBrowserVoice = 'speechSynthesis' in window;
  const conversations = document.querySelector('[data-empathica-chatbot] .conversations');
  const token = conversations?.dataset.ttsToken;
  // Server-side setting (empathica_chatbot.tts.prefetch), rendered into the page because
  // the code that acts on it runs here. Falls back to 1 if absent.
  const prefetch = Math.max(0, parseInt(conversations?.dataset.ttsPrefetch ?? '1', 10) || 0);

  buttons.forEach((button) => {
    if (!hasBrowserVoice && !button.dataset.ttsUrl) {
      button.closest('.icon--tts')?.remove();
      return;
    }

    button.addEventListener('click', async function () {
      // Clicking the button that is already speaking stops it; clicking a
      // different one interrupts and takes over. One button, two states.
      const wasSpeaking = currentButton === this;
      stopSpeaking();
      if (wasSpeaking) {
        return;
      }

      const responseText = this.closest('.conversation')?.querySelector('.response-text');
      if (!responseText) {
        return;
      }

      currentButton = this;
      // Loading until audio actually starts. Clicking again while in this
      // state cancels, and stopSpeaking() aborts the in-flight fetch.
      setReadAloudState(this, 'loading');

      if (await speakWithGemini(this, token, prefetch)) {
        return;
      }

      speakWithBrowser(this, responseText.textContent);
    });
  });
}

/**
 * Fetch one synthesised piece. Resolves to a Blob, or null if unavailable.
 *
 * A 204 means "TTS is off or unavailable, use the other engine" — the normal
 * response on an install that has not set GEMINI_TTS_ENABLED, so it is
 * deliberately not an error.
 */
async function fetchPart(urlTemplate, token, part, signal) {
  try {
    const body = new FormData();
    body.append('_token', token);

    const response = await fetch(urlTemplate.replace('__PART__', String(part)), {
      method: 'POST',
      body,
      credentials: 'same-origin',
      signal,
    });

    if (!response.ok || response.status === 204) {
      return null;
    }

    const blob = await response.blob();
    if (!blob.size) {
      return null;
    }

    // How many pieces this message has in total. Only present on a real
    // audio response, and sent on every part so it is always learnable.
    const total = parseInt(response.headers.get('X-TTS-Parts') || '1', 10);

    return { blob, total: Number.isNaN(total) ? 1 : total };
  }
  catch (error) {
    // Aborted, offline, or blocked. All mean the same thing to the caller.
    return null;
  }
}

/**
 * Play a message through the server-side Gemini voice, piece by piece.
 * Resolves true if playback started.
 *
 * WHY PIECES
 *
 * Gemini's TTS endpoint is not streaming: it returns the whole clip in one
 * response, so synthesising a long answer as a single request means silence
 * until every second of audio exists. On a ~2,400-character response that was
 * three sequential calls before the first sound. Splitting server-side and
 * playing piece one while the rest are still generating makes time-to-first-
 * sound roughly constant regardless of answer length.
 *
 * Pieces are fetched a configurable distance ahead of playback (see
 * data-tts-prefetch, default 1). Prefetching everything up front would be
 * marginally smoother but bills for audio a listener who stops early never
 * hears; one ahead gives the next piece a whole piece's playback time to
 * arrive while risking at most one unheard piece.
 */
async function speakWithGemini(button, token, prefetch) {
  const urlTemplate = button.dataset.ttsUrl;
  if (!urlTemplate || !token) {
    return false;
  }

  const controller = new AbortController();
  const first = await fetchPart(urlTemplate, token, 0, controller.signal);
  if (!first) {
    return false;
  }

  // Pending fetches by part number, so a piece requested ahead of time is
  // awaited rather than requested twice.
  const pending = new Map();

  const queueAhead = (nextToPlay) => {
    for (let part = nextToPlay; part < Math.min(nextToPlay + prefetch, first.total); part++) {
      if (!pending.has(part)) {
        pending.set(part, fetchPart(urlTemplate, token, part, controller.signal));
      }
    }
  };

  const playPart = async (part, payload) => {
    const audio = new Audio(URL.createObjectURL(payload.blob));
    currentAudio = audio;
    currentAbort = controller;

    // Start fetching what comes after this piece while it plays.
    queueAhead(part + 1);

    audio.addEventListener('ended', async () => {
      URL.revokeObjectURL(audio.src);

      const next = part + 1;
      if (next >= first.total || currentButton !== button) {
        stopSpeaking();
        return;
      }

      const upcoming = pending.get(next) ?? fetchPart(urlTemplate, token, next, controller.signal);
      pending.delete(next);

      // With prefetch on, the next piece is usually already here and this
      // resolves instantly. If it is not — a slow round trip, or prefetch
      // turned off — say so rather than sitting silently on "Stop Reading".
      setReadAloudState(button, 'loading');
      const payloadNext = await upcoming;

      // A failed piece mid-way stops playback rather than silently skipping
      // it — a gap in a medication list is worse than an obvious stop.
      if (!payloadNext || currentButton !== button) {
        stopSpeaking();
        return;
      }

      await playPart(next, payloadNext);
    });

    audio.addEventListener('error', stopSpeaking);

    await audio.play();

    // Only now is anything audible. Everything before this point — the fetch,
    // the decode, the autoplay decision — was the "Preparing audio…" window.
    setReadAloudState(button, 'speaking');
  };

  try {
    await playPart(0, first);
    return true;
  }
  catch (error) {
    // Autoplay refusal or a decode failure: fall back to the browser voice.
    controller.abort();
    return false;
  }
}

/**
 * Speak using the browser's built-in synthesiser.
 */
function speakWithBrowser(button, text) {
  if (!('speechSynthesis' in window)) {
    setReadAloudState(button, 'idle');
    currentButton = null;
    return;
  }

  // Local synthesis, so there is no meaningful wait to report.
  setReadAloudState(button, 'speaking');

  const utterance = new SpeechSynthesisUtterance(text);
  // Taken from <html lang>, so the Maria persona replying in Spanish gets a
  // Spanish voice rather than Spanish words in an English accent.
  utterance.lang = document.documentElement.lang || 'en';
  utterance.addEventListener('end', stopSpeaking);
  utterance.addEventListener('error', stopSpeaking);

  window.speechSynthesis.speak(utterance);
}

/**
 * Swap a read-aloud button between its "listen" and "stop" states.
 *
 * The visible label changes as well as the icon: an icon-only state change is
 * invisible to screen readers and to anyone who does not read the icon set.
 */
const READ_ALOUD_STATES = {
  idle: { label: 'Listen to Response', icon: 'fa-solid fa-volume-high fa-lg', pressed: 'false', busy: 'false' },
  // Distinct from "speaking" on purpose. Gemini synthesis is a server round
  // trip that can take several seconds on the first chunk, and a button that
  // says "Stop Reading" while nothing is audible reads as a broken feature
  // rather than a slow one.
  loading: { label: 'Preparing audio…', icon: 'fa-solid fa-spinner fa-spin fa-lg', pressed: 'true', busy: 'true' },
  speaking: { label: 'Stop Reading', icon: 'fa-solid fa-stop fa-lg', pressed: 'true', busy: 'false' },
};

/**
 * Move a read-aloud button between idle, loading and speaking.
 *
 * The visible label carries the state, not just the icon: an icon-only change
 * is invisible to screen readers and to anyone who does not read the icon set.
 * aria-busy marks the fetch so assistive tech can describe the wait, and
 * aria-pressed stays true throughout loading because the control *is* engaged
 * — clicking it again cancels, which is the behaviour a pressed toggle implies.
 */
function setReadAloudState(button, state) {
  const config = READ_ALOUD_STATES[state] ?? READ_ALOUD_STATES.idle;
  const label = button.querySelector('.action--label');
  const icon = button.querySelector('i');

  if (label) {
    label.textContent = config.label;
  }
  if (icon) {
    icon.className = config.icon;
  }
  button.setAttribute('aria-pressed', config.pressed);
  button.setAttribute('aria-busy', config.busy);
}

/**
 * Copy a response to the clipboard.
 *
 * Confirmation is written into the button's own label rather than logged to
 * the console, so the feedback is actually perceivable. The label is part of
 * the button, so screen readers announce the change when focus is on it.
 */
function initCopyToClipboard() {
  const buttons = document.querySelectorAll('[data-empathica-chatbot] .clipboard');

  // navigator.clipboard is unavailable on insecure origins (plain http on a
  // non-localhost host). Removing the button beats leaving one that throws.
  if (!navigator.clipboard) {
    buttons.forEach((button) => button.closest('.icon--clipboard')?.remove());
    return;
  }

  buttons.forEach((button) => {
    button.addEventListener('click', function () {
      const responseText = this.closest('.conversation')?.querySelector('.response-text');
      if (!responseText) {
        return;
      }

      const label = this.querySelector('.action--label');
      const original = label ? label.textContent : null;

      navigator.clipboard.writeText(responseText.textContent)
        .then(() => {
          if (label) {
            label.textContent = 'Copied!';
            window.setTimeout(() => { label.textContent = original; }, 2000);
          }
        })
        .catch(() => {
          if (label) {
            label.textContent = 'Copy failed';
            window.setTimeout(() => { label.textContent = original; }, 2000);
          }
        });
    });
  });
}

/**
 * Chat form submit feedback.
 *
 * A provider round-trip takes several seconds, so without this the page looks
 * frozen and people submit again. Three things happen on submit:
 *
 *   1. The loading widget is revealed (it also carries role="status", so the
 *      "EMPATHICA is thinking..." text is announced, not just displayed).
 *   2. The submit button is disabled — this is the actual double-submit
 *      guard, and it matters because each submit costs a provider call.
 *   3. The button label becomes a spinner.
 *
 * The action attribute is stripped so the form posts back to the current URL.
 * Symfony generates an absolute action, which would otherwise drop query
 * string state on submit.
 */
function initChatForm() {
  const form = document.querySelector("[data-empathica-chatbot] form[name='chatbot_input']");
  if (!form) {
    return;
  }

  form.removeAttribute('action');

  form.addEventListener('submit', function () {
    const wrapper = document.querySelector('[data-empathica-chatbot] .loading-widget-wrapper');
    if (wrapper) {
      wrapper.style.visibility = 'visible';
    }

    const submit = document.getElementById('chatbot_input_submit');
    if (submit) {
      submit.disabled = true;
      submit.innerHTML = '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> Loading...';
    }
  });
}


/**
 * Turn the conversation list into a slide-out panel.
 *
 * The panel is server-rendered, off-canvas by default, and opened by :target —
 * so the "Conversations" control already works as a plain anchor with no
 * JavaScript at all. This function is pure enhancement: it swaps the fragment
 * navigation for a class toggle (so the URL does not grow a #chatbot-conversation-list)
 * and adds the things CSS cannot do — a backdrop, Escape to close, focus
 * management, and aria-expanded.
 *
 * Dialog-ish behaviour, because an overlay that traps you is worse than no
 * overlay: Escape closes, clicking the backdrop closes, focus moves into the
 * panel on open and back to the toggle on close, and the toggle reports its
 * state via aria-expanded.
 */
function initConversationPanel() {
  const toggle = document.querySelector('[data-empathica-chatbot] .conversation--toggle');
  const panel = document.getElementById('chatbot-conversation-list');
  if (!toggle || !panel) {
    return;
  }

  let backdrop = null;

  const close = () => {
    panel.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    backdrop?.remove();
    backdrop = null;
    document.removeEventListener('keydown', onKeydown);
    toggle.focus();
  };

  const onKeydown = (event) => {
    if (event.key === 'Escape') {
      close();
    }
  };

  const open = () => {
    panel.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');

    backdrop = document.createElement('div');
    // Appended to <body>, outside the chatbot root, so its class name is
    // fully prefixed rather than relying on scoping.
    backdrop.className = 'empathica-chatbot-backdrop';
    backdrop.addEventListener('click', close);
    document.body.appendChild(backdrop);

    document.addEventListener('keydown', onKeydown);

    // Move focus into the panel. Its heading is not focusable by default, so
    // give it tabindex="-1" first — otherwise focus stays behind the overlay
    // and keyboard users are tabbing through a page they cannot see.
    const heading = panel.querySelector('h2');
    if (heading) {
      heading.setAttribute('tabindex', '-1');
      heading.focus();
    }

  };

  // The close control is rendered server-side so it works without JS. When JS
  // is present, intercept it so focus returns to the toggle and the backdrop
  // is cleaned up, rather than merely clearing the fragment.
  panel.querySelector('.conversation--close')?.addEventListener('click', function (event) {
    event.preventDefault();
    close();
  });

  toggle.addEventListener('click', function (event) {
    event.preventDefault();
    if (panel.classList.contains('is-open')) {
      close();
    }
    else {
      open();
    }
  });
}

/**
 * Wire everything up for the page currently on screen.
 *
 * WHY NOT JUST DOMContentLoaded
 * -----------------------------
 * The host may use Turbo Drive. Turbo swaps <body> without a full page load,
 * so DOMContentLoaded fires once per *visit to the site*, not once per page —
 * and an ES module only ever executes once, however many times its <script>
 * tag reappears. Arriving at the chat via a Turbo link would otherwise leave
 * every enhancement unbound.
 *
 * So this runs on turbo:load as well, and guards against double-binding with a
 * marker on the chat's root element. A Turbo visit renders a fresh root
 * without the marker, which is exactly when we want to run again; the
 * first-load case, where both events fire, is caught by the marker.
 */
function initChatbot() {
  const root = document.querySelector('[data-empathica-chatbot]');
  if (!root || root.dataset.empathicaChatbotReady) {
    return;
  }
  root.dataset.empathicaChatbotReady = '1';

  initChatForm();
  initConversationPanel();
  initReadAloud();
  initCopyToClipboard();
  goToLatestMessage();
}

document.addEventListener('turbo:load', initChatbot);

// Module scripts are deferred, so on a normal page load this file usually runs
// before DOMContentLoaded. If it was inserted later (a Turbo render), that event
// has already fired and will not come again — run immediately instead.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initChatbot);
}
else {
  initChatbot();
}
