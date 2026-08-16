## 2026-08-16 - Hash Map Lookups and Chunked Stream Reading in File Scanning
**Learning:** In PHP, replacing `in_array` with associative hash map keys (`isset($map[$key])`) reduces array filtering complexity from O(N) to O(1). Additionally, using `fopen`/`fread` (reading 64KB) instead of `file_get_contents` for extracting HTML `<title>` tags avoids loading large files entirely into RAM.
**Action:** Always prefer hash map array key lookups for exclusion sets and chunked stream reads when inspecting headers/metadata in PHP file processing functions.
