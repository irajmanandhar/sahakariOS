<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/** Money-moving endpoints require a client-generated Idempotency-Key so retries never repeat. */
trait RequiresIdempotencyKey
{
    protected function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
            throw new HttpResponseException(response()->json([
                'message' => 'An Idempotency-Key header (8-100 characters: letters, digits, - _ : .) is required.',
            ], 422));
        }

        return $key;
    }
}
