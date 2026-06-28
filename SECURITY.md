# Security Policy

## Supported versions

Security fixes land on the latest `2.x` release. Please upgrade to the most recent tag before reporting, in
case the issue is already fixed.

| Version | Supported |
| ------- | --------- |
| 2.x     | ✅        |
| < 2.0   | ❌        |

## Reporting a vulnerability

**Please do not open a public GitHub issue for a security vulnerability.**

Report it privately instead, so it can be fixed before it's disclosed:

- Preferred: open a [GitHub Security Advisory](https://github.com/ngouyoung/admin-core/security/advisories/new)
  (Security → Report a vulnerability), or
- Email **ngouyuong@gmail.com** with the details.

Please include:

- the affected version(s),
- a description of the issue and its impact (e.g. stored XSS, privilege escalation, SQL injection),
- steps to reproduce or a proof-of-concept, and
- any suggested fix.

## What to expect

- An acknowledgement within a few days.
- A fix released as a patch version, with credit to the reporter (unless you prefer to stay anonymous).
- For confirmed issues, a GitHub Security Advisory once a fixed release is available.

## Scope notes

This package generates admin CRUD code and ships front-end JS. When reporting, note whether the issue is in
the **package runtime** (controllers, services, middleware, the shipped Blade/JS) or in **generated code** —
generated code lives in the host application and is expected to be reviewed and edited there.
