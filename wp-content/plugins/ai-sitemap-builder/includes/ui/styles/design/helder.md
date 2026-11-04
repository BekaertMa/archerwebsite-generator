# Helder — DESIGN.md

> Archer Websites style `helder`. Vertrouwd en toegankelijk: ronde vormen, zachte vlakken, petrol als accent.  
> Generated from `includes/ui/styles/library.json` and `design-notes.json` (DESIGN.md format, 9 sections, after github.com/VoltAgent/awesome-claude-design). An original system, not modelled on any brand.

## 1. Visual Theme & Atmosphere

Trusted and accessible. Rounded shapes, soft flat surfaces and a petrol accent. Feels caring and calm, easy for everyone to use.

- Mode: light
- Density: comfortable
- Photo direction: warm caring moments, people talking, bright welcoming spaces, soft colours
- Voice: Reassuring and simple. Short sentences, no jargon, speak to the reader directly and explain the next step.

## 2. Color Palette & Roles

| Role | Token | Hex | Use |
|---|---|---|---|
| Ground | `--a-base` | `#FFFFFF` | Page background; every section sits on it unless it is a band. |
| Surface | `--a-surface` | `#F1F5F7` | Cards, soft bands and form backgrounds. |
| Surface 2 | `--a-surface2` | `#E5EDF1` | Second surface for nested blocks and hover states. |
| Ink | `--a-ink` | `#0E1A24` | Headings and body text. |
| Muted | `--a-muted` | `#526171` | Secondary text, captions, labels. |
| Accent | `--a-accent` | `#0E6E72` | Primary buttons, the main call to action, key highlights. Use sparingly. |
| On accent | `--a-accent-ink` | `#FFFFFF` | Text and icons on the accent. |
| Line | `--a-line` | `#DCE5EA` | Hairlines, borders and dividers. |
| Dark band | `--a-dark` | `#0E1A24` | Footer and dark bands. |
| On dark | `--a-dark-ink` | `#EDF3F6` | Text on dark bands. |

Contrast: ink on ground 17.6:1 · muted on ground 6.3:1 · on-accent on accent 6.0:1 (target 4.5:1 or more).  
The customer's own colours (from the logo or a photo) replace Accent, Dark band, Ink, Surface and Surface 2; the ground stays.

## 3. Typography Rules

- Headings: **Manrope** 700, tracking -0.025em, line-height 1.06, case none
- Body: **Manrope** 400, line-height 1.65
- Google Fonts only.

| Level | Size (360px → 1440px) | Token |
|---|---|---|
| Display / H1 | 42–78px | `--a-display` |
| H2 | 30–50px | `--a-h2` |
| H3 | 20–25px | `--a-h3` |
| Lead | 18–21px | `--a-lead` |
| Body | 16–18px | `--a-body` |
| Small | 14–15px | `--a-small` |

Sizes are fluid (clamp) between a 360px and a 1440px wide screen. Text blocks stay under 68 characters per line.

## 4. Component Stylings

- **Buttons**: 16px, weight 600, case none, tracking 0, padding 1em 1.6em, min-height 44px, radius 12px (`--a-radius-button`). Primary: accent fill with on-accent text. A second button next to it is the quiet outline variant (ink border, transparent).
- **Press feedback**: scale 0.97 in 160ms (cubic-bezier(0.23, 1, 0.32, 1)); hover changes colour only (150ms).
- **Cards**: Flat: surface background, no border, no shadow. Radius 20px (`--a-radius-card`).
- **Images**: radius 24px (`--a-radius-image`), object-fit cover. A photo that fills a whole section keeps white text over an overlay.
- **Fields**: radius 12px (`--a-radius-field`), 1px line border, ground background, muted labels.
- **Icons**: Iconoir (regular, 1.5 stroke), coloured with the text; one icon per item at most.
- **Header**: on the ground with ink text; the last menu item is a primary button.
- **Footer**: a dark band.
- **Bands**: call to action on the ground (no band); quotes on the ground (no band). At most one band per page besides the footer.

Corner presets (step Style → Hoeken): sharp 0/0/0/0, soft 8/14/14/8, round 999/24/24/12 (button/card/image/field). This style's own preset: **soft**.

## 5. Layout Principles

- Container: 1200px max width, side padding 20–40px.
- Section spacing (top and bottom): 48–84px.
- Heading to content: 30–54px; between content blocks: 20px; grid gap: 20–32px.
- One idea per section: hook, explanation, proof, next step. No two sections on a page tell the same thing.

## 6. Depth & Elevation

- No shadows on anything that does not move (house rule). Depth comes from surfaces: ground → surface → surface 2.
- Only floating UI (menus, dialogs) may cast a shadow.
- Lines (`--a-line`) separate content where a surface would be too heavy.

## 7. Do's and Don'ts

**Do**
- Keep text large enough and contrast high
- Use soft surfaces to group related content
- Make the next step obvious on every page
- Use rounded corners consistently

**Don't**
- Do not use small or light grey text
- Do not use dark or loud sections
- Do not use sharp, aggressive shapes
- Do not stack several calls to action next to each other
- Do not invent proof (reviews, numbers, logos) or use stock marketing words.

## 8. Responsive Behavior

- Fluid type and spacing from 360px to 1440px; no fixed breakpoints for sizes.
- Under 768px: grids stack to one column, the header keeps the logo and the main button, the menu moves to the mobile toggle.
- Touch targets at least 44px; the phone number is a `tel:` link.
- Images keep their aspect ratio; sections never scroll horizontally.

## 9. Agent Prompt Guide

- *Build a page*: "Build a Helder page: trusted and accessible. Use the tokens above, Manrope for headings and Manrope for text, the accent only for the main action."
- *Write copy*: "Reassuring and simple. Short sentences, no jargon, speak to the reader directly and explain the next step. Write in the site's language."
- *Pick photos*: "Stock photos with warm caring moments, people talking, bright welcoming spaces, soft colours."
- *Check*: "Contrast 4.5:1 or more, one H1 per page, buttons 44px high, no shadows on static elements, the rules in section 7."
