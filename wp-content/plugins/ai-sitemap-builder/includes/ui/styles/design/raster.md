# Raster — DESIGN.md

> Archer Websites style `raster`. Zwitsers en technisch: wit, zwart en één rode kleur, alles op een strak raster.  
> Generated from `includes/ui/styles/library.json` and `design-notes.json` (DESIGN.md format, 9 sections, after github.com/VoltAgent/awesome-claude-design). An original system, not modelled on any brand.

## 1. Visual Theme & Atmosphere

Swiss and technical. White, black and a single red, everything on a strict grid with hairlines. Feels precise, engineered and no-nonsense.

- Mode: light
- Density: comfortable
- Photo direction: architectural lines, technical details, clean product shots, strong geometry
- Voice: Factual and exact. Numbers, specifications and clear claims; no adjectives that cannot be measured.

## 2. Color Palette & Roles

| Role | Token | Hex | Use |
|---|---|---|---|
| Ground | `--a-base` | `#FFFFFF` | Page background; every section sits on it unless it is a band. |
| Surface | `--a-surface` | `#F4F4F4` | Cards, soft bands and form backgrounds. |
| Surface 2 | `--a-surface2` | `#EAEAEA` | Second surface for nested blocks and hover states. |
| Ink | `--a-ink` | `#000000` | Headings and body text. |
| Muted | `--a-muted` | `#555555` | Secondary text, captions, labels. |
| Accent | `--a-accent` | `#D21F14` | Primary buttons, the main call to action, key highlights. Use sparingly. |
| On accent | `--a-accent-ink` | `#FFFFFF` | Text and icons on the accent. |
| Line | `--a-line` | `#DADADA` | Hairlines, borders and dividers. |
| Dark band | `--a-dark` | `#000000` | Footer and dark bands. |
| On dark | `--a-dark-ink` | `#FFFFFF` | Text on dark bands. |

Contrast: ink on ground 21.0:1 · muted on ground 7.5:1 · on-accent on accent 5.3:1 (target 4.5:1 or more).  
The customer's own colours (from the logo or a photo) replace Accent, Dark band, Ink, Surface and Surface 2; the ground stays.

## 3. Typography Rules

- Headings: **Archivo** 700, tracking -0.04em, line-height 1.0, case none
- Body: **Archivo** 400, line-height 1.55
- Google Fonts only.

| Level | Size (360px → 1440px) | Token |
|---|---|---|
| Display / H1 | 48–108px | `--a-display` |
| H2 | 34–62px | `--a-h2` |
| H3 | 20–26px | `--a-h3` |
| Lead | 18–22px | `--a-lead` |
| Body | 16–18px | `--a-body` |
| Small | 13–14px | `--a-small` |

Sizes are fluid (clamp) between a 360px and a 1440px wide screen. Text blocks stay under 68 characters per line.

## 4. Component Stylings

- **Buttons**: 16px, weight 600, case none, tracking 0, padding 1em 1.6em, min-height 44px, radius 0px (`--a-radius-button`). Primary: accent fill with on-accent text. A second button next to it is the quiet outline variant (ink border, transparent).
- **Press feedback**: scale 0.97 in 160ms (cubic-bezier(0.23, 1, 0.32, 1)); hover changes colour only (150ms).
- **Cards**: Hairline: transparent background with a 1px line border. Radius 0px (`--a-radius-card`).
- **Images**: radius 0px (`--a-radius-image`), object-fit cover. A photo that fills a whole section keeps white text over an overlay.
- **Fields**: radius 0px (`--a-radius-field`), 1px line border, ground background, muted labels.
- **Icons**: Iconoir (regular, 1.5 stroke), coloured with the text; one icon per item at most.
- **Header**: on the ground with ink text; the last menu item is a primary button.
- **Footer**: a dark band.
- **Bands**: call to action an accent band; quotes none. At most one band per page besides the footer.

Corner presets (step Style → Hoeken): sharp 0/0/0/0, soft 8/14/14/8, round 999/24/24/12 (button/card/image/field). This style's own preset: **sharp**.

## 5. Layout Principles

- Container: 1320px max width, side padding 20–40px.
- Section spacing (top and bottom): 53–97px.
- Heading to content: 30–66px; between content blocks: 20px; grid gap: 0px.
- One idea per section: hook, explanation, proof, next step. No two sections on a page tell the same thing.

## 6. Depth & Elevation

- No shadows on anything that does not move (house rule). Depth comes from surfaces: ground → surface → surface 2.
- Only floating UI (menus, dialogs) may cast a shadow.
- Lines (`--a-line`) separate content where a surface would be too heavy.

## 7. Do's and Don'ts

**Do**
- Put every element on the grid, aligned to columns
- Use hairlines to divide content
- Use the red only for the main action and key numbers
- Use square corners everywhere

**Don't**
- Do not round corners or use soft shadows
- Do not add a second accent colour
- Do not centre text
- Do not use decorative illustrations
- Do not invent proof (reviews, numbers, logos) or use stock marketing words.

## 8. Responsive Behavior

- Fluid type and spacing from 360px to 1440px; no fixed breakpoints for sizes.
- Under 768px: grids stack to one column, the header keeps the logo and the main button, the menu moves to the mobile toggle.
- Touch targets at least 44px; the phone number is a `tel:` link.
- Images keep their aspect ratio; sections never scroll horizontally.

## 9. Agent Prompt Guide

- *Build a page*: "Build a Raster page: swiss and technical. Use the tokens above, Archivo for headings and Archivo for text, the accent only for the main action."
- *Write copy*: "Factual and exact. Numbers, specifications and clear claims; no adjectives that cannot be measured. Write in the site's language."
- *Pick photos*: "Stock photos with architectural lines, technical details, clean product shots, strong geometry."
- *Check*: "Contrast 4.5:1 or more, one H1 per page, buttons 44px high, no shadows on static elements, the rules in section 7."
