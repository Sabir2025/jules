## 2025-08-23 - O(1) Hash Maps and Chunked Reading for Site File Scanning
**Learning:** `scan_site_files` in `admin.php` loaded full `.html` files into memory to extract `<title>` tags and used `in_array()` inside a loop (O(N) search for managed files). Using `isset()` with an associative hash map reduces lookup to O(1), and passing a max length (64KB) to `file_get_contents` avoids reading huge HTML files entirely.
**Action:** Always prefer associative key lookups (`isset($map[$key])`) over `in_array()` in PHP loops, and bound memory usage when parsing metadata from disk files.
