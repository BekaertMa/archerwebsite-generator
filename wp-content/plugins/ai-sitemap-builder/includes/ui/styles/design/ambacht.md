# Ambacht — DESIGN.md

> Archer Websites style `ambacht`. Warm en eerlijk voor vakmensen: stevige letter, warme grond, diepblauw accent.  
> Generated from `includes/ui/styles/library.json` and `design-notes.json` (DESIGN.md format, 9 sections, after github.com/VoltAgent/awesome-claude-design). An original system, not modelled on any brand.

## 1. Visual Theme & Atmosphere

Warm and honest, made for craftspeople. A sturdy grotesque, a warm ground and a deep blue accent. Feels reliable, local and hands-on.

- Mode: light
- Density: comfortable
- Photo direction: craftsmen at work, hands and tools, real materials, warm light, finished projects
- Voice: Warm, plain-spoken and practical, like the owner explaining the job. Mention the work, the materials and the region.

## 2. Color Palette & Roles

| Role | Token | Hex | Use |
|---|---|---|---|
| Ground | `--a-base` | `#FAF8F3` | Page background; every section sits on it unless it is a band. |
| Surface | `--a-surface` | `#F1ECE2` | Cards, soft bands and form backgrounds. |
| Surface 2 | `--a-surface2` | `#E7E0D2` | Second surface for nested blocks and hover states. |
| Ink | `--a-ink` | `#211E19` | Headings and body text. |
| Muted | `--a-muted` | `#675F54` | Secondary text, captions, labels. |
| Accent | `--a-accent` | `#1F3A5F` | Primary buttons, the main call to action, key highlights. Use sparingly. |
| On accent | `--a-accent-ink` | `#FFFFFF` | Text and icons on the accent. |
| Line | `--a-line` | `#E3DCCF` | Hairlines, borders and dividers. |
| Dark band | `--a-dark` | `#1B2A3D` | Footer and dark bands. |
| On dark | `--a-dark-ink` | `#F3F0E9` | Text on dark bands. |

Contrast: ink on ground 15.6:1 · muted on ground 5.9:1 · on-accent on accent 11.5:1 (target 4.5:1 or more).  
The customer's own colours (from the logo or a photo) replace Accent, Dark band, Ink, Surface and Surface 2; the ground stays.

## 3. Typography Rules

- Headings: **Bricolage Grotesque** 700, tracking -0.03em, line-height 1.02, case none
- Body: **DM Sans** 400, line-height 1.6
- Google Fonts only.

| Level | Size (360px → 1440px) | Token |
|---|---|---|
| Display / H1 | 46–94px | `--a-display` |
| H2 | 34–58px | `--a-h2` |
| H3 | 21–27px | `--a-h3` |
| Lead | 18–22px | `--a-lead` |
| Body | 17–18px | `--a-body` |
| Small | 14–15px | `--a-small` |

Sizes are fluid (clamp) between a 360px and a 1440px wide screen. Text blocks stay under 68 characters per line.

## 4. Component Stylings

- **Buttons**: 16px, weight 600, case none, tracking 0, padding 1em 1.6em, min-height 44px, radius 999px (`--a-radius-button`). Primary: accent fill with on-accent text. A second button next to it is the quiet outline variant (ink border, transparent).
- **Press feedback**: scale 0.97 in 160ms (cubic-bezier(0.23, 1, 0.32, 1)); hover changes colour only (150ms).
- **Cards**: Flat: surface background, no border, no shadow. Radius 20px (`--a-radius-card`).
- **Images**: radius 20px (`--a-radius-image`), object-fit cover. A photo that fills a whole section keeps white text over an overlay.
- **Fields**: radius 12px (`--a-radius-field`), 1px line border, ground background, muted labels.
- **Icons**: Iconoir (regular, 1.5 stroke), coloured with the text; one icon per item at most.
- **Header**: on the ground with ink text; the last menu item is a primary button.
- **Footer**: a soft field band.
- **Bands**: call to action a soft field band; quotes none. At most one band per page besides the footer.

Corner presets (step Style → Hoeken): sharp 0/0/0/0, soft 8/14/14/8, round 999/24/24/12 (button/card/image/field). This style's own preset: **round**.

## 5. Layout Principles

- Container: 1240px max width, side padding 20–40px.
- Section spacing (top and bottom): 53–92px.
- Heading to content: 30–60px; between content blocks: 22px; grid gap: 20–32px.
- One idea per section: hook, explanation, proof, next step. No two sections on a page tell the same thing.

## 6. Depth & Elevation

- No shadows on anything that does not move (house rule). Depth comes from surfaces: ground → surface → surface 2.
- Only floating UI (menus, dialogs) may cast a shadow.
- Lines (`--a-line`) separate content where a surface would be too heavy.

## 7. Do's and Don'ts

**Do**
- Show real work and real people as much as possible
- Use the warm surface for cards and the blue for actions
- Put contact details and the main call to action within easy reach
- Use rounded, friendly shapes for buttons

**Don't**
- Do not use cold greys or a dark theme
- Do not use abstract stock imagery
- Do not use thin, light fonts for headings
- Do not hide the phone number
- Do not invent proof (reviews, numbers, logos) or use stock marketing words.

## 8. Responsive Behavior

- Fluid type and spacing from 360px to 1440px; no fixed breakpoints for sizes.
- Under 768px: grids stack to one column, the header keeps the logo and the main button, the menu moves to the mobile toggle.
- Touch targets at least 44px; the phone number is a `tel:` link.
- Images keep their aspect ratio; sections never scroll horizontally.

## 9. Agent Prompt Guide

- *Build a page*: "Build a Ambacht page: warm and honest, made for craftspeople. Use the tokens above, Bricolage Grotesque for headings and DM Sans for text, the accent only for the main action."
- *Write copy*: "Warm, plain-spoken and practical, like the owner explaining the job. Mention the work, the materials and the region. Write in the site's language."
- *Pick photos*: "Stock photos with craftsmen at work, hands and tools, real materials, warm light, finished projects."
- *Check*: "Contrast 4.5:1 or more, one H1 per page, buttons 44px high, no shadows on static elements, the rules in section 7."
