# Atelier — DESIGN.md

> Archer Websites style `atelier`. Redactioneel en rustig: grote schreeftitels, veel wit, zwart als accent.  
> Generated from `includes/ui/styles/library.json` and `design-notes.json` (DESIGN.md format, 9 sections, after github.com/VoltAgent/awesome-claude-design). An original system, not modelled on any brand.

## 1. Visual Theme & Atmosphere

Editorial and calm, like a well-made print magazine. Big serif headlines carry the page; the rest steps back. Lots of white space, black as the only accent, thin rules instead of boxes. Feels considered and quietly premium.

- Mode: light
- Density: airy
- Photo direction: natural light, quiet interiors, materials and details, few people, muted colours
- Voice: Calm, precise and understated. Short sentences, concrete nouns, no exclamation marks. Let the work speak; never oversell.

## 2. Color Palette & Roles

| Role | Token | Hex | Use |
|---|---|---|---|
| Ground | `--a-base` | `#F7F6F2` | Page background; every section sits on it unless it is a band. |
| Surface | `--a-surface` | `#EEECE6` | Cards, soft bands and form backgrounds. |
| Surface 2 | `--a-surface2` | `#E4E1D9` | Second surface for nested blocks and hover states. |
| Ink | `--a-ink` | `#191917` | Headings and body text. |
| Muted | `--a-muted` | `#66645D` | Secondary text, captions, labels. |
| Accent | `--a-accent` | `#191917` | Primary buttons, the main call to action, key highlights. Use sparingly. |
| On accent | `--a-accent-ink` | `#F7F6F2` | Text and icons on the accent. |
| Line | `--a-line` | `#DAD7CE` | Hairlines, borders and dividers. |
| Dark band | `--a-dark` | `#191917` | Footer and dark bands. |
| On dark | `--a-dark-ink` | `#F2F1EC` | Text on dark bands. |

Contrast: ink on ground 16.3:1 · muted on ground 5.5:1 · on-accent on accent 16.3:1 (target 4.5:1 or more).  
The customer's own colours (from the logo or a photo) replace Accent, Dark band, Ink, Surface and Surface 2; the ground stays.

## 3. Typography Rules

- Headings: **Instrument Serif** 400, tracking -0.01em, line-height 1.02, case none
- Body: **Geist** 400, line-height 1.6
- Google Fonts only.

| Level | Size (360px → 1440px) | Token |
|---|---|---|
| Display / H1 | 56–116px | `--a-display` |
| H2 | 38–68px | `--a-h2` |
| H3 | 24–32px | `--a-h3` |
| Lead | 19–23px | `--a-lead` |
| Body | 17–19px | `--a-body` |
| Small | 14–15px | `--a-small` |

Sizes are fluid (clamp) between a 360px and a 1440px wide screen. Text blocks stay under 68 characters per line.

## 4. Component Stylings

- **Buttons**: 15px, weight 500, case none, tracking 0, padding 1em 1.6em, min-height 44px, radius 999px (`--a-radius-button`). Primary: accent fill with on-accent text. A second button next to it is the quiet outline variant (ink border, transparent).
- **Press feedback**: scale 0.97 in 160ms (cubic-bezier(0.23, 1, 0.32, 1)); hover changes colour only (150ms).
- **Cards**: None: no card chrome; content separated by space and hairlines only. Radius 0px (`--a-radius-card`).
- **Images**: radius 2px (`--a-radius-image`), object-fit cover. A photo that fills a whole section keeps white text over an overlay.
- **Fields**: radius 0px (`--a-radius-field`), 1px line border, ground background, muted labels.
- **Icons**: Iconoir (regular, 1.5 stroke), coloured with the text; one icon per item at most.
- **Header**: on the ground with ink text; the last menu item is a primary button.
- **Footer**: a dark band.
- **Bands**: call to action on the ground (no band); quotes none. At most one band per page besides the footer.

Corner presets (step Style → Hoeken): sharp 0/0/0/0, soft 8/14/14/8, round 999/24/24/12 (button/card/image/field). This style's own preset: **round**.

## 5. Layout Principles

- Container: 1240px max width, side padding 20–40px.
- Section spacing (top and bottom): 62–110px.
- Heading to content: 36–72px; between content blocks: 24px; grid gap: 24–40px.
- One idea per section: hook, explanation, proof, next step. No two sections on a page tell the same thing.

## 6. Depth & Elevation

- No shadows on anything that does not move (house rule). Depth comes from surfaces: ground → surface → surface 2.
- Only floating UI (menus, dialogs) may cast a shadow.
- Lines (`--a-line`) separate content where a surface would be too heavy.

## 7. Do's and Don'ts

**Do**
- Give one big serif headline per section room to breathe
- Use thin lines (hairlines) and whitespace to separate content
- Keep body text short and set in the sans
- Show large, calm photography with generous margins

**Don't**
- Do not add coloured accents next to the black
- Do not box content in filled cards
- Do not use more than one serif size per section
- Do not crowd the page with icons or badges
- Do not invent proof (reviews, numbers, logos) or use stock marketing words.

## 8. Responsive Behavior

- Fluid type and spacing from 360px to 1440px; no fixed breakpoints for sizes.
- Under 768px: grids stack to one column, the header keeps the logo and the main button, the menu moves to the mobile toggle.
- Touch targets at least 44px; the phone number is a `tel:` link.
- Images keep their aspect ratio; sections never scroll horizontally.

## 9. Agent Prompt Guide

- *Build a page*: "Build a Atelier page: editorial and calm, like a well-made print magazine. Use the tokens above, Instrument Serif for headings and Geist for text, the accent only for the main action."
- *Write copy*: "Calm, precise and understated. Short sentences, concrete nouns, no exclamation marks. Let the work speak; never oversell. Write in the site's language."
- *Pick photos*: "Stock photos with natural light, quiet interiors, materials and details, few people, muted colours."
- *Check*: "Contrast 4.5:1 or more, one H1 per page, buttons 44px high, no shadows on static elements, the rules in section 7."
