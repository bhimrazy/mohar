### Changed

- **The VAT register counts Nepal VAT only.** The VAT column and the "Return figures" sum the tax levels flagged as Nepal VAT. Another tax (India GST, a foreign sales tax) is no longer counted as VAT, and a document whose only tax is another tax shows its whole value as exempt sales. A document that carries tax but was never classified (flags not set) keeps its tax out of VAT and is counted in a new warning above the table; run `php artisan billing:fiscal-vat-split` to classify it.
- **Credit and debit notes copy their invoice's Nepal VAT flags** when issued (two new nullable columns on `tax_notes`, expand only), and are refused while the mode is on for a taxed invoice that was never classified, with a message that names the command. Notes issued earlier have no flags until reclassified.
- The notes panel and the note actions open only when Nepal VAT mode is in effect: switched on and its start day reached.
