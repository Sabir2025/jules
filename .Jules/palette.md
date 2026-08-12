# Palette's UX Journal

## 2025-02-18 - Accessibility in Dynamic DOM & Single-File Admin Panels
**Learning:** When building lightweight single-file applications with client-side dynamic DOM rendering, standard server-rendered accessibility helpers (like auto-associated form labels or default ARIA tags) are frequently omitted or broken. Explicit programmatic focus management, input disables, and precise dynamic attribute binding are critical to prevent assistive technology and keyboard users from losing context.
**Action:** Always explicitly tie labels with `for` and `id` in modals, bind unique `aria-label` values to all dynamic button elements when they are constructed in JavaScript, and disable inputs during async transitions to maintain structured focus flow.
