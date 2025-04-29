<?php

namespace App\Http\Controllers;

use App\Constants\MessageConstants;
use App\Models\Patient;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use Tymon\JWTAuth\Facades\JWTAuth;

class PatientController extends Controller
{
    use ApiResponseTrait;

    public function register(Request $request)
    {
        

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:patients',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 400);
        }

        $patient = Patient::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = Auth::guard('patient')->login($patient);

        return response()->json([
            'token' => $token,
            'patient' => $patient
        ]);
    }


    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
        if (! $token = Auth::guard('patient')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return response()->json(['token' => $token]);
    }


    // User logout
    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return $this->apiResponse(Patient::all(),MessageConstants::INDEX_SUCCESS,201);
    }


    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $Patient = Patient::find($id);
        if (!$Patient) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }

        return $this->apiResponse($Patient, MessageConstants::SHOW_SUCCESS, 200);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:patients',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 400);
        }

        $Patient = Patient::find($id);
        if (!$Patient) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }

        $Patient->update($request->all());

        return $this->apiResponse($Patient, MessageConstants::UPDATE_SUCCESS, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $Patient = Patient::find($id);

        if (!$Patient) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        $Patient->delete();

        return $this->apiResponse(null, MessageConstants::DELETE_SUCCESS, 200);
    }
}
