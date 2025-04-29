<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DiagnosisController extends Controller
{
    use ApiResponseTrait;
    public function diagnose(Request $request)
    {

        // $output = shell_exec('C:\\laragon\\bin\\python\\python-3.10\\python.exe --version');
        // return "<pre>$output</pre>"; 

        $fever = $request->input('fever');
        $cough = $request->input('cough');
        $throat = $request->input('throat');
        $headache = $request->input('headache');
        $fatigue = $request->input('fatigue');
        $loss_of_smell = $request->input('loss_of_smell');
        $breath_short = $request->input('breath_short');

        $input_data = "$fever,$cough,$throat,$headache,$fatigue,$loss_of_smell,$breath_short";

        $pythonPath = "C:\\laragon\\bin\\python\\python-3.10\\python.exe";  // مسار البايثون الكامل
        $pythonScript = storage_path('app/diagnosis_script.py');

        $process = new Process([
            $pythonPath,
            $pythonScript,
            $input_data
        ]);

        // نحدد البيئة الخاصة بالويندوز
        $process->setEnv([
            'SYSTEMROOT' => 'C:\\Windows',
            'PATH' => 'C:\\Windows\\System32;C:\\Windows;C:\\laragon\\bin\\python\\python-3.10',
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = $process->getOutput();

        $output = trim($process->getOutput()); // <<<<< هذا التعديل المهم

        if ($output == 'Influenza')
            $output = 'الانفلونزا';
        elseif ($output == 'Allergy')
            $output = 'حساسية';
        elseif ($output == 'Throat Infection')
            $output = 'عدوى الحلق';
        elseif ($output == 'COVID-19')
            $output = 'كورونا';
        elseif ($output == 'Tension Headache')
            $output = 'صداع التوتر';
        elseif ($output == 'Common Cold')
            $output = 'زُكام';
        elseif ($output == 'Chest Allergy')
            $output = 'حساسية الصدر';
        elseif ($output == 'Cold')
            $output = 'بردية';
        elseif ($output == 'Sinusitis')
            $output = 'التهاب الجيوب الأنفية';
        elseif ($output == 'Migraine')
            $output = 'صداع نصفي';

        return response()->json(['diagnosis' => $output]); // <<<<< وهون رجعناها JSON مثل الصح
    }
}
