# EMPATHICA Chatbot Bundle

`pixiekat/empathica-chatbot`: the Division of Clinical Informatics' simulated-pharmacist chatbot, packaged as a plug-and-play Symfony bundle. It was extracted from the standalone `chatbot-app`.

Users chat with EMPATHICA about a persona's (Emily or Maria) medications. The bundle sits in front of five providers: Anthropic, Gemini, OpenAI, Mistral and the EmpathicaAgent Cloud Run service. Literacy and empathy levels are adjustable, every turn is stored with full provenance for research, read-aloud is optional, and there are researcher admin screens.

## Requirements

- PHP 8.2+ with `ext-intl`
- Symfony 7.4 or 8.x
- Doctrine ORM 3
- [`pixiekat/symfony-common-helpers`](https://codeberg.org/pixiekat/symfony-common-helpers) (SCH), installed and registered **before** this bundle

## How it plugs in

The bundle only asks one thing of the host's user class: **it must implement `Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface`**.

Every bundle entity relates to that interface, never to `App\Entity\User`. SCH's `symfony_helpers.user_class` setting tells Doctrine which concrete class the interface stands for, through `resolve_target_entities`. The relations are one-way, so the host's `User` gets no new properties, columns or migrations.

| Bundle table | What it holds |
|---|---|
| `empathica_chatbot_preferences` | One row per user who has saved chatbot settings (provider, persona, levels, debug mode, remembered notes). Users who have never saved get defaults, and no row is written. |
| `empathica_chatbot_conversation` | One row per chat thread. |
| `empathica_chatbot_conversation_message` | One row per turn, with the provider, model, levels, prompt version, token counts, latency and finish reason. |

The extension prepends everything else, so the host doesn't have to copy it into its own config:

- the Doctrine entity mapping and the **migrations path**
- the AssetMapper path (`empathica-chatbot/…`)
- the `chatbot` Monolog channel
- two rate limiters: `empathica_chatbot_chat_message` and `empathica_chatbot_text_to_speech`
- the Twig globals `chatbot_layout` and `chatbot_tts_prefetch`
- a **"Chatbot" section in the SCH control panel menu**, through `AdminNavProviderInterface`

## Installation

```bash
composer require pixiekat/empathica-chatbot
```

`config/bundles.php`, placed after SCH:

```php
Pixiekat\SymfonyHelpers\PixiekatSymfonyHelpersBundle::class => ['all' => true],
Pixiekat\EmpathicaChatbot\PixiekatEmpathicaChatbotBundle::class => ['all' => true],
```

`config/routes/empathica_chatbot.yaml`:

```yaml
empathica_chatbot:
  resource: '@PixiekatEmpathicaChatbotBundle/config/routes.yaml'
  prefix: /chat
```

`config/packages/empathica_chatbot.yaml`. Every key is optional; this is the minimum useful setup:

```yaml
empathica_chatbot:
  default_provider: 'Gemini'
  selectable_providers: ['Gemini', 'EmpathicaAgent']
  providers:
    gemini:
      api_key: '%env(string:default::GEMINI_API_KEY)%'
```

Then run:

```bash
php bin/console doctrine:migrations:migrate
```

Run `php bin/console config:dump-reference empathica_chatbot` for every option, with its documentation.

## Using it

| Route name | Path (with `/chat` prefix) | |
|---|---|---|
| `empathica_chatbot_index` | `/chat/` | The chat |
| `empathica_chatbot_history` | `/chat/history` | The user's own conversations |
| `empathica_chatbot_preferences` | `/chat/preferences` | Chatbot settings |
| `empathica_chatbot_admin_users` | `/chat/admin` | Researcher screens (see below) |

The chat pages extend the template named in `empathica_chatbot.layout` (default `base.html.twig`), which must define `stylesheets`, `body` and `javascripts` blocks. The bundle adds its own toolbar, conversation panel, stylesheet (`<link>`) and script (`<script type="module">`) inside them. **The host's importmap needs no entry.**

These Twig functions are available to any host template, for example a chat widget in the layout:

- `chatbot_preferences()`: the current user's `ChatbotPreferences`, or `null` when anonymous. It never writes to the database.
- `chatbot_conversations()` and `chatbot_conversation_counts()`: the current user's recent threads.
- `|chatbot_markdown`: renders a stored message safely (it escapes HTML first).

## Floating chat widget

A launcher button that opens the chat over any page, without leaving it. Include it once, near the end of the host layout's `<body>`:

```twig
{{ include('@PixiekatEmpathicaChatbot/widget/_launcher.html.twig') }}
```

It renders nothing for anonymous users, or on the bundle's own `/chat` pages.

| Mode | When | Behaviour |
|---|---|---|
| Popup | Desktop, by default | Docked bottom-right, non-modal: the page behind stays usable, and the popup stays open across Turbo page visits. |
| Full screen | The **Full screen** button, and always on mobile | A modal `<dialog>`: the page behind is inert, focus stays inside, Escape closes it. The choice is remembered for the browser session. |
| Full page | The **Open full page** link | The normal `/chat` page. The conversation carries over, because the active thread is kept in the session. |

### Options

| Variable | Default | |
|---|---|---|
| `mobile_launcher` | `true` | Show the floating circle on small screens. Pass `false` when the host has its own way in on mobile, such as a bottom-nav item. |
| `mobile_query` | `'(max-width: 768px)'` | What counts as mobile (always full screen). Match the host's breakpoint; widget.css uses 768px for the launcher and expand button, so mirror a different value with your own CSS rule. |

```twig
{{ include('@PixiekatEmpathicaChatbot/widget/_launcher.html.twig', {'mobile_launcher': false}) }}
```

### Opening it from your own controls

Any link or button with `data-empathica-chatbot-open` opens the widget. Make it a real link to the chat, so it still works without JavaScript:

```twig
<a href="{{ path('empathica_chatbot_index') }}" data-empathica-chatbot-open aria-label="Chat">…</a>
```

### Overriding the launcher

The template is built from blocks: `launcher`, `launcher_icon`, `launcher_label`, `panel_header`, `panel_title`, `panel_loading`, `widget_stylesheets` and `widget_javascripts`. To change some of them, create `templates/bundles/PixiekatEmpathicaChatbotBundle/widget/_launcher.html.twig` in the host and extend the original (the `!` skips your override and loads the bundle's own file):

```twig
{% extends '@!PixiekatEmpathicaChatbot/widget/_launcher.html.twig' %}
{% block launcher_icon %}<i class="fa-solid fa-user-doctor"></i>{% endblock %}
```

Keep the `data-empathica-chatbot-*` attributes and the frame id, because `widget.js` and the frame layout depend on them. Colours are `--chatbot-widget-*` custom properties on `.empathica-chatbot-widget`, so most re-theming needs only CSS.

### How it works

The panel holds a `<turbo-frame id="empathica-chatbot-widget-frame" src="/chat/" loading="lazy">`, so nothing loads until the panel is first opened. Turbo sends a `Turbo-Frame` header with every request from the frame, and keeps it through redirects. When that header is present, `chatbot/layout.html.twig` extends the slim `chatbot/frame.html.twig` instead of the host layout (see `chatbot_is_widget_frame()`). The chat runs on the same routes, controllers, CSRF tokens and session as the full page. `Vary: Turbo-Frame` is added to chatbot responses, so caches keep the two versions apart.

**Requirements:** Turbo (`symfony/ux-turbo`) in the host and a browser with `<dialog>`. Without either, the launcher stays a plain link to the full-page chat.

**Include the launcher after `importmap()`.** The launcher prints its own `<script type="module">` tags. If one of them appears before the host's import map, Firefox ignores the map: `import 'app'` fails as an unmapped bare specifier, and every relative import resolves to an unhashed `/assets/…` URL that 404s (the console shows `NS_ERROR_CORRUPTED_CONTENT` / "disallowed MIME type"). Recent Chrome merges a late import map instead, so the bug can look Firefox-only. Put the include below the block that calls `importmap()`, or put `importmap()` in `<head>`:

```twig
{% block javascripts %}{{ importmap('app') }}{% endblock %}
{{ include('@PixiekatEmpathicaChatbot/widget/_launcher.html.twig') }}
```

**Graceful degradation:** the launcher and every control in the panel are real links. With JavaScript off, or without Turbo, they go to the full-page chat, which works as plain HTML forms.

## Access control

| Voter attribute | Granted when |
|---|---|
| `empathica_chatbot.view_history` | The user is logged in. Queries are always scoped to the current user. |
| `empathica_chatbot.research_settings` | The user holds `admin_role`. This unlocks provider, persona and debug mode, plus the testing-only empathy levels 4 and 5. |
| `empathica_chatbot.admin` | `features.admin` is on **and** the user holds `admin_role`. |

Setting `features.admin: false` hides the admin menu entry **and** makes `/chat/admin/*` answer 403. The data is kept.

**Audit trail.** Opening a participant's conversation list or a transcript, and every CSV download, is recorded through SymfonyHelpers' `AuditLogManager` (the `audit_logs` table and the `audit` log channel), so it shows in the SCH audit screen. The entry names the admin and the participant, and the conversation by id. It never records the conversation's title, which is the participant's own words. The sentences shown for these entries are in `translations/messages.en.yaml`. The overview list of users isn't logged, because it shows no message content.

**Limits.** A chat message and the saved medical context are each capped at 4,000 characters (`ChatbotInputType::MESSAGE_MAX_LENGTH`, `ChatbotPreferences::MEMORY_MAX_LENGTH`). Both are sent to a paid provider, the context on every turn.

> **Study safety:** debug mode makes the model announce the active empathy and literacy levels, which tells a participant which experimental arm they're in. Only admins can turn it on, and it's off by default.

## Graceful degradation

Everything works without JavaScript. The chat is a plain POST, redirect, GET form, and the floating widget's launcher is a plain link to it. The conversation panel opens with CSS `:target`. Read-aloud falls back to the browser's `speechSynthesis`, and silently to nothing if that's missing. The script only adds enhancements (loading indicator, focus management, Escape to close, Gemini voices), and it re-initialises on Turbo navigations.

## Prompts

The system prompt is Markdown in `config/prompts/`. To edit it without forking the bundle, copy the directory and point `empathica_chatbot.prompts_dir` at the copy. `PromptRepository::getVersion()` stamps every stored turn with a hash of the prompt files, so you can always tell which prompt produced a transcript.

## Tests

Run from the host's root, using the host's PHPUnit:

```bash
php vendor/bin/phpunit -c vendor/pixiekat/empathica-chatbot/phpunit.dist.xml                       # everything
php vendor/bin/phpunit -c vendor/pixiekat/empathica-chatbot/phpunit.dist.xml --testsuite unit      # no database needed
```

The `functional` suite makes real requests against the host's **test** database, and skips (rather than fails) until it exists. It never calls a real AI provider: the HTTP transport is mocked. [docs/qa-testing.md](docs/qa-testing.md) covers setting up the test database, what each test proves, and the manual QA checklists for participants and researchers.

## Differences from the standalone chatbot-app

- No `User` entity, login, registration or user management. The host owns those.
- Dark mode, larger font and theme preferences are gone. They're site-wide settings, so they belong to the host.
- `UserChatHistory`, the legacy log, isn't carried over. Nothing is migrated from the old database.
- The unused `check_ollama_site()` Twig function, `Mistral_Completions` and `skito/aipi` have been removed.
- API keys come from bundle configuration instead of the bundle reading environment variables itself.
- Everything is prefixed (routes, Twig functions, voter attributes, rate limiters, CSS classes, translation domain) so nothing can collide with the host.
