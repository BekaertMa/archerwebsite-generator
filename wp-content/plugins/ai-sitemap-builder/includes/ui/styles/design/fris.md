# Fris — DESIGN.md

> Archer Websites style `fris`. Vriendelijk en modern voor lokale diensten: ronde letter, wit, kobaltblauw.  
> Generated from `includes/ui/styles/library.json` and `design-notes.json` (DESIGN.md format, 9 sections, after github.com/VoltAgent/awesome-claude-design). An original system, not modelled on any brand.

## 1. Visual Theme & Atmosphere

Friendly and modern for local services. A rounded sans, white ground and cobalt blue. Feels approachable, quick and easy to contact.

- Mode: light
- Density: comfortable
- Photo direction: bright daylight, friendly people, service at home, clean modern spaces
- Voice: Friendly, direct and helpful. Say what you do, where and how to reach you; invite the reader to call or ask a quote.

## 2. Color Palette & Roles

| Role | Token | Hex | Use |
|---|---|---|---|
| Ground | `--a-base` | `#FFFFFF` | Page background; every section sits on it unless it is a band. |
| Surface | `--a-surface` | `#F2F5FD` | Cards, soft bands and form backgrounds. |
| Surface 2 | `--a-surface2` | `#E6ECFB` | Second surface for nested blocks and hover states. |
| Ink | `--a-ink` | `#0D1530` | Headings and body text. |
| Muted | `--a-muted` | `#545D76` | Secondary text, captions, labels. |
| Accent | `--a-accent` | `#2152E8` | Primary buttons, the main call to action, key highlights. Use sparingly. |
| On accent | `--a-accent-ink` | `#FFFFFF` | Text and icons on the accent. |
| Line | `--a-line` | `#DEE4F3` | Hairlines, borders and dividers. |
| Dark band | `--a-dark` | `#0D1530` | Footer and dark bands. |
| On dark | `--a-dark-ink` | `#EEF2FC` | Text on dark bands. |

Contrast: ink on ground 18.0:1 · muted on ground 6.6:1 · on-accent on accent 6.1:1 (target 4.5:1 or more).  
The customer's own colours (from the logo or a photo) replace Accent, Dark band, Ink, Surface and Surface 2; the ground stays.

## 3. Typography Rules

- Headings: **Plus Jakarta Sans** 700, tracking -0.03em, line-height 1.05, case none
- Body: **Plus Jakarta Sans** 400, line-height 1.6
- Google Fonts only.

| Level | Size (360px → 1440px) | Token |
|---|---|---|
| Display / H1 | 42–82px | `--a-display` |
| H2 | 30–52px | `--a-h2` |
| H3 | 20–25px | `--a-h3` |
| Lead | 18–21px | `--a-lead` |
| Body | 16–18px | `--a-body` |
| Small | 14–15px | `--a-small` |

Sizes are fluid (clamp) between a 360px and a 1440px wide screen. Text blocks stay under 68 characters per line.

## 4. Component Stylings

- **Buttons**: 16px, weight 600, case none, tracking 0, padding 1em 1.6em, min-height 44px, radius 999px (`--a-radius-button`). Primary: accent fill with on-accent text. A second button next to it is the quiet outline variant (ink border, transparent).
- **Press feedback**: scale 0.97 in 160ms (cubic-bezier(0.23, 1, 0.32, 1)); hover changes colour only (150ms).
- **Cards**: Flat: surface background, no border, no shadow. Radius 18px (`--a-radius-card`).
- **Images**: radius 18px (`--a-radius-image`), object-fit cover. A photo that fills a whole section keeps white text over an overlay.
- **Fields**: radius 12px (`--a-radius-field`), 1px line border, ground background, muted labels.
- **Icons**: Iconoir (regular, 1.5 stroke), coloured with the text; one icon per item at most.
- **Header**: on the ground with ink text; the last menu item is a primary button.
- **Footer**: a dark band.
- **Bands**: call to action an accent band; quotes on the ground (no band). At most one band per page besides the footer.

Corner presets (step Style → Hoeken): sharp 0/0/0/0, soft 8/14/14/8, round 999/24/24/12 (button/card/image/field). This style's own preset: **round**.

## 5. Layout Principles

- Container: 1240px max width, side padding 20–40px.
- Section spacing (top and bottom): 48–84px.
- Heading to content: 30–54px; between content blocks: 20px; grid gap: 20–28px.
- One idea per section: hook, explanation, proof, next step. No two sections on a page tell the same thing.

## 6. Depth & Elevation

- No shadows on anything that does not move (house rule). Depth comes from surfaces: ground → surface → surface 2.
- Only floating UI (menus, dialogs) may cast a shadow.
- Lines (`--a-line`) separate content where a surface would be too heavy.

## 7. Do's and Don'ts

**Do**
- Make the main action (call, quote, booking) visible in the header and hero
- Use flat light cards to group services
- Keep icons simple and consistent
- Use rounded buttons with a clear label

**Don't**
- Do not use dark themes or heavy imagery
- Do not use serif headlines
- Do not use long paragraphs
- Do not use more than one accent colour
- Do not invent proof (reviews, numbers, logos) or use stock marketing words.

## 8. Responsive Behavior

- Fluid type and spacing from 360px to 1440px; no fixed breakpoints for sizes.
- Under 768px: grids stack to one column, the header keeps the logo and the main button, the menu moves to the mobile toggle.
- Touch targets at least 44px; the phone number is a `tel:` link.
- Images keep their aspect ratio; sections never scroll horizontally.

## 9. Agent Prompt Guide

- *Build a page*: "Build a Fris page: friendly and modern for local services. Use the tokens above, Plus Jakarta Sans for headings and Plus Jakarta Sans for text, the accent only for the main action."
- *Write copy*: "Friendly, direct and helpful. Say what you do, where and how to reach you; invite the reader to call or ask a quote. Write in the site's language."
- *Pick photos*: "Stock photos with bright daylight, friendly people, service at home, clean modern spaces."
- *Check*: "Contrast 4.5:1 or more, one H1 per page, buttons 44px high, no shadows on static elements, the rules in section 7."
