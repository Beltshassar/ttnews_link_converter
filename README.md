# ttnews_link_converter

TYPO3 13/14-compatible extension providing a CLI command that converts legacy
tt_news-style RTE link tags embedded in `tx_news_domain_model_news.bodytext`
(carried over from an old tt_news import on filmogtro.dk) into modern TYPO3
record-link syntax:

```
<link record:tt_news:573 - internal-link>Kingdom of Heaven</link>
```
becomes
```
<a href="t3://record?identifier=tx_news&uid=576">Kingdom of Heaven</a>
```

## Status

Implemented, not yet installed into the main filmogtro.dk project or run
against real data. See [`specs/project-plan.md`](specs/project-plan.md) for
the full design and the Verification section covering how to safely roll
this out (dry-run first, spot-check one row, then execute).

## Usage

```
vendor/bin/typo3 imhlab:ttnews-link-converter:convert              # dry-run (default)
vendor/bin/typo3 imhlab:ttnews-link-converter:convert --execute    # write changes
```

Options: `--limit=N` (cap candidate rows, default unlimited), `--log-file=PATH`
(default `var/log/ttnews_link_converter-unresolved_links.log`),
`--log-format=text|json` (default `text`).

## Development notes

This extension's design and implementation were produced with AI assistance
(Claude Code / Anthropic Claude) under human review by Daniel Alexander Damm
(IMHlab).

## Author

Daniel Alexander Damm <dad@imh.dk> — IMHlab

## License

GPL-2.0-or-later
