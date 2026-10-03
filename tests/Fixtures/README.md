# Fixtures

`urlpatterntestdata.json` is vendored verbatim from
[web-platform-tests](https://github.com/web-platform-tests/wpt):

- Path: `urlpattern/resources/urlpatterntestdata.json`
- Commit: [`23aac9278460a73394585ff5a15b6a04dfcd5ec8`](https://github.com/web-platform-tests/wpt/blob/23aac9278460a73394585ff5a15b6a04dfcd5ec8/urlpattern/resources/urlpatterntestdata.json) (2026-06-12)
- License: [3-Clause BSD License](https://github.com/web-platform-tests/wpt/blob/master/LICENSE.md)

The test runner (`tests/Wpt/WptTest.php`) mirrors the semantics of the upstream
`urlpattern/resources/urlpatterntests.js` harness.

The file contains lone UTF-16 surrogates (e.g. `\uD83D`), which a JavaScript
binding converts to U+FFFD when converting to a `USVString`. PHP strings are
UTF-8 and cannot hold lone surrogates, so the loader applies that same
conversion before decoding the JSON.

To update, download the file from the new WPT commit and update the hash above.
