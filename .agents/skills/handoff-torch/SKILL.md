---
name: handoff-torch
description: "Pass the torch: generate a handoff document so the current task can be continued in a fresh AI conversation (with any AI coding agent). Use when the user asks to create a handoff, hand off / pass the torch on the task, write a continuation/handover doc, prepare a context dump for a new chat/session, or says things like \"handoff doc\", \"pass the torch\", \"continue this in a new session\", \"write a handover\", or \"prepare a context dump for the next agent\". Writes a structured markdown file under docs/handoff/."
metadata:
  author: webreactiva.com
  namespace: webreactiva
---

# Handoff Generator — Pass the Torch

Produce a single self-contained markdown document that lets *you* (or any other AI coding agent) resume the current task in a brand-new conversation with zero prior context. The reader has none of this chat's memory — so the document, not the conversation, has to carry everything: what the task is, where the code lives, how to run and test it, what's left, and the traps already discovered.

Treat this as writing a letter to a competent stranger who will pick up exactly where you left off. If a fact only lives in this chat's scrollback, it must make it into the file.

## Output location and filename

Save to `docs/handoff/` (create the directory if missing). Filename format:

```
YYYYMMDD-HHMM-{slug}.handoff.md
```

- Timestamp: get the real local time with `date +"%Y%m%d-%H%M"`. Never invent it.
- `{slug}`: 3-4 meaningful words, kebab-case, **preferably English** (keeps filenames sortable and portable). Make it specific to the task — `team-subscriptions-handoff`, `checkout-bug-fix`, `search-index-migration` — not generic like `task` or `wip`.
- The `.handoff.md` double extension is intentional: it marks the file as a handoff while staying valid markdown.

## Language

Write the **document body in the same language the user has been using in this chat** — match the user. This skill file is in English, but that does not dictate the output language.

The YAML/section scaffolding stays consistent regardless of language; only the prose adapts.

## Traceability block (agent-agnostic session info)

Right after the title, include a small metadata block so the next agent can locate the exact point in history. It must work for **any** AI agent, not just Claude — so anchor it on git and the clock, which every environment shares. Gather the values with plain commands you already have:

```bash
date +"%Y-%m-%d %H:%M %Z"        # datetime
git rev-parse --abbrev-ref HEAD  # branch
git log -1 --pretty='%h — %s'    # HEAD: short hash + subject (the resume point)
git status --porcelain | wc -l   # uncommitted file count
```

Then write the block:

- **Date** — local datetime
- **Branch** — current git branch
- **HEAD** — short commit hash + subject (the precise resume point)
- **Session ID** — see below
- **Working tree** — clean, or N uncommitted files (and whether they're relevant to this task)

**Session ID, agent-agnostically.** The goal is that whatever AI agent runs this skill can drop in an identifier the user could use to find this conversation again. Different agents expose session identity differently, so:
1. If you (the running agent) know your own session/conversation id — from your environment, system context, or a variable your runtime exposes — use it and name the source (e.g. `Claude Code session abc123`, `Codex thread …`).
2. If you genuinely don't have one, fall back to a reproducible surrogate built from the data already in this file: `{YYYYMMDD-HHMM}@{HEAD-short-hash}`. It's unambiguous about *which working state* the handoff describes.
3. Never block on this — a missing native id is fine; the git anchor is what actually matters. State plainly which form you used so the reader isn't misled.

## Document structure

Mirror this section order. It's the shape that worked for the reference handoff at `references/example-handoff.md` — read that file to see a fully worked example before writing your own. Skip a section only when it genuinely doesn't apply (e.g. no routes in a CLI tool), and say so rather than padding.

1. **Title + traceability block** — `# Handoff — <Task name>` then the metadata bullets above.
2. **What this is** — 2-4 sentences: the goal, the guiding principle, and a pointer to any plan/spec/issue that holds deeper context.
3. **Where the code lives** — a table of the key pieces (component → file path → one-line note). This is the map; spend effort here. Group by layer if large (backend / frontend / config / tests).
4. **Routes / entrypoints / API** — concrete URLs, route names, commands, or public functions, with file:line where useful. Omit for tasks without an external surface.
5. **How to run / test** — exact commands, copy-pasteable, in the order a newcomer runs them (env/flags → migrations/setup → tests → lint/typecheck). Include test credentials, seeders, fixtures, and any external-service test IDs (Stripe test mode, sandbox keys — never real secrets).
6. **Test status** — what's green, what's red, counts, and which suite/filter produces them.
7. **Pending / next steps** — a numbered, prioritized list of what's left. Be specific enough to act on: not "finish frontend" but "wire the seat-adjust endpoint — controller exists at X, route missing in Y." Call out unverified assumptions.
8. **Gotchas learned** — the non-obvious traps this session uncovered (build quirks, cache clears, framework footguns, ordering constraints). These save the next agent the hours you already spent. This is often the highest-value section.
9. **Relevant commits** — recent commit hashes + subjects, what's committed vs. not, and whether anything was pushed.

## How to build it well

- **Mine the whole conversation, not just the last turn.** Decisions, rejected approaches, corrections the user made, and "we tried X, it broke because Y" are exactly what a fresh agent lacks. Surface them — especially in *Gotchas* and *Pending*.
- **Verify facts against the repo as you write.** Run `git status`, `git log --oneline -5`, and the relevant test command so counts, paths, and commit hashes are current — not remembered. A handoff with stale paths is worse than none.
- **Prefer file paths and commands over prose.** The reader will open files and run things; give them the exact targets. `app/Features/Teams/Services/TeamProvisioningService.php` beats "the provisioning service."
- **Be honest about uncertainty.** If something is untested or you're unsure it works, say so in *Pending*. False confidence is the most expensive thing to pass on.
- **Keep secrets out.** Test-mode IDs and public config are fine; real API keys, tokens, and passwords never go in a committed doc.

After writing, tell the user the path and give a one-line summary. Do not commit the file unless the user asks — leave that as their call.
