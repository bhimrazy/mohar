<?php

namespace App\Modules\Core\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

#[Signature('objects:ensure-bucket')]
#[Description('Create the object-storage bucket used by the "objects" disk if it does not exist')]
class EnsureObjectBucket extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk('objects');

        if (! $disk instanceof AwsS3V3Adapter) {
            $this->components->info('The objects disk is not S3-backed; nothing to do.');

            return self::SUCCESS;
        }

        /** @var S3Client $client */
        $client = $disk->getClient();
        $bucket = (string) config('filesystems.disks.objects.bucket');

        if ($client->doesBucketExistV2($bucket, false)) {
            $this->components->info("Bucket [{$bucket}] already exists.");

            return self::SUCCESS;
        }

        $client->createBucket(['Bucket' => $bucket]);
        $this->components->info("Bucket [{$bucket}] created.");

        return self::SUCCESS;
    }
}
