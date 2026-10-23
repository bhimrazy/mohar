### Added

- **Reports can be read a page at a time on screen (#355).** A report view asks for 1 to 200 rows from an offset of up to 100,000 and gets the rows plus the total number of matching rows. It needs the report's own view permission (`reports.<key>.view`, already registered); the export permission does not allow it. Exports are unchanged and still write every row. No schema or permission change.
