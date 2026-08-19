## 2026-08-19 - O(1) Managed Page Lookup & Bounded File Reads in PHP Scanner
**Learning:** In PHP site-scanning routines, checking `in_array` over managed files creates O(N*M) overhead, and reading whole `.html` files for `<title>` tags loads unnecessary content into RAM.
**Action:** Use associative hash maps (`$managed[$slug] = true`) for $O(1)$ lookup via `isset()`, and bound file reads with `file_get_contents($path, false, null, 0, 65536)` when parsing header metadata like `<title>`.
