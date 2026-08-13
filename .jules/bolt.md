# Bolt's Performance Journal ⚡

## 2026-08-13 - [O(1) Hash Maps & Streaming XML/HTML Parsers over Full File Loading]
**Learning:** Reading entire large files into memory just to extract metadata (like HTML `<title>` tags) can cause significant memory overhead and disk I/O bottlenecks. Additionally, doing sequential search operations (like `in_array`) in nested loops introduces a hidden O(N) penalty. Utilizing associative array lookups in PHP reduces lookup time to O(1), and reading files in small chunks (e.g., 4-8KB streams) up to a 64KB threshold allows early regex termination, optimizing performance and memory usage by orders of magnitude.
**Action:** Always favor structured `fopen()` streaming and hash-map lookups over memory-heavy, full-file loads (`file_get_contents`) or O(N) list searches (`in_array`) in processing loops.
