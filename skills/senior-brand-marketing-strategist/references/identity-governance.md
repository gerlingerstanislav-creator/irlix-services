# Visual identity and asset governance

## Start with existing recognition

Compare the **existing mark** and proposed changes at the same sizes/backgrounds. Investigate:
- legibility of the name and distinctiveness of symbol at 16/24/32 px and header sizes;
- monochrome, inverse, transparent and print reproduction;
- kerning, joins, negative space, optical alignment, perceptual weight;
- typical applications: business proposal, website, app favicon, hiring PDF, slide title, embroidery/laser engraving;
- WCAG-informed contrast for actual text and interface use (logo artwork is not the same as text accessibility);
- trademark and font licence constraints, usage rights, master file traceability.

A redesign is **justified only by documented failure, rights issue or measured strategic benefit**. Avoid changing a recognisable mark solely because an AI finds a new one prettier. Explicit NO CHANGE is a successful outcome.

## Brand vs product UI

Corporate brand colors and fonts are not automatically safe UI tokens. A product design system has separate legibility, interaction and accessibility contracts. Adaptation may be appropriate but must be deliberate; do not silently recolor operational screens from a brand moodboard.

## Asset ledger

For every logo/photo/illustration/template/font capture:
`identifier | owner/rightsholder | origin | licence | consent | allowed audiences | checksum/version | approval | embargo | replacement/removal process`.
Default a user-uploaded historic Figma asset to **reference only** until legal and brand status are confirmed. Never republish:
- watermarked stock images;
- photographed people without required permission;
- documents with PII, pay rates, salary data, contacts or contracts;
- third-party logos in implied client references.

## Public brandbook publication gate

1. Versioned approved assets, no unresolved IP or privacy blocker.
2. Visible scope: public vs internal, not merely front-end "hidden" routes.
3. Every published "official" standard has approver/date/version.
4. Downloads link only to actual inspected assets and state their formats.
5. Deep links, print, mobile and preview tests recorded.
6. Revoke/replace paths defined (asset provenance and updates), not raw secrets committed.
