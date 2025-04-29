<?php

namespace App\Http\Controllers;

use App\Constants\MessageConstants;
use App\Models\Appointment;
use App\Models\Patient;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AppointmentController extends Controller
{
    use ApiResponseTrait;
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        $patient = Patient::find($id);
        if (!$patient) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        $appointments = Appointment::where('patient_id', $id)->with(['doctor', 'patient'])->get();

        return $this->apiResponse($appointments, MessageConstants::INDEX_SUCCESS, 200);
    }



    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string|max:255',
            'time_appointment' => 'required|date|after:now', // تحقق أن الوقت تاريخ وأنه في المستقبل
            'note' =>  'required|string',
            'doctor_id' => 'required|exists:doctors,id',
            'patient_id' => 'required|exists:patients,id',

        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }
        $Appointment = Appointment::create($request->all());

        return $this->apiResponse($Appointment, MessageConstants::STORE_SUCCESS, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $Appointment = Appointment::with(['doctor', 'patient'])->find($id);
        if (!$Appointment) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        return $this->apiResponse($Appointment, MessageConstants::SHOW_SUCCESS, 200);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string|max:255',
            'time_appointment' => 'required|date|after:now',
            'note' => 'required|string',
            'doctor_id' => 'required|exists:doctors,id',
            'patient_id' => 'required|exists:patients,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $appointment = Appointment::find($id);
        if (!$appointment) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        $appointment->update([
            'mobile' => $request->mobile,
            'time_appointment' => $request->time_appointment,
            'password' => $request->password,
            'note' => $request->note,
            'doctor_id' => $request->doctor_id,
            'patient_id' => $request->patient_id,
        ]);

        return $this->apiResponse($appointment, MessageConstants::UPDATE_SUCCESS, 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $Appointment = Appointment::find($id);

        if (!$Appointment) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        $Appointment->delete();

        return $this->apiResponse(null, MessageConstants::DELETE_SUCCESS, 200);   
     }
}
