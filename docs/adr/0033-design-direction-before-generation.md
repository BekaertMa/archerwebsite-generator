# ADR-0033: Design direction before generation

- **Status:** Accepted
- **Date:** 2026-11-15
- **Deciders:** Matthias Bekaert
- **Amends:** ADR-0027 (what the AI gets as input), ADR-0030 (photo order)
- **Related:** ADR-0026, ADR-0032
- **Source:** the "Claude for Websites (Full Workflow)" method (Adrien Ninet, Nov 2026): brief → research → strategy → moodboard and style guide → sitemap → one context file → the agent asks designer questions → build → one-style images.

## Context

A site generated from the brief alone looks generic. The page structure, section choice and copy are each written by a separate AI call that only sees the brief, so every call makes its own guesses about positioning, the main action and what to stress. A designer settles those decisions before building. The method above does the same with research, a strategy, a style guide and one context file the agent reads before it builds.

Archer already covers part of it: the agentic intake is the brief, the Archer styles with their DESIGN.md and `design-notes.json` (theme, voice, photo direction, do's and don'ts) are the curated moodboard and style guide, Brixies is the component library. What is missing is research, a written strategy, one context bundle that every AI step reads, designer questions with a proposed answer, and one image look across the site.

The method takes about an hour with a designer. Archer's target is briefing to live site in under 5 minutes, for a client who is not a designer. So the steps run automatically, in the background where possible, and never block generation.

## Decision

### 1. Research runs in the background during the intake

- As soon as the brief has `business_name`, `activity` and `region`, the browser fires `aisb_archer_direction_research` once, without waiting for it. The client keeps answering questions.
- One call to the OpenAI Responses API with the `web_search` tool finds 3 to 4 local businesses with the same activity and reads what their websites claim.
- Output: peers (name, url, main claims), the claims they all make, and gaps (what none of them says clearly).
- The focus is **positioning**, not design. Local competitors' sites are rarely good design references; Archer's styles are.
- Measured on staging: about 30 s and about 35k input tokens per run with `gpt-5.4-mini`.

### 2. A strategy step turns brief + research into a direction

- Fired once from the browser when the intake reaches block 4 or 5 (main goal known and pages, tone or style being asked). If it is not ready when the client clicks "Maak mijn website", `aisb_archer_plan` builds it on the spot.
- Output (JSON, validated): positioning (one sentence), main promise, primary action, proof points from the brief only, 3–5 principles, what to avoid (including claims every competitor makes), the image look, and 0–3 designer questions.

### 3. Designer checkpoint: at most 3 questions, each with a proposal

- When the intake agent says it is done, the server asks the strategy's open questions before finishing, one per turn, without an extra AI call.
- Every question has a proposed answer as the first option and "Kies jij maar" (take the proposal). The client can always type their own answer.
- Questions only cover real trade-offs the brief does not settle (show prices or not, call or book online, lead with the price or with the craft). Tone: plain and short.
- The client can skip the whole checkpoint by clicking "Maak mijn website" earlier; the proposals are then used.

### 4. One design brief that every AI step reads (the AGENTS.md equivalent)

- Stored on the project as post meta `archer_direction` (JSON, `v: 1`): research, strategy, the client's checkpoint decisions, and a hash.
- `AISB_Archer_Direction::prompt_block($project_id, $task)` returns a compact block (at most about 1,800 characters) for one task: `plan`, `sections`, `copy`, `menu` or `photo`. Each task gets only the part it needs.
- The block also carries the style's do's and don'ts from `design-notes.json` for section planning. Theme and voice already go to the copy prompt through `AISB_Archer_Styles::prompt_notes()`.
- The direction never overrides the brief, the section rules or the "never invent facts" rule. It decides emphasis, not facts.

### 5. One image look across the site

- The strategy defines one look (light, setting, framing, colour) in English. Every stock search on the site adds it, so photos from different photographers look like one shoot.
- **AI-generated images are a fallback, off by default** (setting `archer_ai_imagery`). When on, and stock search returns fewer photos than a page needs, the gap is filled with generated images in the same look and the site's accent colour.
- Generated images show **objects, materials, details, textures or spaces only**. Never people, never a specific shop, product or team presented as the client's own, never text or logos. For a local business, a fake "real" photo is misleading.
- Generated images go into the media library with alt text and the source "AI (Archer)", so they can be told apart and replaced.
- Order for photos (amends ADR-0030): client photos → stock photos in the site look → generated images (when switched on).

## What we do not adopt

| Step in the method | Why not |
|---|---|
| Free-form moodboard per client (Figma import) | Breaks the 5-minute goal and the Brixies constraint. The curated Archer styles are the moodboard. A Figma import may stay a staff option. |
| Design skill for product UI (Stageshow) | Built for SaaS pages (bento grids, cursor demos), not for bakers or physios. The style rules do this job. |
| React component library (Arc) | Brixies is the component library. |
| Animated vector logo, green-screen video | Not for phase 1. |

## Consequences

- Two extra AI calls per site (research and strategy), both mostly hidden behind the intake. Extra cost per site: a few cents. The checkpoint adds at most 3 short turns.
- Research depends on the provider's web search. It can fail or find nothing. Then the strategy uses the brief only, and generation never waits for it.
- Prompts get longer. The block per task is kept short and only carries what that task needs.
- Generated images cost money per image and carry provider terms for commercial use. They stay off until those terms and the cost per site are checked.
- ADR-0032 (model routing with Claude and OpenAI) is not implemented yet. The new calls use OpenAI through filters (`archer_ai_research_model`, `archer_ai_strategy_model`, `archer_ai_imagery_model`) so they can move to the shared model layer later.

## Measure

Run the fixture briefs with and without the direction and compare: how often the hero states the positioning, whether the primary action is the same on every page, editing time on the copy, and how many photos get swapped by hand.
