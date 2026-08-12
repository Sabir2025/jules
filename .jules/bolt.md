# Bolt's Performance Journal

## 2025-02-18 - [Optimizing HTML Site Scanner]
**Learning:** Parsing the entire content of large files using `file_get_contents` to extract a small piece of metadata like a `<title>` tag introduces unnecessary high memory usage and IO overhead. Restricting the read block size using buffered file reading and early exit significantly reduces memory usage and execution time.
**Action:** Always prefer streaming/buffered processing with early returns when looking for early metadata (like `<title>` or header elements) in large static pages.
