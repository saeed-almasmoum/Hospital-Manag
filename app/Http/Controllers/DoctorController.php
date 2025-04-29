<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Constants\MessageConstants;

class DoctorController extends Controller
{

    use ApiResponseTrait; 

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:doctors',
            'password' => 'required|string|min:6',
            'image' => 'nullable|mimes:jpeg,jpg,png',
            'mobile' => 'required|string|max:255',
            'specialty_id' => 'required|exists:specialties,id',
            
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 400);
        }

        $doctor = Doctor::create([
            'name' => $request->name,
            'email' => $request->email,
            'image' => $request->image,
            'mobile' => $request->mobile,
            'specialty_id' => $request->specialty_id,
            'password' => Hash::make($request->password),
        ]);

        $token = Auth::guard('doctor')->login($doctor);

        return response()->json([
            'token' => $token,
            'doctor' => $doctor
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
        if (! $token = Auth::guard('doctor')->attempt($credentials)) {
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
        return $this->apiResponse(Doctor::all(), MessageConstants::INDEX_SUCCESS,200); 
    }



    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $Doctor = Doctor::find($id);
        if (!$Doctor) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }

        return $this->apiResponse($Doctor, MessageConstants::SHOW_SUCCESS, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:doctors',
            'password' => 'required|string|min:6',
            'image' => 'nullable|mimes:jpeg,jpg,png',
            'mobile' => 'required|string|max:255',
            'specialty_id' => 'required|exists:specialties,id',

        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $Doctor = Doctor::find($id);
        if (!$Doctor) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }

        $Doctor->update($request->all());

        return $this->apiResponse($Doctor, MessageConstants::UPDATE_SUCCESS, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $Doctor = Doctor::find($id);

        if (!$Doctor) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        $Doctor->delete();

        return $this->apiResponse(null, MessageConstants::DELETE_SUCCESS, 200);
    }
}
