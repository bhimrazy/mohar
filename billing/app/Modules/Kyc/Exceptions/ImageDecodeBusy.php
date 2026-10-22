<?php

namespace App\Modules\Kyc\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Another process held the image-decode lock (ImageSanitiser::LOCK) for longer than the wait. An upload shows it as a
 * validation error ("try again"); `kyc:reencode-images` leaves the document for its next run.
 */
class ImageDecodeBusy extends ValidationException {}
