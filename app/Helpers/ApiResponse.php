<?php

namespace App\Helpers;

use App\Helpers\ResponseEnum;
use App\Exceptions\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

trait ApiResponse
{
    /**
     * 成功
     * @param mixed $data
     * @param array $codeResponse
     * @return JsonResponse
     */
    public function success($data = null, $codeResponse = ResponseEnum::HTTP_OK): JsonResponse
    {
        return $this->jsonResponse('success', $codeResponse, $data, null);
    }

    /**
     * 失败
     * @param array $codeResponse
     * @param mixed $data
     * @param mixed $error
     * @return JsonResponse
     */
    public function fail($codeResponse = ResponseEnum::HTTP_ERROR, $data = null, $error = null): JsonResponse
    {
        return $this->jsonResponse('fail', $codeResponse, $data, $error);
    }

    /**
     * json响应
     * @param $status
     * @param $codeResponse
     * @param $data
     * @param $error
     * @return JsonResponse
     */
    private function jsonResponse($status, $codeResponse, $data, $error): JsonResponse
    {
        list($code, $message) = $codeResponse;
        return response()
            ->json([
                'status' => $status,
                // 'code'    => $code,
                'message' => $message,
                'data' => $data ?? null,
                'error' => $error,
            ], (int) substr(((string) $code), 0, 3));
    }


    private function paginatedResponse(array $items, array $meta, array $extra = []): JsonResponse
    {
        list($code, $message) = ResponseEnum::HTTP_OK;

        return response()->json(array_merge([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'items' => $items,
                'meta' => $meta,
            ],
            // Legacy aliases kept temporarily so existing clients do not break
            'items' => $items,
            'meta' => $meta,
            'pagination' => $meta,
            'total' => $meta['total'],
            'current_page' => $meta['current_page'],
            'per_page' => $meta['per_page'],
            'last_page' => $meta['last_page'],
            'current' => $meta['current_page'],
            'page_size' => $meta['per_page'],
            'error' => null,
        ], $extra), (int) substr(((string) $code), 0, 3));
    }

    public function paginate(LengthAwarePaginator $page, ?array $items = null, array $extra = []): JsonResponse
    {
        $pageItems = $items ?? $page->items();
        $meta = [
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(),
        ];

        return $this->paginatedResponse($pageItems, $meta, $extra);
    }

    public function paginateItems(array $items, int $total, int $currentPage = 1, int $perPage = 10, array $extra = []): JsonResponse
    {
        $safePerPage = max(1, $perPage);
        $safeCurrentPage = max(1, $currentPage);
        $meta = [
            'total' => max(0, $total),
            'current_page' => $safeCurrentPage,
            'per_page' => $safePerPage,
            'last_page' => max(1, (int) ceil(max(0, $total) / $safePerPage)),
        ];

        return $this->paginatedResponse($items, $meta, $extra);
    }

    /**
     * 业务异常返回
     * @param array $codeResponse
     * @param string $info
     * @throws BusinessException
     */
    public function throwBusinessException(array $codeResponse = ResponseEnum::HTTP_ERROR, string $info = '')
    {
        throw new BusinessException($codeResponse, $info);
    }
}
