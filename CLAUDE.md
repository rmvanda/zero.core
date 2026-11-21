# CLAUDE.md — zero.core

This repository is the framework. It is a submodule of each site at `core/`, and
`entity/` is a submodule of it.

## Scope when working here

Your only concern is this repository (and `entity/` when the work reaches into it).

- Commit here. Never commit in the site repository that holds `core/`.
- Never move the site's `core` submodule pointer, and do not report it as outstanding.
  The owner handles the pointer.
- Uncommitted work in the site repository is not yours to stage, tidy or mention.
