# HTE's changes to CortenDesk

This fork is HTE, Inc.'s copy of [CortenDesk](https://github.com/marcpope/cortendesk) (AGPL-3.0)
for HTE Remote. Branch `hte` = the upstream release tag plus the commits below. This file is the
statement of modifications the AGPL asks for (section 5(a)), kept apart from upstream's history so
merges stay clean. The ID server side is [regendorf-ux/hte-cortendesk-server](https://github.com/regendorf-ux/hte-cortendesk-server).

## 1.14.0-hte.3 (2026-10-16) on upstream 1.14.0

**Each computer gets its own password, made and changed by the console** (`app/Hte/Passwords.php`,
`DevicePassword.php`, `PasswordsSweep.php`, `DevicePasswordActions.php`, migration
`2026_10_16_900001_create_hte_device_passwords_table.php`):

- The HTE Remote client (regendorf-ux/hte-remote, `patches/hte_pw.rs`) sends `hte_pw` (the version it
  holds) with every check-in; the console answers `hte_password` = {v, box} until it reports the newest.
  `box` is a libsodium sealed box to the computer's own ed25519 key as hbbs registered it (read from
  hbbs's peer table, `/data/rustdesk/db_v2.sqlite3`), so a spoofed check-in learns nothing.
- Confirmed passwords go into one shared book per person, "HTE Remote", readable only by them, with
  exactly the computers they may reach (`Device::visibleTo`); another person's book is never listed to
  the client, admins included (`AddressBookController::accessibleSharedBooks`, one `reject`).
- `hte:passwords` (every minute): a new password on schedule (7/30/90 days or only on access loss) and
  at once when someone loses access to a computer; then the books are refreshed. "Change now" on the
  device page (administrators).
- Settings → Server: the switch and the schedule (`hte_pw_enabled`, `hte_pw_days`); the device page's
  "Who can reach this device" card has a "Password" row.
- Hooks: `SyncController::heartbeat` (one line), `AddressBookController` (one line),
  `routes/console.php` (register + schedule), `DeviceDetail` (`use` the trait), the two HTE blades.
- Tests: `tests/Hte/PasswordsTest.php` (sealed delivery opened with the computer's key and refused
  with another's, confirmation, spoofed uuid, books and their visibility through the client API,
  rotation on access loss and schedule, the UI).

## 1.14.0-hte.2 (2026-10-16) on upstream 1.14.0

- **Sessions close when access shrinks** (`app/Hte/ReachSweep.php`, `hte:reach-sweep`, every 15 s
  while per-person reach is on): an open session is closed through the console's own disconnect
  (the one Active Sessions uses; the controlled device closes it at its next check-in) when nobody
  signed in on the device that opened it may still reach the target: a grant or group membership
  removed, the device moved, the person disabled, logged out or signed out of the client. If any
  person signed in there may reach it, it stays. Sessions from the web client are left alone (the
  console cannot tell who is behind them). Each close is an alarm (type 103, "Session closed:
  access removed") and a system line in the console log. Hooks: `routes/console.php` (register +
  schedule), `app/Models/AlarmLog.php` (type 103).
- **Direct IP connections can be switched off from a Strategy**: `direct-server` added to
  `Strategy::OPTION_KEYS` and labelled "Accept direct IP connections" in the editor
  (`app/Livewire/StrategyList.php`). Upstream keeps network keys out of strategies because a wrong
  server address strands devices; this key only closes the target's own direct listener, so every
  session goes through the ID server. HTE's default strategy sets it off on every device, the Macs
  on stock RustDesk included.
- The image runs the server 1.1.1-hte.2 and sets `HTE_RELAY_GATE_PORT=21120`, so the relay pairs
  only sessions hbbs admitted.
- Tests: `tests/Hte/ReachTest.php` now 13 (the sweep, the strategy key, the schedule).

## 1.14.0-hte.1 (2026-10-16) on upstream 1.14.0

**Per-person reach, enforced by the ID server.** The console already decides which devices a
signed-in person sees; with the setting on, the HTE build of hbbs refuses a session to any device
outside that set. HTE code lives in `app/Hte/` and `resources/views/hte/`:

- `app/Hte/Reach.php`: the `scope` block added to `GET /api/server/policy` (per active user:
  `name`, `all` for administrators, else `devices` = `Device::visibleTo()`; `token_users` = sha256 of
  each live client token -> user id; disabled people and expired tokens drop out), the "who can
  reach this device" sections for the device page, the newest refusal, and the alarm rows
  (type 102, console source = administrators only) for refusals hbbs reports.
- `app/Hte/ScopeSettings.php`: Livewire trait for the Settings -> Server switch "Each person may
  reach only the devices they are given" (`hte_scope_enforce`, off by default) and its status line,
  which warns when the linked ID server is not the HTE build.
- `app/Hte/RefusalsController.php`: `POST /api/server/refusals` (shared-secret middleware), one
  alarm per refusal plus a security-alarm notification.
- `resources/views/hte/settings-scope.blade.php`, `resources/views/hte/device-reach.blade.php`.
- Tests: `tests/Hte/ReachTest.php` (Pest; `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php` are
  HTE's, upstream ships no tests). Run them with `docker/hte-test.Dockerfile`.

Hooks in upstream files, each marked `HTE`: `app/Services/ServerLink.php` (one key in the policy),
`routes/api.php` (one route), `app/Livewire/SettingsPage.php` (`use` the trait, `hteSave()` in
`save()`), the two blades (one `@include` each), `app/Models/AlarmLog.php` (type 102), `Dockerfile`
(the server stage comes from `ghcr.io/regendorf-ux/hte-cortendesk-server`), `.dockerignore`
(`!tests`), `VERSION`.

Build: `.github/workflows/hte-image.yml` (push a tag `v<upstream>-hte.<n>` or run it by hand): tests,
then `ghcr.io/regendorf-ux/hte-cortendesk:<upstream>-hte.<n>`, amd64.

## Rebuilding on a new upstream release

```
git fetch origin --tags                 # origin = marcpope/cortendesk
git checkout hte && git rebase v<new>   # resolve: the hooks above are small
# VERSION -> <new>-hte.1; Dockerfile SERVER_VERSION -> the HTE server tag; add a section here
docker build -f docker/hte-test.Dockerfile -t t . && docker run --rm t
git push -f hte hte && git tag v<new>-hte.1 && git push hte v<new>-hte.1
```
Rebuild the server image first when upstream's console pins a newer `cortendesk-server`.
