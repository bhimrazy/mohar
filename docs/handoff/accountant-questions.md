# Questions for the accountant

Context: we are building billing software for Nepali web hosts. It sends clients a bill, takes payment (eSewa, Khalti, bank),
charges 13% VAT and keeps a VAT sales register the accountant uses for the monthly return. Everything below is how we
assume things work today. Please confirm, correct or say "ask the tax office". Write your answer under each question.
Source of each item is in brackets for our records.

## 1. Which document is the VAT bill

**1.1 Proforma first, VAT bill on payment.** We give the client an estimate (proforma) before payment, and the VAT
bill is issued, with its legal number, when the money arrives. Is that acceptable for hosting and domains? [A1]
Answer:

**1.2 Part payments.** A client pays part, then the rest later. We date the VAT bill on the final payment, for the full
amount. Is VAT on the earlier part reported in the month it was received? [import Q4]
Answer:

**1.3 Buyer PAN left blank.** If a client has no PAN, may the VAT bill and the register row leave it blank? [A11]
Answer:

**1.4 Bills in AD with a BS date beside them.** Invoices show the English date, with the Nepali (BS) date next to it
if the host wants. The register always shows BS. Will the tax office accept this? [A9]
Answer:

## 2. Numbering and the fiscal year

**2.1 Fiscal-year numbering.** Should bill numbers restart each fiscal year (Shrawan), or run on without a reset? We
run on for now. [fiscal-year numbering, state.md]
Answer:

**2.2 Credit and debit notes.** Same question for the notes: separate number series that never reset, or restart each
fiscal year? [D2]
Answer:

**2.3 Reprints.** When a bill is printed again, does it need to say "copy of original" with a count, even for a small
host? [A7]
Answer:

**2.4 Emailed note as a print.** Does emailing the PDF of a credit or debit note to the client count as a print, so
it needs the "copy" marking and count? [owner question from the #482 review]
Answer:

## 3. Refunds, credit and bad debt

**3.1 Refunds.** A refund of a hosting payment always comes with a credit note against the VAT bill, paid back by
bank, eSewa or Khalti. A refund of money the client simply left as credit needs no note. Correct? [A4]
Answer:

**3.2 Money added to the client's account.** Top-ups are not a VAT sale. VAT is charged on the bill that the credit
later pays. Correct? [A3]
Answer:

**3.3 Chargebacks and reversed wallet payments.** The VAT bill stays as it is until the host decides the money is
lost. Correct? [A5]
Answer:

**3.4 Unpaid bills written off.** The VAT stays on the books and no bad-debt relief is claimed. Correct? [A6]
Answer:

**3.5 Credit notes and other taxes.** On a credit note, does the register's total leave out any tax other than VAT? [#464 register question 2]
Answer:

**3.6 Bills with foreign tax only.** A document that carries only a foreign tax (no Nepal VAT): is it "exempt" in the
register? [#464 register question 3]
Answer:

## 4. The VAT sales register and the monthly return

**4.1 Filing.** You type the monthly totals from our register into the IRD portal. No file upload of the sales book is
needed for a host this size. Correct? [A2]
Answer:

**4.2 When VAT mode changes.** If a host switches VAT mode on and off during the year, should the register show every
period the mode was on, with the dates? [#464 register question 1]
Answer:

**4.3 Register export columns.** The IRD-style CSV has column headers in English. Should they be in Nepali? If yes,
please give the exact wording the office expects. [Nepali labels in the IRD CSV header]
Answer:

**4.4 Month-end income.** Our month-end pack shows cash income as received, VAT included, and the VAT register
shows sales without VAT. Do you also need a cash-received figure without VAT, per payment? [month-end Q11]
Answer:

## 5. Foreign clients and other currencies

**5.1 Which rate.** For a bill in dollars or rupees (INR), which NPR rate counts: the Nepal Rastra Bank rate on the bill
date, the host's own rate table, or the rate on the day the payment arrived? We use the host's stored rate, taken from
the bank's rate, on the numbering date. [D6]
Answer:

**5.2 What counts as an export.** Which bills to foreign clients are zero-rated exports? We only treat a bill as an
export when the host picks an explicit tax rule. [D6]
Answer:

**5.3 No export declaration number.** Services sold abroad have no declaration number, so those columns stay blank.
Correct? [A8]
Answer:

## 6. Electronic billing (CBMS) and where data lives

**6.1 Is the host in scope.** Does web hosting count as an "internet service provider" for the Rs 5 crore turnover line
that requires electronic billing? [CBMS]
Answer:

**6.2 Below the line.** For a host below that line, does billing software need the tax office's permission? [CBMS]
Answer:

**6.3 Data in Nepal.** The rule that billing data must be on a server in Nepal applies only to IRD-approved
e-billing software, not to a host's own billing system below the line. Correct? [A10]
Answer:

## 7. Moving from WHMCS

**7.1 Old invoices already reported.** At the switch, the host voids the unpaid bills in the old system without
claiming VAT on them, and the client pays the new bill, which gets a new legal number on payment. If you already
reported VAT on the old unpaid bills, the same sale is counted twice. Were they reported, and what should the
host do at the switch? [invoice-domain-import Q3]
Answer:
