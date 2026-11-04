# Noir — DESIGN.md

> Archer Websites style `noir`. Donker en exclusief: fijne schreef, champagne als accent, foto's groot.  
> Generated from `includes/ui/styles/library.json` and `design-notes.json` (DESIGN.md format, 9 sections, after github.com/VoltAgent/awesome-claude-design). An original system, not modelled on any brand.

## 1. Visual Theme & Atmosphere

Dark and exclusive. A fine serif, champagne accent and large photography on a near-black ground. Feels evening, intimate and premium.

- Mode: dark
- Density: airy
- Photo direction: moody low light, evening atmosphere, rich textures, close-up details, warm highlights
- Voice: Refined and evocative but still precise. Few words, sensory detail, an invitation rather than a sales pitch.

## 2. Color Palette & Roles

| Role | Token | Hex | Use |
|---|---|---|---|
| Ground | `--a-base` | `#0F0F0E` | Page background; every section sits on it unless it is a band. |
| Surface | `--a-surface` | `#181816` | Cards, soft bands and form backgrounds. |
| Surface 2 | `--a-surface2` | `#22221F` | Second surface for nested blocks and hover states. |
| Ink | `--a-ink` | `#EFEBE3` | Headings and body text. |
| Muted | `--a-muted` | `#A29C90` | Secondary text, captions, labels. |
| Accent | `--a-accent` | `#CDB389` | Primary buttons, the main call to action, key highlights. Use sparingly. |
| On accent | `--a-accent-ink` | `#0F0F0E` | Text and icons on the accent. |
| Line | `--a-line` | `#2C2B28` | Hairlines, borders and dividers. |
| Dark band | `--a-dark` | `#090908` | Footer and dark bands. |
| On dark | `--a-dark-ink` | `#EFEBE3` | Text on dark bands. |

Contrast: ink on ground 16.1:1 · muted on ground 7.0:1 · on-accent on accent 9.5:1 (target 4.5:1 or more).  
The customer's own colours (from the logo or a photo) replace Accent, Dark band, Ink, Surface and Surface 2; the ground stays.

## 3. Typography Rules

- Headings: **Cormorant Garamond** 500, tracking -0.015em, line-height 1.0, case none
- Body: **Jost** 400, line-height 1.65
- Google Fonts only.

| Level | Size (360px → 1440px) | Token |
|---|---|---|
| Display / H1 | 52–112px | `--a-display` |
| H2 | 36–66px | `--a-h2` |
| H3 | 24–30px | `--a-h3` |
| Lead | 18–22px | `--a-lead` |
| Body | 16–18px | `--a-body` |
| Small | 13–14px | `--a-small` |

Sizes are fluid (clamp) between a 360px and a 1440px wide screen. Text blocks stay under 68 characters per line.

## 4. Component Stylings

- **Buttons**: 13px, weight 500, case uppercase, tracking 0.12em, padding 1em 1.6em, min-height 44px, radius 0px (`--a-radius-button`). Primary: accent fill with on-accent text. A second button next to it is the quiet outline variant (ink border, transparent).
- **Press feedback**: scale 0.97 in 160ms (cubic-bezier(0.23, 1, 0.32, 1)); hover changes colour only (150ms).
- **Cards**: Hairline: transparent background with a 1px line border. Radius 0px (`--a-radius-card`).
- **Images**: radius 0px (`--a-radius-image`), object-fit cover. A photo that fills a whole section keeps white text over an overlay.
- **Fields**: radius 0px (`--a-radius-field`), 1px line border, ground background, muted labels.
- **Icons**: Iconoir (regular, 1.5 stroke), coloured with the text; one icon per item at most.
- **Header**: on the ground with ink text; the last menu item is a primary button.
- **Footer**: a soft field band.
- **Bands**: call to action on the ground (no band); quotes none. At most one band per page besides the footer.

Corner presets (step Style → Hoeken): sharp 0/0/0/0, soft 8/14/14/8, round 999/24/24/12 (button/card/image/field). This style's own preset: **sharp**.

## 5. Layout Principles

- Container: 1280px max width, side padding 20–40px.
- Section spacing (top and bottom): 62–110px.
- Heading to content: 36–72px; between content blocks: 24px; grid gap: 16–28px.
- One idea per section: hook, explanation, proof, next step. No two sections on a page tell the same thing.

## 6. Depth & Elevation

- No shadows on anything that does not move (house rule). Depth comes from surfaces: ground → surface → surface 2, and from photography on the dark ground.
- Only floating UI (menus, dialogs) may cast a shadow.
- Lines (`--a-line`) separate content where a surface would be too heavy.

## 7. Do's and Don'ts

**Do**
- Let large photography fill the space
- Use the champagne accent only for actions and fine details
- Use uppercase letter-spaced labels for buttons and small headings
- Keep generous spacing between sections

**Don't**
- Do not use bright colours or white sections
- Do not use small grey text on the dark ground
- Do not use rounded, playful shapes
- Do not use bright daylight stock photos
- Do not invent proof (reviews, numbers, logos) or use stock marketing words.

## 8. Responsive Behavior

- Fluid type and spacing from 360px to 1440px; no fixed breakpoints for sizes.
- Under 768px: grids stack to one column, the header keeps the logo and the main button, the menu moves to the mobile toggle.
- Touch targets at least 44px; the phone number is a `tel:` link.
- Images keep their aspect ratio; sections never scroll horizontally.

## 9. Agent Prompt Guide

- *Build a page*: "Build a Noir page: dark and exclusive. Use the tokens above, Cormorant Garamond for headings and Jost for text, the accent only for the main action."
- *Write copy*: "Refined and evocative but still precise. Few words, sensory detail, an invitation rather than a sales pitch. Write in the site's language."
- *Pick photos*: "Stock photos with moody low light, evening atmosphere, rich textures, close-up details, warm highlights."
- *Check*: "Contrast 4.5:1 or more, one H1 per page, buttons 44px high, no shadows on static elements, the rules in section 7."
