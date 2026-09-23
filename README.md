# ttnews_link_converter

TYPO3 13/14-compatible extension providing a CLI command that converts legacy
tt_news-style RTE link tags (carried over from an old tt_news import on
filmogtro.dk) into modern TYPO3 record-link syntax:

```
<link record:tt_news:573 - internal-link>Kingdom of Heaven</link>
```
becomes
```
<a href="t3://record?identifier=tx_news&uid=576">Kingdom of Heaven</a>
```

Scans `tx_news_domain_model_news.bodytext` and `tt_content.bodytext`/`teaser`
by default - see `SCAN_TARGETS` in `Classes/Service/LinkConverterService.php`
to add further table/field pairs for other projects.

## Status

Installed and confirmed working against filmogtro.dk's `tx_news` content.
`tt_content` scanning was added for a future project's needs and has not yet
been exercised against real `tt_content` data. See
[`specs/project-plan.md`](specs/project-plan.md) for the full design and the
Verification section covering how to safely roll this out (dry-run first,
spot-check one row, then execute).

## Usage

```
vendor/bin/typo3 imhlab:ttnews-link-converter:convert              # dry-run (default)
vendor/bin/typo3 imhlab:ttnews-link-converter:convert --execute    # write changes
```

Options: `--limit=N` (cap candidate rows, default unlimited), `--log-file=PATH`
(default `var/log/ttnews_link_converter-unresolved_links.log`),
`--log-format=text|json` (default `text`).

## TYPO3 14 compatibility

`composer.json`/`ext_emconf.php` accept TYPO3 `^13.4 || ^14.0`. Every core API
this extension uses (`DataHandler`, `ConnectionPool`/QueryBuilder, `SiteFinder`,
`Bootstrap::initializeBackendAuthentication()`, the `console.command` Services.yaml
tag, `Environment::getVarPath()`) was confirmed unchanged between 13.4 and 14 via
the official TYPO3 14 changelog and upgrade docs - no code changes were needed.
This has not been run against an actual TYPO3 14 installation yet (none exists in
this project); re-verify once the parent project's upgrade is underway.

## Development notes

This extension's design and implementation were produced with AI assistance
(Claude Code / Anthropic Claude) under human review by Daniel Alexander Damm
(IMHlab).

## Author

Daniel Alexander Damm <dad@imh.dk> — IMHlab

## License

GPL-2.0-or-later
