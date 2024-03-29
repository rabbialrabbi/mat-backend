<?php

namespace App\Services;

use App\Enums\ApiCustomStatusCode;
use App\Enums\HttpStatusCode;
use Illuminate\Http\Response;
use App\Contracts\ApiBaseServiceInterface;
use JWTAuth;

/**
 * Class ApiBaseService
 * @package App\Services
 */
class ApiBaseService implements ApiBaseServiceInterface
{

    /**
     * Success response method.
     *
     * @param array $result
     * @param $message
     * @param array $pagination
     * @param int $http_status
     * @param int $status_code
     * @return JsonResponse
     */
    public function sendSuccessResponse(
        $result,
        $message,
        $pagination = [],
        $http_status = HttpStatusCode::SUCCESS,
        $status_code = ApiCustomStatusCode::SUCCESS
    ) {
        $response = [
            'status' => 'SUCCESS',
            'status_code' => $status_code,
            'message' => $message,
            'data' => $result
        ];

        if (!empty($pagination)) {
            $response ['pagination'] = $pagination;
        }

        return response()->json($response, $http_status);
    }


    /**
     * Return error response.
     *
     * @param $message
     * @param array $errorMessages
     * @param int $status_code
     * @return Response
     */
    public function sendErrorResponse($message, $errorMessages = [], $status_code = HttpStatusCode::VALIDATION_ERROR)
    {
        $response = [
            'status' => 'FAIL',
            'status_code' => $status_code,
            'message' => $message,
        ];

        if (!empty($errorMessages)) {
            $response['error'] = $errorMessages;
        }

        return response()->json($response, $status_code);
    }


    /**
     * Return Response with pagination
     *
     * @param $items
     * @return array
     */
    public function paginationResponse($items)
    {
        return array(
            'total_items' => $items->total(),
            'current_items' => $items->count(),
            'first_item' => $items->firstItem(),
            'last_item' => $items->lastItem(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'has_more_pages' => $items->hasMorePages(),
        );
    }

    public function getUserId(){
         $user = JWTAuth::parseToken()->authenticate();
         return $user['id'];
    }
    public function getUserInfo(){
        return $user = JWTAuth::parseToken()->authenticate();
    }

    /**
     * Upload image Throw Guzzle
     * @param array $images
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function uploadImageThrowGuzzle(array $images)
    {
        $userId = self::getUserId();

        $output = [];
        $i = 0;
        foreach ($images as $key=>$image){
            $data[$i] = [
                [
                    'name'     => 'image['.$i.'][name]',
                    'contents' => $key,
                ],
                [
                    'name'     => 'image['.$i.'][file]',
                    'contents' => file_get_contents($image),
                    'filename' => $key.'.'.$image->getClientOriginalExtension()
                ],
                [
                    'name'     => 'image['.$i.'][path]',
                    'contents' => 'candidate/candidate_'.$userId.'/',
                ],
            ];
            $output = array_merge($output,$data[$i]);

            $i++;
        }


        $client = new \GuzzleHttp\Client();
        $requestc = $client->post(env('IMAGE_SERVER').'/img',[
            'multipart' => $output
        ]);
        $response = $requestc->getBody();

        return json_decode($response);
    }
}
