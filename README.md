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

## Access control

| Voter attribute | Granted when |
|---|---|
| `empathica_chatbot.view_history` | The user is logged in. Queries are always scoped to the current user. |
| `empathica_chatbot.research_settings` | The user holds `admin_role`. This unlocks provider, persona and debug mode, plus the testing-only empathy levels 4 and 5. |
| `empathica_chatbot.admin` | `features.admin` is on **and** the user holds `admin_role`. |

Setting `features.admin: false` hides the admin menu entry **and** makes `/chat/admin/*` answer 403. The data is kept.

> **Study safety:** debug mode makes the model announce the active empathy and literacy levels, which tells a participant which experimental arm they're in. Only admins can turn it on, and it's off by default.

## Graceful degradation

Everything works without JavaScript. The chat is a plain POST, redirect, GET form. The conversation panel opens with CSS `:target`. Read-aloud falls back to the browser's `speechSynthesis`, and silently to nothing if that's missing. The script only adds enhancements (loading indicator, focus management, Escape to close, Gemini voices), and it re-initialises on Turbo navigations.

## Prompts

The system prompt is Markdown in `config/prompts/`. To edit it without forking the bundle, copy the directory and point `empathica_chatbot.prompts_dir` at the copy. `PromptRepository::getVersion()` stamps every stored turn with a hash of the prompt files, so you can always tell which prompt produced a transcript.

## Tests

```bash
php bin/phpunit                 # once installed with its own dev dependencies
```

Most tests are plain `TestCase`s with no I/O. `ProviderWiringTest`, `TextToSpeechWiringTest` and `TextToSpeechRouteTest` boot a kernel, so they need a host application (`KERNEL_CLASS`).

## Differences from the standalone chatbot-app

- No `User` entity, login, registration or user management. The host owns those.
- Dark mode, larger font and theme preferences are gone. They're site-wide settings, so they belong to the host.
- `UserChatHistory`, the legacy log, isn't carried over. Nothing is migrated from the old database.
- The unused `check_ollama_site()` Twig function, `Mistral_Completions` and `skito/aipi` have been removed.
- API keys come from bundle configuration instead of the bundle reading environment variables itself.
- Everything is prefixed (routes, Twig functions, voter attributes, rate limiters, CSS classes, translation domain) so nothing can collide with the host.
