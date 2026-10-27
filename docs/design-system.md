# Dakshayani — Studio 01

Use this visual direction for each page as it is redesigned. The homepage is the implemented reference. Adopt it page by page; do not automatically restyle account or administration screens.

## Foundation

Load `assets/css/design-system.css` after the existing base and PWA styles. Add `dakshayani-design` to the body. Load the Inter family (400, 500, 600, 700, 800), with system sans-serif fallback. Page-specific styles follow the shared stylesheet.

Shared tokens live in `design-system.css`: ink #132c35, muted #5d6f75, green #087d70, hover #066457, lime #d8f36a, paper #f6f8f7, white surface, border #dce5e1. Use lime sparingly on dark surfaces; use ink for readable text on lime. Maintain visible focus on all interactive controls.

## Composition

- Maximum content width: 1200px; 1320px on very wide screens.
- Side gutters: 32px desktop, 24px tablet, 18px phone.
- Section padding: 96px desktop, 64px phone.
- Body text: 16px; line height 1.7–1.8. Avoid body text below 14px except brief captions and supporting notes.
- Headings: Inter 600, tight tracking, balanced wrapping. Hero scales from 40px phone to 72px desktop. Section headings scale from 32px to 45px.
- Cards: white or dark green feature surface, 20px radius, 1px quiet border, restrained shadow. Allow one prominent feature card per group.
- Controls: at least 48px tall; 12px radius; solid green primary action and outlined secondary action. Keep actions at the same height when adjacent.
- Navigation: compact single row on desktop, drawer on mobile. Avoid scrolling announcement tickers and duplicate badges in the homepage hero.
- Process steps: numbered and separated by thin rules, without another heavy card layer.
- FAQs: simple divided rows. Forms: white surfaces on a pale section background.
- Imagery: preserve source proportions when text or logos appear inside a supplied image. Identify illustrations clearly. Never invent project evidence or performance figures.

## Responsive behavior

The homepage switches its hero to one column at 800px, service grids to two columns at 1000px and one at 700px. Phone actions stack. Test heading wrapping, navigation, form controls, image cropping, and card spacing at 320, 390, 768, 1024, and 1440px. Respect reduced motion. Do not hide overflow as a substitute for correcting a clipped component.

## Current implementation

`homepage.css` supplies the homepage layout; its Studio 01 section refines the previous layout. Shared navigation, footer, type, and palette belong in `design-system.css`. Promote additional components to the shared file when a second page uses them, keeping page-specific layout in its own stylesheet.

The redesign is local and has not been deployed. The local checkout lacks the production installation-photo directory, so the production gallery still needs a final check.
