# Changelog

All notable changes to this add-on are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The `product_media` skill: generate product images (Nano Banana 2) and videos (Seedance 2.0,
  Kling 3.0) with Higgsfield from the admin chat, check their status and add generated images to
  the product gallery.
- OAuth 2.1 connection to the Higgsfield MCP server under Stores › Configuration › Mago Assistant ›
  Higgsfield.
- English (`en_US`) and Dutch (`nl_NL`) translations.
- `SECURITY.md` with how to report a vulnerability.

### Fixed

- A Higgsfield server that kept dropping the MCP session made the client retry without end. It now
  opens one new session and gives up after that.
- Reconnecting from a different admin URL no longer disconnects the working account before the new
  login completes.
- The configuration page no longer shows "Connected" for an expired token that can't be refreshed.
- The token expiry on the configuration page is shown in the store's timezone instead of UTC.
