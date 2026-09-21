# Security Policy

## Reporting a Vulnerability

If you discover a security issue in MixpostMCP, please report it privately rather than opening a
public issue. Use GitHub's [private vulnerability reporting](../../security/advisories/new) on this
repository.

If the issue also affects upstream [Mixpost](https://github.com/inovector/mixpost) — that is, it is
not in code this fork added — please also report it to the upstream maintainers at
dima@inovector.com so it can be fixed for everyone.

## The desktop app

The desktop app opens no network port to other machines: its built-in PHP server listens only on the
local loopback address (`127.0.0.1`), and it rejects requests that do not carry a secret generated
fresh at each launch and known only to the app. There is no login screen because the security
boundary is the operating-system user account — anyone who can sign in to your computer as you can
open the app. Saved network tokens are encrypted with a key that is unique to each install: it is
generated on first launch, kept in the app-data folder (`storage/app/app.key`), and never ships in
the installer.
