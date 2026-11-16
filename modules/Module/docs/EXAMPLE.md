# Module-specific docs

`docs/` is convention, not framework. Nothing in `core/` reads it.

Put documentation here when it is too long for a comment and too module-specific
for the top-level `zero/docs/`. `Notebook/docs/` and `Jackpack/docs/` are the
working examples.

A module-scoped `CLAUDE.md` at the module root (not in here) is loaded on demand
when working inside that module.

## data/

`data/` is convention, not framework. Nothing in `core/` reads it.

Static fixture data that ships with the module. Keep the payload clean — JSON has
no comment syntax, and the real examples (`Ttrpg/data/`, `Bingo/data/`) carry no
metadata keys. Document the shape here instead.
