<?php

namespace App\Http\Controllers;

use App\Constants\MessageConstants;
use App\Models\Specialty;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SpecialtyController extends Controller
{
    use ApiResponseTrait;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return $this->apiResponse(Specialty::all(), MessageConstants::INDEX_SUCCESS, 200);
    }



    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:specialties',

        ]);

        if ($validator->fails()) {
            return $this->apiResponse($validator->errors(), MessageConstants::QUERY_NOT_EXECUTED, 400);
        }
        $specialty = specialty::create($request->all());

        return $this->apiResponse($specialty, MessageConstants::STORE_SUCCESS, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $Specialty= Specialty::find($id);
        if (!$Specialty) {
            return $this->apiResponse(null,MessageConstants::NOT_FOUND, 404);
        }

        return $this->apiResponse($Specialty, MessageConstants::SHOW_SUCCESS, 200);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:specialties',

        ]);

        if ($validator->fails()) {
            return $this->apiResponse($validator->errors(), MessageConstants::QUERY_NOT_EXECUTED, 400);
        }

        $Specialty = Specialty::find($id);

        if (!$Specialty) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }


        $Specialty->update($request->all());

        return $this->apiResponse($Specialty, MessageConstants::UPDATE_SUCCESS, 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $Specialty = Specialty::find($id);

        if (!$Specialty) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
            $Specialty->delete();

        return $this->apiResponse(null,MessageConstants::DELETE_SUCCESS,200);
    }


}
