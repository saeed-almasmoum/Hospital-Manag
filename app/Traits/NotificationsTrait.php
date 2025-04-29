<?php

namespace App\Traits;

use App\Constants\OrderDeliveryTypes;
use App\Events\Event;
use App\Http\Controllers\Reports\TrialBalanceReportController;
use App\Jobs\UsersNotifications;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Partner;
use App\Models\PartnersExpensesBond;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Request;

trait NotificationsTrait
{
    use ReportTrait;
    /**
     * تقوم هذه الدالة بإدارة إشعارات الطلبات بناءً على العملية المقدمة.
     *
     * @param  \App\Models\Order  $order   الطلب المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، تحقق، تحويل إلى شراء، تحويل إلى مبيع، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleOrderNotifications($order, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);

        // رسالة للمسؤول
        $adminMessage = '';
        // رسالة للمستخدم
        $userMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة الحدث
        $eventChannel = '';
        // قناة المسؤول
        $adminChannel = '';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء طلب توريد مواد رقم {$order->code} من قبل {$user->first_name} {$user->last_name}";
                // رسالة للمستخدم عند إنشاء الطلب
                $userMessage = "يجب تدقيق طلب توريد المواد رقم {$order->code}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'OrderCreated';
                // قناة الحدث عند إنشاء الطلب
                $eventChannel = 'Orders';
                // قناة المسؤول عند إنشاء الطلب
                $adminChannel = 'Admin';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل طلب توريد مواد رقم {$order->code} من قبل {$user->first_name} {$user->last_name}";
                // رسالة للمستخدم عند تعديل الطلب
                $userMessage = "يجب تدقيق طلب توريد المواد رقم {$order->code}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'OrderUpdated';
                // قناة الحدث عند تعديل الطلب
                $eventChannel = 'Orders';
                // قناة المسؤول عند تعديل الطلب
                $adminChannel = 'Admin';
                break;
            case 'check':
                $creationTime = $order->created_at;
                $checkingTime = now();
                $duration = $creationTime->diff($checkingTime);
                $durationString = $duration->format('%h ساعة , %i دقيقة , %s ثانية');

                // رسالة للمسؤول عند تدقيق الطلب
                $adminMessage = "تم تدقيق طلب توريد مواد رقم {$order->code} من قبل {$user->first_name} {$user->last_name}, مدة التدقيق $durationString";

                // نوع الحدث عند تدقيق الطلب
                $eventType = 'OrderChecked';
                // قناة المسؤول عند تدقيق الطلب
                $adminChannel = 'Admin';
                break;
            case 'deliver':
                // رسالة للمسؤول عند تسليم الطلب

                // تحديد نوع التسليم (مكتب الشحن أو العميل)
                $deliveryType = $order->delivery()->latest()->first()->type;

                if ($deliveryType == OrderDeliveryTypes::SHIPPING_OFFICE) {
                    // إنشاء الرسالة عند التسليم إلى مكتب الشحن
                    $adminMessage = "تم تسليم طلب توريد مواد رقم {$order->code} إلى مكتب شحن {$order->shipping_office} من قبل {$user->first_name} {$user->last_name}";
                } elseif ($deliveryType == OrderDeliveryTypes::CUSTOMER) {
                    // إنشاء الرسالة عند التسليم إلى الزبون
                    $adminMessage = "تم تسليم طلب توريد مواد رقم {$order->code} إلى الزبون {$order->customer->name} من قبل {$user->first_name} {$user->last_name}";
                } elseif ($deliveryType == OrderDeliveryTypes::INTERNAL) {
                    // إنشاء الرسالة عند التسليم إلى الزبون
                    $adminMessage = "تم تسليم طلب توريد مواد رقم {$order->code} داخلياً من قبل {$user->first_name} {$user->last_name}";
                }
                // نوع الحدث عند تسليم الطلب
                $eventType = 'OrderDelivered';
                // قناة المسؤول عند تسليم الطلب
                $adminChannel = 'Admin';
                break;
            case 'convert-to-purchase':
                // رسالة للمسؤول عند تحويل الطلب إلى شراء
                $adminMessage = "تم تحويل طلب توريد مواد رقم {$order->code} إلى فاتورة شراء رقم {$order->purchase_invoice_code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تحويل الطلب إلى شراء
                $eventType = 'OrderConvertedToPurchase';
                // قناة المسؤول عند تحويل الطلب إلى شراء
                $adminChannel = 'Admin';
                break;
            case 'convert-to-in-storehouse':
                // رسالة للمسؤول عند تحويل الطلب إلى مبيع
                $adminMessage = "تم تحويل طلب توريد مواد رقم {$order->code} إلى إدخال مستودع رقم {$order->in_storehouse_code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تحويل الطلب إلى مبيع
                $eventType = 'OrderConvertedToInStorehouse';
                // قناة المسؤول عند تحويل الطلب إلى مبيع
                $adminChannel = 'Admin';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة طلب توريد مواد رقم {$order->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'OrderArchived';
                // قناة المسؤول عند أرشفة الطلب
                $adminChannel = 'Admin';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة طلب توريد مواد رقم {$order->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'OrderRestored';
                // قناة المسؤول عند استعادة الطلب
                $adminChannel = 'Admin';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف طلب توريد مواد رقم {$order->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'OrderDeleted';
                // قناة المسؤول عند حذف الطلب
                $adminChannel = 'Admin';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $notificationType = 'طلبات توريد مواد';
        $admins = User::notificationLogsAdmins($notificationType)->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $order->id, 'type' => 'order', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $order->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }

        // إذا كانت العملية إضافة أو تحديث الطلب
        if ($operation == 'store' || $operation == 'update') {
            // إستدعاء المهمة الخاصة بتولي ارسال الاشعارات للمستخدمين من أجل التدقيق
            UsersNotifications::dispatch($notificationType, $order, 'order', $eventType, $eventChannel, $userMessage);
        }
    }

    /**
     * تقوم هذه الدالة بإدارة إشعارات الطلبات بناءً على العملية المقدمة.
     *
     * @param  \App\Models\Order  $order   الطلب المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، تحقق، تحويل إلى شراء، تحويل إلى مبيع، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleOrderWhatsAppNotifications($order, $operation, $id)
    {
        // الحصول على معلومات المستخدم الحالي
        $customer = Customer::find($id);

        // رسالة للمسؤول
        $adminMessage = '';
        // رسالة للمستخدم
        $customerMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة الحدث
        $eventChannel = '';
        // قناة المسؤول
        $adminChannel = '';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء طلب توريد مواد رقم {$order->code}   من قبل الزبون على الواتس اب {$customer->name}";
                // رسالة للمستخدم عند إنشاء الطلب
                $customerMessage = "يجب تدقيق طلب توريد المواد رقم {$order->code}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'OrderCreated';
                // قناة الحدث عند إنشاء الطلب
                $eventChannel = 'Orders';
                // قناة المسؤول عند إنشاء الطلب
                $adminChannel = 'Admin';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $notificationType = 'طلبات توريد مواد';
        $admins = User::notificationLogsAdmins($notificationType)->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $order->id, 'type' => 'order', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $order->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }

        // إذا كانت العملية إضافة أو تحديث الطلب
        if ($operation == 'store' || $operation == 'update') {
            // إستدعاء المهمة الخاصة بتولي ارسال الاشعارات للمستخدمين من أجل التدقيق
            UsersNotifications::dispatch($notificationType, $order, 'order', $eventType, $eventChannel, $customerMessage);
        }
    }


    /**
     * تقوم هذه الدالة بإدارة إشعارات إدخال و إخراج المستودع بناءً على العملية المقدمة.
     *
     * @param  \App\Models\InOutStorehouse  $inOutStorehouse   الطلب المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، تحقق، تحويل إلى شراء، تحويل إلى مبيع، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleInOutStorehouseNotifications($inOutStorehouse, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // رسالة للمستخدم
        $userMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة الحدث
        $eventChannel = '';
        // قناة المسؤول
        $adminChannel = '';

        if ($inOutStorehouse->type == 1) {
            $messageType = 'إدخال مستودع';
            $dataType = 'in-storehouse';
        } else {
            $messageType = 'إخراج مستودع';
            $dataType = 'out-storehouse';
        }

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء {$messageType} رقم {$inOutStorehouse->code} من قبل {$user->first_name} {$user->last_name}";
                // رسالة للمستخدم عند إنشاء الطلب
                $userMessage = "يجب تدقيق {$messageType} رقم {$inOutStorehouse->code}";
                // نوع الحدث عند إنشاء الطلب
                if ($inOutStorehouse->type == 1) {
                    $eventType = 'InStorehouseCreated';
                } else {
                    $eventType = 'OutStorehouseCreated';
                }
                // قناة الحدث عند إنشاء الطلب
                $eventChannel = 'InOutStorehouses';
                // قناة المسؤول عند إنشاء الطلب
                $adminChannel = 'Admin';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل {$messageType} رقم {$inOutStorehouse->code} من قبل {$user->first_name} {$user->last_name}";
                // رسالة للمستخدم عند تعديل الطلب
                $userMessage = "يجب تدقيق {$messageType} رقم {$inOutStorehouse->code}";
                // نوع الحدث عند تعديل الطلب
                if ($inOutStorehouse->type == 1) {
                    $eventType = 'InStorehouseUpdated';
                } else {
                    $eventType = 'OutStorehouseUpdated';
                }
                // قناة الحدث عند تعديل الطلب
                $eventChannel = 'InOutStorehouses';
                // قناة المسؤول عند تعديل الطلب
                $adminChannel = 'Admin';
                break;
            case 'check':
                $creationTime = $inOutStorehouse->created_at;
                $checkingTime = now();
                $duration = $creationTime->diff($checkingTime);
                $durationString = $duration->format('%h ساعة , %i دقيقة , %s ثانية');

                // رسالة للمسؤول عند تدقيق الطلب
                $adminMessage = "تم تدقيق {$messageType} رقم {$inOutStorehouse->code} من قبل {$user->first_name} {$user->last_name}, مدة التدقيق $durationString";

                // نوع الحدث عند تدقيق الطلب
                if ($inOutStorehouse->type == 1) {
                    $eventType = 'InStorehouseChecked';
                } else {
                    $eventType = 'OutStorehouseChecked';
                }
                // قناة المسؤول عند تدقيق الطلب
                $adminChannel = 'Admin';
                break;
            case 'convert-to-purchase':
                // رسالة للمسؤول عند تحويل الطلب إلى شراء
                $adminMessage = "تم تحويل {$messageType} رقم {$inOutStorehouse->code} إلى فاتورة شراء رقم {$inOutStorehouse->purchase_invoice_code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تحويل الطلب إلى شراء
                if ($inOutStorehouse->type == 1) {
                    $eventType = 'InStorehouseConvertedToPurchase';
                } else {
                    $eventType = 'OutStorehouseConvertedToPurchase';
                }
                // قناة المسؤول عند تحويل الطلب إلى شراء
                $adminChannel = 'Admin';
                break;
            case 'convert-to-sale':
                // رسالة للمسؤول عند تحويل الطلب إلى مبيع
                $adminMessage = "تم تحويل {$messageType} رقم {$inOutStorehouse->code} إلى فاتورة مبيع رقم {$inOutStorehouse->sale_invoice_code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تحويل الطلب إلى مبيع
                if ($inOutStorehouse->type == 1) {
                    $eventType = 'InStorehouseConvertedToSale';
                } else {
                    $eventType = 'OutStorehouseConvertedToSale';
                }
                // قناة المسؤول عند تحويل الطلب إلى مبيع
                $adminChannel = 'Admin';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة {$messageType} رقم {$inOutStorehouse->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                if ($inOutStorehouse->type == 1) {
                    $eventType = 'InStorehouseArchived';
                } else {
                    $eventType = 'OutStorehouseArchived';
                }
                // قناة المسؤول عند أرشفة الطلب
                $adminChannel = 'Admin';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة {$messageType} رقم {$inOutStorehouse->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                if ($inOutStorehouse->type == 1) {
                    $eventType = 'InStorehouseRestored';
                } else {
                    $eventType = 'OutStorehouseRestored';
                }
                // قناة المسؤول عند استعادة الطلب
                $adminChannel = 'Admin';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف {$messageType} رقم {$inOutStorehouse->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                if ($inOutStorehouse->type == 1) {
                    $eventType = 'InStorehouseDeleted';
                } else {
                    $eventType = 'OutStorehouseDeleted';
                }
                // قناة المسؤول عند حذف الطلب
                $adminChannel = 'Admin';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $notificationType = 'إدخال و إخراج المستودع';
        $admins = User::notificationLogsAdmins($notificationType)->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $inOutStorehouse->id, 'type' => $dataType, 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $inOutStorehouse->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }

        // إذا كانت العملية إضافة أو تحديث الطلب
        if ($operation == 'store' || $operation == 'update') {
            // إستدعاء المهمة الخاصة بتولي ارسال الاشعارات للمستخدمين من أجل التدقيق
            UsersNotifications::dispatch($notificationType, $inOutStorehouse, $dataType, $eventType, $eventChannel, $userMessage);
        }
    }


    /**
     * تقوم هذه الدالة بإدارة إشعارات طلبات التجهيز الداخلي بناءً على العملية المقدمة.
     *
     * @param  \App\Models\InternalOrder  $internalOrder   الطلب المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، تحقق، تحويل إلى شراء، تحويل إلى مبيع، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleInternalOrderNotifications($internalOrder, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // رسالة للمستخدم
        $userMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة الحدث
        $eventChannel = '';
        // قناة المسؤول
        $adminChannel = '';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء طلب تجهيز داخلي رقم {$internalOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // رسالة للمستخدم عند إنشاء الطلب
                $userMessage = "يجب تجهيز طلب تجهيز داخلي رقم {$internalOrder->code}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'InternalOrderCreated';
                // قناة الحدث عند إنشاء الطلب
                $eventChannel = 'InternalOrders';
                // قناة المسؤول عند إنشاء الطلب
                $adminChannel = 'Admin';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل طلب تجهيز داخلي رقم {$internalOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // رسالة للمستخدم عند تعديل الطلب
                $userMessage = "يجب تجهيز طلب تجهيز داخلي رقم {$internalOrder->code}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'InternalOrderUpdated';
                // قناة الحدث عند تعديل الطلب
                $eventChannel = 'InternalOrders';
                // قناة المسؤول عند تعديل الطلب
                $adminChannel = 'Admin';
                break;
            case 'prepare':
                $creationTime = $internalOrder->created_at;
                $preparingTime = now();
                $duration = $creationTime->diff($preparingTime);
                $durationString = $duration->format('%h ساعة , %i دقيقة , %s ثانية');

                // رسالة للمسؤول عند تجهيز الطلب
                $adminMessage = "تم تجهيز طلب تجهيز داخلي رقم {$internalOrder->code} من قبل {$user->first_name} {$user->last_name}, مدة التجهيز $durationString";
                // نوع الحدث عند تجهيز الطلب
                $eventType = 'InternalOrderPrepared';
                // قناة المسؤول عند تجهيز الطلب
                $adminChannel = 'Admin';
                break;
            case 'convert-to-sale':
                // رسالة للمسؤول عند تحويل الطلب إلى مبيع
                $adminMessage = "تم تحويل طلب تجهيز داخلي رقم {$internalOrder->code} إلى فاتورة مبيع رقم {$internalOrder->sale_invoice_code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تحويل الطلب إلى مبيع
                $eventType = 'InternalOrderConvertedToSale';
                // قناة المسؤول عند تحويل الطلب إلى مبيع
                $adminChannel = 'Admin';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة طلب تجهيز داخلي رقم {$internalOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'InternalOrderArchived';
                // قناة المسؤول عند أرشفة الطلب
                $adminChannel = 'Admin';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة طلب تجهيز داخلي رقم {$internalOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'InternalOrderRestored';
                // قناة المسؤول عند استعادة الطلب
                $adminChannel = 'Admin';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف طلب تجهيز داخلي رقم {$internalOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'InternalOrderDeleted';
                // قناة المسؤول عند حذف الطلب
                $adminChannel = 'Admin';
                break;
        }

        $notificationType = 'طلبات التجهيز الداخلي';

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins($notificationType)->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $internalOrder->id, 'type' => 'internal-order', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $internalOrder->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }

        // إذا كانت العملية إضافة أو تحديث الطلب
        if ($operation == 'store' || $operation == 'update') {
            // إستدعاء المهمة الخاصة بتولي ارسال الاشعارات للمستخدمين من أجل التدقيق
            UsersNotifications::dispatch($notificationType, $internalOrder, 'internal-order', $eventType, $eventChannel, $userMessage);
        }
    }

    /**
     * تقوم هذه الدالة بإدارة إشعارات عروض الأسعار بناءً على العملية المقدمة.
     *
     * @param  \App\Models\OfferPrice  $offerPrice  العرض المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، ,تحويل لمبيع, أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleOfferPriceNotifications($offerPrice, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء عرض سعر رقم {$offerPrice->code} للزبون {$offerPrice->customer_name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'OfferPriceCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل عرض سعر رقم {$offerPrice->code} للزبون {$offerPrice->customer_name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'OfferPriceUpdated';
                break;
            case 'convert-to-sale':
                // رسالة للمسؤول عند تحويل الطلب إلى مبيع
                $adminMessage = "تم تحويل عرض سعر رقم {$offerPrice->code} إلى فاتورة مبيع رقم {$offerPrice->sale_invoice_code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تحويل الطلب إلى مبيع
                $eventType = 'OfferPriceConvertedToSale';
                // قناة المسؤول عند تحويل الطلب إلى مبيع
                $adminChannel = 'Admin';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة عرض سعر رقم {$offerPrice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'OfferPriceArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة عرض سعر رقم {$offerPrice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'OfferPriceRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف عرض سعر رقم {$offerPrice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'OfferPriceDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('عروض الأسعار')->get();
    
        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $offerPrice->id, 'type' => 'offer-price', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $offerPrice->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }
    }


    /**
     * تقوم هذه الدالة بإدارة إشعارات فواتير الشراء بناءً على العملية المقدمة.
     *
     * @param  \App\Models\PurchaseInvoice  $purchaseInvoice   الفاتورة المراد إدارة إشعاراتها
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، تحقق، تحويل إلى شراء، تحويل إلى مبيع، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handlePurchaseNotifications($purchaseInvoice, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        $convertedFromOrder = false;

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                if ($purchaseInvoice->customer_id) {
                    $adminMessage = "تم إنشاء فاتورة شراء رقم {$purchaseInvoice->code} للزبون {$purchaseInvoice->customer->name} بقيمة {$purchaseInvoice->total_net_price} {$purchaseInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                } elseif ($purchaseInvoice->supplier_id) {
                    $adminMessage = "تم إنشاء فاتورة شراء رقم {$purchaseInvoice->code} للمورد {$purchaseInvoice->supplier->name} بقيمة {$purchaseInvoice->total_net_price} {$purchaseInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                }
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'PurchaseInvoiceCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                if ($purchaseInvoice->customer_id) {
                    $adminMessage = "تم تعديل فاتورة شراء رقم {$purchaseInvoice->code} للزبون {$purchaseInvoice->customer->name} بقيمة {$purchaseInvoice->total_net_price} {$purchaseInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                } elseif ($purchaseInvoice->supplier_id) {
                    $adminMessage = "تم تعديل فاتورة شراء رقم {$purchaseInvoice->code} للمورد {$purchaseInvoice->supplier->name} بقيمة {$purchaseInvoice->total_net_price} {$purchaseInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                }                // نوع الحدث عند تعديل الطلب
                $eventType = 'PurchaseInvoiceUpdated';
                break;
            case 'convert-to-sale':
                // رسالة للمسؤول عند تحويل الطلب إلى مبيع
                $adminMessage = "تم تحويل فاتورة شراء رقم {$purchaseInvoice->code} إلى فاتورة مبيع رقم {$purchaseInvoice->sale_invoice_code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تحويل الطلب إلى مبيع
                $eventType = 'PurchaseInvoiceConvertedToSale';

                $convertedFromOrder = $purchaseInvoice->convertedFrom && $purchaseInvoice->convertedFrom->convertible_type === 'Order';

                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة فاتورة شراء رقم {$purchaseInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'PurchaseInvoiceArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة فاتورة شراء رقم {$purchaseInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'PurchaseInvoiceRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف فاتورة شراء رقم {$purchaseInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'PurchaseInvoiceDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('فواتير الشراء')->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, [
            'id' => $purchaseInvoice->id,
            'type' => 'purchase-invoice',
            'message' => $adminMessage,
            'converted_from_order' => $convertedFromOrder
        ]));
        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $purchaseInvoice->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }
    }


    /**
     * تقوم هذه الدالة بإدارة إشعارات فواتير مرتجع الشراء بناءً على العملية المقدمة.
     *
     * @param  \App\Models\ReturnPurchaseInvoice  $returnPurchaseInvoice   الفاتورة المراد إدارة إشعاراتها
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، تحقق، تحويل إلى شراء، تحويل إلى مبيع، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleReturnPurchaseNotifications($returnPurchaseInvoice, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                if ($returnPurchaseInvoice->customer_id) {
                    $adminMessage = "تم إنشاء فاتورة مرتجع شراء رقم {$returnPurchaseInvoice->code} للزبون {$returnPurchaseInvoice->customer->name} بقيمة {$returnPurchaseInvoice->total_net_price} {$returnPurchaseInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                } elseif ($returnPurchaseInvoice->supplier_id) {
                    $adminMessage = "تم إنشاء فاتورة مرتجع شراء رقم {$returnPurchaseInvoice->code} للمورد {$returnPurchaseInvoice->supplier->name} بقيمة {$returnPurchaseInvoice->total_net_price} {$returnPurchaseInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                }
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'ReturnPurchaseInvoiceCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                if ($returnPurchaseInvoice->customer_id) {
                    $adminMessage = "تم تعديل فاتورة مرتجع شراء رقم {$returnPurchaseInvoice->code} للزبون {$returnPurchaseInvoice->customer->name} بقيمة {$returnPurchaseInvoice->total_net_price} {$returnPurchaseInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                } elseif ($returnPurchaseInvoice->supplier_id) {
                    $adminMessage = "تم تعديل فاتورة مرتجع شراء رقم {$returnPurchaseInvoice->code} للمورد {$returnPurchaseInvoice->supplier->name} بقيمة {$returnPurchaseInvoice->total_net_price} {$returnPurchaseInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                }                // نوع الحدث عند تعديل الطلب
                $eventType = 'ReturnPurchaseInvoiceUpdated';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة فاتورة مرتجع شراء رقم {$returnPurchaseInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'ReturnPurchaseInvoiceArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة فاتورة مرتجع شراء رقم {$returnPurchaseInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'ReturnPurchaseInvoiceRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف فاتورة مرتجع شراء رقم {$returnPurchaseInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'ReturnPurchaseInvoiceDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('فواتير مرتجع الشراء')->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $returnPurchaseInvoice->id, 'type' => 'return-purchase-invoice', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $returnPurchaseInvoice->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }
    }

    /**
     * تقوم هذه الدالة بإدارة إشعارات فواتير المبيع بناءً على العملية المقدمة.
     *
     * @param  \App\Models\SaleInvoice  $saleInvoice   الفاتورة المراد إدارة إشعاراتها
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، تحقق، تحويل إلى شراء، تحويل إلى مبيع، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleSaleNotifications($saleInvoice, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء فاتورة مبيع رقم {$saleInvoice->code} للزبون {$saleInvoice->customer->name} بقيمة {$saleInvoice->total_net_price} {$saleInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'SaleInvoiceCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل فاتورة مبيع رقم {$saleInvoice->code} للزبون {$saleInvoice->customer->name} بقيمة {$saleInvoice->total_net_price} {$saleInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'SaleInvoiceUpdated';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة فاتورة مبيع رقم {$saleInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'SaleInvoiceArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة فاتورة مبيع رقم {$saleInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'SaleInvoiceRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف فاتورة مبيع رقم {$saleInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'SaleInvoiceDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('فواتير المبيع')->get();
        
        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $saleInvoice->id, 'type' => 'sale-invoice', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $saleInvoice->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }

        if($operation == 'store' || $operation == 'update'){

            // معالجة الحوالات النقدية وإخطار المستخدمين
            $moneyTransferNotifications = [];
            foreach ($saleInvoice->moneyTransfers as $moneyTransfer) {
                $moneyTransferUser = $moneyTransfer->user;
                $message = "يجب استلام حوالة بقيمة {$moneyTransfer->amount} {$saleInvoice->currency->name}";
                $moneyTransferNotifications[] = [
                'user_id' => $moneyTransferUser->id,
                'message' => $message,
            ];

            // إنشاء سجل إشعار للمستخدمين المعنيين
            $moneyTransferUser->notificationLogs()->create([
                'message' => $message,
                'user_id' => $moneyTransferUser->id,
            ]);
        }
        
        // بث حدث تحويلات الأموال مع البيانات المجمعة
        event(new Event('MoneyTransfers', 'MoneyTransfers', ['notifications' => $moneyTransferNotifications]));
        }
    }


    /**
     * تقوم هذه الدالة بإدارة إشعارات فواتير مرتجع المبيع بناءً على العملية المقدمة.
     *
     * @param  \App\Models\ReturnSaleInvoice  $returnSaleInvoice   الفاتورة المراد إدارة إشعاراتها
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleReturnSaleNotifications($returnSaleInvoice, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء فاتورة مرتجع المبيع رقم {$returnSaleInvoice->code} للزبون {$returnSaleInvoice->customer->name} بقيمة {$returnSaleInvoice->total_net_price} {$returnSaleInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'ReturnSaleInvoiceCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل فاتورة مرتجع المبيع رقم {$returnSaleInvoice->code} للزبون {$returnSaleInvoice->customer->name} بقيمة {$returnSaleInvoice->total_net_price} {$returnSaleInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'ReturnSaleInvoiceUpdated';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة فاتورة مرتجع المبيع رقم {$returnSaleInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'ReturnSaleInvoiceArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة فاتورة مرتجع المبيع رقم {$returnSaleInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'ReturnSaleInvoiceRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف فاتورة مرتجع المبيع رقم {$returnSaleInvoice->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'ReturnSaleInvoiceDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('فواتير مرتجع المبيع')->get();


        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $returnSaleInvoice->id, 'type' => 'return-sale-invoice', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $returnSaleInvoice->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }
    }

    /**
     * تقوم هذه الدالة بإدارة إشعارات سندات القبض بناءً على العملية المقدمة.
     *
     * @param  \App\Models\ReceiptBond  $receiptBond  السند المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleReceiptNotifications($receiptBond, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء سند قبض رقم {$receiptBond->code} بقيمة {$receiptBond->amount} {$receiptBond->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'ReceiptBondCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل سند قبض رقم {$receiptBond->code} بقيمة {$receiptBond->amount} {$receiptBond->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'ReceiptBondUpdated';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة سند قبض رقم {$receiptBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'ReceiptBondArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة سند قبض رقم {$receiptBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'ReceiptBondRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف سند قبض رقم {$receiptBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'ReceiptBondDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('سندات القبض')->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $receiptBond->id, 'type' => 'receipt-bond', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $receiptBond->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }
    }


    /**
     * تقوم هذه الدالة بإدارة إشعارات سندات الفع بناءً على العملية المقدمة.
     *
     * @param  \App\Models\PayBond  $payBond  السند المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handlePayNotifications($payBond, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء سند دفع رقم {$payBond->code} بقيمة {$payBond->amount} {$payBond->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'PayBondCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل سند دفع رقم {$payBond->code} بقيمة {$payBond->amount} {$payBond->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'PayBondUpdated';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة سند دفع رقم {$payBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'PayBondArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة سند دفع رقم {$payBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'PayBondRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف سند دفع رقم {$payBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'PayBondDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('سندات الدفع')->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $payBond->id, 'type' => 'pay-bond', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $payBond->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }
    }


    /**
     * تقوم هذه الدالة بإدارة إشعارات سندات الحوالة بناءً على العملية المقدمة.
     *
     * @param  \App\Models\UserMoneyTransferBond  $bonds  السند المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleUserMoneyTransferNotifications($bond, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء سند حوالة رقم {$bond->code} للمستخدم {$bond->user->first_name} {$bond->user->last_name} بقيمة {$bond->amount} {$bond->saleInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'UserMoneyTransferBondCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل سند حوالة رقم {$bond->code} للمستخدم {$bond->user->first_name} {$bond->user->last_name} بقيمة {$bond->amount} {$bond->saleInvoice->currency->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'UserMoneyTransferBondUpdated';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة سند حوالة رقم {$bond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'UserMoneyTransferBondArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة سند حوالة رقم {$bond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'UserMoneyTransferBondRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف سند حوالة رقم {$bond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'UserMoneyTransferBondDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('سندات الحوالة')->get();
        

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $bond->id, 'type' => 'user-money-transfer-bond', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $bond->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }

        if ($operation == 'store' || $operation == 'update') {
            // معالجة الحوالات النقدية وإخطار المستخدمين بتسديد حوالاتهم
            $moneyTransferUser = $bond->user;
            $message = "لقد قمت بتسديد حوالة بقيمة {$bond->amount} {$bond->saleInvoice->currency->name}";
            $moneyTransferNotifications[] = [
                'user_id' => $moneyTransferUser->id,
                'message' => $message,
            ];

            // إنشاء سجل إشعار للمستخدمين المعنيين
            $moneyTransferUser->notificationLogs()->create([
                'message' => $message,
                'user_id' => $moneyTransferUser->id,
            ]);

            // بث حدث تحويلات الأموال مع البيانات المجمعة
            event(new Event('MoneyTransfers', 'MoneyTransfers', ['notifications' => $moneyTransferNotifications]));
        }
    }

    /**
     * تقوم هذه الدالة بإدارة إشعارات سندات الحسومات الممنوحة بناءً على العملية المقدمة.
     *
     * @param  \App\Models\GivenDiscountBond  $givenDiscountBond  السند المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handleGivenDiscountNotifications($givenDiscountBond, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء سند حسم ممنوح رقم {$givenDiscountBond->code} بقيمة {$givenDiscountBond->amount} {$givenDiscountBond->currency->name} للزبون {$givenDiscountBond->customer->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'GivenDiscountBondCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل سند حسم ممنوح رقم {$givenDiscountBond->code} بقيمة {$givenDiscountBond->amount} {$givenDiscountBond->currency->name} للزبون {$givenDiscountBond->customer->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'GivenDiscountBondUpdated';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة سند حسم ممنوح رقم {$givenDiscountBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'GivenDiscountBondArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة سند حسم ممنوح رقم {$givenDiscountBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'GivenDiscountBondRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف سند حسم ممنوح رقم {$givenDiscountBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'GivenDiscountBondDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('سندات الحسم الممنوح')->get();
        

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $givenDiscountBond->id, 'type' => 'given-discount-bond', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $givenDiscountBond->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }
    }

    /**
     * تقوم هذه الدالة بإدارة إشعارات سندات مسحوبات الشركاء بناءً على العملية المقدمة.
     *
     * @param  \App\Models\PartnersExpensesBond  $partnersExpensesBond  السند المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handlePartnersExpensesBondNotifications($partnersExpensesBond, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء سند مسحوبات شركاء رقم {$partnersExpensesBond->code} بقيمة {$partnersExpensesBond->amount} {$partnersExpensesBond->currency->name} للشريك {$partnersExpensesBond->partner->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'PartnersExpensesBondCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل سند مسحوبات شركاء رقم {$partnersExpensesBond->code} بقيمة {$partnersExpensesBond->amount} {$partnersExpensesBond->currency->name} للشريك {$partnersExpensesBond->partner->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'PartnersExpensesBondUpdated';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة سند مسحوبات شركاء رقم {$partnersExpensesBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'PartnersExpensesBondArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة سند مسحوبات شركاء رقم {$partnersExpensesBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'PartnersExpensesBondRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف سند مسحوبات شركاء رقم {$partnersExpensesBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'PartnersExpensesBondDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('سندات مسحوبات الشركاء')->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $partnersExpensesBond->id, 'type' => 'partners-expenses-bond', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $partnersExpensesBond->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }
    }


    /**
     * تقوم هذه الدالة بإدارة إشعارات سندات مدفوعات الشركاء بناءً على العملية المقدمة.
     *
     * @param  \App\Models\PartnerPayBond  $partnerPayBond  السند المراد إدارة إشعاراته
     * @param  string $operation  العملية التي تمثل الحدث (إضافة، تحديث، أرشفة، استعادة، حذف)
     * @return void
     */
    public function handlePartnersPayBondNotifications($partnerPayBond, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);
        // رسالة للمسؤول
        $adminMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة المسؤول
        $adminChannel = 'Admin';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء سند مدفوعات شركاء رقم {$partnerPayBond->code} بقيمة {$partnerPayBond->amount} {$partnerPayBond->currency->name} للشريك {$partnerPayBond->partner->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'PartnersPayBondCreated';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل سند مدفوعات شركاء رقم {$partnerPayBond->code} بقيمة {$partnerPayBond->amount} {$partnerPayBond->currency->name} للشريك {$partnerPayBond->partner->name} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'PartnersPayBondUpdated';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة سند مدفوعات شركاء رقم {$partnerPayBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'PartnersPayBondArchived';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة سند مدفوعات شركاء رقم {$partnerPayBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'PartnersPayBondRestored';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف سند مدفوعات شركاء رقم {$partnerPayBond->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'PartnersPayBondDeleted';
                break;
        }

        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins('سندات مدفوعات الشركاء')->get();

        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $partnerPayBond->id, 'type' => 'partners-pay-bond', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $partnerPayBond->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }
    }



    public function handleProjectOrderNotifications($projectOrder, $operation)
    {
        // الحصول على معلومات المستخدم الحالي
        $user = User::find(auth()->user()->id);

        // رسالة للمسؤول
        $adminMessage = '';
        // رسالة للمستخدم
        $userMessage = '';
        // نوع الحدث
        $eventType = '';
        // قناة الحدث
        $eventChannel = '';
        // قناة المسؤول
        $adminChannel = '';

        // التبديل بين عمليات مختلفة
        switch ($operation) {
            case 'store':
                // رسالة للمسؤول عند إنشاء الطلب
                $adminMessage = "تم إنشاء طلب توريد مواد مشروع رقم {$projectOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // رسالة للمستخدم عند إنشاء الطلب
                $userMessage = "يجب تسعير طلب توريد مواد مشروع رقم {$projectOrder->code}";
                // نوع الحدث عند إنشاء الطلب
                $eventType = 'ProjectOrderCreated';
                // قناة الحدث عند إنشاء الطلب
                $eventChannel = 'ProjectOrders';
                // قناة المسؤول عند إنشاء الطلب
                $adminChannel = 'Admin';
                break;
            case 'update':
                // رسالة للمسؤول عند تعديل الطلب
                $adminMessage = "تم تعديل طلب توريد مواد مشروع رقم {$projectOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // رسالة للمستخدم عند تعديل الطلب
                $userMessage = "يجب تسعير طلب توريد مواد مشروع رقم {$projectOrder->code}";
                // نوع الحدث عند تعديل الطلب
                $eventType = 'ProjectOrderUpdated';
                // قناة الحدث عند تعديل الطلب
                $eventChannel = 'ProjectOrders';
                // قناة المسؤول عند تعديل الطلب
                $adminChannel = 'Admin';
                break;
            case 'convert-to-offer':
                // رسالة للمسؤول عند تحويل الطلب إلى شراء
                $adminMessage = "تم تحويل طلب توريد مواد مشروع رقم {$projectOrder->code} إلى عرض سعر رقم {$projectOrder->offer_price_code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند تحويل الطلب إلى شراء
                $eventType = 'ProjectOrderConvertedToOffer';
                // قناة المسؤول عند تحويل الطلب إلى شراء
                $adminChannel = 'Admin';
                break;
            case 'archive':
                // رسالة للمسؤول عند أرشفة الطلب
                $adminMessage = "تم أرشفة طلب توريد مواد مشروع رقم {$projectOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند أرشفة الطلب
                $eventType = 'ProjectOrderArchived';
                // قناة المسؤول عند أرشفة الطلب
                $adminChannel = 'Admin';
                break;
            case 'restore':
                // رسالة للمسؤول عند استعادة الطلب
                $adminMessage = "تم استعادة طلب توريد مواد مشروع رقم {$projectOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند استعادة الطلب
                $eventType = 'ProjectOrderRestored';
                // قناة المسؤول عند استعادة الطلب
                $adminChannel = 'Admin';
                break;
            case 'delete':
                // رسالة للمسؤول عند حذف الطلب
                $adminMessage = "تم حذف طلب توريد مواد مشروع رقم {$projectOrder->code} من قبل {$user->first_name} {$user->last_name}";
                // نوع الحدث عند حذف الطلب
                $eventType = 'ProjectOrderDeleted';
                // قناة المسؤول عند حذف الطلب
                $adminChannel = 'Admin';
                break;
        }

        $notificationType = 'طلبات توريد مواد المشاريع';
        
        // الحصول على المسؤولين الذين يجب إخطارهم
        $admins = User::notificationLogsAdmins($notificationType)->get();


        // بث الحدث على قناة المسؤولين
        event(new Event($eventType, $adminChannel, ['id' => $projectOrder->id, 'type' => 'project-order', 'message' => $adminMessage]));

        // إنشاء سجل إشعار للمسؤولين
        foreach ($admins as $admin) {
            $projectOrder->notificationLogs()->create([
                'message' => $adminMessage,
                'user_id' => $admin->id,
            ]);
        }

        // إذا كانت العملية إضافة أو تحديث الطلب
        if ($operation == 'store' || $operation == 'update') {
            // إستدعاء المهمة الخاصة بتولي ارسال الاشعارات للمستخدمين من أجل التدقيق
            UsersNotifications::dispatch($notificationType, $projectOrder, 'project-order', $eventType, $eventChannel, $userMessage);
        }
    }




    /**
     * معالجة إشعارات المواد التي وصلت كميتها إلى أو دون الحد الأدنى
     *
     * @param array $products قائمة المنتجات المتأثرة
     * @param int $storehouseId معرف المستودع الذي تمت فيه العملية
     * @return void
     */
    public function handleUnderMinQtyProductsNotifications($products, $storehouseId)
    {
        // مؤشر يحدد ما إذا كان يجب إرسال إشعار
        $notificationTrigger = false;

        // الحصول على معرفات المنتجات المتأثرة
        $productIds = collect($products)->pluck('product_id')->toArray();

        // جلب المنتجات من قاعدة البيانات
        $products = Product::whereIn('id', $productIds)->get(['id', 'min_quantity']);

        // فحص كميات المنتجات وتحديد ما إذا كان الإشعار مطلوبًا
        foreach ($products as $product) {
            if ($product->min_quantity !== null) {
                // التحقق من كمية المنتج في المستودع المعين
                $qty = $this->validateProductQty($product->id, $storehouseId);
                $qty = $qty['remainQty'];

                // إذا كانت كمية المنتج أقل من أو تساوي الحد الأدنى، قم بتعيين مؤشر الإشعار
                if ($qty <= $product->min_quantity) {
                    $notificationTrigger = true;
                    break;
                }
            }
        }

        // إرسال إشعار إذا كان هناك منتجات تحت الحد الأدنى
        if ($notificationTrigger) {
            // جلب المسؤولين المفعلين من قاعدة البيانات
            $admins = User::whereHas('roles', function ($query) {
                $query->where('name', 'Admin');
            })
                ->whereDoesntHave('roles', function ($query) {
                    $query->where('name', 'Inactive');
                })
                ->get();

            // نوع الحدث ورسالة الإشعار
            $eventType = 'ProductsQuantitiesUnderMin';
            $message = 'بعض المواد بلغت كميتها الحد الأدنى!';

            // بث الحدث على قناة المسؤولين
            event(new Event($eventType, 'Admin', ['message' => $message]));

            // إنشاء سجل إشعار لكل مسؤول
            foreach ($admins as $admin) {
                $admin->notificationLogs()->create([
                    'message' => $message,
                    'notifiable_type' => $eventType,
                    'user_id' => $admin->id,
                ]);
            }
        }
    }

    /**
     * معالجة إشعارات الرصيد الأعظمي لزبون
     * 
     * @param int $customerId معرف الزبون
     * @return void
     */
    public function handleCustomerMaxBalanceNotifications($customerId)
    {
        // تحديد ما إذا كان يجب تفعيل الإشعار
        $notificationTrigger = false;

        // جلب بيانات الزبون باستخدام معرفه
        $customer = Customer::find($customerId);

        // إعداد طلب HTTP للحصول على رصيد الزبون
        $request = new HttpRequest;
        $request['customer_id'] = $customer->currency_id;
        $request['report_currency_id'] = $customer->currency_id;

        // جلب بيانات الرصيد للزبون
        $balanceData = $this->customerBalance($request);

        // التحقق مما إذا كان رصيد الزبون قد تجاوز الرصيد الأعظمي المسموح به
        if ($balanceData['customer_balance'] >= $customer->max_balance) {
            $notificationTrigger = true;
        }

        // إذا تم تفعيل الإشعار
        if ($notificationTrigger) {
            // جلب جميع المستخدمين من نوع مدير الذين ليس لديهم دور "غير نشط"
            $admins = User::whereHas('roles', function ($query) {
                $query->where('name', 'Admin');
            })
                ->whereDoesntHave('roles', function ($query) {
                    $query->where('name', 'Inactive');
                })
                ->get();

            // تحديد نوع الحدث والرسالة
            $eventType = 'CustomerBalanceExceededMax';
            $message = 'رصيد الزبون ' . $customer->name . ' تجاوز رصيده الأعظمي!';

            // إرسال الحدث مع الرسالة
            event(new Event($eventType, 'Admin', [
                'customer_id' => $customerId,
                'customer_name' => $customer->name,
                'currency_id' => $customer->currency_id,
                'currency_name' => $customer->currency->name,
                'currency_is_default' => $customer->currency->is_default,
                'message' => $message
            ]));

            // إنشاء سجل إشعار لكل مدير
            foreach ($admins as $admin) {
                $admin->notificationLogs()->create([
                    'message' => $message,
                    'notifiable_type' => 'Customer',
                    'notifiable_id' => $customerId,
                    'user_id' => $admin->id,
                ]);
            }
        }
    }

    public function handlePartnerExpensesNotifications($partnerId)
    {
        $notificationTrigger = false;

        $partner = Partner::find($partnerId);

        $request = new HttpRequest;
        $request['report_currency_id'] = $partner->currency_id;
        $request['category_id'] = $partner->category_id;
        $request['price_type_id'] = 8; // متوسط سعر الشراء(من أجل بضاعة آخر مدة)

        // استدعاء تقرير ميزان المراجعة
        $trialBalanceReportController = new TrialBalanceReportController();
        $ans = $trialBalanceReportController->report($request);
        $originalData = $ans->getOriginalContent();
        $trialBalance = $originalData['data'];

        $partnerShare = $trialBalance['difference'] * ($partner->percent_discount / 100);

        $partnerBonds = PartnersExpensesBond::where('partner_id', $partner->id)->get();

        $partnerBondsAmounts = 0;
        foreach ($partnerBonds as $bond) {
            $amount = $this->convertBondCurrency($bond, 'PartnersExpensesBond', $partner->id);
            $partnerBondsAmounts += $amount;
        }

        if ($partnerBondsAmounts > $partnerShare) {
            $notificationTrigger = true;
        }

        if ($notificationTrigger) {
            // جلب جميع المستخدمين من نوع مدير الذين ليس لديهم دور "غير نشط"
            $admins = User::whereHas('roles', function ($query) {
                $query->where('name', 'Admin');
            })
                ->whereDoesntHave('roles', function ($query) {
                    $query->where('name', 'Inactive');
                })
                ->get();

            // تحديد نوع الحدث والرسالة
            $eventType = 'PartnerExpensesExceededShare';
            $message = 'قيمة مسحوبات الشريك ' . $partner->name . ' تجاوزت المبلغ المخصص له!';

            $price = PriceType::where('name', 'متوسط سعر الشراء')->first();

            // إرسال الحدث مع الرسالة
            event(new Event($eventType, 'Admin', [
                'partner_id' => $partnerId,
                'partner_name' => $partner->name,
                'message' => $message,
                'currency_id' => $partner->currency_id,
                'currency_name' => $partner->currency->name,
                'category_id' => $partner->category_id,
                'category_name' => $partner->category->name,
                'price_type' => $price->type,
                'price_type_id' => $price->id
            ]));

            // إنشاء سجل إشعار لكل مدير
            foreach ($admins as $admin) {
                $admin->notificationLogs()->create([
                    'message' => $message,
                    'notifiable_type' => 'Partner',
                    'notifiable_id' => $partnerId,
                    'user_id' => $admin->id,
                ]);
            }
        }
    }
}
