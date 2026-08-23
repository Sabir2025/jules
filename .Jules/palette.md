## 2026-08-23 - Icon-Only Action Buttons in Dynamic UI
**Learning:** Single-file web applications dynamically generating DOM elements often use icon-only buttons (such as `✎`, `✕`, `↑`, `↓`) without accessible names, rendering them unusable or confusing for screen readers.
**Action:** When inspecting DOM generation logic, ensure all dynamically created icon-only buttons have explicit `aria-label` attributes alongside visual `title` tooltips.
