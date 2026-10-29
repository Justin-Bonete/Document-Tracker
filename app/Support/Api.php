<?php
namespace App\Support;

class Api
{
    public static function ok(mixed $data, int $status = 200, array $meta = [])
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge(['request_id' => request()->attributes->get('request_id')], $meta),
        ], $status);
    }

    public static function error(string $code, string $message, int $status, ?array $details = null)
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }
        return response()->json([
            'error' => $error,
            'meta'  => ['request_id' => request()->attributes->get('request_id')],
        ], $status);
    }
}
