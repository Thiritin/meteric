---
name: meteric-docs-voice
description: Writing-voice rules for Meteric documentation. Use when writing or editing any page under docs/ so the prose reads like a developer wrote it, not an LLM. Covers banned words, sentence shape, and the "lead with code" structure.
---

# Meteric docs voice

Audience: working Laravel developers. They know Eloquent, migrations, service
providers, facades, and the container. Do not explain those.

## Structure

- Lead with the code. Show the call, then a line of explanation if it needs one.
- Each page answers "how do I do X" fast. Put the common case first.
- State defaults and gotchas directly where they matter.
- Verify every snippet against the source in `src/`. Do not invent methods,
  arguments, or columns.

## Tone

- Short declarative sentences. Second person, present tense.
- Vary sentence length so it reads like a person wrote it.
- It is fine to be a little dry. Assume competence.

## Banned words and phrases (AI tells)

Never use: delve, robust, seamless / seamlessly, leverage, comprehensive,
powerful, elegant, effortless / effortlessly, "simply" or "just" as filler,
"in the world of", "It's important to note", "It's worth noting",
"Whether you're X or Y", unlock, streamline, boost, game-changer,
"out of the box", promotional superlatives.

## Banned patterns

- Rule-of-three cadence ("fast, simple, and reliable").
- Em-dashes. Never use them, anywhere. Use a comma, colon, period, or parentheses.
  (In definition lists: `code`: description. In prose: a comma or a new sentence.)
- Sentences starting with "Plus," or "And here's the thing".
- Emoji in body copy.
- Marketing hype in intros. Say what it does, plainly.

## Before finalizing

Run the `humanizer` skill over the prose, or apply its checklist by hand, to
strip residual AI patterns.
