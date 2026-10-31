# Questions for the accountant

Context: we are building billing software for Nepali web hosts. It sends clients a bill, takes payment (eSewa, Khalti, bank),
charges 13% VAT and keeps a VAT sales register the accountant uses for the monthly return. We read the VAT Act, the VAT
Rules, the Electronic Billing Procedure 2074 and the IRD notices (details in
`docs/research/2026-10-31-nepal-vat-ebilling.md`). Where we found an answer, we state it: please confirm or correct it.
Where we did not, the question is open. Write your answer under each item. The source of each item is in brackets for
our records.

## 1. Which document is the VAT bill

**1.1 Proforma first, VAT bill on payment.** We give an estimate (proforma) before payment and issue the VAT bill, with
its legal number, when the money arrives. We found that issuing a bill without supplying the service is penalised
(VAT Act s.29(1ग)), which supports this. Please confirm it is acceptable for hosting and domains. [A1]
Answer:

**1.2 Unpaid renewals while the service runs (new).** The time of supply is the earliest of the bill, the service
being provided, or payment (VAT Act s.6(2)). A hosting renewal often keeps running for some days before it is paid.
Is VAT due from the service date in that case, or is a VAT bill on payment still correct? [time of supply]
Answer:

**1.3 Part payments.** A client pays part, then the rest later. We date the VAT bill on the final payment, for the
full amount. Is VAT on the earlier part reported in the month it was received? [import Q4]
Answer:

**1.4 Buyer PAN left blank.** We found that records show the buyer's number only "if registered" (VAT Act s.16(2);
Rule 20). So we leave it blank when the client has no PAN. Please confirm or correct. [A11]
Answer:

**1.5 BS date on the bill.** The tax period is the BS month (Rule 26) and the IRD's systems use BS dates. The bill
format (Rule 17, Schedule 5) does not say BS or AD. Must the printed VAT bill show the BS date? [A9]
Answer:

## 2. Numbering and the fiscal year

**2.1 Bill numbers.** We found that billing software must number bills in sequence within each fiscal year
(Procedure cl. 6(ङ)). So we will restart (or prefix) the series each Shrawan. Please confirm or correct.
[fiscal-year numbering]
Answer:

**2.2 Credit and debit notes (new detail).** The Rules ask only for a "running number" (Rule 20(1)(क)). Should the
note series also restart each fiscal year, like bills? [D2]
Answer:

**2.3 Reprints.** We found that billing software prints a bill once, and every reprint must say "Copy of Original"
with the print count (Procedure cl. 6(च)). We do this for every host. Please confirm or correct. [A7]
Answer:

**2.4 Emailed PDF as a print.** The rules speak of printing only. Does emailing the PDF of a bill or note count as the
first print, so that later downloads are copies? [#482 review]
Answer:

## 3. Refunds, credit and bad debt

**3.1 Refunds.** We found that any change in the value of a supply needs a credit or debit note (Rule 20). So every
refund of a hosting payment comes with a credit note. A refund of money left as credit needs none. Please confirm or
correct. [A4]
Answer:

**3.2 Top-ups.** We found that a deposit is not taxable until it is matched to a supply (VAT Act s.12(8)). So we
charge VAT on the bill the credit later pays, not on the top-up. Please confirm a wallet top-up is a deposit, not an
advance. [A3]
Answer:

**3.3 Chargebacks and reversed wallet payments.** We found no rule. We leave the VAT bill as it is until the host
decides the money is lost. Correct? [A5]
Answer:

**3.4 Unpaid bills written off.** We found no bad-debt relief in the VAT Act, so the VAT stays. Please confirm. [A6]
Answer:

**3.5 Which month a credit note counts in.** We count a note in its own month, not the bill's. Correct? [F5]
Answer:

**3.6 Credit notes and other taxes.** On a credit note, should the register's total include every tax, with VAT in its
own column? [#464 question 2]
Answer:

**3.7 Bills with foreign tax only.** Exempt supplies are those in Schedule 1 of the VAT Act, so a bill carrying only a
foreign tax is not "exempt". Should it be an export, or left out of the register? [#464 question 3]
Answer:

## 4. The VAT sales register and the monthly return

**4.1 Filing.** We found that a taxpayer with an e-billing permit must upload purchase and sales details with each
return, in the IRD's format (Rule 25(2)). Is an upload needed for this host, and in what format? [A2]
Answer:

**4.2 Sales book format since 2083 (new).** The Rules' sales book (Schedule 9) lost its goods and quantity columns in
2080 and uses "निर्यात" for exports since 2081. The Economic Act 2083 removed the format, so the IRD now sets it. What
format, and which Nepali column headings, does the IRD expect now? [IRD CSV headers]
Answer:

**4.3 When VAT mode changes.** If a host switches VAT mode on and off, the register keeps every period it was on
(records are kept six years, Rule 23(7)). Correct? [#464 question 1]
Answer:

**4.4 Month-end income.** Our month-end pack shows cash received, VAT included, and the register shows sales without
VAT. Do you also need cash received without VAT, per payment? [month-end Q11]
Answer:

## 5. Foreign clients and other currencies

**5.1 Which rate.** We found that when payment is received in foreign currency, the bill shows the NPR amount at the
Nepal Rastra Bank rate of the transaction day (Rule 21). Which NRB rate: buying, selling or the middle? And is the
"transaction day" the payment day or the bill date? [D6]
Answer:

**5.2 What counts as an export.** We found that services to a person outside Nepal with no business or representative
in Nepal are zero-rated (VAT Act Schedule 2, item 2). The text has no foreign-currency condition. The VAT Directive
says online service exports need Nepal Rastra Bank certification of the contract, bill and payment. Does that apply to
zero-rating a hosting bill, or only to refund claims? [D6, NRB certification]
Answer:

**5.3 No export declaration number.** Services sold abroad have no customs declaration, so those columns stay blank.
Correct? [A8]
Answer:

## 6. Electronic billing (CBMS) and where data lives

**6.1 Permission to bill from software.** We found that any electronic bill needs the tax office's prior approval,
at any size (VAT Act s.14क(1)), using IRD-listed software (Procedure cl. 3, 4). Please confirm. What does the host's
tax office need from us to approve this software? [CBMS]
Answer:

**6.2 Is web hosting an "ISP".** Internet service providers with turnover over Rs 5 crore must bill electronically
(IRD notices 2075/11/29 and 2079/02/20). Does a web-hosting company count as one? [CBMS]
Answer:

**6.3 Is a VPS "cloud" (new).** Anyone billing from a "Cloud Base Computing System" must send each bill to CBMS when
issuing it, whatever the turnover (Procedure cl. 4(ङ)). Does a host running this software on its own VPS count? [CBMS]
Answer:

**6.4 Which line applies.** E-billing has been compulsory above Rs 10 crore since 2079 (notice 2079/02/20). E-billing
plus CBMS is compulsory above Rs 20 crore (notice 2083/01/03). Does the Rs 10 crore line still stand? [CBMS]
Answer:

**6.5 Data in Nepal.** We found that billing from a central server needs that server in Nepal, an operator registered
in Nepal, and IRD access (Procedure cl. 7). This applies to anyone billing electronically, not only to "approved"
software. Is a VPS in a Nepali data centre enough? [A10]
Answer:

## 7. Moving from WHMCS

**7.1 Old invoices already reported.** A VAT bill, once issued, can only be corrected by a credit note (VAT Directive;
Rule 20). If VAT was already reported on the old unpaid WHMCS bills, the host must issue credit notes there at the
switch rather than void them. Were they reported? [invoice-domain-import Q3]
Answer:
