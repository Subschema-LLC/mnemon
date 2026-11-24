# Agent Instructions

This project's agent instructions live in [`CLAUDE.md`](./CLAUDE.md). It applies to all coding agents (Claude Code, Cursor, Codex, Copilot, Gemini CLI, etc.) — read it first.

Mnemon is moving, one piece at a time, to the [FIDES](https://github.com/Subschema-LLC/FIDES-Software-Architecture) architecture inside Laravel. New code goes in `src/` and follows the rules below. Code in `app/` has not moved yet.

For deeper context, see:

- [`docs/USERGUIDE.md`](./docs/USERGUIDE.md) — day-to-day playbook: setup, connecting agents, OAuth, troubleshooting
- [`README.md`](./README.md) — what the project is, why it exists, and its limitations
- [`docs/design/`](./docs/design/) — dated design rationale, kept for history and not maintained as current documentation

<!-- FIDES:BEGIN version=v0.4-pre. Managed by the FIDES sync: edit the rules upstream, not here. -->
**FIDES rules.** These are the rules every FIDES repository shares. This
repository's own guide, outside this block, adapts them to its language and
framework and names its check command. Where the two differ, follow this
repository's guide: its checker enforces it.

**Rules**

1. Only the owner changes its data. Others call its actions.
2. Entities import only their own folder. Refer to other entities by id.
3. One use case per feature folder. Features never import each other.
4. The core (entities, features, shared) never touches the database, files,
   network, environment or clock.
5. Dependencies point inward. Only wiring imports integrations.
6. No inheritance. Compose. Contracts are interfaces. A custom error may
   extend one built-in exception, nothing else. No traits or mixins in the core.
7. Messages: entities record, stores save with the change, a worker
   dispatches after commit. Every handler is safe to run twice.
8. One write by default. A UnitOfWork only when two entities change.
9. Settings: declared in code by their owner, overridden in config/, then by
   the environment. Secrets come from the environment only. The database
   never holds configuration.
10. Views only display values their feature shaped. Escaped by default.
11. Pages compose features: a folder per page in delivery. A form belongs to
    the feature that handles it. A feature view never renders another's. CSS
    and JS sit with the layout, feature or page, never inline.
12. At most `zone/owner/file` deep (pages: `delivery/<door>/<page>/file`;
    grouped features: `features/<group>/<use_case>/file`, all or none, no code
    in a group). Lines up to 100 characters. One casing (snake_case or
    PascalCase) everywhere.
13. Files: aim for 200 code lines, never past 300 (blank lines and comments
    do not count). Past 200, split out any concept you can name. Keep a file
    whole only if one name covers all of it and a split would leave `part1`,
    `helpers` or files that import each other, and say why in your summary.
    200 to 300 is room for one concept, not space to fill.
14. Every file opens with what it does and why. Comments say why, and what is
    true now; history goes in the commit message. A decision no unit owns
    goes in `docs/decisions/<name>.md`, named in code as `Decision: <name>`.
    A TODO names its issue: TODO(#123).

**Naming.** A name is a promise; if the code drifts from it, that is a defect.

- A reader who knows the domain can guess a feature's path, and a file's
  contents from its name. If they cannot, rename it.
- Full words, no abbreviations, nothing clever. Length is fine; ambiguity
  is not.
- One word per concept, spelled the same in folders, classes, methods,
  routes and tests.
- Features are verb phrases (IssueInvoice), entities plain nouns (Invoice),
  and a method that answers yes or no is a question (isOverdue).
- No empty words: Manager, Helper, Utils, Handler, Processor, Data, Info,
  Misc. "And" in a name means two jobs: split the unit.
- Tests are named as the rule they check (invoice_total_includes_tax).

**Spread.** If a change touches more than its feature folder, the entities it
names, one integration and wiring, stop and ask whether a concept is spread
too far.
<!-- FIDES:END -->

## This app

Run `composer fides` before you finish: it tests the checker, then checks the architecture. CI runs the same in the `fides` job. Also run `php artisan test --compact` and `./vendor/bin/pint`.

### Where code goes

| What | Where |
|---|---|
| A rule always true for a concept (Wing, Room, Drawer, Document, WikiPage) | `src/Entities/<Concept>/` |
| Something a user or agent does, named as a verb phrase (AddDrawer, UploadDocument) | `src/Features/<UseCase>/` |
| A contract no single entity owns (a clock, a unit of work) | `src/Shared/` |
| A way the app reaches the world: an Eloquent record, an embedding or digest provider, file storage, a parser library | `src/Integrations/<Name>/`, behind a contract |
| A way the world reaches the app: an MCP tool, an HTTP controller, an artisan command, an admin screen | `src/Delivery/<Door>/` (`Mcp`, `Http`, `Cli`, `Admin`) |
| Binding every contract to its integration | `src/Delivery/Wiring.php`, a service provider listed in `bootstrap/providers.php` |
| An operator's settings file (`LlmModel.yaml`) | the overrides folder, `storage/overrides/` (`MNEMON_OVERRIDES_PATH`), one file per owner, read and written through the owner's store contract |

Namespaces are `Mnemon\` plus the folder path. Feature groups (`src/Features/<Group>/<UseCase>/`) are not decided yet: the first feature to move decides, and then every feature follows.

### How the rules fit Laravel

- **Eloquent models are integrations.** An active-record model inherits from the framework, so it is the store's record, never an entity. A store in `src/Integrations/` copies between the record and the entity.
- **No facades or helpers in the core.** `DB::`, `Cache::`, `config()`, `now()` and the like hide a dependency. A feature names what it needs in its constructor; delivery and integrations may use the framework freely.
- **A base class the framework requires** (a service provider, a `laravel/mcp` tool, a Filament resource) is allowed only at the edge, in delivery or integrations, kept thin, and listed with its reason in `FIDES_EDGE` in `fides/arch-check.php`.
- **Settings:** the framework's `config/` and `.env` stay as they are. Settings an operator curates live in the overrides folder, never the database: the chat models, their prices and the digest model are `LlmModel.yaml`, kept by `YamlLlmModelStore` behind `LlmModelStore`. The **LLM models** admin page writes the same file.
- **Tests** never touch a real settings file: `Tests\TestCase` binds an in-memory store before the migrations run, and `addLlmModel()` adds a model the way the admin page does.

### Moving a piece out of `app/`

1. Pick one concept that has tests and is about to change anyway.
2. Build it in `src/`. Code in `src/` never imports `app/`, except an integration that wraps an old service as an adapter until that service moves too. Old code may call into `src/`.
3. Point the old callers at the new code, delete the old code, and keep the tests passing at every step.
4. `app/` is held to a ratchet in `fides/ratchet.json`: its count of inheritance, oversized files and facade imports may go down, never up. When a move lowers a count, run `php fides/arch-check.php --update-ratchet` and commit the file. A count that goes up fails the build; write the new code in `src/` instead.

Moved so far: `LlmModel` (entity and store in `src/`; its admin page is still in `app/Filament/Pages/LlmModels.php`, until the model catalog and usage totals move).

The checker does not cover Blade views (rules 10 and 11) or settings (rule 9) yet.
