<?php

namespace App\Traits;

trait ApiResponseTrait
{
    public function apiResponse($data = null, $message = null, $status = null)
    {

        // يتم استخدام هذا الدالة لإرجاع الاستجابة للطلبات API
        // تقوم الدالة بإنشاء مصفوفة تحتوي على البيانات المطلوبة للرد
        // البيانات: يتم تمرير البيانات المراد إرجاعها في المصفوفة 'data'
        // الرسالة: يمكن تمرير رسالة لوصف الاستجابة في المصفوفة 'message'
        // الحالة: يتم تمرير حالة الاستجابة (مثل 200 للنجاح أو 404 للعثور على طلب غير موجود) في المصفوفة 'status'
        // يتم بناء الاستجابة باستخدام الدالة response() من Laravel وتمرير المصفوفة وحالة الاستجابة
        // ومن ثم يتم إرجاع الاستجابة المناسبة إلى المستخدم

        $array = [
            'data' => $data,
            'message' => $message,
            'status' => $status,
        ];

        return response($array, $status);
    }
}
