# Plan: `ttnews_link_converter` TYPO3 extension

## Context

filmogtro.dk's news content (`tx_news_domain_model_news`, ~2912 rows, `import_source='TT_NEWS_IMPORT'`) was migrated from an old tt_news installation. The migration carried over `bodytext` verbatim, including old tt_news-style link tags such as:

```
<link record:tt_news:573 - internal-link>Kingdom of Heaven</link>
```

These tags are inert in TYPO3 13/14 — they don't resolve to anything — and there is no existing tool to fix them. This extension provides a one-shot, re-runnable CLI command to rewrite these into modern TYPO3 record-link syntax so the links work again. It needs to be safe (dry-run by default), auditable (log of anything it can't resolve), and minimal (single-purpose CLI tool, not a general framework).

## Update — 2026-09-23: multi-table scanning + TYPO3 14 support

Shipped, installed via Composer into the main project, and confirmed working against filmogtro.dk's real `tx_news` data. Two follow-up changes were made after that initial rollout:

1. **Multi-table/field scanning.** The current site has no notable `tt_content` content, but an upcoming project does, so scanning was generalized from a single hardcoded table (`tx_news_domain_model_news.bodytext`) to a configurable list of `(table, fields[])` targets — see `LinkConverterService::SCAN_TARGETS`. `tt_content.bodytext` and `tt_content.teaser` (both standard core fields, confirmed present in this project's schema) were added alongside the original `tx_news_domain_model_news.bodytext` target. The write boundary (formerly `NewsRecordWriter`, renamed `RecordFieldWriter` since it's no longer news-only) was made table-agnostic to match — it accepts `table => uid => field => value` and batches everything into one `DataHandler` call regardless of source table. `ConversionResult`/`ConvertedLink`/`LinkIssue` all gained `table`/`field` properties so the summary table and issue log stay traceable across sources. The SQL candidate-prefilter was also widened to include the filmid-redirect marker (`filmogtro.dk/index.php?site=anmeldelserread`), fixing a latent gap where a row containing *only* a filmid-redirect link (no `record:tt_news:` reference) would never have been selected for scanning, and so its issue would silently never reach the log.
2. **TYPO3 14 compatibility.** The parent project plans to upgrade to TYPO3 14 before launch and wants to keep this extension across that upgrade. Research against the official TYPO3 14 changelog/upgrade docs confirmed every core API this extension uses (`DataHandler`, `ConnectionPool`/QueryBuilder, `SiteFinder`, `Bootstrap::initializeBackendAuthentication()`, the `console.command` Services.yaml tag, `Environment::getVarPath()`) is unchanged between 13.4 and 14 — no code changes were needed. `composer.json`/`ext_emconf.php` now declare `typo3/cms-core: ^13.4 || ^14.0`. This has not been run against an actual TYPO3 14 installation (none exists in this project yet) — re-verify once the parent project's upgrade is underway.

## Research findings that drive the design

- **On `tx_news_domain_model_news`, `bodytext` is the only field affected.** `teaser` was confirmed empty of any legacy link markup (0 of 2912 rows) — not scanned on this table. (`tt_content.teaser` is scanned, per the 2026-09-23 update above — a different table/column, confirmed present in this project's schema, added for an upcoming project's needs.) The parser itself is field-agnostic.
- **The number inside the old tag is the *old* tt_news uid, not the new one.** `tx_news_domain_model_news.import_id` (varchar) stores that old uid as a string. Resolution requires:
  ```sql
  SELECT uid FROM tx_news_domain_model_news
  WHERE import_source = 'TT_NEWS_IMPORT' AND import_id = '<old_uid>'
  ```
  136 of 3048 old tt_news rows were never migrated and have no match — this is expected, not an error, and must be logged rather than blocking the run.
- **Two link shapes are in scope**, both resolved the same way:
  1. Dominant (~1325 rows): `<link record:tt_news:926 - internal-link>Title</link>` — the text after ` - ` (the class) varies, including a "wrong" `external-link-new-window` class on genuine internal links; the pattern must ignore the class and match on `record:tt_news:N` alone.
  2. Rare raw-HTML remnant (1 row, 5 occurrences): `<a href="record:tt_news:6" mce_href="../index.php?...">Title</a>` — different tag shape, same resolution logic.
- **Out of scope, confirmed with user**: plain external `<link http...>` tags (~467 rows, never reference tt_news) are left untouched entirely (not even logged). 16 rows with legacy `<link http://filmogtro.dk/index.php?site=anmeldelserread&link=stlink&filmid=N>` redirect links are detected and logged as "unhandled" but not converted — they use a different id space (`filmid`) that isn't `import_id`, and mapping it is unconfirmed.
- **Target syntax** (confirmed via TYPO3 core `RecordLinkHandler` and the `georgringer/news-recordlinks` site set already active on this project's site, which registers `tx_news` as the record-link identifier):
  ```html
  <a href="t3://record?identifier=tx_news&uid={new_uid}">{link text}</a>
  ```
- **Conversion is naturally idempotent**: the regexes only match legacy shapes, never the converted `<a href="t3://record?...">` output, so re-running the command is safe and a second dry-run is a good way to confirm nothing legacy is left (aside from the intentionally-skipped filmid rows).

## Decisions made with the user

1. **Composer wiring**: a GitHub repo has been created for this extension at `https://github.com/Beltshassar/ttnews_link_converter` (assumed — the user gave the URL as `https://Beltshassar/ttnews_link_converter`, missing the `github.com` host; **confirm this before it's used for an actual push or composer edit**). Wire it into root `/var/www/html/composer.json` the same way the other four `imhlab/*` packages are wired to their Bitbucket repos — a `git`-type repository entry — rather than the originally-planned temporary `path` repository, since a real remote now exists from the start:
   ```jsonc
   "require": {
       "imhlab/ttnews_link_converter": "dev-main"
   },
   "repositories": {
       "imhlab_ttnews_link_converter": {
           "type": "git",
           "url": "git@github.com:Beltshassar/ttnews_link_converter.git"
       }
   }
   ```
   Branch assumed `main` (GitHub's current default for new repos, unlike Bitbucket's `master`-based siblings) — confirm against the actual default branch of the new repo before requiring it. This is still the one step that touches a file outside `vendor/imhlab/`, which project rules normally forbid without explicit sign-off — flagged here as that sign-off, and it should be called out again at implementation time. The local extension directory (`vendor/imhlab/ttnews_link_converter`) needs its own git init/remote pointing at this GitHub URL and an initial push before `composer update` can resolve it.
2. **Legacy `filmid` redirect links**: out of scope. Detected and logged as an "unhandled pattern" for visibility, not converted.
3. **DB writes go through `DataHandler::process_datamap()`**, not a raw SQL `UPDATE` — matches the precedent in `vendor/imhlab/film/Classes/Command/GenerateNewsListPerCategoryCommand.php`, and gives proper backend history/undo plus reference-index maintenance. This does mean the write path depends on TYPO3's RTE-transform-on-persist pipeline (`RteHtmlParser::transformTextForPersistence()`), which round-trips every `<a href>` through `LinkService`/sanitizer on save — expected to be a no-op for our already-canonical hrefs, but worth a one-row `--execute --limit=1` spot check before running the full batch (see Verification). Unlike the sibling extension's command (which hardcodes the DDEV hostname into `$_SERVER['HTTP_HOST']` for this same DataHandler-on-CLI bootstrap), this extension must work regardless of environment/hostname — so the host is resolved dynamically from the site configuration instead of hardcoded (see `RecordFieldWriter` below).
4. **Dry-run by default.** No flags = preview only, nothing written. `--execute` is required to actually write changes.

## Extension scaffold

```
vendor/imhlab/ttnews_link_converter/
├── composer.json
├── ext_emconf.php
├── README.md
├── .gitignore
├── Classes/
│   ├── Command/
│   │   └── ConvertLinksCommand.php        # thin CLI glue only
│   ├── Service/
│   │   ├── LegacyLinkParser.php           # pure regex scan+replace, no DB/TYPO3 deps
│   │   ├── NewsImportIdResolver.php       # old uid -> new uid, via import_id lookup (cached)
│   │   ├── LinkConverterService.php       # orchestrates parser+resolver, no writes
│   │   ├── RecordFieldWriter.php           # isolated DataHandler write boundary
│   │   └── LinkConversionLogWriter.php    # writes the unresolved/unhandled log
│   └── Domain/Dto/
│       ├── ConversionResult.php
│       ├── ConvertedLink.php
│       ├── LinkIssue.php
│       └── BatchWriteResult.php
└── Configuration/
    └── Services.yaml
```

No `ext_localconf.php`, no `ext_tables.sql`, no TCA, no `Tests/` scaffolding — this tool adds no plugins/schema and `LegacyLinkParser` is trivially testable later without needing a full PHPUnit harness now. `ext_emconf.php` isn't strictly required in TYPO3 13 composer mode but is included as a small, cheap addition matching `imhlab/film`'s existing convention (Extension Manager listing/author display).

### `composer.json`

```json
{
    "name": "imhlab/ttnews_link_converter",
    "type": "typo3-cms-extension",
    "description": "CLI command converting legacy tt_news RTE link tags in tx_news_domain_model_news.bodytext to TYPO3 13 record-link syntax. Developed with AI assistance (Claude Code).",
    "authors": [
        {
            "name": "Daniel Alexander Damm",
            "role": "Developer",
            "email": "dad@imh.dk",
            "homepage": "https://indremission.dk"
        }
    ],
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.2",
        "typo3/cms-core": "^13.4 || ^14.0"
    },
    "autoload": {
        "psr-4": { "Imhlab\\TtnewsLinkConverter\\": "Classes" }
    },
    "extra": {
        "typo3/cms": { "extension-key": "ttnews_link_converter" }
    }
}
```

- `typo3/cms-core` constrained to `^13.4 || ^14.0` — widened per the 2026-09-23 update above once TYPO3 14 was confirmed released and every API this extension uses was verified unchanged against the official 13→14 changelog/upgrade docs.
- `georgringer/news` is **not** added as a hard Composer dependency — this tool never calls GeorgRinger PHP classes, only the `tx_news_domain_model_news` table by name via `ConnectionPool`. The real coupling (table shape, and the `tx_news` record-link identifier being active) is documented in the README instead, keeping the tool decoupled per "simple, single-purpose."
- Vendor/author metadata: package vendor namespace stays `imhlab/` (matches Composer/Bitbucket convention of siblings); "IMHlab" and "Daniel Alexander Damm" are represented in `authors` and should also appear in `ext_emconf.php`'s `author`/`author_company`.

### `Configuration/Services.yaml`

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Imhlab\TtnewsLinkConverter\:
    resource: '../Classes/*'

  Imhlab\TtnewsLinkConverter\Command\ConvertLinksCommand:
    tags:
      - name: 'console.command'
        command: 'imhlab:ttnews-link-converter:convert'
        schedulable: false
        description: 'Convert legacy tt_news RTE link tags in tx_news bodytext to TYPO3 record-link syntax (dry-run by default; pass --execute to write)'
```

Matches the exact house pattern already used in `vendor/imhlab/film/Configuration/Services.yaml` — no `Configuration/Commands.php`, no CommandController.

## Business logic design

### `LegacyLinkParser` — stateless regex engine

Two patterns, matched independently, both routed through the same `resolve` callback:

```php
private const LINK_TAG_PATTERN =
    '#<link\s+record:tt_news:(?P<olduid>\d+)\b[^>]*>(?P<text>.*?)</link>#is';

private const RAW_ANCHOR_PATTERN =
    '#<a\s+href="record:tt_news:(?P<olduid>\d+)"[^>]*>(?P<text>.*?)</a>#is';

private const FILMID_REDIRECT_PATTERN =
    '#<link\s+http://filmogtro\.dk/index\.php\?site=anmeldelserread&link=stlink&filmid=(?P<filmid>\d+)[^>]*>(?P<text>.*?)</link>#is';
```

`convert(string $bodytext, callable $resolve, callable $onUnhandled): string` runs `preg_replace_callback` for each pattern in turn: the first two replace matched tags with the resolver's output (or leave the original tag untouched if resolution fails), the third is detection-only (always logs via `$onUnhandled`, never rewrites). The `[^>]*` after the numeric uid is what makes pattern 1 ignore the trailing class/title variants.

### `NewsImportIdResolver` — the id-mapping query, cached per old uid

```php
SELECT uid FROM tx_news_domain_model_news
WHERE import_source = 'TT_NEWS_IMPORT' AND import_id = :oldUid AND deleted = 0
```
Caches results in-memory (same target news item is linked from many bodytexts). More than one match is treated the same as "no match" (logged, not guessed) since `import_id` is expected unique per import.

### `LinkConverterService` — orchestration, no writes

`convertAll(int $limit = 0): list<ConversionResult>` selects candidate rows via a cheap SQL prefilter (`bodytext LIKE '%record:tt_news:%'`), then for each row calls `convertRow()`, which runs the parser with:
- a `resolve` closure that looks up the new uid via `NewsImportIdResolver`, emits `<a href="t3://record?identifier=tx_news&uid={new_uid}">{text}</a>` on success, or records a `LinkIssue` (`TYPE_UNRESOLVED_IMPORT_ID`) and leaves the tag untouched on failure;
- an `onUnhandled` closure that records a `LinkIssue` (`TYPE_UNHANDLED_FILMID_REDIRECT`) for the filmid pattern.

Returns a `ConversionResult` per row (`sourceUid`, `originalBodytext`, `newBodytext`, converted links, issues). This service is pure scan+transform — safe to call unconditionally in both dry-run and execute mode; only the Command layer decides whether to persist the result.

### `RecordFieldWriter` — isolated DataHandler write boundary

DataHandler triggers hooks that build a PSR-7 `ServerRequest` from `$_SERVER`, which isn't populated on CLI. The sibling extension's command hardcodes `$_SERVER['HTTP_HOST']` to the local DDEV hostname to work around this — that only works in one environment. This extension instead resolves the host **dynamically from the site configuration**, via `SiteFinder`, so it works unmodified on DDEV, staging, or production. (This class was originally named `NewsRecordWriter` with a `bodytext`-only, single-table `updateBodytext(array $uidToBodytext)` method, shown below for the CLI-bootstrap illustration; the 2026-09-23 update generalized it to `RecordFieldWriter::updateFields(array $tableUidFieldValues)` accepting `table => uid => field => value` across every scanned table — the bootstrap logic itself is unchanged.)

```php
public function __construct(
    private readonly SiteFinder $siteFinder,
) {}

private function bootstrapCliRequestContext(): void
{
    $site = current($this->siteFinder->getAllSites());
    if ($site === false) {
        throw new \RuntimeException('No TYPO3 site configuration found - cannot bootstrap a request context for DataHandler on CLI.', 1234567890);
    }

    $base = $site->getBase();
    $_SERVER['HTTP_HOST'] = $base->getHost();
    $_SERVER['HTTPS'] = $base->getScheme() === 'https' ? 'on' : 'off';
    $_SERVER['REQUEST_URI'] = '/';

    Bootstrap::initializeBackendAuthentication();
}

public function updateBodytext(array $uidToBodytext): BatchWriteResult
{
    if ($uidToBodytext === []) {
        return new BatchWriteResult([], []);
    }

    $this->bootstrapCliRequestContext();

    $data = ['tx_news_domain_model_news' => []];
    foreach ($uidToBodytext as $uid => $bodytext) {
        $data['tx_news_domain_model_news'][$uid] = ['bodytext' => $bodytext];
    }
    $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
    $dataHandler->start($data, []);
    $dataHandler->process_datamap();

    return new BatchWriteResult(array_keys($uidToBodytext), $dataHandler->errorLog);
}
```

One batched `process_datamap()` call for all changed rows in a run, not one per row — matches `GenerateNewsListPerCategoryCommand`'s batching and avoids re-running the bootstrap per row. Taking the *first* configured site is sufficient here since this project has a single site and the host is only needed to satisfy DataHandler's hook plumbing, not for any routing decision.

### `LinkConversionLogWriter` — the required failure log

- Default path: `Environment::getVarPath() . '/log/ttnews_link_converter-unresolved_links.log'` (TYPO3 core API, composer-mode-safe) — i.e. `var/log/ttnews_link_converter-unresolved_links.log`.
- Default format: plain text, one line per issue with timestamp, source uid, old uid (if applicable), issue type, message, and a truncated raw snippet. `--log-format=json` available for scripting against later.
- Overwritten each run (not appended) — the log should reflect the current remaining problem set, not accumulate duplicates across repeated dry-runs.
- Overridable via `--log-file=PATH` and `--log-format=text|json`.

### `ConvertLinksCommand` — thin CLI glue

Options: `--execute` (VALUE_NONE, default off = dry-run), `--limit=N` (default 0 = unlimited), `--log-file=PATH`, `--log-format=text|json` (default text).

Flow: call `convertAll()` unconditionally (never writes) → filter to rows/fields with actual changes → print a `SymfonyStyle` summary table (rows scanned, links converted, issues found) → if `--execute` and there are changes, group them by `table => uid => field => value` and call `RecordFieldWriter::updateFields()`, failing the command on any `errorLog` entries → always write the issue log → print final summary (dry-run vs executed, counts, log path).

## Verification

1. Initialize git in `vendor/imhlab/ttnews_link_converter`, add the GitHub remote, commit the scaffolded extension, and push to its default branch. Then, after adding the `git`-type repository entry to root composer.json, run `composer update imhlab/ttnews_link_converter -vvv` inside `/var/www/html` — confirm Composer clones/resolves the new GitHub repo correctly and that the installed package matches the local working copy.
2. `ddev exec vendor/bin/typo3 imhlab:ttnews-link-converter:convert` (no flags) — dry-run. Verify the summary table's counts look sane (~1325+ rows with matches, ~136 unresolved-import-id issues, 16 unhandled-filmid issues) and inspect the generated log file.
3. `ddev exec vendor/bin/typo3 imhlab:ttnews-link-converter:convert --execute --limit=1` — convert exactly one row, then manually inspect that row's stored `bodytext` in the database (via `ddev mysql`) to confirm the RTE transform-on-persist pipeline didn't mangle the `t3://record?identifier=tx_news&uid=N` href.
4. Spot-check that converted link resolves correctly on the frontend (visit a review page containing a formerly-broken link, confirm it now points to the right film review).
5. Run the full `--execute` (no `--limit`), then re-run a plain dry-run afterward — expect near-zero remaining `record:tt_news:` matches (aside from the 16 filmid rows, which never match the in-scope patterns).
6. Confirm re-running `--execute` a second time is a no-op (idempotency) — no rows reported as changed, since converted `<a href="t3://record?...">` no longer matches either legacy pattern.

## Follow-ups / explicitly out of scope

- Plain external `<link http...>` tags (~467 rows) — untouched, not this tool's concern.
- The 16 `filmid`-based legacy redirect links — logged only; a future tool could resolve `filmid` if that mapping is ever confirmed.
- Confirming the exact GitHub URL/default branch (assumed `https://github.com/Beltshassar/ttnews_link_converter`, branch `main`) before the first push and root composer.json edit.
- No automated tests scaffolded initially; `LegacyLinkParser` has no TYPO3 dependency and would be cheap to unit-test later if desired.

---

## Kickoff prompt (copy this to start AI-assisted development)

```
Implement the `ttnews_link_converter` TYPO3 13 extension per the plan at
vendor/imhlab/ttnews_link_converter (currently empty). Full design is recorded in
this repo's conversation history / plan doc — summary below.

Extension: imhlab/ttnews_link_converter, namespace Imhlab\TtnewsLinkConverter\,
author Daniel Alexander Damm <dad@imh.dk>, vendor "IMHlab", license
GPL-2.0-or-later. Mention in composer.json description and README that this
extension was developed with AI assistance (Claude Code).

Goal: a CLI command `imhlab:ttnews-link-converter:convert` that rewrites legacy
tt_news link tags embedded in tx_news_domain_model_news.bodytext into modern
TYPO3 record-link syntax.

Legacy patterns to convert:
  <link record:tt_news:N - internal-link>Text</link>   (ignore whatever follows
    " - ", e.g. sometimes wrongly tagged "external-link-new-window")
  <a href="record:tt_news:N" mce_href="..." class="internal-link">Text</a>

Resolve N (an OLD tt_news uid) to the current uid via:
  SELECT uid FROM tx_news_domain_model_news
  WHERE import_source = 'TT_NEWS_IMPORT' AND import_id = 'N' AND deleted = 0
If no match, log it as unresolved and leave the tag untouched — do not error out
(some old rows were never migrated).

IMPORTANT: this extension must be environment-agnostic. Do NOT hardcode the
DDEV hostname (filmogtro.dk.ddev.site) anywhere, even though the sibling
extension vendor/imhlab/film does this in its own CLI/DataHandler bootstrap.
Instead, resolve the host dynamically via SiteFinder->getAllSites() (take the
first configured site's getBase()->getHost()/getScheme()) before calling
Bootstrap::initializeBackendAuthentication(), so the command works unmodified
regardless of hostname/environment.

Replace matched tags with:
  <a href="t3://record?identifier=tx_news&uid={new_uid}">Text</a>

Also detect (but do NOT convert) legacy redirect links of the form
  <link http://filmogtro.dk/index.php?site=anmeldelserread&link=stlink&filmid=N>
— log them as "unhandled" only.

Explicitly leave alone (don't touch, don't log): any other <link http...>
external link that isn't one of the above.

Architecture: Classes/Service/LegacyLinkParser.php (pure regex, no DB),
Classes/Service/NewsImportIdResolver.php (cached DB lookup),
Classes/Service/LinkConverterService.php (orchestrates scan+transform, no
writes), Classes/Service/RecordFieldWriter.php (isolated DataHandler write
boundary — DataHandler on CLI needs $_SERVER['HTTP_HOST']/['HTTPS']/['REQUEST_URI']
populated before Bootstrap::initializeBackendAuthentication(); resolve these
dynamically via SiteFinder->getAllSites() as described above, never hardcode
a hostname),
Classes/Service/LinkConversionLogWriter.php (writes issue log to
Environment::getVarPath() . '/log/ttnews_link_converter-unresolved_links.log'
by default, plain text, one file per run, overridable via --log-file/--log-format),
Classes/Command/ConvertLinksCommand.php (thin CLI glue: --execute to write,
default is dry-run; --limit; --log-file; --log-format).

Register the command via Configuration/Services.yaml using the console.command
DI tag (see vendor/imhlab/film/Configuration/Services.yaml for the exact house
pattern) — no CommandController, no Configuration/Commands.php.

A GitHub repo already exists for this extension at
https://github.com/Beltshassar/ttnews_link_converter (confirm the exact URL
and its default branch before pushing - it was given verbally and may need
correcting). Initialize git in vendor/imhlab/ttnews_link_converter, add that
remote, commit the scaffolded extension, and push it. Then (with my explicit
confirmation before editing, since this touches a file outside vendor/imhlab/
which is normally off-limits) add a "git"-type repository entry for
imhlab/ttnews_link_converter in the root /var/www/html/composer.json pointing
at that GitHub URL, and require it pinned to the pushed branch (e.g.
"dev-main"), mirroring how the other four imhlab/* packages are wired to
their own Bitbucket repos in that same file.

After implementing, verify per the plan's Verification section: dry-run first
and sanity-check the summary counts and log, then --execute --limit=1 and
manually inspect the written bodytext in the database, then a full --execute,
then confirm a second dry-run/--execute run is a no-op (idempotent).
```
