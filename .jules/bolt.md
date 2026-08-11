# Bolt's Journal

## 2026-08-11 - [Initial Scan & Architecture Overview]
**Learning:** The application `admin.php` is a single-file PHP drop-in admin panel that manages multiple pages, saves data as JSON (`data.json`), and renders static HTML files. It relies on full file reads and writes on every page action or stat query, scanning directories using `glob()`, etc.
**Action:** Identify critical bottlenecks in file reading/parsing or scanning, or unnecessary loops, and apply targeted performance improvements.
