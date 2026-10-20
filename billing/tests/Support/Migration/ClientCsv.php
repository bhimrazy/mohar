<?php

namespace Tests\Support\Migration;

use App\Modules\Migration\Models\MigrationRun;
use Illuminate\Http\UploadedFile;

/**
 * Client files for the import tests, and the upload a staff member makes with them (the queue runs synchronously).
 */
final class ClientCsv
{
    public const HEADER = ['ID', 'First Name', 'Last Name', 'Company Name', 'Email Address', 'Country', 'Currency', 'Client Group', 'Status'];

    /**
     * @param  list<list<string>>  $rows
     * @param  list<string>  $header
     */
    public static function file(array $rows, array $header = self::HEADER, string $delimiter = ',', string $prefix = '', string $name = 'clients.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test-');
        $handle = fopen($path, 'wb');
        fwrite($handle, $prefix);

        foreach ([$header, ...$rows] as $row) {
            fputcsv($handle, $row, $delimiter, '"', '');
        }

        fclose($handle);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /**
     * A client row in HEADER order.
     */
    public static function row(string $id, string $email, string $first = 'Asha', string $last = 'Rai', string $company = '', string $country = 'NP', string $currency = '', string $group = '', string $status = 'Active'): array
    {
        return [$id, $first, $last, $company, $email, $country, $currency, $group, $status];
    }

    /**
     * Upload a file as the signed-in staff member and return the run.
     *
     * @param  array<string, mixed>  $input
     */
    public static function upload(UploadedFile $file, array $input = []): MigrationRun
    {
        test()->post(route('admin.migration.imports.store'), ['file' => $file, 'source_name' => 'Old billing', 'preset' => 'whmcs', ...$input])
            ->assertSessionHasNoErrors()->assertRedirect();

        return MigrationRun::query()->latest('id')->firstOrFail();
    }
}
