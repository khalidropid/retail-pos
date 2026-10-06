<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A domain rule was violated. The message is user-facing Arabic text.
 */
class BusinessException extends RuntimeException
{
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->withErrors(['business' => $this->getMessage()])->withInput();
    }
}
