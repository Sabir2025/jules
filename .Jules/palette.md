# Palette's Journal

## 2024-08-11 - [Block Deletion Confirmation & Accessibility]
**Learning:** Destructive actions like removing a block on a visual content editor must have a confirmation prompt to prevent accidental data loss. Furthermore, icon-only buttons need descriptive `aria-label` attributes to ensure keyboard/screen-reader accessibility.
**Action:** Add confirmation prompts to the delete block function and ensure all interactive icons inside `admin.php` are annotated with clear ARIA labels and forms utilize appropriate labeling.
