# Bolt Journal - Critical Learnings

## 2025-08-15 - Site Scanner Optimization in admin.php
**Learning:** `scan_site_files()` performed `in_array()` O(N) searches inside a loop over discovered `.html` files, and loaded whole files into memory with `file_get_contents()` just to search for `<title>` tags. Switching to an O(1) hash map array lookup (`isset($managed[$basename])`) and streaming up to 64KB with `fopen`/`fread` drastically cuts memory consumption and improves execution speed when scanning large directories or large HTML files.
**Action:** Use associative array keys for O(1) set membership checks and stream-based partial file reads for metadata extraction in PHP.
