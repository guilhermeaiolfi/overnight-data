# Third-Party Notices

## Cycle ORM parser subsystem

This project includes source and tests adapted from the Cycle ORM parser subsystem.

- Upstream project: Cycle ORM
- Upstream repository: https://github.com/cycle/orm
- Pinned upstream commit: `a7a1db351df8037ff7a1196e19688bfc7d35c63e`
- Imported area: structural parser subsystem and parser-focused tests
- Adaptation scope: namespace migration into `ON\Data\Query\Result\Parser`, replacement of `ArrayNode` with `CollectionNode`, replacement of `MultiKeyCollection` with `ReferenceIndex`, removal of parser-local typecasting and database-criteria generation, and standalone data-layer validation changes
- Upstream license: MIT

The adapted sources remain subject to the upstream MIT license and retain upstream provenance in the relevant file headers.
