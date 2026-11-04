# Studio — DESIGN.md

> Archer Websites style `studio`. Groot, zelfzeker en grafisch: enorme titels, oranje accent, scherpe lijnen.  
> Generated from `includes/ui/styles/library.json` and `design-notes.json` (DESIGN.md format, 9 sections, after github.com/VoltAgent/awesome-claude-design). An original system, not modelled on any brand.

## 1. Visual Theme & Atmosphere

Big, confident and graphic. Huge headlines, hard lines and an orange accent. Feels like a design studio: bold, opinionated and fast.

- Mode: light
- Density: airy
- Photo direction: bold compositions, strong contrast, close-ups, graphic shapes, urban settings
- Voice: Confident and direct. Strong verbs, short punchy headlines, a clear opinion. Still concrete: no hype words.

## 2. Color Palette & Roles

| Role | Token | Hex | Use |
|---|---|---|---|
| Ground | `--a-base` | `#FAFAF9` | Page background; every section sits on it unless it is a band. |
| Surface | `--a-surface` | `#F0F0EE` | Cards, soft bands and form backgrounds. |
| Surface 2 | `--a-surface2` | `#E5E5E2` | Second surface for nested blocks and hover states. |
| Ink | `--a-ink` | `#0B0B0B` | Headings and body text. |
| Muted | `--a-muted` | `#5F5F5B` | Secondary text, captions, labels. |
| Accent | `--a-accent` | `#FF4F1A` | Primary buttons, the main call to action, key highlights. Use sparingly. |
| On accent | `--a-accent-ink` | `#0B0B0B` | Text and icons on the accent. |
| Line | `--a-line` | `#E0E0DC` | Hairlines, borders and dividers. |
| Dark band | `--a-dark` | `#0B0B0B` | Footer and dark bands. |
| On dark | `--a-dark-ink` | `#FAFAF9` | Text on dark bands. |

Contrast: ink on ground 18.8:1 · muted on ground 6.1:1 · on-accent on accent 6.0:1 (target 4.5:1 or more).  
The customer's own colours (from the logo or a photo) replace Accent, Dark band, Ink, Surface and Surface 2; the ground stays.

## 3. Typography Rules

- Headings: **Geist** 600, tracking -0.045em, line-height 0.98, case none
- Body: **Geist** 400, line-height 1.55
- Google Fonts only.

| Level | Size (360px → 1440px) | Token |
|---|---|---|
| Display / H1 | 56–132px | `--a-display` |
| H2 | 38–76px | `--a-h2` |
| H3 | 22–28px | `--a-h3` |
| Lead | 19–23px | `--a-lead` |
| Body | 16–18px | `--a-body` |
| Small | 13–14px | `--a-small` |

Sizes are fluid (clamp) between a 360px and a 1440px wide screen. Text blocks stay under 68 characters per line.

## 4. Component Stylings

- **Buttons**: 16px, weight 600, case none, tracking -0.01em, padding 1em 1.6em, min-height 44px, radius 6px (`--a-radius-button`). Primary: accent fill with on-accent text. A second button next to it is the quiet outline variant (ink border, transparent).
- **Press feedback**: scale 0.97 in 160ms (cubic-bezier(0.23, 1, 0.32, 1)); hover changes colour only (150ms).
- **Cards**: Hairline: transparent background with a 1px line border. Radius 12px (`--a-radius-card`).
- **Images**: radius 12px (`--a-radius-image`), object-fit cover. A photo that fills a whole section keeps white text over an overlay.
- **Fields**: radius 6px (`--a-radius-field`), 1px line border, ground background, muted labels.
- **Icons**: Iconoir (regular, 1.5 stroke), coloured with the text; one icon per item at most.
- **Header**: on the ground with ink text; the last menu item is a primary button.
- **Footer**: a dark band.
- **Bands**: call to action a dark band; quotes none. At most one band per page besides the footer.

Corner presets (step Style → Hoeken): sharp 0/0/0/0, soft 8/14/14/8, round 999/24/24/12 (button/card/image/field). This style's own preset: **soft**.

## 5. Layout Principles

- Container: 1320px max width, side padding 20–40px.
- Section spacing (top and bottom): 53–101px.
- Heading to content: 36–72px; between content blocks: 22px; grid gap: 16–24px.
- One idea per section: hook, explanation, proof, next step. No two sections on a page tell the same thing.

## 6. Depth & Elevation

- No shadows on anything that does not move (house rule). Depth comes from surfaces: ground → surface → surface 2.
- Only floating UI (menus, dialogs) may cast a shadow.
- Lines (`--a-line`) separate content where a surface would be too heavy.

## 7. Do's and Don'ts

**Do**
- Let one headline dominate each section
- Use hairline borders and a strict grid
- Use the orange accent sparingly, for the main action and one highlight
- Mix very large and small type for contrast

**Don't**
- Do not soften with rounded cards or pastel surfaces
- Do not centre long text blocks
- Do not use the accent for body text
- Do not add decorative icons to every item
- Do not invent proof (reviews, numbers, logos) or use stock marketing words.

## 8. Responsive Behavior

- Fluid type and spacing from 360px to 1440px; no fixed breakpoints for sizes.
- Under 768px: grids stack to one column, the header keeps the logo and the main button, the menu moves to the mobile toggle.
- Touch targets at least 44px; the phone number is a `tel:` link.
- Images keep their aspect ratio; sections never scroll horizontally.

## 9. Agent Prompt Guide

- *Build a page*: "Build a Studio page: big, confident and graphic. Use the tokens above, Geist for headings and Geist for text, the accent only for the main action."
- *Write copy*: "Confident and direct. Strong verbs, short punchy headlines, a clear opinion. Still concrete: no hype words. Write in the site's language."
- *Pick photos*: "Stock photos with bold compositions, strong contrast, close-ups, graphic shapes, urban settings."
- *Check*: "Contrast 4.5:1 or more, one H1 per page, buttons 44px high, no shadows on static elements, the rules in section 7."
