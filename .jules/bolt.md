# Bolt's Journal ⚡

## 2026-08-14 - [O(1) Scan Site Files & Early-Terminating Title Scanner]
**Learning:** In PHP, reading large static HTML files fully into memory using `file_get_contents` to extract meta/title tags is highly inefficient and creates memory/CPU bottlenecks. Additionally, using `in_array` to filter out managed pages in a loop has O(N) complexity which degrades performance as the page list grows.
**Action:** Always optimize site/directory file scanning by indexing the managed page array keys to perform O(1) hash map checks, and implement buffered stream reading (`fopen`/`fread`) with early termination as soon as the target tag (e.g., `</title>`) is matched.
