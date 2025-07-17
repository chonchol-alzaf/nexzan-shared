<?php

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\QueryException;
use Nexzan\Shared\Exceptions\CustomException;
use Illuminate\Pagination\LengthAwarePaginator;

if (! function_exists('ResponseSuccess')) {
    function ResponseSuccess($data, $message = null)
    {
        $items = $data;
        $meta = null;

        if (isset($data['meta'])) {
            $meta = $data['meta'];
        }

        if (isset($data['items'])) {
            $items = $data['items'];
        }

        $response = [
            'success' => true,
            'message' => __($message ?? 'Success'),
            'resource' => $items,
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        return response()->json($response, Response::HTTP_OK);
    }
}


if (! function_exists('ResponseError')) {
    function ResponseError($message = null, $jsonStatus = Response::HTTP_INTERNAL_SERVER_ERROR, $throwable = null)
    {
        if ($throwable) {
            Log::error($throwable);
            Log::channel('mail')->error($throwable);
        } else {
            Log::error($message);
            Log::channel('mail')->error($message);
        }

        if ($throwable && $throwable instanceof CustomException) {
            $jsonStatus = $throwable->getStatusCode();
            $message = $throwable->getMessage();
        } elseif (
            class_exists('App\\Exceptions\\CloudPanelException') &&
            $throwable instanceof \App\Exceptions\CloudPanelException
        ) {
            $jsonStatus = $throwable->getStatusCode();
            $response = $throwable->getMessage();
            $message = json_decode($response)->message ?? $message;
        } elseif ($throwable && $throwable instanceof QueryException) {
            $message = 'A database error occurred.Please try again';
        }

        if (!is_int($jsonStatus) || $jsonStatus < 100 || $jsonStatus > 599) {
            $jsonStatus = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        return response()->json([
            'success' => false,
            'message' => __($message),
        ], $jsonStatus);
    }
}

if (! function_exists('paginateMetaData')) {
    function paginateMetaData($data)
    {
        if (! ($data instanceof LengthAwarePaginator || $data instanceof Paginator)) {
            return null;
        }

        $total_items = $data->total();
        $per_page = $data->perPage();
        $total_pages = ceil($total_items / $per_page);

        return [
            'current_page' => $data->currentPage(),
            'last_page' => $data->lastPage(),
            'per_page' => $per_page,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'from' => $data->firstItem(),
            'to' => $data->lastItem(),
        ];
    }
}
