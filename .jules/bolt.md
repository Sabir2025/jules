## 2025-08-21 - [Optimize site file scanner lookup & memory footprint]
**Learning:** In PHP single-file administrative tools scanning site HTML root files, `in_array` causes $O(N)$ lookup costs for managed pages and full `file_get_contents($path)` loads entire HTML files into memory when only extracting `<title>`.
**Action:** Use associative array keys `$managed[$filename] = true` with `isset()` for $O(1)$ lookups, and set `$length` to 64KB (`65536`) in `file_get_contents` to avoid loading full files into memory during scanning.
