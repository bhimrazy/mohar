# Nepal VAT and electronic billing rules for a hosting company, 2026-10-31

Research only, by the product manager agent. **This is not tax advice.** It summarises what the published law and
IRD documents say, so the owner's accountant can confirm or correct each finding instead of answering open questions.
Everything is in our own words; statute text appears only as short cited phrases.

Unlike the 2026-10-22 research behind `billing/docs/product/vat-register.md` §17 (when `ird.gov.np` did not answer),
the IRD site answered on 2026-10-31. The VAT Act, the VAT Rules, the Electronic Billing Procedure, the VAT Directive,
the CBMS API document and the e-billing notices were all downloaded from it and read first-hand.

**Confidence labels.** **Primary** = read in the IRD-published text of the Act, Rules, Procedure, a notice or IRD's own
API document. **Secondary** = a practitioner, vendor or news source. **High / medium / low** says how sure the reading
is: high = the text says it directly; medium = it follows from the text but needs an interpretation step; low = one
secondary source, or sources that disagree.

**Reading caveat.** The IRD PDFs use fonts whose extracted text swaps some Devanagari glyphs (for example व/ि, न/भ).
The meaning is clear in every passage cited here, but a phrase quoted below may differ in a vowel sign from the
printed page. Section and rule numbers are as they appear in the IRD texts; none is inferred.

## 0. Summary

1. **Any electronic invoice needs the tax office's approval first, at every size.** The VAT Act lets a taxpayer issue
   an electronic invoice only "with the Department's prior approval" (s.14क(1)). The Electronic Billing Procedure 2074
   adds that only software listed with the IRD may be used (cl. 3(क)) and that the taxpayer needs a permit from its
   tax office (cl. 4(क)). The turnover lines decide who **must** bill electronically and, at Rs 20 crore, report to
   CBMS. They do not decide who **may** bill from software without permission. The vat-register spec left this open
   for the accountant ("whether software billing below the line needs the tax office's permission", §14 and §17), and
   A10 leaned towards no approval below the line. The text answers it: approval is needed at every size. *Primary,
   high.*
2. **A cloud-hosted billing system must report to CBMS in real time, whatever the turnover.** When the tax office
   grants the permit, it must make sure that anyone using a "Cloud Base Computing System" sends each bill to CBMS at
   the moment it is issued (Procedure cl. 4(ङ)). A cloud server must be in Nepal, its operator registered in Nepal,
   the IRD must have access, tenants' data must be kept apart, and a three-party agreement (Schedule 7) must be filed
   (cl. 7(ख)). An accountant or the tax office must say whether a self-hosted Mohar install on a VPS counts as
   "cloud". *Primary, high on the text; the classification is open.*
3. **Software that lets bill data be edited or deleted costs the taxpayer Rs 5 lakh, approved or not** (s.29(1)(छ२)).
   The maker, installer or operator of billing software that does not follow the Procedure faces the same Rs 5 lakh
   (s.29(1)(छ३)). The Economic Act 2083 graded the fines on taxpayers who issue electronic invoices (the छ२ case) from
   Rs 1 lakh to Rs 5 lakh (IRD booklet). The booklet does not say whether छ३ changed. Mohar's immutable tax invoices
   and notes are therefore a legal requirement, not a nicety. It also means whoever distributes Mohar carries exposure
   as a software producer. *Primary, high.*
4. **E-billing and CBMS thresholds, from the IRD notices themselves.** Two lines make electronic billing compulsory,
   **without CBMS**: Rs 5 crore a year for hotels, restaurants, snack houses (चमेना गृह) and **internet service
   providers** (notice 2075/11/29, confirmed in force by the notice of 2079/02/20); Rs 10 crore for any business from
   2079/04/01 (notice 2079/02/20). Only the Rs 20 crore line, for any business except banks and financial institutions
   that do not invoice consumers, adds **CBMS at the time of issue** (notice dated 2083/01/03, IRD decision of
   2082/12/27). *Primary, high on each notice. How the 2079 and 2083 lines relate is unclear (§2).*
5. **Bill numbers run in sequence within each fiscal year** for software billing (Procedure cl. 6(ङ) and 6(च),
   Schedule 5). CBMS identifies a bill by fiscal year plus number. This mostly settles the fiscal-year numbering
   question in favour of a per-BS-fiscal-year sequence. *Primary, high for e-billing software.*
6. **When payment is received in convertible foreign currency, the tax invoice must show the NPR amount at the Nepal
   Rastra Bank rate of the transaction day** (VAT Rule 21). That answers the source and the date in D6. The text does
   not say whether the buying, selling or mid rate applies. Mohar's invoice PDF prints the invoice currency only.
   *Primary, high.*
7. **The sales book layout Mohar exports is out of date.** The 25th amendment of the VAT Rules (2080-02-15) removed
   the goods-name and quantity columns from Schedule 9. The 26th amendment (2081) replaced "निकासी" with "निर्यात"
   (export) in the headings. The Economic Act 2083 then removed the Schedule 9 format from the Rules, so the IRD now
   prescribes it (IRD booklet). Mohar's `ird` layout still carries the 2022 template's goods, quantity and unit
   columns and the "निकासी" wording. *Primary, high for the amendments; no post-2083 format found.*
8. **Hosts with e-billing permission must upload their sales and purchase details with each return**, electronically,
   in the IRD's format (Rule 25(2)). Assumption A2 ("typed totals, no upload") holds only for a host without that
   permission. Under finding 1, such a host should not be billing from software at all. *Primary, high.*
9. **Time of supply is the earliest of invoice, service performed, or consideration received** (Act s.6(2)). For
   continuous telecom or similar public services it is the invoice (s.6(3)(क)). An advance tied to a supply is
   consideration (Directive §4.3). A deposit is not taxable until the supplier matches it to a supply (s.12(8)). D1
   ("tax invoice on payment") and A3 ("add-funds is a deposit") fit this. One case still needs the accountant: a
   hosting renewal whose service keeps running before it is paid. *Primary, medium.*
10. **Imported services are reverse-charged.** Any recipient, registered or not, of a service from a person outside
    Nepal must assess and pay VAT at the earlier of payment or receipt (s.8(2)). A registered recipient can then claim
    it as input credit (s.17(5ख)). This covers a host's foreign registrar and cloud bills. It is purchase-side, so
    outside Mohar's scope, but the accountant must know. *Primary, high.*

## 1. Sources (all read 2026-10-31)

Primary (IRD, `ird.gov.np` and its CDN `giwmscdnone.gov.np`):

| Ref | Document | URL | Version shown |
| --- | --- | --- | --- |
| P1 | VAT Act 2052, as amended (मूल्य अभिवृद्धि कर ऐन, २०५२) | https://ird.gov.np/content/13407/value-added-tax-act--2052--as-amended/ | amendments up to Economic Act 2082 (2082/03/30) |
| P2 | VAT Rules 2053 with the 27th amendment (मूल्य अभिवृद्धि कर नियमावली, २०५३) | https://ird.gov.np/content/13412/value-added-tax-rules--2053--with-twenty-seventh/ | 27th amendment 2082/02/15 |
| P3 | Electronic Billing Procedure 2074, 4th amendment (विद्युतीय बीजक सम्बन्धी कार्यविधि, २०७४) | https://ird.gov.np/content/5490/tax-laws-16910453028/ | in force 2074/09/16; amended 2075/02/09, 2075/11/08, 2076/04/13, 2076/09/09 |
| P4 | VAT Directive 2069, 3rd amendment 2080 (मूल्य अभिवृद्धि कर निर्देशिका) | https://ird.gov.np/content/9010/tax-laws-16834521109/ | 2080; predates the 2081 Schedule 2 |
| P5 | Notice on e-billing, 2079/02/20 (विद्युतीय माध्यमबाट बीजक जारी गर्ने सम्बन्धी जरुरी सूचना) | https://ird.gov.np/content/8070/notice-16542603731/ | published 2079/02/20 |
| P6 | Notice on e-billing and CBMS, dated 2083/01/03 (3 Baishakh; the URL slug says 01-04) | https://ird.gov.np/content/13488/cbms-notice-01-04/ | decision 2082/12/27 |
| P7 | CBMS API technical document | https://ird.gov.np/content/9052/cbmsapitechnicaldocumentfor/ | "Updated on October 14, 2022 (2079 Ashoj 28)" |
| P8 | Economic Act 2083 information booklet (आर्थिक ऐन, २०८३ जानकारीमूलक पुस्तिका) | https://ird.gov.np/content/13632/economic-act--2083---information-booklet-on/ | 2083 |
| P9 | Notice on issuing bills (विल/बीजक जारी गर्ने सम्बन्धी सूचना) | https://ird.gov.np/content/13574/notice-regarding-issuance-of-will-invoice-/ | undated, 2083 list |
| P10 | Purchase book format page (खरिद खाताको ढाँचा) | https://ird.gov.np/content/7049/purchasebookformat/ | 27 Asar 2078; attachment not retrievable |
| P11 | Nepal Rastra Bank forex API | https://www.nrb.org.np/api/forex/v1/rates | response for 2026-10-30 |

The Economic Act 2083 itself (https://ird.gov.np/content/13566/economic-act--2083--date-of-publication-in/) is a
415-page scan with no text layer. It was not read; P8 is IRD's own summary of it.

Secondary:

| Ref | Source | URL |
| --- | --- | --- |
| S1 | Nexus Advisory, IRD billing software (page says "checked 8 Oct 2026") | https://nexusadvisory.com.np/ird-approved-billing-software-nepal/ |
| S2 | VATupdate, Nepal e-invoicing country booklet (2026-09-20) | https://www.vatupdate.com/2026/09/20/nepal-e-invoicing-e-reporting-country-booklet/ |
| S3 | Ratopati, Rs 200 million e-billing line | https://english.ratopati.com/story/59617/businesses-with-transactions-exceeding-rs-200-million-must-now-issue-mandatory-electronic-bills |
| S4 | CollegeNP, IRD new purchase and sales book format (2078/79) | https://www.collegenp.com/news/inland-revenue-department-ird-published-vat-purchase-and-sales-book-new-format |
| S5 | Baker Tilly Nepal, sales and purchase register templates (already used in vat-register §17) | https://bakertilly.com.np/tax-portal/notices-and-circulars/new-format-of-sales-register-and-purchase-register |

## 2. Who must bill electronically, CBMS, and approval of software

**Rule.**

- Act s.14क(1): a taxpayer may issue an electronic invoice "with the Department's prior approval". s.14क(2): the IRD
  may require named taxpayers to bill electronically and connect to CBMS. s.14क(3): the IRD sets a procedure that
  makers, distributors and users must follow. *P1, primary, high.*
- Rule 18क: a taxpayer approved for electronic billing must issue bills that way. Rule 23(3): records may be kept on
  computer with the IRD's permission. *P2, primary, high.*
- Procedure cl. 3(क): anyone billing electronically must use only devices and software listed with the IRD. The list
  is published on the IRD site. cl. 3(ख): the maker certifies its own software for listing (Schedule 1), or the
  distributor does for imported software. cl. 3(ग): the lister gives each user a consent letter (Schedule 2) and
  tells the IRD. *P3, primary, high.*
- Procedure cl. 4(क): anyone who wants to bill electronically, or is required to, must first get a permit from its
  tax office. cl. 4(ख): the application (Schedule 3) asks for the software maker and version, the server location,
  the number of terminals, a user manual, the method that keeps data from being altered, backup and recovery, system
  architecture, the setup file, 20 sample bills in Excel, and printed samples: original and copy, reprints marked
  "Copy of Original (1)" and "(2)", a sales report, a credit or debit note, a cancelled bill, an audit trail report and
  a report of the materialised view synced to the CBMS **test** server. *P3, primary, high.*
- Procedure cl. 4(ङ): for anyone using a "Cloud Base Computing System", and anyone the IRD names by notice, the tax
  office must make sure bill data reaches the CBMS central server at the moment of issue. *P3, primary, high.*
- Notices on who **must** bill electronically:
  - 2075/11/29 (cited in P5 as still in force): over Rs 5 crore a year for hotels, restaurants, snack houses
    (चमेना गृह) and internet service providers (इण्टरनेट सेवा प्रदायक, ISP); over Rs 10 crore for department stores,
    marts, flooring and furnishing; cinema halls in the Kathmandu Valley on the box-office system. E-billing only;
    P5 says nothing about CBMS for these. *P5, primary, high.*
  - 2079/02/20, from 2079/04/01: any taxpayer with turnover over Rs 10 crore in FY 2077/78 or 2078/79, except banks and
    financial institutions that do not invoice consumers; all cinema halls on the box-office system. Others may bill
    electronically by choice **with a permit**. Taxpayers already holding a permit need not reapply. Only listed
    software may be used. *P5, primary, high.*
  - 2083/01/03 (3 Baishakh): every taxpayer with annual turnover over Rs 20 crore, except banks and financial
    institutions that do not invoice consumers directly, must bill electronically and connect to CBMS at the time of
    issue. This is the only notice read that ties a turnover line to CBMS. *P6, primary, high.*
- Economic Act 2083 (per P8): the IRD may provide a billing system to taxpayers for issuing electronic invoices.
  *P8, primary, high that the power was added; no system found.*

**Conflicts and gaps.**

- P6 sets Rs 20 crore with CBMS. P5 (2079) set Rs 10 crore for e-billing. Secondary sources report a Rs 25 crore line
  from the FY 2080/81 budget, lowered to Rs 20 crore in 2083 (S2, S3), and say the FY 2083/84 budget announced Rs 10
  crore without a notice so far (S1). From the primary texts it is unclear whether the 2079 Rs 10 crore e-billing duty
  still stands beside the 2083 Rs 20 crore CBMS duty. *Low.*
- An earlier secondary source said "internet service providers" at Rs 5 crore. P5 confirms the wording. Whether a
  web-hosting company is an ISP for this notice is not defined in anything read. *Open; accountant.*
- The IRD's list of approved software is "on the website" (cl. 3(क)). It was not found on the new site. The
  secondary-cited PDF (`/public/pdf/834857563.pdf`) returns the site's HTML shell. *Not verified.*

**What this means.** Software billing is permitted billing. The Rs 5, 10 and 20 crore lines only make it compulsory
and, at Rs 20 crore, add real-time CBMS posting. A host that bills from Mohar needs a permit from its own tax office,
and Mohar would need to be on the IRD list (cl. 3). The maker lists software made in Nepal. For software made or
imported from abroad with no authorised distributor in Nepal, cl. 3(ख) (last sentence) lets the user certify and list
it, taking on the producer's duties. Whether a self-hosted install counts as cloud under cl. 4(ङ) and cl. 7 decides
whether CBMS posting is needed even for a small host.

## 3. Cloud and server location (Procedure cl. 7)

- cl. 7(क), own central server: it must be in Nepal; the IRD and the tax office must be told where it is and must
  have access.
- cl. 7(ख), rented server or service: the operator must be registered in Nepal; the server must be in Nepal with
  prior notice of its location; the IRD must have access; the server must be multi-tenant with each user's data stored
  apart; and the software maker or distributor, the server operator and the billing taxpayer must sign the Schedule 7
  agreement and file it with the IRD. Schedule 7 asks for the data-centre location, IP address of the VPS or server,
  multi-tenancy (yes or no), database and version, DR site, SSL, tier or standard, and URL.
- cl. 7(ग), multinationals with a server abroad: a separate audit-log server in Nepal, written at the time of issue.
- *P3, primary, high.* A10 (the server rule applies only to IRD-approved software) does not match. The rule applies to
  anyone billing electronically from a central server, and that requires approval anyway.

## 4. Software standards (Procedure cl. 6) and the master bill view

Standards a billing program must meet (*P3, primary, high*):

- a DBMS that runs SQL (6(क));
- entered data cannot be deleted (6(ख), 6(ट)); errors are fixed by ending the record's effect and adding a new
  record, with a separate report of corrected records (6(ट));
- an enabled log archive of every database action, plus backup and recovery (6(ग)); every record carries entry date,
  time, effect and user (6(घ));
- a table or materialised view with **Schedule 5** fields, bill numbers in sequence **within each fiscal year**,
  viewable and printable (6(ङ));
- a bill with a fiscal-year serial number prints **once**. A reprint must clearly say "प्रतिलिपि" or "Copy of Original"
  and show how many times it has been printed. The count is stored and available to an investigation (6(च));
- the bill meets the VAT, Income Tax and Excise Act requirements in their prescribed formats (6(छ));
- an issued bill may be cancelled with a reason, and the master view updated, with a report of cancelled bills
  (6(ज));
- bill and sales details in the Schedule 6 formats on screen and in print (6(झ)); an audit trail or activity log per
  user (6(ञ));
- export of tables to Excel or XML, and PDF (6(ठ)); backup by an authorised user (6(ड)); a user manual in Nepali or
  English, also as in-app help (6(ढ));
- able to post to CBMS by web service (6(ण));
- where consumers pay electronically, send Schedule 8 data to the payment provider for the 10% VAT cash-back (6(त),
  6(थ)). The cash-back itself is Act s.25(1ख) and applies to goods and services the IRD names by notice. Whether
  hosting is named was not checked.

Schedule 5 master-bill fields: Fiscal Year, Bill_no, Customer_name, Customer_Pan, Bill_Date, Amount, Discount,
Taxable_Amount, Tax_Amount, Total_Amount, Sync with IRD, IS_Bill_Printed, Is_bill_Active, Printed_Time, Entered_By,
Printed_by, Is_realtime, Payment_Method, VAT_Refund_Amount (if any), Transaction Id (if any).

Schedule 6 (on-screen and printed formats): a "Tax Invoice" with bill number, transaction date, seller PAN, invoice
issue date, seller name and address, purchaser name, address and PAN, method of payment (cash, cheque, credit, other),
lines (S.No, details, quantity, unit price, total), discount, taxable amount, "VAT 13%", total, **amount in words**,
and authorised signature. An abbreviated tax invoice has the same layout without the VAT line. A sales book has these
columns: date, bill no, buyer's name, buyer's PAN, total sales, non-taxable sales, export sales, discount, taxable
sales amount and tax.

## 5. The tax invoice

- Act s.14(1): every registered person gives the recipient an invoice for every supply, except where the Rules say
  otherwise. s.14(2): the format is prescribed. s.14(3): the recipient must take it. *P1, primary, high.*
- Rule 17(1): the tax invoice follows **Schedule 5** (and 5क for insurers). For goods it also shows kind, size, brand,
  model and at least the first four digits of the HS code for imports. Rule 17(2): "कर बीजक" must be written clearly on
  the face. **Three copies**: the original to the buyer, the second kept separately to produce to the tax office, the
  third for the seller's own records. *P2, primary, high.*
- Schedule 5 (26th amendment), fields: invoice number; **transaction date**; seller's registration number; **invoice
  issue date**; seller name and address; buyer name and address; buyer's registration number; payment method (cash,
  cheque, credit, other); lines with S.No, HS code, description, quantity, unit price, amount; discount at a stated
  percent; taxable value; tax at a stated percent; total; seller's signature. *P2, primary, high.*
- The Directive adds that when one invoice carries both taxable and exempt supplies, they are shown separately. *P4,
  primary, high.*
- Rule 18: an abbreviated tax invoice is allowed for retail sales with the tax office's permission, never above
  Rs 10,000, and gives the buyer no input credit. *P2, primary, high.* Not needed for hosting.
- Act s.15: an unregistered person may not show or collect VAT. *P1, primary, high.*
- Act s.29(1)(ग): failing to issue an invoice costs Rs 10,000 each time. s.29(1ग): issuing an invoice without
  supplying the goods or service costs 50% of the invoice value or up to six months' prison. *P1, primary, high.* The
  second point supports D1: don't issue a tax invoice for a renewal that has not been supplied or paid.
- BS dates on the invoice: Schedule 5 prints "मिति" without saying BS or AD. CBMS takes BS dates (§11). *Primary, medium
  that BS is expected; not stated for the printed invoice.*

## 6. Numbering, fiscal year and periods

- Bill numbers: sequential within each fiscal year for software billing (Procedure 6(ङ), 6(च)). Notes: a "क्रमागत"
  (running) number (Rule 20(1)(क)), with no reset rule stated. CBMS keys bills by `fiscal_year` plus `invoice_number`
  and rejects a duplicate bill (code 101) (P7). *Primary, high for bills; medium for notes.*
- The tax period is the BS month (Rule 26(1)). The return form (Schedule 10) lists the months from साउन to असार.
  Bi-monthly and four-monthly periods exist on the form. Rule 26(3ख) lets the IRD set four-monthly returns for print
  and electronic media and for taxpayers with turnover up to Rs 1 crore. From 2083, small and cottage industries may
  too (P8). *Primary, high.*
- Return and payment are due within 25 days of the period's end, even with no transactions (Act s.18(1), s.19(1)). A
  late payment carries a 10% a year additional fee (s.19(2)). The return is amendable within 7 days from 2083 (P8).
  *Primary, high.*
- Schedule 10 asks for: taxable sales, export, exempt sales; purchases and imports; other adjustments; credit carried
  forward; refund claimed; and **counts** of purchase invoices, credit notes, debit notes, credit advices, debit
  advices and sales invoices for the period. Amounts under one rupee are rounded to one rupee. *Primary, high.*

## 7. Credit and debit notes, cancellation, refunds

- Rule 20(1): when the value of a supply changes, the note says "credit note" or "debit note" and shows a running
  number, issue date, supplier's name, address and registration number, recipient's name, address and registration
  number (if registered), the related tax invoice's number and date, the description and reason, the amount and the
  tax. Rule 20(2): a monthly account of the notes is kept. *P2, primary, high* (this upgrades F3 in vat-register §1).
- Directive: an issued invoice may not be altered; changes go through notes, adjusted at the tax rate that applied at
  the time of supply, and accounted monthly. *P4, primary, high.*
- Procedure 6(ज): an issued bill may be cancelled with a reason. The cancellation is recorded on the master view and
  in a cancelled-bills report. The application samples include both a credit note and a cancelled bill (cl. 4(ख)).
  *P3, primary, high.* So cancellation of a bill is a recognised software state beside credit notes, but the Rules
  route value changes through notes.
- In which period a note reduces output VAT: not stated in the Act or Rules read. The Schedule 10 note counts are per
  period, which suggests the note's own period. *Medium; accountant (F5).*
- Refunds to customers: no separate rule. A refund changes the value of the supply, so Rule 20 applies. *Medium.*
- Bad debts: no bad-debt relief provision was found in the Act. *Medium (absence); supports A6.*
- CBMS has a credit-note ("billreturn") endpoint but **no debit-note endpoint** (P7). *Primary, high.*

## 8. Rate, exempt and zero-rated supplies, export of services

- 13% single rate (Act s.7(1)). From 2083 the government may set more than one rate, none above 13%, by gazette
  notice (P8; the booklet gives no section number). Schedule 1 supplies are exempt and Schedule 2 supplies are zero-rated (s.5(3), s.7(2)). *Primary, high.*
- Registration: required above Rs 50 lakh a year for goods and Rs 30 lakh for services or mixed (Rule 6(1)).
  Voluntary registration is allowed (Rule 6(2)). *Primary, high.*
- Schedule 2 item 2 (replaced by the Economic Act 2081), zero-rated: (क) a service a Nepal resident supplies to a
  person outside Nepal who has no business, business representation or legal representative in Nepal; (ख) a supply
  of goods or services by a registered resident to a person residing outside Nepal. *Primary, high.* The current text
  has **no foreign-currency condition**.
- Place of supply of a service is where the benefit is received (Rule 16). *Primary, high.*
- Directive (2080, written before the 2081 schedule): for exports of software and online services, the export counts
  for VAT refund only once Nepal Rastra Bank has certified it on the export contract, invoice and proof of payment
  from the buyer. Foreign currency received without that is not enough. *P4, primary, high on the text; medium on
  whether it still applies.*
- Export declaration number and date columns: the prescribed customs declaration is for goods. Services have none.
  *Medium; supports A8.*
- Domain registration resold to Nepali clients: no specific provision found in the Act, Rules, Directive or any
  secondary source. It is a service supplied in Nepal and not in Schedule 1 as read, so 13% applies by default.
  *Medium (absence); accountant.*

## 9. Foreign currency and exchange rate

- Rule 21: when the consideration is received in convertible foreign currency, the tax invoice must state the
  NPR equivalent at the exchange rate set by Nepal Rastra Bank for the day of the transaction. The Directive repeats
  this and adds that 13% VAT is computed on that NPR value. *P2, P4, primary, high.*
- The rule does not say whether the buying, selling or mid rate applies. NRB publishes both (P11). Some currencies
  are quoted per 10 or per 100 units (INR per 100, JPY per 10). The day's set is published in the afternoon (the
  2026-10-30 set at 16:52). *P11, primary, high.*
- Mohar's `NrbRateSource` already reads this API with a `platform.nrb_rate_type` setting (mid by default, or buy or
  sell). The invoice PDF prints "All amounts in <currency>" and shows no NPR equivalent.

## 10. Imported services, foreign registrars and cloud providers

- Act s.8(2): a registered or unregistered person receiving a service from a person outside Nepal assesses and pays
  VAT at the earlier of payment or receipt of the service. s.17(5ख) lets the registered recipient claim it as input
  credit. The Directive (§2.9) calls this the reverse charge and gives an example that also applies 15% income tax
  withholding (Income Tax Act s.88(1)) on the payment to the non-resident. *Primary, high for VAT; the withholding is
  the Directive's example, not checked in the Income Tax Act.*
- Non-residents supplying "electronic services" to Nepal register above Rs 30 lakh a year (s.10ख1). The definition
  (s.2(ट2)) includes cloud services, data storage, software supply and updates, and online advertising. Rule 16क sets
  the place of supply as Nepal on any of several tests, such as a Nepali IP address or Nepali card payment. *Primary,
  high.* For a VAT-registered host buying from such a provider, whether to apply reverse charge or accept the
  provider's Nepal VAT is an accountant question.
- Mohar impact: purchase side, so out of scope (vat-register §16). The reseller cost of a domain carries no output VAT
  effect. The host's sale to its client is a normal 13% supply.

## 11. CBMS interface (for when it is needed)

- `POST https://cbapi.ird.gov.np/api/bill`: username, password (the taxpayer portal login), seller_pan, buyer_pan,
  fiscal_year (`2073.074` form), buyer_name, invoice_number, invoice_date (BS `2074.07.06` form), total_sales,
  taxable_sales_vat, vat, excisable_amount, excise, taxable_sales_hst, hst, amount_for_esf, esf, export_sales,
  tax_exempted_sales, isrealtime, datetimeClient. Response codes: 200 success, 100 bad credentials, 101 bill exists,
  102 save error, 103 unknown, 104 invalid model. *P7, primary, high.*
- `POST https://cbapi.ird.gov.np/api/billreturn` (credit note): the same plus ref_invoice_number, credit_note_number,
  credit_note_date, reason_for_return. 105 means the referenced bill does not exist. *P7, primary, high.*
- The API uses the taxpayer's portal password, so a password change breaks posting until it is updated (P7). Mohar's
  CBMS-ready columns (vat-register §11) cover these fields. HST and ESF are zero for hosts.

## 12. Records and the sales book

- Act s.16(1): accounts kept up to date per tax period; s.16(1क): the IRD may have continuous access to the database;
  s.16(2): records show date, value and the counterpart's registration number **if registered**; s.16(3): purchase and
  sales books self-certified each fiscal year. *P1, primary, high.*
- Rule 23(1): keep tax invoices issued and received, import and export papers, all credit and debit notes, the VAT
  account (Schedule 7), purchase book (Schedule 8) and sales book (Schedule 9). Rule 23(7): **six years**. *P2,
  primary, high* (upgrades F1).
- Rule 25(1): purchase and sales books are certified by the taxpayer each tax period. Rule 25(2): a taxpayer with an
  e-billing permit, or one the IRD names, submits purchase and sales details electronically in the IRD's format with
  each return. *P2, primary, high.*
- Procedure 8(घ): an e-billing taxpayer backs up the database and logs for each fiscal year and keeps them for the
  record-keeping period. *P3, primary, high.*
- Schedule 9 as published with the 27th amendment: header with taxpayer name, registration number and tax period
  (month, year). Columns: बीजक (मिति, बीजक नम्बर, खरिदकर्ताको नाम, खरिदकर्ताको स्थायी लेखा नम्बर), जम्मा बिक्री/निर्यात
  मूल्य (रु), स्थानीय कर छुटको बिक्री मूल्य (रु), करयोग्य बिक्री (मूल्य (रु), कर (रु)), निर्यात (निर्यात गरेको वस्तु वा सेवाको
  मूल्य (रु), निर्यात गरेको देश, निर्यात प्रज्ञापनपत्र नम्बर, निर्यात प्रज्ञापनपत्र मिति), कुल जम्मा. Footnotes: the 25th amendment
  (2080) removed column 5 (goods or service name) and column 6 (quantity); the 26th amendment (2081) replaced
  "निकासी" with "निर्यात"; some headings were changed by a gazette notice of 2077/06/19. *P2, primary, high.*
- Economic Act 2083: the sales book format (Schedule 9) is removed from the Rules and is now as the IRD prescribes
  (P8; the Rules PDF read is the 27th amendment and predates this). No IRD-prescribed format after 2083 was found.
  The IRD's 2078 purchase-book page (P10) has no retrievable attachment. The 2078/79 notice is known only from S4.
  *Primary for the change; the new format is not verified.*

## 13. Advance payments and prepaid credit

- Act s.6(2): time of supply is the earliest of invoice, removal of goods, **service provided**, or **consideration
  received**. s.6(3)(क): for continuously supplied telecom or similar public services, it is when the invoice is
  issued. s.6(3)(ख): for instalment contracts, the earlier of payment or the contractual due date. *P1, primary,
  high.*
- Directive §4.3: an advance for a supply is part of the consideration and fixes the time of supply when received.
  It is not full consideration, so the balance is taxed later. *P4, primary, high.*
- Act s.12(8): a deposit held against goods or services is not taxable value until the supplier matches it to a
  supply as consideration, as prescribed. *P1, primary, high.* This supports A3: add-funds credit is a deposit taxed
  when it pays an invoice. The Rules' "as prescribed" was not found, so *medium* on how the matching works.
- Open: a hosting service that keeps running after its renewal date while the invoice is unpaid. Under s.6(2)(ग) the
  supply may have happened before payment. Hosting may or may not be a "continuous ... similar public service" under
  s.6(3)(क) (invoice time). This decides whether D1 (tax invoice only on payment) is safe for services delivered
  during a grace period.

## 14. Mapping the open Mohar questions

| Question | What the rules say | Confidence | Remaining question for the accountant | Mohar impact |
| --- | --- | --- | --- | --- |
| **CBMS** (D5) | Software billing needs prior approval at any size (s.14क(1); Procedure 3, 4). CBMS at issue is required above Rs 20 crore (P6) and for anyone on a cloud system (Procedure 4(ङ)). The Rs 5 crore line for ISPs (P5) makes e-billing compulsory, not CBMS. | primary, high (text); classification open | Is a hosting company an "ISP" for the Rs 5 crore line? Is a self-hosted Mohar on a VPS a "Cloud Base Computing System"? Does the 2079 Rs 10 crore e-billing line still stand? | Treat the CBMS driver as likely needed for real hosts. Build an IRD permit checklist (Schedule 3 samples) and the Schedule 7 agreement data. Revisit D5 with the owner. |
| **D2** note numbering | Bills: sequential per fiscal year (Procedure 6(ङ)). Notes: "running number" (Rule 20(1)(क)); CBMS keys notes with `fiscal_year`. | primary, high (bills); medium (notes) | Should credit and debit notes also restart each BS fiscal year, and is the `CN-`/`DN-` prefix with the BS FY acceptable? | Turn on `{BS_FY}` and the `bs_fiscal_year` reset for invoices and notes together in VAT mode (vat-register §4.5, AC 58). |
| **Fiscal-year numbering** (invoices) | As D2: sequential within each fiscal year; CBMS rejects a duplicate bill number within a fiscal year. | primary, high | Restart at 1 each Shrawan, or a running number with the fiscal year in the format? | Default the invoice format in VAT mode to a per-FY sequence; refuse a format without `{BS_FY}` when reset is per FY. |
| **D6** currency rate | When paid in convertible foreign currency: NRB rate on the transaction day, and the invoice shows the NPR equivalent (Rule 21). Export zero-rated under Schedule 2 item 2; current text has no foreign-currency condition. | primary, high (source and date); open (buy, sell or mid) | Which NRB rate (buying, selling, mid)? Is "transaction day" the payment day (D1) or the invoice date? Do online service exports need NRB certification to zero-rate, or only for refunds? | Snapshot the NRB rate of the numbering day as `report_rate`, using the rate type set in Settings. Print the NPR equivalent and rate on tax invoices paid in foreign currency. Mark an export through the 0% export rule only. |
| **A1** proforma then VAT bill | Issuing an invoice without supplying costs 50% of its value (s.29(1ग)). Time of supply includes "service provided" (s.6(2)(ग)). | primary, medium | Is a proforma acceptable for renewals where the service keeps running unpaid? | None if confirmed. Otherwise, an option to number on the service start date. |
| **A2** typed totals, no upload | E-billing permit holders upload purchase and sales details with the return (Rule 25(2)). | primary, high | In what format does the portal take the upload today (after the 2083 removal of Schedule 9)? | An IRD upload export (XLSX or CSV) to the portal's format, once known; reopen D4. |
| **A3** add-funds is a deposit | A deposit is not taxable until matched to a supply (s.12(8)). An advance for a specific supply is consideration (Directive §4.3). | primary, medium | Is a prepaid wallet top-up a deposit (s.12(8)) and not an advance? | None if confirmed. |
| **A4** refund carries a credit note | Value changes go through Rule 20 notes; no refund rule otherwise. | primary, medium | None beyond confirming. | None. |
| **A5** chargeback leaves the bill | Nothing found. | not found | When a wallet payment is reversed, does the host issue a credit note at once, or only when the money is written off? | None if confirmed. |
| **A6** no bad-debt relief | No bad-debt provision found in the Act. | primary (absence), medium | Confirm no relief. | None. |
| **A7** reprint marking below the line | Required of all e-billing software (Procedure 6(च)), and software billing needs a permit at any size. | primary, high | None beyond confirming. | Already built; make it a MUST, not a SHOULD (vat-register §9). |
| **A8** no export declaration for services | Declarations are customs papers for goods. | medium | Confirm the columns stay blank for services. | None. |
| **A9** AD dates with optional BS | Periods are BS months (Rule 26); CBMS takes BS dates; Schedule 5 does not say BS or AD. | primary, medium | Must the printed tax invoice carry the BS date? | Force the BS date on tax invoices and notes in VAT mode (it is optional today). |
| **A10** server-in-Nepal only for approved software | The rule applies to anyone billing from a central server (Procedure 7), and billing by software needs approval anyway. | primary, high | Does a VPS rented from a Nepali data centre satisfy cl. 7(ख)? A foreign VPS cannot. | A deploy check and a docs warning: VAT mode with a server outside Nepal is non-compliant. The Schedule 7 agreement needs host, operator and maker details. |
| **A11** buyer PAN blank | Records show the counterpart's number "if registered" (s.16(2)(ग); Rule 20(1)(घ)). | primary, medium | Confirm a blank PAN is acceptable for unregistered buyers on the invoice and in the sales book. | None. |
| **#464 Q1** register keeps every stretch the mode was on | Books are kept per tax period for six years (Rule 23(7)). Nothing addresses switching modes. | medium | Confirm periods with the mode off simply have no register. | Keep every stretch; never drop old periods. |
| **#464 Q2** note register total excludes other tax | Rule 20 asks for the note's amount and its tax. CBMS splits VAT, excise, HST and ESF. | medium | Should the note register's "Total" be the full note amount, with VAT shown separately? | Probably show total including all tax, VAT in its own column. |
| **#464 Q3** foreign-tax-only documents as "exempt" | Exempt means Schedule 1. Zero-rated export is a separate column. A foreign tax (e.g. Indian GST) is not a Nepal exemption. | primary, medium | How should a document carrying only foreign tax be classed: export, or out of scope? | Stop classing them as exempt; route them to export or flag them for staff. |
| **Emailed PDF as print** | The Procedure speaks of printing (छपाइ) once per bill; emailing is not mentioned. | not found | Is the emailed original the first print, so every later download is a copy? | Today an email does not count as a print. Change if the accountant says it should. |
| **IRD CSV Nepali headers** | Schedule 9 as of the 27th amendment: no goods or quantity columns (25th amendment 2080); "निर्यात", not "निकासी" (26th amendment 2081). Removed from the Rules in 2083 in favour of an IRD-set format. | primary, high | Which format does the IRD prescribe after the Economic Act 2083, and does the portal accept an upload? | Change `VatRegisterReport::IRD_COLUMNS` to the current Schedule 9 now: drop goods, quantity and unit; use "निर्यात"; add कुल जम्मा. |
| **Month-end Q11** income ex VAT | Nothing on management reports. | not applicable | Does the accountant need cash receipts split ex VAT? | An extra column, only if asked. |
| **Import Q3** old WHMCS invoice numbers | An issued tax invoice cannot be altered, only corrected by a note (Directive; Rule 20). Invoicing without a supply is penalised (s.29(1ग)). | primary, medium | Did the host report VAT on WHMCS invoices before payment? If so, the cutover needs WHMCS credit notes, not a void and a new Mohar number. | A cutover runbook step only. |

## 15. Gaps Mohar has against these rules (ranked)

1. **No approval path.** Mohar is not on the IRD's software list, and nothing helps a host get a permit (Procedure
   cl. 3, 4). Without one, a host billing from Mohar is in breach of s.14क(1), and the s.29 penalties can reach the
   software's maker as well. *Needs an owner decision: list Mohar with the IRD as its producer, or check whether each
   host may list it as a user (cl. 3(ख) covers only software from abroad without a distributor in Nepal).*
2. **CBMS not built (D5).** It is likely needed for any cloud-hosted install (cl. 4(ङ)), and for hosts over Rs 20 crore.
   ISPs over Rs 5 crore must bill electronically, but P5 does not require CBMS for them. The Schedule 3 application wants a materialised-view report synced to the
   CBMS test server.
3. **Server location.** Nothing checks or warns that VAT mode needs the server in Nepal with an operator registered
   in Nepal (cl. 7).
4. **Sales book layout out of date.** The `ird` export carries removed columns (goods, quantity, unit) and the old
   "निकासी" wording. The 2083 format, which the IRD now sets, is unknown.
5. **Tax invoices paid in foreign currency** don't show the NPR equivalent and NRB rate (Rule 21).
6. **Numbering per fiscal year** is not the default in VAT mode (Procedure 6(ङ); D2).
7. **Invoice fields.** Schedule 5 and 6 fields Mohar does not print: a separate transaction date beside the issue
   date; payment method on every tax invoice (today only when a gateway is set); amount in words; a BS date that is
   always shown (today optional).
8. **Master bill view and reports.** No Schedule 5 view (Is_bill_Active, IS_Bill_Printed, Printed_Time, Printed_by,
   Sync with IRD and so on). No cancelled-bills report, corrected-records report, or per-user audit trail print
   (6(ङ), 6(ज), 6(ञ), 6(ट)). Mohar's activity log and immutability cover the substance but not these named reports.
9. **Return counts.** The register does not give the period's counts of sales invoices, credit notes and debit notes
   that Schedule 10 asks for.
10. **XLSX or XML export** of the bill tables (6(ठ)), which D4 deferred.
11. **CBMS debit notes.** CBMS has no debit-note endpoint, so a later driver must decide how to report Mohar's debit
    notes (likely as bills).

## 16. Not verified

- The text of the Economic Act 2083 (a scan with no text layer). Its VAT changes are taken from IRD's own booklet
  (P8).
- Any IRD-prescribed sales book format after the 2083 removal of Schedule 9, and the portal's upload format (if any).
- The IRD's current list of approved billing software and whether any WHMCS-like or self-hosted product is on it.
- Whether "internet service provider" in the Rs 5 crore notice includes web hosting.
- Whether the 2079/02/20 Rs 10 crore e-billing line still stands beside the 2083 Rs 20 crore CBMS line. The Rs 25
  crore line reported by secondary sources (S2, S3) was not seen in an IRD notice.
- Which NRB rate (buying, selling or mid) Rule 21 means.
- How s.12(8)'s "as prescribed" matching of deposits works in the Rules.
- Whether the Directive's NRB-certification test for online service exports applies after the 2081 Schedule 2.
- Whether hosting is among the goods and services named for the 10% electronic-payment VAT cash-back (s.25(1ख)).
- Any rule on emailed bills as prints, on the period in which a credit note adjusts VAT, and on chargebacks.
- India GST: not researched in this round (the brief is Nepal first).
