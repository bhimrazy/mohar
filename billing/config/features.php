<?php

/*
| Switches for unfinished work, per module: `features.<module>.<flag>`, env `FEATURE_<MODULE>_<FLAG>`, off by default.
| Config only, never a stored setting. The PR that finishes the work deletes its flag and every check of it.
| A flag hides the screen and its routes; it does not undo data the feature already wrote (architecture.md, Feature flags).
*/
// No flags at present (Nepal VAT mode's was lifted with VAT PR 4c).
return [];
