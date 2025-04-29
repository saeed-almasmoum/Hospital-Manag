<?php

namespace App\Http\Controllers;

use App\Constants\MessageConstants;
use App\Models\AboutUs;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AboutUsController extends Controller
{
    use ApiResponseTrait;
    // عرض بيانات "من نحن"
    public function show()
    {
        $data = AboutUs::first(); // لأننا غالباً نحتاج سجل واحد فقط
        return $this->apiResponse($data, MessageConstants::SHOW_SUCCESS, 200);
    }

    // إنشاء السجل (مرة واحدة فقط غالبًا)
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'hospital_name' => 'required|string|max:255',
            'mobile' => 'nullable|string|unique:about_us,mobile',
            'instgram' => 'nullable|string',
            'facebook' => 'nullable|string',
            'X' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->apiResponse($validator->errors(), MessageConstants::QUERY_NOT_EXECUTED, 400);
        }

        $about = AboutUs::create($request->all());
        return $this->apiResponse($about, MessageConstants::STORE_SUCCESS, 201);
    }

    // تحديث بيانات "من نحن"
    public function update(Request $request, $id)
    {
        $about = AboutUs::find($id);

        if (!$about) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }

        $validator = Validator::make($request->all(), [
            'hospital_name' => 'sometimes|required|string|max:255',
            'mobile' => 'nullable|string|unique:about_us,mobile,' . $id,
            'instgram' => 'nullable|string',
            'facebook' => 'nullable|string',
            'X' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->apiResponse($validator->errors(), MessageConstants::QUERY_NOT_EXECUTED, 400);
        }

        $about->update($request->all());
        return $this->apiResponse($about, MessageConstants::UPDATE_SUCCESS, 201);
    }

    // حذف السجل (نادراً ما تحتاجه)
    public function destroy($id)
    {
        $AboutUs = AboutUs::find($id);

        if (!$AboutUs) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        $AboutUs->delete();

        return $this->apiResponse(null, MessageConstants::DELETE_SUCCESS, 200);
    }
}
