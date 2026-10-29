# QA and testing

How to check that the EMPATHICA chatbot works, for developers running the automated tests and for anyone doing a manual check before a release. No programming knowledge is needed for the manual checklists in sections 3 and 4.

- [1. Automated tests](#1-automated-tests)
- [2. What the automated tests cover](#2-what-the-automated-tests-cover)
- [3. Manual checklist: participants](#3-manual-checklist-participants)
- [4. Manual checklist: researchers (admins)](#4-manual-checklist-researchers-admins)
- [5. Recording results](#5-recording-results)

---

## 1. Automated tests

The bundle has no application of its own, so its tests run **inside the host** (Infosage), using the host's PHPUnit and kernel. Run every command from the host's root folder.

```bash
# Everything
php vendor/bin/phpunit -c vendor/pixiekat/empathica-chatbot/phpunit.dist.xml

# Just the quick tests, which need no database
php vendor/bin/phpunit -c vendor/pixiekat/empathica-chatbot/phpunit.dist.xml --testsuite unit

# Just the full-request tests
php vendor/bin/phpunit -c vendor/pixiekat/empathica-chatbot/phpunit.dist.xml --testsuite functional
```

### Setting up the test database (once per machine)

The **functional** tests make real requests against the host's *test* database. Until it exists, every one of them is reported as **skipped**, with a message pointing here. That's expected, not a failure.

The host's `.env.test` says which database the tests use. For Infosage it's `infosage_app_unittest`, reached as the MariaDB user `symfonyusertest`. That user is separate from the application's own user, so a misconfigured test run can't reach development data. (Infosage's `infosage_app_test` is a different database, which holds legacy-migration trial data. Don't point tests at it.)

Creating the database needs a database administrator account:

```bash
# 1. Create the database and a user that can reach ONLY it (needs an admin login).
sudo mariadb -e "CREATE DATABASE infosage_app_unittest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE USER IF NOT EXISTS 'symfonyusertest'@'%';
                 GRANT ALL PRIVILEGES ON infosage_app_unittest.* TO 'symfonyusertest'@'%';"

# 2. Create every table: the host's, SymfonyHelpers' and this bundle's.
php bin/console doctrine:schema:create --env=test
```

To start again from empty (for example, after an interrupted run left test users behind):

```bash
php bin/console doctrine:schema:drop --force --full-database --env=test
php bin/console doctrine:schema:create --env=test
```

`schema:create` builds the tables straight from the entity mappings. That's quicker and more reliable for a throwaway test database than replaying every migration. If the entities change later, refresh it with `php bin/console doctrine:schema:update --env=test --force`.

### Things the tests promise

- **They never call a real AI provider.** The HTTP transport is replaced by a mock in every functional test, so API keys on the machine don't matter and nothing is billed. See `tests/Functional/ChatbotWebTestCase.php`.
- **They clean up after themselves.** Each test creates its own users, with addresses like `chatbot-test-…@example.com`, and deletes them afterwards, along with their conversations and audit entries.
- **They never touch the development database.** `APP_ENV=test` is forced in `phpunit.dist.xml`.

---

## 2. What the automated tests cover

| Area | Test | What it proves |
|---|---|---|
| Security: XSS | `Twig/MessageExtensionRuntimeTest` | Scripts, event-handler attributes and `javascript:`/`data:` links in a message are shown as text, never run, for the participant and for an admin reading the transcript. |
| Security: CSV injection | `Export/CsvSanitizerTest` | A message starting with `=`, `+`, `-` or `@` can't run as a spreadsheet formula when an export is opened. |
| Permissions | `Security/Voter/DefaultVoterTest` | The full matrix of participant/admin × admin screens on/off. Turning the admin screens off doesn't take research settings away. |
| Settings form | `Form/ChatbotFormsTest` | Participants never see the research fields or the testing-only empathy levels, and can't submit them by hand. Message and saved-context length limits. |
| Preferences | `Services/ChatbotPreferencesManagerTest` | Opening the chat never writes to the database; defaults come from configuration. |
| Configuration | `DependencyInjection/ExtensionTest` | Safe defaults (read-aloud off, admin role-gated), and the read-aloud key falling back to the Gemini key. |
| Widget plumbing | `Twig/WidgetFrameTest` | The widget is detected only by its own frame id; caches are told the page varies by it. |
| Access (functional) | `Functional/ChatAccessTest` | Logged-out visitors go to login. **One participant can't open, list or have read aloud another participant's conversation.** CSRF on "New conversation". |
| Sending (functional) | `Functional/ChatMessageTest` | When the provider fails, the question is still saved and the participant is told. Over-long messages are refused before anything is sent. Rapid messages are throttled. |
| Admin (functional) | `Functional/AdminAccessTest` | Participants are refused on every admin page and export. Admins get in. CSV is a download with formulas neutralised. Reading and exporting are audited, without the participant's words in the log. |
| Widget (functional) | `Functional/WidgetTest` | The widget gets the slim frame; the full page gets the host layout and no launcher. |

Also in `tests/`: provider request and response parsing, prompt assembly, read-aloud text splitting, entity behaviour, and the read-aloud route shape.

**Not covered automatically:** anything that needs a real browser, such as JavaScript behaviour, focus, screen readers, layout and colour. That's what the manual checklists below are for.

---

## 3. Manual checklist: participants

Use an ordinary (non-admin) account. Do each section on **desktop** and on a **phone** (or a browser window under 768px wide). Tick only when the **Expected** result happens exactly.

### 3.1 Getting to the chat

| # | Steps | Expected |
|---|---|---|
| P1 | Log out, then visit `/chat`. | You're taken to the login page, not shown an error. |
| P2 | Log in. On desktop, look at the bottom-right of any page. | A round chat button is there. Hovering over it, or tabbing to it, shows "Chat with EMPATHICA". |
| P3 | On a phone, look at the bottom navigation. | There's a chat item in the bottom bar, and no separate floating circle covering it. |
| P4 | Visit `/chat` itself. | The chat fills the page, and there's no floating button on top of it. |

### 3.2 The floating window (desktop)

| # | Steps | Expected |
|---|---|---|
| W1 | Click the round button. | A chat window opens above it. The page behind can still be scrolled and clicked. |
| W2 | Move to another Infosage page using the site menu. | The chat window stays open, with the same conversation. |
| W3 | Click **Full screen**. | The chat fills the screen. The button now reads **Exit full screen**. The page behind can't be reached with the mouse or Tab. |
| W4 | Press **Escape**. | The window closes, and keyboard focus goes back to the button that opened it. |
| W5 | Open the window, then **Conversations**, then press **Escape** once. | Only the conversation list closes. The chat window stays open. Press Escape again: now the window closes. |
| W6 | Click **Open full page**. | You go to `/chat`, in the same conversation. |
| W7 | On a phone, tap the chat item in the bottom bar. | The chat opens full screen. There's no Full screen button, because full screen is the only mode on a phone. |

### 3.3 Chatting

| # | Steps | Expected |
|---|---|---|
| C1 | Type a question about a medication and press **Submit**. | The button turns into "Loading..." and can't be pressed twice. An "EMPATHICA is thinking..." box appears. Then the answer appears, and the page moves to it. |
| C2 | Read the help text under the message box. | It says the limit (4,000 characters), and that conversations are saved and the research team can read them. |
| C3 | Paste a very long piece of text. | The box stops accepting text at the limit. |
| C4 | Press **Submit** with an empty box. | "Please enter a message." No message is sent. |
| C5 | Send about a dozen messages as fast as you can. | Eventually a yellow message asks you to wait a few seconds. Your text stays in the box. |
| C6 | Send a message, then press the browser's **Back** button. | The page is usable: the button says Submit again and the thinking box is gone. |
| C7 | On an answer, press **Listen to Response**. | The button changes to "Preparing audio…", then "Stop Reading", and the answer is read aloud. Pressing it again stops it. |
| C8 | Press **Copy Response**, then paste somewhere. | The button briefly says "Copied!", and the answer pastes correctly. |
| C9 | Press **New conversation**. | A "New conversation" note appears and the previous messages are gone from view. |
| C10 | Open **Conversations**. | The previous conversation is listed, with its date and message count. Clicking it brings it back. |
| C11 | Ask the chatbot to include a link in its answer, and click the link. | It opens normally. In the floating window, it opens as a whole page instead of an empty box. |

### 3.4 Settings

| # | Steps | Expected |
|---|---|---|
| S1 | Open **Settings**. | You see the saved context, literacy level and empathy level. You **don't** see Persona, Chatbot, Debug Mode, or the "Anti empathy" and "Confrontational" levels. |
| S2 | Change the literacy level and save. | "Preferences saved." The chat footer shows the new level, and the next message starts a new conversation. |
| S3 | On the chat page, click the literacy level link in the footer. | The settings page opens at the literacy level field. |

### 3.5 Without JavaScript

Turn JavaScript off in the browser. In Firefox: `about:config` → `javascript.enabled` → false.

| # | Steps | Expected |
|---|---|---|
| N1 | Click the chat button or bottom-bar item. | It goes to the full `/chat` page. |
| N2 | Send a message. | The answer appears after a page reload. (There's no thinking box, which is expected.) |
| N3 | Click **Conversations**. | The list opens. **Close** closes it. |
| N4 | Change a setting and save. | It saves. |

### 3.6 Accessibility

| # | Steps | Expected |
|---|---|---|
| A1 | Use only the keyboard (Tab, Shift+Tab, Enter, Space, Escape) for all of 3.2 and 3.3. | Everything can be reached and used, and you can always see where the focus is. |
| A2 | With a screen reader (NVDA on Windows, VoiceOver on Mac or iPhone), send a message. | "EMPATHICA is thinking" is announced while you wait, and focus lands on the reply when it arrives. |
| A3 | Zoom the browser to 200%. | Nothing overlaps or is cut off. The page scrolls only up and down, not sideways. |
| A4 | Switch the device to dark mode. | The chat and the floating window are dark too, and all text is readable. |
| A5 | Turn on "reduce motion" in the operating system. | The thinking box and the window-opening animation don't move. |
| A6 | Windows High Contrast mode, if available. | The round button and the window edges are still visible. |

---

## 4. Manual checklist: researchers (admins)

Use an account with the admin role. Have a second, participant account that has a few conversations.

| # | Steps | Expected |
|---|---|---|
| R1 | Open the control panel menu. | There's a **Chatbot → Conversations** entry. |
| R2 | Open it. | A list of everyone who has chatted, with counts and last activity, most recent first. |
| R3 | Open a participant's conversations, then a transcript. | Every message is there in order. Answers show provider, model, levels, tokens and time. |
| R4 | Download a transcript CSV, then a participant's CSV, then the full export. Open each in Excel or LibreOffice. | Accented characters display correctly. A message that started with `=` shows as text with a leading `'`, not as a formula. |
| R5 | Open the SymfonyHelpers **audit log**. | R3 and R4 each left a line such as "*you* read a chatbot conversation belonging to *participant*". The R2 list didn't. No line contains anything the participant typed. |
| R6 | Open **Settings** in the chat. | Persona, Chatbot and an **Administrative Options** section with Debug Mode are shown, along with all five empathy levels. |
| R7 | Turn on Debug Mode and send a message. | The answer mentions the active levels. **Turn it off again.** Never enable it for a study participant's account. |
| R8 | Log in as the participant and try to open an admin URL (copy one from R3). | "Access denied" (403). |
| R9 | *(Developers)* Set `empathica_chatbot.features.admin: false` and clear the cache. | The menu entry is gone and every `/chat/admin` URL answers 403. Research settings (R6) still work. Set it back afterwards. |

---

## 5. Recording results

For each run, note:

- the date, the build or commit, and who tested;
- the browser, device and, for A2, the screen reader and version;
- the checklist numbers that failed, with what happened instead, and a screenshot if possible.

A failure in **P1, C1, R3, R5 or R8**, or any accessibility item, should block a release.
