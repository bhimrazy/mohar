# Third-party notices

This repository is all rights reserved (see `LICENSE`). The material below comes from third parties under their own
licences.

## Bikram Sambat month lengths

`billing/app/Modules/Billing/Support/BikramSambat.php` holds a table of the number of days in each Bikram Sambat month
for 2000–2095 BS. The month lengths are calendar facts from Nepal's published panchang. The table was compiled by
comparing these open-source tables year by year:

| Project | Author | Licence | Used for |
| --- | --- | --- | --- |
| [nepali-datetime](https://github.com/amitgaru/nepali-datetime) (`nepali_datetime/data/calendar_bs.csv`) | Amit Garu | Apache License 2.0 (`license.txt` in the repository; its `setup.py` says MIT) | Base table |
| [nepali-date-converter](https://github.com/sbmdkl/nepali-date-converter) (`src/config.ts`), npm `@sbmdkl/nepali-date-converter` | Shubham Dhakal | MIT, declared in `package.json` (the repository has no licence file) | Cross-check; rows 2062 and 2087 |
| [nepali-date](https://github.com/ernilambar/nepali-date) (`src/NepaliCalendar.php`) | Nilambar Sharma | MIT (Copyright (c) 2026 Nilambar Sharma) | Tie-break for 2062 and 2087 |

No source code from these projects is included. The conversion code is our own.

The MIT License requires the following notice to accompany copies of the material:

> Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
> documentation files (the "Software"), to deal in the Software without restriction, including without limitation the
> rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit
> persons to whom the Software is furnished to do so, subject to the following conditions: The above copyright notice
> and this permission notice shall be included in all copies or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
> WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
> COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
> OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

The Apache License 2.0 text is at <https://www.apache.org/licenses/LICENSE-2.0>. nepali-datetime ships no NOTICE file.
