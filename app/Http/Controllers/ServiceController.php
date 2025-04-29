<?php

namespace App\Http\Controllers;

use App\Constants\MessageConstants;
use App\Models\Service;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceController extends Controller
{
    use ApiResponseTrait;
    // عرض كل الخدمات
    public function index()
    {

        return $this->apiResponse(Service::all(), MessageConstants::INDEX_SUCCESS, 200);
    }

    // إنشاء خدمة جديدة
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->apiResponse($validator->errors(), MessageConstants::QUERY_NOT_EXECUTED, 400);
        }

        $service = Service::create($request->only('title', 'content'));
        return $this->apiResponse($service, MessageConstants::STORE_SUCCESS, 201);
    }

    // عرض خدمة واحدة
    public function show($id)
    {
        $service = Service::find($id);
        if (!$service) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        return $this->apiResponse($service, MessageConstants::SHOW_SUCCESS, 200);
    }

    // تحديث خدمة
    public function update(Request $request, $id)
    {
        $service = Service::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
        ]);

        if ($validator->fails()) {
            return $this->apiResponse($validator->errors(), MessageConstants::QUERY_NOT_EXECUTED, 400);
        }

        $service->update($request->only('title', 'content'));
        return $this->apiResponse($service, MessageConstants::UPDATE_SUCCESS, 201);
    }

    // حذف خدمة
    public function destroy($id)
    {
        $Service = Service::find($id);

        if (!$Service) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        $Service->delete();

        return $this->apiResponse(null, MessageConstants::DELETE_SUCCESS, 200);
    }
}
