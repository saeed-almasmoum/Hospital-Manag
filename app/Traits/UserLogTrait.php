<?php

namespace App\Traits;

use App\Models\UserLog;
use App\Constants\ModelTypeConstants;

trait UserLogTrait
{
    /**
     * سجل نشاط المستخدم.
     *
     * @param int $userId
     * @param int $modelId
     * @param int $modelType
     * @param int $operation
     * @param string $date
     * @return void
     */
    public function logUserActivity(int $userId, int $modelId, int $modelType, int $operation, string $date = null): void
    {
        // إنشاء سجل جديد لنشاط المستخدم
        $log = new UserLog();
        // تعيين بيانات السجل
        $log->user_id = $userId;
        $log->model_id = $modelId;
        $log->model_type = $modelType;
        $log->operation = $operation;
        $log->date = $date ?: now(); // تعيين تاريخ السجل، إذا لم يتم توفير التاريخ، سيتم استخدام التاريخ الحالي
        $log->save(); // حفظ السجل في قاعدة البيانات
    }

    /**
     * سجل نشاط المستخدم لإنشاء نموذج.
     *
     * @param int $userId
     * @param int $modelId
     * @param int $modelType
     * @param string $date
     * @return void
     */
    public function logCreation(int $userId, int $modelId, int $modelType, string $date = null): void
    {
        // استخدام الوظيفة logUserActivity() لتسجيل إنشاء النموذج
        $this->logUserActivity($userId, $modelId, $modelType, ModelTypeConstants::OPERATION_CREATE, $date);
    }

    /**
     * سجل نشاط المستخدم لتحديث نموذج.
     *
     * @param int $userId
     * @param int $modelId
     * @param int $modelType
     * @param string $date
     * @return void
     */
    public function logUpdate(int $userId, int $modelId, int $modelType, string $date = null): void
    {
        // استخدام الوظيفة logUserActivity() لتسجيل تحديث النموذج
        $this->logUserActivity($userId, $modelId, $modelType, ModelTypeConstants::OPERATION_UPDATE, $date);
    }
}
