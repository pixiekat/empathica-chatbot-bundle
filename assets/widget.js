/*
 * EMPATHICA chatbot: the floating chat widget.
 *
 * Pairs with templates/widget/_launcher.html.twig. Like chatbot.js it's loaded
 * as a plain <script type="module">, imports nothing, and is pure enhancement:
 * every control it upgrades is already a working link to the full-page chat.
 *
 * WHAT IT DOES
 * ------------
 *   - Turns the launcher (and anything else marked data-empathica-chatbot-open,
 *     such as a host's bottom-navigation item) into a toggle for the panel.
 *   - Opens the panel as a non-modal popup on desktop, or as a modal full-screen
 *     dialog when expanded or on mobile.
 *   - Puts focus back where it came from when the panel closes.
 *   - Keeps the widget sane across Turbo page visits.
 *
 * WHEN IT STANDS DOWN
 * -------------------
 * The chat inside the panel is loaded by a <turbo-frame>, so the widget needs
 * Turbo, and it needs <dialog>. If either is missing it does nothing at all,
 * and every launcher stays an ordinary link to /chat. That's the intended
 * fallback, not an error.
 */

const WIDGET_SELECTOR = '[data-empathica-chatbot-widget]';
const OPEN_SELECTOR = '[data-empathica-chatbot-open]';

/** sessionStorage key remembering whether the user prefers full screen. */
const EXPANDED_KEY = 'empathica-chatbot-widget-expanded';

/**
 * Can this browser run the widget?
 *
 * Checked on every event rather than once, because Turbo can finish loading
 * after this module runs; customElements.get() is the reliable signal that
 * <turbo-frame> is actually defined.
 */
function isSupported() {
  return typeof HTMLDialogElement === 'function'
    && typeof HTMLDialogElement.prototype.showModal === 'function'
    && Boolean(window.customElements?.get('turbo-frame'));
}

/** The widget elements currently in the document, or null. */
function getWidget() {
  const root = document.querySelector(WIDGET_SELECTOR);
  const panel = root?.querySelector('dialog');
  if (!root || !panel) {
    return null;
  }

  return {
    root,
    panel,
    frame: panel.querySelector('turbo-frame'),
    expandButton: panel.querySelector('[data-empathica-chatbot-expand]'),
  };
}

/**
 * sessionStorage, wrapped. It throws in some private-browsing modes and when
 * storage is blocked; a remembered preference is a convenience, so failure
 * just means "not remembered".
 */
function readExpandedPreference() {
  try {
    return window.sessionStorage.getItem(EXPANDED_KEY) === '1';
  }
  catch (error) {
    return false;
  }
}

function writeExpandedPreference(expanded) {
  try {
    window.sessionStorage.setItem(EXPANDED_KEY, expanded ? '1' : '0');
  }
  catch (error) {
    // Not remembered. Nothing else to do.
  }
}

/** Is the viewport currently "mobile", by the include's mobile_query? */
function isMobile(widget) {
  const query = widget.root.dataset.mobileQuery || '(max-width: 768px)';
  return window.matchMedia(query).matches;
}

/** The element that opened the panel, so focus can go back to it on close. */
let returnFocusTo = null;

/**
 * Keep every opener's aria-expanded in step with the panel.
 *
 * Openers are found fresh each time because the host's own (a bottom-nav
 * item) is re-rendered on every Turbo visit, unlike the permanent widget.
 */
function syncOpeners(expanded) {
  document.querySelectorAll(OPEN_SELECTOR).forEach((opener) => {
    opener.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  });
}

/**
 * Reflect the current mode on the expand button.
 *
 * The visible label changes as well as aria-pressed. A state shown only by
 * swapping an icon is invisible to screen readers and to anyone who doesn't
 * know the icon set.
 */
function syncExpandButton(widget, fullscreen) {
  const button = widget.expandButton;
  if (!button) {
    return;
  }

  button.setAttribute('aria-pressed', fullscreen ? 'true' : 'false');

  const label = button.querySelector('.empathica-chatbot-widget--action-label');
  if (label) {
    label.textContent = fullscreen ? button.dataset.labelRestore : button.dataset.labelExpand;
  }

  const icon = button.querySelector('i');
  if (icon) {
    icon.className = fullscreen ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
  }
}

/**
 * Open the panel in the right mode.
 *
 * Full screen is a MODAL dialog (showModal): the page behind becomes inert, so
 * keyboard and screen-reader users can't wander off into content they can't
 * see. The popup is NON-modal (show): it's a side panel, and the page behind
 * it is meant to stay usable.
 */
function openPanel(widget, { fullscreen }) {
  const { panel } = widget;

  // Switching modes means reopening, because a dialog can't turn modal or
  // non-modal while open. Closing doesn't touch the frame, so the loaded
  // conversation (and anything half-typed) survives the switch.
  if (panel.open) {
    panel.close();
  }

  panel.classList.toggle('is-fullscreen', fullscreen);
  syncExpandButton(widget, fullscreen);

  if (fullscreen) {
    panel.showModal();
  }
  else {
    panel.show();
  }

  syncOpeners(true);

  // Into the panel: the chat's message box if it has loaded, otherwise the
  // panel heading, so focus is never left behind on the page.
  const message = panel.querySelector('textarea, input[type="text"]');
  const heading = panel.querySelector('.empathica-chatbot-widget--title');
  if (message) {
    message.focus();
  }
  else if (heading) {
    heading.setAttribute('tabindex', '-1');
    heading.focus();
  }
}

function closePanel(widget) {
  if (widget.panel.open) {
    // The 'close' listener does the tidying up, so Escape (which closes a
    // modal dialog without going through here) is tidied up the same way.
    widget.panel.close();
  }
}

/** Open from a launcher, choosing the mode for this screen and preference. */
function openFromTrigger(widget, trigger) {
  returnFocusTo = trigger;
  openPanel(widget, { fullscreen: isMobile(widget) || readExpandedPreference() });
}

/**
 * Clicks, delegated from the document.
 *
 * Delegation (rather than binding each element) is what lets this survive
 * Turbo: the host's bottom nav is replaced on every visit, and the widget is
 * moved between documents, but the document listener stays put.
 */
document.addEventListener('click', (event) => {
  if (!isSupported()) {
    return;
  }

  const widget = getWidget();
  if (!widget) {
    return;
  }

  // Opening. Modifier-clicks (ctrl/cmd/shift, middle button) are left alone,
  // so "open in new tab" on the launcher still opens the full chat page.
  const opener = event.target.closest(OPEN_SELECTOR);
  if (opener) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    event.preventDefault();

    if (widget.panel.open) {
      closePanel(widget);
    }
    else {
      openFromTrigger(widget, opener);
    }
    return;
  }

  if (event.target.closest('[data-empathica-chatbot-close]')) {
    closePanel(widget);
    return;
  }

  if (event.target.closest('[data-empathica-chatbot-expand]')) {
    const fullscreen = !widget.panel.classList.contains('is-fullscreen');
    writeExpandedPreference(fullscreen);
    openPanel(widget, { fullscreen });
    return;
  }

  // "Open full page": close first, so the modal isn't left in the top layer
  // while Turbo swaps the page underneath it. The link then navigates as
  // normal.
  if (event.target.closest('[data-empathica-chatbot-full-page]')) {
    closePanel(widget);
  }
});

/**
 * Escape for the popup.
 *
 * The browser closes a MODAL dialog on Escape by itself, but not a non-modal
 * one, so the popup needs this. Only while focus is inside the panel: Escape
 * pressed elsewhere on the page belongs to whatever's there.
 */
document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape' || event.defaultPrevented) {
    return;
  }

  const widget = getWidget();
  if (widget?.panel.open && !widget.panel.matches(':modal') && widget.panel.contains(document.activeElement)) {
    closePanel(widget);
  }
});

/**
 * Tidy up after ANY close: close button, launcher toggle, Escape, or a mode
 * switch. 'close' doesn't bubble, so this is a capturing listener on the
 * document; that also means it works for a widget carried over by Turbo.
 */
document.addEventListener('close', (event) => {
  const widget = getWidget();
  if (!widget || event.target !== widget.panel) {
    return;
  }

  // A mode switch reopens straight away. Only a real close resets openers and
  // focus; openPanel() sets them again otherwise.
  window.queueMicrotask(() => {
    if (widget.panel.open) {
      return;
    }
    syncOpeners(false);

    // Back to whatever opened it, if that's still on the page. A bottom-nav
    // item may have been replaced by a Turbo visit since, in which case focus
    // falls back to the launcher.
    const target = returnFocusTo?.isConnected ? returnFocusTo : widget.root.querySelector(OPEN_SELECTOR);
    target?.focus();
    returnFocusTo = null;
  });
}, true);

/**
 * Viewport changes: rotating a tablet, or resizing a window across the mobile
 * breakpoint. An open popup that's now on "mobile" becomes full screen, since
 * a docked popup on a phone-sized screen would cover the page without making
 * it inert.
 */
function watchViewport() {
  const widget = getWidget();
  if (!widget || widget.root.dataset.empathicaChatbotViewportWatched) {
    return;
  }
  widget.root.dataset.empathicaChatbotViewportWatched = '1';

  const query = window.matchMedia(widget.root.dataset.mobileQuery || '(max-width: 768px)');
  query.addEventListener('change', (event) => {
    const current = getWidget();
    if (event.matches && current?.panel.open && !current.panel.classList.contains('is-fullscreen')) {
      openPanel(current, { fullscreen: true });
    }
  });
}

/**
 * Before Turbo renders a new page, close a MODAL panel.
 *
 * data-turbo-permanent moves the widget into the new page's <body>. Moving a
 * modal <dialog> drops it out of the top layer while it still says it's open,
 * leaving a panel that looks modal but isn't, over a page that isn't inert. A
 * non-modal popup has no top-layer state, so it's carried over open, which
 * is the point of making it permanent.
 */
document.addEventListener('turbo:before-render', () => {
  const widget = getWidget();
  if (widget?.panel.open && widget.panel.matches(':modal')) {
    closePanel(widget);
  }
});

/**
 * The frame got a response with no matching <turbo-frame> in it.
 *
 * The common cause is an expired session: the chat redirects to the login
 * page, which is a normal full page. Turbo's default is an empty "Content
 * missing" box inside the widget. Instead, visit that response as a whole
 * page, so the user lands on the login form where they can do something.
 */
document.addEventListener('turbo:frame-missing', (event) => {
  const widget = getWidget();
  if (!widget || event.target !== widget.frame) {
    return;
  }

  event.preventDefault();
  closePanel(widget);
  event.detail.visit(event.detail.response);
});

/**
 * Loading state. aria-busy tells assistive tech the frame's contents are
 * being replaced, so it doesn't read half-swapped content. It's set in the
 * template for the first load and cleared here once content arrives.
 */
document.addEventListener('turbo:before-fetch-request', (event) => {
  const widget = getWidget();
  if (widget?.frame && widget.frame.contains(event.target)) {
    widget.frame.setAttribute('aria-busy', 'true');
  }
});

document.addEventListener('turbo:frame-render', (event) => {
  const widget = getWidget();
  if (widget && event.target === widget.frame) {
    widget.frame.removeAttribute('aria-busy');
  }
});

/**
 * Mark the openers as popup controls, but only once we know we can actually
 * open the popup. Until then they're described as the plain links they are.
 */
function announceOpeners() {
  if (!isSupported() || !getWidget()) {
    return;
  }

  document.querySelectorAll(OPEN_SELECTOR).forEach((opener) => {
    opener.setAttribute('aria-haspopup', 'dialog');
    opener.setAttribute('aria-controls', 'empathica-chatbot-widget-panel');
    if (!opener.hasAttribute('aria-expanded')) {
      opener.setAttribute('aria-expanded', getWidget().panel.open ? 'true' : 'false');
    }
  });

  watchViewport();
}

// Every page render, Turbo or not, so a re-rendered bottom-nav item gets the
// attributes too. Module scripts are deferred, so the document may already be
// parsed when this runs.
document.addEventListener('turbo:load', announceOpeners);
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', announceOpeners);
}
else {
  announceOpeners();
}
